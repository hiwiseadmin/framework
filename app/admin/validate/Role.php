<?php
namespace app\admin\validate;

use think\Validate;

class Role extends Validate
{
    // 验证规则
    // 注意：角色标识字段名为 ident（slug 的访问器别名），唯一性校验由控制器
    // 通过 Rbac::getRoleBySlug() 手动检查（涉及软删除、自排除等复杂逻辑），
    // 此处不再使用 unique 规则（表名为 auth_roles 而非 roles，避免查错表）。
    protected $rule = [
        'name'  => 'require|max:50',
        'ident' => 'require|max:50',
        'status' => 'in:0,1',
        'sort'  => 'number|min:0',
        'description' => 'max:500',
        'max_members' => 'number|min:0'
    ];
    
    // 验证提示
    protected $message = [
        'name.require' => '角色名称不能为空',
        'name.max' => '角色名称不能超过50个字符',
        'ident.require' => '角色标识不能为空',
        'ident.max' => '角色标识不能超过50个字符',
        'status.in' => '状态值必须为0或1',
        'sort.number' => '排序序号必须是数字',
        'sort.min' => '排序号不能小于0',
        'description.max' => '角色描述不能超过500个字符'
    ];
    
    // 场景验证
    protected $scene = [
        'add'  => ['name', 'ident', 'status', 'sort', 'description', 'max_members'],
        'edit' => ['name', 'ident', 'status', 'sort', 'description', 'max_members']
    ];
}
