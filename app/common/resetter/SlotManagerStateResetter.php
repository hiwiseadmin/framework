<?php
declare(strict_types=1);

namespace app\common\resetter;

use wise\support\contracts\RequestStateResetterInterface;

/**
 * SlotManager 请求级状态重置器（宿主业务）
 *
 * 清理「按当前登录管理员注入的权限判定闭包」，避免常驻进程把上一位用户的权限上下文
 * 复用到下一位用户（越权级别的问题）。插槽**定义**属于启动期注册数据，不重置。
 *
 * 见 {@see \app\common\slot\SlotManager::resetRequestState()}。
 *
 * 注册入口：{@see \app\AppService::boot()}（优先级 150，晚于基础设施、早于包业务）
 *
 * @package app\common\resetter
 */
class SlotManagerStateResetter implements RequestStateResetterInterface
{
    /**
     * {@inheritDoc}
     */
    public function resetRequestState(): void
    {
        \app\common\slot\SlotManager::resetRequestState();
    }
}
