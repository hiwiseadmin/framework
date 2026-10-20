<?php
namespace app\common\model\traits;

use think\facade\Db;

/**
 * 软删除扩展 Trait
 * 
 * @method int getPk() 获取主键字段名
 * @method \think\db\BaseQuery db($scope = []) 获取数据库实例
 * 
 * 在 TP 原生 SoftDelete 基础上提供批量恢复、强制删除等便捷方法。
 *
 * 前置条件：模型必须同时引入 think\model\concern\SoftDelete 和本 Trait。
 *
 * 使用示例：
 * ```php
 * use think\model\concern\SoftDelete;
 * use app\common\model\traits\SoftDeleteTrait;
 *
 * class Article extends BaseModel
 * {
 *     use SoftDelete, SoftDeleteTrait;
 *     protected $deleteTime = 'delete_time';
 * }
 *
 * // 批量恢复
 * Article::restoreByIds([1, 2, 3]);
 *
 * // 按条件批量恢复
 * Article::restoreAll(['status' => 0]);
 *
 * // 批量强制删除
 * Article::forceDeleteByIds([4, 5]);
 *
 * // 判断当前记录是否已软删除
 * $article->isSoftDeleted();
 * ```
 */
trait SoftDeleteTrait
{
    /**
     * 批量恢复软删除记录（按主键 ID）
     *
     * @param array $ids 主键 ID 列表
     * @return int 影响行数
     */
    public static function restoreByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return 0;
        }

        $instance  = new static();
        $table     = $instance->db()->getTable();
        $pk        = $instance->getPk();
        $deleteTime = $instance->getSoftDeleteField();

        $idsStr = implode(',', $ids);

        // 使用原生 SQL 绕过 SoftDelete 的自动过滤
        $sql = "UPDATE `{$table}` SET `{$deleteTime}` = NULL WHERE `{$pk}` IN ({$idsStr})";

        return Db::execute($sql);
    }

    /**
     * 按条件批量恢复软删除记录
     *
     * @param array $where 条件数组
     * @return int 影响行数
     */
    public static function restoreAll(array $where): int
    {
        if (empty($where)) {
            return 0;
        }

        $instance   = new static();
        $table      = $instance->db()->getTable();
        $deleteTime = $instance->getSoftDeleteField();

        // 恢复目标：delete_time 非空的记录（NOT NULL 查询，避免 <> null 恒为假）
        $query = Db::table($table)->whereNotNull($deleteTime);
        foreach ($where as $field => $value) {
            $query->where($field, $value);
        }

        return $query->update([$deleteTime => null]);
    }

    /**
     * 批量强制删除记录（按主键 ID，忽略软删除）
     *
     * @param array $ids 主键 ID 列表
     * @return int 影响行数
     */
    public static function forceDeleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return 0;
        }

        $instance = new static();
        $table    = $instance->db()->getTable();
        $pk       = $instance->getPk();

        $idsStr = implode(',', $ids);

        $sql = "DELETE FROM `{$table}` WHERE `{$pk}` IN ({$idsStr})";

        return Db::execute($sql);
    }

    /**
     * 判断当前模型实例是否已被软删除
     *
     * @return bool
     */
    public function isSoftDeleted(): bool
    {
        $deleteTime = $this->getSoftDeleteField();
        $value      = $this->getData($deleteTime);

        return $value !== null && $value !== '';
    }

    /**
     * 获取软删除字段名（内部辅助方法，避免与 think\model\concern\SoftDelete
     * 的 getDeleteTimeField(bool $read = false) 签名冲突）。
     *
     * @return string
     */
    protected function getSoftDeleteField(): string
    {
        return $this->deleteTime ?: 'delete_time';
    }
}
