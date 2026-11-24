<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\derive\MenuDeriver;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * 菜单推导内核测试（纯静态、无框架依赖）。
 *
 * 用临时目录伪造插件：`{tmp}/plugins/{ident}/{manifest.json,controller/*.php}`，
 * 因此可以直接验证「推导规则 / 安全规则 / 漂移判定」而不触碰真实插件目录。
 */
class MenuDeriverTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisemenu_' . uniqid();
        mkdir($this->root . DIRECTORY_SEPARATOR . 'plugins', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }


    public function testDerivesIndexNodesAndReportsDrift(): void
    {
        $this->makePlugin('mycms', ['name' => 'mycms', 'menus' => [
            ['name' => '内容配置', 'type' => 'dir', 'path' => '', 'children' => [
                ['name' => '文章', 'path' => '/plugin/mycms/article', 'type' => 'menu'],
                ['name' => '文章（重复声明）', 'path' => '/plugin/mycms/article/index', 'type' => 'menu'],
                ['name' => '章节', 'path' => '/plugin/mycms/chapter/index', 'type' => 'menu'],
                ['name' => '章节生成', 'path' => '/plugin/mycms/chapter/generate', 'type' => 'menu'],
                ['name' => '对战', 'path' => '/plugin/mycms/groupBattle/index', 'type' => 'menu'],
                ['name' => '章节生成按钮', 'path' => '/plugin/mycms/chapter/generate', 'type' => 'button'],
            ]],
            ['name' => '玩家查询', 'path' => '/plugin/mycms/player_detail', 'type' => 'menu'],
        ]], [
            'Article.php' => $this->controller('Article', <<<'PHP'
    public function index() {}

    public function getList() {}

    protected function helper() {}
PHP),
            'Chapter.php' => $this->controller('Chapter', <<<'PHP'
    public function index() {}

    /**
     * @menu name="章节生成" group="内容配置" icon="bi bi-magic" sort=20
     */
    public function generate() {}

    /** @menu-ignore */
    public function raw() {}
PHP),
            'GroupBattle.php'  => $this->controller('GroupBattle', '    public function index() {}'),
            'Newsletter.php'   => $this->controller('Newsletter', '    public function index() {}'),
            'Playtest.php'     => $this->controller('Playtest', '    public function index() {}'),
            'Ignored.php'      => $this->controller('Ignored', "    /** @menu-ignore */\n    public function index() {}"),
            'Base.php'         => $this->controller('Base', ''),
        ]);

        $result = $this->deriver()->derive('mycms');

        $this->assertNull($result['error']);
        $this->assertTrue($result['exists']);
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $result['form'], '既有条目以长式为主 → 候选也用长式');

        $this->assertSame(5, $result['scanned']);
        $this->assertSame(1, $result['blocked'], 'Playtest 含敏感词，被硬拦截');

        $paths = array_column($result['candidates'], 'path');
        $this->assertContains('/plugin/mycms/article/index', $paths);
        $this->assertContains('/plugin/mycms/chapter/index', $paths);
        $this->assertContains('/plugin/mycms/chapter/generate', $paths, '@menu 注解强制产出');
        $this->assertContains('/plugin/mycms/group_battle/index', $paths, 'URL 段用 snake_case');
        $this->assertContains('/plugin/mycms/newsletter/index', $paths);

        $methods     = array_column($result['candidates'], 'method');
        $controllers = array_column($result['candidates'], 'controller');

        $this->assertNotContains('getList', $methods);
        $this->assertNotContains('helper', $methods);
        $this->assertNotContains('raw', $methods, '@menu-ignore 豁免注解产出');
        $this->assertNotContains('Ignored', $controllers, '@menu-ignore 也会豁免 index');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('playtest', $path);
        }

        $this->assertCount(1, $result['missing']);
        $this->assertSame('Newsletter', $result['missing'][0]['controller']);

        $this->assertSame(['/plugin/mycms/player_detail'], array_column($result['extra'], 'path'));

        $this->assertCount(1, $result['duplicates']);
        $this->assertSame('plugin/mycms/article', $result['duplicates'][0]['identity']);

        $this->assertTrue($result['drift']);
    }

    public function testStyleInheritanceShortForm(): void
    {
        $this->makePlugin('myshop', ['name' => 'myshop', 'menus' => [
            ['name' => '商品', 'path' => '/plugin/myshop/product', 'type' => 'menu'],
            ['name' => '仓库', 'path' => '/plugin/myshop/warehouse', 'type' => 'menu'],
        ]], [
            'Product.php' => $this->controller('Product', '    public function index() {}', 'myshop'),
        ]);

        $result = $this->deriver()->derive('myshop');

        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $result['form']);
        $this->assertSame(
            ['/plugin/myshop/product'],
            array_column($result['candidates'], 'path')
        );
        $this->assertSame([], $result['missing'], '短式候选与短式声明按身份相等');
        $this->assertFalse($result['drift']);
    }

    public function testDriftIsCleanWhenOnlyAndExcludeFilterControllers(): void
    {
        $this->makePlugin('mycms', ['name' => 'mycms', 'menus' => [
            ['name' => '文章', 'path' => '/plugin/mycms/article/index', 'type' => 'menu'],
        ]], [
            'Article.php'    => $this->controller('Article', '    public function index() {}'),
            'Newsletter.php' => $this->controller('Newsletter', '    public function index() {}'),
        ]);

        $deriver = $this->deriver();

        $this->assertTrue($deriver->derive('mycms')['drift'], '默认会发现 Newsletter 漂移');
        $this->assertFalse($deriver->derive('mycms', ['exclude' => ['Newsletter']])['drift']);
        $this->assertFalse($deriver->derive('mycms', ['only' => 'Article'])['drift']);
        $this->assertSame(1, $deriver->derive('mycms', ['only' => 'Article'])['scanned']);
    }

    public function testGroupOptionFillsMissingGroups(): void
    {
        $this->makePlugin('mycms', ['name' => 'mycms', 'menus' => []], [
            'Article.php' => $this->controller('Article', '    public function index() {}'),
        ]);

        $candidate = $this->deriver()->derive('mycms', ['group' => '内容配置'])['candidates'][0];

        $this->assertSame('内容配置', $candidate['group']);
        $this->assertSame('Article', $candidate['name'], '无 @menu name 时回落类名');
        $this->assertSame('index', $candidate['source']);
        $this->assertSame('plugins/mycms/controller/Article.php', $candidate['file']);
    }

    public function testDerivesButtonNodesOnlyWhenAnnotated(): void
    {
        $body = <<<'PHP'
    public function index() {}

    /**
     * @menu name="修改密码" type="button" icon="bi bi-key" sort=3
     */
    public function changePassword() {}

    public function save() {}
    PHP;

        $this->makePlugin('myshop', ['name' => 'myshop', 'menus' => []], [
            'Product.php' => $this->controller('Product', $body),
        ]);

        $result = $this->deriver()->derive('myshop');

        $byPath = [];
        foreach ($result['candidates'] as $candidate) {
            $byPath[$candidate['path']] = $candidate;
        }

        $this->assertSame('menu', $byPath['/plugin/myshop/product/index']['type']);
        $this->assertSame('button', $byPath['/plugin/myshop/product/changePassword']['type']);
        $this->assertSame('修改密码', $byPath['/plugin/myshop/product/changePassword']['name']);
        $this->assertSame(3, $byPath['/plugin/myshop/product/changePassword']['sort']);
        $this->assertArrayNotHasKey('/plugin/myshop/product/save', $byPath, '未注解的方法不产出');
    }

    public function testButtonEntryOmitsChildren(): void
    {
        $entry = (new FakeMenuDeriveCommand())->entry([
            'name' => '修改密码',
            'path' => '/plugin/myshop/product/changePassword',
            'type' => 'button',
            'sort' => 3,
        ]);

        $this->assertSame('button', $entry['type']);
        $this->assertArrayNotHasKey('children', $entry, '按钮不写 children');
        $this->assertSame(3, $entry['sort']);
    }


    public function testReportsErrorWhenPluginMissing(): void
    {
        $result = $this->deriver()->derive('not_exist');

        $this->assertFalse($result['exists']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('插件目录不存在', (string) $result['error']);
    }

    public function testReportsErrorOnInvalidIdent(): void
    {
        $result = $this->deriver()->derive('../etc');

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('插件标识非法', (string) $result['error']);
    }

    public function testReportsErrorOnBrokenManifest(): void
    {
        $dir = $this->root . '/plugins/broken';
        mkdir($dir . '/controller', 0755, true);
        file_put_contents($dir . '/manifest.json', '{ 这不是 JSON');

        $result = $this->deriver()->derive('broken');

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('不是合法 JSON', (string) $result['error']);
    }

    public function testPluginsListsOnlyDirsWithManifest(): void
    {
        $this->makePlugin('mycms', ['name' => 'mycms', 'menus' => []], []);
        mkdir($this->root . '/plugins/no_manifest', 0755, true);

        $this->assertSame(['mycms'], $this->deriver()->plugins());
    }

    public function testPluginWithoutControllerDirectoryIsNotDrift(): void
    {
        $this->makePlugin('emptyplugin', ['name' => 'emptyplugin', 'menus' => []], []);

        $result = $this->deriver()->derive('emptyplugin');

        $this->assertNull($result['error']);
        $this->assertSame(0, $result['scanned']);
        $this->assertSame([], $result['candidates']);
        $this->assertFalse($result['drift']);
    }


    /**
     * @param array<string, mixed>  $manifest
     * @param array<string, string> $controllers 文件名 => 源码
     */
    private function makePlugin(string $ident, array $manifest, array $controllers): void
    {
        $dir = $this->root . '/plugins/' . $ident;
        mkdir($dir . '/controller', 0755, true);

        file_put_contents(
            $dir . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        foreach ($controllers as $file => $code) {
            file_put_contents($dir . '/controller/' . $file, $code);
        }
    }

    private function controller(string $class, string $body, string $ident = 'mycms'): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace plugin\\{$ident}\\controller;\n\n"
            . "use plugin\\BaseController;\n\nclass {$class} extends BaseController\n{\n{$body}\n}\n";
    }

    private function deriver(): MenuDeriver
    {
        return new MenuDeriver(
            $this->root . DIRECTORY_SEPARATOR . 'plugins',
            new MenuPathNormalizer()
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

/**
 * 暴露 `candidateEntry()` 的测试替身（验证 manifest 条目形态）
 */
class FakeMenuDeriveCommand extends \wise\plugin\command\tool\MenuDerive
{
    public function entry(array $candidate): array
    {
        return $this->candidateEntry($candidate, false);
    }
}
