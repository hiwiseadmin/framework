<?php
namespace plugin\wisecms\validate;

use think\Validate;
use think\facade\Db;
use plugin\wisecms\service\model\FieldFactory;

class ModelField extends Validate
{
    protected $rule = [
        'model_id' => 'require|integer|gt:0',
        'field' => 'require|alphaDash|length:2,20|checkFieldName',
        'name' => 'require|length:2,50',
        'form_type' => 'require|checkFieldType',
        'data_type' => 'in:varchar,text,mediumtext,int,tinyint,smallint,mediumint,bigint,float,double,decimal,date,datetime,timestamp',
        'length' => 'integer|between:0,65535',
        'default_value' => 'max:255',
        'is_system' => 'in:0,1',
        'is_required' => 'in:0,1',
        'is_unique' => 'in:0,1',
        'is_search' => 'in:0,1',
        'is_fulltext' => 'in:0,1',
        'is_base' => 'in:0,1',
        'min_value' => 'integer',
        'max_value' => 'integer',
        'sort_order' => 'integer',
        'status' => 'in:0,1',
    ];
    
    protected $message = [
        'model_id.require' => '模型ID不能为空',
        'model_id.integer' => '模型ID必须是整数',
        'model_id.gt' => '模型ID必须大于0',
        'field.require' => '字段标识不能为空',
        'field.alphaDash' => '字段标识只能包含字母、数字、下划线',
        'field.length' => '字段标识长度2-20个字符',
        'field.checkFieldName' => '字段标识已存在',
        'name.require' => '字段名称不能为空',
        'name.length' => '字段名称长度2-50个字符',
        'form_type.require' => '字段类型不能为空',
        'form_type.checkFieldType' => '字段类型不存在',
        'data_type.in' => '数据类型不正确',
        'length.integer' => '长度必须是整数',
        'length.between' => '长度范围0-65535',
        'default_value.max' => '默认值不能超过255个字符',
        'is_system.in' => '系统字段值不正确',
        'is_required.in' => '必填字段值不正确',
        'is_unique.in' => '唯一字段值不正确',
        'is_search.in' => '搜索字段值不正确',
        'is_fulltext.in' => '全文索引值不正确',
        'is_base.in' => '基本字段值不正确',
        'min_value.integer' => '最小值必须是整数',
        'max_value.integer' => '最大值必须是整数',
        'sort_order.integer' => '排序必须是整数',
        'status.in' => '状态值不正确',
    ];
    
    protected $scene = [
        'create' => ['model_id', 'field', 'name', 'form_type', 'data_type', 'length', 'default_value', 'is_system', 'is_required', 'is_unique', 'is_search', 'is_fulltext', 'is_base', 'min_value', 'max_value', 'sort_order', 'status'],
        'edit' => ['name', 'data_type', 'length', 'default_value', 'is_required', 'is_unique', 'is_search', 'is_fulltext', 'is_base', 'min_value', 'max_value', 'sort_order', 'status'],
    ];
    
    /**
     * 验证字段名是否唯一
     */
    protected function checkFieldName($value, $rule, $data = [], $field = '')
    {
        $query = Db::name('wisecms_model_field')
            ->where('model_id', $data['model_id'])
            ->where('field', $value);
        
        if (isset($data['id']) && $data['id'] > 0) {
            $query->where('id', '<>', $data['id']);
        }
        
        return $query->count() === 0;
    }
    
    /**
     * 验证字段类型是否存在
     */
    protected function checkFieldType($value, $rule, $data = [], $field = '')
    {
        // FieldFactory 未提供 getFieldConfig()，改用已注册的类型白名单
        return in_array($value, FieldFactory::getAllFieldTypes(), true);
    }
    
    /**
     * 验证字段设置
     */
    protected function checkSetting($value, $rule, $data = [], $field = '')
    {
        if (!is_array($value)) {
            return false;
        }
        
        // 根据字段类型验证设置
        $formType = $data['form_type'] ?? '';
        
        switch ($formType) {
            case 'select':
            case 'radio':
            case 'checkbox':
                if (empty($value['options'])) {
                    return '选项不能为空';
                }
                break;
                
            case 'image':
            case 'file':
                if (isset($value['maxsize']) && $value['maxsize'] <= 0) {
                    return '文件大小必须大于0';
                }
                break;
        }
        
        return true;
    }
    
    /**
     * 验证字段规则
     */
    protected function checkPattern($value, $rule, $data = [], $field = '')
    {
        if (empty($value)) {
            return true;
        }
        
        // 检查正则表达式是否合法
        try {
            $result = preg_match($value, '');
            return $result !== false;
        } catch (\Exception $e) {
            return '正则表达式格式不正确';
        }
    }
}