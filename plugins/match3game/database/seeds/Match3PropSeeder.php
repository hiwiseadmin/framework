<?php

declare(strict_types=1);

use think\migration\Seeder;

/**
 * 消消乐道具定义种子（§5.3）
 *
 * 局内道具（kind=2）：锤子 / 炸弹 / 加步 / 洗牌
 * 开局道具（kind=1）：双倍奖励
 */
class Match3PropSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $props = [
            [
                'code'             => 'prop.hammer',
                'name'             => '魔法锤',
                'kind'             => 2,
                'effect'           => json_encode(['type' => 'hammer']),
                'price'            => json_encode(['coin' => 500]),
                'ad_unit_id'       => null,
                'per_battle_limit' => 3,
                'icon'             => null,
                'status'           => 1,
            ],
            [
                'code'             => 'prop.bomb',
                'name'             => '炸弹',
                'kind'             => 2,
                'effect'           => json_encode(['type' => 'bomb', 'radius' => 2]),
                'price'            => json_encode(['coin' => 800]),
                'ad_unit_id'       => null,
                'per_battle_limit' => 2,
                'icon'             => null,
                'status'           => 1,
            ],
            [
                'code'             => 'prop.add_moves',
                'name'             => '加步道具',
                'kind'             => 2,
                'effect'           => json_encode(['type' => 'add_moves', 'count' => 5]),
                'price'            => json_encode(['ad' => 1]),
                'ad_unit_id'       => 'adunit_match3_addmoves',
                'per_battle_limit' => 3,
                'icon'             => null,
                'status'           => 1,
            ],
            [
                'code'             => 'prop.shuffle',
                'name'             => '洗牌',
                'kind'             => 2,
                'effect'           => json_encode(['type' => 'shuffle']),
                'price'            => json_encode(['coin' => 300]),
                'ad_unit_id'       => null,
                'per_battle_limit' => 2,
                'icon'             => null,
                'status'           => 1,
            ],
            [
                'code'             => 'prop.double_reward',
                'name'             => '双倍奖励',
                'kind'             => 1,
                'effect'           => json_encode(['type' => 'double_reward']),
                'price'            => json_encode(['diamond' => 10]),
                'ad_unit_id'       => null,
                'per_battle_limit' => 1,
                'icon'             => null,
                'status'           => 1,
            ],
            // 复活道具（2026-09-27 补）
            //
            // 【为什么之前没有它也能"跑"】`ad/revive` 扣的是 `prop.revive`，但背包层只看
            // `match3_player_inventory`、**不校验道具表** → 玩家恒为 0 个 → 一点就
            // HTTP 400 `210201 物品不足: prop.revive 需要 1, 当前 0`（真机）。
            // 补上这一行后：商店可购买 / `ad/scene` 能报出持有量 / 扣减有据可依。
            // `kind=2`（局内道具）——它只在局内复活弹窗用，不进对局道具栏
            //（道具栏是固定列表 `PROP_CODES`，见 `battle-view.ts`）。
            [
                'code'             => 'prop.revive',
                'name'             => '复活道具',
                'kind'             => 2,
                'effect'           => json_encode(['type' => 'revive']),
                'price'            => json_encode(['coin' => 1000]),
                'ad_unit_id'       => null,
                'per_battle_limit' => 1,
                'icon'             => null,
                'status'           => 1,
            ],
        ];

        $rows = [];
        foreach ($props as $p) {
            $p['create_time'] = $now;
            $p['update_time'] = $now;
            $rows[] = $p;
        }

        $table = $this->table('match3_prop');
        $table->insert($rows)->save();
    }
}
