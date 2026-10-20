<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsSiteTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_site', [
            'engine'=>'InnoDB',
            'comment'=>'CMS多站点表',
            'collation'=>'utf8mb4_general_ci'
        ]);

        $table->addColumn(Column::string('name', 100)->setDefault('')->setComment('站点名称'))
            ->addColumn(Column::string('domain', 255)->setDefault('')->setComment('站点域名'))
            ->addColumn(Column::string('logo', 255)->setDefault('')->setComment('站点LOGO'))
            ->addColumn(Column::text('setting')->setNullable()->setComment('站点设置(JSON)'))
            ->addColumn(Column::text('seo_setting')->setNullable()->setComment('SEO设置(JSON)'))
            ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['domain'])
            ->addIndex(['status', 'sort'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_site')) {
            $this->table('wisecms_site')->drop()->save();
        }
    }
}
