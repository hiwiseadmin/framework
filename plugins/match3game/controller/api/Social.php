<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\model\Match3PvpBattle;
use plugin\match3game\service\RewardBridgeService;
use plugin\match3game\service\analytics\Match3Funnel;
use plugin\match3game\service\social\FriendService;
use plugin\match3game\service\social\GroupBattleService;
use plugin\match3game\service\social\PvpService;
use plugin\match3game\service\social\RankService;
use plugin\match3game\service\social\RedisCounter;
use plugin\match3game\service\social\SeasonService;
use plugin\match3game\service\social\SocialException;
use plugin\match3game\service\social\SocialRules;

/**
 * 消消乐社交 API（Phase 5，05 §A4.1）
 *
 * 【URL 约定】本框架的插件 API 走 catch-all 自动路由：
 *   `/api/match3game/{controller}/{action}` → `controller/api/{Controller}::{action}`
 * 因此实际端点为 `/api/match3game/social/battleCreate` 形式。
 *
 * 05 §A4.1 的 REST 风格路径（`/api/game/match3/battle/create`）需要显式路由文件
 * （`route/api.php`）——本仓库**所有插件都没有 route/api.php**，且既有的
 * `/api/match3game/battle/*`、`/api/match3game/roguelike/*` 都依赖自动路由，
 * 故这里保持一致（详见 07 §5.4 的偏差说明）。
 *
 * | 05 §A4.1 端点                        | 本插件端点                                  |
 * |-------------------------------------|--------------------------------------------|
 * | POST battle/create                  | POST /api/match3game/social/battleCreate    |
 * | POST battle/accept                  | POST /api/match3game/social/battleAccept    |
 * | GET  battle/list                    | POST /api/match3game/social/battleList      |
 * | GET  battle/{battleId}              | POST /api/match3game/social/battleDetail    |
 * | GET  rank/friend                    | POST /api/match3game/social/rankFriend      |
 * | GET  rank/group                     | POST /api/match3game/social/rankGroup       |
 * | GET  rank/daily                     | POST /api/match3game/social/rankDaily       |
 * | POST friend/gift                    | POST /api/match3game/social/giftSend        |
 * | GET  friend/gifts                   | POST /api/match3game/social/giftList        |
 * | POST friend/gift/claim              | POST /api/match3game/social/giftClaim       |
 * | POST friend/rescue                  | POST /api/match3game/social/rescue          |
 * | GET  season/current                 | POST /api/match3game/social/seasonCurrent   |
 * | POST season/claim                   | POST /api/match3game/social/seasonClaim     |
 * | GET  group/current                  | POST /api/match3game/social/groupCurrent    |
 * （另加 battleRefuse / rankSeason / share / invite 四个补充端点；invite 见 7.6）
 */
class Social extends Base
{
    protected PvpService $pvp;

    protected RankService $rank;

    protected FriendService $friends;

    protected SeasonService $season;

    protected GroupBattleService $group;

    protected RedisCounter $counter;

    protected RewardBridgeService $bridge;

    protected function initialize(): void
    {
        parent::initialize();

        $this->rank    = new RankService();
        $this->pvp     = new PvpService(new SeasonService($this->rank), $this->rank);
        $this->season  = new SeasonService($this->rank);
        $this->group   = new GroupBattleService($this->rank);
        $this->friends = new FriendService();
        $this->counter = new RedisCounter();
        $this->bridge  = new RewardBridgeService();
    }

    /* ======================================================================
     *  对战
     * ====================================================================== */

    /** 创建对战（05 §A4.2） */
    public function battleCreate()
    {
        try {
            $out = $this->pvp->create(
                $this->userId(),
                (string) $this->payload('mode', Match3PvpBattle::MODE_FRIEND),
                (string) $this->payload('stageCode', $this->payload('stage_code', '')),
                (int) $this->payload('opponentId', $this->payload('opponent_id', 0)),
                (string) $this->payload('groupId', $this->payload('group_id', '')),
                (array) $this->payload('relics', [])
            );
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /** 接受挑战 */
    public function battleAccept()
    {
        try {
            $out = $this->pvp->accept($this->userId(), (string) $this->payload('battleId', $this->payload('battle_id', '')));
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /** 拒绝挑战 */
    public function battleRefuse()
    {
        try {
            $out = $this->pvp->refuse($this->userId(), (string) $this->payload('battleId', $this->payload('battle_id', '')));
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /** 我的对战列表 */
    public function battleList()
    {
        return $this->success('ok', $this->pvp->listBattles(
            $this->userId(),
            (string) $this->payload('box', 'all'),
            (int) $this->payload('page', 1),
            (int) $this->payload('size', 20)
        ));
    }

    /** 对战详情（盲打：我未完成时看不到对手分数） */
    public function battleDetail()
    {
        try {
            $out = $this->pvp->detail($this->userId(), (string) $this->payload('battleId', $this->payload('battle_id', '')));
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /* ======================================================================
     *  排行榜
     * ====================================================================== */

    /**
     * 好友榜
     *
     * 好友列表由前端 `wx.getFriendCloudStorage` 取得后传入 `friend_ids`
     * （服务端不维护好友关系表，规避隐私合规风险）。
     */
    public function rankFriend()
    {
        $friendIds = (array) $this->payload('friend_ids', $this->payload('friendIds', []));

        return $this->success('ok', $this->rank->friendRank(
            $this->userId(),
            array_map('intval', $friendIds),
            (int) $this->payload('limit', 50)
        ));
    }

    /** 每日挑战榜 */
    public function rankDaily()
    {
        $day = $this->payload('day', null);

        return $this->success('ok', $this->rank->dailyRank(
            $this->userId(),
            is_string($day) && $day !== '' ? $day : null,
            (int) $this->payload('limit', 50)
        ));
    }

    /** 群内 + 群间榜 */
    public function rankGroup()
    {
        $groupId  = (string) $this->payload('groupId', $this->payload('group_id', ''));
        $periodId = (string) $this->payload('periodId', $this->payload('period_id', ''));

        if ($groupId === '') {
            return $this->error('缺少 groupId', [], SocialException::CODE_BAD_REQUEST);
        }
        if ($periodId === '') {
            $periodId = SocialRules::periodId();
        }

        return $this->success('ok', [
            'periodId' => $periodId,
            'members'  => $this->rank->groupMemberRank($groupId, $periodId, $this->userId(), (int) $this->payload('limit', 50)),
            'board'    => $this->rank->groupBoardRank($periodId, 20),
        ]);
    }

    /** 赛季榜 */
    public function rankSeason()
    {
        $seasonId = (string) $this->payload('seasonId', $this->payload('season_id', ''));
        if ($seasonId === '') {
            $seasonId = $this->season->currentSeasonId();
        }

        return $this->success('ok', $this->rank->seasonRank($seasonId, $this->userId(), (int) $this->payload('limit', 50)));
    }

    /* ======================================================================
     *  好友助力
     * ====================================================================== */

    /** 送体力 / 送道具 */
    public function giftSend()
    {
        try {
            $out = $this->friends->gift(
                $this->userId(),
                (int) $this->payload('targetId', $this->payload('target_id', 0)),
                (string) $this->payload('type', 'energy'),
                (int) $this->payload('count', 1),
                (string) $this->payload('prop', 'prop.hammer')
            );
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /** 待领取列表 */
    public function giftList()
    {
        return $this->success('ok', $this->friends->gifts(
            $this->userId(),
            (int) $this->payload('status', 0),
            (int) $this->payload('limit', 50)
        ));
    }

    /** 领取助力 */
    public function giftClaim()
    {
        try {
            $out = $this->friends->claim($this->userId(), (array) $this->payload('ids', []));
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /** 卡关求助（同一关失败 3 次后可发起） */
    public function rescue()
    {
        try {
            $out = $this->friends->rescue(
                $this->userId(),
                (string) $this->payload('stageCode', $this->payload('stage_code', '')),
                (int) $this->payload('helperId', $this->payload('helper_id', 0))
            );
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /* ======================================================================
     *  赛季
     * ====================================================================== */

    /** 当前赛季 + 我的段位（05 §A4.1 第 12 项） */
    public function seasonCurrent()
    {
        return $this->success('ok', $this->season->current($this->userId()));
    }

    /** 领取赛季段位奖励（05 §A4.1 第 13 项） */
    public function seasonClaim()
    {
        try {
            $out = $this->season->claim($this->userId());
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /* ======================================================================
     *  群擂台
     * ====================================================================== */

    /** 当前群擂台（05 §A4.1 第 14 项） */
    public function groupCurrent()
    {
        $groupId = (string) $this->payload('groupId', $this->payload('group_id', ''));
        if ($groupId === '') {
            return $this->error('缺少 groupId', [], SocialException::CODE_BAD_REQUEST);
        }

        try {
            $out = $this->group->current(
                $this->userId(),
                $groupId,
                (string) $this->payload('periodId', $this->payload('period_id', ''))
            );
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        return $this->success('ok', $out);
    }

    /* ======================================================================
     *  分享（05 §A7.4）
     * ====================================================================== */

    /**
     * 分享上报（冷却 + 每日 N 次奖励 + `nonce` 幂等）
     *
     * 【合规】分享是**可选**的：不调这个接口照样能正常玩（05 §A8.1 第 9 条）。
     * 卡片图片由前端离屏 Canvas 渲染（P5-F03），服务端只发奖励与冷却控制。
     *
     * 【`nonce` 为什么存在（2026-10-04）】微信小游戏 `wx.shareAppMessage` 的 `success`
     *   只表示"分享面板已拉起"，用户随后取消**没有回调**；部分机型还会重复触发回调。
     *   于是同一次分享可能被上报多次 —— 客户端在**拉起面板前**生成 nonce 并随请求带来，
     *   服务端对同一 nonce **只结算一次**，之后原样返回首次结果（`idempotent: true`）。
     *   老客户端不带 nonce → 走原逻辑，完全兼容。
     */
    public function share()
    {
        $userId = $this->userId();
        $today  = date('Y-m-d');

        // 限流参数（可配，缺省取常量）：见 `config/match3.php` 的 `share` 段
        $dailyLimit = $this->shareConfig('daily_limit', SocialRules::SHARE_DAILY_LIMIT);
        $cooldown   = $this->shareConfig('cooldown', SocialRules::SHARE_COOLDOWN);

        // ⓪ 幂等：同一 nonce 直接返回首次结果（不发奖、不再占配额）
        $nonce   = trim((string) $this->payload('nonce', ''));
        $idemKey = $nonce !== '' ? 'share:once:' . $userId . ':' . md5($nonce) : '';

        if ($idemKey !== '') {
            $prev = $this->counter->getJson($idemKey);
            if ($prev !== null) {
                return $this->success('ok', $prev + ['idempotent' => true]);
            }
        }

        // ① 每日 N 次
        $key   = 'share:' . $userId . ':' . $today;
        $count = $this->counter->incr($key, 86400 * 2);
        if ($count === false) {
            $count = 1; // Redis 不可用 → 不阻断分享（合规优先：不能不让人分享）
        }

        if ($count > $dailyLimit) {
            $this->counter->decr($key);

            return $this->shareResult([
                'rewarded'    => false,
                'reason'      => 'daily_limit',
                'remainToday' => 0,
                'message'     => '今日分享奖励已领完（不影响继续分享）',
            ], $idemKey);
        }

        // ② 冷却（统一为 code=0 + data 区分，与「配额用尽」一致，降低前端分支复杂度）
        //
        // ⚠️ `acquire()` 只在**确实被占用**时才返回 false；Redis 故障返回 true（放行），
        //    不会把服务端故障显示成"你分享太频繁"（2026-10-04 修）。
        if ($this->counter->available() && !$this->counter->acquire('share:cd:' . $userId, $cooldown)) {
            $this->counter->decr($key);

            return $this->shareResult([
                'rewarded'    => false,
                'reason'      => 'cooldown',
                'remainToday' => max(0, $dailyLimit - ($count - 1)),
                'cooldown'    => $cooldown,
                'message'     => '分享太频繁，请 ' . $cooldown . ' 秒后再试',
            ], $idemKey);
        }

        // ③ 发奖
        $this->bridge->grantCoins($userId, SocialRules::SHARE_REWARD_COIN, RewardReason::SHARE_REWARD, [
            'scene' => (string) $this->payload('scene', 'battle'),
        ]);

        return $this->shareResult([
            'rewarded'    => true,
            'coin'        => SocialRules::SHARE_REWARD_COIN,
            'coinItem'    => Match3Constants::ITEM_COINS,
            'remainToday' => max(0, $dailyLimit - $count),
            'cooldown'    => $cooldown,
        ], $idemKey);
    }

    /**
     * 统一出口：把本次结果**记成该 nonce 的首次结果**，再返回
     *
     * 【为什么必须统一出口】幂等快照要覆盖三种情形（发奖 / 冷却 / 配额用尽）；
     *   分三处各写一遍必然漏一处，漏掉的那处就会"重复上报时给出不同答案"。
     *
     * 【并发兜底】同一 nonce 的两个请求同时进来（极罕见）可能都算出结果，
     *   后写的覆盖先写的 —— 重复发奖由**每日配额**兜住，不再额外加锁（避免复杂化）。
     *
     * @param array<string,mixed> $data
     */
    protected function shareResult(array $data, string $idemKey): \think\response\Json
    {
        if ($idemKey !== '') {
            $this->counter->put($idemKey, $data, 86400 * 2);
        }

        return $this->success('ok', $data);
    }

    /**
     * 读 `match3.share.{key}`，缺省/非法时用常量
     *
     * 【为什么要兜底常量】配置是运营可改的，写进库里的值可能为 0 或负数；
     *   冷却取 0 会让 `SET NX EX 0` 报错、配额取 0 会让分享永远"已领完"——
     *   两者都是"配置写错 → 玩家被挡"，所以非法值一律回落到常量。
     */
    protected function shareConfig(string $key, int $default): int
    {
        if (!function_exists('config')) {
            return $default;
        }

        $all = config('match3game.match3', []);
        $raw = is_array($all) ? ($all['share'][$key] ?? null) : null;

        if (!is_numeric($raw)) {
            return $default;
        }

        return max(1, (int) $raw);
    }

    /* ======================================================================
     *  邀请（补齐「invite 端点不存在」缺口）
     * ====================================================================== */

    /**
     * 邀请好友
     *
     * - 不传 `invite_code`：返回我的邀请码 + 分享卡（不消耗任何额度）
     * - 传他人 `invite_code`：一次性绑定并双向发奖（防刷见 FriendService::invite）
     */
    public function invite()
    {
        $userId    = $this->userId();
        $scene     = (string) $this->payload('scene', 'invite');
        $inviteCode = (string) $this->payload('inviteCode', $this->payload('invite_code', ''));

        try {
            $out = $this->friends->invite($userId, $inviteCode);
        } catch (SocialException $e) {
            return $this->error($e->getMessage(), [], $e->errorCode());
        }

        $shareCfg = (array) (config('match3game.match3')['share'] ?? []);

        Match3Funnel::emit(Match3Funnel::INVITE, $userId, [
            'scene'     => $scene,
            'accepted'  => !empty($out['accepted']) ? 1 : 0,
            'inviterId' => (int) ($out['inviterId'] ?? 0),
        ]);

        return $this->success('ok', array_merge($out, [
            'scene'      => $scene,
            'shareTitle' => (string) ($shareCfg['invite_title'] ?? '一起来玩消消乐，领金币啦！'),
            'sharePath'  => (string) ($shareCfg['invite_path'] ?? '/pages/index/index') . '?inviteCode=' . $out['inviteCode'],
            'shareImage' => (string) ($shareCfg['invite_image'] ?? ''),
        ]));
    }
}
