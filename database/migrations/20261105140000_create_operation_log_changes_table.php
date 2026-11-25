<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 字段级变更表＼�
 *
 * 一行 = 一个字段的一次变化；同一次保存的多行用 `change_set` 聚合，
 * 时间线按 `change_set` 分组展示，回滚也以 `change_set` 为单位。
 *
 * `log_id` 为弱关联（operation_log 的 id 在 flush 后才有），仅便于交叉排查；
 * 时间线聚合与查询一律走 `change_set` / `model + pk_value`。
 */
class CreateOperationLogChangesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('operation_log_changes', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '字段级变更记录表',
        ]);

        $table->addColumn(Column::char('change_set', 32)->setNull(false)->setDefault('')->setComment('变更组标识（一次保存为一组）'))
            ->addColumn(Column::integer('log_id')->setNull(true)->setSigned(false)->setComment('关联操作日志ID（弱关联，可空）'))
            ->addColumn(Column::string('model', 150)->setNull(false)->setDefault('')->setComment('模型全类名'))
            ->addColumn(Column::string('model_label', 50)->setNull(false)->setDefault('')->setComment('模型展示名'))
            ->addColumn(Column::string('pk_field', 50)->setNull(false)->setDefault('id')->setComment('主键字段名'))
            ->addColumn(Column::string('pk_value', 100)->setNull(false)->setDefault('')->setComment('主键值'))
            ->addColumn(Column::string('title', 255)->setNull(false)->setDefault('')->setComment('行标题快照'))
            ->addColumn(Column::string('action', 20)->setNull(false)->setDefault('update')->setComment('动作：update/create/rollback'))
            ->addColumn(Column::string('field', 100)->setNull(false)->setDefault('')->setComment('变更字段'))
            ->addColumn(Column::string('field_label', 100)->setNull(false)->setDefault('')->setComment('字段中文名'))
            ->addColumn(Column::text('old_value')->setNull(true)->setComment('变更前值（NULL 表示原值为空）'))
            ->addColumn(Column::text('new_value')->setNull(true)->setComment('变更后值'))
            ->addColumn(Column::boolean('truncated')->setNull(false)->setDefault(0)->setComment('是否发生截断（1=不可回滚）'))
            ->addColumn(Column::string('source', 20)->setNull(false)->setDefault('model')->setComment('来源：model/rollback'))
            ->addColumn(Column::integer('operator_id')->setNull(true)->setSigned(false)->setComment('操作者ID'))
            ->addColumn(Column::string('operator_name', 100)->setNull(false)->setDefault('')->setComment('操作者名称快照'))
            ->addColumn(Column::string('ip', 50)->setNull(false)->setDefault('')->setComment('请求IP'))
            ->addColumn(Column::string('route', 255)->setNull(false)->setDefault('')->setComment('请求路径'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addIndex(['change_set'], ['name' => 'idx_change_set'])
            ->addIndex(['model', 'pk_value', 'create_time'], ['name' => 'idx_model_pk'])
            ->addIndex(['log_id'], ['name' => 'idx_log'])
            ->addIndex(['operator_id', 'create_time'], ['name' => 'idx_operator_time'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('operation_log_changes')) {
            $this->table('operation_log_changes')->drop()->save();
        }
    }
}
