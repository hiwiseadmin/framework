<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudMap;
use wise\crud\CrudPlanBuilder;
use wise\crud\TableIntrospector;

/**
 * 关联字段声明（`--relation` / `crud_map.relations`）的解析、校验与回写。
 *
 * 声明关联后必须变成**可执行**的声明；声明非法时必须**立即报错**，
 */
class RelationSpecTest extends CrudTestCase
{
    /** @var array<string, array<int, array<int, mixed>>> */
    private array $tables = [
        'ws_article' => [
            ['id', 'bigint', 'bigint(20) unsigned', 'ID', true],
            ['title', 'varchar', 'varchar(120)', '标题'],
            ['status', 'tinyint', 'tinyint(4)', '状态 0:禁用,1:启用'],
            ['dept_id', 'int', 'int(11)', '部门'],
        ],
        'ws_dept' => [
            ['id', 'bigint', 'bigint(20) unsigned', 'ID', true],
            ['name', 'varchar', 'varchar(60)', '部门名'],
        ],
        'ws_article_cat' => [
            ['id', 'bigint', 'bigint(20) unsigned', 'ID', true],
            ['title', 'varchar', 'varchar(60)', '分类名'],
        ],
    ];

    private function builder(): CrudPlanBuilder
    {
        return new CrudPlanBuilder();
    }

    private function introspector(): TableIntrospector
    {
        $tables = $this->tables;

        return new class ($tables) extends TableIntrospector {
            public function __construct(private array $tables)
            {
            }

            public function columns(string $table, string $connection = 'mysql'): array
            {
                if (!isset($this->tables[$table])) {
                    throw new \RuntimeException(sprintf('表 [%s] 不存在或无可读列', $table));
                }

                $columns = [];

                foreach ($this->tables[$table] as $def) {
                    $columns[] = [
                        'name'        => (string) $def[0],
                        'db_type'     => (string) $def[1],
                        'column_type' => (string) ($def[2] ?? $def[1]),
                        'comment'     => (string) ($def[3] ?? ''),
                        'nullable'    => true,
                        'default'     => null,
                        'is_primary'  => (bool) ($def[4] ?? false),
                    ];
                }

                return $columns;
            }

            public function tableComment(string $table, string $connection = 'mysql'): string
            {
                return '';
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function build(array $options = []): array
    {
        return $this->builder()->build('ws_article', array_merge([
            'rules'        => $this->rules(),
            'introspector' => $this->introspector(),
        ], $options));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function field(array $plan, string $name): array
    {
        foreach ($plan['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        $this->fail('计划中不存在字段：' . $name);
    }


    public function testRelationFromCommandLineIsApplied(): void
    {
        $plan  = $this->build(['relation' => ['dept_id:ws_dept:id:name']]);
        $field = $this->field($plan, 'dept_id');

        $this->assertSame('select', $field['type']);
        $this->assertSame('P0', $field['layer']);
        $this->assertTrue($field['declared']);
        $this->assertSame('ws_dept', $field['props']['relation']['table']);
        $this->assertSame('name', $field['props']['relation']['label']);
    }

    public function testRelationRemovesUnknownTableWarning(): void
    {
        $plain = $this->field($this->build(), 'dept_id');
        $this->assertNotEmpty($plain['warnings']);

        $declared = $this->field($this->build(['relation' => ['dept_id:ws_dept:id:name']]), 'dept_id');
        $this->assertSame([], $declared['warnings']);
    }

    public function testPlanMetaReportsDeclarationSources(): void
    {
        $plan = $this->build([
            'crud_map' => new CrudMap([
                'ws_article' => [
                    'fields'    => ['status' => ['type' => 'radio', 'options' => ['0' => '禁用', '1' => '启用']]],
                    'relations' => ['dept_id' => ['table' => 'ws_dept', 'value' => 'id', 'label' => 'name']],
                ],
            ]),
        ]);

        $this->assertTrue($plan['meta']['map_applied']);
        $this->assertSame(['status'], $plan['meta']['map_declared']);
        $this->assertSame(['dept_id'], $plan['meta']['map_relations']);
    }

    public function testTablePrefixCompletesShortTargetName(): void
    {
        $rules                     = $this->rules();
        $rules['generator']['table_prefix'] = 'ws_';

        $plan  = $this->build(['rules' => $rules, 'relation' => ['dept_id:dept:id:name']]);
        $field = $this->field($plan, 'dept_id');

        $this->assertSame('ws_dept', $field['props']['relation']['table']);
    }

    public function testCommandLineRelationOverridesDeclarationFile(): void
    {
        $plan = $this->build([
            'crud_map' => new CrudMap([
                'ws_article' => [
                    'relations' => ['dept_id' => ['table' => 'ws_dept', 'value' => 'id', 'label' => 'name']],
                ],
            ]),
            'relation' => ['dept_id:ws_article_cat:id:title'],
        ]);

        $field = $this->field($plan, 'dept_id');

        $this->assertSame('ws_article_cat', $field['props']['relation']['table']);
        $this->assertSame('title', $field['props']['relation']['label']);
    }

    public function testSelfRelationIsAllowedButWarned(): void
    {
        $plan  = $this->build(['relation' => ['dept_id:ws_article:id:title']]);
        $field = $this->field($plan, 'dept_id');

        $this->assertSame('ws_article', $field['props']['relation']['table']);
        $this->assertNotEmpty($field['warnings']);
        $this->assertStringContainsString('自关联', (string) $field['warnings'][0]);
    }


    public function testUnknownFieldThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/不在表/');

        $this->build(['relation' => ['nope_id:ws_dept:id:name']]);
    }

    public function testUnknownTargetTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/不存在/');

        $this->build(['relation' => ['dept_id:ws_missing:id:name']]);
    }

    public function testUnknownLabelColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/显示列/');

        $this->build(['relation' => ['dept_id:ws_dept:id:nope']]);
    }
}
