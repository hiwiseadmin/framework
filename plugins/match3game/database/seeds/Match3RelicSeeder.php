<?php

declare(strict_types=1);

use think\migration\Seeder;

/**
 * 消消乐遗物种子（05 §B5 的 30 条）
 *
 * 遗物 = 对 Level Schema 的一次 patch，纯配置，策划可自助维护。
 *
 * ⚠️ 与 05 §B5 原文的两处偏离（都是有意的，已在 desc 注明）：
 *   ① `r_extra_spawner`：原文给 `/regions/0/layers/terrain/0 = "SSSSSSSSSS"`（10 字符），
 *      与 9 列棋盘冲突（LevelValidator 会判定行长度不一致）。改为引擎级标记
 *      `/runtime/extraSpawnerCols`，前端据此多生成一列，服务端不破坏 schema。
 *   ② `r_cursed_narrow`：原文给 `/board/cols = -1`，会让地形越界（board.cols 8 < terrain 9 列）。
 *      同样改为引擎级标记 `/runtime/cursedNarrowCols`，由前端裁列。
 *   两条都保留原意（"补货更快" / "棋盘少一列"），待前端引擎支持后再回到纯 schema patch。
 *
 * 稀有度分布：common 9 / rare 13 / epic 6 / cursed 2（原文汇总写作 10/12/6/2，以明细表为准）。
 */
class Match3RelicSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [];
        foreach ($this->relics() as $r) {
            $rows[] = array_merge($r, [
                'patch'      => json_encode($r['patch'], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        $this->table('match3_relic')->insert($rows)->save();
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    protected function relics(): array
    {
        return [
            /* ============ 棋盘改造 board（5） ============ */
            $this->relic('r_stripe_start', '起手剑', 'common', 'board', '开局随机 1 个元素变横条纹',
                [['op' => 'add', 'path' => '/runtime/initialSpecials', 'value' => ['stripe_h' => 1]]]),
            $this->relic('r_bomb_start', '火药桶', 'rare', 'board', '开局随机 1 个元素变炸弹',
                [['op' => 'add', 'path' => '/runtime/initialSpecials', 'value' => ['bomb' => 1]]]),
            $this->relic('r_rainbow_start', '灵鸟', 'epic', 'board', '开局随机 1 个元素变魔力鸟',
                [['op' => 'add', 'path' => '/runtime/initialSpecials', 'value' => ['rainbow' => 1]]], 300),
            $this->relic('r_extra_spawner', '涌泉', 'rare', 'board', '棋盘多一列生成器（补货更快）',
                [['op' => 'add', 'path' => '/runtime/extraSpawnerCols', 'value' => 1]], 300),
            $this->relic('r_clear_obstacle', '开山斧', 'common', 'board', '开局清除所有固定障碍',
                [['op' => 'add', 'path' => '/runtime/clearBlockers', 'value' => true]]),

            /* ============ 规则改造 rule（6） ============ */
            $this->relic('r_four_bomb', '易爆体质', 'epic', 'rule', '四连也生成炸弹（原为条纹）',
                [['op' => 'replace', 'path' => '/rules/specials/match4', 'value' => 'bomb']]),
            $this->relic('r_five_bomb', '连环爆', 'rare', 'rule', '五连直线也生成炸弹',
                [['op' => 'replace', 'path' => '/rules/specials/match5Line', 'value' => 'bomb']]),
            $this->relic('r_combo_boost', '连锁感应', 'rare', 'rule', '连击倍率 +0.5',
                [['op' => 'add', 'path' => '/rules/comboMul', 'value' => 0.5, 'mode' => 'accumulate']]),
            $this->relic('r_min_match_2', '心有灵犀', 'epic', 'rule', '三连判定降为二连（大幅降低难度）',
                [['op' => 'replace', 'path' => '/rules/minMatch', 'value' => 2]], 300),
            $this->relic('r_special_chain', '连锁引爆', 'epic', 'rule', '特殊元素消除时引爆相邻特殊元素',
                [['op' => 'add', 'path' => '/rules/specialChain', 'value' => true]]),
            $this->relic('r_cascade_bonus', '落雪', 'common', 'rule', '每次连锁额外 +100 分',
                [['op' => 'add', 'path' => '/rules/scoreBonus/cascade', 'value' => 100]]),

            /* ============ 资源改造 resource（5） ============ */
            $this->relic('r_more_moves', '时间沙漏', 'common', 'resource', '每关步数 +5',
                [['op' => 'add', 'path' => '/rules/moves', 'value' => 5, 'mode' => 'accumulate']]),
            $this->relic('r_score_mul', '聚宝盆', 'rare', 'resource', '分数 ×1.2',
                [['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 1.2]]),
            $this->relic('r_coin_boost', '招财', 'common', 'resource', '局内灵玉 +30%',
                [['op' => 'mul', 'path' => '/rules/coinMul', 'value' => 1.3]]),
            $this->relic('r_gold_ice', '点金手', 'rare', 'resource', '每破除一块冰 +50 分',
                [['op' => 'add', 'path' => '/rules/scoreBonus/ice', 'value' => 50]]),
            $this->relic('r_free_prop', '锦囊', 'rare', 'resource', '每关开局赠送 1 个随机道具',
                [['op' => 'add', 'path' => '/runtime/freePropPerLevel', 'value' => 1, 'mode' => 'accumulate']]),

            /* ============ 行为改造 behavior（5） ============ */
            $this->relic('r_no_choco', '净化符', 'rare', 'behavior', '巧克力不再蔓延',
                [['op' => 'replace', 'path' => '/behaviours/[id=choc]/interval', 'value' => 0]]),
            $this->relic('r_slow_boss', '定身咒', 'rare', 'behavior', 'Boss 出招间隔 +1',
                [['op' => 'add', 'path' => '/behaviours/[id=boss]/interval', 'value' => 1, 'mode' => 'accumulate']]),
            $this->relic('r_reverse_belt', '逆转', 'common', 'behavior', '传送带方向反转',
                [['op' => 'replace', 'path' => '/behaviours/[id=belt]/params/reverse', 'value' => true]]),
            $this->relic('r_no_hazard', '封印', 'rare', 'behavior', '障碍生成器停止产出',
                [['op' => 'replace', 'path' => '/behaviours/[id=haz]/interval', 'value' => 0]]),
            $this->relic('r_vine_slow', '除草', 'common', 'behavior', '藤蔓生长间隔 +2',
                [['op' => 'add', 'path' => '/behaviours/[id=vine]/interval', 'value' => 2, 'mode' => 'accumulate']]),

            /* ============ 风险改造 risk（4，双刃剑） ============ */
            $this->relic('r_risk_moves', '破釜沉舟', 'epic', 'risk', '步数 -5，但分数 ×2',
                [
                    ['op' => 'add', 'path' => '/rules/moves', 'value' => -5, 'mode' => 'accumulate'],
                    ['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 2],
                ], 300),
            $this->relic('r_risk_target', '背水一战', 'rare', 'risk', '目标数量 +40%，但步数 +8',
                [
                    ['op' => 'mul', 'path' => '/rules/goalMul', 'value' => 1.4],
                    ['op' => 'add', 'path' => '/rules/moves', 'value' => 8, 'mode' => 'accumulate'],
                ]),
            $this->relic('r_cursed_narrow', '一线天', 'cursed', 'risk', '棋盘少一列，但每关灵玉 ×2',
                [
                    ['op' => 'add', 'path' => '/runtime/cursedNarrowCols', 'value' => 1],
                    ['op' => 'mul', 'path' => '/rules/coinMul', 'value' => 2],
                ]),
            $this->relic('r_cursed_fragile', '玻璃心', 'cursed', 'risk', '失败机会 -1，但分数 ×1.5',
                [
                    ['op' => 'add', 'path' => '/run/lives', 'value' => -1],
                    ['op' => 'mul', 'path' => '/rules/scoreMul', 'value' => 1.5],
                ]),

            /* ============ meta 向（5） ============ */
            $this->relic('r_meta_stone', '聚灵', 'common', 'meta', '本局结算灵石 +50%',
                [['op' => 'mul', 'path' => '/run/metaMul', 'value' => 1.5]]),
            $this->relic('r_meta_no_lose', '护心镜', 'epic', 'meta', '本局第一次失败不消耗机会',
                [['op' => 'add', 'path' => '/run/protectOnce', 'value' => true]], 300),
            $this->relic('r_meta_shop', '慧眼', 'common', 'meta', '商店所有商品价格 -30%',
                [['op' => 'mul', 'path' => '/run/shopPriceMul', 'value' => 0.7]]),
            $this->relic('r_meta_reroll', '天机', 'rare', 'meta', '每关额外获得 1 次免费重选',
                [['op' => 'add', 'path' => '/run/freeRerollPerLevel', 'value' => 1, 'mode' => 'accumulate']]),
            $this->relic('r_meta_elite', '勇者', 'rare', 'meta', '精英节点奖励翻倍，但精英关难度 +20%',
                [
                    ['op' => 'mul', 'path' => '/run/eliteRewardMul', 'value' => 2],
                    ['op' => 'mul', 'path' => '/run/eliteDMul', 'value' => 1.2],
                ]),
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $patch
     */
    protected function relic(
        string $relicId,
        string $name,
        string $rarity,
        string $category,
        string $desc,
        array $patch,
        int $unlockCost = 0
    ): array {
        return [
            'relic_id'    => $relicId,
            'name'        => $name,
            'rarity'      => $rarity,
            'category'    => $category,
            'desc'        => $desc,
            'icon'        => 'relic_' . $relicId . '.png',
            'patch'       => $patch,
            'weight'      => 100,
            'unlock_cost' => $unlockCost,
            'enabled'     => 1,
        ];
    }
}
