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


    public function testMissingStubsAreReportedInsteadOfSilentlySkipped(): void
    {
        $writer = $this->writer(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_no_such_stubs_'
            . uniqid() . DIRECTORY_SEPARATOR);

        $result = $writer->write($this->plan());

        $this->assertTrue($result['fatal']);
        $this->assertSame([], $result['written']);
        $this->assertSame(['Controller.stub', 'Model.stub', 'js.stub', 'menu.stub'], $result['missing']);
        $this->assertSame(['Controller.stub', 'Model.stub', 'js.stub'], $result['required_missing']);
    }


    public function testFrameworkModeIgnoresMergeManifestRequest(): void
    {
        $result = $this->writer()->write($this->plan(), ['merge_manifest' => true]);

        $this->assertTrue($result['merge_ignored'], '框架级应提示该选项被忽略');
        $this->assertNull($result['merge']);
    }

    public function testPluginModeRunsMergeAndFailureDoesNotFailWholeWrite(): void
    {
        $result = $this->writer()->write($this->plan('mycms'), ['merge_manifest' => true]);

        $this->assertFalse($result['merge_ignored']);
        $this->assertFalse($result['fatal']);
        $this->assertCount(3, $result['written']);
        $this->assertIsArray($result['merge']);
        $this->assertFalse($result['merge']['ok']);
        $this->assertStringContainsString('未找到 manifest.json', $result['merge']['message']);
    }


    /**
     * @return array<int, string>
     */
    private function menuMigrations(): array
    {
        return glob($this->root . 'database' . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '*_menu_article.php') ?: [];
    }

    public function testSecondRunDoesNotCreateAnotherMenuMigration(): void
    {
        $this->writer()->write($this->plan());

        $this->assertCount(1, $this->menuMigrations());

        $second = $this->writer()->write($this->plan());

        $this->assertSame([], $second['written'], '第二次不应再写任何文件');
        $this->assertCount(4, $second['skipped']);
        $this->assertCount(1, $this->menuMigrations(), '文件名含时间戳的迁移必须按正则复用，不能新增');
    }

    public function testForceRewritesMenuMigrationInPlace(): void
    {
        $this->writer()->write($this->plan());

        $before = basename($this->menuMigrations()[0]);

        $result = $this->writer()->write($this->plan(), ['force' => true]);

        $after = $this->menuMigrations();

        $this->assertCount(1, $after, '--force 不应产生新的时间戳文件');
        $this->assertSame($before, basename($after[0]), '--force 应原地覆盖已有迁移');
        $this->assertContains(
            'database/migrations/' . $before,
            $result['written'],
            '覆盖已有迁移时，written 应报告它的真实路径'
        );
    }

    public function testDuplicatedMenuMigrationsAreReported(): void
    {
        $this->writer()->write($this->plan());

        $dir = $this->root . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;
        copy($dir . basename($this->menuMigrations()[0]), $dir . '20200101000000_menu_article.php');

        $result = $this->writer()->write($this->plan());

        $this->assertNotEmpty($result['duplicates'], '重复的菜单迁移应被报告');
        $this->assertCount(2, $result['duplicates'][0]['files']);
    }


    public function testForceWithBackupKeepsPreviousContent(): void
    {
        $this->writer()->write($this->plan());

        $original = $this->readFile('app/admin/controller/Article.php');

        $result = $this->writer()->write($this->plan(), ['force' => true, 'backup' => true]);

        $this->assertNotSame([], $result['backups'], '--force + --backup 应产生备份');

        $backup = $this->root . str_replace('/', DIRECTORY_SEPARATOR, $result['backups'][0]);

        $this->assertFileExists($backup);
        $this->assertSame($original, (string) file_get_contents($backup), '备份必须是覆盖前的原文');
    }

    public function testForceWithoutBackupWritesNoBackup(): void
    {
        $this->writer()->write($this->plan());

        $result = $this->writer()->write($this->plan(), ['force' => true]);

        $this->assertSame([], $result['backups']);
        $this->assertDirectoryDoesNotExist($this->root . 'runtime' . DIRECTORY_SEPARATOR . 'crud-backup');
    }

    public function testNoBackupWhenNothingIsOverwritten(): void
    {
        $result = $this->writer()->write($this->plan(), ['backup' => true]);

        $this->assertSame([], $result['backups']);
    }


    public function testFreshWriteHasNoDrift(): void
    {
        $result = $this->writer()->write($this->plan());

        $this->assertSame([], $result['drift'], '刚生成完的产物不应有偏差');
    }

    public function testDriftDetectsFieldAddedToTableButNotRegenerated(): void
    {
        $this->writer()->write($this->plan());

        $plan              = $this->plan();
        $plan['fields'][]  = (new FieldTypeResolver($this->rules()))
            ->resolve($this->column('remark', 'varchar', 'varchar(50)', '备注'), 'ws_article');

        $result = $this->writer()->write($plan);

        $this->assertNotSame([], $result['drift'], '新增列后应检出产物落后');

        $byKind = [];

        foreach ($result['drift'] as $item) {
            $byKind[$item['kind']] = $item['missing'];
        }

        $this->assertContains('remark', $byKind['controller'] ?? [], '控制器 defineForm 缺新字段');
        $this->assertContains('remark', $byKind['model'] ?? [], '模型白名单缺新字段（提交会被静默丢弃）');
        $this->assertContains('remark', $byKind['js'] ?? [], '列表列配置缺新字段');
    }

    public function testDriftReportsExtraFieldsWithoutFailing(): void
    {
        $this->writer()->write($this->plan());

        $path    = $this->root . 'app' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controller'
            . DIRECTORY_SEPARATOR . 'Article.php';
        $content = (string) file_get_contents($path);
        $content = str_replace(
            "\$form->add(Text::make('title', '标题')",
            "\$form->add(Text::make('custom_note', '自定义'));\n        \$form->add(Text::make('title', '标题')",
            $content
        );
        file_put_contents($path, $content);

        $result = $this->writer()->write($this->plan());

        $controller = null;

        foreach ($result['drift'] as $item) {
            if ($item['kind'] === 'controller') {
                $controller = $item;
            }
        }

        $this->assertNotNull($controller);
        $this->assertContains('custom_note', $controller['extra'], '手加字段应作为 extra 报告而非报错');
    }

    public function testFatalWriteDoesNotAttemptMerge(): void
    {
        $writer = $this->writer(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_no_such_stubs_'
            . uniqid() . DIRECTORY_SEPARATOR);

        $result = $writer->write($this->plan('mycms'), ['merge_manifest' => true]);

        $this->assertTrue($result['fatal']);
        $this->assertNull($result['merge']);
    }
}
