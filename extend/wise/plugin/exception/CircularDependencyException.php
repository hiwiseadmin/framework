<?php

declare(strict_types=1);

namespace wise\plugin\exception;

/**
 * 当检测到插件之间存在循环依赖时抛出的异常。
 *
 * 此异常提供检测到的循环路径，用于调试目的。
 *
 * 错误码：ErrorCode::PLUGIN_CIRCULAR_DEPENDENCY (1005)
 * HTTP：409
 */
class CircularDependencyException extends PluginDependencyException
{
    /**
     * 检测到的循环依赖路径。
     *
     * @var array
     */
    protected array $_cycle = [];

    /**
     * 创建一个新的 CircularDependencyException 实例。
     *
     * @param array           $cycle    检测到的循环路径（插件名称列表）
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(array $cycle = [], ?\Throwable $previous = null)
    {
        $this->_cycle = $cycle;
        $message = sprintf(
            'Circular dependency detected: %s',
            implode(' -> ', $cycle)
        );
        // 循环依赖使用专门错误码，通过父类构造函数传入
        parent::__construct($message, $cycle, $previous, ErrorCode::PLUGIN_CIRCULAR_DEPENDENCY->value);
    }

    /**
     * 获取检测到的循环依赖路径。
     *
     * @return array
     */
    public function getCyclePath(): array
    {
        return $this->_cycle;
    }
}
