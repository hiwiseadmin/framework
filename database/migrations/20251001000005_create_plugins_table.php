<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreatePluginsTable extends Migrator
{
    public function up()
    {
        $table = $this->table('plugins', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '插件表'
        ]);

        $table->addColumn(Column::string('name', 100)->setUnique()->setComment('插件名称'))
            ->addColumn(Column::string('ident', 50)->setUnique()->setComment('插件标识(英文唯一)'))
            ->addColumn(Column::string('description', 255)->setDefault('')->setComment('插件描述'))
            ->addColumn(Column::string('author', 50)->setDefault('')->setComment('作者'))
            ->addColumn(Column::string('version', 20)->setDefault('')->setComment('当前版本'))
            ->addColumn(Column::tinyInteger('is_charge')->setDefault(0)->setComment('是否收费 0:免费 1:收费'))
            ->addColumn(Column::string('charge_standard', 1000)->setDefault('[]')->setComment('收费标准(JSON)'))
            ->addColumn(Column::tinyInteger('is_official')->setDefault(0)->setComment('是否官方插件 0:否，1:是'))
            ->addColumn(Column::tinyInteger('is_core')->setDefault(0)->setComment('是否核心插件 0:否，1:是'))
            ->addColumn(Column::tinyInteger('source')->setDefault(0)->setComment('来源 0:本地，1:在线安装，2:开发版，3:Composer'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('状态 0:禁用，1:启用，2:未安装'))
            ->addColumn(Column::integer('category_id')->setDefault(0)->setComment('分类ID'))
            ->addColumn(Column::string('url', 255)->setDefault('')->setComment('插件地址'))
            ->addColumn(Column::string('doc_url', 255)->setDefault('')->setComment('文档地址'))
            ->addColumn(Column::string('config', 1000)->setDefault('[]')->setComment('配置信息(JSON)'))
            ->addColumn(Column::tinyInteger('editor_provider')->setDefault(0)->setComment('是否为编辑器提供者 0:否 1:是'))
            ->addColumn(Column::string('editor_types', 255)->setDefault('[]')->setComment('编辑器类型列表(JSON数组)'))
            ->addColumn(Column::datetime('install_time')->setComment('安装时间'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addIndex(['name'], ['unique' => true])
            ->addIndex(['ident'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('plugins')) {
            $this->table('plugins')->drop()->save();
        }
    }
}