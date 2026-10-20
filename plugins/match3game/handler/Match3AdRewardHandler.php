<?php

declare(strict_types=1);

namespace plugin\match3game\handler;

use wise\wx\contract\AdRewardHandlerInterface;
use plugin\match3game\contract\Match3Constants;
use plugin\match3game\contract\RewardReason;
use plugin\match3game\service\AdQuotaService;
use plugin\match3game\service\RewardBridgeService;
use wise\support\context\ContextualLogger;

/**
 * 消消乐激励视频广告奖励处理器（§4.1 / §6.4）
 *
 * 微信服务端回调验签/幂等后，AdService 调用本 handler 发放奖励。
 * 幂等性由 wise-wx 的 wx_ad_log.transaction_id 唯一键保证，本 handler 无需重复校验。
 *
 * 场景 → ad_unit_id 映射（config/match3.php ad.scenes）：
 *   revive    → 复活本局（客户端即时加步，服务端仅埋点）
 *   add_moves → +3 步（客户端即时，服务端仅埋点）
 *   coins     → 发金币
 */
class Match3AdRewardHandler implements AdRewardHandlerInterface
{
    protected RewardBridgeService $bridge;

    /** 广告每日配额计数（与 ad/scene 共用计数源） */
    protected AdQuotaService $quota;

    /** @var array 广告场景映射 */
    protected array $scenes;

    public function __construct(?RewardBridgeService $bridge = null, ?AdQuotaService $quota = null)
    {
        $config       = config('match3game.match3', []);
        $this->bridge = $bridge ?? new RewardBridgeService($config);
        $this->quota  = $quota ?? new AdQuotaService();
        $this->scenes = (array) ($config['ad']['scenes'] ?? []);
    }

    public function handle(int $userId, string $openid, string $adUnitId, array $reward): bool
    {
        if ($userId <= 0) {
            ContextualLogger::warning('[match3] 广告回调缺少 userId，openid 未绑定', [
                'openid' => $openid, 'ad_unit_id' => $adUnitId,
            ]);
            return false;
        }

        // 根据 ad_unit_id 反查场景
        $scene = $this->resolveScene($adUnitId);
        if ($scene === null) {
            ContextualLogger::warning('[match3] 未识别的广告单元: ' . $adUnitId, [
                'user_id' => $userId, 'openid' => $openid,
            ]);
            return false;
        }

        // 发奖
        $this->deliver($userId, $scene, $reward);

        // 计数：该场景今日已用 +1（ad/scene 读取同一计数源）
        $this->quota->markUsed($userId, $scene);

        return true;
    }

    /**
     * 根据 ad_unit_id 反查场景
     */
    protected function resolveScene(string $adUnitId): ?string
    {
        foreach ($this->scenes as $scene => $conf) {
            if (($conf['ad_unit_id'] ?? '') === $adUnitId) {
                return (string) $scene;
            }
        }
        return null;
    }

    /**
     * 发放奖励（按场景）
     */
    protected function deliver(int $userId, string $scene, array $reward): void
    {
        switch ($scene) {
            case Match3Constants::AD_SCENE_COINS:
                $coins = (int) ($this->scenes[$scene]['coins'] ?? 200);
                $this->bridge->grantCoins($userId, $coins, RewardReason::AD_REWARD, [
                    'ad_unit_id' => $reward['ad_unit_id'] ?? '',
                    'transaction_id' => $reward['transaction_id'] ?? '',
                ]);
                break;

            case Match3Constants::AD_SCENE_ADD_MOVES:
            case Match3Constants::AD_SCENE_REVIVE:
                // 复活/加步为客户端即时行为，服务端仅埋点（客户端权威）
                $this->bridge->reportEvents($userId, [
                    ['event' => 'ad_reward', 'data' => [
                        'scene' => $scene,
                        'ad_unit_id' => $reward['ad_unit_id'] ?? '',
                    ]],
                ]);
                break;

            default:
                ContextualLogger::warning('[match3] 未知广告场景: ' . $scene, ['user_id' => $userId]);
        }
    }
}
