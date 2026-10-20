<?php

declare(strict_types=1);

namespace wise\plugin\exception;

/**
 * 当请求的插件未找到时抛出的异常。
 *
 * 当尝试访问、安装、启用或对不存在的插件执行任何操作时抛出此异常。
 *
 * 错误码：ErrorCode::PLUGIN_NOT_FOUND (1001)
 * HTTP：404
 */
class PluginNotFoundException extends PluginException
{
    protected int $statusCode = 404;

    /**
     * 未找到的插件名称。
     *
     * @var string
     */
    protected string $pluginName;

    /**
     * 创建一个新的 PluginNotFoundException 实例。
     *
     * @param string          $pluginName 未找到的插件名称
     * @param \Throwable|null $previous   前一个异常
     */
    public function __construct(string $pluginName, ?\Throwable $previous = null)
    {
        $this->pluginName = $pluginName;
        $message = sprintf('Plugin "%s" not found.', $pluginName);
        parent::__construct($message, ErrorCode::PLUGIN_NOT_FOUND->value, $previous);
    }

    /**
     * 获取未找到的插件名称。
     *
     * @return string
     */
    public function getPluginName(): string
    {
        return $this->pluginName;
    }
}
