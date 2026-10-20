<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWiseDocsStatsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_stats', [
            'engine' => 'InnoDB',
            'comment' => '帮助文档统计表',
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        // P1：不再 drop 已存在的表（原实现重复执行会删除全部数据）。
        // 表已存在时直接返回，结构变更交由增量迁移维护。
        if ($table->exists()) {
            return;
        }
// CREATE TABLE IF NOT EXISTS `help_document_stats` (
//   `id` int(11) NOT NULL AUTO_INCREMENT,
//   `document_id` int(11) NOT NULL COMMENT '文档ID',
//   `date` date NOT NULL COMMENT '统计日期',
//   `view_count` int(11) DEFAULT 0 COMMENT '当日浏览次数',
//   `helpful_count` int(11) DEFAULT 0 COMMENT '当日有帮助次数',
//   `unhelpful_count` int(11) DEFAULT 0 COMMENT '当日无帮助次数',
//   `share_count` int(11) DEFAULT 0 COMMENT '分享次数',
//   `total_view_count` int(11) DEFAULT 0 COMMENT '累计浏览',
//   `total_helpful_count` int(11) DEFAULT 0 COMMENT '累计有帮助',
//   `total_unhelpful_count` int(11) DEFAULT 0 COMMENT '累计无帮助',
//   `create_time` datetime DEFAULT NULL,
//   `update_time` datetime DEFAULT NULL,
//   PRIMARY KEY (`id`),
//   UNIQUE KEY `idx_document_date` (`document_id`, `date`),
//   KEY `idx_date` (`date`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档统计表';
        $table->addColumn(Column::integer('project_id')->setComment('项目ID'))
              ->addColumn(Column::integer('document_id')->setComment('文档ID'))
              ->addColumn(Column::date('date')->setComment('统计日期'))
              ->addColumn(Column::integer('view_count')->setComment('当日浏览次数'))
              ->addColumn(Column::integer('helpful_count')->setComment('当日有帮助次数'))
              ->addColumn(Column::integer('unhelpful_count')->setComment('当日无帮助次数'))
              ->addColumn(Column::integer('share_count')->setComment('分享次数'))
              ->addColumn(Column::integer('total_view_count')->setComment('累计浏览'))
              ->addColumn(Column::integer('total_helpful_count')->setComment('累计有帮助'))
              ->addColumn(Column::integer('total_unhelpful_count')->setComment('累计无帮助'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->addIndex('project_id')
              ->addIndex(['document_id', 'date'], ['unique' => true, 'name' => 'uniq_wisedocs_stats_doc_date'])
              ->create();

    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_stats')) {
            $this->table('wisedocs_stats')->drop()->save();
        }
    }
}
