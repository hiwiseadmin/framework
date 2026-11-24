<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\plugin\audit\AuditRuleSet;
use wise\plugin\audit\AuditRunner;

/**
 * 本地预检执行器测试（纯静态：不连库、不联网）。
 *
 * 重点不是「把所有规则跑一遍」，而是守住三条不能坏的判定：
 *  1. 结构性错误必须**阻断**（error），否则作者带着装不上的包去上架；
 *  2. 代码层正则只能**提示**（warn），否则误报会赶走作者；
 *  3. 合规包不能被报出 error —— 假红比漏报更伤（作者会失去对预检的信任）。
 */
class AuditRunnerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiseaudit_' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testCompliantPackageHasNoErrors(): void
    {
        $issues = $this->audit($this->makePlugin('demo'));

        $this->assertSame([], $this->errors($issues), '合规包不应出现 error 级问题：' . $this->describe($issues));
    }

    public function testMissingManifestBlocks(): void
    {
        $dir = $this->root . '/demo';
        mkdir($dir, 0755, true);

        $issues = $this->audit($dir);

        $this->assertContains(AuditRuleSet::STRUCTURE_MANIFEST_MISSING, $this->rules($issues));
        $this->assertSame([AuditRuleSet::STRUCTURE_MANIFEST_MISSING], array_column($this->errors($issues), 'rule'));
    }

    public function testInvalidJsonBlocks(): void
    {
        $dir = $this->root . '/demo';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/manifest.json', '{ 这不是 JSON');

        $issues = $this->audit($dir);

        $this->assertContains(AuditRuleSet::STRUCTURE_MANIFEST_INVALID, $this->rules($issues));
        $this->assertNotEmpty($this->errors($issues));
    }

    public function testMissingRequiredFieldBlocks(): void
    {
        $dir = $this->makePlugin('demo', ['name' => '', 'version' => '1.0.0']);

        $this->assertContains(AuditRuleSet::MANIFEST_REQUIRED_FIELD, $this->rules($this->audit($dir)));
    }

    public function testDangerousFunctionIsWarnOnly(): void
    {
        $dir = $this->makePlugin('demo', [], [
            'service/Export.php' => "<?php\nnamespace plugin\\demo\\service;\n\nclass Export\n{\n    public function run(\$code)\n    {\n        eval(\$code);\n    }\n}\n",
        ]);

        $issues = $this->audit($dir);
        $hit    = $this->byRule($issues, AuditRuleSet::CODE_DANGEROUS_FUNCTION);

        $this->assertNotNull($hit, '应命中危险函数提示：' . $this->describe($issues));
        $this->assertSame(AuditIssue::WARN, $hit->level, '代码正则永不阻断');
        $this->assertSame([], $this->errors($issues), '提示不应升级为 error');
    }

    public function testForbiddenExtensionBlocks(): void
    {
        $dir = $this->makePlugin('demo', [], [
            'statics/backdoor.phtml' => '<?php echo 1;',
        ]);

        $this->assertContains(AuditRuleSet::STRUCTURE_FORBIDDEN_EXTENSION, $this->rules($this->audit($dir)));
    }

    public function testMisspelledFieldIsWarnOnly(): void
    {
        $dir = $this->makePlugin('demo', ['menuss' => []]);

        $issues = $this->audit($dir);
        $hit    = $this->byRule($issues, AuditRuleSet::MANIFEST_UNKNOWN_FIELD);

        $this->assertNotNull($hit, '拼写错误字段应被提示：' . $this->describe($issues));
        $this->assertSame(AuditIssue::WARN, $hit->level);
    }

    public function testValidSeedDeclarationIsNotUnknownField(): void
    {
        foreach (['required', 'demo', 'none'] as $mode) {
            $dir    = $this->makePlugin('demo_' . $mode, ['name' => 'demo_' . $mode, 'seed' => $mode]);
            $issues = $this->audit($dir);

            $this->assertNull(
                $this->byRule($issues, AuditRuleSet::MANIFEST_UNKNOWN_FIELD),
                'seed=' . $mode . ' 是已知字段：' . $this->describe($issues)
            );
            $this->assertSame([], $this->errors($issues));
        }
    }

    public function testValidCompatibleWithIsNotUnknownField(): void
    {
        $issues = $this->audit($this->makePlugin('demo', ['compatible_with' => ['^1.0', '>=1.1 <2.0']]));

        $this->assertNull($this->byRule($issues, AuditRuleSet::MANIFEST_UNKNOWN_FIELD));
        $this->assertSame([], $this->errors($issues), $this->describe($issues));
    }

    public function testInvalidCompatibleWithRangeBlocks(): void
    {
        $hit = $this->byRule(
            $this->audit($this->makePlugin('demo', ['compatible_with' => '^1.0 &&'])),
            AuditRuleSet::MANIFEST_TYPE_INVALID
        );

        $this->assertNotNull($hit, '范围语法写错必须提示（客户端 fail-closed，会让作者"莫名装不上"）');
        $this->assertStringContainsString('compatible_with', $hit->message);
    }

    public function testEmptyCompatibleWithBlocks(): void
    {
        $hit = $this->byRule(
            $this->audit($this->makePlugin('demo', ['compatible_with' => []])),
            AuditRuleSet::MANIFEST_TYPE_INVALID
        );

        $this->assertNotNull($hit, '空数组既不是"未声明"也不是有效声明，应显式报错');
    }

    public function testInvalidSeedValueBlocks(): void
    {
        $hit = $this->byRule(
            $this->audit($this->makePlugin('demo', ['seed' => 'demoo'])),
            AuditRuleSet::MANIFEST_TYPE_INVALID
        );

        $this->assertNotNull($hit, '写错的值必须提示，否则会静默回落为 required');
        $this->assertSame(AuditIssue::ERROR, $hit->level);
        $this->assertStringContainsString('seed', $hit->message);
    }

    public function testDirectoryNameMismatchIsWarn(): void
    {
        $dir = $this->makePlugin('demo');
        $renamed = $this->root . '/other';
        rename($dir, $renamed);

        $hit = $this->byRule($this->audit($renamed), AuditRuleSet::STRUCTURE_DIR_NAME_MISMATCH);

        $this->assertNotNull($hit);
        $this->assertSame(AuditIssue::WARN, $hit->level);
    }

    public function testMissingServiceProviderFileBlocks(): void
    {
        $dir = $this->makePlugin('demo');
        unlink($dir . '/BootServiceProvider.php');

        $this->assertContains(
            AuditRuleSet::MANIFEST_SERVICE_PROVIDER_MISSING,
            $this->rules($this->audit($dir))
        );
    }

    public function testVendorDirectoryIsWarnOnly(): void
    {
        $dir = $this->makePlugin('demo', [], [
            'node_modules/pkg/index.js' => 'module.exports = 1;',
        ]);

        $issues = $this->audit($dir);
        $hit    = $this->byRule($issues, AuditRuleSet::STRUCTURE_VENDOR_DIR);

        $this->assertNotNull($hit);
        $this->assertSame(AuditIssue::WARN, $hit->level, '依赖目录只是提示，不阻断（打包器会排除）');
    }

    public function testPackageIgnoresDependencyDirectoriesWhenScanningCode(): void
    {
        $dir = $this->makePlugin('demo', [], [
            'vendor/evil/func.php' => "<?php\nsystem('rm -rf /');\n",
        ]);

        $issues = $this->audit($dir);

        $this->assertNull(
            $this->byRule($issues, AuditRuleSet::CODE_DANGEROUS_FUNCTION),
            'vendor 目录不参与规则扫描（只提示目录存在）'
        );
    }


    /**
     * @return array<int, AuditIssue>
     */
    private function audit(string $path): array
    {
        return (new AuditRunner(AuditRuleSet::regexRules()))->run(AuditPackage::open($path));
    }

    /**
     * @param array<int, AuditIssue> $issues
     *
     * @return array<int, array<string, mixed>>
     */
    private function errors(array $issues): array
    {
        return array_values(array_map(
            static fn (AuditIssue $issue): array => $issue->toArray(),
            array_filter($issues, static fn (AuditIssue $issue): bool => $issue->isError())
        ));
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
     * 造一个「合规」插件（manifest 必需字段齐全 + provider 文件存在）。
     *
     * @param array<string, mixed>  $manifestOverride
     * @param array<string, string> $extraFiles       相对路径 => 内容
     */
    private function makePlugin(string $ident, array $manifestOverride = [], array $extraFiles = []): string
    {
        $dir = $this->root . '/' . $ident;
        mkdir($dir, 0755, true);

        $manifest = array_merge([
            'name'             => $ident,
            'version'          => '1.0.0',
            'author'           => 'WiseAdmin',
            'title'            => '示例插件',
            'description'      => '用于预检测试的示例插件',
            'service_provider' => 'plugin\\' . $ident . '\\BootServiceProvider',
            'menus'            => [
                ['name' => '示例', 'path' => '/plugin/' . $ident . '/index', 'type' => 'menu'],
            ],
            'permissions'      => [
                ['name' => '查看示例', 'ident' => $ident . '.index', 'type' => 'custom'],
            ],
        ], $manifestOverride);

        file_put_contents(
            $dir . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents(
            $dir . '/BootServiceProvider.php',
            "<?php\nnamespace plugin\\{$ident};\n\nclass BootServiceProvider\n{\n}\n"
        );

        foreach ($extraFiles as $relative => $code) {
            $full = $dir . '/' . $relative;
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
