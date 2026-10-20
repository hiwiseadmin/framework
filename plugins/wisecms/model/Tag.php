<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

/**
 * 标签模型（**词表按站点隔离**，P2）
 *
 * 关键约定：
 *   · `site_id`  —— 标签归属站点，`name` / `alias` 的唯一性均为**站点内唯一**
 *     （唯一键 `(site_id, name)` / `(site_id, alias)`）；
 *   · `count`    —— **本站口径**的引用计数（本站「已发布 + 未回收」内容对该标签的引用数），
 *     唯一维护入口是 `ContentService::recountTags()`；
 *   · 关联表 `wisecms_tag_relation.site_id` 为冗余列（恒与内容同站）。
 *
 * 已移除的历史方法（勿再加回）：
 *   · `getHot()`    —— 无调用方，且按全站 count 排序已无意义（现由 `TagService::siteCloud()` 承担，带站点与缓存）；
 *   · `updateCount()` —— 无调用方，且其统计口径（`TagRelation::where('tag_id')->count()`）不含
 *     状态/回收站/站点过滤，会写坏 `count`（现统一走 `ContentService::recountTags()` 的 GROUP BY 重算）。
 */
class Tag extends BaseModel
{
    protected $name = 'wisecms_tag';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    // 表已补 update_time 列：恢复自动维护（原为关闭）
    protected $updateTime = 'update_time';

    /**
     * 关联内容
     */
    public function contents()
    {
        return $this->belongsToMany(Content::class, TagRelation::class, 'content_id', 'tag_id');
    }
}
