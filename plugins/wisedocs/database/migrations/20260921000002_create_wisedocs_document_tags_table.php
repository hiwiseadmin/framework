<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建文档-标签关联表。
 *
 * 项目尚未发布：原「将 documents.tags 逗号串回填到关联表」的存量迁移逻辑，
 * 已随 documents.tags 遗留列的移除一并删除；新装直接使用关联表。
 */
class CreateWiseDocsDocumentTagsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_document_tags', [
            'engine'    => 'InnoDB',
            'comment'   => '文档-标签关联表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('document_id')->setComment('文档ID'))
              ->addColumn(Column::integer('tag_id')->setComment('标签ID'))
              ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
              ->addIndex(['document_id'], ['name' => 'idx_wisedocs_dt_document'])
              ->addIndex(['tag_id'], ['name' => 'idx_wisedocs_dt_tag'])
              ->addIndex(['document_id', 'tag_id'], ['unique' => true, 'name' => 'uniq_wisedocs_dt'])
              ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_document_tags')) {
            $this->table('wisedocs_document_tags')->drop()->save();
        }
    }
}
