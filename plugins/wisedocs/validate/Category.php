<?php
namespace plugin\wisedocs\validate;

use think\Validate;
use plugin\wisedocs\model\Category as CategoryModel;

class Category extends Validate
{
    protected $rule = [
        'name' => 'require|max:100|unique:wisedocs_categories',
        'slug' => 'require|max:100|alphaDash|unique:wisedocs_categories',
        'parent_id' => 'integer|checkParent',
        'icon' => 'max:50',
        'description' => 'max:500',
        'sort_order' => 'integer',
        'status' => 'require|in:0,1',
    ];
    
    protected $message = [
        'name.require' => '分类名称不能为空',
        'name.max' => '分类名称最多100个字符',
        'name.unique' => '分类名称已存在',
        'slug.require' => 'URL标识不能为空',
        'slug.max' => 'URL标识最多100个字符',
        'slug.alphaDash' => 'URL标识只能包含字母、数字、下划线和破折号',
        'slug.unique' => 'URL标识已存在',
        'parent_id.checkParent' => '父级分类不存在或设置错误',
        'icon.max' => '图标类名最多50个字符',
        'description.max' => '描述最多500个字符',
        'status.require' => '请选择状态',
        'status.in' => '状态值错误',
    ];
    
    protected $scene = [
        'create' => ['name', 'slug', 'parent_id', 'icon', 'description', 'sort_order', 'status'],
        'update' => ['name', 'slug', 'parent_id', 'icon', 'description', 'sort_order', 'status'],
    ];
    
    // 自定义验证：检查父级分类
    protected function checkParent($value, $rule, $data = [])
    {
        if ($value == 0) {
            return true; // 顶级分类
        }
        
        $category = CategoryModel::find($value);
        if (!$category) {
            return '父级分类不存在';
        }
        
        // 不能选择自己作为父级
        if (isset($data['id']) && $data['id'] == $value) {
            return '不能选择自己作为父级';
        }
        
        // 检查层级深度（最多3级）
        if ($category->level >= 3) {
            return '分类层级不能超过3级';
        }
        
        return true;
    }
    
    // 更新场景，排除自身的唯一性检查
    public function sceneUpdate()
    {
        return $this->only([
            'name', 'slug', 'parent_id', 'icon', 'description', 'sort_order', 'status'
        ])->remove('name', 'unique')
          ->remove('slug', 'unique')
          ->append('name', 'unique:wisedocs_categories,name^id')
          ->append('slug', 'unique:wisedocs_categories,slug^id');
    }
}