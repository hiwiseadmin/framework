<?php

declare(strict_types=1);

namespace wise\plugin\exception;

/**
 * 当插件依赖要求未满足时抛出的异常。
 *
 * 当插件依赖项缺失、版本约束不满足或形成循环依赖时抛出此异常。
 *
 * 错误码：ErrorCode::PLUGIN_DEPENDENCY_ERROR (1004)
 * HTTP：409
 */
class PluginDependencyException extends PluginException
{
    protected int $statusCode = 409;

    /**
     * 缺失或未满足的依赖项列表。
     *
     * @var array
     */
    protected array $_missing = [];

    /**
     * 创建一个新的 PluginDependencyException 实例。
     *
     * @param string          $message  异常消息
     * @param array           $missing  缺失的依赖项列表
     * @param \Throwable|null $previous 前一个异常
     * @param int             $code     业务错误码（传 0 时回退到 ErrorCode::PLUGIN_DEPENDENCY_ERROR）
     */
    public function __construct(string $message = '', array $missing = [], ?\Throwable $previous = null, int $code = 0)
    {
        $this->_missing = $missing;
        if ($message === '' && !empty($missing)) {
            $message = sprintf('Unsatisfied dependencies: %s', implode(', ', $missing));
        }
        parent::__construct($message, $code ?: ErrorCode::PLUGIN_DEPENDENCY_ERROR->value, $previous);
    }

    /**
     * 获取缺失或未满足的依赖项列表。
     *
     * @return array
     */
    public function getMissingDependencies(): array
    {
        return $this->_missing;
    }
}
