<?php

declare(strict_types=1);

namespace plugin\match3game\contract;

use wise\game\task\contract\TaskInterface;

/**
 * 消消乐任务通用实现（TaskInterface）
 *
 * 以定义数组构造，避免为每个任务创建独立类。
 */
class Match3Task implements TaskInterface
{
    /** @var array{code:string, category:string, goal:array, rewards:array, prerequisites?:array} */
    protected array $def;

    public function __construct(array $def)
    {
        $this->def = $def;
    }

    public function getCode(): string
    {
        return (string) ($this->def['code'] ?? '');
    }

    public function getCategory(): string
    {
        return (string) ($this->def['category'] ?? 'main');
    }

    public function getGoal(): array
    {
        return (array) ($this->def['goal'] ?? []);
    }

    public function getRewards(): array
    {
        return (array) ($this->def['rewards'] ?? []);
    }

    public function getPrerequisites(): array
    {
        return (array) ($this->def['prerequisites'] ?? []);
    }
}
