<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class ContentVersion extends BaseModel
{
    protected $name = 'wisecms_content_version';
    // 自动时间戳：create_time / update_time 均为 DATETIME（见建表迁移的时间列约定）
    protected $autoWriteTimestamp = true;

    /**
     * 关联内容
     */
    public function content()
    {
        return $this->belongsTo(Content::class, 'content_id', 'id');
    }
}
