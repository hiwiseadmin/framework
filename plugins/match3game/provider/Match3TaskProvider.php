<?php

declare(strict_types=1);

namespace plugin\match3game\provider;

use plugin\match3game\contract\Match3Constants;

/**
 * 消消乐任务 / 成就定义（§2.3 / §4.1）
 *
 * 提供日常任务与一次性成就的定义数组，由 BootServiceProvider
 * 通过 ExtPoint 注册到 wise-game-task（game.task.definition / game.task.achievement）。
 *
 * 事件名对齐 Match3Constants 与 BattleService::reportSettleEvents 上报：
 *   level_pass / three_star / total_score / prop_used
 */
class Match3TaskProvider
{
    /**
     * 日常任务定义（category=daily）
     *
     * @return array<int, array{code:string, category:string, goal:array, rewards:array}>
     */
    public static function tasks(): array
    {
        return [
            // 每日通关 1 关
            [
                'code'     => 'match3_daily_pass_1',
                'category' => 'daily',
                'goal'     => ['type' => 'count', 'event' => Match3Constants::EVENT_LEVEL_PASS, 'target_count' => 1],
                'rewards'  => [['item_id' => Match3Constants::ITEM_COINS, 'count' => 100]],
            ],
            // 每日通关 3 关
            [
                'code'     => 'match3_daily_pass_3',
                'category' => 'daily',
                'goal'     => ['type' => 'count', 'event' => Match3Constants::EVENT_LEVEL_PASS, 'target_count' => 3],
                'rewards'  => [['item_id' => Match3Constants::ITEM_COINS, 'count' => 300]],
            ],
            // 每日使用 1 次道具
            [
                'code'     => 'match3_daily_prop_1',
                'category' => 'daily',
                'goal'     => ['type' => 'count', 'event' => Match3Constants::EVENT_PROP_USED, 'target_count' => 1],
                'rewards'  => [['item_id' => Match3Constants::ITEM_COINS, 'count' => 50]],
            ],
        ];
    }

    /**
     * 一次性成就定义
     *
     * @return array<int, array{code:string, goal:array, rewards:array, title:string}>
     */
    public static function achievements(): array
    {
        return [
            // 首次通关
            [
                'code'    => 'match3_ach_first_pass',
                'goal'    => ['event' => Match3Constants::EVENT_LEVEL_PASS, 'target_count' => 1],
                'rewards' => [['item_id' => Match3Constants::ITEM_DIAMOND, 'count' => 5]],
                'title'   => '初次通关',
            ],
            // 首次三星通关
            [
                'code'    => 'match3_ach_three_star',
                'goal'    => ['event' => Match3Constants::EVENT_THREE_STAR, 'target_count' => 1],
                'rewards' => [['item_id' => Match3Constants::ITEM_DIAMOND, 'count' => 10]],
                'title'   => '三星达人',
            ],
            // 累计通关 50 关
            [
                'code'    => 'match3_ach_pass_50',
                'goal'    => ['event' => Match3Constants::EVENT_LEVEL_PASS, 'target_count' => 50],
                'rewards' => [['item_id' => Match3Constants::ITEM_DIAMOND, 'count' => 50]],
                'title'   => '闯关高手',
            ],
        ];
    }
}
