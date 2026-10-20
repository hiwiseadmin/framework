<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudGenerator;
use wise\crud\CrudPlanBuilder;
use wise\crud\CrudWriter;
use wise\crud\FieldTypeResolver;

/**
 * 落盘服务（CrudWriter）的行为测试。
 *
 * 抽核心之前，落盘逻辑在 `CrudCommand::write()` 里，签名是
 * `write(array $plan, Input $input, Output $output): int` —— 它**自己打印、自己决定退出码**，
 * 因此无法在不启动 console 的情况下断言「写了哪些文件 / 是否跳过 / 是否致命」。
 *
 * 现在 `write()` 返回结构化结果，本测试直接断言这些结果，
 * 用临时项目根目录做真实落盘（不触碰真实 app/ 与 public/）。
 */
class CrudWriterTest extends CrudTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_root_' . uniqid()
            . DIRECTORY_SEPARATOR;

        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);

        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function writer(?string $stubDir = null): CrudWriter
    {
        return new CrudWriter(new CrudGenerator($stubDir), $this->root);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $plugin = ''): array
    {
        $resolver = new FieldTypeResolver($this->rules());
        $fields   = $resolver->resolveAll([
            $this->column('id', 'bigint', 'bigint(20)', 'ID', false, null, true),
            $this->column('title', 'varchar', 'varchar(100)', '标题'),
            $this->column('create_time', 'datetime', 'datetime', '创建时间'),
        ], 'ws_article');

        return [
            'table'          => 'ws_article',
            'table_short'    => 'article',
            'class'          => 'Article',
            'title'          => '文章',
            'module'         => 'admin',
            'plugin'         => $plugin,
            'controller_url' => 'article',
            'soft_delete'    => CrudPlanBuilder::hasSoftDelete($fields),
            'generator'      => [
                'menu_plugin'    => 'system',
                'controller_dir' => 'app/admin/controller',
                'model_dir'      => 'app/admin/model',
                'migration_dir'  => 'database/migrations',
                'js_dir'         => 'public/statics/admin',
            ],
            'fields'         => $fields,
        ];
    }

    private function readFile(string $relative): string
    {
        return (string) file_get_contents($this->root . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    // ──────────────────────────────────────────────
    //  落盘与覆盖策略
    // ──────────────────────────────────────────────

    public function testWriteCreatesAllFrameworkArtifacts(): void
    {
        $result = $this->writer()->write($this->plan());

        $this->assertFalse($result['fatal']);
        $this->assertSame([], $result['required_missing']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([
            'app/admin/controller/Article.php',
            'app/admin/model/Article.php',
            'public/statics/admin/article.js',
        ], array_slice($result['written'], 0, 3));

        // 迁移文件名含 {timestamp}：用模式匹配断言，避免「断言与生成跨秒」造成的偶发失败
        $this->assertMatchesRegularExpression(
            '#^database/migrations/\d{14}_menu_article\.php$#',
            (string) ($result['written'][3] ?? '')
        );

        $this->assertStringContainsString('class Article extends BaseController', $this->readFile($result['written'][0]));
    }

    public function testSecondWriteSkipsExistingFilesUnlessForced(): void
    {
        $writer = $this->writer();
        $writer->write($this->plan());

        $second = $writer->write($this->plan());
        $this->assertSame([], $second['written'], '默认不覆盖已存在文件');
        $this->assertCount(4, $second['skipped']);

        $forced = $writer->write($this->plan(), ['force' => true]);
        $this->assertCount(4, $forced['written']);
        $this->assertSame([], $forced['skipped']);
    }

    public function testSkipOptionOmitsArtifact(): void
    {
        // 命令行 --no-js 翻译成 skip 列表；服务层只认数组，不认 Input
        $result = $this->writer()->write($this->plan(), ['skip' => ['no-js', 'no-menu']]);

        $this->assertSame([
            'app/admin/controller/Article.php',
            'app/admin/model/Article.php',
        ], $result['written']);
    }

    public function testPluginModeWritesIntoPluginTreeWithoutMenuMigration(): void
    {
        $result = $this->writer()->write($this->plan('mycms'));

        $this->assertSame([
            'plugins/mycms/controller/Article.php',
            'plugins/mycms/model/Article.php',
            'plugins/mycms/statics/js/article.js',
        ], $result['written']);

        $this->assertStringContainsString('namespace plugin\mycms\controller;', $this->readFile($result['written'][0]));
    }

    // ──────────────────────────────────────────────
    //  模板缺失的降级行为
    // ──────────────────────────────────────────────

    public function testMissingStubsAreReportedInsteadOfSilentlySkipped(): void
    {
        // 模板目录不存在 → 一个文件都产不出，必须显式标记 fatal，
        // 而不是「静默成功、什么都没生成」
        $writer = $this->writer(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_no_such_stubs_'
            . uniqid() . DIRECTORY_SEPARATOR);

        $result = $writer->write($this->plan());

        $this->assertTrue($result['fatal']);
        $this->assertSame([], $result['written']);
        $this->assertSame(['Controller.stub', 'Model.stub', 'js.stub', 'menu.stub'], $result['missing']);
        // 必需产物缺模板 → 上层应报错
        $this->assertSame(['Controller.stub', 'Model.stub', 'js.stub'], $result['required_missing']);
    }

    // ──────────────────────────────────────────────
    //  manifest 合并的接线
    // ──────────────────────────────────────────────

    public function testFrameworkModeIgnoresMergeManifestRequest(): void
    {
        $result = $this->writer()->write($this->plan(), ['merge_manifest' => true]);

        $this->assertTrue($result['merge_ignored'], '框架级应提示该选项被忽略');
        $this->assertNull($result['merge']);
    }

    public function testPluginModeRunsMergeAndFailureDoesNotFailWholeWrite(): void
    {
        // manifest.json 不存在 → 合并失败，但产物已经写好，
        // 因此整次 write 不算失败（fatal=false、文件仍在），只是 merge.ok=false
        $result = $this->writer()->write($this->plan('mycms'), ['merge_manifest' => true]);

        $this->assertFalse($result['merge_ignored']);
        $this->assertFalse($result['fatal']);
        $this->assertCount(3, $result['written']);
        $this->assertIsArray($result['merge']);
        $this->assertFalse($result['merge']['ok']);
        $this->assertStringContainsString('未找到 manifest.json', $result['merge']['message']);
    }

    public function testFatalWriteDoesNotAttemptMerge(): void
    {
        // 产物都写不出来时不该去动用户的 manifest.json
        $writer = $this->writer(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_no_such_stubs_'
            . uniqid() . DIRECTORY_SEPARATOR);

        $result = $writer->write($this->plan('mycms'), ['merge_manifest' => true]);

        $this->assertTrue($result['fatal']);
        $this->assertNull($result['merge']);
    }
}
