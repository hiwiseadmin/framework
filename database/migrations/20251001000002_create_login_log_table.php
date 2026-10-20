<?php
use think\migration\Migrator;
use think\migration\db\Column;

class CreateLoginLogTable extends Migrator
{
    /**
    * Change Method.
    *
    * Write your reversible migrations using this method.
    *
    * More information on writing migrations is available here:
    * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
    *
    * The following commands can be used in this method and Phinx will
    * automatically reverse them when rolling back:
    *
    * createTable
    * renameTable
    * addColumn
    * renameColumn
    * addIndex
    * addForeignKey
    *
    * Remember to call "create()" or "update()" and NOT "save()" when working
    * with the Table class.
    */
    public function up()
    {
        $table = $this->table('login_log',[
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '登录日志表'
        ]);

        $table->addColumn(Column::integer('admin_id')->setComment('用户ID'))
            ->addColumn(Column::string('username', 50)->setComment('用户名'))
            ->addColumn(Column::string('login_ip', 45)->setComment('登录IP'))
            ->addColumn(Column::string('location', 100)->setComment('登录地点'))
            ->addColumn(Column::string('browser', 100)->setComment('浏览器'))
            ->addColumn(Column::string('os', 50)->setComment('操作系统'))
            ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('登录状态:1成功,0失败'))
            ->addColumn(Column::string('message', 255)->setNullable()->setComment('提示消息'))
            ->addColumn(Column::string('session_id', 64)->setNullable()->setComment('会话ID'))
            ->addColumn(Column::tinyInteger('security_level')->setDefault(2)->setComment('安全等级:1低 2中 3高，默认:2'))
            ->addColumn(Column::tinyInteger('is_trusted_device')->setDefault(0)->setComment('是否受信任设备:1是 0否'))
            ->addColumn(Column::string('device_fingerprint', 64)->setNullable()->setComment('设备指纹'))
            ->addColumn(Column::datetime('login_time')->setComment('登录时间'))
            ->addIndex(['admin_id'])
            ->addIndex(['login_time'])
            ->addIndex(['device_fingerprint'])
            ->addIndex(['login_ip'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('login_log')) {
            $this->table('login_log')->drop()->save();
        }
    }
}