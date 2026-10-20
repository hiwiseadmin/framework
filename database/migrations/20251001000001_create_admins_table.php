<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAdminsTable extends Migrator
{
    public function up()
    {
        $table = $this->table('admins', [
            'engine' => 'InnoDB',
            'comment' => '后台管理员表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table->addColumn(Column::string('username', 50)->setUnique()->setComment('用户名'))
              ->addColumn(Column::string('password', 255)->setComment('密码'))
              ->addColumn(Column::string('nickname', 50)->setComment('昵称'))
              ->addColumn(Column::string('email', 100)->setNullable()->setComment('邮箱'))
              ->addColumn(Column::string('mobile', 20)->setNullable()->setComment('手机号'))
              ->addColumn(Column::string('third_party_auth', 255)->setNullable()->setComment('第三方认证'))
              ->addColumn(Column::string('avatar', 255)->setNullable()->setComment('头像'))
              ->addColumn(Column::tinyInteger('login_failed')->setDefault(0)->setComment('错误次数：0-正确，其它数字-错误次数'))
              ->addColumn(Column::datetime('last_failed_time')->setNullable()->setComment('最近错误时间'))
              ->addColumn(Column::string('ui_settings', 255)->setNullable()->setComment('UI设置'))
              ->addColumn(Column::text('ext_fields')->setNullable()->setComment('扩展字段'))
              ->addColumn(Column::datetime('last_login_time')->setNullable()->setComment('最后登录时间'))
              ->addColumn(Column::string('last_login_ip', 50)->setNullable()->setComment('最后登录IP'))
              ->addColumn(Column::integer('member_id')->setNullable()->setComment('绑定会员ID'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用，1:正常'))
              ->addColumn(Column::string('description', 255)->setNullable()->setComment('备注'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
              ->create();

        // 初始管理员数据
        // $this->insertDefaultAdmin($table);
    }

    public function down(): void
    {
        if ($this->hasTable('admins')) {
            $this->table('admins')->drop()->save();
        }
    }
}
