<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsTemplateTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_template', [
            'engine' => 'InnoDB', 'comment' => 'CMS内容模板表', 'collation' => 'utf8mb4_general_ci',
        ]);
        $table->addColumn(Column::string('name', 100)->setComment('模板名称'))
            ->addColumn(Column::string('alias', 50)->setComment('模板标识'))
            ->addColumn(Column::string('type', 20)->setDefault('article')->setComment('article/page/product/custom'))
            ->addColumn(Column::string('category', 30)->setDefault('')->setComment('模板分类'))
            ->addColumn(Column::string('icon', 50)->setDefault('')->setComment('图标'))
            ->addColumn(Column::longText('schema')->setNullable()->setComment('模板结构Schema(JSON)'))
            ->addColumn(Column::text('presets')->setNullable()->setComment('预设内容(JSON)'))
            ->addColumn(Column::text('seo_defaults')->setNullable()->setComment('SEO默认值(JSON)'))
            ->addColumn(Column::tinyInteger('is_system')->setDefault(0)->setComment('系统模板'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['alias'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_template')) {
            $this->table('wisecms_template')->drop()->save();
        }
    }
}
