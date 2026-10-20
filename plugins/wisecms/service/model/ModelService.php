<?php
namespace plugin\wisecms\service\model;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\ModelField;
use plugin\wisecms\model\Model;
use think\facade\Db;

class ModelService
{
    /**
     * 创建新内容模型
     */
    public function createModel(array $data): array
    {
        // 1. 验证模型标识
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $data['name'])) {
            throw new WisecmsException('模型标识只能包含小写字母、数字和下划线，且以字母开头', ErrorCode::PARAM_INVALID);
        }
        
        // 2. 检查是否已存在
        $exists = Model::where('name', $data['name'])->find();
        if ($exists) {
            throw new WisecmsException('模型标识已存在', ErrorCode::DUPLICATE);
        }
        
        // 3. 开始事务
        Db::startTrans();
        try {
            // 4. 创建模型记录
            $modelData = [
                'name' => $data['name'],
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'is_system' => 0,
                'status' => (int) ($data['status'] ?? 1),
                // create_time / update_time 由模型 autoWriteTimestamp 维护
            ];
            // 数据表名：表单可留空自动生成；指定时沿用（validate\Model 已校验 alphaDash 与唯一性）。
            // 留空交由 Model::onBeforeInsert() 按 name 生成 wisecms_content_{name}。
            if (!empty($data['table_name'])) {
                $modelData['table_name'] = (string) $data['table_name'];
            }
            
            $model = Model::create($modelData);
            
            // 5. 创建数据表
            $this->createModelTable($model->table_name);
            
            // 6. 添加自定义字段
            if (!empty($data['fields'])) {
                foreach ($data['fields'] as $field) {
                    $this->addField($model->id, $field);
                }
            }
            
            Db::commit();
            
            return [
                'id' => $model->id,
                'name' => $model->name,
                'table_name' => $model->table_name,
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
    
    /**
     * 创建模型的副表
     *
     * D1 存储模型：统一「全局主表 wisecms_content + 每模型 _data 副表」。
     * 系统字段存于全局主表（由 migration 定义），此处仅创建自定义字段落列的 _data 副表。
     */
    protected function createModelTable(string $tableName): void
    {
        $fullDataTable = Db::name($tableName)->getTable() . '_data';

        Db::execute("CREATE TABLE IF NOT EXISTS `{$fullDataTable}` (
            `id` int(10) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容副表'");
    }
    
    /**
     * 添加字段到模型
     */
    public function addField(int $modelId, array $fieldData): bool
    {
        $model = Model::find($modelId);
        if (!$model) {
            throw new WisecmsException('模型不存在', ErrorCode::NOT_FOUND);
        }

        // 字段名必须在落库前校验：validate 的 alphaDash 允许 "-"，但 MySQL 标识符不允许，
        // 否则会出现「字段记录已写入、ALTER TABLE 失败」的脏字段（记录存在但无对应列）
        $fieldName = (string) ($fieldData['field'] ?? '');
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
            throw new WisecmsException('字段名只能以字母或下划线开头，且仅含字母、数字、下划线', ErrorCode::PARAM_INVALID);
        }
        if (ModelField::where('model_id', $modelId)->where('field', $fieldName)->count() > 0) {
            throw new WisecmsException('字段标识已存在', ErrorCode::DUPLICATE);
        }
        
        // 1. 添加字段记录
        $field = ModelField::create([
            'model_id' => $modelId,
            'field' => $fieldData['field'],
            'name' => $fieldData['name'],
            'form_type' => $fieldData['form_type'],
            'data_type' => $fieldData['data_type'] ?? 'varchar',
            'length' => $fieldData['length'] ?? 0,
            'default_value' => $fieldData['default_value'] ?? '',
            'is_system' => $fieldData['is_system'] ?? 0,
            'is_required' => $fieldData['is_required'] ?? 0,
            'is_unique' => $fieldData['is_unique'] ?? 0,
            'is_search' => $fieldData['is_search'] ?? 0,
            'is_fulltext' => $fieldData['is_fulltext'] ?? 0,
            'is_base' => $fieldData['is_base'] ?? 1,
            'setting' => json_encode($fieldData['setting'] ?? [], JSON_UNESCAPED_UNICODE),
            'sort_order' => $fieldData['sort_order'] ?? 0,
            'status' => (int) ($fieldData['status'] ?? 1),
            // create_time / update_time 由模型 autoWriteTimestamp 维护
        ]);
        
        // 2. 动态修改数据表（副表）
        // DDL 失败时回收刚写入的字段记录，避免留下「有记录无列」的脏字段
        try {
            $this->addTableField($model->table_name, $field);
        } catch (\Throwable $e) {
            ModelField::where('id', $field->id)->delete();
            throw $e;
        }

        // 3. 清除字段缓存
        (new FieldRegistry())->clear($modelId);
        
        return true;
    }
    
    /**
     * 动态添加表字段
     */
    protected function addTableField(string $tableName, $field): void
    {
        $dataType = $field->data_type;
        $fieldName = $field->field;
        $defaultValue = $field->default_value ?? '';
        
        // 验证字段名格式（仅允许字母数字下划线），防止 SQL 注入
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
            throw new WisecmsException('字段名包含非法字符', ErrorCode::PARAM_INVALID);
        }
        
        // D1：自定义字段统一落列到副表 _data
        // 必须取「带全局前缀」的真实表名：DDL 语句不经 Query 构造器，不做前缀解析，
        // 直接拼 `{table_name}_data` 会打到无前缀的库表上（1146 Table doesn't exist）
        $actualTableName = Db::name($tableName . '_data')->getTable();
        
        // 构建SQL
        // P1-4：DDL（ALTER TABLE）**不支持参数标记** —— 写成 `DEFAULT :defaultVal` 会被
        // MySQL 当作语法错误（1064 near '?'），且与是否绑定值无关。因此这里必须自行
        // 转义为字符串字面量（见 sqlLiteral()），不能走 Db::execute($sql, $bind)。
        switch ($dataType) {
            case 'varchar':
                $length = (int)($field->length ?: 255);
                $sql = "ALTER TABLE `{$actualTableName}` ADD COLUMN `{$fieldName}` VARCHAR({$length}) NOT NULL DEFAULT " . $this->sqlLiteral((string)$defaultValue);
                Db::execute($sql);
                break;
                
            case 'int':
                $sql = "ALTER TABLE `{$actualTableName}` ADD COLUMN `{$fieldName}` INT NOT NULL DEFAULT " . (int)$defaultValue;
                Db::execute($sql);
                break;
                
            case 'text':
                $sql = "ALTER TABLE `{$actualTableName}` ADD COLUMN `{$fieldName}` TEXT";
                Db::execute($sql);
                break;
                
            case 'mediumtext':
                $sql = "ALTER TABLE `{$actualTableName}` ADD COLUMN `{$fieldName}` MEDIUMTEXT";
                Db::execute($sql);
                break;
                
            case 'datetime':
                $sql = "ALTER TABLE `{$actualTableName}` ADD COLUMN `{$fieldName}` DATETIME";
                Db::execute($sql);
                break;
                
            default:
                $sql = "ALTER TABLE `{$actualTableName}` ADD COLUMN `{$fieldName}` VARCHAR(255) NOT NULL DEFAULT " . $this->sqlLiteral((string)$defaultValue);
                Db::execute($sql);
        }
        
        // 索引管理
        $this->addFieldIndexes($actualTableName, $field);
    }

    /**
     * 把值转义为 MySQL 字符串字面量（含首尾单引号），供 DDL 中的 DEFAULT 使用。
     *
     * 为什么不用参数绑定：`ALTER TABLE ... DEFAULT :name` 是 **DDL**，MySQL 的
     * 预处理语句不覆盖 DDL 中的默认值，占位符会被原样当语法错误抛出（1064）。
     * 反斜杠与单引号同时转义，以兼容 `sql_mode=NO_BACKSLASH_ESCAPES`。
     */
    protected function sqlLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    /**
     * 根据字段配置创建索引（is_search 普通 / is_fulltext 全文 / is_unique 唯一）
     */
    protected function addFieldIndexes(string $actualTableName, $field): void
    {
        $fieldName = $field->field;

        if (!empty($field->is_search)) {
            Db::execute("ALTER TABLE `{$actualTableName}` ADD INDEX `idx_{$fieldName}` (`{$fieldName}`)");
        }
        if (!empty($field->is_fulltext)) {
            try {
                Db::execute("ALTER TABLE `{$actualTableName}` ADD FULLTEXT INDEX `ft_{$fieldName}` (`{$fieldName}`)");
            } catch (\Exception $e) {
                // 全文索引不支持时忽略（如某些存储引擎/版本）
            }
        }
        if (!empty($field->is_unique)) {
            try {
                Db::execute("ALTER TABLE `{$actualTableName}` ADD UNIQUE INDEX `uniq_{$fieldName}` (`{$fieldName}`)");
            } catch (\Exception $e) {
                // 已有重复数据或字段类型（TEXT 等）不支持唯一索引时，交由应用层 validateUnique 兜底
            }
        }
    }

    /**
     * 同步模型的副表结构（存量数据对齐：确保副表存在、补齐缺失列与索引）
     */
    public function syncModelSchema(int $modelId): array
    {
        $model = Model::find($modelId);
        if (!$model || empty($model->table_name)) {
            throw new WisecmsException('模型不存在', ErrorCode::NOT_FOUND);
        }

        $dataTable = $model->table_name . '_data';
        $fullTable = Db::name($dataTable)->getTable();
        Db::execute("CREATE TABLE IF NOT EXISTS `{$fullTable}` (
            `id` int(10) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容副表'");

        $fields = ModelField::where('model_id', $modelId)
            ->where('is_system', 0)
            ->where('status', 1)
            ->select();

        $addedColumns = [];
        foreach ($fields as $field) {
            if (!$this->columnExists($dataTable, $field->field)) {
                $this->addTableField($model->table_name, $field);
                $addedColumns[] = $field->field;
            }
        }

        return ['table' => $dataTable, 'added_columns' => $addedColumns];
    }

    /**
     * 判断表中是否存在某列
     */
    protected function columnExists(string $tableName, string $column): bool
    {
        $fullTable = Db::name($tableName)->getTable();
        $rows = Db::query("SHOW COLUMNS FROM `{$fullTable}`");
        foreach ($rows as $row) {
            if (($row['Field'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * 删除 D1 改造前遗留的孤立空主表（wisecms_content_{name}）
     *
     * 仅删除「空表」且非全局主表、非 _data 副表，用于清理历史遗留。
     * 返回被删除的短表名列表（不含前缀）。
     */
    public function dropOrphanMainTables(): array
    {
        $prefix = (string) Db::getConfig('prefix');
        $like   = addcslashes($prefix . 'wisecms_content_', '\\_%') . '%';

        $rows = Db::query("SHOW TABLES LIKE '{$like}'");

        $dropped = [];
        foreach ($rows as $row) {
            $fullTable = is_array($row) ? reset($row) : $row;
            if (!is_string($fullTable) || $fullTable === '') {
                continue;
            }

            // 跳过 _data 副表
            if (str_ends_with($fullTable, '_data')) {
                continue;
            }

            // 跳过全局主表
            $shortName = substr($fullTable, strlen($prefix));
            if ($shortName === 'wisecms_content') {
                continue;
            }

            // 仅删除空表（遗留主表从未被写入，应为空）
            $count = Db::query("SELECT COUNT(*) AS c FROM `{$fullTable}`");
            if ((int) ($count[0]['c'] ?? 0) !== 0) {
                continue;
            }

            Db::execute("DROP TABLE `{$fullTable}`");
            $dropped[] = $shortName;
        }

        return $dropped;
    }

    /**
     * 更新内容模型
     *
     * name / table_name 决定物理表名，变更需重建整表，因此不在此支持；
     * 仅允许修改展示层字段（title / description / status）。
     */
    public function updateModel(int $id, array $data): array
    {
        $model = Model::find($id);
        if (!$model) {
            throw new WisecmsException('模型不存在', ErrorCode::NOT_FOUND);
        }

        $allowed = ['title', 'description', 'status'];
        $model->save(array_intersect_key($data, array_flip($allowed)));

        return [
            'id'         => (int) $model->id,
            'name'       => $model->name,
            'table_name' => $model->table_name,
        ];
    }

    /**
     * 删除内容模型（含字段定义与副表）
     *
     * 系统模型不允许删除；模型下仍有未删除内容时拒绝删除，避免产生孤儿内容。
     */
    public function deleteModel(int $id): bool
    {
        $model = Model::find($id);
        if (!$model) {
            throw new WisecmsException('模型不存在', ErrorCode::NOT_FOUND);
        }
        if (!empty($model->is_system)) {
            throw new WisecmsException('系统模型不允许删除', ErrorCode::VALIDATE_FAILED);
        }

        // P1-C：**含回收站内容**一并计入 —— 副表会随模型 DROP，回收站里的内容即便恢复
        // 也只剩主表字段、自定义字段永久丢失（原实现只统计未删内容，可绕过该保护）
        $contentCount = \plugin\wisecms\model\Content::withTrashed()
            ->where('model_id', $id)
            ->count();
        if ($contentCount > 0) {
            throw new WisecmsException("该模型下仍有 {$contentCount} 篇内容（含回收站），请先彻底清空", ErrorCode::VALIDATE_FAILED);
        }

        Db::startTrans();
        try {
            // 先删模型（onBeforeDelete 会逐个 DROP COLUMN，必须早于副表整体 DROP）
            $model->delete();
            // 再删副表，避免遗留孤儿表
            $model->deleteTable();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        (new FieldRegistry())->clear($id);

        return true;
    }
}