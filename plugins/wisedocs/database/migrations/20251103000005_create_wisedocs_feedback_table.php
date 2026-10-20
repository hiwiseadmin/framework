<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWiseDocsFeedbackTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_feedback', [
            'engine' => 'InnoDB',
            'comment' => '帮助文档反馈表',
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        // P1：不再 drop 已存在的表（原实现重复执行会删除全部数据）。
        // 表已存在时直接返回，结构变更交由增量迁移维护。
        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::integer('project_id')->setComment('项目ID'))
              ->addColumn(Column::integer('document_id')->setComment('文档ID'))
              ->addColumn(Column::integer('user_id')->setComment('用户ID'))
              ->addColumn(Column::tinyInteger('type')->setComment('反馈类型:1有帮助,0无帮助,2建议'))
              ->addColumn(Column::text('comment')->setComment('反馈内容'))
              ->addColumn(Column::tinyInteger('processed')->setDefault(0)->setComment('处理状态:0待处理,1已处理,2已忽略'))
              ->addColumn(Column::integer('processor_id')->setComment('处理人ID'))
              ->addColumn(Column::datetime('processed_at')->setComment('处理时间'))
              ->addColumn(Column::text('remark')->setComment('处理备注'))
              ->addColumn(Column::string('ip', 45)->setComment('IP地址'))
              ->addColumn(Column::text('user_agent')->setComment('用户代理'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->addIndex('project_id')
              ->addIndex('document_id')
              ->addIndex('user_id')
              ->addIndex('type')
              ->addIndex('processed')
              ->create();

    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_feedback')) {
            $this->table('wisedocs_feedback')->drop()->save();
        }
    }
}
