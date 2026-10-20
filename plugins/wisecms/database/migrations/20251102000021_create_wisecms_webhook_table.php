<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsWebhookTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_webhook', [
            'engine'=>'InnoDB',
            'comment'=>'CMS Webhook表',
            'collation'=>'utf8mb4_general_ci'
        ]);
        
        $table->addColumn(Column::string('name',100)->setComment('名称'))
            ->addColumn(Column::string('url',500)->setComment('回调URL'))
            ->addColumn(Column::string('events',500)->setComment('触发事件(逗号分隔)'))
            ->addColumn(Column::string('secret',64)->setDefault('')->setComment('HMAC密钥'))
            ->addColumn(Column::tinyInteger('max_retries')->setDefault(3))
            ->addColumn(Column::tinyInteger('retry_count')->setDefault(0))
            ->addColumn(Column::tinyInteger('last_status')->setNullable())
            ->addColumn(Column::tinyInteger('status')->setDefault(1))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_webhook')) {
            $this->table('wisecms_webhook')->drop()->save();
        }
    }
}
