<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\FieldTypeResolver;

/**
 * 五级优先级推导引擎 —— 用本项目**真实 schema** 的字段作为验证样本。
 */
class FieldTypeResolverTest extends CrudTestCase
{
    private function resolver(array $extra = []): FieldTypeResolver
    {
        return new FieldTypeResolver(array_merge($this->rules(), $extra));
    }

    // ──────────────────────────────────────────────
    //  P1 精确匹配
    // ──────────────────────────────────────────────

    public function testStatusBecomesRadioFromLooseComment(): void
    {
        $result = $this->resolver()->resolve(
            $this->column('status', 'tinyint', 'tinyint(4)', '状态 0:禁用,1:启用', false, '1')
        );

        $this->assertSame('radio', $result['type']);
        $this->assertSame('P1', $result['layer']);
        $this->assertSame(['0' => '禁用', '1' => '启用'], $result['options']);
    }

    public function testStatusFallsBackWithWarningWhenCommentUnparseable(): void
    {
        // 注释无法解析出枚举时必须**显式降级并留痕**，而不是静默当文本
        $result = $this->resolver()->resolve($this->column('status', 'tinyint', 'tinyint(4)', '状态'));

        $this->assertSame('number', $result['type']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testPrimaryKeyIsSkipped(): void
    {
        $result = $this->resolver()->resolve($this->column('id', 'bigint', 'bigint(20)', 'ID', false, null, true));

        $this->assertTrue($result['skip_form']);
        $this->assertSame('primary', $result['system']);
    }

    public function testSystemTimeFieldsAreSkippedForBothNamingSchools(): void
    {
        foreach (['create_time', 'created_at', 'add_time', 'createtime'] as $name) {
            $result = $this->resolver()->resolve($this->column($name, 'datetime'));
            $this->assertTrue($result['skip_form'], "{$name} 应被跳过");
            $this->assertSame('create_time', $result['system'], "{$name} 应识别为创建时间");
        }

        foreach (['update_time', 'updated_at', 'updatetime'] as $name) {
            $this->assertSame('update_time', $this->resolver()->resolve($this->column($name, 'datetime'))['system']);
        }

        foreach (['delete_time', 'deleted_at', 'deletetime'] as $name) {
            $this->assertSame('delete_time', $this->resolver()->resolve($this->column($name, 'datetime'))['system'], "{$name} 应识别为删除时间（启用软删除）");
        }
    }

    public function testReservedAdminIdIsSkipped(): void
    {
        $result = $this->resolver()->resolve($this->column('admin_id', 'bigint'));

        $this->assertTrue($result['skip_form']);
        $this->assertSame('reserved', $result['system']);
    }

    // ──────────────────────────────────────────────
    //  P2 前缀
    // ──────────────────────────────────────────────

    public function testIsPrefixBecomesSwitch(): void
    {
        foreach (['is_system', 'is_official', 'is_trusted_device', 'has_perm'] as $name) {
            $result = $this->resolver()->resolve($this->column($name, 'tinyint', 'tinyint(4)', '', false, '0'));

            $this->assertSame('switch', $result['type'], "{$name} 应为 switch");
            $this->assertSame('P2', $result['layer']);
        }
    }

    // ──────────────────────────────────────────────
    //  P3 后缀（含冲突消解顺序）
    // ──────────────────────────────────────────────

    public function testRelationIntIdBecomesSelect(): void
    {
        foreach (['parent_id', 'permission_id', 'role_id', 'member_id'] as $name) {
            $result = $this->resolver()->resolve($this->column($name, 'int', 'int(11)', '', true, '0'));

            $this->assertSame('select', $result['type'], "{$name} 应为关联下拉");
            $this->assertSame('P3', $result['layer']);
        }
    }

    public function testSessionIdIsNotTreatedAsRelation(): void
    {
        // 关键回归：varchar 的 `_id` 不是关联字段（FastAdmin 会误判为 selectpage → 404）
        $result = $this->resolver()->resolve($this->column('session_id', 'varchar', 'varchar(64)', '会话ID', true));

        $this->assertSame('text', $result['type']);
    }

    public function testImgIdPrefersImageOverRelation(): void
    {
        // Q3 决策：img_id 语义为图片（FastAdmin 会判成关联）
        $result = $this->resolver()->resolve($this->column('img_id', 'int', 'int(11)', '封面图', true, '0'));

        $this->assertSame('image', $result['type']);
        $this->assertFalse($result['multiple']);
    }

    public function testImgIdsBecomesMultipleImage(): void
    {
        $result = $this->resolver()->resolve($this->column('img_ids', 'json', 'json', '图集', true));

        $this->assertSame('image', $result['type']);
        $this->assertTrue($result['multiple']);
    }

    public function testImgListIsSupported(): void
    {
        // FastAdmin 完全不处理 img_list；本规范必须支持
        $result = $this->resolver()->resolve($this->column('img_list', 'json', 'json', '图片列表', true));

        $this->assertSame('image', $result['type']);
        $this->assertTrue($result['multiple']);
    }

    public function testAvatarPrefersAvatarOverImage(): void
    {
        $result = $this->resolver()->resolve($this->column('avatar', 'varchar', 'varchar(255)', '头像', true));

        $this->assertSame('avatar', $result['type']);
    }

    public function testFileFields(): void
    {
        $this->assertSame('file', $this->resolver()->resolve($this->column('attach', 'varchar', 'varchar(255)'))['type']);
        $this->assertTrue($this->resolver()->resolve($this->column('files', 'json', 'json'))['multiple']);
    }

    public function testTimeSuffixRequiresUnderscoreBoundary(): void
    {
        // 关键回归：`runtime` / `lifetime` 不得被 time 后缀误伤（FastAdmin 会误判为 datetime）
        foreach (['runtime', 'lifetime'] as $name) {
            $result = $this->resolver()->resolve($this->column($name, 'varchar', 'varchar(32)'));

            $this->assertSame('text', $result['type'], "{$name} 不应被识别为时间");
        }

        // 下划线边界的时间后缀正常命中
        $this->assertSame('datetime', $this->resolver()->resolve($this->column('last_login_time', 'datetime'))['type']);
        $this->assertSame('datetime', $this->resolver()->resolve($this->column('install_time', 'datetime'))['type']);
        $this->assertSame('datetime', $this->resolver()->resolve($this->column('expired_at', 'datetime'))['type']);
    }

    public function testEditorOnlyForTextColumns(): void
    {
        // 富文本仅当 DB 是 text 系列
        $this->assertSame(
            'editor',
            $this->resolver()->resolve($this->column('content', 'text', 'text'))['type']
        );

        $this->assertNotSame(
            'editor',
            $this->resolver()->resolve($this->column('content', 'varchar', 'varchar(255)'))['type']
        );
    }

    // ──────────────────────────────────────────────
    //  P4 DB 类型兜底
    // ──────────────────────────────────────────────

    public function testEnumBecomesRadioWithChineseLabels(): void
    {
        $result = $this->resolver()->resolve(
            $this->column('type', 'enum', "enum('dir','menu','button')", '菜单类型 dir:目录,menu:菜单,button:按钮', false, 'menu')
        );

        $this->assertSame('radio', $result['type']);
        $this->assertSame(['dir' => '目录', 'menu' => '菜单', 'button' => '按钮'], $result['options']);
    }

    public function testSetBecomesMultipleSelect(): void
    {
        $result = $this->resolver()->resolve(
            $this->column('tag', 'set', "set('a','b')", '标签 a:A,b:B')
        );

        $this->assertSame('select', $result['type']);
        $this->assertTrue($result['multiple']);
    }

    public function testTinyintOneFallsBackToSwitch(): void
    {
        $result = $this->resolver()->resolve($this->column('pinned', 'tinyint', 'tinyint(1)', '是否钉选', false, '0'));

        $this->assertSame('switch', $result['type']);
    }

    public function testSortFallsBackToNumber(): void
    {
        $result = $this->resolver()->resolve($this->column('sort', 'int', 'int(11)', '排序号,越小越靠前', false, '0'));

        $this->assertSame('number', $result['type']);
    }

    // ──────────────────────────────────────────────
    //  P0 显式覆盖
    // ──────────────────────────────────────────────

    public function testExplicitOverrideWinsOverEverything(): void
    {
        $resolver = $this->resolver([
            'overrides' => [
                'article.status' => ['type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
            ],
        ]);

        $result = $resolver->resolve(
            $this->column('status', 'tinyint', 'tinyint(4)', '状态 0:禁用,1:启用', false, '1'),
            'article'
        );

        $this->assertSame('select', $result['type']);
        $this->assertSame('P0', $result['layer']);
        $this->assertTrue($result['declared']);
    }

    // ──────────────────────────────────────────────
    //  可解释性（规范 §3.0 / §8）
    // ──────────────────────────────────────────────

    public function testEveryResultCarriesExplainabilityFields(): void
    {
        $columns = [
            $this->column('id', 'bigint', 'bigint(20)', 'ID', false, null, true),
            $this->column('status', 'tinyint', 'tinyint(4)', '状态 0:禁用,1:启用', false, '1'),
            $this->column('session_id', 'varchar', 'varchar(64)', '会话ID', true),
            $this->column('unknown_field', 'varchar', 'varchar(50)', '无规则命中'),
        ];

        foreach ($this->resolver()->resolveAll($columns, 'demo') as $result) {
            $this->assertArrayHasKey('layer', $result, $result['name']);
            $this->assertArrayHasKey('rule', $result, $result['name']);
            $this->assertArrayHasKey('fallback', $result, $result['name']);
            $this->assertArrayHasKey('declared', $result, $result['name']);
            $this->assertNotSame('', $result['rule'], $result['name']);
            $this->assertMatchesRegularExpression('/^P[0-5]$/', $result['layer'], $result['name']);
        }
    }
}
