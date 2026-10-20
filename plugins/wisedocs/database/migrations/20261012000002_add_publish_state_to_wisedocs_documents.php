<?php

use think\migration\Migrator;
use think\migration\db\Column;
use think\facade\Db;

/**
 * 批次 2：内容生命周期（草稿 → 发布）
 *
 * 1) `wisedocs_documents` 增加两列：
 *    - `publish_state`：0 草稿（从未发布）/ 1 已发布 / 2 待发布（已发布但存在未发布的改动）
 *    - `draft_data`：JSON，保存「已发布文档」的编辑稿（内容类字段）
 *      —— 已发布文档再次保存时，改动进这里而**不覆盖线上内容**，
 *         使「编辑 → 发布」真正分离（前台读的始终是已发布内容，无需改动）。
 *
 * 2) 新建 `wisedocs_document_revisions`：发布快照表
 *    —— 每次发布写一条，作为「发布前差异对比」的基准（也可用于回溯）。
 */
class AddPublishStateToWiseDocsDocuments extends Migrator
{
    public function up(): void
    {
        $docsTable = $this->table('wisedocs_documents');

        if (!$docsTable->hasColumn('publish_state')) {
            $this->table('wisedocs_documents')
                ->addColumn(
                    Column::tinyInteger('publish_state')
                        ->setDefault(0)
                        ->setComment('发布状态:0草稿,1已发布,2待发布')
                )
                ->update();
        }

        if (!$docsTable->hasColumn('draft_data')) {
            $this->table('wisedocs_documents')
                ->addColumn(Column::text('draft_data')->setNullable()->setComment('未发布的编辑稿(JSON)'))
                ->update();
        }

        $tableName = (string) Db::name('wisedocs_documents')->getTable();

        // 索引：拿不到就跳过（不阻断迁移）
        try {
            $this->execute("ALTER TABLE `{$tableName}` ADD INDEX `idx_wisedocs_documents_publish_state` (`publish_state`)");
        } catch (\Throwable $e) {
            // 已存在或引擎不支持，忽略
        }

        // 历史数据：已发布的视为「已发布」，其余为草稿（无待发布态）
        $this->execute(
            "UPDATE `{$tableName}` SET `publish_state` = CASE WHEN `status` = 1 THEN 1 ELSE 0 END"
        );

        if (!$this->hasTable('wisedocs_document_revisions')) {
            $this->table('wisedocs_document_revisions', [
                'engine'    => 'InnoDB',
                'comment'   => '文档发布快照',
                'collation' => 'utf8mb4_general_ci',
            ])
                ->addColumn(Column::integer('document_id')->setComment('文档ID'))
                ->addColumn(Column::string('title', 200)->setComment('标题快照'))
                ->addColumn(Column::text('content')->setComment('正文快照'))
                ->addColumn(Column::string('content_format', 20)->setDefault('html')->setComment('正文格式快照'))
                ->addColumn(Column::string('excerpt', 500)->setNullable()->setComment('摘要快照'))
                ->addColumn(Column::string('cover', 255)->setNullable()->setComment('封面快照'))
                ->addColumn(Column::integer('admin_id')->setNullable()->setComment('发布人'))
                ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
                ->addIndex(['document_id'], ['name' => 'idx_wisedocs_revisions_document'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('wisedocs_document_revisions')) {
            $this->table('wisedocs_document_revisions')->drop()->save();
        }

        $table = $this->table('wisedocs_documents');

        if ($table->hasColumn('draft_data')) {
            $this->table('wisedocs_documents')->removeColumn('draft_data')->update();
        }

        if ($table->hasColumn('publish_state')) {
            $this->table('wisedocs_documents')->removeColumn('publish_state')->update();
        }
    }
}
