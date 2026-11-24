<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudGenerator;
use wise\crud\CrudPlanBuilder;
use wise\crud\CrudWriter;
use wise\crud\FieldTypeResolver;
use wise\crud\RecycleRegistrar;

/**
 * 软删模型 → 宿主回收站清单（config/recycle.php）的注册行为。
 */
class RecycleRegistrarTest extends CrudTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecrud_recycle_' . bin2hex(random_bytes(4))
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

    private function registrar(): RecycleRegistrar
    {
        return new RecycleRegistrar($this->root);
    }

    private function configPath(): string
    {
        return $this->root . 'config' . DIRECTORY_SEPARATOR . 'recycle.php';
    }

    public function testCreatesConfigWhenMissing(): void
    {
        $result = $this->registrar()->register('app\admin\model\Article', 'title');

        $this->assertTrue($result['ok']);
        $this->assertSame('written', $result['status']);

        $content = (string) file_get_contents($this->configPath());

        $this->assertStringContainsString('@wise:generated', $content);
        $this->assertStringContainsString('\app\admin\model\Article::class', $content);
        $this->assertStringContainsString("'title' => 'title'", $content);

        $data = include $this->configPath();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('app\admin\model\Article', $data['models']);
    }

    public function testRegisterIsIdempotent(): void
    {
        $this->registrar()->register('app\admin\model\Article', 'title');
        $first = (string) file_get_contents($this->configPath());

        $second = $this->registrar()->register('app\admin\model\Article', 'title');

        $this->assertSame('unchanged', $second['status']);
        $this->assertSame($first, (string) file_get_contents($this->configPath()), '重复注册不得改动文件');
        $this->assertSame(1, substr_count($first, 'Article::class'));
    }

    public function testPreservesExistingEntriesAndPrologue(): void
    {
        mkdir($this->root . 'config', 0755, true);
        file_put_contents($this->configPath(), <<<'PHP'
<?php

/**
 * 手写的说明文字，不能被生成器冲掉
 */

return [
    'models' => [
        \app\admin\model\Menu::class => [
            'title' => 'name',
            'group' => '系统',
        ],
    ],
];
PHP
        );

        $result = $this->registrar()->register('app\admin\model\Article', 'title');

        $this->assertSame('written', $result['status']);
        $content = (string) file_get_contents($this->configPath());

        $this->assertStringContainsString('手写的说明文字，不能被生成器冲掉', $content);
        $this->assertStringContainsString('\app\admin\model\Menu::class', $content);
        $this->assertStringContainsString("'group' => '系统'", $content);
        $this->assertStringContainsString('\app\admin\model\Article::class', $content);

        $data = include $this->configPath();
        $this->assertCount(2, $data['models']);
    }

    public function testTitleFieldCanBeOmitted(): void
    {
        $this->registrar()->register('app\admin\model\Log');

        $data = include $this->configPath();

        $this->assertSame([], $data['models']['app\admin\model\Log'], '无标题字段时不写 title');
    }


    private function writer(): CrudWriter
    {
        return new CrudWriter(new CrudGenerator(), $this->root);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $plugin = '', bool $softDelete = true): array
    {
        $columns = [
            $this->column('id', 'bigint', 'bigint(20)', 'ID', false, null, true),
            $this->column('title', 'varchar', 'varchar(100)', '标题'),
        ];

        if ($softDelete) {
            $columns[] = $this->column('delete_time', 'datetime', 'datetime', '删除时间', true);
        }

        $fields = (new FieldTypeResolver($this->rules()))->resolveAll($columns, 'ws_article');

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

    public function testWriterRegistersSoftDeleteModel(): void
    {
        $result = $this->writer()->write($this->plan());

        $this->assertIsArray($result['recycle']);
        $this->assertSame('written', $result['recycle']['status']);
        $this->assertFileExists($this->configPath());
    }

    public function testWriterSkipsRegistrationForNonSoftDeleteTable(): void
    {
        $result = $this->writer()->write($this->plan('', false));

        $this->assertNull($result['recycle'], '无 delete_time 的表不注册回收站');
        $this->assertFileDoesNotExist($this->configPath());
    }

    public function testWriterSkipsRegistrationInPluginMode(): void
    {
        $result = $this->writer()->write($this->plan('mycms'));

        $this->assertNull($result['recycle']);
        $this->assertFileDoesNotExist($this->configPath());
    }

    public function testWriterHonoursNoRecycleSkipOption(): void
    {
        $result = $this->writer()->write($this->plan(), ['skip' => ['no-recycle']]);

        $this->assertNull($result['recycle']);
        $this->assertFileDoesNotExist($this->configPath());
    }
}
