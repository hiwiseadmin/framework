<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * TagQueryService 数据库依赖测试（C-3 模板标签库）
 *
 * 覆盖（需要真实数据）：
 * - 站点隔离：A 站标签不输出 B 站内容；
 * - 外泄防护：status != 2（草稿/待审/归档）与软删内容不出现在 arclist 结果中；
 * - typeid 多栏目：`typeid="1,2"` 命中两个栏目的内容；`sub="1"` 展开子栏目；
 * - flag=recommend：仅 is_recommend=1 的内容；flag=hot 按 view_count 降序；
 * - orderby 白名单生效：`orderby="view_count"` 生效、非法值回落 id；
 * - limit 上限：`limit="999"` 实际最多返回 tag_arclist_limit_max（默认 50）条；
 * - day 时间窗口：仅返回 create_time >= now - day*86400 的内容；
 * - 缓存命中：同一参数二次调用不再查询（spy CacheService，第二次 remember 直接命中）；
 * - channel：parentid 取子栏目、children 子树、showhidden 控制隐藏栏目；
 * - tags：limit 收敛、count desc 顺序；
 * - sql：tag_sql_enable=false 返回空；表白名单/关键词黑名单拦截。
 *
 * 纯逻辑用例（normalizeArclist / normalizeChannel / normalizeTags / cacheKey /
 * safeSqlFragment / buildTree / Cms 编译产物 / 标签缓存失效接线）
 * 已由零依赖的 tests/run.php 覆盖，无需数据库。
 *
 * 当前插件测试基建未接入 ThinkPHP 应用引导与测试库，故此处标记 skipped，
 * 待提供测试库后补充 bootstrap（初始化 think\App + migrations + 造数）即可启用。
 */
class TagQueryServiceTest extends TestCase
{
    public function testSiteIsolation(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testDraftAndDeletedAreNotExposed(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testTypeidMultiCategoryAndSub(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testFlagRecommendAndHotOrdering(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testOrderbyWhitelistTakesEffect(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testLimitUpperBound(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testDayTimeWindow(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testSecondCallHitsCache(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testChannelTreeAndShowhidden(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testTagsLimitAndOrder(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testSqlDisabledByDefault(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testSqlTableAndKeywordGuards(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testContentSavedInvalidatesArclistCache(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
