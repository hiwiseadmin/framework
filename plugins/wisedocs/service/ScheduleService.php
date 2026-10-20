<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

use plugin\wisedocs\model\Document as DocumentModel;
use think\facade\Cache;
use think\facade\Log;

/**
 * 定时发布服务（P5-2）
 *
 * 轻量实现：不做常驻调度器，而是在前台请求时「惰性触发」——
 * 每次最多每 60 秒执行一次 UPDATE，把到期的草稿置为已发布。
 * 这样无需依赖 cron 即可让定时内容按点上线；如需严格准点，
 * 可用系统 cron 定时访问站点首页，或后续接入框架的定时任务。
 */
class ScheduleService
{
    /** 运行节流锁 */
    protected const LOCK_KEY = 'wisedocs:publish_due_lock';

    /** 节流窗口（秒） */
    protected const LOCK_TTL = 60;

    /**
     * 发布所有到期的草稿
     *
     * @return int 本次发布的文档数
     */
    public function publishDue(): int
    {
        // 节流：同一窗口内只跑一次（锁不可用时仍执行，保证功能可用）
        try {
            if (Cache::get(self::LOCK_KEY)) {
                return 0;
            }
            Cache::set(self::LOCK_KEY, 1, self::LOCK_TTL);
        } catch (\Throwable $e) {
            // 忽略缓存异常
        }

        try {
            return (int) DocumentModel::where('status', DocumentModel::STATUS_DRAFT)
                ->whereNotNull('published_at')
                ->where('published_at', '<=', date('Y-m-d H:i:s'))
                ->update(['status' => DocumentModel::STATUS_PUBLISHED]);
        } catch (\Throwable $e) {
            Log::warning('[wisedocs] publish due failed: ' . $e->getMessage());

            return 0;
        }
    }
}
