<?php

declare(strict_types=1);

namespace plugin\match3game\tests;

use PHPUnit\Framework\TestCase;
use plugin\match3game\validator\Match3Validator;

/**
 * Match3Validator 星级评定 / 目标校验测试
 *
 * 协议已切到 Level Schema v1：星级读 rules.starScores，目标读 rules.goals（01 §4.1）。
 * 通过反射调用 protected 纯逻辑方法，不依赖数据库与框架容器。
 */
class Match3ValidatorTest extends TestCase
{
    private Match3Validator $validator;

    protected function setUp(): void
    {
        // 不调用构造函数（避免 config() / 模型依赖），直接反射实例化
        $reflection       = new \ReflectionClass(Match3Validator::class);
        $this->validator  = $reflection->newInstanceWithoutConstructor();
    }

    private function callProtected(string $method, array $args)
    {
        $ref = new \ReflectionMethod(Match3Validator::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->validator, $args);
    }

    /** rules.starScores = [一星, 二星, 三星] */
    private array $starScores = [20000, 40000, 60000];

    // ===================== calcStars =====================

    public function testCalcStarsThreeStar(): void
    {
        $stars = $this->callProtected('calcStars', [65000, $this->starScores]);
        $this->assertSame(3, $stars);
    }

    public function testCalcStarsTwoStar(): void
    {
        $stars = $this->callProtected('calcStars', [45000, $this->starScores]);
        $this->assertSame(2, $stars);
    }

    public function testCalcStarsOneStar(): void
    {
        $stars = $this->callProtected('calcStars', [25000, $this->starScores]);
        $this->assertSame(1, $stars);
    }

    public function testCalcStarsZero(): void
    {
        $stars = $this->callProtected('calcStars', [10000, $this->starScores]);
        $this->assertSame(0, $stars);
    }

    // ===================== checkGoalMet =====================

    public function testCheckGoalMetPass(): void
    {
        $goals = [
            ['type' => 'collect', 'target' => 'red_gem', 'count' => 30],
            ['type' => 'clear',   'layer'  => 'ice',     'count' => 10],
        ];
        $err = $this->callProtected('checkGoalMet', [
            ['red_gem' => 32, 'ice' => 12],
            $goals,
        ]);
        $this->assertNull($err);
    }

    public function testCheckGoalMetFail(): void
    {
        $goals = [
            ['type' => 'collect', 'target' => 'red_gem', 'count' => 30],
        ];
        $err = $this->callProtected('checkGoalMet', [
            ['red_gem' => 20],
            $goals,
        ]);
        $this->assertNotNull($err);
        $this->assertStringContainsString('未达标', $err);
    }

    public function testCheckGoalMetIgnoresScoreOnlyGoal(): void
    {
        $goals = [
            ['type' => 'score', 'count' => 20000],
        ];
        $err = $this->callProtected('checkGoalMet', [[], $goals]);
        $this->assertNull($err);
    }

    // ===================== scoreTargetOf =====================

    public function testScoreTargetOf(): void
    {
        $goals = [
            ['type' => 'collect', 'target' => 'red_gem', 'count' => 30],
            ['type' => 'score', 'count' => 20000],
        ];
        $this->assertSame(20000, $this->callProtected('scoreTargetOf', [$goals]));
    }

    public function testScoreTargetOfNone(): void
    {
        $goals = [['type' => 'collect', 'target' => 'red_gem', 'count' => 30]];
        $this->assertSame(0, $this->callProtected('scoreTargetOf', [$goals]));
    }
}
