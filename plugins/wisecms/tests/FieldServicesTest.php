<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\service\model\FieldRegistry;
use plugin\wisecms\service\model\InputService;

/**
 * 动态字段服务测试（纯逻辑部分，无需数据库）
 *
 * - InputService::validateField  必填 / 长度 / 正则
 * - FieldRegistry::clear         缓存失效
 * - FieldRegistry::normalize     setting 解码 + type_config 合并 + data_type 回退
 *
 * 数据库依赖的 validateUnique 见本文件末尾（标记 skipped）。
 */
class FieldServicesTest extends TestCase
{
    /**
     * 仅记录 delete 调用的 CacheService 测试替身
     */
    private function makeFakeCache(): CacheService
    {
        return new class extends CacheService {
            /** @var string[] */
            public array $deleted = [];

            public function delete(string $key): bool
            {
                $this->deleted[] = $key;
                return true;
            }
        };
    }

    private function invokeProtected(object $obj, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function newInputService(): InputService
    {
        return (new \ReflectionClass(InputService::class))->newInstanceWithoutConstructor();
    }

    // ===================== validateField：必填 =====================

    public function testValidateRequiredRejectsEmpty(): void
    {
        $input = $this->newInputService();
        $this->expectException(\Exception::class);
        $this->invokeProtected($input, 'validateField', [['name' => '标题', 'is_required' => 1], '']);
    }

    public function testValidateRequiredAcceptsNonEmpty(): void
    {
        $input = $this->newInputService();
        $this->invokeProtected($input, 'validateField', [['name' => '标题', 'is_required' => 1], '内容']);
        $this->assertTrue(true);
    }

    // ===================== validateField：长度 =====================

    public function testValidateLengthRejectsTooLong(): void
    {
        $input = $this->newInputService();
        $this->expectException(\Exception::class);
        $this->invokeProtected($input, 'validateField', [['name' => '标题', 'length' => 5], '123456']);
    }

    // ===================== validateField：正则 =====================

    public function testValidatePatternRejectsMismatch(): void
    {
        $input = $this->newInputService();
        $this->expectException(\Exception::class);
        $this->invokeProtected($input, 'validateField', [['name' => '邮箱', 'pattern' => '/^[\w.]+@[\w.]+$/'], 'not-an-email']);
    }

    // ===================== FieldRegistry::clear =====================

    public function testClearDeletesCorrectCacheKey(): void
    {
        $fake = $this->makeFakeCache();
        $registry = new FieldRegistry($fake);
        $registry->clear(42);

        $this->assertContains('wisecms:model:fields:42', $fake->deleted);
    }

    // ===================== FieldRegistry::normalize =====================

    private function normalizeSample(): array
    {
        $registry = (new \ReflectionClass(FieldRegistry::class))->newInstanceWithoutConstructor();
        $fields = $this->invokeProtected($registry, 'normalize', [[
            ['field' => 'name', 'form_type' => 'text', 'setting' => '{"max":10}', 'data_type' => ''],
        ]]);
        return $fields[0] ?? [];
    }

    public function testNormalizeDecodesSetting(): void
    {
        $f = $this->normalizeSample();
        $this->assertIsArray($f['setting'] ?? null);
        $this->assertSame(10, $f['setting']['max'] ?? null);
    }

    public function testNormalizeMergesTypeConfig(): void
    {
        $f = $this->normalizeSample();
        $this->assertSame('varchar', $f['type_config']['field_type'] ?? null);
    }

    public function testNormalizeFallsBackDataType(): void
    {
        $f = $this->normalizeSample();
        $this->assertSame('varchar', $f['data_type'] ?? null);
    }

    // ===================== validateUnique（需数据库，暂跳过）=====================

    public function testValidateUnique(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用与测试数据库，暂缓接入（见 ContentServiceTest::testCreateWithFieldsRollback）');
    }
}
