<?php
namespace app\admin\validate;

use think\Validate;

class Menu extends Validate
{
    protected $rule = [
        'parent_id' => 'number',
        'name' => 'require|max:100',
        'path' => 'max:255',
        'component' => 'max:255',
        'permission_id' => 'number',
        'sort' => 'number|min:0',
        'type' => 'require|in:dir,menu,button|checkType',
        'status' => 'in:0,1'
    ];
    
    protected $message = [
        'parent_id.number' => '父级菜单ID必须是数字',
        'name.require' => '菜单名称不能为空',
        'name.max' => '菜单名称不能超过100个字符',
        'path.max' => '路径不能超过255个字符',
        'component.max' => '组件路径不能超过255个字符',
        'permission_id.number' => '权限ID必须是数字',
        'sort.number' => '排序号必须是数字',
        'sort.min' => '排序号不能小于0',
        'type.require' => '请选择菜单类型',
        'type.in' => '菜单类型不正确（可选值：dir, menu, button）',
        'status.in' => '状态值不正确'
    ];
    
    protected $scene = [
        'add' => ['parent_id', 'name', 'path', 'component', 'permission_id', 'sort', 'type', 'status'],
        'edit' => ['parent_id', 'name', 'path', 'component', 'permission_id', 'sort', 'type', 'status']
    ];

    /**
     * 自定义验证规则 - 检查等级是否被占用
     */
    protected function checkType($value, $rule, $data = [])
    {
        if ($value !== 'dir') {
            if (!isset($data['path']) || $data['path'] == null || trim($data['path']) == '') {
                return '路径不能为空';
            }
        }

        return true;
    }
}
