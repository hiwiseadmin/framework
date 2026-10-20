<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\model\Match3FriendHelp;
use plugin\match3game\model\Match3PlayerInventory;
use plugin\match3game\model\Match3PlayerProgress;
use plugin\match3game\service\LevelProgressService;
use plugin\match3game\service\RewardBridgeService;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 好友助力服务（05 §A3.3 / §A7.2，P5-B08）
 *
 * 【配额（Redis 原子计数，防并发超发）】
 *   每日可送 5 次     `match3:gift:{uid}:{date}`      → 05 §A8.1 第 7 条
 *   每日可收 10 次    按 `match3_friend_help` 当日行数（收件方去重更直观）
 *   体力上限 100      读插件自有背包表 `match3_player_inventory`
 *   送 1 次 = +5 体力，赠送者 +20 金币（双向激励）
 *   礼物有效期 24h
 *
 * 【卡关求助】同一关失败 3 次可求助：
 *   失败次数记 `match3:fail:{uid}:{stageCode}`（Redis，7 天）；
 *   Redis 不可用时降级为"查 match3_battle 当天该关的未通关局数"。
 *   好友（helper）打通该关后调用 `resolveRescue()`，求助方直接过关（保留星级）。
 */
class FriendService
{
    protected RedisCounter $counter;

    protected RewardBridgeService $bridge;

    public function __construct(?RedisCounter $counter = null, ?RewardBridgeService $bridge = null)
    {
        $this->counter = $counter ?? new RedisCounter();
        $this->bridge  = $bridge ?? new RewardBridgeService();
    }

    /* ======================================================================
     *  送体力 / 送道具
     * ====================================================================== */

    /**
     * 赠送（05 §A4.1 第 8 项）
     *
     * @param string $type energy / prop
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function gift(
        int $userId,
        int $targetId,
        string $type = Match3FriendHelp::TYPE_ENERGY,
        int $propCount = 1,
        string $propCode = 'prop.hammer'
    ): array {
        $today = date('Y-m-d');

        if ($targetId <= 0 || $targetId === $userId) {
            throw new SocialException('接收者无效', SocialException::CODE_BAD_REQUEST);
        }
        if (!in_array($type, [Match3FriendHelp::TYPE_ENERGY, Match3FriendHelp::TYPE_PROP], true)) {
            throw new SocialException('不支持的助力类型: ' . $type, SocialException::CODE_BAD_REQUEST);
        }

        // ① 每日可送 5 次（Redis 原子自增，失败回滚）
        $sendKey = 'gift:' . $userId . ':' . $today;
        $sent    = $this->counter->incr($sendKey, 86400 * 2);
        if ($sent === false) {
            // Redis 不可用 → MySQL 降级计数
            $sent = $this->dbSentToday($userId, $today) + 1;
        }
        if ($sent > SocialRules::GIFT_DAILY_SEND) {
            if ($this->counter->available()) {
                $this->counter->decr($sendKey);
            }
            throw new SocialException(
                '今日赠送次数已用完（' . SocialRules::GIFT_DAILY_SEND . ' 次）',
                SocialException::CODE_LIMIT
            );
        }

        // ② 接收方今日上限 10 次（防小号刷）
        $received = Match3FriendHelp::where('target_id', $targetId)
            ->where('day', $today)
            ->whereIn('help_type', [Match3FriendHelp::TYPE_ENERGY, Match3FriendHelp::TYPE_PROP])
            ->count();
        if ($received >= SocialRules::GIFT_DAILY_RECEIVE) {
            if ($this->counter->available()) {
                $this->counter->decr($sendKey);
            }
            throw new SocialException('对方今日接收已达上限', SocialException::CODE_LIMIT);
        }

        // ③ 体力上限
        if ($type === Match3FriendHelp::TYPE_ENERGY && SocialRules::energyAtCap($this->energyBalance($targetId))) {
            if ($this->counter->available()) {
                $this->counter->decr($sendKey);
            }
            throw new SocialException('对方体力已满，无法接收', SocialException::CODE_ENERGY_FULL);
        }

        $payload = $type === Match3FriendHelp::TYPE_ENERGY
            ? ['count' => SocialRules::ENERGY_PER_GIFT]
            : ['prop' => $propCode, 'count' => max(1, $propCount)];

        $gift = Match3FriendHelp::create([
            'help_type' => $type,
            'user_id'   => $userId,
            'target_id' => $targetId,
            'payload'   => $payload,
            'status'    => Match3FriendHelp::STATUS_PENDING,
            'day'       => $today,
            'create_time' => date('Y-m-d H:i:s'),
            'expire_at' => date('Y-m-d H:i:s', time() + SocialRules::GIFT_TTL_HOURS * 3600),
        ]);

        // ④ 赠送者奖励 +20 金币（双向有用）
        $this->bridge->grantCoins($userId, SocialRules::GIFT_REWARD_COIN, RewardReason::FRIEND_GIFT, [
            'target_id' => $targetId, 'help_type' => $type,
        ]);

        return [
            'giftId'       => (int) $gift->id,
            'type'         => $type,
            'payload'      => $payload,
            'remainSend'   => max(0, SocialRules::GIFT_DAILY_SEND - $sent),
            'senderReward' => SocialRules::GIFT_REWARD_COIN,
            'expireAt'     => date('Y-m-d H:i:s', time() + SocialRules::GIFT_TTL_HOURS * 3600),
        ];
    }

    /**
     * 待领取列表（05 §A4.1 第 9 项）
     *
     * @return array{list:array<int,array<string,mixed>>, total:int, energyTotal:int}
     */
    public function gifts(int $userId, int $status = Match3FriendHelp::STATUS_PENDING, int $limit = 50): array
    {
        $this->expireStale();

        $limit = max(1, min(100, $limit));
        $rows  = Match3FriendHelp::where('target_id', $userId)
            ->where('status', $status)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $list  = [];
        $total = 0;
        foreach ($rows as $r) {
            $count = (int) ($r['payload']['count'] ?? 0);
            $total += (int) $r['help_type'] === Match3FriendHelp::TYPE_ENERGY ? $count : 0;

            $list[] = [
                'id'        => (int) $r['id'],
                'type'      => (string) $r['help_type'],
                'typeText'  => Match3FriendHelp::typeText((string) $r['help_type']),
                'from'      => (int) $r['user_id'],
                'payload'   => (array) $r['payload'],
                'status'    => (int) $r['status'],
                'expireAt'  => $r['expire_at'] ?? null,
                'createdAt' => $r['create_time'] ?? null,
            ];
        }

        return ['list' => $list, 'total' => count($list), 'energyTotal' => $total];
    }

    /**
     * 领取（05 §A4.1 第 10 项）
     *
     * 【并发安全】逐条 `UPDATE ... WHERE status = 0`，靠 affected rows 判定归属，
     * 同一礼物被并发领取时只有一个请求能成功发奖。
     *
     * @param  array<int,int> $ids 空 = 领取全部待领取
     * @return array{claimed:array<int,array<string,mixed>>, energy:int}
     * @throws SocialException
     */
    public function claim(int $userId, array $ids = []): array
    {
        if ($ids === []) {
            $ids = Match3FriendHelp::where('target_id', $userId)
                ->where('status', Match3FriendHelp::STATUS_PENDING)
                ->where('expire_at', '>', date('Y-m-d H:i:s'))
                ->column('id');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        if ($ids === []) {
            return ['claimed' => [], 'energy' => 0];
        }

        $rows = Match3FriendHelp::whereIn('id', $ids)
            ->where('target_id', $userId)
            ->where('status', Match3FriendHelp::STATUS_PENDING)
            ->select()
            ->toArray();

        $claimed = [];
        $energy  = 0;

        foreach ($rows as $row) {
            $affected = Db::name('match3_friend_help')
                ->where('id', (int) $row['id'])
                ->where('target_id', $userId)
                ->where('status', Match3FriendHelp::STATUS_PENDING)
                ->update([
                    'status'     => Match3FriendHelp::STATUS_CLAIMED,
                    'claimed_at' => date('Y-m-d H:i:s'),
                ]);

            if ($affected <= 0) {
                continue; // 已被并发领取
            }

            $count   = (int) ($row['payload']['count'] ?? 0);
            $type    = (string) $row['help_type'];
            $granted = 0;

            if ($type === Match3FriendHelp::TYPE_ENERGY) {
                $granted = min($count, max(0, SocialRules::ENERGY_CAP - $this->energyBalance($userId)));
                if ($granted > 0) {
                    $this->bridge->grantRewards($userId, [['item_id' => Match3Constants::ITEM_ENERGY, 'count' => $granted]], RewardReason::FRIEND_GIFT_CLAIM, [
                        'gift_id' => (int) $row['id'], 'from' => (int) $row['user_id'],
                    ]);
                    $energy += $granted;
                }
            } elseif ($type === Match3FriendHelp::TYPE_PROP) {
                $propCode = (string) ($row['payload']['prop'] ?? 'prop.hammer');
                if ($propCode !== '' && $count > 0) {
                    $this->bridge->grantRewards($userId, [['item_id' => $propCode, 'count' => $count]], RewardReason::FRIEND_GIFT_CLAIM, [
                        'gift_id' => (int) $row['id'], 'from' => (int) $row['user_id'],
                    ]);
                    $granted = $count;
                }
            }

            $claimed[] = [
                'id'      => (int) $row['id'],
                'type'    => $type,
                'granted' => $granted,
                'from'    => (int) $row['user_id'],
            ];
        }

        return ['claimed' => $claimed, 'energy' => $energy];
    }

    /* ======================================================================
     *  邀请（05 §A4.1 补充端点 invite）
     * ====================================================================== */

    /**
     * 邀请：返回我的邀请码（可传入他人邀请码完成一次绑定并双向发奖）
     *
     * 【防刷】
     *   · 受邀者只能绑定一次（Redis `invite:bound:{uid}`，90 天）
     *   · 邀请者每日奖励人数上限 `INVITE_DAILY_LIMIT`
     *   · Redis 不可用时 fail-open（放行，计数精度下降但不阻断）
     *
     * @return array<string,mixed>
     * @throws SocialException 邀请码非法
     */
    public function invite(int $userId, string $inviteCode = ''): array
    {
        $myCode = SocialRules::encodeInviteCode($userId);

        $out = [
            'inviteCode'    => $myCode,
            'accepted'      => false,
            'inviterId'     => 0,
            'inviteeReward' => 0,
            'inviterReward' => 0,
            'alreadyBound'  => false,
        ];

        $inviteCode = trim($inviteCode);
        if ($inviteCode === '' || strtoupper($inviteCode) === $myCode) {
            return $out; // 只取自己的邀请码
        }

        $inviterId = SocialRules::decodeInviteCode($inviteCode);
        if ($inviterId <= 0 || $inviterId === $userId) {
            throw new SocialException('邀请码无效', SocialException::CODE_BAD_REQUEST);
        }

        // ① 受邀者一次性绑定（防同一账号反复绑定领奖）
        $boundKey = 'invite:bound:' . $userId;
        if ($this->counter->available() && !$this->counter->acquire($boundKey, SocialRules::INVITE_BIND_TTL)) {
            $out['alreadyBound'] = true;

            return $out;
        }

        // ② 邀请者每日奖励上限（超额只记绑定、不再发给邀请者）
        $inviterPaid = false;
        $dailyKey    = 'invite:daily:' . $inviterId . ':' . date('Y-m-d');
        $today       = $this->counter->incr($dailyKey, 86400 * 2);
        if ($today === false || $today <= SocialRules::INVITE_DAILY_LIMIT) {
            $this->bridge->grantCoins($inviterId, SocialRules::INVITE_INVITER_REWARD, RewardReason::INVITE_REWARD, [
                'role' => 'inviter', 'invitee_id' => $userId,
            ]);
            $inviterPaid = true;
        } elseif ($this->counter->available()) {
            $this->counter->decr($dailyKey);
        }

        // ③ 受邀者奖励
        $this->bridge->grantCoins($userId, SocialRules::INVITE_INVITEE_REWARD, RewardReason::INVITE_REWARD, [
            'role' => 'invitee', 'inviter_id' => $inviterId,
        ]);

        return [
            'inviteCode'    => $myCode,
            'accepted'      => true,
            'inviterId'     => $inviterId,
            'inviteeReward' => SocialRules::INVITE_INVITEE_REWARD,
            'inviterReward' => $inviterPaid ? SocialRules::INVITE_INVITER_REWARD : 0,
            'alreadyBound'  => false,
        ];
    }

    /* ======================================================================
     *  卡关求助
     * ====================================================================== */

    /**
     * 记录一次失败，返回当前连续失败次数
     */
    public function recordFail(int $userId, string $stageCode): int
    {
        $count = $this->counter->incr('fail:' . $userId . ':' . $stageCode, 86400 * 7);

        if ($count === false) {
            // 降级：查当天该关的未通关局数（近似值）
            return $this->dbFailCount($userId, $stageCode);
        }

        return $count;
    }

    /**
     * 卡关求助（05 §A4.1 第 11 项）
     *
     * @param int $helperId 0 = 向所有好友广播
     * @return array<string,mixed>
     * @throws SocialException
     */
    public function rescue(int $userId, string $stageCode, int $helperId = 0): array
    {
        if ($stageCode === '') {
            throw new SocialException('缺少 stageCode', SocialException::CODE_BAD_REQUEST);
        }

        $fails = $this->failCount($userId, $stageCode);
        if ($fails < SocialRules::RESCUE_FAIL_TIMES) {
            throw new SocialException(
                sprintf('同一关卡失败 %d 次后才能求助（当前 %d 次）', SocialRules::RESCUE_FAIL_TIMES, $fails),
                SocialException::CODE_LIMIT
            );
        }

        // 同一关同一好友只允许一条未领取的求助，避免刷屏
        // 注意：用独立的 stage_code 列查询（payload 是 text 存的 JSON，不能走 -> JSON path）
        $exists = Match3FriendHelp::where('user_id', $userId)
            ->where('help_type', Match3FriendHelp::TYPE_RESCUE)
            ->where('status', Match3FriendHelp::STATUS_PENDING)
            ->where('stage_code', $stageCode)
            ->find();

        if ($exists) {
            return ['id' => (int) $exists->id, 'reused' => true, 'stageCode' => $stageCode];
        }

        $help = Match3FriendHelp::create([
            'help_type'  => Match3FriendHelp::TYPE_RESCUE,
            'user_id'    => $userId,
            'target_id'  => max(0, $helperId),
            'payload'    => ['stageCode' => $stageCode, 'fails' => $fails],
            'stage_code' => $stageCode,
            'status'     => Match3FriendHelp::STATUS_PENDING,
            'day'        => date('Y-m-d'),
            'create_time' => date('Y-m-d H:i:s'),
            'expire_at'  => date('Y-m-d H:i:s', time() + SocialRules::GIFT_TTL_HOURS * 3600),
        ]);

        return [
            'id'        => (int) $help->id,
            'reused'    => false,
            'stageCode' => $stageCode,
            'helperId'  => $helperId,
        ];
    }

    /**
     * 好友打通了某关 → 兑现所有指望着他的求助（求助方直接过关，保留星级）
     *
     * 由 `BattleService::submit()` 在通关后调用。
     *
     * @return int 兑现的求助数
     */
    public function resolveRescue(int $helperId, string $stageCode, int $stars = 0, int $score = 0): int
    {
        $rows = Match3FriendHelp::where('help_type', Match3FriendHelp::TYPE_RESCUE)
            ->where('status', Match3FriendHelp::STATUS_PENDING)
            ->whereIn('target_id', [0, $helperId])
            ->where('stage_code', $stageCode)
            ->select()
            ->toArray();

        if ($rows === []) {
            return 0;
        }

        $progress = new LevelProgressService();
        $done     = 0;

        foreach ($rows as $row) {
            $requesterId = (int) $row['user_id'];
            if ($requesterId === $helperId) {
                continue;
            }

            $affected = Db::name('match3_friend_help')
                ->where('id', (int) $row['id'])
                ->where('status', Match3FriendHelp::STATUS_PENDING)
                ->update([
                    'status'     => Match3FriendHelp::STATUS_CLAIMED,
                    'claimed_at' => date('Y-m-d H:i:s'),
                ]);

            if ($affected <= 0) {
                continue;
            }

            // 求助方直接过关（星级取好友成绩与既有成绩的较大值）
            $best = max($stars, 1);
            $progress->recordPass($requesterId, $stageCode, $best, $score);
            $done++;

            ContextualLogger::info('[match3] 卡关求助已兑现', [
                'requester' => $requesterId, 'helper' => $helperId,
                'stage_code' => $stageCode, 'stars' => $best,
            ]);
        }

        return $done;
    }

    /**
     * 连续失败次数（Redis 优先，降级查库）
     */
    public function failCount(int $userId, string $stageCode): int
    {
        $count = $this->counter->get('fail:' . $userId . ':' . $stageCode);

        return $count ?? $this->dbFailCount($userId, $stageCode);
    }

    /**
     * 过期未领取的礼物（定时任务调用）
     */
    public function expireStale(int $limit = 500): int
    {
        return Db::name('match3_friend_help')
            ->where('status', Match3FriendHelp::STATUS_PENDING)
            ->where('expire_at', '<', date('Y-m-d H:i:s'))
            ->limit($limit)
            ->update(['status' => Match3FriendHelp::STATUS_EXPIRED]);
    }

    /* ======================================================================
     *  内部
     * ====================================================================== */

    /**
     * 体力余额（插件自有背包表）
     */
    public function energyBalance(int $userId): int
    {
        try {
            $row = Match3PlayerInventory::where('user_id', $userId)
                ->where('item_id', Match3Constants::ITEM_ENERGY)
                ->find();

            return $row ? (int) $row->count : 0;
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] 读取体力失败: ' . $e->getMessage(), ['user_id' => $userId]);

            return 0;
        }
    }

    protected function dbSentToday(int $userId, string $day): int
    {
        return Match3FriendHelp::where('user_id', $userId)
            ->where('day', $day)
            ->whereIn('help_type', [Match3FriendHelp::TYPE_ENERGY, Match3FriendHelp::TYPE_PROP])
            ->count();
    }

    protected function dbFailCount(int $userId, string $stageCode): int
    {
        try {
            // 降级口径：当天该关"有过对局但没有通关"的局数
            $total = Db::name('match3_battle')
                ->where('user_id', $userId)
                ->where('level_code', $stageCode)
                ->where('started_at', '>=', date('Y-m-d') . ' 00:00:00')
                ->count();

            $passed = Match3PlayerProgress::where('user_id', $userId)
                ->where('level_code', $stageCode)
                ->where('best_stars', '>', 0)
                ->count();

            return $passed > 0 ? min($total, SocialRules::RESCUE_FAIL_TIMES - 1) : $total;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
