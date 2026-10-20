<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class ContentQuality extends BaseModel
{
    protected $name = 'wisecms_content_quality';
    // 自动时间戳：create_time / update_time 均为 DATETIME（见建表迁移的时间列约定）
    protected $autoWriteTimestamp = true;
    
    protected $json = ['suggestions'];
}
