<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 plugin_config 表。
 *
 * 此迁移创建 DatabaseConfigDriver 用于以键值对形式
 * 存储插件配置的表。
 */
class CreatePluginConfigTable extends Migrator
{
    /**
     * 表名。
     *
     * @var string
     */
    protected string $tableName = 'plugin_config';

    /**
     * 创建 plugin_config 表。
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table($this->tableName, [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '插件配置存储',
        ]);

        $table
            ->addColumn(Column::string('plugin_name', 100)->setNull(false)->setComment('插件名称'))
            ->addColumn(Column::string('key', 200)->setNull(false)->setComment('配置键'))
            ->addColumn(Column::text('value')->setNull(true)->setComment('配置值（JSON 编码）'))
            ->addColumn(Column::dateTime('create_time')->setNull(false)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(false)->setComment('更新时间'))
            ->addIndex(['plugin_name', 'key'], [
                'unique' => true,
                'name'   => 'idx_plugin_config_unique',
            ])
            ->addIndex(['plugin_name'], [
                'name' => 'idx_plugin_config_plugin',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->tableName)) {
            $this->table($this->tableName)->drop()->save();
        }
    }
}
