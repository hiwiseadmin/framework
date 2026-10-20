<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\manifest\ManifestMenuWriter;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * manifest `menus` 读写内核测试。
 *
 * 锁住「幂等 + 非破坏 + 只回收机器写的」这三条合同 —— `wise:crud --merge-manifest`
 * 与 `plugin:menu-derive --apply/--prune` 都依赖它。
 */
class ManifestMenuWriterTest extends TestCase
{
    private string $dir = '';
    private string $manifest = '';
    private ManifestMenuWriter $writer;

    protected function setUp(): void
    {
        $this->dir      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisemenuwriter_' . uniqid();
        $this->manifest = $this->dir . DIRECTORY_SEPARATOR . 'manifest.json';

        mkdir($this->dir, 0755, true);

        $this->writer = new ManifestMenuWriter(new MenuPathNormalizer());
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        @rmdir($this->dir);
    }

    // ──────────────────────────────────────────────
    //  append：幂等 + 非破坏 + 标记
    // ──────────────────────────────────────────────

    public function testAppendAddsEntryToTopLevel(): void
    {
        $menus = [['name' => '原有菜单', 'path' => '/plugin/x/existing']];
        $entry = $this->entry('/plugin/x/newsletter', 'Newsletter');

        $outcome = $this->writer->append($menus, $entry);

        $this->assertTrue($outcome['added']);
        $this->assertSame('', $outcome['target']);
        $this->assertCount(2, $menus);
        $this->assertSame($entry, $menus[1]);
    }

    public function testAppendIsIdempotentAndNeverTouchesManualNode(): void
    {
        $menus = [[
            'name' => '我改过的标题',
            'path' => '/plugin/x/article',
            'sort' => 42,
        ]];

        // 同 path
        $outcome = $this->writer->append($menus, $this->entry('/plugin/x/article', '文章'));
        $this->assertFalse($outcome['added']);
        $this->assertStringContainsString('原样保留', $outcome['message']);

        // 等价写法（长式 vs 短式）
        $outcome = $this->writer->append($menus, $this->entry('/plugin/x/article/index', '文章'));
        $this->assertFalse($outcome['added']);
        $this->assertStringContainsString('等价菜单', $outcome['message']);

        $this->assertCount(1, $menus, '不得追加重复节点');
        $this->assertSame('我改过的标题', $menus[0]['name']);
        $this->assertSame(42, $menus[0]['sort']);
        $this->assertFalse($this->writer->isMarked($menus[0]), '手工节点不得被补标记');
    }

    public function testAppendDetectsEquivalentNodeInsideGroup(): void
    {
        $menus = [[
            'name'     => '内容配置',
            'type'     => 'dir',
            'path'     => '',
            'children' => [['name' => '文章', 'path' => '/plugin/x/group_battle/index']],
        ]];

        // 控制器段拼写不同（camelCase vs snake_case）也算同一节点
        $outcome = $this->writer->append($menus, $this->entry('/plugin/x/groupBattle/index', '对战'));

        $this->assertFalse($outcome['added']);
        $this->assertCount(1, $menus);
        $this->assertCount(1, $menus[0]['children']);
    }

    public function testAppendUsesExistingGroupChildrenAndKeepsAliasKey(): void
    {
        $menus = [[
            'name' => '内容配置',
            'type' => 'dir',
            'path' => '',
            'child' => [['name' => '文章', 'path' => '/plugin/x/article']],
        ]];

        $outcome = $this->writer->append($menus, $this->entry('/plugin/x/chapter', '章节'), '内容配置');

        $this->assertTrue($outcome['added']);
        $this->assertSame('内容配置', $outcome['target']);
        $this->assertCount(2, $menus[0]['child'], '沿用既有子节点键名 child');
        $this->assertSame('/plugin/x/chapter', $menus[0]['child'][1]['path']);
        $this->assertCount(1, $menus, '不得在顶级重复追加');
    }

    public function testAppendFallsBackToTopLevelWhenGroupDirMissing(): void
    {
        $menus = [['name' => '其他分组', 'type' => 'dir', 'path' => '', 'children' => []]];

        $outcome = $this->writer->append($menus, $this->entry('/plugin/x/chapter', '章节'), '内容配置');

        $this->assertTrue($outcome['added']);
        $this->assertSame('', $outcome['target'], '不新建 dir，退回顶级');
        $this->assertCount(2, $menus);
    }

    // ──────────────────────────────────────────────
    //  prune：只回收「机器写的 + 已不存在」
    // ──────────────────────────────────────────────

    public function testPruneRemovesOnlyMarkedStaleNodes(): void
    {
        $menus = [
            ['name' => '手工陈旧', 'path' => '/plugin/x/stale_manual'],
            $this->entry('/plugin/x/stale_auto', '自动陈旧'),
            $this->entry('/plugin/x/alive/index', '自动在用'),
        ];

        $result = $this->writer->prune($menus, ['plugin/x/alive' => true]);

        $this->assertCount(1, $result['removed']);
        $this->assertSame('/plugin/x/stale_auto', $result['removed'][0]['path']);
        $this->assertSame(
            ['/plugin/x/stale_manual', '/plugin/x/alive/index'],
            array_column($menus, 'path'),
            '手工节点保留；标记项因控制器仍在而保留'
        );
    }

    public function testPruneRecursesIntoGroupAndKeepsDirNode(): void
    {
        $menus = [[
            'name'     => '内容配置',
            'type'     => 'dir',
            'path'     => '',
            'children' => [
                ['name' => '手工', 'path' => '/plugin/x/manual'],
                $this->entry('/plugin/x/gone', '自动陈旧'),
                $this->entry('/plugin/x/here', '自动在用'),
            ],
        ]];

        $result = $this->writer->prune($menus, ['plugin/x/here' => true]);

        $this->assertSame(['/plugin/x/gone'], array_column($result['removed'], 'path'));
        $this->assertCount(1, $menus, '分组节点本身保留（它不是工具创建的）');
        $this->assertCount(2, $menus[0]['children']);
        $this->assertSame(
            ['/plugin/x/manual', '/plugin/x/here'],
            array_column($menus[0]['children'], 'path')
        );
    }

    public function testPruneWithoutMarkedStaleNodesIsNoop(): void
    {
        $menus = [['name' => '手工', 'path' => '/plugin/x/anything']];

        $result = $this->writer->prune($menus, []);

        $this->assertSame([], $result['removed']);
        $this->assertCount(1, $menus);
    }

    // ──────────────────────────────────────────────
    //  sortLevels：只改顺序，不改字段值
    // ──────────────────────────────────────────────

    public function testSortLevelsOrdersBySortThenNameRecursively(): void
    {
        $menus = [
            ['name' => '系统', 'type' => 'dir', 'path' => '', 'sort' => 50, 'children' => [
                ['name' => '玩法参数', 'path' => '/plugin/x/settings', 'sort' => 20],
                ['name' => '压测入口', 'path' => '/plugin/x/other', 'sort' => 10],
            ]],
            ['name' => '关卡', 'type' => 'dir', 'path' => '', 'sort' => 20, 'children' => []],
            ['name' => '运营', 'path' => '/plugin/x/dashboard', 'sort' => 10],
        ];

        $this->writer->sortLevels($menus);

        $this->assertSame(['运营', '关卡', '系统'], array_column($menus, 'name'));
        $this->assertSame(['压测入口', '玩法参数'], array_column($menus[2]['children'], 'name'), '子层同样按 sort 升序');
    }

    public function testSortLevelsIsStableAndLeavesFieldValuesUntouched(): void
    {
        $first  = ['name' => '同名', 'path' => '/plugin/x/a', 'sort' => 5, 'icon' => 'bi bi-a'];
        $second = ['name' => '同名', 'path' => '/plugin/x/b', 'sort' => 5, 'icon' => 'bi bi-b'];

        $menus = [$first, $second];

        $this->writer->sortLevels($menus);

        $this->assertSame(
            ['/plugin/x/a', '/plugin/x/b'],
            array_column($menus, 'path'),
            '完全同键者保持原相对顺序（PHP 8 稳定排序）'
        );
        $this->assertSame($first, $menus[0], '字段值一字不改');
        $this->assertSame($second, $menus[1]);
    }

    public function testSortLevelsIgnoresNonListStructures(): void
    {
        // children 写成单个节点（非列表）→ 结构不动，避免把对象写成数组
        $menus = [[
            'name'     => '分组',
            'type'     => 'dir',
            'path'     => '',
            'sort'     => 1,
            'children' => ['name' => '单节点', 'path' => '/plugin/x/only', 'sort' => 9],
        ]];

        $this->writer->sortLevels($menus);

        $this->assertSame('单节点', $menus[0]['children']['name']);

        $assoc = ['b' => ['name' => 'B', 'sort' => 2], 'a' => ['name' => 'A', 'sort' => 1]];
        $this->writer->sortLevels($assoc);

        $this->assertSame(['b', 'a'], array_keys($assoc), '根非列表不动');
    }

    // ──────────────────────────────────────────────
    //  load / save / 辅助
    // ──────────────────────────────────────────────

    public function testLoadReportsMissingAndBrokenManifest(): void
    {
        $missing = $this->writer->load($this->manifest);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('未找到 manifest.json', $missing['message']);

        file_put_contents($this->manifest, '{ 这不是 JSON');
        $broken = $this->writer->load($this->manifest);
        $this->assertFalse($broken['ok']);
        $this->assertStringContainsString('不是合法 JSON', $broken['message']);
    }

    /**
     * 回归：`json_decode(..., true)` 会把空对象 `{}` 解成 `[]`，回写时若不带原始形态，
     * 就会把 `"dependencies": {}` 改成 `"dependencies": []` —— 一片无关 diff + 语义破坏。
     */
    public function testSavePreservesEmptyObjectsButKeepsNewEmptyArrays(): void
    {
        file_put_contents($this->manifest, <<<'JSON'
{
    "name": "mycms",
    "dependencies": {},
    "config": {"auto": {"type": "boolean"}},
    "menus": []
}
JSON);

        $loaded = $this->writer->load($this->manifest);
        $this->assertTrue($loaded['ok']);

        $data  = $loaded['data'];
        $menus = (array) $data['menus'];
        $this->writer->append($menus, $this->entry('/plugin/mycms/article', '文章'));
        $data['menus'] = $menus;

        $saved = $this->writer->save($this->manifest, $data, $loaded['shape']);
        $this->assertTrue($saved['ok']);

        $raw = (string) file_get_contents($this->manifest);

        $this->assertStringContainsString('"dependencies": {}', $raw, '空对象必须原样保留');
        $this->assertStringNotContainsString('"dependencies": []', $raw);
        $this->assertStringContainsString('"children": []', $raw, '新增节点的空数组仍应是数组');

        $decoded = json_decode($raw);
        $this->assertInstanceOf(\stdClass::class, $decoded->dependencies);
        $this->assertSame('/plugin/mycms/article', $decoded->menus[0]->path);
    }

    public function testSaveWithoutShapeStillWritesValidJson(): void
    {
        file_put_contents($this->manifest, '{}');

        $saved = $this->writer->save($this->manifest, ['name' => 'mycms', 'menus' => []]);

        $this->assertTrue($saved['ok']);
        $this->assertSame(['name' => 'mycms', 'menus' => []], json_decode((string) file_get_contents($this->manifest), true));
    }

    public function testSaveWritesUnescapedUnicodeWithTrailingNewline(): void
    {
        file_put_contents($this->manifest, '{}');

        $saved = $this->writer->save($this->manifest, ['name' => 'mycms', 'menus' => [['name' => '文章']]]);

        $this->assertTrue($saved['ok']);

        $raw = (string) file_get_contents($this->manifest);
        $this->assertStringContainsString('文章', $raw, '中文不转义，diff 可读');
        $this->assertStringEndsWith("\n", $raw, '统一尾换行，保证 diff 干净');
        $this->assertSame('文章', json_decode($raw, true)['menus'][0]['name']);
    }

    public function testPathsAndFindCoverNestedChildren(): void
    {
        $menus = [[
            'name'     => '分组',
            'type'     => 'dir',
            'path'     => '',
            'children' => [['name' => '章节', 'path' => '/plugin/x/chapter']],
        ]];

        $this->assertSame(['/plugin/x/chapter'], $this->writer->paths($menus));
        $this->assertSame('/plugin/x/chapter', $this->writer->find($menus, '/plugin/x/chapter/index')['path']);
        $this->assertNull($this->writer->find($menus, '/plugin/x/other'));
    }

    /**
     * 构造一条带「自动合并」标记的条目（模拟工具新增）。
     *
     * @return array<string, mixed>
     */
    private function entry(string $path, string $name): array
    {
        return [
            'name'                        => $name,
            'icon'                        => 'bi bi-circle',
            'path'                        => $path,
            'type'                        => 'menu',
            'sort'                        => 0,
            'status'                      => 1,
            'children'                    => [],
            ManifestMenuWriter::AUTO_MERGED_KEY => ManifestMenuWriter::AUTO_MERGED_NOTE,
        ];
    }
}
