<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * RecommendService 数据库依赖测试（A-2 相关推荐）
 *
 * 覆盖（需要真实数据）：
 * - 站点隔离：他站内容不出现在候选集；
 * - 草稿/软删不出现：status != 2 与已软删内容不参与推荐；
 * - tag_hits 正确：候选 SQL 的 COUNT(DISTINCT tr.tag_id) 与预期一致；
 * - 缓存命中：连续两次 related() 第二次不再查询 tag_relation 表（spy CacheService）；
 * - 缓存异常降级：缓存驱动抛异常时仍返回数据（不 500）。
 *
 * 纯逻辑用例（score / rank / normalizeLimit / 缓存键）
 * 已由零依赖的 tests/run.php 覆盖，无需数据库。
 *
 * 当前插件测试基建未接入 ThinkPHP 应用引导与测试库，故此处标记 skipped，
 * 待提供测试库后补充 bootstrap（初始化 think\App + migrations + 造数）即可启用。
 */
class RecommendServiceTest extends TestCase
{
    public function testSiteIsolation(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testDraftAndDeletedNotRecommended(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testRelatedTagHitsCorrect(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testRelatedCacheHitAvoidsQuery(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testCacheFailureFallsBack(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
