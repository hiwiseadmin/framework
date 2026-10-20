<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateWisecmsNewsletterTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisecms_newsletter', [
            'engine'=>'InnoDB',
            'comment'=>'CMS邮件订阅表',
            'collation'=>'utf8mb4_general_ci'
        ]);

        $table->addColumn(Column::string('email', 150)->setComment('订阅邮箱'))
            ->addColumn(Column::string('name', 100)->setDefault('')->setComment('订阅人'))
            ->addColumn(Column::string('tags', 255)->setDefault('')->setComment('订阅标签'))
            ->addColumn(Column::string('token', 64)->setDefault('')->setComment('确认/退订令牌'))
            ->addColumn(Column::tinyInteger('status')->setDefault(0)->setComment('0待确认 1已确认 2已退订'))
            ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['token'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('wisecms_newsletter')) {
            $this->table('wisecms_newsletter')->drop()->save();
        }
    }
}
