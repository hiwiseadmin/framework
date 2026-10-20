<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class SeoPushLog extends BaseModel
{
    protected $name = 'wisecms_seo_push_log';
    // 自动时间戳：create_time / update_time 均为 DATETIME（见建表迁移的时间列约定）
    protected $autoWriteTimestamp = true;
}
