<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class CommentLike extends BaseModel
{
    protected $name = 'wisecms_comment_like';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    // 表已补 update_time 列：恢复自动维护（原为关闭）
    protected $updateTime = 'update_time';
}
