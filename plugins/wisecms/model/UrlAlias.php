<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

/**
 * URL 别名索引模型（当前规范 URL + 历史 URL）
 */
class UrlAlias extends BaseModel
{
    protected $name = 'wisecms_url_alias';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const TYPE_CONTENT  = 'content';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_TAG      = 'tag';
    public const TYPE_PAGE     = 'page';
}
