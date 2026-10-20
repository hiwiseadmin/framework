<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * CMS 单页表
 *
 * `site_id`：单页既参与前台展示又需多站点隔离，缺失会导致任意站点通过
 * `?id=N` / `?alias=x` 读到其它站点单页（跨站内容泄露），故建表即带该列。
 *
 * 时间字段约定（全插件统一）：`create_time` / `update_time` / `delete_time` 均为 DATETIME（可空），
 * 前两者由模型 `autoWriteTimestamp` 自动维护。
 */
class CreateWisecmsPageTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_page', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS单页表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::integer('catid')->setSigned(false)->setDefault(0)->setComment('关联栏目'))
            ->addColumn(Column::string('title', 200)->setComment('标题'))
            // 单页 URL 按 alias 解析，站点内必须唯一；可空（唯一索引允许多个 NULL）
            ->addColumn(Column::string('alias', 100)->setNullable()->setComment('别名'))
            ->addColumn(Column::longText('content')->setNullable()->setComment('内容'))
            ->addColumn(Column::string('thumb', 255)->setDefault('')->setComment('缩略图'))
            ->addColumn(Column::string('seo_title', 100)->setDefault('')->setComment('SEO标题'))
            ->addColumn(Column::string('seo_keywords', 255)->setDefault('')->setComment('SEO关键词'))
            ->addColumn(Column::string('seo_description', 500)->setDefault('')->setComment('SEO描述'))
            ->addColumn(Column::string('template', 50)->setDefault('')->setComment('自定义模板'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('状态'))
            ->addColumn(Column::integer('view_count')->setDefault(0)->setComment('浏览量'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['catid'])
            ->addIndex(['site_id'])
            ->addIndex(['site_id', 'alias'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_page')) {
            $this->table('wisecms_page')->drop()->save();
        }
    }
}
