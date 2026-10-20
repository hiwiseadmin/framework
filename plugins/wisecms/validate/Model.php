<?php
namespace plugin\wisecms\validate;

use think\Validate;
use think\facade\Db;

class Model extends Validate
{
    protected $rule = [
        'name' => 'require|alphaDash|length:2,30|checkModelName',
        'title' => 'require|length:2,50',
        'description' => 'max:200',
        'table_name' => 'alphaDash|length:2,30|checkTableName',
        'status' => 'in:0,1',
    ];
    
    protected $message = [
        'name.require' => '模型标识不能为空',
        'name.alphaDash' => '模型标识只能包含字母、数字、下划线',
        'name.length' => '模型标识长度2-30个字符',
        'name.checkModelName' => '模型标识已存在',
        'title.require' => '模型名称不能为空',
        'title.length' => '模型名称长度2-50个字符',
        'description.max' => '模型描述不能超过200个字符',
        'table_name.alphaDash' => '数据表名只能包含字母、数字、下划线',
        'table_name.length' => '数据表名长度2-30个字符',
        'table_name.checkTableName' => '数据表名已存在',
        'status.in' => '状态值不正确',
    ];
    
    protected $scene = [
        'create' => ['name', 'title', 'description', 'table_name', 'status'],
        'edit' => ['title', 'description', 'status'],
    ];
    
    /**
     * 验证模型名称是否唯一
     */
    protected function checkModelName($value, $rule, $data = [], $field = '')
    {
        $query = Db::name('wisecms_model')->where('name', $value);
        
        if (isset($data['id']) && $data['id'] > 0) {
            $query->where('id', '<>', $data['id']);
        }
        
        return $query->count() === 0;
    }
    
    /**
     * 验证表名是否唯一
     */
    protected function checkTableName($value, $rule, $data = [], $field = '')
    {
        // 如果没有传入表名，自动生成
        if (empty($value) && isset($data['name'])) {
            $value = 'wisecms_content_' . $data['name'];
        }
        
        // 检查表是否存在（须带全局前缀，且使用绑定参数替代字符串拼接）
        try {
            $result = Db::query('SHOW TABLES LIKE :table', ['table' => Db::name($value)->getTable()]);
            return empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 验证字段配置
     */
    protected function checkFields($value, $rule, $data = [], $field = '')
    {
        if (!is_array($value)) {
            return false;
        }
        
        $fieldNames = [];
        foreach ($value as $fieldData) {
            // 检查字段名
            if (empty($fieldData['field']) || !preg_match('/^[a-z][a-z0-9_]*$/i', $fieldData['field'])) {
                return '字段标识格式不正确';
            }
            
            // 检查字段名是否重复
            if (in_array($fieldData['field'], $fieldNames)) {
                return '字段标识不能重复';
            }
            
            $fieldNames[] = $fieldData['field'];
            
            // 检查字段名称
            if (empty($fieldData['name']) || mb_strlen($fieldData['name']) > 50) {
                return '字段名称不能为空且不能超过50个字符';
            }
            
            // 检查字段类型
            if (empty($fieldData['form_type'])) {
                return '字段类型不能为空';
            }
        }
        
        return true;
    }
}