<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsTagRelationTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_tag_relation', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS标签关联表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('tag_id')->setSigned(false)->setComment('标签ID'))
            ->addColumn(Column::integer('content_id')->setSigned(false)->setComment('内容ID'))
            // 站点冗余列（P2）：标签关联恒与内容同站（写入侧统一由内容站点决定），
            // 冗余后「按站点重算引用数 / 删除站点级联清理关联」无需再 JOIN 内容表。
            ->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::integer('model_id')->setSigned(false)->setDefault(0)->setComment('模型ID'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['tag_id', 'content_id'], ['unique' => true])
            ->addIndex(['content_id'])
            // 站点隔离（P2）：按站点统计某标签的引用数（recountTags 的 GROUP BY 前置条件）
            ->addIndex(['site_id', 'tag_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_tag_relation')) {
            $this->table('wisecms_tag_relation')->drop()->save();
        }
    }
}
