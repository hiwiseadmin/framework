<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CrudGenerator;
use wise\crud\CrudPlanBuilder;
use wise\crud\CrudWriter;
use wise\crud\FieldTypeResolver;

/**
 * 产物模板渲染集成测试（框架级 / 插件级双模式）。
 *
 * 目的是锁住**最容易静默出错**的四类问题：
 *   1. stub 里写了占位符，但 variables() 没提供 → 生成物残留 `{$xxx}`
 *   2. 渲染出的 PHP 语法不合法 → 生成即报错，且错误现场已被写入文件
 *   3. 类型映射到不存在/拼错的字段类 → 生成物被加载时才 fatal
 *   4. 插件模式与框架模式串味（命名空间、基类、产物目录、菜单策略）
 *
 * 用**真实推导引擎**的输出构造 plan，因此同时覆盖了「引擎 → 变量 → 模板」整条链路，
 * 并且经由 CrudGenerator::render() 渲染，故 CRLF 归一化等行为也在测试范围内。
 *
 * 这些方法在抽核心后已成为 CrudPlanBuilder / CrudGenerator / CrudWriter 的**公开 API**，
 * 因此本测试不再需要反射 —— 这本身就是「核心已可被 Web 侧复用」的证据。
 */
class StubRenderingTest extends CrudTestCase
{
    private ?CrudPlanBuilder $planner = null;
    private ?CrudGenerator $generator = null;
    private ?CrudWriter $writer = null;

    /** @var array<string, array<string, string>> 按插件标识缓存 */
    private array $variablesCache = [];

    private function planner(): CrudPlanBuilder
    {
        return $this->planner ??= new CrudPlanBuilder();
    }

    private function generator(): CrudGenerator
    {
        return $this->generator ??= new CrudGenerator();
    }

    private function writer(): CrudWriter
    {
        return $this->writer ??= new CrudWriter($this->generator());
    }

    /**
     * 覆盖各类判定的字段集（用于构造 plan）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function columns(): array
    {
        return [
            $this->column('id', 'bigint', 'bigint(20)', 'ID', false, null, true),
            $this->column('title', 'varchar', 'varchar(100)', '标题'),
            $this->column('status', 'tinyint', 'tinyint(4)', '状态 0:禁用,1:启用', false, '1'),
            $this->column('is_top', 'tinyint', 'tinyint(1)', '是否置顶', false, '0'),
            $this->column('parent_id', 'int', 'int(11)', '父级分类', false, '0'),
            $this->column('cover_id', 'int', 'int(11)', '封面图', true, '0'),
            $this->column('img_ids', 'json', 'json', '图集', true),
            $this->column('content', 'text', 'text', '内容'),
            $this->column('create_time', 'datetime', 'datetime', '创建时间'),
            $this->column('delete_time', 'datetime', 'datetime', '删除时间', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $plugin = ''): array
    {
        $resolver = new FieldTypeResolver($this->rules());
        $fields   = $resolver->resolveAll($this->columns(), 'ws_article');

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

    /**
     * @return array<string, string>
     */
    private function variables(string $plugin = ''): array
    {
        return $this->variablesCache[$plugin]
            ??= $this->generator()->variables($this->plan($plugin));
    }

    private function stubPath(string $name): string
    {
        return dirname(__DIR__) . '/stubs/' . $name;
    }

    private function render(string $stub, string $plugin = ''): string
    {
        return (string) $this->generator()->render($stub, $this->variables($plugin));
    }

    /**
     * @return array<int, string>
     */
    private function stubNames(): array
    {
        $names = [];

        foreach (glob($this->stubPath('*')) ?: [] as $file) {
            $names[] = basename($file);
        }

        sort($names);

        return $names;
    }


    public function testAllStubsExist(): void
    {
        $this->assertSame(
            ['Controller.stub', 'Model.stub', 'js.stub', 'menu.stub'],
            $this->stubNames()
        );
    }

    /**
     * @dataProvider modeProvider
     */
    public function testEveryStubPlaceholderIsProvided(string $plugin): void
    {
        $provided = array_keys($this->variables($plugin));

        foreach ($this->stubNames() as $name) {
            $content = (string) file_get_contents($this->stubPath($name));

            preg_match_all('/\{\$[a-z_]+\}/', $content, $matches);

            $missing = array_diff(array_unique($matches[0] ?? []), $provided);

            $this->assertSame(
                [],
                array_values($missing),
                sprintf('%s 存在 variables() 未提供的占位符', $name)
            );
        }
    }

    /**
     * @dataProvider modeProvider
     */
    public function testRenderingLeavesNoPlaceholderNorCrlf(string $plugin): void
    {
        foreach ($this->stubNames() as $name) {
            $rendered = $this->render($name, $plugin);

            $this->assertNotSame('', $rendered, sprintf('%s 渲染结果为空（模板是否缺失？）', $name));
            $this->assertStringNotContainsString('{$', $rendered, sprintf('%s 渲染后残留占位符', $name));
            $this->assertStringNotContainsString("\r", $rendered, sprintf('%s 含 CR，换行未归一化', $name));
        }
    }


    /**
     * @dataProvider phpStubProvider
     */
    public function testRenderedPhpStubHasValidSyntax(string $stub, string $plugin): void
    {
        $rendered = $this->render($stub, $plugin);
        $temp     = tempnam(sys_get_temp_dir(), 'wisecrud_') . '.php';

        file_put_contents($temp, $rendered);

        try {
            $output = [];
            $code   = 0;
            exec(sprintf('php -l %s 2>&1', escapeshellarg($temp)), $output, $code);

            $this->assertSame(
                0,
                $code,
                sprintf("%s（plugin=%s）语法错误：\n%s\n---\n%s", $stub, $plugin, implode("\n", $output), $rendered)
            );
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phpStubProvider(): array
    {
        $cases = [];

        foreach (['Controller.stub', 'Model.stub', 'menu.stub'] as $stub) {
            $cases[$stub . '|host']   = [$stub, ''];
            $cases[$stub . '|plugin'] = [$stub, 'mycms'];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modeProvider(): array
    {
        return [
            '框架级' => [''],
            '插件级' => ['mycms'],
        ];
    }


    public function testControllerDeclaresFormAndModelDependency(): void
    {
        $rendered = $this->render('Controller.stub');

        $this->assertStringContainsString('namespace app\admin\controller;', $rendered);
        $this->assertStringNotContainsString('use app\admin\controller\BaseController;', $rendered);
        $this->assertStringContainsString('class Article extends BaseController', $rendered);
        $this->assertStringContainsString('use CrudTrait;', $rendered);
        $this->assertStringContainsString('use CrudSchemaTrait;', $rendered);
        $this->assertStringContainsString('use app\admin\model\Article as ArticleModel;', $rendered);
        $this->assertStringContainsString('protected function defineForm(Form $form): void', $rendered);
        $this->assertStringContainsString('return new ArticleModel();', $rendered);
        $this->assertStringContainsString("return \$this->renderCrudIndex('文章');", $rendered);
        $this->assertStringContainsString("protected \$plugin = 'system';", $rendered);

        $this->assertStringContainsString('public function getList()', $rendered);
        $this->assertStringContainsString('return $this->_getList();', $rendered);
        $this->assertStringContainsString('public function save()', $rendered);
        $this->assertStringContainsString('return $this->_save();', $rendered);
        $this->assertStringContainsString('public function delete()', $rendered);
        $this->assertStringContainsString('return $this->_delete();', $rendered);

        $this->assertStringNotContainsString("\n    public function forceDelete()", $rendered);
        $this->assertStringContainsString('// public function forceDelete()', $rendered);
    }

    /**
     * 含 delete_time 的表：控制器级回收站动作必须开出来（动作名对齐 admin/Menu）
     */
    public function testSoftDeleteTableExposesControllerLevelRecycle(): void
    {
        $rendered = $this->render('Controller.stub');

        foreach (['restore', 'recycleList', 'recycleToken', 'recycleRestore'] as $action) {
            $this->assertStringContainsString(
                "\n    public function {$action}()",
                $rendered,
                "软删表缺少控制器级回收站动作 {$action}()"
            );
        }

        $this->assertStringContainsString('return $this->_recycleList();', $rendered);
        $this->assertStringContainsString('return $this->_recycleToken();', $rendered);

        foreach (['forceDelete', 'recycleForceDelete'] as $action) {
            $this->assertStringNotContainsString(
                "\n    public function {$action}()",
                $rendered,
                "彻底删除 {$action}() 不应默认开放"
            );
            $this->assertStringContainsString("// public function {$action}()", $rendered);
        }
    }

    /**
     * 无 delete_time 的表：不应出现任何回收站动作
     */
    public function testNonSoftDeleteTableHasNoRecycleActions(): void
    {
        $plan = $this->plan();
        $plan['fields'] = array_values(array_filter(
            $plan['fields'],
            static fn (array $field): bool => $field['name'] !== 'delete_time'
        ));
        $plan['soft_delete'] = false;

        $rendered = (string) $this->generator()->render('Controller.stub', $this->generator()->variables($plan));

        foreach (['restore', 'recycleList', 'recycleToken', 'recycleRestore', 'recycleForceDelete', 'forceDelete'] as $action) {
            $this->assertStringNotContainsString(
                $action,
                $rendered,
                "非软删表不应出现回收站动作 {$action}"
            );
        }
    }

    /**
     * 服务端导出入口必须生成：_export() 在 CrudTrait 里，但不生成包装等于没有端点
     */
    public function testControllerExposesServerSideExport(): void
    {
        $rendered = $this->render('Controller.stub');

        $this->assertStringContainsString("\n    public function export()", $rendered);
        $this->assertStringContainsString('return $this->_export();', $rendered);
    }

    /**
     * 搜索区：按列推导声明，且只声明不写查询（参数层由 RequestQueryBuilder 解析）
     */
    public function testControllerDeclaresSearchSection(): void
    {
        $rendered = $this->render('Controller.stub');

        $this->assertStringContainsString('use app\common\library\crud\Search;', $rendered);
        $this->assertStringContainsString('use app\common\library\crud\SearchField;', $rendered);
        $this->assertStringContainsString('protected function defineSearch(Search $search): void', $rendered);

        $this->assertStringContainsString("SearchField::make('title', 'text')", $rendered);
        $this->assertStringContainsString("SearchField::make('status', 'select')", $rendered);
        $this->assertStringContainsString("SearchField::make('create_time', 'date')", $rendered);
        $this->assertStringContainsString('->range()', $rendered);

        $this->assertStringNotContainsString("SearchField::make('content'", $rendered);
        $this->assertStringNotContainsString("SearchField::make('id'", $rendered);
        $this->assertStringNotContainsString("SearchField::make('delete_time'", $rendered);

        $this->assertStringContainsString('$search->defaultVisible(false);', $rendered);

        $this->assertStringNotContainsString('function buildSearchQuery', $rendered);
    }

    /**
     * --no-search：不产出 defineSearch 与相关 import
     */
    public function testSearchSectionCanBeDisabled(): void
    {
        $plan = $this->plan();
        $plan['search'] = false;

        $rendered = (string) $this->generator()->render('Controller.stub', $this->generator()->variables($plan));

        $this->assertStringNotContainsString('defineSearch', $rendered);
        $this->assertStringNotContainsString('SearchField::make', $rendered);
    }

    /**
     * 校验规则：varchar 长度上限来自列定义
     */
    public function testFormLinesCarryColumnDerivedRules(): void
    {
        $rendered = $this->render('Controller.stub');

        $this->assertStringContainsString("->rules('max:100')", $rendered);
    }

    public function testFormLinesCarryExplainabilityComments(): void
    {
        $rendered = $this->render('Controller.stub');

        $this->assertStringContainsString('// status: tinyint(4) · P1(', $rendered);
        $this->assertStringContainsString('// is_top: tinyint(1) · P2(', $rendered);
        $this->assertStringContainsString('备选 number', $rendered);

        $this->assertStringNotContainsString('create_time::make', $rendered);
        $this->assertStringNotContainsString('delete_time::make', $rendered);
        $this->assertStringNotContainsString("->add(Text::make('id'", $rendered);
    }

    public function testRelationFieldGetsTodoInsteadOfGuess(): void
    {
        $rendered = $this->render('Controller.stub');

        $this->assertStringContainsString('->options([]) /* TODO', $rendered);
    }

    public function testOnlyUsedFieldClassesAreImported(): void
    {
        $rendered = $this->render('Controller.stub');

        preg_match('/use wise\\\\form\\\\schema\\\\field\\\\\{([^}]*)\};/', $rendered, $matches);

        $this->assertNotEmpty($matches[1] ?? '', '未生成字段类 import');

        foreach (array_map('trim', explode(',', $matches[1])) as $class) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($class, '/') . '::make\(/',
                $rendered,
                sprintf('import 了未使用的字段类 %s', $class)
            );
        }
    }

    public function testModelDeclaresWhitelistSoftDeleteAndJson(): void
    {
        $rendered = $this->render('Model.stub');

        $this->assertStringContainsString('class Article extends BaseModel', $rendered);
        $this->assertStringContainsString("namespace app\admin\model;\n\nuse app\common\model\BaseModel;\nuse think\model\concern\SoftDelete;", $rendered);
        $this->assertStringContainsString("protected \$name = 'article';", $rendered);
        $this->assertStringContainsString('use SoftDelete;', $rendered);
        $this->assertStringContainsString("protected \$json = ['img_ids'];", $rendered);
        $this->assertStringContainsString("'title'", $rendered);
        $this->assertStringNotContainsString("'create_time',", $rendered);
        $this->assertStringNotContainsString("'delete_time'", $rendered);
    }

    public function testMenuStubUsesIdempotentApi(): void
    {
        $rendered = $this->render('menu.stub');

        $this->assertStringContainsString('class MenuArticle extends Migrator', $rendered);
        $this->assertStringContainsString('Menu::createMenusWithPermissions(', $rendered);
        $this->assertStringContainsString("'/admin/article/index'", $rendered);
        $this->assertStringNotContainsString('insert(', $rendered);
    }

    /**
     * 宿主模块的菜单迁移是框架既有约定（见方桼�，它必须：
     *   1. 以「保留已存在菜单字段」的方式注册（否则会冲掉运营/手工排版）；
     *   2. 回滚时**软删除**（与插件 / 库包卸载同一语义），而不是物理删。
     */
    public function testMenuStubPreservesExistingAndSoftDeletesOnRollback(): void
    {
        $rendered = $this->render('menu.stub');

        $this->assertStringContainsString('preserveExisting: true', $rendered);

        $this->assertStringContainsString('$menu->delete();', $rendered);
        $this->assertStringNotContainsString("Db::name('menus')", $rendered);
        $this->assertStringNotContainsString('force()', $rendered);
    }

    public function testJsStubRegistersListAndForm(): void
    {
        $rendered = $this->render('js.stub');

        $this->assertStringContainsString('export default Controller;', $rendered);
        $this->assertStringContainsString("const API_BASE = '/admin/article';", $rendered);
        $this->assertStringContainsString("url: API_BASE + '/getList'", $rendered);
        $this->assertStringContainsString("new FormValidator('wsForm'", $rendered);
        $this->assertStringContainsString("field: 'ck'", $rendered);
        $this->assertStringContainsString("field: 'operate'", $rendered);
    }


    public function testPluginControllerSwitchesNamespaceAndBaseClass(): void
    {
        $rendered = $this->render('Controller.stub', 'mycms');

        $this->assertStringContainsString('namespace plugin\mycms\controller;', $rendered);
        $this->assertStringContainsString('use plugin\BaseController;', $rendered);
        $this->assertStringContainsString('use plugin\mycms\model\Article as ArticleModel;', $rendered);
        $this->assertStringContainsString("namespace plugin\mycms\controller;\n\nuse plugin\BaseController;\n", $rendered);
        $this->assertStringNotContainsString('protected $plugin', $rendered);
    }

    public function testPluginModelUsesPluginBaseModel(): void
    {
        $rendered = $this->render('Model.stub', 'mycms');

        $this->assertStringContainsString('namespace plugin\mycms\model;', $rendered);
        $this->assertStringContainsString('use plugin\BaseModel;', $rendered);
        $this->assertStringNotContainsString('app\common\model\BaseModel', $rendered);
        $this->assertStringContainsString("namespace plugin\mycms\model;\n\nuse plugin\BaseModel;\nuse think\model\concern\SoftDelete;\n", $rendered);
    }

    public function testJsStubInPluginModeDoesNotUseAdminUrl(): void
    {
        $rendered = $this->render('js.stub', 'mycms');

        $this->assertStringContainsString("const API_BASE = '/plugin/mycms/article';", $rendered);
        $this->assertStringNotContainsString("'/admin/", $rendered);
        $this->assertStringNotContainsString('"/admin/', $rendered);
    }

    public function testPluginArtifactsGoIntoPluginTreeWithoutMenuMigration(): void
    {
        $paths = array_column($this->writer()->artifactsFor($this->plan('mycms')), 'path');

        $this->assertContains('plugins/{$plugin}/controller/{$class}.php', $paths);
        $this->assertContains('plugins/{$plugin}/model/{$class}.php', $paths);
        $this->assertContains('plugins/{$plugin}/statics/js/{$controller_url}.js', $paths);
        $this->assertStringNotContainsString('migration', implode(' ', $paths));
    }

    public function testHostArtifactsKeepMenuMigration(): void
    {
        $paths = array_column($this->writer()->artifactsFor($this->plan()), 'path');

        $this->assertCount(4, $paths);
        $this->assertStringContainsString('{$migration_dir}/{$timestamp}_menu_', implode(' ', $paths));
    }

    public function testManifestMenuSnippetUsesPluginRoute(): void
    {
        $snippet = $this->writer()->manifestMenuSnippet($this->plan('mycms'));

        $this->assertStringContainsString('"/plugin/mycms/article/index"', $snippet);
        $this->assertStringContainsString('"name": "文章"', $snippet);
        $this->assertStringContainsString('"children": []', $snippet);
    }


    public function testValidPluginIdentIsNormalized(): void
    {
        $this->assertSame('mycms', $this->planner()->normalizePlugin('mycms'));
        $this->assertSame('my_cms', $this->planner()->normalizePlugin('  my_cms  '));
        $this->assertSame('', $this->planner()->normalizePlugin(''));
    }

    /**
     * @dataProvider invalidPluginProvider
     */
    public function testInvalidPluginIdentIsRejected(string $ident): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->planner()->normalizePlugin($ident);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPluginProvider(): array
    {
        return [
            '大写'     => ['MyCMS'],
            '连字符'   => ['my-cms'],
            '数字开头' => ['3cms'],
            '含反斜杠' => ['my\\cms'],
            '含点'     => ['my.cms'],
        ];
    }


    /**
     * 推导出的每种表单类型，都必须能映射到**真实存在**的字段类。
     *
     * 这类错误（类名拼错 / 映射到未实现的类型）语法检查查不出来，
     * 只会在生成物被加载时才 fatal。
     */
    public function testEveryMappedFieldClassExists(): void
    {
        $types = [
            'text', 'textarea', 'editor', 'number', 'select', 'radio', 'checkbox',
            'switch', 'datetime', 'date', 'time', 'color', 'email', 'password',
            'hidden', 'image', 'file', 'avatar',
        ];

        foreach ($types as $type) {
            $class = $this->generator()->fieldClass($type);
            $fqcn  = 'wise\\form\\schema\\field\\' . $class;

            $this->assertTrue(
                class_exists($fqcn),
                sprintf('类型 %s 映射到 %s，该类不存在', $type, $fqcn)
            );
        }
    }
}
