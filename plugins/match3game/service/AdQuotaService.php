<?php

declare(strict_types=1);

namespace plugin\match3game\service;

use plugin\match3game\service\social\RedisCounter;
use wise\support\context\ContextualLogger;

/**
 * 广告每日配额计数（补齐「ad/scene 不返回今日已用次数」缺口）
 *
 * 【为什么单独抽出来】
 *   "今日已看 N 次"必须与真实发奖发生在**同一个计数源**上：
 *   - `ad/scene` 只读该计数（申请广告位时展示"N/上限"）；
 *   - `Match3AdRewardHandler` 在激励视频回调发奖成功后自增。
 *   若两处各写一套逻辑，计数迟早对不上。
 *
 * 【key 命名】`ad:{uid}:{scene}:{date}`，随日期自然隔离。
 *
 * 【降级】Redis 不可用 → `usedToday()` 返回 0（fail-open：不阻断看广告），
 * `markUsed()` 静默失败。计数精度下降但功能不中断。
 */
class AdQuotaService
{
    protected RedisCounter $counter;

    public function __construct(?RedisCounter $counter = null)
    {
        $this->counter = $counter ?? new RedisCounter();
    }

    /**
     * 今日已用次数（Redis 不可用返回 0）
     */
    public function usedToday(int $userId, string $scene): int
    {
        $value = $this->counter->get($this->key($userId, $scene));

        return $value === null ? 0 : $value;
    }

    /**
     * 标记一次广告已完成（发奖成功后调用）
     *
     * @return int 自增后的今日次数（Redis 不可用时返回 -1）
     */
    public function markUsed(int $userId, string $scene): int
    {
        $value = $this->counter->incr($this->key($userId, $scene), 86400 * 2);

        if ($value === false) {
            ContextualLogger::warning('[match3][ad] 广告计数失败（Redis 不可用），已降级', [
                'user_id' => $userId, 'scene' => $scene,
            ]);

            return -1;
        }

        return $value;
    }

    /**
     * 今日剩余次数（不小于 0）
     */
    public function remainToday(int $userId, string $scene, int $maxPerDay): int
    {
        return max(0, $maxPerDay - $this->usedToday($userId, $scene));
    }

    protected function key(int $userId, string $scene): string
    {
        return 'ad:' . $userId . ':' . $scene . ':' . date('Y-m-d');
    }
}
