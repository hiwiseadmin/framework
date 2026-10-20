<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;
use think\facade\Db;

/**
 * 动态字段模型
 *
 * P1-B4：**刻意不使用 SoftDelete**。字段删除会同步 `DROP COLUMN`（见 onBeforeDelete），
 * 记录本身随即失去意义；且表上有 `UNIQUE(model_id, field)`，软删残留行会让「删除后
 * 重新添加同名字段」直接撞唯一键。改用硬删除后，`where()->delete()` 与实例 `delete()`
 * 语义一致（软删模型上的 `where()->delete()` 在本框架下不产生任何 SQL，是空操作）。
 *
 * `delete_time` 列保留（历史结构不动），仅不再参与删除语义。
 */
class ModelField extends BaseModel
{
    // 表名
    protected $name = 'wisecms_model_field';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
        
    // JSON字段
    protected $json = ['setting'];
        
    /**
     * 关联模型
     */
    public function model()
    {
        return $this->belongsTo(Model::class, 'model_id', 'id');
    }
    
    /**
     * 获取设置数组
     */
    public function getSettingArrayAttr($value, $data)
    {
        $setting = $data['setting'] ?? '{}';
        return json_decode($setting, true) ?: [];
    }
    
    /**
     * 获取默认值
     */
    public function getDefaultValueAttr($value, $data)
    {
        if (isset($data['setting'])) {
            $setting = json_decode($data['setting'], true);
            if (isset($setting['defaultvalue'])) {
                return $setting['defaultvalue'];
            }
        }
        return $value;
    }
    
    /**
     * 获取字段验证规则
     */
    public function getValidateRules()
    {
        $rules = [];
        
        if ($this->is_required) {
            $rules[] = 'require';
        }
        
        if ($this->min_value > 0 && $this->data_type == 'int') {
            $rules[] = 'min:' . $this->min_value;
        }
        
        if ($this->max_value > 0 && $this->data_type == 'int') {
            $rules[] = 'max:' . $this->max_value;
        }
        
        if ($this->length > 0 && in_array($this->data_type, ['varchar', 'text'])) {
            $rules[] = 'max:' . $this->length;
        }
        
        if (!empty($this->pattern)) {
            $rules[] = 'regex:' . $this->pattern;
        }
        
        if ($this->is_unique) {
            $rules[] = 'unique:' . $this->getUniqueTable();
        }
        
        return $rules;
    }
    
    /**
     * 获取唯一验证表名
     */
    protected function getUniqueTable()
    {
        $model = $this->model;
        if (!$model) {
            return '';
        }
        
        // D1：自定义字段统一存于 _data 副表
        return $model->table_name . '_data';
    }
    
    /**
     * 获取字段提示
     */
    public function getTips()
    {
        if (!empty($this->errortips)) {
            return $this->errortips;
        }
        
        $tips = [];
        
        if ($this->is_required) {
            $tips[] = '必填';
        }
        
        if ($this->min_value > 0) {
            $tips[] = '最小值：' . $this->min_value;
        }
        
        if ($this->max_value > 0) {
            $tips[] = '最大值：' . $this->max_value;
        }
        
        if ($this->length > 0) {
            $tips[] = '长度限制：' . $this->length . '个字符';
        }
        
        if (!empty($this->pattern)) {
            $tips[] = '格式验证';
        }
        
        return !empty($tips) ? implode('，', $tips) : '';
    }
    
    /**
     * 更新前回调
     */
    public static function onBeforeUpdate($model)
    {
        // 更新缓存
        if ($model->model_id) {
            (new \plugin\wisecms\service\model\FieldRegistry())->clear((int) $model->model_id);
        }
    }
    
    /**
     * 删除前回调
     */
    public static function onBeforeDelete($model)
    {
        // 删除数据库字段（仅自定义字段；系统字段存于全局主表，不随字段记录删除）
        // 防御性校验：字段名必须是合法标识符，避免历史脏数据或被改写的 field 拼接出非法/额外 DDL
        $fieldName = (string) $model->field;
        if ($model->model && $fieldName !== '' && !$model->is_system
            && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
            try {
                $fullTableName = Db::name($model->model->table_name . '_data')->getTable();
                Db::execute("ALTER TABLE `{$fullTableName}` DROP COLUMN `{$fieldName}`");
            } catch (\Exception) {
                // 字段可能不存在，忽略错误
            }
        }
        
        // 更新缓存
        if ($model->model_id) {
            (new \plugin\wisecms\service\model\FieldRegistry())->clear((int) $model->model_id);
        }
    }
}