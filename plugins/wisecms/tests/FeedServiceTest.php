<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * FeedService 数据库依赖测试（A-3 RSS/Atom）
 *
 * 覆盖（需要真实数据）：
 * - 站点隔离：只输出当前 site_id 的已发布内容；
 * - 草稿不外泄：status != 2 与已软删内容不出现在 feed；
 * - 栏目范围过滤：category feed 只含该 catid 的内容；
 * - limit 上限：条目数收敛到 1..100（feed_limit 超限时截断到 100）；
 * - 缓存命中：连续两次 build() 第二次不再查询内容表。
 *
 * 纯逻辑用例（控制字符 / CDATA 拆分 / 转义 / 时间格式 / 渲染链路）
 * 已由零依赖的 tests/run.php 覆盖，无需数据库。
 *
 * 当前插件测试基建未接入 ThinkPHP 应用引导与测试库，故此处标记 skipped，
 * 待提供测试库后补充 bootstrap（初始化 think\App + migrations）即可启用。
 */
class FeedServiceTest extends TestCase
{
    public function testSiteIsolation(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testDraftAndDeletedContentNotExposed(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testCategoryScopeFilter(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testTagScopeFilter(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testLimitUpperBound(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testCacheHitAvoidsQuery(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
