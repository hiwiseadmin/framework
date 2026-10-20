<?php

declare(strict_types=1);

namespace plugin\wisecms\tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * PreviewService / 预览端点数据库依赖测试（B-3 草稿预览）
 *
 * 覆盖（需要真实数据 + 应用引导）：
 * - 草稿 + 合法令牌可访问（HTTP 200）；
 * - 草稿 + 无令牌 404；
 * - 已发布 + 合法令牌仍返回 200；
 * - 跨站内容（site_id 不符）404；
 * - 预览页响应头含 X-Robots-Tag（noindex, nofollow, noarchive）；
 * - 预览不改变 view_count；
 * - 令牌过期 / 篡改 → 404；
 * - 密钥为空（fail-closed）→ 预览端点 404、后台签发报错。
 *
 * 纯函数用例（encode/decode/sign/ttl/b64url*、空密钥 enable）已由零依赖的
 * tests/run.php 覆盖，无需数据库。
 *
 * 当前插件测试基建未接入 ThinkPHP 应用引导与测试库，故此处标记 skipped，
 * 待提供测试库后补充 bootstrap（初始化 think\App + migrations）即可启用。
 */
class PreviewServiceTest extends TestCase
{
    public function testDraftWithValidTokenReturns200(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testDraftWithoutTokenReturns404(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testPublishedWithValidTokenStillReturns200(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testCrossSiteContentReturns404(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testPreviewResponseCarriesNoindexHeader(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testPreviewDoesNotIncreaseViewCount(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testExpiredTokenReturns404(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testTamperedTokenReturns404(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }

    public function testEmptySecretFailClosed(): void
    {
        $this->markTestSkipped('需要 ThinkPHP 应用引导 + 测试数据库');
    }
}
