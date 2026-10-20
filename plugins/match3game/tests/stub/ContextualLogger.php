<?php
/**
 * 测试替身：wise\support\context\ContextualLogger
 *
 * 仅用于离线自检脚本（tests/verify-*.php），真实运行时由框架提供。
 */

declare(strict_types=1);

namespace wise\support\context;

class ContextualLogger
{
    public static function info(string $message, array $context = []): void
    {
    }

    public static function warning(string $message, array $context = []): void
    {
    }

    public static function error(string $message, array $context = []): void
    {
    }

    public static function debug(string $message, array $context = []): void
    {
    }
}
