<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;
use think\facade\Db;

/**
 * 内容模型（动态建模）
 *
 * P1-B4：**刻意不使用 SoftDelete**。删除模型会同步 DROP 副表（见 deleteTable()），
 * 记录本身随即失去意义；且表上有 `UNIQUE(name)`，软删残留行会让「删除后重建同名模型」
 * 直接撞唯一键。改用硬删除后，`where()->delete()` 与实例 `delete()` 语义一致
 * （软删模型上的 `where()->delete()` 在本框架下不产生任何 SQL，是空操作）。
 *
 * `delete_time` 列保留（历史结构不动），仅不再参与删除语义。
 */
class Model extends BaseModel
{
    // 表名
    protected $name = 'wisecms_model';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';
    
    /**
     * 关联字段
     */
    public function fields()
    {
        return $this->hasMany(ModelField::class, 'model_id', 'id')
            ->where('status', 1)
            ->order('sort_order', 'asc');
    }
    
    /**
     * 获取系统字段
     */
    public function getSystemFields()
    {
        return $this->fields()->where('is_system', 1)->select();
    }
    
    /**
     * 获取模型字段
     */
    public function getModelFields()
    {
        return $this->fields()->where('is_system', 0)->select();
    }
    
    /**
     * 获取基本字段
     */
    public function getBaseFields()
    {
        return $this->fields()->where('is_base', 1)->select();
    }
    
    /**
     * 获取高级字段
     */
    public function getSeniorFields()
    {
        return $this->fields()->where('is_base', 0)->select();
    }
    
    /**
     * 创建前回调
     */
    public static function onBeforeInsert(Model $model)
    {
        if (empty($model->table_name) && !empty($model->name)) {
            // table_name 存储不含全局前缀的动态数据表名，与 Db::name() 约定一致
            $shortName = str_replace('wisecms_', '', $model->name);
            $model->table_name = 'wisecms_content_' . $shortName;
        }
    }
    
    /**
     * 删除前回调
     */
    public static function onBeforeDelete(Model $model)
    {
        // 删除关联字段
        ModelField::where('model_id', $model->id)->delete();
    }
    
    /**
     * 删除数据表
     */
    public function deleteTable(): bool
    {
        try {
            // D1：仅删除 _data 副表（系统字段存于全局主表，不随模型删除）
            $dataTable = Db::name($this->table_name)->getTable() . '_data';
            Db::execute("DROP TABLE IF EXISTS `{$dataTable}`");
            return true;
        } catch (\Exception) {
            return false;
        }
    }
    
    /**
     * 获取模型状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? 0;
        $statusArr = [0 => '禁用', 1 => '启用'];
        return $statusArr[$status] ?? '未知';
    }
    
    /**
     * 获取是否为系统模型文本
     */
    public function getIsSystemTextAttr($value, $data)
    {
        $isSystem = $data['is_system'] ?? 0;
        return $isSystem ? '系统模型' : '自定义模型';
    }
}

