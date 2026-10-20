<?php
declare(strict_types=1);

namespace plugin\demo\model;

use think\Model;

/**
 * demo 示例模型
 *
 * 表名约定（不含前缀）：demo_example
 */
class Example extends Model
{
    protected $name = 'demo_example';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $schema = [
        'id'          => 'int',
        'title'       => 'string',
        'content'     => 'string',
        'status'      => 'int',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
    ];
}
