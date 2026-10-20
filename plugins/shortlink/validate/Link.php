<?php
namespace plugin\shortlink\validate;

use think\Validate;

class Link extends Validate
{
    protected $rule = [
        'long_url' => 'require|url',
        'title'    => 'max:255',
        'code'     => 'alphaDash',
        'expire_time' => 'date',
        'max_visits' => 'integer|egt:0',
    ];

    protected $message = [
        'long_url.require' => '原始链接不能为空',
        'long_url.url'     => '原始链接格式不正确',
        'title.max'        => '标题长度不能超过 255 个字符',
        'code.alphaDash'   => '短码只能包含字母、数字、下划线或中划线',
        'expire_time.date' => '过期时间格式不正确',
        'max_visits.integer' => '最大访问次数必须是整数',
        'max_visits.egt'     => '最大访问次数不能小于 0',
    ];

    protected $scene = [
        'save' => ['long_url', 'title', 'expire_time', 'max_visits'],
    ];
}
