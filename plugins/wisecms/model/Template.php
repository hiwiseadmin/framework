<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class Template extends BaseModel
{
    protected $name = 'wisecms_template';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
