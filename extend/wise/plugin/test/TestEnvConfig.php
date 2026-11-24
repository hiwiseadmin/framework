<?php

declare(strict_types=1);

namespace wise\plugin\test;

use InvalidArgumentException;

/**
 * ## 两种运行方式
 *
 * | `plugin.test_env` / `--test-env` | 行为 |
 * |:--|:--|
 * | 空（默认） | **直接在当前环境执行**（读 `.env` 的真实库）；`--expect-db` 可选，提供则强校验 |
 * | 非空 | 切到 `.env.{name}`：父进程用 `PHP_ENV_NAME` 拉起子进程，子进程读该 env 文件里的库 |
 *
 * ## 为什么必须用子进程
 *
 * ThinkPHP 的 `Env` 在 `initialize()` 期间就把 `.env` 解析进 `Env::$data`，
 * 之后 `Env::get()` 永远先命中这份 data；也就是说**启动后再改 `ENV_NAME` 不会重载 env 文件**。
 * 只有「新进程 + `PHP_ENV_NAME`」才能在 bootstrap 阶段选中 `.env.{name}`。
 *
 * ## 为什么不用 env 变量直接覆盖 DB_*
 *
 * 实测（`variables_order=GPCS`）：`.env` 装载后 `Env::$data` 优先于 `getenv()`，
 * 因此 `DB_NAME=xxx` 作为进程变量不会生效；`.env.{name}` 必须是完整副本（不能只写 DB_*）。
 */
final class TestEnvConfig
{
    /** 子进程标记：防止父 → 子 → 孙无限递归 */
    public const CHILD_MARKER = 'WISE_PLUGIN_TEST_CHILD';

    /** 合法环境名（同时用于拼 `.env.{name}`，必须防路径穿越） */
    private const NAME_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$~';

    /**
     * 解析生效的环境名：命令行优先于配置；空串表示「当前真实环境」。
     */
    public static function resolve(string $option, string $config): string
    {
        $name = trim($option);

        return $name !== '' ? $name : trim($config);
    }

    /**
     * @throws InvalidArgumentException 名字非法（可能是路径穿越）
     */
    public static function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '环境名非法："%s"（只允许字母/数字/下划线/连字符，最长 32 位，且必须以字母或数字开头）',
                $name
            ));
        }
    }

    /**
     * `.env.{name}` 的绝对路径。
     */
    public static function envFile(string $rootPath, string $name): string
    {
        return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . '.env.' . $name;
    }

    public static function exists(string $rootPath, string $name): bool
    {
        return $name !== '' && is_file(self::envFile($rootPath, $name));
    }

    /**
     * 当前进程是否已经是「被父进程拉起的测试环境子进程」。
     */
    public static function isChild(): bool
    {
        return getenv(self::CHILD_MARKER) === '1';
    }
}
