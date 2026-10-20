<?php
namespace plugin\wisecms\controller\api;

use plugin\ApiController;
use plugin\wisecms\model\Content;
use plugin\wisecms\service\site\SiteService;

/**
 * CMS 前台 API 基类（P1-D：站点隔离的唯一事实源）
 *
 * **所有** 对外 API 都必须限定在「当前站点」范围内：
 *   · 站点解析沿用前台口径 —— `SiteService::resolveCurrentSiteId()`（域名匹配 > 会话切换 > 默认站点）；
 *   · **不接受 `?site_id=` 请求参数**（P1-B3 已取消其信任）：否则任何匿名请求加个参数
 *     即可遍历其它站点的内容 / 评论 / 搜索词，属越权读取与缓存污染；
 *   · 越界内容一律按「不存在」处理（不返回 403），避免暴露「此处存在受保护内容」成为探测点。
 *
 * 新增 API action 时的强制约定：任何按 `id` 取内容的查询都必须走 {@see findSiteContent()}，
 * 任何列表查询都必须带上 `site_id` 条件。
 */
abstract class Base extends ApiController
{
    /**
     * 当前站点 ID（>0）
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveCurrentSiteId();
    }

    /**
     * 取「当前站点 + 已发布 + 未删除」的内容（不存在返回 null）
     *
     * 详情、评论、相关推荐、上下篇等按 id 关联内容的入口统一走本方法，
     * 避免各自漏掉站点/状态条件（这正是 P1-D 要修的越权读取面）。
     */
    protected function findSiteContent(int $contentId): ?Content
    {
        return Content::where('id', $contentId)
            ->where('site_id', $this->siteId())
            ->where('status', 2)
            ->whereNull('delete_time')
            ->find();
    }
}
