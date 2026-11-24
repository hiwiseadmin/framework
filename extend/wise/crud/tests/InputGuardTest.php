<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudMap;
use wise\crud\CrudPlanBuilder;

/**
 * 生成参数的注入面护栏。
 *
 * 背景（代码复审 2026-11-08）：`class` / `plugin` 一直有白名单，但 `module` 与
 * `title` 没有。二者都会进入**生成出来的 PHP 代码**：
 *
 *   - `module` → `namespace app\{module};`
 *   - `title`  → `renderCrudIndex('{title}')`
 *
 * 因此只被授予 `admin.crud_builder.generate`（开发角色，非超管）的用户，
 * 提交 `module=admin;system($_GET[0]);//` 并以 force 生成，即可让“能生成后台页面”
 * 升级为“以 web 用户身份执行命令”。这组用例锁死该行为。
 *
 * 校验发生在读表（TableIntrospector）之前，因此本测试不需要数据库。
 */
class InputGuardTest extends CrudTestCase
{
    private function builder(): CrudPlanBuilder
    {
        return new CrudPlanBuilder();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(array $override = []): array
    {
        return $override + [
            'rules'    => ['generator' => []],
            'crud_map' => new CrudMap([], false),
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeTables(): array
    {
        return [
            'backtick'    => ['ws_article`'],
            'space'       => ['ws_article WHERE 1=1'],
            'semicolon'   => ['ws_article;DROP TABLE x'],
            'dot'         => ['other_db.ws_article'],
            'leading digit' => ['1table'],
            'empty'       => [''],
        ];
    }

    /**
     * @dataProvider unsafeTables
     */
    public function testRejectsUnsafeTable(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('表名非法');

        $this->builder()->build($table, $this->options());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeModules(): array
    {
        return [
            'semicolon'  => ["admin;system(\$_GET[0]);//"],
            'double dot' => ['../member'],
            'slash'      => ['admin/member'],
            'uppercase'  => ['Admin'],
            'space'      => ['admin member'],
            'empty'      => [''],
        ];
    }

    /**
     * @dataProvider unsafeModules
     */
    public function testRejectsUnsafeModule(string $module): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('模块名非法');

        $this->builder()->build('ws_article', $this->options(['module' => $module]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeTitles(): array
    {
        return [
            'quote escape' => ["');system(\$_GET[0]);//"],
            'double quote' => ['" . system("id") . "'],
            'variable'     => ['${system}'],
            'semicolon'    => ['标题;phpinfo()'],
            'backslash'    => ['标题\\'],
            'newline'      => ["标题\n"],
        ];
    }

    /**
     * @dataProvider unsafeTitles
     */
    public function testRejectsUnsafeTitle(string $title): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('标题含非法字符');

        $this->builder()->build('ws_article', $this->options(['title' => $title]));
    }
}
