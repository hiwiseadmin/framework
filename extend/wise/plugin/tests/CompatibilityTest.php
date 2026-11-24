<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\compat\FrameworkCompatibility;
use wise\plugin\compat\VersionRange;
use wise\plugin\scanner\PluginMetadata;

/**
 * 框架兼容契约测试（`manifest.compatible_with`）。
 *
 * 三件事必须锁死：
 *  1. **语法与市场侧一致** —— 同一批用例在 market `VersionConstraint` 上必须给出同样结果
 *     （双跑脚本 `scripts/verify-compat-parity.php`）；这里的表就是那份共享用例。
 *  2. **未声明 ≠ 不兼容** —— 存量插件没有该字段，一律拦截会把升级路径砸断。
 *  3. **版本补段要等价** —— `1.0` 与 `1.0.0` 必须视为同一版本（PHP `version_compare` 不是）。
 */
class CompatibilityTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wisecompat_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);
    }

    public function testNormalizeAcceptsStringAndArray(): void
    {
        $this->assertSame(['^1.0'], FrameworkCompatibility::normalize('^1.0'));
        $this->assertSame(['^1.0', '>=1.1 <2.0'], FrameworkCompatibility::normalize([' ^1.0 ', '>=1.1 <2.0']));
        $this->assertSame(['^1.0'], FrameworkCompatibility::normalize(['^1.0', '^1.0']), '去重');
    }

    public function testNormalizeDropsInvalidShapes(): void
    {
        $this->assertSame([], FrameworkCompatibility::normalize(null));
        $this->assertSame([], FrameworkCompatibility::normalize(123));
        $this->assertSame([], FrameworkCompatibility::normalize(['', '  ', ['nested']]));
        $this->assertSame(['^1.0'], FrameworkCompatibility::normalize(['^1.0', 42]));
    }

    public function testNormalizeCapsRangeCount(): void
    {
        $ranges = [];

        for ($i = 0; $i < 12; $i++) {
            $ranges[] = '>=' . (1 + $i) . '.0';
        }

        $this->assertCount(8, FrameworkCompatibility::normalize($ranges), '脏数据不应把字段撑爆');
    }

    public function testUndeclaredIsUnknownNotIncompatible(): void
    {
        $this->assertSame(FrameworkCompatibility::UNKNOWN, FrameworkCompatibility::check([], '1.0.1'));
    }

    public function testDeclaredRangeJudgesCompatibility(): void
    {
        $this->assertSame(FrameworkCompatibility::COMPATIBLE, FrameworkCompatibility::check(['^1.0'], '1.0.1'));
        $this->assertSame(FrameworkCompatibility::COMPATIBLE, FrameworkCompatibility::check(['^1.0', '^2.0'], '2.3.0'));
        $this->assertSame(FrameworkCompatibility::INCOMPATIBLE, FrameworkCompatibility::check(['^1.0'], '2.0.0'));
        $this->assertSame(FrameworkCompatibility::COMPATIBLE, FrameworkCompatibility::check(['^1.0'], '1.0'), '1.0 补段后等于 1.0.0，仍满足 ^1.0');
    }

    /**
     * 共享用例表：market 侧 `scripts/verify-compat-parity.php` 会跑同一批数据。
     *
     * @return array<string, array{0:string,1:string,2:bool}>
     */
    public static function rangeCases(): array
    {
        return [
            'caret-major'        => ['1.4.2', '^1.0', true],
            'caret-major-bound'  => ['2.0.0', '^1.0', false],
            'caret-zero-minor'   => ['0.3.9', '^0.3.0', true],
            'caret-zero-bump'    => ['0.4.0', '^0.3.0', false],
            'tilde-patch'        => ['1.2.9', '~1.2.3', true],
            'tilde-patch-bound'  => ['1.3.0', '~1.2.3', false],
            'tilde-minor'        => ['1.9.0', '~1.2', true],
            'tilde-minor-bound'  => ['2.0.0', '~1.2', false],
            'wildcard-patch'     => ['1.2.7', '1.2.*', true],
            'wildcard-bound'     => ['1.3.0', '1.2.*', false],
            'and-space'          => ['1.5.0', '>=1.2 <2.0', true],
            'and-space-low'      => ['1.1.9', '>=1.2 <2.0', false],
            'and-comma'          => ['1.5.0', '>=1.2,<2.0', true],
            'or-pipe'            => ['2.1.0', '^1.0 | ^2.0', true],
            'hyphen-range'       => ['2.0.0', '1.2.3 - 2.0.0', true],
            'hyphen-range-out'   => ['2.0.1', '1.2.3 - 2.0.0', false],
            'exact'              => ['1.0.0', '1.0.0', true],
            'exact-padded'       => ['1.0', '1.0.0', true],
            'any-star'           => ['9.9.9', '*', true],
            'any-empty'          => ['1.0.0', '', true],
            'pad-short-range'    => ['1.0.0', '>=1.0', true],
            'invalid-syntax'     => ['1.0.0', '^1.0 &&', false],
            'invalid-wildcard'   => ['1.2.3', '1.*.3', false],
        ];
    }

    /**
     * @dataProvider rangeCases
     */
    public function testRangeCasesMatchExpected(string $version, string $constraint, bool $expected): void
    {
        $this->assertSame($expected, VersionRange::satisfies($version, $constraint), $version . ' against ' . $constraint);
    }

    public function testIsValidDetectsSyntaxErrors(): void
    {
        $this->assertTrue(VersionRange::isValid('^1.0'));
        $this->assertTrue(VersionRange::isValid(''));
        $this->assertFalse(VersionRange::isValid('^1.0 &&'));
        $this->assertFalse(VersionRange::isValid('not-a-version'));
    }

    public function testFromManifestFileHandlesMissingAndBroken(): void
    {
        $this->assertSame([], FrameworkCompatibility::fromManifestFile($this->tmp . '/missing.json'));

        $broken = $this->tmp . '/broken.json';
        file_put_contents($broken, '{ broken');
        $this->assertSame([], FrameworkCompatibility::fromManifestFile($broken));

        $ok = $this->tmp . '/manifest.json';
        file_put_contents($ok, json_encode(['compatible_with' => '^1.0']));
        $this->assertSame(['^1.0'], FrameworkCompatibility::fromManifestFile($ok));
    }

    public function testViolationMessageIsActionable(): void
    {
        $message = FrameworkCompatibility::violationMessage('demo', ['^1.0'], '2.0.0');

        $this->assertStringContainsString('demo', $message);
        $this->assertStringContainsString('^1.0', $message);
        $this->assertStringContainsString('2.0.0', $message);
        $this->assertStringContainsString('--ignore-compat', $message);
    }

    public function testMetadataKeepsCompatibilityThroughCacheRoundTrip(): void
    {
        $metadata = PluginMetadata::fromArray([
            'name'            => 'demo',
            'version'         => '1.0.0',
            'compatible_with' => ['^1.0', '^1.1'],
        ]);

        $this->assertSame(['^1.0', '^1.1'], $metadata->getCompatibleWith());
        $this->assertTrue($metadata->hasCompatibilityDeclaration());
        $this->assertSame(['^1.0', '^1.1'], $metadata->toManifest()['compatible_with']);

        $restored = PluginMetadata::fromArray($metadata->toArray());
        $this->assertSame(['^1.0', '^1.1'], $restored->getCompatibleWith(), '缓存往返后声明必须保留');
    }

    public function testMetadataWithoutDeclarationStaysEmptyAndAbsent(): void
    {
        $metadata = PluginMetadata::fromArray(['name' => 'demo', 'version' => '1.0.0']);

        $this->assertSame([], $metadata->getCompatibleWith());
        $this->assertFalse($metadata->hasCompatibilityDeclaration());
        $this->assertArrayNotHasKey('compatible_with', $metadata->toArray());
        $this->assertArrayNotHasKey('compatible_with', $metadata->toManifest());
    }
}
