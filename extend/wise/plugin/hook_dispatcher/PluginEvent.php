<?php

declare(strict_types=1);

namespace wise\plugin\hook_dispatcher;

/**
 * 表示带有名称和负载的插件事件。
 *
 * PluginEvent 封装了通过钩子分发系统传递的数据，
 * 提供对事件名称和可变更负载的访问。
 */
class PluginEvent
{
    /**
     * 事件/钩子名称。
     *
     * @var string
     */
    protected string $name;

    /**
     * 事件负载数据。
     *
     * @var mixed
     */
    protected mixed $payload;

    /**
     * 事件传播是否应停止。
     *
     * @var bool
     */
    protected bool $propagationStopped = false;

    /**
     * 创建一个新的 PluginEvent 实例。
     *
     * @param string $name    事件名称
     * @param mixed  $payload 事件负载数据
     */
    public function __construct(string $name, mixed $payload = null)
    {
        $this->name = $name;
        $this->payload = $payload;
    }

    /**
     * 获取事件名称。
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取事件负载数据。
     *
     * @return mixed
     */
    public function getPayload(): mixed
    {
        return $this->payload;
    }

    /**
     * 设置事件负载数据。
     *
     * @param mixed $payload 新的负载值
     * @return void
     */
    public function setPayload(mixed $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * 停止事件传播。
     *
     * @return void
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * 检查事件传播是否已停止。
     *
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
