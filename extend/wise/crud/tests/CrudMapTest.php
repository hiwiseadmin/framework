<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudMap;

/**
 * 声明文件（config/crud_map.php）的读取、校验与合并语义。
 *
 * 关注四件事：
 *   1. 空声明 = 与现状 100% 等价（引入新能力不能改变默认行为）
 *   2. 字段级声明优先于全局 overrides，表级兜底写入 `表名.*`
 *   3. 非法结构**立即报错**，不静默忽略（否则错误延迟到生成物才暴露）
 *   4. `--relation` 的解析容错（省略值列/显示列时的默认值）
 */
class CrudMapTest extends CrudTestCase
{
    public function testEmptyMapIsEquivalentToNoDeclaration(): void
    {
        $map  = new CrudMap([]);
        $base = $this->rules();

        $this->assertFalse($map->has('ws_article'));
        $this->assertSame([], $map->tables());
        $this->assertSame($base, $map->applyToRules($base, 'ws_article'));
    }

    public function testDisabledMapIgnoresDeclarations(): void
    {
        $map = new CrudMap([
            'ws_article' => ['fields' => ['status' => ['type' => 'radio']]],
        ], false);

        $base = $this->rules();

        $this->assertFalse($map->isEnabled());
        $this->assertFalse($map->has('ws_article'));
        $this->assertSame($base, $map->applyToRules($base, 'ws_article'));
    }

    public function testEnabledFalseInArrayDisablesMap(): void
    {
        $map = new CrudMap([
            '__enabled'  => false,
            'ws_article' => ['fields' => ['status' => ['type' => 'radio']]],
        ]);

        $this->assertFalse($map->isEnabled());
        $this->assertFalse($map->has('ws_article'));
    }

    public function testFieldDeclarationWinsOverGlobalOverride(): void
    {
        $map = new CrudMap([
            'ws_article' => [
                'fields' => [
                    'status' => ['type' => 'radio', 'options' => ['0' => '禁用', '1' => '启用']],
                ],
            ],
        ]);

        $rules = $this->rules();
        $rules['overrides']['ws_article.status'] = ['type' => 'text'];

        $merged = $map->applyToRules($rules, 'ws_article');

        $this->assertSame('radio', $merged['overrides']['ws_article.status']['type']);
        $this->assertSame(['0' => '禁用', '1' => '启用'], $merged['overrides']['ws_article.status']['options']);
    }

    public function testTableDefaultsGoToWildcardKey(): void
    {
        $map = new CrudMap([
            'ws_article' => ['table_defaults' => ['type' => 'textarea']],
        ]);

        $merged = $map->applyToRules($this->rules(), 'ws_article');

        $this->assertSame('textarea', $merged['overrides']['ws_article.*']['type']);
    }

    public function testUnknownTableKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/未知键/');

        new CrudMap([
            'ws_article' => ['field' => []],
        ]);
    }

    public function testUnknownFieldKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/未知键/');

        new CrudMap([
            'ws_article' => ['fields' => ['status' => ['widget' => 'radio']]],
        ]);
    }

    public function testRelationWithoutLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/label/');

        new CrudMap([
            'ws_article' => ['relations' => ['dept_id' => ['table' => 'ws_dept', 'value' => 'id']]],
        ]);
    }

    public function testNonArrayOptionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CrudMap([
            'ws_article' => ['fields' => ['status' => ['type' => 'radio', 'options' => '0=禁用']]],
        ]);
    }

    public function testAccessorsReturnDeclarations(): void
    {
        $map = new CrudMap([
            'ws_article' => [
                'relations' => ['dept_id' => ['table' => 'ws_dept', 'value' => 'id', 'label' => 'name']],
                'ignore'    => ['remark', ''],
            ],
        ]);

        $this->assertTrue($map->has('ws_article'));
        $this->assertSame(['remark'], $map->ignore('ws_article'));
        $this->assertSame('ws_dept', $map->relations('ws_article')['dept_id']['table']);
        $this->assertSame([], $map->spec('ws_other'));
    }


    public function testParseRelationWithAllParts(): void
    {
        $parsed = CrudMap::parseRelation('dept_id:ws_dept:id:name');

        $this->assertSame('dept_id', $parsed['field']);
        $this->assertSame('ws_dept', $parsed['table']);
        $this->assertSame('id', $parsed['value']);
        $this->assertSame('name', $parsed['label']);
    }

    public function testParseRelationDefaultsValueAndLabel(): void
    {
        $parsed = CrudMap::parseRelation('dept_id:ws_dept');

        $this->assertSame('id', $parsed['value']);
        $this->assertSame('name', $parsed['label']);
    }

    public function testParseRelationRejectsMalformedInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CrudMap::parseRelation('dept_id');
    }

    public function testParseRelationRejectsIllegalFieldName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CrudMap::parseRelation('dept-id:ws_dept');
    }


    public function testMergeIntoWritesGeneratedFileAndRoundTrips(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_crud_map_' . bin2hex(random_bytes(4)) . '.php';

        try {
            $first = CrudMap::mergeInto(
                $path,
                'ws_demo',
                ['status' => ['type' => 'radio', 'options' => ['0' => '禁用', '1' => '启用']]],
                ['dept_id' => ['table' => 'ws_dept', 'value' => 'id', 'label' => 'name']]
            );

            $this->assertSame('written', $first['status']);

            $loaded = require $path;
            $this->assertSame('radio', $loaded['ws_demo']['fields']['status']['type']);
            $this->assertSame('ws_dept', $loaded['ws_demo']['relations']['dept_id']['table']);

            $second = CrudMap::mergeInto($path, 'ws_demo', ['title' => ['type' => 'text']], []);
            $this->assertSame('written', $second['status']);

            $merged = require $path;
            $this->assertArrayHasKey('status', $merged['ws_demo']['fields']);
            $this->assertArrayHasKey('title', $merged['ws_demo']['fields']);
            $this->assertArrayHasKey('dept_id', $merged['ws_demo']['relations']);

            $this->assertTrue((new CrudMap($merged))->has('ws_demo'));
        } finally {
            @unlink($path);
        }
    }

    public function testMergeIntoRefusesToOverwriteHandwrittenFile(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_crud_map_' . bin2hex(random_bytes(4)) . '.php';
        $handwritten = "<?php\n// 这是一份手写声明，注释必须保留\nreturn [];\n";

        file_put_contents($path, $handwritten);

        try {
            $result = CrudMap::mergeInto($path, 'ws_demo', ['status' => ['type' => 'radio']], []);

            $this->assertSame('snippet', $result['status']);
            $this->assertStringContainsString('ws_demo', $result['snippet']);
            $this->assertSame($handwritten, (string) file_get_contents($path), '手写文件不得被覆盖');
        } finally {
            @unlink($path);
        }
    }

    public function testMergeIntoWithoutDeclarationsIsNoop(): void
    {
        $result = CrudMap::mergeInto(sys_get_temp_dir() . '/unused.php', 'ws_demo', [], []);

        $this->assertSame('noop', $result['status']);
        $this->assertSame('', $result['snippet']);
    }
}
