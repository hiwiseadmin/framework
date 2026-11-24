<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\lifecycle\SeedPolicy;
use wise\plugin\scanner\PluginMetadata;

/**
 * 数据填充策略测试。
 *
 * 这套用例守的是「装完之后库里有什么」：
 *  - 基线数据（`required`）不能被静默跳过 —— 跳过等于装出半个系统；
 *  - 演示数据（`demo`）不能被默认灌入 —— 第三方插件塞一堆假数据比没有更糟；
 *  - 演示数据失败不能阻断安装 —— 可用性优先于「有假数据」。
 */
class SeedPolicyTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiseseed_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);
    }

    public function testNormalizeAcceptsKnownModes(): void
    {
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::normalize('required'));
        $this->assertSame(SeedPolicy::DEMO, SeedPolicy::normalize(' DEMO '));
        $this->assertSame(SeedPolicy::NONE, SeedPolicy::normalize('none'));
    }

    public function testNormalizeFallsBackToRequiredForUndeclaredOrUnknown(): void
    {
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::normalize(null));
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::normalize(''));
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::normalize('demoo'));
    }

    public function testNormalizeAcceptsLegacyBooleanDemoFlag(): void
    {
        $this->assertSame(SeedPolicy::DEMO, SeedPolicy::normalize(true), 'demo_seed: true 历史写法');
        $this->assertSame(SeedPolicy::NONE, SeedPolicy::normalize(false));
    }

    public function testFromManifestPrefersSeedKey(): void
    {
        $this->assertSame(SeedPolicy::DEMO, SeedPolicy::fromManifest(['seed' => 'demo']));
        $this->assertSame(SeedPolicy::NONE, SeedPolicy::fromManifest(['seed' => 'none', 'demo_seed' => true]), 'seed 优先');
        $this->assertSame(SeedPolicy::DEMO, SeedPolicy::fromManifest(['demo_seed' => true]), '兼容旧键');
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::fromManifest([]));
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::fromManifest(null));
    }

    public function testFromManifestFileHandlesMissingAndBrokenFiles(): void
    {
        $missing = $this->tmp . '/missing.json';
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::fromManifestFile($missing), '升级时读不到清单不该改行为');

        $broken = $this->tmp . '/broken.json';
        file_put_contents($broken, '{ broken');
        $this->assertSame(SeedPolicy::REQUIRED, SeedPolicy::fromManifestFile($broken));

        $ok = $this->tmp . '/manifest.json';
        file_put_contents($ok, json_encode(['seed' => 'demo']));
        $this->assertSame(SeedPolicy::DEMO, SeedPolicy::fromManifestFile($ok));
    }

    public function testShouldRunCoversThreeModes(): void
    {
        $this->assertTrue(SeedPolicy::shouldRun(SeedPolicy::REQUIRED, false), '基线数据默认执行');
        $this->assertTrue(SeedPolicy::shouldRun(SeedPolicy::REQUIRED, true));

        $this->assertFalse(SeedPolicy::shouldRun(SeedPolicy::DEMO, false), '演示数据默认跳过');
        $this->assertTrue(SeedPolicy::shouldRun(SeedPolicy::DEMO, true), '--with-demo 才执行');

        $this->assertFalse(SeedPolicy::shouldRun(SeedPolicy::NONE, false));
        $this->assertFalse(SeedPolicy::shouldRun(SeedPolicy::NONE, true), 'none 即使显式也不执行');
    }

    public function testFailureIsFatalOnlyForBaselineData(): void
    {
        $this->assertTrue(SeedPolicy::failureIsFatal(SeedPolicy::REQUIRED, false), '缺基线数据 = 半个系统，必须回滚');
        $this->assertFalse(SeedPolicy::failureIsFatal(SeedPolicy::DEMO, false));
        $this->assertFalse(SeedPolicy::failureIsFatal(SeedPolicy::DEMO, true), '演示数据失败只告警');
        $this->assertFalse(SeedPolicy::failureIsFatal(SeedPolicy::NONE, false));
    }

    public function testLabelIsHumanReadable(): void
    {
        $this->assertStringContainsString('基线数据', SeedPolicy::label(SeedPolicy::REQUIRED));
        $this->assertStringContainsString('--with-demo', SeedPolicy::label(SeedPolicy::DEMO));
        $this->assertStringContainsString('无数据填充', SeedPolicy::label(SeedPolicy::NONE));
    }


    public function testMetadataDefaultsToRequiredWhenUndeclared(): void
    {
        $metadata = PluginMetadata::fromArray(['name' => 'demo', 'version' => '1.0.0']);

        $this->assertSame(SeedPolicy::REQUIRED, $metadata->getSeedMode());
        $this->assertFalse($metadata->hasSeedDeclaration());
        $this->assertArrayNotHasKey('seed', $metadata->toManifest(), '未声明时不凭空补字段');
        $this->assertArrayNotHasKey('seed', $metadata->toArray(), '缓存序列化同样不补字段（避免写回清单污染）');
    }

    public function testMetadataKeepsSeedThroughCacheRoundTrip(): void
    {
        $metadata = PluginMetadata::fromArray(['name' => 'wisedocs', 'version' => '1.0.0', 'seed' => 'demo']);

        $this->assertSame(SeedPolicy::DEMO, $metadata->getSeedMode());
        $this->assertTrue($metadata->hasSeedDeclaration());
        $this->assertSame('demo', $metadata->toManifest()['seed']);

        $restored = PluginMetadata::fromArray($metadata->toArray());
        $this->assertSame(SeedPolicy::DEMO, $restored->getSeedMode(), '缓存往返后策略必须保留');
    }

    public function testMetadataIgnoresInvalidSeedValue(): void
    {
        $metadata = PluginMetadata::fromArray(['name' => 'demo', 'version' => '1.0.0', 'seed' => 'demoo']);

        $this->assertSame(SeedPolicy::REQUIRED, $metadata->getSeedMode(), '写错回落默认值，不打崩安装');
        $this->assertFalse($metadata->hasSeedDeclaration());
    }
}
