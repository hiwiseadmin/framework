<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWorkflowOrdersTable extends Migrator
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $table = $this->table('workflow_orders', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '工作流演示订单表',
        ]);

        $table->addColumn('title',       'string',   ['limit' => 200, 'default' => '', 'comment' => '订单标题'])
              ->addColumn('description', 'text',     ['null' => true,               'comment' => '订单描述'])
              ->addColumn('amount',      'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0.00, 'comment' => '订单金额'])
              ->addColumn('status',      'string',   ['limit' => 50,  'default' => 'draft', 'comment' => '当前状态（冗余字段）'])
              ->addColumn('admin_id',    'integer',  ['limit' => 11,  'default' => 0, 'comment' => '创建人ID'])
              ->addColumn('create_time',  'integer',  ['limit' => 11,  'null' => true, 'comment' => '创建时间'])
              ->addColumn('update_time',  'integer',  ['limit' => 11,  'null' => true, 'comment' => '更新时间'])
              ->addIndex(['admin_id'], ['name' => 'idx_admin_id'])
              ->addIndex(['status'],   ['name' => 'idx_status'])
              ->addIndex(['create_time'], ['name' => 'idx_create_time'])
              ->create();
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        if ($this->hasTable('workflow_orders')) {
            $this->table('workflow_orders')->drop()->save();
        }
    }
}
