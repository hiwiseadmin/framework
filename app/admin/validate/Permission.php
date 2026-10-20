<?php
namespace app\admin\validate;

use think\Validate;

class Permission extends Validate
{
    // 验证规则
    protected $rule = [
        'parent_id' => 'number|egt:0',
        'ident'      => 'require|max:100',
        'name'     => 'require|max:100',
        'type'      => 'require|in:menu,api,custom',
        'method'    => 'require|in:*,ALL,GET,POST,PUT,DELETE',
        'sort'      => 'number|min:0',
        'description' => 'max:255',
        'status'    => 'in:0,1'
    ];
    
    // 验证提示
    protected $message = [
        'parent_id.number' => '父级ID必须是数字',
        'parent_id.egt' => '父级ID不能小于0',
        'ident.require' => '权限标识不能为空',
        'ident.max' => '权限标识不能超过100个字符',
        'name.require' => '权限名称不能为空',
        'name.max' => '权限名称不能超过100个字符',
        'type.require' => '请选择权限类型',
        'type.in' => '权限类型只能是menu、api、custom',
        'method.require' => '请选择请求方法',
        'method.in' => '请求方法只能是*、ALL、GET、POST、PUT、DELETE',
        'sort.number' => '排序号必须是数字',
        'sort.min' => '排序号不能小于0',
        'description.max' => '权限描述不能超过255个字符',
        'status.in' => '状态值必须为0或1'
    ];
    
    // 场景验证
    protected $scene = [
        'add'  => ['parent_id', 'ident', 'name', 'type', 'method', 'sort', 'description', 'status'],
        'edit' => ['parent_id', 'ident', 'name', 'type', 'method', 'sort', 'description', 'status']
    ];
}
