<?php

declare(strict_types=1);

namespace plugin\match3game\tests;

use PHPUnit\Framework\TestCase;
use plugin\match3game\service\ScoreGuardService;

/**
 * ScoreGuardService 理论上限 / 风控校验测试（§3.3 第 4/5/7 项）
 */
class ScoreGuardServiceTest extends TestCase
{
    private ScoreGuardService $guard;

    protected function setUp(): void
    {
        $this->guard = new ScoreGuardService([
            'max_eliminate_per_move_ratio' => 2.0,
            'score_limit_factor'           => 1.2,
            'min_duration_ms'              => 30000,
            'min_duration_per_move_ms'     => 300,
            'min_duration_abs_ms'          => 1500,
        ], 100);
    }

    // ===================== 理论上限计算 =====================

    public function testMaxCollectable(): void
    {
        // 8x8 棋盘、30 步、系数 2.0 → 64 * 30 * 2 = 3840
        $this->assertSame(3840, $this->guard->maxCollectable(8, 8, 30));
    }

    public function testMaxScore(): void
    {
        // 8x8 棋盘、30 步、单颗 100 分、系数 1.2 → 64 * 30 * 100 * 1.2 = 230400
        $this->assertSame(230400, $this->guard->maxScore(8, 8, 30));
    }

    // ===================== 收集量校验 =====================

    public function testCheckCollectPass(): void
    {
        $this->assertNull($this->guard->checkCollect(
            ['red_gem' => 30, 'ice' => 10],
            8, 8, 30
        ));
    }

    public function testCheckCollectExceedsLimit(): void
    {
        $err = $this->guard->checkCollect(['red_gem' => 999999], 8, 8, 30);
        $this->assertNotNull($err);
        $this->assertStringContainsString('超出物理上限', $err);
    }

    public function testCheckCollectNegative(): void
    {
        $err = $this->guard->checkCollect(['red_gem' => -1], 8, 8, 30);
        $this->assertNotNull($err);
        $this->assertStringContainsString('不能为负', $err);
    }

    /**
     * 回归 2026-09-27 真机：`score` 汇总键被当成"收集个数"
     *
     * 真机报文：`收集量超出物理上限: score=8676（上限 4536，棋盘 9x9，步数 28）`
     * —— 重放引擎的 `goals` 里带 `score` 汇总键，而它被拿去和"个数上限"比了（量纲混用）。
     */
    public function testCheckCollectIgnoresScalarKeys(): void
    {
        $this->assertSame(4536, $this->guard->maxCollectable(9, 9, 28));

        // 只有汇总键 → 不违规（真机那条报文）
        $this->assertNull($this->guard->checkCollect(['score' => 8676], 9, 9, 28));

        // 元素键 + 汇总键混杂（引擎 `goals` 的真实形状）→ 只看元素键
        $this->assertNull($this->guard->checkCollect(['red_gem' => 120, 'score' => 8676], 9, 9, 28));

        // 元素键本身超上限 → 依旧拦（这条才是本校验的意义）
        $err = $this->guard->checkCollect(['red_gem' => 4537, 'score' => 8676], 9, 9, 28);
        $this->assertNotNull($err);
        $this->assertStringContainsString('red_gem=4537', (string) $err);

        // 其它汇总键同样跳过（它们各有专门的校验）
        $this->assertNull($this->guard->checkCollect(['moves' => 99999, 'maxCombo' => 99999], 9, 9, 28));
    }

    /** 非数值不是计数（`null` / 布尔 / 数组）→ 跳过，交给结构校验去报 */
    public function testCheckCollectSkipsNonNumeric(): void
    {
        $this->assertNull($this->guard->checkCollect(['red_gem' => null], 8, 8, 30));
        $this->assertNull($this->guard->checkCollect(['red_gem' => true], 8, 8, 30));
        $this->assertNull($this->guard->checkCollect(['red_gem' => ['nested' => 1]], 8, 8, 30));
        $this->assertNull($this->guard->checkCollect(['red_gem' => '120'], 8, 8, 30));
    }

    // ===================== 分数校验 =====================

    public function testCheckScorePass(): void
    {
        $this->assertNull($this->guard->checkScore(45200, 8, 8, 30));
    }

    public function testCheckScoreExceedsLimit(): void
    {
        $err = $this->guard->checkScore(99999999, 8, 8, 30);
        $this->assertNotNull($err);
        $this->assertStringContainsString('超出物理上限', $err);
    }

    public function testCheckScoreNegative(): void
    {
        $this->assertNotNull($this->guard->checkScore(-100, 8, 8, 30));
    }

    // ===================== 时长校验 =====================

    public function testCheckDurationPass(): void
    {
        $this->assertNull($this->guard->checkDuration(142000));
    }

    public function testCheckDurationTooShort(): void
    {
        $err = $this->guard->checkDuration(1000);
        $this->assertNotNull($err);
        $this->assertStringContainsString('秒结算', $err);
    }

    /**
     * 下限按步数折算 —— 回归 2026-09-27 真机误杀
     *
     * 【为什么单独立一条】玩家正常游玩，因"消除后掉落引发的连锁自动消除"14.7s 打完一局，
     * 被恒定的 30s 下限判成秒结算（`10008`）。
     * 时长与作弊之间没有稳定对应关系：**步数少的关卡本来就可能在十几秒内结束**。
     */
    public function testCheckDurationScalesWithMoves(): void
    {
        // 真机那局：14709ms / 8 步 → 下限 2.4s → 通过
        $this->assertNull($this->guard->checkDuration(14709, 8));

        // 同样时长、步数多得多（30 步 → 下限 9s）：它确实打了 14.7s，仍应通过
        $this->assertNull($this->guard->checkDuration(14709, 30));

        // 0.2s 交 8 步 → 依旧拦
        $err = $this->guard->checkDuration(200, 8);
        $this->assertNotNull($err);
        $this->assertStringContainsString('步数 8', (string) $err);
    }

    /** 步数极少时回落到绝对下限（1.5s），不会被折算成 0 */
    public function testCheckDurationAbsoluteFloor(): void
    {
        $this->assertSame(1500, $this->guard->requiredDurationMs(0));
        $this->assertSame(1500, $this->guard->requiredDurationMs(2));
        $this->assertNotNull($this->guard->checkDuration(1000, 1));
        $this->assertNull($this->guard->checkDuration(1600, 1));
    }

    /** 上限仍是 `min_duration_ms`：步数再多也不会要求超过 30s */
    public function testRequiredDurationCapsAtMinDurationMs(): void
    {
        $this->assertSame(30000, $this->guard->requiredDurationMs(500));
        // 60 步 → 18s，未触上限
        $this->assertSame(18000, $this->guard->requiredDurationMs(60));
    }

    /**
     * 服务端自测时长兜底：取二者较大值（只放宽，不收紧）
     *
     * 客户端上报可能偏小（续局只算本进程、切后台挂起），而服务端自己算的
     * `battle/start → now` 只会 ≥ 真实游玩时间（见 BattleService::elapsedMs）。
     */
    public function testCheckDurationUsesServerElapsed(): void
    {
        // 客户端说 1s，服务端测到 20s → 以 20s 为准 → 通过（8 步下限 2.4s）
        $this->assertNull($this->guard->checkDuration(1000, 8, 20000));

        // 两边都短 → 拦
        $this->assertNotNull($this->guard->checkDuration(1000, 8, 500));

        // 时钟异常（负值）按报错处理，不静默放行
        $this->assertStringContainsString('不能为负', (string) $this->guard->checkDuration(-1, 8, 0));
        $this->assertStringContainsString('不能为负', (string) $this->guard->checkDuration(20000, 8, -1));
    }

    // ===================== 步数校验 =====================

    public function testCheckMovesPass(): void
    {
        $this->assertNull($this->guard->checkMoves(24, 30));
    }

    public function testCheckMovesExceeded(): void
    {
        $err = $this->guard->checkMoves(31, 30);
        $this->assertNotNull($err);
        $this->assertStringContainsString('步数超限', $err);
    }

    public function testCheckMovesWithAddedMoves(): void
    {
        // 30 基础 + 5 加步 → 35 步以内合法
        $this->assertNull($this->guard->checkMoves(35, 30, 5));
        $this->assertNotNull($this->guard->checkMoves(36, 30, 5));
    }
}
