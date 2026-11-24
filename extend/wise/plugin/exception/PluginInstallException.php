<?php

declare(strict_types=1);

namespace wise\plugin\exception;

/**
 * 当插件安装或卸载发生错误时抛出的异常。
 *
 * 包括插件下载、解压或移除过程中的错误。
 *
 * 错误码：ErrorCode::PLUGIN_INSTALL_ERROR (1002)
 * HTTP：500
 */
class PluginInstallException extends PluginException
{
    protected int $statusCode = 500;

    /**
     * 创建一个新的 PluginInstallException 实例。
     *
     * @param string          $message  异常消息
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, ErrorCode::PLUGIN_INSTALL_ERROR->value, $previous);
    }
}
