<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use ZipArchive;
use wise\plugin\publish\PublishPackager;

/**
 * 发布打包器测试。
 *
 * 三个必须成立的事实：
 *  1. 产物是 **wrapped** 结构（`demo/manifest.json`）—— 本地安装器与服务端都认；
 *  2. `size` / `sha256` 与服务端落库口径一致（`hash_file('sha256')`），否则市场会判「包被篡改」；
 *  3. 排除项真的没进包（`node_modules` / `runtime` / `.env` 进包 = 体积翻倍 + 泄密）。
 */
class PublishPackagerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('缺少 ZipArchive 扩展');
        }

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisepublish_' . uniqid();
        mkdir($this->root . '/plugins', 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            $this->removeDir($this->root);
        }
    }

    public function testPackagesWrappedZipWithSha256(): void
    {
        $this->makePlugin('demo');
        $result = $this->packager()->package('demo', '1.2.3', $this->root . '/dist');

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(
            str_replace('\\', '/', $this->root) . '/dist/demo-1.2.3.zip',
            str_replace('\\', '/', $result['path'])
        );
        $this->assertGreaterThan(0, $result['size']);
        $this->assertSame(hash_file('sha256', $result['path']), $result['sha256'], 'sha256 必须与市场落库口径一致');

        $entries = $this->entries($result['path']);

        $this->assertContains('demo/manifest.json', $entries, '必须是 wrapped 结构');
        $this->assertContains('demo/controller/Index.php', $entries);
        $this->assertContains('demo/statics/app.css', $entries);
    }

    public function testExcludesDependencyAndRuntimeDirectories(): void
    {
        $plugin = $this->makePlugin('demo');
        mkdir($plugin . '/node_modules/pkg', 0755, true);
        file_put_contents($plugin . '/node_modules/pkg/index.js', 'module.exports = 1;');
        mkdir($plugin . '/runtime/cache', 0755, true);
        file_put_contents($plugin . '/runtime/cache/a.php', '<?php return [];');
        file_put_contents($plugin . '/.env', 'DB_PASS=secret');
        mkdir($plugin . '/tests', 0755, true);
        file_put_contents($plugin . '/tests/DemoTest.php', '<?php');

        $result  = $this->packager()->package('demo', '1.0.0', $this->root . '/dist');
        $entries = $this->entries($result['path']);

        foreach (['node_modules', 'runtime', 'tests', '.env'] as $forbidden) {
            foreach ($entries as $entry) {
                $this->assertStringNotContainsString('/' . $forbidden, $entry, $forbidden . ' 不应进包：' . $entry);
            }
        }

        $this->assertContains('node_modules', $result['skip']);
    }

    public function testDefaultGlobSkipExcludesLogsAndSourceMaps(): void
    {
        $plugin = $this->makePlugin('demo');
        file_put_contents($plugin . '/debug.log', 'runtime noise');
        file_put_contents($plugin . '/statics/app.js.map', '{"version":3}');
        file_put_contents($plugin . '/notes.tmp', 'tmp');
        file_put_contents($plugin . '/.swap.swp', 'swap');
        mkdir($plugin . '/logs', 0755, true);
        file_put_contents($plugin . '/logs/APP.LOG', 'uppercase log');

        $result  = $this->packager()->package('demo', '1.0.0', $this->root . '/dist');
        $entries = $this->entries($result['path']);

        foreach ($entries as $entry) {
            foreach (['.log', '.map', '.tmp', '.swp'] as $suffix) {
                $this->assertStringNotContainsString($suffix, strtolower($entry), $suffix . ' 不应进包：' . $entry);
            }
        }

        $this->assertContains('demo/statics/app.css', $entries, '业务资源必须保留');
        $this->assertContains('*.log', $result['skip']);
        $this->assertContains('*.map', $result['skip']);
    }

    public function testGlobExtraSkipMatchesAnyLevelAndIsCaseInsensitive(): void
    {
        $plugin = $this->makePlugin('demo');
        mkdir($plugin . '/sub/deep', 0755, true);
        file_put_contents($plugin . '/sub/deep/old.BAK', 'backup');
        file_put_contents($plugin . '/keep.txt', 'keep');

        $result  = $this->packager()->package('demo', '1.0.0', $this->root . '/dist', ['*.bak']);
        $entries = $this->entries($result['path']);

        $this->assertContains('*.bak', $result['skip']);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('.bak', strtolower($entry), 'glob 应跨层级且忽略大小写：' . $entry);
        }

        $this->assertContains('demo/keep.txt', $entries, '未命中的文件必须保留');
    }

    public function testCharacterClassGlobIsSupported(): void
    {
        $plugin = $this->makePlugin('demo');
        file_put_contents($plugin . '/file-1.txt', '1');
        file_put_contents($plugin . '/file-a.txt', 'a');

        $result  = $this->packager()->package('demo', '1.0.0', $this->root . '/dist', ['file-[0-9].txt']);
        $entries = $this->entries($result['path']);

        $this->assertNotContains('demo/file-1.txt', $entries);
        $this->assertContains('demo/file-a.txt', $entries);
    }

    public function testSkipMatchesBasenameOnlyNotPaths(): void
    {
        $plugin = $this->makePlugin('demo');
        mkdir($plugin . '/runtime/cache', 0755, true);
        file_put_contents($plugin . '/runtime/cache/a.txt', 'x');

        $result = $this->packager()->package('demo', '1.0.0', $this->root . '/dist', ['runtime/cache']);

        foreach ($this->entries($result['path']) as $entry) {
            $this->assertStringNotContainsString('runtime/', $entry, '目录名命中时整棵子树都不应进包');
        }
    }

    public function testExtraSkipIsMerged(): void
    {
        $plugin = $this->makePlugin('demo');
        file_put_contents($plugin . '/notes.txt', 'internal');

        $result  = $this->packager()->package('demo', '1.0.0', $this->root . '/dist', ['notes.txt']);
        $entries = $this->entries($result['path']);

        $this->assertContains('notes.txt', $result['skip']);
        $this->assertNotContains('demo/notes.txt', $entries);
    }

    public function testMissingManifestFails(): void
    {
        $dir = $this->root . '/plugins/broken';
        mkdir($dir, 0755, true);

        $result = $this->packager()->package('broken', '1.0.0', $this->root . '/dist');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('manifest.json', $result['error']);
        $this->assertFileDoesNotExist($this->root . '/dist/broken-1.0.0.zip');
    }

    public function testMissingPluginDirectoryFails(): void
    {
        $result = $this->packager()->package('not_exist', '1.0.0', $this->root . '/dist');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('插件目录不存在', $result['error']);
    }

    public function testFileNameSanitizesVersion(): void
    {
        $packager = $this->packager();

        $this->assertSame('demo-1.0.0.zip', $packager->fileName('demo', '1.0.0'));
        $this->assertSame('demo-1.0.0-beta.1.zip', $packager->fileName('demo', '1.0.0-beta.1'), '语义化后缀要保留');
        $this->assertSame('demo-1.0-0-2.zip', $packager->fileName('demo', '1.0 0/2'), '非法字符替换为 -');
    }


    private function packager(): PublishPackager
    {
        return new PublishPackager($this->root . '/plugins');
    }

    private function makePlugin(string $ident): string
    {
        $dir = $this->root . '/plugins/' . $ident;
        mkdir($dir . '/controller', 0755, true);
        mkdir($dir . '/statics', 0755, true);

        file_put_contents(
            $dir . '/manifest.json',
            (string) json_encode([
                'name'    => $ident,
                'version' => '1.0.0',
                'title'   => '示例',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents($dir . '/controller/Index.php', "<?php\nnamespace plugin\\demo\\controller;\n\nclass Index\n{\n}\n");
        file_put_contents($dir . '/statics/app.css', 'body{}');

        return $dir;
    }

    /**
     * @return array<int, string>
     */
    private function entries(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, '无法打开产物：' . $zipPath);

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $entries;
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
