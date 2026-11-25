<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateStorageConfigTables extends Migrator
{
    public function up()
    {
        $table = $this->table('storage_config', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '存储配置表'
        ]);
        
        $table->addColumn(Column::string('name', 100)->setComment('配置名称'))
            ->addColumn(Column::string('driver', 20)->setComment('存储类型'))
            ->addColumn(Column::string('config', 1000)->setComment('配置信息(JSON)'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用;1:正常'))
            ->addColumn(Column::tinyInteger('is_default')->setDefault(1)->setComment('是否默认 0:否;1:是'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
            ->create();
        
        $data = [
            'name' => '本地存储',
            'driver' => 'local',
            'config' => json_encode(['root' => 'uploads', 'url' => '/uploads']),
            'status' => 1,
            'is_default' => 1,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
            'delete_time' => null,
        ];
        
        $table->insert($data)->save();
    }

    public function down(): void
    {
        if ($this->hasTable('storage_config')) {
            $this->table('storage_config')->drop()->save();
        }
    }
}