<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;
use think\model\concern\SoftDelete;

class Page extends BaseModel
{
    use SoftDelete;

    protected $name = 'wisecms_page';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 关联栏目
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'catid', 'id');
    }
}
