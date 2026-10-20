<?php
namespace plugin\wisecms\service\model;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\ModelField as FieldModel;
use plugin\wisecms\model\Model as ModelModel;
use think\facade\Db;

/**
 * 模型字段服务类
 * 管理内容模型的自定义字段 CRUD
 */
class ModelFieldService
{
    /**
     * 添加字段
     * 委托 ModelService 执行字段记录创建 + 动态 ALTER TABLE
     */
    public function addField(int $modelId, array $data): bool
    {
        $modelService = new ModelService();
        return $modelService->addField($modelId, $data);
    }

    /**
     * 更新字段
     * 更新字段记录，若数据类型变更则尝试 ALTER TABLE
     */
    public function updateField(int $id, array $data): bool
    {
        $field = FieldModel::find($id);
        if (!$field) {
            throw new WisecmsException('字段不存在', ErrorCode::NOT_FOUND);
        }

        $oldDataType = $field->data_type;
        $oldLength   = $field->length;
        $oldSearch   = (int) $field->is_search;
        $oldFulltext = (int) $field->is_fulltext;
        $oldUnique   = (int) $field->is_unique;

        // 仅允许白名单字段更新：field / model_id / is_system 属于标识列，一经创建不可改写，
        // 否则可被写入任意字符串并拼进后续 DDL（标识符注入）或把字段挂到其它模型上
        $allowed = [
            'name', 'form_type', 'data_type', 'length', 'default_value', 'setting',
            'is_required', 'is_unique', 'is_search', 'is_fulltext', 'is_base',
            'min_value', 'max_value', 'sort_order', 'status',
        ];
        $field->save(array_intersect_key($data, array_flip($allowed)));

        // 任何 DDL 前置校验：字段名必须是合法标识符，杜绝拼接出非法/额外子句
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $field->field)) {
            throw new WisecmsException('字段名包含非法字符', ErrorCode::PARAM_INVALID);
        }

        // 数据类型或长度变更时，同步修改数据库表结构
        $newDataType = $data['data_type'] ?? $oldDataType;
        $newLength   = $data['length'] ?? $oldLength;
        if ($newDataType !== $oldDataType || $newLength !== $oldLength) {
            $this->modifyTableColumn($field, $newDataType, $newLength);
        }

        // 索引标志（is_search / is_fulltext / is_unique）变更时，同步 DROP 旧索引 + ADD 新索引
        $this->syncFieldIndexes($field, $oldSearch, $oldFulltext, $oldUnique, $data);

        return true;
    }

    /**
     * 删除字段
     * 模型事件 onBeforeDelete 会自动执行 DROP COLUMN + 清除缓存
     */
    public function deleteField(int $id): bool
    {
        $field = FieldModel::find($id);
        if (!$field) {
            throw new WisecmsException('字段不存在', ErrorCode::NOT_FOUND);
        }

        $field->delete();
        return true;
    }

    /**
     * 更新字段缓存
     */
    public function updateFieldCache(int $modelId): void
    {
        (new FieldRegistry())->clear($modelId);
    }

    /**
     * 修改数据库表列定义
     */
    protected function modifyTableColumn(FieldModel $field, string $dataType, int $length): void
    {
        $model = ModelModel::find($field->model_id);
        if (!$model) {
            return;
        }

        $actualTable = $model->table_name . '_data';
        $fullTable   = Db::name($actualTable)->getTable();
        $fieldName   = $field->field;

        // 仅支持常见类型的 MODIFY COLUMN
        switch ($dataType) {
            case 'varchar':
                $len = $length ?: 255;
                Db::execute("ALTER TABLE `{$fullTable}` MODIFY COLUMN `{$fieldName}` VARCHAR({$len}) NOT NULL DEFAULT ''");
                break;
            case 'text':
                Db::execute("ALTER TABLE `{$fullTable}` MODIFY COLUMN `{$fieldName}` TEXT");
                break;
            case 'int':
                Db::execute("ALTER TABLE `{$fullTable}` MODIFY COLUMN `{$fieldName}` INT NOT NULL DEFAULT 0");
                break;
            case 'datetime':
                Db::execute("ALTER TABLE `{$fullTable}` MODIFY COLUMN `{$fieldName}` DATETIME");
                break;
            default:
                break;
        }
    }

    /**
     * 同步字段索引：索引标志变更时 DROP 旧索引 + ADD 新索引
     *
     * 仅处理自定义字段（is_system=0，落列于 _data 副表）；系统字段存于全局主表，索引由 migration 维护。
     */
    protected function syncFieldIndexes(FieldModel $field, int $oldSearch, int $oldFulltext, int $oldUnique, array $data): void
    {
        if ((int) $field->is_system === 1) {
            return;
        }

        $model = ModelModel::find($field->model_id);
        if (!$model || empty($model->table_name)) {
            return;
        }

        $fullTable = Db::name($model->table_name . '_data')->getTable();
        $fieldName = $field->field;

        $newSearch   = (int) ($data['is_search'] ?? $oldSearch);
        $newFulltext = (int) ($data['is_fulltext'] ?? $oldFulltext);
        $newUnique   = (int) ($data['is_unique'] ?? $oldUnique);

        if ($oldSearch !== $newSearch) {
            $this->dropIndexIfExists($fullTable, "idx_{$fieldName}");
            if ($newSearch) {
                Db::execute("ALTER TABLE `{$fullTable}` ADD INDEX `idx_{$fieldName}` (`{$fieldName}`)");
            }
        }

        if ($oldFulltext !== $newFulltext) {
            $this->dropIndexIfExists($fullTable, "ft_{$fieldName}");
            if ($newFulltext) {
                try {
                    Db::execute("ALTER TABLE `{$fullTable}` ADD FULLTEXT INDEX `ft_{$fieldName}` (`{$fieldName}`)");
                } catch (\Exception $e) {
                    // 存储引擎不支持全文索引时忽略
                }
            }
        }

        if ($oldUnique !== $newUnique) {
            $this->dropIndexIfExists($fullTable, "uniq_{$fieldName}");
            if ($newUnique) {
                try {
                    Db::execute("ALTER TABLE `{$fullTable}` ADD UNIQUE INDEX `uniq_{$fieldName}` (`{$fieldName}`)");
                } catch (\Exception $e) {
                    // 已有重复数据或字段类型不支持唯一索引时，交由应用层 validateUnique 兜底
                }
            }
        }
    }

    /**
     * 删除指定索引（不存在时忽略）
     */
    protected function dropIndexIfExists(string $fullTable, string $indexName): void
    {
        try {
            Db::execute("ALTER TABLE `{$fullTable}` DROP INDEX `{$indexName}`");
        } catch (\Exception $e) {
            // 索引不存在，忽略
        }
    }
}
