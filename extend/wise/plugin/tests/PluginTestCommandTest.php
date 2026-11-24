<?php

declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\console\Input;
use think\console\input\Definition;
use think\console\input\Option;
use wise\plugin\command\tool\PluginTest;

/**
 * `plugin:test` 子进程委派的参数重建测试。
 *
 * 守的是两条真实事故：
 *  1. `--test-env` 下 `--dry-run` 若没转发，子进程会**真的执行**装/卸（预览变写库）；
 *  2. `--format=json` 若没归一成 `--json`，子进程输出纯文本，破坏 JSON 契约。
 */
class PluginTestCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function makeInput(array $values): Input
    {
        $definition = new Definition();

        foreach ([
            'plugin'         => Option::VALUE_REQUIRED,
            'path'           => Option::VALUE_REQUIRED,
            'all'            => Option::VALUE_NONE,
            'format'         => Option::VALUE_REQUIRED,
            'strict'         => Option::VALUE_NONE,
            'json'           => Option::VALUE_NONE,
            'caseset'        => Option::VALUE_REQUIRED,
            'caseset-sha256' => Option::VALUE_REQUIRED,
            'expect-db'      => Option::VALUE_REQUIRED,
            'test-env'       => Option::VALUE_REQUIRED,
            'purge'          => Option::VALUE_NONE,
            'skip-unit'      => Option::VALUE_NONE,
            'dry-run'        => Option::VALUE_NONE,
            'force'          => Option::VALUE_NONE,
        ] as $name => $mode) {
            $definition->addOption(new Option($name, null, $mode));
        }

        $input = new Input([]);
        $input->bind($definition);

        foreach ($values as $name => $value) {
            $input->setOption($name, $value);
        }

        return $input;
    }

    private function build(Input $input): string
    {
        $class = new ReflectionClass(PluginTest::class);
        $command = $class->newInstanceWithoutConstructor();

        $method = $class->getMethod('buildChildCommand');
        $method->setAccessible(true);

        return (string) $method->invoke($command, $input, 'C:/app');
    }

    public function testDryRunIsForwardedToChild(): void
    {
        $command = $this->build($this->makeInput([
            'plugin'  => 'demo',
            'dry-run' => true,
            'force'   => true,
        ]));

        $this->assertStringContainsString('--integration', $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringNotContainsString('--test-env', $command, '子进程必须靠 PHP_ENV_NAME 继承环境，不能带 --test-env（会递归）');
        $this->assertMatchesRegularExpression('/(^|\s)-p\s/', $command);
        $this->assertStringContainsString('demo', $command);
    }

    public function testFormatJsonBecomesJsonFlag(): void
    {
        $command = $this->build($this->makeInput([
            'plugin' => 'demo',
            'format' => 'json',
        ]));

        $this->assertStringContainsString('--json', $command, '--format=json 必须归一成 --json，否则子进程输出文本');
        $this->assertStringNotContainsString('--format', $command);
    }

    public function testExpectDbAndOtherFlagsAreForwarded(): void
    {
        $command = $this->build($this->makeInput([
            'plugin'    => 'demo',
            'all'       => true,
            'expect-db' => 'wise_test',
            'purge'     => true,
            'skip-unit' => true,
            'strict'    => true,
        ]));

        foreach (['--expect-db=', '--purge', '--skip-unit', '--strict', '--all'] as $flag) {
            $this->assertStringContainsString($flag, $command, $flag . ' 应转发给子进程');
        }

        $this->assertStringContainsString('wise_test', $command);
    }

    public function testTestEnvOptionIsNeverForwarded(): void
    {
        $command = $this->build($this->makeInput([
            'plugin'   => 'demo',
            'test-env' => 'test',
        ]));

        $this->assertStringNotContainsString('test-env', $command);
    }
}
