<?php

declare(strict_types=1);

namespace plugin\match3game\tests;

use PHPUnit\Framework\TestCase;
use plugin\match3game\service\LevelProgressService;

/**
 * LevelProgressService 解锁判定 / 当前关测试（§2.2 判断 C / §5.5）
 */
class LevelProgressServiceTest extends TestCase
{
    private LevelProgressService $service;

    protected function setUp(): void
    {
        $this->service = new LevelProgressService();
    }

    // ===================== isUnlocked 纯逻辑 =====================

    public function testUnlockedWhenNoPreUnlock(): void
    {
        $level = ['code' => 'L1-1', 'pre_unlock' => null, 'unlock_stars' => 0, 'status' => 1];
        $this->assertTrue($this->service->isUnlocked($level, []));
    }

    public function testLockedWhenPreNotPlayed(): void
    {
        $level = ['code' => 'L1-2', 'pre_unlock' => 'L1-1', 'unlock_stars' => 0, 'status' => 1];
        // 前置关无记录
        $this->assertFalse($this->service->isUnlocked($level, []));
    }

    public function testLockedWhenPreNotPassed(): void
    {
        $level = ['code' => 'L1-2', 'pre_unlock' => 'L1-1', 'unlock_stars' => 0, 'status' => 1];
        $map   = ['L1-1' => ['best_stars' => 0]];
        $this->assertFalse($this->service->isUnlocked($level, $map));
    }

    public function testUnlockedWhenPrePassedNoStarRequirement(): void
    {
        $level = ['code' => 'L1-2', 'pre_unlock' => 'L1-1', 'unlock_stars' => 0, 'status' => 1];
        $map   = ['L1-1' => ['best_stars' => 1]];
        $this->assertTrue($this->service->isUnlocked($level, $map));
    }

    public function testLockedWhenStarRequirementNotMet(): void
    {
        $level = ['code' => 'L3-1', 'pre_unlock' => 'L2-4', 'unlock_stars' => 2, 'status' => 1];
        $map   = ['L2-4' => ['best_stars' => 1]];
        $this->assertFalse($this->service->isUnlocked($level, $map));
    }

    public function testUnlockedWhenStarRequirementMet(): void
    {
        $level = ['code' => 'L3-1', 'pre_unlock' => 'L2-4', 'unlock_stars' => 2, 'status' => 1];
        $map   = ['L2-4' => ['best_stars' => 3]];
        $this->assertTrue($this->service->isUnlocked($level, $map));
    }

    /**
     * 【2026-10-03】`unlock_stars` **缺省**时必须按 `DEFAULT_UNLOCK_STARS`(1) 处理
     *
     * "默认 1 = 前置关通关即解锁"的回归锁：老数据里 0 / 缺省混用，
     * 若哪天把 fallback 改回 0、而某个关卡的该列又是 NULL，解锁口径会悄悄漂移。
     */
    public function testMissingUnlockStarsFallsBackToOne(): void
    {
        $level = ['code' => 'L1-2', 'pre_unlock' => 'L1-1', 'status' => 1];

        // 前置关通关（1 星）→ 解锁
        $this->assertTrue($this->service->isUnlocked($level, ['L1-1' => ['best_stars' => 1]]));

        // 前置关未通关 → 依旧锁着（缺省值不会绕过"必须通关"这条硬要求）
        $this->assertFalse($this->service->isUnlocked($level, ['L1-1' => ['best_stars' => 0]]));
    }

    public function testLockedWhenLevelDisabled(): void
    {
        $level = ['code' => 'L9-9', 'pre_unlock' => null, 'unlock_stars' => 0, 'status' => 0];
        $this->assertFalse($this->service->isUnlocked($level, []));
    }

    // ===================== resolveCurrentLevel =====================

    public function testCurrentLevelFirstUnpassed(): void
    {
        $levels = [
            ['code' => 'L1-1', 'status' => 1],
            ['code' => 'L1-2', 'status' => 1],
            ['code' => 'L1-3', 'status' => 1],
        ];
        $map = ['L1-1' => ['best_stars' => 2]];
        $this->assertSame('L1-2', $this->service->resolveCurrentLevel($levels, $map));
    }

    public function testCurrentLevelLastWhenAllPassed(): void
    {
        $levels = [
            ['code' => 'L1-1', 'status' => 1],
            ['code' => 'L1-2', 'status' => 1],
        ];
        $map = ['L1-1' => ['best_stars' => 2], 'L1-2' => ['best_stars' => 1]];
        $this->assertSame('L1-2', $this->service->resolveCurrentLevel($levels, $map));
    }
}
