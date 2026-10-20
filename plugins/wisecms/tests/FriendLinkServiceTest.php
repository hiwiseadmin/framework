<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * FriendLinkService 数据库依赖测试（B-1 友情链接）
 *
 * 覆盖（需要真实数据）：
 * - 站点隔离：只输出当前 site_id 的友链；
 * - status=0 不输出；
 * - 有效期窗口：未生效 / 已过期 / 不限 三种组合；
 * - 排序：sort desc, id desc；
 * - forFront 二次调用命中缓存（不产生 wisecms_friend_link 查询）；
 * - create 非法 URL 抛 WisecmsException（VALIDATE_FAILED）；
 * - 后台变更后 forFront 立即反映（缓存已失效）。
 *
 * 纯逻辑用例（normalizeUrl / cacheKey）已由零依赖的 tests/run.php 覆盖，无需数据库。
 *
 * 当前插件测试基建未接入 ThinkPHP 应用引导与测试库，故此处标记 skipped，
 * 待提供测试库后补充 bootstrap（初始化 think\App + migrations）即可启用。
 */
class FriendLinkServiceTest extends TestCase
{
    public function testSiteIsolation(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testHiddenNotExposed(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testTimeWindowFilter(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testOrderBySortThenId(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testCacheHitAvoidsQuery(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testCreateRejectsInvalidUrl(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testChangeInvalidatesCache(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
