<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\plugin\contract\ContractCaseSet;
use wise\plugin\contract\ContractRunner;

/**
 * 契约检查执行器测试（`plugin:test` 的 unit 引擎）。
 *
 * 这套用例守的是「预检一致率」（方桼�：本地绿、服务端红的每一例，
 * 都会让作者在上传后被打回。所以正例（合规包必须全绿）与反例（每种坏写法必须命中）
 * 都要有 —— 只测正例等于没测。
 */
class ContractRunnerTest extends TestCase
{
    private string $root = '';

    private array $caseset = [];

    protected function setUp(): void
    {
        $this->root    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecontract_' . uniqid();
        $this->caseset = ContractCaseSet::load();

        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testBuiltinCasesetCoversAllEngineChecks(): void
    {
        $this->assertSame('bundled-2026.11.1', $this->caseset['version']);
        $this->assertFalse($this->caseset['mismatch']);

        $run = $this->inspect($this->makePlugin('demo'));

        $this->assertSame([], $run['skipped'], '内置用例集不应有引擎未实现的 case');
        $this->assertSame(count($this->caseset['cases']), $run['checks']);
    }

    public function testCompliantPluginIsClean(): void
    {
        $issues = $this->inspect($this->makePlugin('demo'))['issues'];

        $this->assertSame([], $issues, '合规插件不应报任何问题：' . $this->describe($issues));
    }

    public function testMissingManifestIsReported(): void
    {
        $dir = $this->root . '/demo';
        mkdir($dir, 0755, true);

        $rules = $this->rules($this->inspect($dir));

        $this->assertContains('contract.manifest_present', $rules);
    }

    public function testBrokenJsonIsReported(): void
    {
        $dir = $this->makePlugin('demo');
        file_put_contents($dir . '/manifest.json', '{ broken');

        $this->assertContains('contract.manifest_parse', $this->rules($this->inspect($dir)));
    }

    public function testSyntaxErrorIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'controller/Index.php' => "<?php\nnamespace plugin\\demo\\controller;\n\nclass Index {\n",
        ]);

        $this->assertContains('contract.php_syntax', $this->rules($this->inspect($dir)));
    }

    public function testWrongNamespacePrefixIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'controller/Index.php' => "<?php\nnamespace app\\controller;\n\nclass Index\n{\n}\n",
        ]);

        $this->assertContains('contract.namespace_prefix', $this->rules($this->inspect($dir)));
    }

    public function testMissingProviderFileIsReported(): void
    {
        $dir = $this->makePlugin('demo', [], false);

        $this->assertContains('contract.provider_file', $this->rules($this->inspect($dir)));
    }

    public function testInvalidMenuShapeIsReported(): void
    {
        $dir = $this->makePlugin('demo', [], true, [
            'menus' => [
                ['name' => '示例', 'path' => 'plugin/demo/index', 'type' => 'unknown'],
            ],
        ]);

        $rules = $this->rules($this->inspect($dir));

        $this->assertContains('contract.menus_shape', $rules, 'path 未以 / 开头 + type 非法都要报');
    }

    public function testMenuPathOutsidePluginScopeIsWarnOnly(): void
    {
        $dir = $this->makePlugin('demo', [], true, [
            'menus' => [
                ['name' => '示例', 'path' => '/admin/dashboard/index', 'type' => 'menu'],
            ],
        ]);

        $run  = $this->inspect($dir);
        $hits = $this->byRule($run['issues'], 'contract.menus_path_scope');

        $this->assertNotNull($hits);
        $this->assertSame(AuditIssue::WARN, $hits->level, '启发式用例只提示，不阻断');
    }

    public function testDuplicatePermissionIdentIsReported(): void
    {
        $dir = $this->makePlugin('demo', [], true, [
            'permissions' => [
                ['name' => 'A', 'ident' => 'demo.index'],
                ['name' => 'B', 'ident' => 'demo.index'],
            ],
        ]);

        $this->assertContains('contract.permissions_shape', $this->rules($this->inspect($dir)));
    }

    public function testBadMigrationFilenameIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'database/migrations/create_demo_table.php' => "<?php\n\nclass CreateDemoTable\n{\n    public function up()\n    {\n    }\n}\n",
        ]);

        $rules = $this->rules($this->inspect($dir));

        $this->assertContains('contract.migration_filename', $rules);
        $this->assertNotContains('contract.migration_class', $rules, '命名已错时不再重复报类名');
    }

    public function testMigrationClassMismatchIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'database/migrations/20260101000000_create_demo_table.php' => "<?php\n\nclass OtherName\n{\n    public function up()\n    {\n    }\n}\n",
        ]);

        $this->assertContains('contract.migration_class', $this->rules($this->inspect($dir)));
    }

    public function testMigrationWithoutUpIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'database/migrations/20260101000000_create_demo_table.php' => "<?php\n\nclass CreateDemoTable\n{\n    public function down()\n    {\n    }\n}\n",
        ]);

        $this->assertContains('contract.migration_body', $this->rules($this->inspect($dir)));
    }

    public function testSeedClassMismatchIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'database/seeds/DemoSeeder.php' => "<?php\n\nclass WrongSeeder\n{\n}\n",
        ]);

        $this->assertContains('contract.seed_class', $this->rules($this->inspect($dir)));
    }

    public function testPublishTargetMustStayUnderPublic(): void
    {
        $dir = $this->makePlugin('demo', ['statics/app.css' => 'body{}'], true, [
            'publish' => ['statics' => 'statics/moved'],
        ]);

        $this->assertContains('contract.publish_map', $this->rules($this->inspect($dir)));
    }

    public function testMissingPublishSourceIsReported(): void
    {
        $dir = $this->makePlugin('demo', [], true, [
            'publish' => ['statics' => 'public/statics/plugins/demo'],
        ]);

        $this->assertContains('contract.publish_map', $this->rules($this->inspect($dir)));
    }

    public function testRouteToMissingControllerIsReported(): void
    {
        $dir = $this->makePlugin('demo', [
            'route/app.php' => "<?php\nuse think\\facade\\Route;\n\nRoute::get('demo', 'plugin\\\\demo\\\\controller\\\\Missing@index');\n",
        ]);

        $this->assertContains('contract.route_targets', $this->rules($this->inspect($dir)));
    }

    public function testRouteToExistingControllerIsClean(): void
    {
        $dir = $this->makePlugin('demo', [
            'controller/Index.php' => "<?php\nnamespace plugin\\demo\\controller;\n\nclass Index\n{\n    public function index()\n    {\n    }\n}\n",
            'route/app.php'        => "<?php\nuse think\\facade\\Route;\n\nRoute::get('demo', 'plugin\\\\demo\\\\controller\\\\Index@index');\n",
        ]);

        $this->assertNotContains('contract.route_targets', $this->rules($this->inspect($dir)));
    }

    public function testUnknownCaseIsSkippedNotFailed(): void
    {
        $cases = $this->caseset['cases'];
        $cases['contract.future_check'] = ['group' => 'structure', 'level' => 'error', 'title' => '服务端新增'];

        $run = (new ContractRunner($cases, 'demo'))->run(AuditPackage::open($this->makePlugin('demo')));

        $this->assertArrayHasKey('contract.future_check', $run['skipped']);
        $this->assertSame([], $run['issues'], '未知 case 不能把插件判成失败');
    }

    public function testCasesetMismatchProducesWarn(): void
    {
        $run = (new ContractRunner(
            $this->caseset['cases'],
            'demo',
            true,
            '2026.12.1',
            'bundled-2026.11.1'
        ))->run(AuditPackage::open($this->makePlugin('demo')));

        $hit = $this->byRule($run['issues'], 'contract.caseset_version');

        $this->assertNotNull($hit);
        $this->assertSame(AuditIssue::WARN, $hit->level);
    }


    /**
     * @return array{issues: array<int, AuditIssue>, checks: int, skipped: array<string, string>}
     */
    private function inspect(string $path): array
    {
        return (new ContractRunner($this->caseset['cases'], 'demo'))->run(AuditPackage::open($path));
    }

    /**
     * 既接受 `run()` 的返回结构，也接受裸 issues 数组。
     *
     * @param array<string, mixed>|array<int, AuditIssue> $run
     *
     * @return array<int, string>
     */
    private function rules(array $run): array
    {
        $issues = $run['issues'] ?? $run;

        return array_map(static fn (AuditIssue $issue): string => $issue->rule, $issues);
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    private function byRule(array $issues, string $rule): ?AuditIssue
    {
        foreach ($issues as $issue) {
            if ($issue->rule === $rule) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * @param array<int, AuditIssue> $issues
     */
    private function describe(array $issues): string
    {
        return implode(' | ', array_map(
            static fn (AuditIssue $issue): string => $issue->level . ':' . $issue->rule . ' ' . $issue->message,
            $issues
        ));
    }

    /**
     * 造一个契约合规的插件。
     *
     * @param array<string, string> $files            相对路径 => 内容
     * @param bool                  $withProvider     false 时故意不建 provider 文件
     * @param array<string, mixed>  $manifestOverride
     */
    private function makePlugin(
        string $ident,
        array $files = [],
        bool $withProvider = true,
        array $manifestOverride = []
    ): string {
        $dir = $this->root . '/' . $ident;
        mkdir($dir, 0755, true);

        $manifest = array_merge([
            'name'             => $ident,
            'version'          => '1.0.0',
            'title'            => '示例插件',
            'service_provider' => 'plugin\\' . $ident . '\\BootServiceProvider',
            'menus'            => [
                ['name' => '示例', 'path' => '/plugin/' . $ident . '/index', 'type' => 'menu'],
            ],
            'permissions'      => [
                ['name' => '查看', 'ident' => $ident . '.index'],
            ],
        ], $manifestOverride);

        file_put_contents(
            $dir . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($withProvider) {
            file_put_contents(
                $dir . '/BootServiceProvider.php',
                "<?php\nnamespace plugin\\{$ident};\n\nclass BootServiceProvider\n{\n}\n"
            );
        }

        foreach ($files as $relative => $code) {
            $full   = $dir . '/' . $relative;
            $parent = dirname($full);

            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            file_put_contents($full, $code);
        }

        return $dir;
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
