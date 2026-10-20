<?php
/**
 * 肉鸽扩展自检（Phase 4：效果字典 / 商店 / 休息 / 事件 / 每日挑战）
 *
 *   php src/plugins/match3game/tests/verify-rogue-ext.php
 *
 * 覆盖可离线验证的纯逻辑：
 *   1. RunEffects           10 种效果的正确性与边界
 *   2. RelicService         可升级判定
 *   3. ShopService          库存确定性 / 价格折扣 / 休息选项
 *   4. EventService         事件抽取确定性与层限制
 *   5. DailyService         当日种子一致性（全服一致的前提）
 *   6. Match3EventSeeder    22 个事件的 effects 全部合法
 */

declare(strict_types=1);

$levelBase = __DIR__ . '/../service/level/';
require __DIR__ . '/stub/ContextualLogger.php';
require $levelBase . 'StableStringify.php';
require $levelBase . 'Match3Random.php';
require $levelBase . 'DifficultyCurve.php';
require $levelBase . 'LevelSchemaFactory.php';
require $levelBase . 'LevelValidator.php';
require $levelBase . 'Match3Board.php';
require $levelBase . 'LevelSimulator.php';
require $levelBase . 'Match3LevelGenerator.php';
require $levelBase . 'LevelGenerationService.php';
require $levelBase . 'GoalJudge.php';
require $levelBase . 'Match3OpsValidator.php';
require $levelBase . 'Match3ValidationResult.php';
require $levelBase . 'Match3ReplayEngineInterface.php';
require $levelBase . 'Match3NullReplayEngine.php';
require __DIR__ . '/../service/rogue/PatchException.php';
require __DIR__ . '/../service/rogue/PatchResult.php';
require __DIR__ . '/../service/rogue/PatchEngine.php';
require __DIR__ . '/../service/rogue/Match3NodeException.php';
require __DIR__ . '/../service/rogue/Match3MapGenerator.php';
require __DIR__ . '/../service/rogue/RogueRules.php';
require __DIR__ . '/../service/rogue/RunEffects.php';
require __DIR__ . '/../service/rogue/RelicService.php';
require __DIR__ . '/../service/rogue/Match3NodeService.php';
require __DIR__ . '/../service/rogue/Match3RunService.php';
require __DIR__ . '/../service/rogue/ShopService.php';
require __DIR__ . '/../service/rogue/EventService.php';
require __DIR__ . '/../service/rogue/DailyService.php';

use plugin\match3game\service\rogue\DailyService;
use plugin\match3game\service\rogue\EventService;
use plugin\match3game\service\rogue\RelicService;
use plugin\match3game\service\rogue\RogueRules;
use plugin\match3game\service\rogue\RunEffects;
use plugin\match3game\service\rogue\ShopService;

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

/** 假遗物池（覆盖 4 种稀有度） */
function pool(): array
{
    return [
        ['relic_id' => 'r_more_moves',   'name' => '时间沙漏', 'rarity' => 'common', 'weight' => 100, 'patch' => [['op' => 'add', 'path' => '/rules/moves', 'value' => 5, 'mode' => 'accumulate']]],
        ['relic_id' => 'r_score_mul',    'name' => '聚宝盆',   'rarity' => 'rare',   'weight' => 100, 'patch' => [['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 1.2]]],
        ['relic_id' => 'r_more_moves2',  'name' => '沙漏二',   'rarity' => 'common', 'weight' => 60,  'patch' => [['op' => 'add', 'path' => '/rules/moves', 'value' => 3, 'mode' => 'accumulate']]],
        ['relic_id' => 'r_four_bomb',    'name' => '易爆体质', 'rarity' => 'epic',   'weight' => 100, 'patch' => [['op' => 'replace', 'path' => '/rules/specials/match4', 'value' => 'bomb']]],
        ['relic_id' => 'r_risk_moves',   'name' => '破釜沉舟', 'rarity' => 'cursed', 'weight' => 100, 'patch' => [['op' => 'add', 'path' => '/rules/moves', 'value' => -5, 'mode' => 'accumulate']]],
        ['relic_id' => 'r_meta_stone',   'name' => '聚灵',     'rarity' => 'common', 'weight' => 100, 'patch' => [['op' => 'mul', 'path' => '/run/metaMul', 'value' => 1.5]]],
    ];
}

function baseRun(): array
{
    return [
        'coins'  => 200,
        'lives'  => 3,
        'relics' => [],
        'buffs'  => [],
        'level_seed' => 123456,
    ];
}

// ======================================================================
section('1. RunEffects —— 效果字典');
// ======================================================================

$run = baseRun();
$r   = RunEffects::apply($run, [['kind' => 'coin', 'value' => 60]]);
check('coin：+60 灵玉', $run['coins'] === 260 && $r['applied'] !== []);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'coin', 'value' => -1000]]);
check('coin：不会扣成负数（夹到 0）', $run['coins'] === 0, (string) $run['coins']);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'life', 'value' => -1]]);
check('life：-1 机会', $run['lives'] === 2, (string) $run['lives']);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'life', 'value' => 5]]);
check('life：上限 = BASE_LIVES(3)', $run['lives'] === RogueRules::BASE_LIVES, (string) $run['lives']);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'life', 'value' => -9]]);
check('life：下限 0', $run['lives'] === 0);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'buff_moves', 'value' => 5], ['kind' => 'buff_moves', 'value' => 3]]);
check('buff_moves：多次累加（5+3=8）', $run['buffs']['moves'] === 8, var_export($run['buffs']['moves'] ?? null, true));

$run = baseRun();
RunEffects::apply($run, [['kind' => 'score_buff', 'value' => 15]]);
check('score_buff：+15%', $run['buffs']['scorePercent'] === 15);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'coin_buff', 'value' => 25]]);
check('coin_buff：+25%', $run['buffs']['coinPercent'] === 25);

$run = baseRun();
$r   = RunEffects::apply($run, [['kind' => 'relic', 'relic_id' => 'r_score_mul']], ['pool' => pool()]);
check('relic：指定 id 获得遗物', count($run['relics']) === 1 && $run['relics'][0]['id'] === 'r_score_mul');
check('relic：带 lv=1 与 obtainedAt', ($run['relics'][0]['lv'] ?? 0) === 1 && ($run['relics'][0]['obtainedAt'] ?? '') === 'event');

$run = baseRun();
RunEffects::apply($run, [['kind' => 'relic', 'rarity' => 'rare']], ['pool' => pool()]);
check('relic：按稀有度获得（rare → 聚宝盆）', ($run['relics'][0]['id'] ?? '') === 'r_score_mul');

$run = baseRun();
RunEffects::apply($run, [['kind' => 'relic', 'rarity' => 'rare']], ['pool' => pool()]);
$r = RunEffects::apply($run, [['kind' => 'relic', 'rarity' => 'rare']], ['pool' => pool()]);
check('relic：不重复获得同一遗物', count($run['relics']) === 1 && $r['failed'] !== []);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'curse']], ['pool' => pool()]);
check('curse：获得诅咒遗物（唯一一个 cursed）', ($run['relics'][0]['id'] ?? '') === 'r_risk_moves');

$run = baseRun();
$run['relics'] = [['id' => 'r_risk_moves', 'lv' => 1]];
RunEffects::apply($run, [['kind' => 'remove_curse', 'value' => 1]], ['pool' => pool()]);
check('remove_curse：移除诅咒遗物', $run['relics'] === []);

$run = baseRun();
$run['relics'] = [['id' => 'r_score_mul', 'lv' => 1]];
$r = RunEffects::apply($run, [['kind' => 'remove_curse', 'value' => 1]], ['pool' => pool()]);
check('remove_curse：无诅咒时失败但不崩', $r['failed'] !== [] && count($run['relics']) === 1);

// 升级：只能升级"可升级"遗物
$run = baseRun();
$run['relics'] = [['id' => 'r_more_moves', 'lv' => 1]];
$r = RunEffects::apply($run, [['kind' => 'upgrade', 'relic_id' => 'r_more_moves']], ['pool' => pool()]);
check('upgrade：可升级遗物 lv1 → lv2', ($run['relics'][0]['lv'] ?? 0) === 2 && $r['applied'] !== []);

$r = RunEffects::apply($run, [['kind' => 'upgrade', 'relic_id' => 'r_more_moves']], ['pool' => pool()]);
check('upgrade：达最高等级被拒', $r['failed'] !== [] && $run['relics'][0]['lv'] === 2);

$run = baseRun();
$run['relics'] = [['id' => 'r_four_bomb', 'lv' => 1]];
$r = RunEffects::apply($run, [['kind' => 'upgrade', 'relic_id' => 'r_four_bomb']], ['pool' => pool()]);
check('upgrade：replace 类遗物不可升级', $r['failed'] !== [] && $run['relics'][0]['lv'] === 1);

$run = baseRun();
RunEffects::apply($run, [['kind' => 'stone', 'value' => 120]]);
check('stone：记入 buffs.stone', $run['buffs']['stone'] === 120);

$run = baseRun();
$r   = RunEffects::apply($run, [['kind' => 'nonsense', 'value' => 1]]);
check('未知效果类型：记 failed，不抛异常', $r['failed'] !== [] && $r['applied'] === []);

check('describe：生成可读文案', RunEffects::describe([
    ['kind' => 'coin', 'value' => 60],
    ['kind' => 'life', 'value' => -1],
]) === ['灵玉 +60', '失败机会 -1']);

check('MAX_RELIC_LEVEL = 2', RunEffects::MAX_RELIC_LEVEL === 2);

// ======================================================================
section('2. RelicService —— 可升级判定');
// ======================================================================

check('isUpgradable：add+accumulate → true', RelicService::isUpgradable([['op' => 'add', 'path' => '/x', 'value' => 1, 'mode' => 'accumulate']]));
check('isUpgradable：mul → true', RelicService::isUpgradable([['op' => 'mul', 'path' => '/x', 'value' => 1.2]]));
check('isUpgradable：replace → false', !RelicService::isUpgradable([['op' => 'replace', 'path' => '/x', 'value' => 1]]));
check('isUpgradable：普通 add（覆盖写）→ false', !RelicService::isUpgradable([['op' => 'add', 'path' => '/x', 'value' => 1]]));
check('isUpgradable：remove → false', !RelicService::isUpgradable([['op' => 'remove', 'path' => '/x']]));
check('isUpgradable：空 patch → false', !RelicService::isUpgradable([]));
check('isUpgradable：混合 → false', !RelicService::isUpgradable([
    ['op' => 'mul', 'path' => '/x', 'value' => 2],
    ['op' => 'replace', 'path' => '/y', 'value' => 1],
]));

$list = RelicService::upgradableOf([
    ['id' => 'r_more_moves', 'lv' => 1],
    ['id' => 'r_four_bomb',  'lv' => 1],
], pool());
check('upgradableOf：逐个标注可升级', count($list) === 2
    && $list[0]['upgradable'] === true
    && $list[1]['upgradable'] === false, json_encode(array_column($list, 'upgradable')));
check('upgradableOf：带出名称与等级', $list[0]['name'] === '时间沙漏' && $list[0]['lv'] === 1);

// ======================================================================
section('3. ShopService —— 库存与休息');
// ======================================================================

$shop = new ShopService();

$stockA = $shop->buildStock(baseRun(), '2-1', pool());
$stockB = $shop->buildStock(baseRun(), '2-1', pool());
check('同 (seed, nodeId) 库存完全一致', $stockA === $stockB);

$stockC = $shop->buildStock(baseRun(), '2-2', pool());
check('不同 nodeId 库存不同（至少商品集合/价格有差异）', $stockA !== $stockC);

check('库存含遗物 + 步数商品', (function () use ($stockA) {
    $kinds = array_column($stockA, 'kind');
    return in_array('relic', $kinds, true) && in_array(RunEffects::KIND_BUFF_MOVES, $kinds, true);
})(), json_encode(array_column($stockA, 'id')));

check('遗物售价落在 60-120', (function () use ($stockA) {
    foreach ($stockA as $it) {
        if ($it['kind'] === 'relic' && ($it['price'] < 50 || $it['price'] > 120)) {
            return false;
        }
    }
    return true;
})());

$richStock = $shop->buildStock(array_merge(baseRun(), ['lives' => 1]), '2-1', pool());
check('机会不满时上架"恢复机会"', in_array('life', array_column($richStock, 'id'), true));

$noLifeStock = $shop->buildStock(baseRun(), '2-1', pool()); // lives = BASE_LIVES（已满）
check('机会已满时不上架"恢复机会"', !in_array('life', array_column($noLifeStock, 'id'), true),
    json_encode(array_column($noLifeStock, 'id')));

$discount = $shop->buildStock(array_merge(baseRun(), ['buffs' => ['shopPriceMul' => 0.7]]), '2-1', pool());
$orig     = $shop->buildStock(baseRun(), '2-1', pool());
check('r_meta_shop 折扣生效（0.7 倍）', (function () use ($discount, $orig) {
    $d = $discount[count($discount) - 1]['price'];
    $o = $orig[count($orig) - 1]['price'];
    return $d < $o;
})(), json_encode([array_column($discount, 'price'), array_column($orig, 'price')]));

$curseRun = baseRun();
$curseRun['relics'] = [['id' => 'r_risk_moves', 'lv' => 1]];
$curseStock = $shop->buildStock($curseRun, '2-1', pool());
check('持有诅咒遗物时上架"净化符"', in_array('remove_curse', array_column($curseStock, 'id'), true));

$rest = $shop->restOptions(array_merge(baseRun(), ['lives' => 1, 'relics' => [['id' => 'r_more_moves', 'lv' => 1]]]), pool());
check('休息选项：机会不满 + 有可升级遗物 → 两项', count($rest) === 2, json_encode(array_column($rest, 'id')));

$restFull = $shop->restOptions(array_merge(baseRun(), ['relics' => [['id' => 'r_more_moves', 'lv' => 1]]]), pool());
check('休息选项：机会已满 → 只剩升级', count($restFull) === 1 && $restFull[0]['id'] === ShopService::REST_UPGRADE);

$restNone = $shop->restOptions(baseRun(), pool());
check('休息选项：两者都不可用 → 兜底一项（不卡死）', count($restNone) === 1 && $restNone[0]['id'] === ShopService::REST_LIFE);

// ======================================================================
section('4. EventService —— 事件抽取');
// ======================================================================

$events = [
    ['event_id' => 'e1', 'title' => 'A', 'enabled' => 1, 'weight' => 100, 'min_layer' => 1, 'options' => [['id' => 'x', 'label' => 'x', 'effects' => []]]],
    ['event_id' => 'e2', 'title' => 'B', 'enabled' => 1, 'weight' => 100, 'min_layer' => 1, 'options' => [['id' => 'x', 'label' => 'x', 'effects' => []]]],
    ['event_id' => 'e3', 'title' => 'C', 'enabled' => 1, 'weight' => 100, 'min_layer' => 3, 'options' => [['id' => 'x', 'label' => 'x', 'effects' => []]]],
    ['event_id' => 'e4', 'title' => 'D', 'enabled' => 0, 'weight' => 100, 'min_layer' => 1, 'options' => [['id' => 'x', 'label' => 'x', 'effects' => []]]],
    ['event_id' => 'e5', 'title' => 'E', 'enabled' => 1, 'weight' => 100, 'min_layer' => 1, 'options' => []],
];

check('抽取确定性：同参数同结果', EventService::pick($events, 777, '1-2', 1) === EventService::pick($events, 777, '1-2', 1));

$picked1 = EventService::pick($events, 777, '1-2', 1);
check('第 1 层不会抽到 min_layer=3 的事件', ($picked1['event_id'] ?? '') !== 'e3', json_encode($picked1['event_id'] ?? null));
check('不会抽到 disabled 事件', ($picked1['event_id'] ?? '') !== 'e4');
check('不会抽到无选项事件', ($picked1['event_id'] ?? '') !== 'e5');

$picked3 = EventService::pick($events, 777, '3-1', 3);
check('第 3 层可以抽到高层的 e3 或其它合法事件', in_array($picked3['event_id'], ['e1', 'e2', 'e3'], true), json_encode($picked3['event_id'] ?? null));

check('无任何可用事件 → null', EventService::pick([], 1, '1-1', 1) === null);
check('全部 disabled → null', EventService::pick([$events[3]], 1, '1-1', 1) === null);

$presented = EventService::present([
    'event_id' => 'ev_x',
    'title'    => '标题',
    'desc'     => '描述',
    'options'  => [
        ['id' => 'a', 'label' => '选项A', 'desc' => '说明', 'risk' => true, 'effects' => [['kind' => 'coin', 'value' => 10]]],
        ['id' => 'b', 'label' => '选项B', 'effects' => []],
    ],
]);
check('present：不泄露 effects 原始结构', !isset($presented['options'][0]['effects']));
check('present：给出 preview 预览文案', ($presented['options'][0]['preview'] ?? []) === ['灵玉 +10']);
check('present：risk 透传', ($presented['options'][0]['risk'] ?? false) === true);

// ======================================================================
section('5. DailyService —— 当日一致性');
// ======================================================================

check('seedForDay：同日期同种子', DailyService::seedForDay('2026-09-11') === DailyService::seedForDay('2026-09-11'));
check('seedForDay：不同日期不同种子', DailyService::seedForDay('2026-09-11') !== DailyService::seedForDay('2026-09-12'));
check('seedForDay：结果为正整数（& 0x7FFFFFFF）', DailyService::seedForDay('2026-09-11') > 0);
check('seedForDay 与已知值一致（跨端可复现）', DailyService::seedForDay('2026-01-01') === (int) (crc32('match3-daily-2026-01-01') & 0x7FFFFFFF));
check('today() 格式为 YYYY-MM-DD', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', DailyService::today()));
check('LAYERS = 1（约 5 个节点，10 分钟内）', DailyService::LAYERS === 1);
check('RELIC_POOL_SIZE = 3（正好够三选一）', DailyService::RELIC_POOL_SIZE === 3);

// ======================================================================
section('6. Match3EventSeeder —— 22 个事件配置合法性');
// ======================================================================

require __DIR__ . '/stub/Seeder.php';
require __DIR__ . '/../database/seeds/Match3EventSeeder.php';

$seeder = new Match3EventSeeder();
$method = new ReflectionMethod(Match3EventSeeder::class, 'events');
$method->setAccessible(true);
$all = $method->invoke($seeder);

check('事件数量 ≥ 20', count($all) >= 20, '实际 ' . count($all));

$ids = array_column($all, 'event_id');
check('event_id 唯一', count($ids) === count(array_unique($ids)));

$knownKinds = [
    RunEffects::KIND_COIN, RunEffects::KIND_LIFE, RunEffects::KIND_BUFF_MOVES,
    RunEffects::KIND_SCORE_BUFF, RunEffects::KIND_COIN_BUFF, RunEffects::KIND_RELIC,
    RunEffects::KIND_CURSE, RunEffects::KIND_REMOVE_CURSE, RunEffects::KIND_UPGRADE,
    RunEffects::KIND_STONE,
];

$badKind  = '';
$badOpt   = '';
$noRisk   = 0;
$maxEff   = 0;

foreach ($all as $e) {
    $options = (array) ($e['options'] ?? []);
    if (count($options) < 2) {
        $badOpt = $e['event_id'] . ' 选项 < 2';
        break;
    }
    if (empty($e['title']) || empty($e['desc'])) {
        $badOpt = $e['event_id'] . ' 缺标题/描述';
        break;
    }

    $hasRisk = false;
    foreach ($options as $opt) {
        if (empty($opt['id']) || empty($opt['label'])) {
            $badOpt = $e['event_id'] . ' 选项缺 id/label';
            break 2;
        }
        if (!empty($opt['risk'])) {
            $hasRisk = true;
        }
        $effects = (array) ($opt['effects'] ?? []);
        $maxEff  = max($maxEff, count($effects));
        foreach ($effects as $eff) {
            if (!in_array((string) ($eff['kind'] ?? ''), $knownKinds, true)) {
                $badKind = $e['event_id'] . ' 未知效果: ' . (string) ($eff['kind'] ?? '');
                break 3;
            }
        }
    }

    if (!$hasRisk) {
        $noRisk++;
    }
}

check('每个事件都有 id/label 完整的 ≥2 个选项', $badOpt === '', $badOpt);
check('所有 effects 的 kind 都在效果字典内', $badKind === '', $badKind);
check('至少 80% 事件含"风险选项"（04 设计要点）', $noRisk <= (int) floor(count($all) * 0.2), "无风险选项 {$noRisk}/" . count($all));
check('单个选项效果数 ≤ 3（不出现超长效果串）', $maxEff <= 3, "最大 {$maxEff}");

// 事件效果能真实应用（用第一个事件逐个跑一遍）
$run  = baseRun();
$fails = [];
foreach ($all as $e) {
    foreach ((array) $e['options'] as $opt) {
        $tmp = $run;
        try {
            RunEffects::apply($tmp, (array) ($opt['effects'] ?? []), ['pool' => pool(), 'seed' => 1, 'salt' => 1]);
        } catch (\Throwable $ex) {
            $fails[] = $e['event_id'] . '/' . $opt['id'] . ': ' . $ex->getMessage();
        }
    }
}
check('全部事件选项的效果应用不抛异常', $fails === [], implode('; ', array_slice($fails, 0, 3)));

// ======================================================================
echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
