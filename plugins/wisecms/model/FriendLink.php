<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 友情链接模型（B-1）
 *
 * 表 wisecms_friend_link，软删除，自动写入 create_time / update_time。
 * 业务读写统一走 service/content/FriendLinkService（URL 校验 + 缓存失效）。
 */
class FriendLink extends BaseModel
{
    use SoftDelete;

    protected $name = 'wisecms_friend_link';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';
}
