<?php
declare(strict_types=1);

namespace wise\crud\tests;

use PHPUnit\Framework\TestCase;

/**
 * CRUD 生成器测试基类：提供规则表加载与列定义构造器。
 */
abstract class CrudTestCase extends TestCase
{
    /**
     * 加载 config/crud.php（进程内只读一次）。
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        static $rules = null;

        if ($rules === null) {
            $rules = require dirname(__DIR__, 4) . '/config/crud.php';
        }

        return $rules;
    }

    /**
     * 构造 TableIntrospector 风格的列定义。
     *
     * @return array<string, mixed>
     */
    protected function column(
        string $name,
        string $dbType,
        ?string $columnType = null,
        string $comment = '',
        bool $nullable = false,
        mixed $default = null,
        bool $primary = false
    ): array {
        return [
            'name'        => $name,
            'db_type'     => $dbType,
            'column_type' => $columnType ?? $dbType,
            'comment'     => $comment,
            'nullable'    => $nullable,
            'default'     => $default,
            'is_primary'  => $primary,
        ];
    }
}
