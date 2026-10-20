<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudGenerator;
use wise\crud\CrudWriter;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * 插件菜单自动合并进 manifest.json 的测试。
 *
 * 合并逻辑被设计成**幂等 + 非破坏**，且失败只返回结果、不抛异常
 * （产物文件此时已写入成功，合并失败不应让整条命令变成失败）。
 * 本测试把这些约定全部锁住。
 */
class ManifestMergeTest extends CrudTestCase
{
    private string $dir = '';
    private string $manifest = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_' . uniqid();
        $this->manifest = $this->dir . DIRECTORY_SEPARATOR . 'manifest.json';

        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * Writer 子类：把 manifest 路径指向临时目录，避免触碰真实 plugins/。
     */
    private function writer(): CrudWriter
    {
        $writer = new class (new CrudGenerator()) extends CrudWriter {
            public string $testManifestPath = '';

            public function manifestPath(string $plugin): string
            {
                return $this->testManifestPath;
            }
        };

        $writer->testManifestPath = $this->manifest;

        return $writer;
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $plugin = 'mycms', string $controllerUrl = 'article'): array
    {
        return [
            'table'          => 'mycms_article',
            'table_short'    => 'mycms_article',
            'class'          => 'Article',
            'title'          => '文章',
            'module'         => 'admin',
            'plugin'         => $plugin,
            'controller_url' => $controllerUrl,
            'generator'      => ['menu_plugin' => 'mycms'],
            'fields'         => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        file_put_contents(
            $this->manifest,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        return (array) json_decode((string) file_get_contents($this->manifest), true);
    }

    // ──────────────────────────────────────────────
    //  追加与标记
    // ──────────────────────────────────────────────

    public function testAppendsMenuWithAutoMergedMarker(): void
    {
        $this->write([
            'name'  => 'mycms',
            'menus' => [['name' => '原有菜单', 'path' => '/plugin/mycms/index']],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('已自动合并', $result['message']);

        $menus = $this->read()['menus'];
        $this->assertCount(2, $menus, '应追加到已有菜单之后');

        // 原有菜单不受影响
        $this->assertSame('/plugin/mycms/index', $menus[0]['path']);

        $entry = $menus[1];
        $this->assertSame('/plugin/mycms/article/index', $entry['path']);
        $this->assertSame('文章', $entry['name']);
        $this->assertArrayHasKey(CrudWriter::AUTO_MERGED_KEY, $entry);
        $this->assertStringContainsString('手动', (string) $entry[CrudWriter::AUTO_MERGED_KEY]);
        // 标记值必须稳定（不含时间戳），否则重复执行会产生无谓 diff
        $this->assertSame(CrudWriter::AUTO_MERGED_NOTE, $entry[CrudWriter::AUTO_MERGED_KEY]);
    }

    public function testMergedEntryOnlyUsesManifestCompatibleKeysPlusMarker(): void
    {
        // 标记之外的字段必须是 manifest 规范键，否则菜单同步会丢字段
        $entry = $this->writer()->manifestMenuEntry($this->plan(), true);

        $this->assertSame(
            ['name', 'icon', 'path', 'type', 'sort', 'status', 'children', CrudWriter::AUTO_MERGED_KEY],
            array_keys($entry)
        );
    }

    public function testManualSnippetHasNoMarker(): void
    {
        // 手工粘贴属于刻意行为，不该带「自动合并」标记
        $snippet = $this->writer()->manifestMenuSnippet($this->plan());

        $this->assertStringNotContainsString(CrudWriter::AUTO_MERGED_KEY, $snippet);
        $this->assertStringContainsString('"/plugin/mycms/article/index"', $snippet);
    }

    // ──────────────────────────────────────────────
    //  幂等 / 非破坏
    // ──────────────────────────────────────────────

    public function testMergeIsIdempotent(): void
    {
        $this->write(['name' => 'mycms', 'menus' => []]);

        $writer = $this->writer();
        $writer->mergeManifestMenu($this->plan());
        $first = (string) file_get_contents($this->manifest);

        $writer->mergeManifestMenu($this->plan());

        $this->assertCount(1, $this->read()['menus'], '重复执行不应新增菜单');
        $this->assertSame($first, (string) file_get_contents($this->manifest), '重复执行不应改动文件');
    }

    public function testMergeDoesNotOverwriteManualEdits(): void
    {
        // 已存在同 path 且被手工调整过 → 原样保留，也**不补标记**
        // （手工维护的菜单不该被误标成「自动合并」）
        $this->write([
            'name'  => 'mycms',
            'menus' => [[
                'name' => '我改过的标题',
                'path' => '/plugin/mycms/article/index',
                'sort' => 42,
            ]],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('原样保留', $result['message']);

        $menus = $this->read()['menus'];
        $this->assertCount(1, $menus);
        $this->assertSame('我改过的标题', $menus[0]['name']);
        $this->assertSame(42, $menus[0]['sort']);
        $this->assertArrayNotHasKey(CrudWriter::AUTO_MERGED_KEY, $menus[0]);
    }

    // ──────────────────────────────────────────────
    //  等价 path（写法不同）与子层去重
    // ──────────────────────────────────────────────

    public function testMergeTreatsEquivalentPathStyleAsSameNode(): void
    {
        // 既有条目是**少数派写法**（短式），多数派是长式 → 生成器仍产出长式，
        // 但两者身份相同 → 视为同一节点：不追加、不改写既有写法、不补标记
        $this->write([
            'name'  => 'mycms',
            'menus' => [
                ['name' => '我改过的标题', 'path' => '/plugin/mycms/article', 'sort' => 42],
                ['name' => '栏目', 'path' => '/plugin/mycms/category/index'],
                ['name' => '标签', 'path' => '/plugin/mycms/tag/index'],
            ],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('等价菜单', $result['message']);

        $menus = $this->read()['menus'];
        $this->assertCount(3, $menus, '等价写法不得追加重复节点');
        $this->assertSame('/plugin/mycms/article', $menus[0]['path'], '不得改写既有写法');
        $this->assertSame('我改过的标题', $menus[0]['name']);
        $this->assertSame(42, $menus[0]['sort']);
        $this->assertArrayNotHasKey(CrudWriter::AUTO_MERGED_KEY, $menus[0]);
    }

    public function testMergeFindsEquivalentNodeInsideGroup(): void
    {
        // 开发者把生成器产出的平铺菜单手工挪进了 dir 分组 → 递归查找，不在顶层重复追加
        $this->write([
            'name'  => 'mycms',
            'menus' => [[
                'name'     => '内容配置',
                'type'     => 'dir',
                'path'     => '',
                'children' => [
                    ['name' => '文章', 'path' => '/plugin/mycms/article/index'],
                ],
            ]],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('原样保留', $result['message']);

        $menus = $this->read()['menus'];
        $this->assertCount(1, $menus, '分组内已存在时不得在顶层追加');
        $this->assertCount(1, $menus[0]['children']);
    }

    public function testMergeStillAppendsForDifferentActionOfSameController(): void
    {
        // 负样本：归一化不得过度收敛 —— 同一控制器的另一个动作是**另一个页面**
        $this->write([
            'name'  => 'mycms',
            'menus' => [['name' => '文章详情配置', 'path' => '/plugin/mycms/article/detail']],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('已自动合并', $result['message']);
        $this->assertCount(2, $this->read()['menus']);
    }

    // ──────────────────────────────────────────────
    //  风格继承与控制器段拼写
    // ──────────────────────────────────────────────

    public function testMergeInheritsShortFormFromExistingMenus(): void
    {
        // 既有条目以短式为主 → 追加的条目也用短式（避免插件内长短混排）
        $this->write([
            'name'  => 'mycms',
            'menus' => [
                ['name' => '栏目', 'path' => '/plugin/mycms/category'],
                ['name' => '标签', 'path' => '/plugin/mycms/tag'],
                ['name' => '回收站', 'path' => '/plugin/mycms/recycle/index'],
            ],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('已自动合并', $result['message']);

        $menus = $this->read()['menus'];
        $this->assertSame('/plugin/mycms/article', $menus[3]['path'], '新条目沿用短式');
    }

    public function testMergeInheritsLongFormFromExistingMenus(): void
    {
        $this->write([
            'name'  => 'mycms',
            'menus' => [
                ['name' => '栏目', 'path' => '/plugin/mycms/category/index'],
                ['name' => '标签', 'path' => '/plugin/mycms/tag/index'],
                ['name' => '回收站', 'path' => '/plugin/mycms/recycle'],
            ],
        ]);

        $this->writer()->mergeManifestMenu($this->plan());

        $this->assertSame('/plugin/mycms/article/index', $this->read()['menus'][3]['path'], '新条目沿用长式');
    }

    public function testMergeDefaultsToLongFormWhenOnlyActionLevelEntriesExist(): void
    {
        // 动作级页面（`report/inOut`）不表达风格偏好 → 回落到长式
        $this->write([
            'name'  => 'mycms',
            'menus' => [
                ['name' => '报表一', 'path' => '/plugin/mycms/report/inOut'],
                ['name' => '报表二', 'path' => '/plugin/mycms/report/turnover'],
            ],
        ]);

        $this->writer()->mergeManifestMenu($this->plan());

        $this->assertSame('/plugin/mycms/article/index', $this->read()['menus'][2]['path']);
    }

    public function testMergeTreatsCamelCaseSpellingAsSameNode(): void
    {
        // 既有条目写作 camelCase（wisecms 形态），生成器产出 snake_case → 同一控制器，不追加
        $this->write([
            'name'  => 'mycms',
            'menus' => [['name' => '分组对战', 'path' => '/plugin/mycms/groupBattle/index']],
        ]);

        $result = $this->writer()->mergeManifestMenu($this->plan('mycms', 'group_battle'));

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('等价菜单', $result['message']);
        $this->assertCount(1, $this->read()['menus']);
        $this->assertSame('/plugin/mycms/groupBattle/index', $this->read()['menus'][0]['path']);
    }

    public function testPreferredFormForPluginReadsExistingStyle(): void
    {
        $this->write([
            'name'  => 'mycms',
            'menus' => [['name' => '栏目', 'path' => '/plugin/mycms/category']],
        ]);

        $writer = $this->writer();

        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $writer->preferredFormForPlugin('mycms'));
        $this->assertStringContainsString('"/plugin/mycms/article"', $writer->manifestMenuSnippet($this->plan(), MenuPathNormalizer::FORM_SHORT));
        $this->assertStringContainsString('"/plugin/mycms/article/index"', $writer->manifestMenuSnippet($this->plan()));
    }

    public function testPreferredFormForPluginReturnsNullWhenManifestMissingOrBroken(): void
    {
        $writer = $this->writer();

        $this->assertNull($writer->preferredFormForPlugin('mycms'), 'manifest 不存在 → null（用默认长式）');

        file_put_contents($this->manifest, '{ 这不是 JSON');
        $this->assertNull($writer->preferredFormForPlugin('mycms'), 'manifest 非法 → null');
    }

    public function testMergeCreatesMenusKeyWhenAbsent(): void
    {
        $this->write(['name' => 'mycms']);

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->read()['menus']);
    }

    // ──────────────────────────────────────────────
    //  失败降级（不抛异常、不破坏原文件）
    // ──────────────────────────────────────────────

    public function testMergeReportsFailureWhenManifestMissing(): void
    {
        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('未找到 manifest.json', $result['message']);
    }

    public function testMergeReportsFailureOnInvalidJsonWithoutCorruptingFile(): void
    {
        file_put_contents($this->manifest, '{ 这不是 JSON');

        $result = $this->writer()->mergeManifestMenu($this->plan());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('不是合法 JSON', $result['message']);
        // 解析失败时绝不能回写，否则会把用户的 manifest 清成 {}
        $this->assertSame('{ 这不是 JSON', (string) file_get_contents($this->manifest));
    }
}
