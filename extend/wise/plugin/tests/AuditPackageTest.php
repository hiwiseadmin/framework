<?php

declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\plugin\audit\AuditRuleSet;
use wise\plugin\audit\AuditRunner;

/**
 * 待审计包（目录 / zip）的结构判定测试。
 *
 * 这组用例从已删除的手工脚本 `tests/manual/plugin-audit-smoke.php` 迁移而来，
 * 守的是 phpunit 里原本没有覆盖的部分：
 *  1. 扁平（manifest 在根）/ 包裹（唯一顶层目录）/ 多根（broken）三种结构；
 *  2. zip 与目录走同一套规则（作者审源码干净、不代表 zip 干净）；
 *  3. 路径穿越（`../`）必须命中 `structure.unsafe_path`，不能被归一化吞掉。
 *
 * 没有 ZipArchive 的环境只跳过 zip 用例，目录结构断言仍然执行。
 */
class AuditPackageTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiseauditpkg_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testDirectoryWithManifestAtRootIsFlat(): void
    {
        $dir = $this->makePlugin('demo');

        $package = AuditPackage::open($dir);

        $this->assertSame(AuditPackage::TYPE_DIR, $package->type());
        $this->assertSame('flat', $package->structureType(), 'manifest 在根 = flat');
        $this->assertSame('', $package->rootDir());
        $this->assertSame('manifest.json', $package->manifestPath());
    }

    public function testWrappedZipIsDetected(): void
    {
        $this->requireZip();

        $zip = $this->makeZip('wrapped.zip', [
            'demo/manifest.json'      => $this->manifest(['name' => 'demo']),
            'demo/controller/Index.php' => "<?php\nnamespace plugin\\demo\\controller;\nclass Index {}\n",
        ]);

        $package = AuditPackage::open($zip);

        $this->assertSame(AuditPackage::TYPE_ZIP, $package->type());
        $this->assertSame('wrapped', $package->structureType());
        $this->assertSame('demo/', $package->rootDir(), '包裹结构的 rootDir 带尾斜杠');
        $this->assertSame('demo/manifest.json', $package->manifestPath());
    }

    public function testMultipleTopLevelDirsAreBroken(): void
    {
        $this->requireZip();

        $zip = $this->makeZip('multiroot.zip', [
            'a/one.txt' => 'x',
            'b/two.txt' => 'y',
        ]);

        $package = AuditPackage::open($zip);

        $this->assertSame('broken', $package->structureType());
        $this->assertContains(
            AuditRuleSet::STRUCTURE_MULTIPLE_ROOTS,
            $this->rules($this->runRules($package))
        );
    }

    public function testZipManifestRulesStillApply(): void
    {
        $this->requireZip();

        $zip = $this->makeZip('bad.zip', [
            'demo/manifest.json' => json_encode([
                'name'    => 'demo',
                'version' => '1.0.0',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $issues = $this->runRules(AuditPackage::open($zip));

        $this->assertContains(AuditRuleSet::MANIFEST_REQUIRED_FIELD, $this->rules($issues));
        $this->assertNotEmpty($this->errors($issues), '缺必填字段必须阻断');
    }

    public function testTraversalEntryIsReported(): void
    {
        $this->requireZip();

        $zip = new ZipArchive();

        if ($zip->open($this->root . '/traversal.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->fail('无法创建测试 zip');
        }

        $zip->addFromString('manifest.json', $this->manifest(['name' => 'demo']));
        $zip->addFromString('BootServiceProvider.php', "<?php\nclass BootServiceProvider {}\n");
        $added = $zip->addFromString('../escape.php', '<?php echo 1;');
        $zip->close();

        if (!$added) {
            $this->markTestSkipped('当前 ZipArchive 拒绝写入 `..` 条目，无法构造穿越用例');
        }

        $issues = $this->runRules(AuditPackage::open($this->root . '/traversal.zip'));

        $this->assertContains(
            AuditRuleSet::STRUCTURE_UNSAFE_PATH,
            $this->rules($issues),
            '原始条目名里的 `../` 必须命中危险路径，不能被归一化吞掉'
        );
    }

    public function testReadRejectsTraversalPath(): void
    {
        $dir = $this->makePlugin('demo');

        $package = AuditPackage::open($dir);

        $this->assertNull($package->read('../manifest.json'), 'read() 不得越出包根');
        $this->assertFalse($package->exists('../manifest.json'));
    }


    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('缺少 ZipArchive 扩展');
        }
    }

    /**
     * @param array<string, string> $entries 相对路径 => 内容
     */
    private function makeZip(string $name, array $entries): string
    {
        $path = $this->root . '/' . $name;
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建测试 zip：' . $path);
        }

        foreach ($entries as $relative => $content) {
            $zip->addFromString($relative, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePlugin(string $ident, array $overrides = []): string
    {
        $dir = $this->root . '/' . $ident;
        mkdir($dir . '/controller', 0755, true);

        file_put_contents($dir . '/manifest.json', $this->manifest(array_merge(['name' => $ident], $overrides)));
        file_put_contents(
            $dir . '/BootServiceProvider.php',
            "<?php\nnamespace plugin\\{$ident};\nclass BootServiceProvider {}\n"
        );
        file_put_contents(
            $dir . '/controller/Index.php',
            "<?php\nnamespace plugin\\{$ident}\\controller;\nclass Index {}\n"
        );

        return $dir;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function manifest(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'name'        => 'demo',
            'title'       => '演示插件',
            'version'     => '1.0.0',
            'description' => '用于结构判定测试',
            'author'      => 'wiseAdmin',
        ], $overrides), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, AuditIssue>
     */
    private function runRules(AuditPackage $package): array
    {
        return (new AuditRunner(AuditRuleSet::regexRules()))->run($package);
    }

    /**
     * @param array<int, AuditIssue> $issues
     *
     * @return array<int, string>
     */
    private function rules(array $issues): array
    {
        return array_map(static fn (AuditIssue $issue): string => $issue->rule, $issues);
    }

    /**
     * @param array<int, AuditIssue> $issues
     *
     * @return array<int, AuditIssue>
     */
    private function errors(array $issues): array
    {
        return array_values(array_filter(
            $issues,
            static fn (AuditIssue $issue): bool => $issue->level === AuditIssue::ERROR
        ));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
