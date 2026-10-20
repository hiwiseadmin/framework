<?php

declare(strict_types=1);

namespace wise\plugin\hook_dispatcher;

/**
 * 表示带有回调函数和优先级的钩子监听器。
 *
 * 监听器按优先级排序（数值越小越先执行）。
 * 当两个监听器优先级相同时，按插入顺序执行。
 */
class Listener
{
    /**
     * 钩子分发时执行的回调函数。
     *
     * @var callable
     */
    protected $callback;

    /**
     * 监听器优先级（数值越小越先执行）。
     *
     * @var int
     */
    protected int $priority;

    /**
     * 创建一个新的 Listener 实例。
     *
     * @param callable $callback 监听器回调函数
     * @param int      $priority 监听器优先级（默认：10）
     */
    public function __construct(callable $callback, int $priority = 10)
    {
        $this->callback = $callback;
        $this->priority = $priority;
    }

    /**
     * 获取监听器回调函数。
     *
     * @return callable
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * 获取监听器优先级。
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * 执行监听器回调函数。
     *
     * @param mixed $payload 传给回调函数的负载数据
     * @return mixed 回调函数的返回值
     */
    public function execute(mixed $payload): mixed
    {
        return call_user_func($this->callback, $payload);
    }
}
