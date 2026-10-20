<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\exception\Match3ErrorCode;
use plugin\match3game\exception\Match3Exception;
use plugin\match3game\service\analytics\Match3Funnel;

/**
 * 消消乐增长 / 归因 API（补齐「无买量归因上报接口」缺口）
 *
 * POST /api/match3game/analytics/attribution   买量归因上报
 *
 * 【背景】IAA 买量需要 channel / click_id / adgroup_id 等字段才能归因（08 §十）。
 * 客户端在小游戏启动（或进入归因场景）时上报一次，服务端落结构化日志
 * （`[match3][funnel] match3.attribution`）并转发 wise-game-ops 分析服务。
 *
 * 【合规】不落库、不存用户画像，仅作为埋点事件进入日志/分析管道；
 * 所有字段经 `Match3Funnel::sanitize()` 清洗（截断长字符串、限制键数量）。
 */
class Analytics extends Base
{
    /**
     * 允许的归因字段（同时接受 camelCase 与 snake_case）
     *
     * @var array<int,string>
     */
    protected const ATTR_KEYS = [
        'channel',
        'sub_channel', 'subChannel',
        'click_id', 'clickId',
        'adgroup_id', 'adgroupId',
        'campaign_id', 'campaignId',
        'creative_id', 'creativeId',
        'promoter_id', 'promoterId',
        'cid',
        'gdt_vid', 'gdtVid',
        'scene',
    ];

    /**
     * 买量归因上报
     *
     * 请求体（至少提供一个归因字段）：
     * {
     *   "channel": "tencent",
     *   "click_id": "xxx",
     *   "adgroup_id": "123",
     *   "campaign_id": "456",
     *   "creative_id": "789",
     *   "scene": "launch"
     * }
     *
     * 响应：{ "received": true, "ts": 1736438400000 }
     */
    public function attribution()
    {
        $userId = $this->userId();
        $input  = $this->payload();
        $input  = is_array($input) ? $input : [];

        $props = [];
        foreach (self::ATTR_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $props[$this->toSnake($key)] = $value;
        }

        if ($props === []) {
            throw Match3Exception::from(
                Match3ErrorCode::LEVEL_NOT_FOUND,
                '缺少归因参数（channel / click_id 至少其一）'
            );
        }

        // 场景默认 launch；user_id 由 JWT 提供，便于与漏斗其它事件串联
        $props['scene']   = (string) ($props['scene'] ?? 'launch');
        $props['user_id'] = $userId;

        Match3Funnel::emit(Match3Funnel::ATTRIBUTION, $userId, $props);

        return $this->success('ok', [
            'received' => true,
            'ts'       => (int) round(microtime(true) * 1000),
        ]);
    }

    /**
     * camelCase → snake_case（保证同一字段两种写法落到同一个键）
     */
    protected function toSnake(string $key): string
    {
        return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key);
    }
}
