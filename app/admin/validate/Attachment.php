<?php
namespace app\admin\validate;

use think\Validate;

class Attachment extends Validate
{
    protected $rule = [
        'category_id' => 'number',
        'ids'         => 'require',
        'keyword'     => 'max:100',
        'page'        => 'number',
        'limit'       => 'number|between:1,100',
    ];

    protected $message = [
        'category_id.number' => '分类ID必须为数字',
        'ids.require'        => '请选择要操作的附件',
        'keyword.max'        => '关键词长度不能超过100个字符',
        'page.number'        => '页码必须为数字',
        'limit.number'       => '每页数量必须为数字',
        'limit.between'      => '每页数量必须在1-100之间',
    ];

    public function sceneUpload()
    {
        return $this->only(['category_id']);
    }

    public function sceneList()
    {
        return $this->only(['category_id', 'keyword', 'page', 'limit']);
    }

    public function sceneDelete()
    {
        return $this->only(['ids']);
    }

    public function sceneMove()
    {
        return $this->only(['ids', 'category_id']);
    }
}
