<?php

declare(strict_types=1);

namespace wise\plugin\hook_dispatcher;

use wise\plugin\hook_dispatcher\Listener;

/**
 * HookDispatcher 管理钩子监听器并通过它们分发事件。
 *
 * 监听器按优先级注册（数值越小越先执行）。
 * 在分发过程中，负载按优先级顺序依次传递给每个监听器，
 * 每个监听器的返回值成为下一个监听器的负载
 * （链式处理）。
 */
class HookDispatcher
{
    /**
     * 按钩子名称索引的已注册监听器。
     *
     * @var array<string, Listener[]>
     */
    protected array $listeners = [];

    /**
     * 记录哪些钩子的监听器列表已排序。
     *
     * @var array<string, bool>
     */
    protected array $sorted = [];

    /**
     * 向钩子添加监听器。
     *
     * @param string   $hookName 要监听的钩子名称
     * @param callable $callback 监听器回调函数
     * @param int      $priority 监听器优先级（数值越小越先执行，默认：10）
     * @return void
     */
    public function addListener(string $hookName, callable $callback, int $priority = 10): void
    {
        if (!isset($this->listeners[$hookName])) {
            $this->listeners[$hookName] = [];
        }

        $listener = new Listener($callback, $priority);
        $this->listeners[$hookName][] = $listener;

        // 标记为需要重新排序，延迟到 dispatch 时执行
        unset($this->sorted[$hookName]);
    }

    /**
     * 通过所有已注册监听器分发钩子事件。
     *
     * 负载按优先级顺序依次传递给每个监听器。
     * 每个监听器的返回值成为下一个监听器的负载
     * （链式处理）。如果传播被停止，剩余的
     * 监听器不会被调用。
     *
     * @param string $hookName 要分发的钩子名称
     * @param mixed  $payload  初始负载
     * @return mixed 所有监听器处理后的最终负载
     */
    public function dispatch(string $hookName, mixed $payload = null): mixed
    {
        if (!isset($this->listeners[$hookName])) {
            return $payload;
        }

        // 延迟排序：仅在首次分发时执行一次
        if (!isset($this->sorted[$hookName])) {
            // 使用 Schwartzian transform 确保稳定排序：同优先级保持插入顺序
            $listeners = $this->listeners[$hookName];
            $priorities = array_map(fn(Listener $l): int => $l->getPriority(), $listeners);
            $order = array_keys($listeners);
            array_multisort($priorities, SORT_NUMERIC, SORT_ASC, $order, SORT_NUMERIC, SORT_ASC, $listeners);
            $this->listeners[$hookName] = $listeners;
            $this->sorted[$hookName] = true;
        }

        $event = new PluginEvent($hookName, $payload);

        foreach ($this->listeners[$hookName] as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }
            // 监听器的返回值成为新的负载
            $result = $listener->execute($event->getPayload());
            if ($result !== null) {
                $event->setPayload($result);
            }
        }

        return $event->getPayload();
    }

    /**
     * 从钩子中移除监听器。
     *
     * @param string   $hookName 钩子名称
     * @param callable $callback 要移除的监听器回调函数
     * @return void
     */
    public function removeListener(string $hookName, callable $callback): void
    {
        if (!isset($this->listeners[$hookName])) {
            return;
        }

        $this->listeners[$hookName] = array_values(array_filter(
            $this->listeners[$hookName],
            function (Listener $listener) use ($callback): bool {
                return $listener->getCallback() !== $callback;
            }
        ));
    }

    /**
     * 获取钩子的所有监听器，按优先级排序。
     *
     * @param string $hookName 钩子名称
     * @return Listener[]
     */
    public function getListeners(string $hookName): array
    {
        return $this->listeners[$hookName] ?? [];
    }
}
