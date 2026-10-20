<?php
/**
 * 发奖审计自检（P6-B04）
 *
 *   php src/plugins/match3game/tests/verify-reward-audit.php
 *
 * 覆盖 `RewardReason` 受控词表与 `Match3Constants` 物品标识 —— 都是**纯常量/纯函数**，
 * 可离线校验。这里锁死的是"审计口径"：
 *   · reason 必须封闭（不能出现拼错的孤儿值）
 *   · 每个 reason 必须有中文文案与分类（审计要给人看、要能对账）
 *   · 分类与方向必须自洽（gain/cost）
 *   · 物品标识唯一（否则流水里不同物品会串）
 *
 * 为什么值得单独建一个脚本：inventory_log 是**经济系统的唯一对账依据**，
 * 词表一旦失控（拼写漂移），事后无法追溯"这笔钱哪来的"。
 */

declare(strict_types=1);

require __DIR__ . '/../contract/Match3Constants.php';
require __DIR__ . '/../contract/RewardReason.php';

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;

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

// ======================================================================
section('1. 词表结构完整性');
// ======================================================================

$all = RewardReason::all();

check('词表非空', $all !== []);
check('每个 reason 都有 cat 与 text', (function () use ($all) {
    foreach ($all as $def) {
        if (!isset($def['cat'], $def['text']) || $def['text'] === '') {
            return false;
        }
    }
    return true;
})());
check('分类只能是 gain 或 cost', (function () use ($all) {
    foreach ($all as $def) {
        if (!in_array($def['cat'], [RewardReason::CAT_GAIN, RewardReason::CAT_COST], true)) {
            return false;
        }
    }
    return true;
})());
check('reason 键与常量值一致（无空键）', (function () use ($all) {
    foreach ($all as $reason => $def) {
        if ($reason === '') {
            return false;
        }
    }
    return true;
})());

// 常量 → 值 一一对应（防止复制粘贴导致两个常量同值）
$ref = new ReflectionClass(RewardReason::class);
$consts = $ref->getConstants();
$reasonConsts = [];
foreach ($consts as $name => $value) {
    if (str_starts_with($name, 'CAT_')) {
        continue;
    }
    $reasonConsts[$name] = $value;
}

check('reason 常量无重复值', count($reasonConsts) === count(array_unique($reasonConsts)),
    json_encode(array_diff_assoc($reasonConsts, array_unique($reasonConsts))));
check('常量数量 = 词表条目数', count($reasonConsts) === count($all),
    count($reasonConsts) . ' vs ' . count($all));
check('每个常量值都在词表里', (function () use ($reasonConsts, $all) {
    foreach ($reasonConsts as $value) {
        if (!isset($all[$value])) {
            return false;
        }
    }
    return true;
})());

// ======================================================================
section('2. 分类与方向自洽');
// ======================================================================

$gains = RewardReason::gains();
$costs = RewardReason::costs();

check('发放类非空', $gains !== []);
check('扣减类非空', $costs !== []);
check('gain 与 cost 无交集', array_intersect($gains, $costs) === []);
check('gain + cost = 全部', count($gains) + count($costs) === count($all));

check('LEVEL_PASS 属于发放', RewardReason::categoryOf(RewardReason::LEVEL_PASS) === RewardReason::CAT_GAIN);
check('ROGUE_FINISH 属于发放（灵石结算）', RewardReason::categoryOf(RewardReason::ROGUE_FINISH) === RewardReason::CAT_GAIN);
check('META_UNLOCK 属于扣减（灵石解锁）', RewardReason::categoryOf(RewardReason::META_UNLOCK) === RewardReason::CAT_COST);
check('BATTLE_START 属于扣减', RewardReason::categoryOf(RewardReason::BATTLE_START) === RewardReason::CAT_COST);
check('PROP_USE 属于扣减', RewardReason::categoryOf(RewardReason::PROP_USE) === RewardReason::CAT_COST);
check('未知值归类为 unknown', RewardReason::categoryOf('nope') === RewardReason::CAT_UNKNOWN);

// ======================================================================
section('3. 校验与文案');
// ======================================================================

check('isKnown 对已知返回 true', RewardReason::isKnown(RewardReason::SHARE_REWARD));
check('isKnown 对未知返回 false', !RewardReason::isKnown('share-reward'));

check('validate 对已知返回 null', RewardReason::validate(RewardReason::AD_REWARD) === null);
check('validate 对空串报错', RewardReason::validate('') !== null);
check('validate 对未知报错', RewardReason::validate('battle_use') !== null);

$err = (string) RewardReason::validate('prop_uses');
check('validate 对近似拼写给出建议（levenshtein）', str_contains($err, 'prop_use'),
    $err);

check('text 对已知返回中文', RewardReason::text(RewardReason::SEASON_REWARD) === '赛季段位奖励');
check('text 对未知原样返回（便于发现孤儿值）', RewardReason::text('xxx') === 'xxx');

check('全部 reason 的文案互不相同（避免审计歧义）', (function () use ($all) {
    $texts = array_column($all, 'text');

    return count($texts) === count(array_unique($texts));
})());

// ======================================================================
section('4. 业务覆盖：每个发奖场景都有受控 reason');
// ======================================================================

$required = [
    'LEVEL_PASS'        => RewardReason::LEVEL_PASS,
    'ROGUE_FINISH'      => RewardReason::ROGUE_FINISH,
    'FRIEND_GIFT'       => RewardReason::FRIEND_GIFT,
    'FRIEND_GIFT_CLAIM' => RewardReason::FRIEND_GIFT_CLAIM,
    'SHARE_REWARD'      => RewardReason::SHARE_REWARD,
    'INVITE_REWARD'     => RewardReason::INVITE_REWARD,
    'AD_REWARD'         => RewardReason::AD_REWARD,
    'SEASON_REWARD'     => RewardReason::SEASON_REWARD,
    'GM_GRANT'          => RewardReason::GM_GRANT,
    'BATTLE_START'      => RewardReason::BATTLE_START,
    'PROP_USE'          => RewardReason::PROP_USE,
    'META_UNLOCK'       => RewardReason::META_UNLOCK,
];

$missing = [];
foreach ($required as $name => $value) {
    if (!RewardReason::isKnown($value)) {
        $missing[] = $name;
    }
}
check('12 个业务场景的 reason 全部在词表里', $missing === [], implode(',', $missing));

// 历史遗留的自由字符串必须**不再**合法（防止回退）
// 'shop_buy' 之所以要失效：它同时被用于"扣费"和"获得"两个方向，无法对账
$legacy = ['battle_use', 'match3_prop_use', 'reward', 'friend_gift_claim ', 'shop_buy'];
$stillKnown = [];
foreach ($legacy as $old) {
    if (RewardReason::isKnown($old)) {
        $stillKnown[] = $old;
    }
}
check('历史散落字符串已不再是合法 reason', $stillKnown === [], implode(',', $stillKnown));

// ======================================================================
section('5. 物品标识（inventory_log.item_id）');
// ======================================================================

$items = [
    'coins'        => Match3Constants::ITEM_COINS,
    'diamond'      => Match3Constants::ITEM_DIAMOND,
    'energy'       => Match3Constants::ITEM_ENERGY,
    'spirit_stone' => Match3Constants::ITEM_SPIRIT_STONE,
];

check('4 种物品标识互不相同', count($items) === count(array_unique($items)), json_encode($items));
check('灵石标识 = spirit_stone（P6-B04 纳入背包）', Match3Constants::ITEM_SPIRIT_STONE === 'spirit_stone');
check('GAME_ID = match3_game', Match3Constants::GAME_ID === 'match3_game');

echo "\n----------------------------------------\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
