<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWiseDocsCategoriesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_categories', [
            'engine' => 'InnoDB',
            'comment' => '文档分类表',
            'collation' => 'utf8mb4_general_ci',
        ]);
        
        // P1：不再 drop 已存在的表（原实现重复执行会删除全部数据）。
        // 表已存在时直接返回，结构变更交由增量迁移维护。
        if ($table->exists()) {
            return;
        }

//         CREATE TABLE IF NOT EXISTS `help_categories` (
//   `id` int(11) NOT NULL AUTO_INCREMENT,
//   `parent_id` int(11) DEFAULT 0 COMMENT '父级ID',
//   `level` tinyint(1) DEFAULT 1 COMMENT '层级深度',
//   `path` varchar(255) DEFAULT '' COMMENT '分类路径',
//   `name` varchar(100) NOT NULL COMMENT '分类名称',
//   `slug` varchar(100) NOT NULL COMMENT 'URL标识',
//   `description` text COMMENT '分类描述',
//   `icon` varchar(50) DEFAULT 'bi-question-circle' COMMENT '图标类名',
//   `sort_order` int(11) DEFAULT 0 COMMENT '排序',
//   `status` tinyint(1) DEFAULT 1 COMMENT '状态:1启用,0禁用',
//   `create_time` datetime DEFAULT NULL,
//   `update_time` datetime DEFAULT NULL,
//   PRIMARY KEY (`id`),
//   UNIQUE KEY `slug` (`slug`),
//   KEY `parent_id` (`parent_id`),
//   KEY `status` (`status`)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帮助分类表';
        $table->addColumn(Column::integer('project_id')->setComment('项目ID'))
              ->addColumn(Column::integer('parent_id')->setComment('父级ID'))
              ->addColumn(Column::tinyInteger('level')->setDefault(1)->setComment('层级深度'))
              ->addColumn(Column::string('path', 255)->setComment('分类路径'))
              ->addColumn(Column::string('name', 100)->setComment('分类名称'))
              ->addColumn(Column::string('slug', 100)->setComment('URL标识'))
              ->addColumn(Column::text('description')->setNullable()->setComment('分类描述'))
              ->addColumn(Column::string('icon', 50)->setDefault('bi-question-circle')->setComment('图标类名'))
              ->addColumn(Column::integer('sort_order')->setDefault(0)->setComment('排序'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态:1启用,0禁用'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->addIndex('project_id')
              ->addIndex('parent_id')
              ->addIndex('status')
              ->create();

    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_categories')) {
            $this->table('wisedocs_categories')->drop()->save();
        }
    }
}
