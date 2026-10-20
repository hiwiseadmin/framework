<?php

declare(strict_types=1);

namespace plugin\match3game\contract;

/**
 * 发奖 / 扣减的**受控 reason 词表**（P6-B04）
 *
 * 【为什么要有这个词表】
 *   `inventory_log.reason` 是审计的唯一入口：出问题时第一个要查的就是"这笔钱从哪来"。
 *   如果 reason 是各调用点随手写的字符串，会出现：
 *     · 同一个语义写成多种拼写（`match3_prop_use` / `prop_use` / `battle_use`）
 *     · 拼写错误无人发现，流水里出现"孤儿 reason"
 *     · 运营/客服看不懂，只能靠猜
 *   因此把 reason 收敛成**常量 + 分类 + 中文文案**的封闭词表。
 *
 * 【用法】
 *   $bridge->grantCoins($userId, 200, RewardReason::SHARE_REWARD, ['scene' => 'battle']);
 *
 * 【新增 reason 的规矩】
 *   ① 必须同时给出中文文案（审计要给人看）
 *   ② 必须归入 gain 或 cost（决定流水方向，便于对账）
 *   ③ 不要复用（宁可新增），否则无法定位来源
 *
 * 【纯逻辑】不依赖框架与 DB，可离线单测（tests/verify-reward-audit.php）。
 */
final class RewardReason
{
    /** 发放（余额增加） */
    public const CAT_GAIN = 'gain';

    /** 扣减（余额减少） */
    public const CAT_COST = 'cost';

    /** 未知（不在词表内） */
    public const CAT_UNKNOWN = 'unknown';

    /* ---------------- 发放类 ---------------- */

    /** 固定关卡通关奖励（金币/道具） */
    public const LEVEL_PASS = 'level_pass';

    /** 肉鸽局结算灵石（含通关加成与 buffs 加成） */
    public const ROGUE_FINISH = 'rogue_finish';

    /** 好友助力：赠送者奖励（+20 金币） */
    public const FRIEND_GIFT = 'friend_gift';

    /** 好友助力：接收者领取（体力/道具） */
    public const FRIEND_GIFT_CLAIM = 'friend_gift_claim';

    /** 分享奖励（每日 3 次，可选分享，不强制） */
    public const SHARE_REWARD = 'share_reward';

    /** 邀请奖励（受邀者绑定一次；邀请者/受邀者各得） */
    public const INVITE_REWARD = 'invite_reward';

    /** 看广告奖励（加步/金币/复活） */
    public const AD_REWARD = 'ad_reward';

    /** 赛季段位奖励 */
    public const SEASON_REWARD = 'season_reward';

    /** 每日挑战奖励 */
    public const DAILY_REWARD = 'daily_reward';

    /** 商店购买获得（道具/遗物） */
    public const SHOP_BUY_GAIN = 'shop_buy_gain';

    /** 运营后台手动发放（GM） */
    public const GM_GRANT = 'gm_grant';

    /** 清理僵尸局时补发的安慰灵石（match3:cleanup --grant-stone） */
    public const CLEANUP_STONE = 'cleanup_stone';

    /* ---------------- 扣减类 ---------------- */

    /** 开局预扣道具 */
    public const BATTLE_START = 'battle_start';

    /** 局内使用道具 */
    public const PROP_USE = 'prop_use';

    /** 灵石永久解锁（遗物/遗物位/起点） */
    public const META_UNLOCK = 'meta_unlock';

    /** 商店购买扣费（灵玉/灵石） */
    public const SHOP_BUY_COST = 'shop_buy_cost';

    /** 看广告复活：消耗复活道具 */
    public const REVIVE_CONSUME = 'revive_consume';

    /**
     * 完整词表：[reason => ['cat' => ..., 'text' => ...]]
     *
     * @return array<string, array{cat:string, text:string}>
     */
    public static function all(): array
    {
        return [
            // 发放
            self::LEVEL_PASS        => ['cat' => self::CAT_GAIN, 'text' => '关卡通关奖励'],
            self::ROGUE_FINISH      => ['cat' => self::CAT_GAIN, 'text' => '肉鸽局结算灵石'],
            self::FRIEND_GIFT       => ['cat' => self::CAT_GAIN, 'text' => '好友助力·赠送者奖励'],
            self::FRIEND_GIFT_CLAIM => ['cat' => self::CAT_GAIN, 'text' => '好友助力·领取'],
            self::SHARE_REWARD      => ['cat' => self::CAT_GAIN, 'text' => '分享奖励'],
            self::INVITE_REWARD     => ['cat' => self::CAT_GAIN, 'text' => '邀请奖励'],
            self::AD_REWARD         => ['cat' => self::CAT_GAIN, 'text' => '看广告奖励'],
            self::SEASON_REWARD     => ['cat' => self::CAT_GAIN, 'text' => '赛季段位奖励'],
            self::DAILY_REWARD      => ['cat' => self::CAT_GAIN, 'text' => '每日挑战奖励'],
            self::SHOP_BUY_GAIN     => ['cat' => self::CAT_GAIN, 'text' => '商店购买获得'],
            self::GM_GRANT          => ['cat' => self::CAT_GAIN, 'text' => '运营手动发放'],
            self::CLEANUP_STONE     => ['cat' => self::CAT_GAIN, 'text' => '僵尸局补偿灵石'],

            // 扣减
            self::BATTLE_START      => ['cat' => self::CAT_COST, 'text' => '开局预扣道具'],
            self::PROP_USE          => ['cat' => self::CAT_COST, 'text' => '局内使用道具'],
            self::META_UNLOCK       => ['cat' => self::CAT_COST, 'text' => '灵石解锁'],
            self::SHOP_BUY_COST     => ['cat' => self::CAT_COST, 'text' => '商店购买扣费'],
            self::REVIVE_CONSUME    => ['cat' => self::CAT_COST, 'text' => '复活消耗道具'],
        ];
    }

    /**
     * 发放类 reason 列表
     *
     * @return array<int,string>
     */
    public static function gains(): array
    {
        return self::ofCategory(self::CAT_GAIN);
    }

    /**
     * 扣减类 reason 列表
     *
     * @return array<int,string>
     */
    public static function costs(): array
    {
        return self::ofCategory(self::CAT_COST);
    }

    /**
     * 是否在词表内
     */
    public static function isKnown(string $reason): bool
    {
        return isset(self::all()[$reason]);
    }

    /**
     * 分类：gain / cost / unknown
     */
    public static function categoryOf(string $reason): string
    {
        return (string) (self::all()[$reason]['cat'] ?? self::CAT_UNKNOWN);
    }

    /**
     * 中文文案（未知 reason 原样返回，便于发现孤儿值）
     */
    public static function text(string $reason): string
    {
        return (string) (self::all()[$reason]['text'] ?? $reason);
    }

    /**
     * 校验：合法返回 null，否则返回问题描述
     *
     * 为什么返回 null 而不是抛异常：**审计不能阻断玩家拿到奖励**。
     * 调用方拿到非 null 时应记 warning，但流程继续。
     */
    public static function validate(string $reason): ?string
    {
        if ($reason === '') {
            return 'reason 不能为空';
        }

        if (self::isKnown($reason)) {
            return null;
        }

        $hint = self::closest($reason);

        return '未知 reason: ' . $reason
            . '（请使用 RewardReason 常量'
            . ($hint !== null ? '，是否想用 ' . $hint . '？' : '') . '）';
    }

    /* ---------------- 内部 ---------------- */

    /**
     * @return array<int,string>
     */
    protected static function ofCategory(string $cat): array
    {
        $out = [];
        foreach (self::all() as $reason => $def) {
            if ($def['cat'] === $cat) {
                $out[] = $reason;
            }
        }

        return $out;
    }

    /**
     * 用编辑距离猜一个最接近的已知 reason（帮助快速定位拼写错误）
     */
    protected static function closest(string $reason): ?string
    {
        $best    = null;
        $bestLen = PHP_INT_MAX;

        foreach (array_keys(self::all()) as $known) {
            $dist = levenshtein($reason, $known);
            if ($dist < $bestLen && $dist <= max(3, (int) (strlen($known) / 2))) {
                $best    = $known;
                $bestLen = $dist;
            }
        }

        return $best;
    }
}
