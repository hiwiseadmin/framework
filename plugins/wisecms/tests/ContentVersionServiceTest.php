<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * 内容版本快照 / 对比 / 回滚测试（A-1，依赖 ThinkPHP + 测试数据库）
 *
 * 零依赖可执行的纯逻辑用例见 `tests/run.php`（planPrune / normalize / FROZEN / TextDiffService）。
 * 本文件覆盖必须落库的行为，当前插件测试基建未接入 ThinkPHP 应用引导与测试库，
 * 故统一标记 skipped，待提供测试库后补 bootstrap（初始化 think\App + migrations）即可启用。
 *
 * 详见 docs/wisecms-content-version-design.md §10
 */
class ContentVersionServiceTest extends TestCase
{
    /**
     * 快照必须是自包含的：主表（剔除冻结字段）+ 副表 + 标签
     */
    public function testCaptureIsSelfContained(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 冻结字段不进快照（status / site_id / model_id / view_count / update_time ...）
     */
    public function testCaptureExcludesFrozenFields(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 无实质改动的保存不产生新版本（data_hash 去重）
     */
    public function testCaptureDeduplicatesUnchangedContent(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 创建即产生 v1；第 N 次保存产生 v(N+1)
     */
    public function testCreateWithFieldsProducesInitialVersion(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 回滚后主表 / 副表 / 标签全部还原为快照值
     */
    public function testRollbackRestoresMainCustomAndTags(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 回滚不还原冻结字段（防绕过审核、防跨站错位）
     */
    public function testRollbackKeepsFrozenFields(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 回滚会追加一条新版本，且可再次回退到回滚前版本
     */
    public function testRollbackIsReversible(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 副表写入失败时主表一并回滚（事务原子性）
     */
    public function testRollbackIsAtomic(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * base_update_time 不匹配时抛 409601（并发覆盖保护）
     */
    public function testRollbackRejectsStaleBaseUpdateTime(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 跨 content_id 的 version_id 取不到（防越权）
     */
    public function testFindRejectsForeignVersionId(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * prune 只删除最旧的版本，保留最新 N 条
     */
    public function testPruneKeepsNewestVersions(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    /**
     * 对比：base 缺省为当前内容，字段摘要与行级 hunks 均正确
     */
    public function testDiffAgainstCurrentContent(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
