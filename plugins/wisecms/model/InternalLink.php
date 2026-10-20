<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class InternalLink extends BaseModel
{
    protected $name = 'wisecms_internal_link';
    // 自动时间戳：create_time / update_time 均为 DATETIME（见建表迁移的时间列约定）
    protected $autoWriteTimestamp = true;

    /**
     * 根据关键词和站点获取链接
     */
    public static function getByKeyword(string $keyword, int $siteId = 0): ?array
    {
        $query = static::where('keyword', $keyword)->where('status', 1);
        if ($siteId > 0) $query->where('site_id', $siteId);
        return $query->find()?->toArray();
    }
}
