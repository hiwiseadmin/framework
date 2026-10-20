<?php

declare(strict_types=1);

namespace plugin\match3game\contract;

/**
 * 消消乐插件全局常量（§4.3 game_id 与命名空间约定）
 *
 * 全链路 game_id 统一为 match3_game。
 */
final class Match3Constants
{
    /** 游戏固定标识（stage/energy/task/core 各 API 的 game_id 参数均传此值） */
    public const GAME_ID = 'match3_game';

    // ================================================================
    //  任务/成就事件名（上报到 wise-game-task，§6.3 / §7.2）
    // ================================================================

    /** 通关（任意星级） */
    public const EVENT_LEVEL_PASS = 'level_pass';

    /** 三星通关 */
    public const EVENT_THREE_STAR = 'three_star';

    /** 累计得分（按 score 增量累计） */
    public const EVENT_TOTAL_SCORE = 'total_score';

    /** 首次通关 */
    public const EVENT_FIRST_PASS = 'first_pass';

    /** 使用道具 */
    public const EVENT_PROP_USED = 'prop_used';

    // ================================================================
    //  排行榜 board key（§6.3 / config/match3.php leaderboard 节）
    // ================================================================

    public const BOARD_SCORE = 'match3:score';
    public const BOARD_STAR  = 'match3:star';

    // ================================================================
    //  广告场景（§6.4）
    // ================================================================

    public const AD_SCENE_REVIVE    = 'revive';
    public const AD_SCENE_ADD_MOVES = 'add_moves';
    public const AD_SCENE_COINS     = 'coins';

    // ================================================================
    //  背包 item_id（与 model/Match3PlayerInventory 常量保持一致）
    // ================================================================

    public const ITEM_COINS   = 'coins';
    public const ITEM_DIAMOND = 'diamond';
    public const ITEM_ENERGY  = 'energy';

    /**
     * 灵石（局外货币，肉鸽结算产出 / 永久解锁消耗）
     *
     * P6-B04 起**余额以背包为准**（match3_player_inventory），
     * match3_meta_progress.spirit_stone 降级为只读快照（同事务同步，仅供展示）。
     */
    public const ITEM_SPIRIT_STONE = 'spirit_stone';

    private function __construct()
    {
        // 工具类，禁止实例化
    }
}
