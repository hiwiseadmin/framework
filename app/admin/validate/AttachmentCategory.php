<?php
namespace app\admin\validate;

use think\Validate;

class AttachmentCategory extends Validate
{
    protected $rule = [
        'id' => 'require|number',
        'name' => 'require|max:100',
        'parent_id' => 'number',
        'sort' => 'number',
        'status' => 'in:0,1'
    ];
    
    protected $message = [
        'id.require' => '分类ID不能为空',
        'id.number' => '分类ID必须为数字',
        'name.require' => '分类名称不能为空',
        'name.max' => '分类名称长度不能超过100个字符',
        'parent_id.number' => '父级分类ID必须为数字',
        'sort.number' => '排序值必须为数字',
        'status.in' => '状态值不正确'
    ];
    
    public function sceneAdd()
    {
        return $this->only(['name', 'parent_id', 'sort'])
                   ->remove('id', 'require');
    }
    
    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'parent_id', 'sort']);
    }
    
    public function sceneDelete()
    {
        return $this->only(['id']);
    }
    
    public function sceneInfo()
    {
        return $this->only(['id']);
    }
}