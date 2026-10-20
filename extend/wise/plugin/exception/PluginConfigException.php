<?php

declare(strict_types=1);

namespace wise\plugin\exception;

/**
 * 当插件配置发生错误时抛出的异常。
 *
 * 包括无效的配置值、缺失的配置文件或读写插件配置时的错误。
 *
 * 错误码：ErrorCode::PLUGIN_CONFIG_ERROR (1003)
 * HTTP：500
 */
class PluginConfigException extends PluginException
{
    protected int $statusCode = 500;

    /**
     * 创建一个新的 PluginConfigException 实例。
     *
     * @param string          $message  异常消息
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, ErrorCode::PLUGIN_CONFIG_ERROR->value, $previous);
    }
}
