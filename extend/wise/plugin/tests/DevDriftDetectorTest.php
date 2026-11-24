<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use wise\plugin\adapter\AssetManager;
use wise\plugin\adapter\MigrationRunner;
use wise\plugin\dev\DevDriftDetector;
use wise\plugin\manifest\MenuPathNormalizer;
use wise\plugin\PluginManager;
use wise\plugin\scanner\PluginMetadata;

/**
 * 开发期漂移检测测试（`plugin:dev --status` 的唯一事实来源）。
 *
 * 检测器是**只读**的，所以测试用 mock 把三类外部事实（静态副本、扫描缓存、安装状态）
 * 固定下来，只验证「事实 → section 状态 → 整体 drift」的判定：
 *
 *  - `clean`   ：一致，不提示；
 *  - `drift`   ：有差异，退出码应为 1；
 *  - `skipped` ：数据库不可达 / 插件未安装，**不能**据此判定漂移（否则开发机会被库连接卡住）；
 *  - `error`   ：比对过程本身失败。
 *
 * 另外用反射覆盖两个纯函数（菜单身份键、声明树拍平）——它们是「同名不同写法算不算漂移」的口径，
 * 一旦错了，开发者会看到一堆假漂移。
 */
class DevDriftDetectorTest extends TestCase
{
    public function testCleanPluginReportsNoDrift(): void
    {
        $result = $this->detect($this->metadata(), $this->manager(false), $this->assets());

        $this->assertFalse($result['drift']);
        $this->assertSame(
            ['assets', 'cache', 'migrations', 'menus', 'permissions'],
            array_column($result['sections'], 'key'),
            'section 顺序与输出渲染绑定，不能随意调整'
        );
    }

    public function testNotInstalledPluginSkipsDatabaseSections(): void
    {
        $metadata = $this->metadata([
            'menus'       => [['name' => '文章', 'path' => '/plugin/demo/article', 'type' => 'menu']],
            'permissions' => [['name' => '查看', 'ident' => 'demo.view', 'type' => 'custom']],
        ]);

        $result = $this->detect($metadata, $this->manager(false), $this->assets());
        $byKey  = $this->byKey($result);

        foreach (['migrations', 'menus', 'permissions'] as $key) {
            $this->assertSame('skipped', $byKey[$key]['state'], $key . ' 在未安装时应跳过而不是判漂移');
            $this->assertStringContainsString('未安装', $byKey[$key]['summary']);
        }

        $this->assertFalse($result['drift'], '未安装不构成漂移');
    }

    public function testAssetsDriftIsDetected(): void
    {
        $assets = $this->assets([
            'entries' => [
                ['state' => 'copy', 'source' => 'statics/app.css', 'target' => 'demo/app.css'],
                ['state' => 'same', 'source' => 'statics/app.js', 'target' => 'demo/app.js'],
            ],
            'copied'  => 1,
            'same'    => 1,
            'errors'  => 0,
        ]);

        $result = $this->detect($this->metadata(), $this->manager(false), $assets);
        $assetsSection = $this->byKey($result)['assets'];

        $this->assertSame('drift', $assetsSection['state']);
        $this->assertStringContainsString('待同步 1', $assetsSection['summary']);
        $this->assertStringContainsString('statics/app.css', $assetsSection['details'][0]);
        $this->assertTrue($result['drift']);
    }

    public function testAssetsComparisonErrorIsErrorState(): void
    {
        $assets = $this->assets([
            'entries' => [],
            'copied'  => 0,
            'same'    => 0,
            'errors'  => 2,
        ]);

        $section = $this->byKey($this->detect($this->metadata(), $this->manager(false), $assets))['assets'];

        $this->assertSame('error', $section['state']);
        $this->assertStringContainsString('2 个文件比对失败', $section['details'][0]);
    }

    public function testAssetsDetailsAreCappedWithNote(): void
    {
        $entries = [];

        for ($i = 0; $i < 15; $i++) {
            $entries[] = ['state' => 'copy', 'source' => 'statics/f' . $i . '.css', 'target' => 'demo/f' . $i . '.css'];
        }

        $assets  = $this->assets(['entries' => $entries, 'copied' => 15, 'same' => 0, 'errors' => 0]);
        $section = $this->byKey($this->detect($this->metadata(), $this->manager(false), $assets))['assets'];

        $this->assertCount(10, $section['details'], '明细默认只显示 10 条');
        $this->assertStringContainsString('仅列出前 10 项', $section['note']);
    }

    public function testCacheDriftForThisPluginIsDetected(): void
    {
        $manager = $this->manager(false, [
            'enabled'       => true,
            'state'         => 'stale',
            'current'       => [],
            'cached'        => [],
            'stale_plugins' => ['demo' => 'mtime 变化'],
        ]);

        $section = $this->byKey($this->detect($this->metadata(), $manager, $this->assets()))['cache'];

        $this->assertSame('drift', $section['state']);
        $this->assertStringContainsString('mtime 变化', $section['details'][0]);
        $this->assertStringContainsString('--refresh', $section['note']);
    }

    public function testCacheStaleFromOtherPluginsIsNotThisPluginsDrift(): void
    {
        $manager = $this->manager(false, [
            'enabled'       => true,
            'state'         => 'stale',
            'current'       => [],
            'cached'        => [],
            'stale_plugins' => ['other' => 'mtime 变化'],
        ]);

        $result  = $this->detect($this->metadata(), $manager, $this->assets());
        $section = $this->byKey($result)['cache'];

        $this->assertSame('clean', $section['state'], '别的插件改清单，不该让本插件显示漂移');
        $this->assertStringContainsString('1 个插件', $section['note']);
        $this->assertFalse($result['drift']);
    }

    public function testCacheDisabledAndEmptyAreClean(): void
    {
        $disabled = $this->byKey($this->detect(
            $this->metadata(),
            $this->manager(false, ['enabled' => false, 'state' => 'disabled', 'current' => [], 'cached' => null, 'stale_plugins' => []]),
            $this->assets()
        ))['cache'];

        $empty = $this->byKey($this->detect(
            $this->metadata(),
            $this->manager(false, ['enabled' => true, 'state' => 'empty', 'current' => [], 'cached' => null, 'stale_plugins' => []]),
            $this->assets()
        ))['cache'];

        $this->assertSame('clean', $disabled['state']);
        $this->assertStringContainsString('缓存已关闭', $disabled['summary']);
        $this->assertSame('clean', $empty['state']);
        $this->assertStringContainsString('无缓存条目', $empty['summary']);
    }

    public function testEmptyManifestSectionsAreClean(): void
    {
        $result = $this->detect($this->metadata(), $this->manager(false), $this->assets());
        $byKey  = $this->byKey($result);

        $this->assertSame('clean', $byKey['menus']['state']);
        $this->assertSame('clean', $byKey['permissions']['state']);
        $this->assertSame('manifest 未声明菜单', $byKey['menus']['summary']);
        $this->assertSame('manifest 未声明独立权限', $byKey['permissions']['summary']);
    }

    public function testMenuIdentityTreatsShortAndLongFormAsSameNode(): void
    {
        $detector = $this->bareDetector();

        $this->assertSame(
            $this->identity($detector, '/plugin/mycms/article', ''),
            $this->identity($detector, '/plugin/mycms/article/index', ''),
            '/plugin/x/y 与 /plugin/x/y/index 必须视为同一节点，否则每次检测都是假漂移'
        );
        $this->assertNotSame(
            $this->identity($detector, '/plugin/mycms/article', ''),
            $this->identity($detector, '/plugin/mycms/chapter', '')
        );
    }

    public function testMenuIdentityFallsBackToNameForDirectoryNodes(): void
    {
        $detector = $this->bareDetector();

        $this->assertSame('n:内容配置', $this->identity($detector, '', '内容配置'));
        $this->assertSame('', $this->identity($detector, '', ''));
    }

    public function testFlattenDeclaredWalksWholeTreeWithoutDuplicates(): void
    {
        $detector = $this->bareDetector();
        $nodes    = [
            ['name' => '内容配置', 'type' => 'dir', 'path' => '', 'children' => [
                ['name' => '文章', 'path' => '/plugin/mycms/article/index', 'type' => 'menu'],
                ['name' => '章节', 'path' => '/plugin/mycms/chapter', 'type' => 'menu'],
            ]],
            ['name' => '文章（重复声明）', 'path' => '/plugin/mycms/article', 'type' => 'menu'],
            ['name' => '玩家', 'path' => '/plugin/mycms/player', 'type' => 'menu'],
        ];

        $out = [];
        $this->flatten($detector, $nodes, $out);

        $this->assertCount(4, $out, '同名节点只保留第一次声明');
        $this->assertArrayHasKey('n:内容配置', $out);
        $this->assertArrayHasKey('p:plugin/mycms/article', $out);
        $this->assertSame('文章', $out['p:plugin/mycms/article']['name'], '短式声明先入为主');
        $this->assertSame(0, $out['p:plugin/mycms/article']['sort']);
        $this->assertArrayHasKey('p:plugin/mycms/chapter', $out);
        $this->assertArrayHasKey('p:plugin/mycms/player', $out);
    }


    /**
     * @param array<string, mixed> $overrides
     */
    private function metadata(array $overrides = []): PluginMetadata
    {
        $metadata = new PluginMetadata();
        $metadata->setName('demo');
        $metadata->setIdent('demo');
        $metadata->setDisplayName('示例插件');
        $metadata->setEnabled(true);

        $metadata->setMenus($overrides['menus'] ?? []);
        $metadata->setPermissions($overrides['permissions'] ?? []);

        return $metadata;
    }

    /**
     * @param array<string, mixed>|null $cacheSnapshot
     */
    private function manager(bool $installed, ?array $cacheSnapshot = null): PluginManager
    {
        $manager = $this->createMock(PluginManager::class);
        $manager->method('isInstalled')->willReturn($installed);
        $manager->method('scannerCacheDrift')->willReturn($cacheSnapshot ?? [
            'enabled'       => true,
            'state'         => 'clean',
            'current'       => [],
            'cached'        => [],
            'stale_plugins' => [],
        ]);

        return $manager;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assets(array $result = [
        'entries' => [],
        'copied'  => 0,
        'same'    => 0,
        'errors'  => 0,
    ]): AssetManager {
        $assets = $this->createMock(AssetManager::class);
        $assets->method('syncAssets')->willReturn($result);

        return $assets;
    }

    /**
     * @return array<string, mixed>
     */
    private function detect(PluginMetadata $metadata, PluginManager $manager, AssetManager $assets): array
    {
        $detector = new DevDriftDetector($manager, $assets, $this->createMock(MigrationRunner::class));

        return $detector->detect($metadata);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $result): array
    {
        $out = [];

        foreach ($result['sections'] as $section) {
            $out[$section['key']] = $section;
        }

        return $out;
    }

    private function bareDetector(): DevDriftDetector
    {
        $detector = (new ReflectionClass(DevDriftDetector::class))->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(DevDriftDetector::class, 'normalizer');
        $property->setAccessible(true);
        $property->setValue($detector, new MenuPathNormalizer());

        return $detector;
    }

    private function identity(DevDriftDetector $detector, string $path, string $name): string
    {
        $method = new ReflectionMethod(DevDriftDetector::class, 'menuIdentity');
        $method->setAccessible(true);

        return (string) $method->invoke($detector, $path, $name);
    }

    /**
     * @param array<int, array<string, mixed>>                         $nodes
     * @param array<string, array{name:string, sort:int, path:string}> $out
     */
    private function flatten(DevDriftDetector $detector, array $nodes, array &$out): void
    {
        $method = new ReflectionMethod(DevDriftDetector::class, 'flattenDeclared');
        $method->setAccessible(true);
        $method->invokeArgs($detector, [$nodes, &$out]);
    }
}
