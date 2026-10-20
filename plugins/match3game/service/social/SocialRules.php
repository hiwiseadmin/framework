<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

/**
 * 社交数值与判定规则（05 §A7 数值表，全部为纯函数 → 可离线单测）
 *
 * 把"判定/积分/段位/配额"从服务里抽出来，好处是：
 *   · 策划改数值只动这一个文件；
 *   · 对战判定这种**必须三端一致**的逻辑可以脱离 DB/Redis 验证。
 */
final class SocialRules
{
    /* ======================================================================
     *  一、对战积分（05 §A7.1）
     * ====================================================================== */

    /** 胜（对手分差 > 20%） */
    public const WIN_BIG = 30;
    /** 胜（分差 ≤ 20%） */
    public const WIN_CLOSE = 25;
    /** 负 */
    public const LOSE = -15;
    /** 超时未完成 */
    public const TIMEOUT = -20;
    /** 每日首胜额外 */
    public const FIRST_WIN_BONUS = 10;
    /** "大胜"的分差阈值 */
    public const BIG_GAP_RATIO = 0.20;

    /* ======================================================================
     *  二、段位（05 §A7.1）
     * ====================================================================== */

    /**
     * 段位 → [积分下限, 积分上限]（上限 null = 无上限）
     */
    public const TIERS = [
        'bronze'   => [0, 199],
        'silver'   => [200, 499],
        'gold'     => [500, 999],
        'platinum' => [1000, 1799],
        'diamond'  => [1800, 2999],
        'king'     => [3000, null],
    ];

    public const TIER_NAMES = [
        'bronze'   => '青铜',
        'silver'   => '白银',
        'gold'     => '黄金',
        'platinum' => '铂金',
        'diamond'  => '钻石',
        'king'     => '王者',
    ];

    /** 赛季周期：2 周（05 §A7.1） */
    public const SEASON_DAYS = 14;

    /* ======================================================================
     *  三、好友助力（05 §A7.2）
     * ====================================================================== */

    /** 每日可送出 */
    public const GIFT_DAILY_SEND = 5;
    /** 每日可接收（防小号刷） */
    public const GIFT_DAILY_RECEIVE = 10;
    /** 每次送体力 */
    public const ENERGY_PER_GIFT = 5;
    /** 赠送者奖励（金币/次） */
    public const GIFT_REWARD_COIN = 20;
    /** 体力上限 */
    public const ENERGY_CAP = 100;
    /** 礼物有效期（小时） */
    public const GIFT_TTL_HOURS = 24;
    /** 卡关求助触发：同一关卡失败 3 次 */
    public const RESCUE_FAIL_TIMES = 3;

    /* ======================================================================
     *  四、群擂台（05 §A7.3）
     * ====================================================================== */

    /** 群内 Top5 分数累加 */
    public const GROUP_TOP_N = 5;
    /** 个人每关每日可打次数 */
    public const GROUP_DAILY_PLAYS = 3;
    /** 每期关卡池大小（1 简单 + 1 中等 + 1 困难） */
    public const GROUP_STAGE_POOL = 3;

    /* ======================================================================
     *  五、分享与公开挑战池（05 §A7.4）
     * ====================================================================== */

    /**
     * 分享冷却（秒）—— **缺省值 10**，可被 `match3.share.cooldown` 覆盖
     *
     * 【2026-10-04 从 60 改为 10】原值 60 会误伤正常行为：
     *   微信小游戏 `wx.shareAppMessage` 的 `success` 在**分享面板拉起**时就触发
     *   （用户随后取消不会再有回调），于是"拉起面板"即被记成一次分享并上锁；
     *   玩家连发 2~3 个好友必然撞冷却，看到「分享太频繁，请 60 秒后再试」——
     *   而他自己只"真发出去了一次"。10s 只用于防连点，不再限制正常分享。
     */
    public const SHARE_COOLDOWN = 10;
    /** 每日分享奖励上限（可被 `match3.share.daily_limit` 覆盖） */
    public const SHARE_DAILY_LIMIT = 3;
    /** 每次分享奖励（金币） */
    public const SHARE_REWARD_COIN = 20;
    /** 公开挑战池同时存在的未接受上限 */
    public const LADDER_MAX_OPEN = 3;

    /* ======================================================================
     *  六、邀请（05 §A4.1 补充端点 invite）
     * ====================================================================== */

    /** 邀请码混淆盐（邀请码 = base36(uid + salt)，可反解且不易被顺序猜测） */
    public const INVITE_SALT = 46261875;

    /** 受邀者奖励（金币） */
    public const INVITE_INVITEE_REWARD = 50;

    /** 邀请者奖励（金币/人） */
    public const INVITE_INVITER_REWARD = 50;

    /** 邀请者每日可获奖励人数上限（防刷） */
    public const INVITE_DAILY_LIMIT = 5;

    /** 邀请绑定有效期（秒，90 天） */
    public const INVITE_BIND_TTL = 7776000;

    /* ======================================================================
     *  判定
     * ====================================================================== */

    /**
     * 对战判定（05 §A8.1 第 2 条）
     *
     * 依次比较：分数 → 步数（少者胜）→ 提交时间（先提交者胜）→ 平局
     *
     * @param  array<string,mixed> $a 我方 {"score","moves","finishedAt"(int 时间戳)}
     * @param  array<string,mixed> $b 对方
     * @return array{result:string, side:string, reason:string}
     */
    public static function judge(array $a, array $b): array
    {
        $sa = (int) ($a['score'] ?? 0);
        $sb = (int) ($b['score'] ?? 0);

        if ($sa !== $sb) {
            return [
                'result' => $sa > $sb ? 'win' : 'lose',
                'side'   => $sa > $sb ? 'a' : 'b',
                'reason' => 'score',
            ];
        }

        $ma = (int) ($a['moves'] ?? 0);
        $mb = (int) ($b['moves'] ?? 0);

        if ($ma !== $mb) {
            return [
                'result' => $ma < $mb ? 'win' : 'lose',
                'side'   => $ma < $mb ? 'a' : 'b',
                'reason' => 'moves',
            ];
        }

        $ta = (int) ($a['finishedAt'] ?? 0);
        $tb = (int) ($b['finishedAt'] ?? 0);

        if ($ta !== $tb) {
            return [
                'result' => $ta < $tb ? 'win' : 'lose',
                'side'   => $ta < $tb ? 'a' : 'b',
                'reason' => 'submitTime',
            ];
        }

        return ['result' => 'draw', 'side' => '', 'reason' => 'tie'];
    }

    /**
     * 单场积分变化（05 §A7.1）
     *
     * @param string $result       win / lose / draw / timeout
     * @param int    $myScore
     * @param int    $oppScore
     * @param bool   $firstWinToday 是否当日首胜
     */
    public static function pointsDelta(string $result, int $myScore, int $oppScore, bool $firstWinToday = false): int
    {
        if ($result === 'timeout') {
            return self::TIMEOUT;
        }

        if ($result === 'draw') {
            return 0;
        }

        if ($result === 'lose') {
            return self::LOSE;
        }

        $gap = $oppScore > 0 ? ($myScore - $oppScore) / $oppScore : 1.0;
        $base = $gap > self::BIG_GAP_RATIO ? self::WIN_BIG : self::WIN_CLOSE;

        return $base + ($firstWinToday ? self::FIRST_WIN_BONUS : 0);
    }

    /**
     * 积分区间 → 段位
     */
    public static function tierOf(int $points): string
    {
        $tier = 'bronze';
        foreach (self::TIERS as $name => [$min, $_max]) {
            if ($points >= $min) {
                $tier = $name;
            }
        }

        return $tier;
    }

    /**
     * 段位下限（负分不降段：`-15` 后不低于当前段位下限，05 §A7.1）
     */
    public static function tierFloor(string $tier): int
    {
        return (int) (self::TIERS[$tier][0] ?? 0);
    }

    /**
     * 应用"不降段"下限
     *
     * 注意：判断用的是**扣分前**所在段位（否则从白银掉到 199 会被夹到 200 而永远掉不回青铜）。
     */
    public static function applyFloor(int $newPoints, string $tierBefore): int
    {
        return max($newPoints, self::tierFloor($tierBefore));
    }

    /**
     * 段位中文名
     */
    public static function tierName(string $tier): string
    {
        return self::TIER_NAMES[$tier] ?? $tier;
    }

    /* ======================================================================
     *  配额与周期
     * ====================================================================== */

    /**
     * 群擂台周期 ID（每周一 00:00 刷新，05 §A7.3）
     *
     * 用 ISO-8601 周编号：`2026-W37`
     */
    public static function periodId(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();

        return date('o-\WW', $ts);
    }

    /**
     * 赛季 ID：`S{年}-{第几期}`（一期 2 周，05 §A7.1）
     */
    public static function seasonId(?int $timestamp = null): string
    {
        $ts  = $timestamp ?? time();
        $year = (int) date('o', $ts);
        // 以当年 1 月 1 日为锚点，每 14 天一期
        $dayOfYear = (int) date('z', $ts);
        $seq       = (int) floor($dayOfYear / self::SEASON_DAYS) + 1;

        return sprintf('S%d-%02d', $year, $seq);
    }

    /**
     * 体力是否已达上限（05 §A7.2：超出不接收，提示"已满"）
     */
    public static function energyAtCap(int $current): bool
    {
        return $current >= self::ENERGY_CAP;
    }

    /**
     * 分享是否可用（冷却 `SHARE_COOLDOWN` + 每日 `SHARE_DAILY_LIMIT` 次）
     *
     * @param int $cooldown 冷却秒数（调用方应传**配置值**，缺省用常量）
     */
    public static function shareAllowed(
        int $lastShareAt,
        int $todayCount,
        ?int $now = null,
        ?int $cooldown = null,
        ?int $dailyLimit = null
    ): bool {
        if ($todayCount >= ($dailyLimit ?? self::SHARE_DAILY_LIMIT)) {
            return false;
        }

        return (($now ?? time()) - $lastShareAt) >= ($cooldown ?? self::SHARE_COOLDOWN);
    }

    /* ======================================================================
     *  邀请码（可逆混淆，纯函数 → 可离线单测）
     * ====================================================================== */

    /**
     * 生成邀请码：base36(uid + 盐)，首位大写
     *
     * 邀请码需要能被服务端**反解出邀请者 uid**（否则无法发奖），
     * 因此不能用 hash；加盐偏移只是为了不暴露连续 uid、避免顺序枚举。
     */
    public static function encodeInviteCode(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        return strtoupper(base_convert((string) ($userId + self::INVITE_SALT), 10, 36));
    }

    /**
     * 解析邀请码 → 邀请者 uid；非法（含畸形/伪造）返回 0
     *
     * 通过"往返校验"拒绝任何不能被 encode 还原的字符串：
     * 既挡掉随手输入的乱码，也挡掉超出盐偏移范围的伪造值。
     */
    public static function decodeInviteCode(string $code): int
    {
        $code = strtoupper(trim($code));
        if ($code === '' || preg_match('/^[0-9A-Z]+$/', $code) !== 1) {
            return 0;
        }

        $userId = (int) base_convert($code, 36, 10) - self::INVITE_SALT;
        if ($userId <= 0) {
            return 0;
        }

        return self::encodeInviteCode($userId) === $code ? $userId : 0;
    }

    /**
     * 群标识是否为**加密 ID**（05 §A8.1 第 8 条：DB 不得存明文群名）
     *
     * 判据（启发式，因为微信 openGId 无法在服务端校验签名）：
     *   · 非空、长度 ≥ 8
     *   · 不含中日韩文字（明文群名几乎必然含中文）
     *   · 仅含 base64/urlsafe 或十六进制字符
     *
     * 放在 SocialRules 里是为了能**离线单测**（不依赖 DB/Redis）。
     */
    public static function isEncryptedGroupId(string $groupId): bool
    {
        $groupId = trim($groupId);

        if ($groupId === '' || strlen($groupId) < 8 || strlen($groupId) > 64) {
            return false;
        }
        if (preg_match('/\p{Han}/u', $groupId) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_\-=+\/]+$/', $groupId) === 1;
    }
}
