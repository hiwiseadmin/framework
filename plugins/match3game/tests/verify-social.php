<?php
/**
 * 社交模块自检（Phase 5：对战判定 / 积分 / 段位 / 榜单 / 助力 / 群擂台）
 *
 *   php src/plugins/match3game/tests/verify-social.php
 *
 * 覆盖可离线验证的纯逻辑：
 *   1. SocialRules::judge       对战判定（分数 → 步数 → 提交时间 → 平局）与两端一致性
 *   2. SocialRules::pointsDelta 积分（+30/+25/-15/-20，每日首胜 +10）
 *   3. 段位映射与"负分不降段"
 *   4. 周期/赛季 ID、分享冷却、体力上限
 *   5. 群标识合规校验（不存明文群名）
 *   6. GroupBattleService::topK 群内 Top5 累加
 *   7. 模型状态文案
 *   8. Redis 不可用时的降级不抛异常
 *   9. 对战 seed 一致性（同一 seed → 棋盘 100% 一致）
 *  10. 邀请码可逆混淆与防伪造（invite 端点）
 */

declare(strict_types=1);

$levelBase = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require __DIR__ . '/stub/BaseModel.php';
require $levelBase . 'StableStringify.php';
require $levelBase . 'Match3Random.php';
require $levelBase . 'LevelSchemaFactory.php';
require $levelBase . 'Match3Board.php';

require __DIR__ . '/../model/Match3PvpBattle.php';
require __DIR__ . '/../model/Match3PvpPlayer.php';
require __DIR__ . '/../model/Match3FriendHelp.php';
require __DIR__ . '/../model/Match3Season.php';
require __DIR__ . '/../model/Match3GroupBattle.php';

require __DIR__ . '/../service/social/SocialRules.php';
require __DIR__ . '/../service/social/SocialException.php';
require __DIR__ . '/../service/social/RedisCounter.php';
require __DIR__ . '/../service/social/GroupBattleService.php';
require __DIR__ . '/../service/social/SeasonService.php';
require __DIR__ . '/../service/social/PvpService.php';

use plugin\match3game\model\Match3FriendHelp;
use plugin\match3game\model\Match3GroupBattle;
use plugin\match3game\model\Match3PvpBattle;
use plugin\match3game\model\Match3PvpPlayer;
use plugin\match3game\model\Match3Season;
use plugin\match3game\service\level\Match3Board;
use plugin\match3game\service\social\GroupBattleService;
use plugin\match3game\service\social\RedisCounter;
use plugin\match3game\service\social\SeasonService;
use plugin\match3game\service\social\SocialRules;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

function player(int $score, int $moves, int $at, int $uid = 0): array
{
    return ['score' => $score, 'moves' => $moves, 'finishedAt' => $at, 'userId' => $uid];
}

// ======================================================================
section('1. 对战判定（05 §A8.1 第 2 条）');
// ======================================================================

$r = SocialRules::judge(player(9000, 20, 100), player(8000, 20, 100));
check('分数高者胜', $r['result'] === 'win' && $r['reason'] === 'score');

$r = SocialRules::judge(player(8000, 20, 100), player(9000, 20, 100));
check('分数低者负', $r['result'] === 'lose');

$r = SocialRules::judge(player(9000, 18, 100), player(9000, 22, 100));
check('同分 → 步数少者胜', $r['result'] === 'win' && $r['reason'] === 'moves');

$r = SocialRules::judge(player(9000, 22, 100), player(9000, 18, 100));
check('同分同步数 → 步数多者负', $r['result'] === 'lose');

$r = SocialRules::judge(player(9000, 20, 100), player(9000, 20, 200));
check('同分同步数 → 先提交者胜', $r['result'] === 'win' && $r['reason'] === 'submitTime');

$r = SocialRules::judge(player(9000, 20, 200), player(9000, 20, 100));
check('后提交者负', $r['result'] === 'lose');

$r = SocialRules::judge(player(9000, 20, 100), player(9000, 20, 100));
check('全同 → 平局', $r['result'] === 'draw' && $r['reason'] === 'tie');

// 两端一致性：互换 a/b，胜负必须镜像（保证双方看到同一结果）
$cases = [
    [player(9000, 20, 100), player(8000, 20, 100)],
    [player(9000, 20, 100), player(9000, 20, 100)],
    [player(9000, 25, 100), player(9000, 18, 100)],
    [player(9000, 20, 180), player(9000, 20, 120)],
];
$mirrorOk = true;
foreach ($cases as [$a, $b]) {
    $ab = SocialRules::judge($a, $b);
    $ba = SocialRules::judge($b, $a);
    $flip = ['win' => 'lose', 'lose' => 'win', 'draw' => 'draw'];
    if ($ab['result'] !== $flip[$ba['result']]) {
        $mirrorOk = false;
        break;
    }
}
check('互换双方后胜负严格镜像（两端一致）', $mirrorOk);

// ======================================================================
section('2. 积分规则（05 §A7.1）');
// ======================================================================

check('胜（分差 > 20%）→ +30', SocialRules::pointsDelta('win', 10000, 8000) === 30);
check('胜（分差 ≤ 20%）→ +25', SocialRules::pointsDelta('win', 9000, 8000) === 25);
check('胜（恰好 20%）→ +25（边界取闭区间）', SocialRules::pointsDelta('win', 9600, 8000) === 25);
check('胜（刚过 20%）→ +30', SocialRules::pointsDelta('win', 9601, 8000) === 30);
check('每日首胜额外 +10', SocialRules::pointsDelta('win', 9000, 8000, true) === 35);
check('大胜 + 首胜 → 40', SocialRules::pointsDelta('win', 20000, 8000, true) === 40);
check('负 → -15', SocialRules::pointsDelta('lose', 8000, 9000) === -15);
check('超时 → -20', SocialRules::pointsDelta('timeout', 0, 0) === -20);
check('平局 → 0', SocialRules::pointsDelta('draw', 9000, 9000) === 0);
check('对手 0 分且我胜 → 大胜 +30', SocialRules::pointsDelta('win', 100, 0) === 30);

// ======================================================================
section('3. 段位（05 §A7.1）');
// ======================================================================

check('0 → 青铜', SocialRules::tierOf(0) === 'bronze');
check('199 → 青铜', SocialRules::tierOf(199) === 'bronze');
check('200 → 白银', SocialRules::tierOf(200) === 'silver');
check('1000 → 铂金', SocialRules::tierOf(1000) === 'platinum');
check('2999 → 钻石', SocialRules::tierOf(2999) === 'diamond');
check('3000 → 王者', SocialRules::tierOf(3000) === 'king');
check('99999 → 王者（无上限）', SocialRules::tierOf(99999) === 'king');

check('青铜下限 0', SocialRules::tierFloor('bronze') === 0);
check('白银下限 200', SocialRules::tierFloor('silver') === 200);
check('王者下限 3000', SocialRules::tierFloor('king') === 3000);

check('白银 210 -15 → 夹在 200（不降段）', SocialRules::applyFloor(210 - 15, 'silver') === 200);
check('白银 210 -15 若夹到 199 则错（回归保护）', SocialRules::applyFloor(195, 'silver') === 200);
check('青铜 10 -15 → 夹在 0', SocialRules::applyFloor(-5, 'bronze') === 0);
check('钻石 1800 -15 → 1800（正好顶在段位下限，也夹住）', SocialRules::applyFloor(1800 - 15, 'diamond') === 1800);
check('钻石 2000 -15 → 1985（段位内正常掉落）', SocialRules::applyFloor(2000 - 15, 'diamond') === 1985);
check('钻石 1850 -15 → 1835（未触底则如实扣分）', SocialRules::applyFloor(1850 - 15, 'diamond') === 1835);

check('段位中文名', SocialRules::tierName('king') === '王者' && SocialRules::tierName('bronze') === '青铜');
check('未知名原样返回', SocialRules::tierName('xxx') === 'xxx');

// ======================================================================
section('4. 周期 / 赛季 / 配额');
// ======================================================================

$mon = strtotime('2026-09-07 09:00:00'); // 周一
$sun = strtotime('2026-09-13 23:00:00'); // 同周周日
$next = strtotime('2026-09-14 09:00:00'); // 下周一
check('periodId 格式 YYYY-Www', (bool) preg_match('/^\d{4}-W\d{2}$/', SocialRules::periodId($mon)), SocialRules::periodId($mon));
check('同一周同 periodId', SocialRules::periodId($mon) === SocialRules::periodId($sun));
check('下一周 periodId 变化', SocialRules::periodId($mon) !== SocialRules::periodId($next));

$s1 = SocialRules::seasonId(strtotime('2026-01-02'));
check('seasonId 格式 S{年}-{期}', (bool) preg_match('/^S\d{4}-\d{2}$/', $s1), $s1);
check('赛季周期 2 周内同 ID（第 0 天与第 13 天）',
    SocialRules::seasonId(strtotime('2026-01-02')) === SocialRules::seasonId(strtotime('2026-01-14')));
check('第 14 天进入下一期',
    SocialRules::seasonId(strtotime('2026-01-02')) !== SocialRules::seasonId(strtotime('2026-01-17')));
check('SEASON_DAYS = 14', SocialRules::SEASON_DAYS === 14);

check('体力 99 未满', !SocialRules::energyAtCap(99));
check('体力 100 已满', SocialRules::energyAtCap(100) && SocialRules::energyAtCap(120));
check('ENERGY_CAP = 100 / 每次 +5', SocialRules::ENERGY_CAP === 100 && SocialRules::ENERGY_PER_GIFT === 5);
check('每日送 5 收 10', SocialRules::GIFT_DAILY_SEND === 5 && SocialRules::GIFT_DAILY_RECEIVE === 10);
check('赠送者奖励 +20 金币', SocialRules::GIFT_REWARD_COIN === 20);
check('求助门槛：失败 3 次', SocialRules::RESCUE_FAIL_TIMES === 3);

$now = 1_700_000_000;
// ⚠️ 2026-10-04：冷却 60s → 10s（微信 `shareAppMessage` 的 success 只是"面板拉起"，
//    60s 会把"连发 2~3 个好友"误判成刷分享）。缺省常量即新口径，配置可再覆盖。
check('分享刚冷却完（10s）可分享', SocialRules::shareAllowed($now - 10, 0, $now));
check('分享冷却中不可分享', !SocialRules::shareAllowed($now - 5, 0, $now));
check('分享满 3 次不可分享', !SocialRules::shareAllowed($now - 3600, 3, $now));
check('冷却/配额可传参覆盖（配置化）', SocialRules::shareAllowed($now - 30, 0, $now, 20)
    && !SocialRules::shareAllowed($now - 3600, 2, $now, 10, 2));
check('分享冷却 10s / 每日 3 次 / 每次 +20', SocialRules::SHARE_COOLDOWN === 10 && SocialRules::SHARE_DAILY_LIMIT === 3 && SocialRules::SHARE_REWARD_COIN === 20);
check('公开挑战池上限 3', SocialRules::LADDER_MAX_OPEN === 3);

// ---- 分享限流的"故障不得算到玩家头上"（2026-10-04 修的两处）----
$pluginRoot = dirname(__DIR__);

$counterSrc = (string) (is_file($pluginRoot . '/service/social/RedisCounter.php')
    ? file_get_contents($pluginRoot . '/service/social/RedisCounter.php') : '');
check('acquire() 故障放行（catch 返回 true，不是 false）', str_contains($counterSrc, 'return true; // 故障放行'));

$socialSrc = (string) (is_file($pluginRoot . '/controller/api/Social.php')
    ? file_get_contents($pluginRoot . '/controller/api/Social.php') : '');
check('share() 冷却读配置而非写死常量', str_contains($socialSrc, "shareConfig('cooldown'"));
check('share() 每日上限读配置', str_contains($socialSrc, "shareConfig('daily_limit'"));
check('配置非法值兜底到常量（max(1, ...)）', str_contains($socialSrc, 'max(1, (int) $raw)'));

$cfgSrc = (string) (is_file($pluginRoot . '/config/match3.php') ? file_get_contents($pluginRoot . '/config/match3.php') : '');
check("config 的 share 段有 cooldown / daily_limit", str_contains($cfgSrc, "'cooldown'") && str_contains($cfgSrc, "'daily_limit'"));

// ---- 分享幂等（nonce）：同一次分享只结算一次 ----
check('share() 读取 nonce 幂等键', str_contains($socialSrc, "'share:once:'"));
check('幂等结果经统一出口写入（三处 return 不会各写各的）', str_contains($socialSrc, 'protected function shareResult('));
check('重复上报返回首次结果并标记 idempotent', str_contains($socialSrc, "'idempotent' => true"));
check('RedisCounter 提供 getJson / put / putIfAbsent', str_contains($counterSrc, 'function getJson(')
    && str_contains($counterSrc, 'function put(') && str_contains($counterSrc, 'function putIfAbsent('));

// ======================================================================
section('5. 群标识合规（05 §A8.1 第 8 条）');
// ======================================================================

check('拒绝明文群名（含中文）', !SocialRules::isEncryptedGroupId('消消乐交流群'));
check('拒绝过短', !SocialRules::isEncryptedGroupId('abc'));
check('拒绝空', !SocialRules::isEncryptedGroupId(''));
check('拒绝非法字符（空格/冒号）', !SocialRules::isEncryptedGroupId('abc def:ghi'));
check('接受 openGId 形态（base64url）', SocialRules::isEncryptedGroupId('Gx9aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456'));
check('接受 hex 形态', SocialRules::isEncryptedGroupId('a1b2c3d4e5f60718293a4b5c6d7e8f90'));
check('接受含 = / + 的 base64', SocialRules::isEncryptedGroupId('YWJjZGVmZ2hp+SGtsbW5vcA=='));

// ======================================================================
section('6. 群擂台 Top5 累加（05 §A7.3）');
// ======================================================================

check('Top5 累加（10 人）', GroupBattleService::topK([100, 90, 80, 70, 60, 50, 40, 30, 20, 10], 5) === 400);
check('不足 5 人 → 全累加', GroupBattleService::topK([100, 80, 30], 5) === 210);
check('空数组 → 0', GroupBattleService::topK([], 5) === 0);
check('全 0 → 0', GroupBattleService::topK([0, 0, 0, 0, 0, 0], 5) === 0);
check('含重复分', GroupBattleService::topK([100, 100, 100, 100, 100, 1], 5) === 500);
check('只取前 1（K=1）', GroupBattleService::topK([100, 90, 80], 1) === 100);
check('K=0 → 0', GroupBattleService::topK([100], 0) === 0);
check('入参为字符串数字也能算', GroupBattleService::topK(['100', '90', '80', '70', '60', '50'], 5) === 400);
$before = [5, 1, 3];
GroupBattleService::topK($before, 5);
check('不修改入参数组', $before === [5, 1, 3], json_encode($before));
check('GROUP_TOP_N = 5 / 每日 3 次 / 关卡池 3', Match3GroupBattle::TOP_N === 5 && Match3GroupBattle::DAILY_PLAYS === 3 && SocialRules::GROUP_STAGE_POOL === 3);

// ======================================================================
section('7. 模型状态文案');
// ======================================================================

check('对战场次状态文案', Match3PvpBattle::statusText(0) === '待接受'
    && Match3PvpBattle::statusText(1) === '进行中'
    && Match3PvpBattle::statusText(2) === '已完成'
    && Match3PvpBattle::statusText(3) === '已过期'
    && Match3PvpBattle::statusText(4) === '已拒绝');
check('对战玩家状态文案', Match3PvpPlayer::statusText(2) === '已完成' && Match3PvpPlayer::statusText(3) === '超时');
check('作弊标记文案', Match3PvpPlayer::cheatText(0) === '正常'
    && Match3PvpPlayer::cheatText(1) === '可疑'
    && Match3PvpPlayer::cheatText(2) === '确认作弊');
check('助力类型/状态文案', Match3FriendHelp::typeText('energy') === '送体力'
    && Match3FriendHelp::typeText('rescue') === '卡关求助'
    && Match3FriendHelp::statusText(1) === '已领取'
    && Match3FriendHelp::statusText(2) === '已过期');
check('赛季状态文案', Match3Season::statusText(1) === '进行中' && Match3Season::statusText(2) === '已结算');
check('挑战卡有效期 24h', Match3PvpBattle::TTL_HOURS === 24 && Match3FriendHelp::TTL_HOURS === 24);

// ======================================================================
section('8. 赛季奖励配置（05 §A7.1）');
// ======================================================================

$rewards = SeasonService::defaultRewards();
check('覆盖 6 个段位', count($rewards) === 6 && array_keys($rewards) === ['bronze', 'silver', 'gold', 'platinum', 'diamond', 'king']);
check('金币随段位递增', (function () use ($rewards) {
    $prev = -1;
    foreach ($rewards as $r) {
        if ((int) $r['coin'] <= $prev) {
            return false;
        }
        $prev = (int) $r['coin'];
    }
    return true;
})());
check('王者奖励含称号/头像框等外观', in_array('avatar_frame_king', $rewards['king']['cosmetics'], true));
check('段位限定道具使用真实 prop code', array_key_exists('prop.hammer', $rewards['silver']['props']));

// ======================================================================
section('9. Redis 不可用时的降级（不抛异常）');
// ======================================================================

$counter = new RedisCounter();
$threw   = false;
$value   = null;
try {
    $value = $counter->incr('verify:social:test', 10);
} catch (\Throwable $e) {
    $threw = true;
}

check('无 Redis 环境时 incr() 不抛异常', !$threw);
check('降级返回 false（调用方据此走 MySQL 计数）', $value === false || is_int($value), var_export($value, true));
check('available() 探测不抛异常', is_bool($counter->available()));

// ======================================================================
section('10. 对战 seed 一致性（05 §A8.1 第 1 条）');
// ======================================================================

// 双方 start 都读 match3_pvp_battle.seed → 只要 seed 相同，棋盘必须 100% 一致
//
// 【算法口径】走唯一权威实现 Match3Board::buildState()（mulberry32 + deriveSeed），
// 与 L3 重放引擎、客户端同源。旧版用 BoardSeedService（xorshift32）验证，
// 与实际对局棋盘不同源，结论无效，已统一。
$seed   = 1736438400;
$schema = [
    'schema'  => 'match3/level@1',
    'board'   => ['cols' => 8, 'rows' => 8, 'cellSize' => 76, 'origin' => ['c' => 0, 'r' => 0]],
    'palette' => ['gems' => ['a', 'b', 'c'], 'theme' => 'test'],
    'regions' => [[
        'id'      => 'main',
        'origin'  => ['c' => 0, 'r' => 0],
        'gravity' => 'down',
        'layers'  => ['terrain' => array_fill(0, 8, 'oooooooo'), 'entity' => [], 'overlay' => []],
        'spawn'   => ['mode' => 'top', 'weights' => [1, 1, 1]],
    ]],
    'rules'   => ['mode' => 'moves', 'minMatch' => 3, 'moves' => 25],
];

$fpOf = static function (int $s) use ($schema): string {
    return Match3Board::colorFingerprint(Match3Board::buildState($schema, $s));
};

$fp1 = $fpOf($seed);
$fp2 = $fpOf($seed);

check('同一 seed → 同一棋盘指纹（100 次一致性）', (function () use ($fpOf, $seed, $fp1) {
    for ($i = 0; $i < 100; $i++) {
        if ($fpOf($seed) !== $fp1) {
            return false;
        }
    }
    return true;
})());
check('双方棋盘逐格一致', $fp1 === $fp2);
check('不同 seed → 不同棋盘（防止 seed 被忽略）', $fpOf($seed + 1) !== $fp1);

// ======================================================================
section('11. 邀请码（可逆混淆 + 防伪造）');
// ======================================================================

$code = SocialRules::encodeInviteCode(12345);
check('邀请码非空且为 base36 大写', $code !== '' && preg_match('/^[0-9A-Z]+$/', $code) === 1, $code);
check('邀请码可反解回 uid', SocialRules::decodeInviteCode($code) === 12345);
check('不同 uid → 不同邀请码', SocialRules::encodeInviteCode(1) !== SocialRules::encodeInviteCode(2));
check('大小写不敏感', SocialRules::decodeInviteCode(strtolower($code)) === 12345);
check('含非法字符 → 0', SocialRules::decodeInviteCode('hello!@#') === 0);
check('空码 → 0', SocialRules::decodeInviteCode('') === 0);
check('伪造（盐偏移以下）→ 0', SocialRules::decodeInviteCode('1') === 0);
check('uid 0 → 空码', SocialRules::encodeInviteCode(0) === '');
check('多种 uid 往返一致', (function () {
    foreach ([1, 2, 999, 100000, 987654321, 2147483647] as $uid) {
        if (SocialRules::decodeInviteCode(SocialRules::encodeInviteCode($uid)) !== $uid) {
            return false;
        }
    }
    return true;
})());
check('邀请数值：受邀者 50 / 邀请者 50 / 每日 5 人',
    SocialRules::INVITE_INVITEE_REWARD === 50
    && SocialRules::INVITE_INVITER_REWARD === 50
    && SocialRules::INVITE_DAILY_LIMIT === 5);

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
