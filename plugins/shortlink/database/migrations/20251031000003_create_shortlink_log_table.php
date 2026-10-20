<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateShortlinkLogTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('shortlink_log', [
            'engine'    => 'InnoDB',
            'comment'   => '短链接访问日志表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::integer('link_id')->setComment('短链接ID'))
              ->addColumn(Column::string('code', 32)->setComment('短码'))
              ->addColumn(Column::string('ip', 50)->setNullable()->setComment('访问IP'))
              ->addColumn(Column::string('ua', 500)->setNullable()->setComment('User-Agent'))
              ->addColumn(Column::string('referer', 500)->setNullable()->setComment('来源页面'))
              ->addColumn(Column::string('country', 100)->setNullable()->setComment('国家'))
              ->addColumn(Column::string('province', 100)->setNullable()->setComment('省份'))
              ->addColumn(Column::string('city', 100)->setNullable()->setComment('城市'))
              ->addColumn(Column::string('device', 50)->setNullable()->setComment('设备类型'))
              ->addColumn(Column::string('browser', 50)->setNullable()->setComment('浏览器'))
              ->addColumn(Column::string('os', 50)->setNullable()->setComment('操作系统'))
              ->addColumn(Column::datetime('create_time')->setComment('访问时间'))
              ->addIndex(['link_id'])
              ->addIndex(['code'])
              ->addIndex(['link_id', 'create_time'])  // 组合索引，优化趋势查询和分页
              ->addIndex(['create_time'])
              ->addIndex(['ip'])
              ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('shortlink_log')) {
            $this->table('shortlink_log')->drop()->save();
        }
    }
}
