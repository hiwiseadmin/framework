<?php

declare(strict_types=1);

namespace plugin\match3game\contract;

use wise\game\task\contract\AchievementInterface;

/**
 * 消消乐成就通用实现（AchievementInterface）
 *
 * 以定义数组构造，避免为每个成就创建独立类。
 */
class Match3Achievement implements AchievementInterface
{
    /** @var array{code:string, goal:array, rewards:array, title:string} */
    protected array $def;

    public function __construct(array $def)
    {
        $this->def = $def;
    }

    public function getCode(): string
    {
        return (string) ($this->def['code'] ?? '');
    }

    public function getGoal(): array
    {
        return (array) ($this->def['goal'] ?? []);
    }

    public function getRewards(): array
    {
        return (array) ($this->def['rewards'] ?? []);
    }

    public function getTitle(): string
    {
        return (string) ($this->def['title'] ?? '');
    }
}
