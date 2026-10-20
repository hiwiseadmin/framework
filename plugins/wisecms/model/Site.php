<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class Site extends BaseModel
{
    protected $name = 'wisecms_site';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $json = ['setting', 'seo_setting'];
}
