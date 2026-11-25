<?php

declare(strict_types=1);

namespace wise\plugin\exception;

use wise\support\exception\WiseException;

/**
 * 所有插件相关错误的基础异常。
 *
 * 所有插件管理器异常均继承此类，以提供一致的异常层次结构和统一错误码。
 * 错误码统一定义在 {@see ErrorCode} 枚举中（号段 1000~1099）。
 */
class PluginException extends WiseException
{
    /**
     * HTTP 状态码（子类可重写）
     */
    protected int $statusCode = 400;

    /**
     * @param string          $message  异常消息
     * @param int             $code     业务错误码（传 0 时回退到 ErrorCode::PLUGIN_ERROR）
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::PLUGIN_ERROR->value;
        parent::__construct($code, $message, $this->statusCode, $previous);
    }
}
