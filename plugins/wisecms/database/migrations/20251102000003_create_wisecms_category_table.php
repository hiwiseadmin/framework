<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsCategoryTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_category', [
            'engine'    => 'InnoDB',
            'comment'   => 'CMS栏目表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $table->addColumn(Column::integer('site_id')->setSigned(false)->setDefault(1)->setComment('站点ID'))
            ->addColumn(Column::integer('parent_id')->setSigned(false)->setDefault(0)->setComment('父级ID'))
            ->addColumn(Column::integer('model_id')->setSigned(false)->setDefault(0)->setComment('绑定模型'))
            ->addColumn(Column::string('name', 50)->setComment('栏目名称'))
            // 别名用于前台 URL 解析，站点内必须唯一；可空（MySQL 唯一索引允许多个 NULL，
            // 避免多个「未填别名」的栏目互相冲突）
            ->addColumn(Column::string('alias', 50)->setNullable()->setComment('别名(URL)'))
            ->addColumn(Column::tinyInteger('type')->setDefault(0)->setComment('类型:0内部 1单页 2外链'))
            ->addColumn(Column::string('url', 255)->setDefault('')->setComment('外链URL'))
            ->addColumn(Column::string('image', 255)->setDefault('')->setComment('栏目图片'))
            ->addColumn(Column::string('description', 500)->setDefault('')->setComment('描述'))
            ->addColumn(Column::text('setting')->setNullable()->setComment('扩展设置(JSON)'))
            ->addColumn(Column::string('seo_title', 100)->setDefault('')->setComment('SEO标题'))
            ->addColumn(Column::string('seo_keywords', 255)->setDefault('')->setComment('SEO关键词'))
            ->addColumn(Column::string('seo_description', 500)->setDefault('')->setComment('SEO描述'))
            ->addColumn(Column::string('og_image', 500)->setDefault('')->setComment('栏目默认OG图'))
            ->addColumn(Column::string('canonical_url', 500)->setDefault('')->setComment('栏目Canonical'))
            ->addColumn(Column::tinyInteger('noindex')->setDefault(0)->setComment('禁止索引'))
            ->addColumn(Column::tinyInteger('nofollow')->setDefault(0)->setComment('禁止追踪'))
            ->addColumn(Column::string('template_index', 50)->setDefault('')->setComment('栏目页模板'))
            ->addColumn(Column::string('template_list', 50)->setDefault('')->setComment('列表页模板'))
            ->addColumn(Column::string('template_show', 50)->setDefault('')->setComment('详情页模板'))
            ->addColumn(Column::integer('list_rows')->setDefault(15)->setComment('每页条数'))
            ->addColumn(Column::tinyInteger('is_nav')->setDefault(1)->setComment('导航显示'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('软删除时间'))
            ->addIndex(['site_id'])
            ->addIndex(['site_id', 'alias'], ['unique' => true])
            ->addIndex(['parent_id'])
            ->addIndex(['model_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_category')) {
            $this->table('wisecms_category')->drop()->save();
        }
    }
}
