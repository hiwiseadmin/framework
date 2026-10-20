<?php
declare(strict_types=1);

namespace plugin\demo\validate;

use think\Validate;

/**
 * demo 示例验证器
 */
class Example extends Validate
{
    protected $rule = [
        'title'   => 'require|max:200',
        'content' => 'require',
        'status'  => 'require|in:0,1',
    ];

    protected $message = [
        'title.require'   => '标题不能为空',
        'title.max'       => '标题最多 200 个字符',
        'content.require' => '内容不能为空',
    ];

    protected $scene = [
        'create' => ['title', 'content', 'status'],
        'update' => ['title', 'content', 'status'],
    ];
}
