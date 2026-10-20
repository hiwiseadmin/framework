<?php

declare(strict_types=1);

namespace plugin\wisedocs\validate;

use think\Validate;

class Space extends Validate
{
    protected $rule = [
        'name'        => 'require|max:100|unique:wisedocs_projects',
        'code'        => 'require|max:50|alphaDash|unique:wisedocs_projects',
        'description' => 'max:500',
        'logo'        => 'max:255',
        'theme'       => 'max:50',
        'domain'      => 'max:255',
        'sort_order'  => 'integer',
        'status'      => 'in:0,1',
    ];

    protected $message = [
        'name.require'   => '空间名称不能为空',
        'name.max'       => '空间名称最多100个字符',
        'name.unique'    => '空间名称已存在',
        'code.require'   => '空间编码不能为空',
        'code.max'       => '空间编码最多50个字符',
        'code.alphaDash' => '空间编码只能包含字母、数字、下划线和破折号',
        'code.unique'    => '空间编码已存在',
        'description.max' => '描述最多500个字符',
        'logo.max'       => 'Logo 地址最多255个字符',
        'theme.max'      => '主题最多50个字符',
        'domain.max'     => '域名最多255个字符',
        'status.in'      => '状态值错误',
    ];

    protected $scene = [
        'create' => ['name', 'code', 'description', 'logo', 'theme', 'domain', 'sort_order', 'status'],
    ];

    /**
     * 更新场景：唯一性校验排除自身
     */
    public function sceneUpdate()
    {
        return $this->only([
            'name', 'code', 'description', 'logo', 'theme', 'domain', 'sort_order', 'status',
        ])->remove('name', 'unique')
          ->remove('code', 'unique')
          ->append('name', 'unique:wisedocs_projects,name^id')
          ->append('code', 'unique:wisedocs_projects,code^id');
    }
}
