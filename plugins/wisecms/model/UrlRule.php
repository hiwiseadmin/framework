<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

/**
 * URL 规则模型
 */
class UrlRule extends BaseModel
{
    protected $name = 'wisecms_url_rule';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const TYPE_CONTENT  = 'content';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_TAG      = 'tag';
    public const TYPE_PAGE     = 'page';

    public const SCOPE_GLOBAL   = 'global';
    public const SCOPE_MODEL    = 'model';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_ENTITY   = 'entity';
}
