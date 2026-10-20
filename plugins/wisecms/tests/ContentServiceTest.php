<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * ContentService 事务与唯一性测试（依赖 ThinkPHP + 测试数据库）
 *
 * - createWithFields 事务回滚：主表 create 成功但副表 saveCustomData 抛异常时，主表记录应一并回滚。
 * - saveCustomData 唯一冲突：副表存在唯一索引时，插入重复值应抛出友好提示（A-8）。
 *
 * 当前插件测试基建未接入 ThinkPHP 应用引导与测试库，故此处标记 skipped，
 * 待提供测试库后补充 bootstrap（初始化 think\App + migrations）即可启用。
 */
class ContentServiceTest extends TestCase
{
    public function testCreateWithFieldsRollback(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testSaveCustomDataDuplicateThrowsFriendlyMessage(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
