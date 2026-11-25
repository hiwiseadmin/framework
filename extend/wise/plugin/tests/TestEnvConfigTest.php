<?php

declare(strict_types=1);

namespace wise\plugin\tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use wise\plugin\test\TestEnvConfig;

/**
 * 测试环境选择（`.env.{name}`）的契约测试。
 *
 * 这里守的是「目标库选错」这类事故：
 *  - 名字必须能安全拼进文件名（防路径穿越）；
 *  - 命令行优先于配置；
 *  - 配置文件不存在时不能"静默回落真实库"；
 *  - 子进程标记必须能被识别，否则父 → 子无限递归。
 */
class TestEnvConfigTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisetesenv_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/.env*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);
    }

    public function testOptionOverridesConfig(): void
    {
        $this->assertSame('test', TestEnvConfig::resolve('test', 'debug'));
        $this->assertSame('debug', TestEnvConfig::resolve('', 'debug'));
        $this->assertSame('', TestEnvConfig::resolve('', ''));
    }

    public function testResolveTrimsBothSides(): void
    {
        $this->assertSame('test', TestEnvConfig::resolve('  test  ', '  debug '));
        $this->assertSame('debug', TestEnvConfig::resolve('   ', ' debug '));
    }

    public function testValidNamesAreAccepted(): void
    {
        foreach (['test', 'debug', 'ci_test', 'php81-test', 'A1'] as $name) {
            TestEnvConfig::assertValidName($name);
        }

        $this->addToAssertionCount(1);
    }

    public function testInvalidNamesAreRejected(): void
    {
        foreach (['', '../prod', 'a/b', '.hidden', 'a b', str_repeat('a', 33)] as $name) {
            try {
                TestEnvConfig::assertValidName($name);
                $this->fail('应当拒绝：' . $name);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('环境名非法', $e->getMessage());
            }
        }
    }

    public function testEnvFilePathAndExistence(): void
    {
        $file = TestEnvConfig::envFile($this->tmp, 'test');

        $this->assertSame(
            str_replace('/', DIRECTORY_SEPARATOR, $this->tmp . '/.env.test'),
            $file
        );

        $this->assertFalse(TestEnvConfig::exists($this->tmp, 'test'));

        file_put_contents($file, "DB_NAME = wise_test\n");

        $this->assertTrue(TestEnvConfig::exists($this->tmp, 'test'));
        $this->assertFalse(TestEnvConfig::exists($this->tmp, ''));
    }

    public function testChildMarkerIsRecognised(): void
    {
        $previous = getenv(TestEnvConfig::CHILD_MARKER);

        putenv(TestEnvConfig::CHILD_MARKER . '=1');
        $this->assertTrue(TestEnvConfig::isChild());

        putenv(TestEnvConfig::CHILD_MARKER . '=0');
        $this->assertFalse(TestEnvConfig::isChild());

        if ($previous === false) {
            putenv(TestEnvConfig::CHILD_MARKER);
        } else {
            putenv(TestEnvConfig::CHILD_MARKER . '=' . $previous);
        }
    }
}
