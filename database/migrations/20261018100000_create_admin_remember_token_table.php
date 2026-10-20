<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 后台「保持登录」持久化令牌表
 *
 * 设计要点：
 *  - selector / validator 双段式令牌，Cookie 只保存明文，数据库只保存
 *    validator 的 SHA-256 哈希；即使库被拖走也无法直接伪造 Cookie。
 *  - credential_hash 记录签发时 admin.password 的哈希指纹，管理员改密后
 *    所有旧令牌自动失效（无需额外挂钩改密流程）。
 *  - 一个管理员可有多条记录（多浏览器/多设备），logout 只撤销当前这条。
 */
class CreateAdminRememberTokenTable extends Migrator
{
    public function up(): void
    {
        if ($this->hasTable('admin_remember_token')) {
            return;
        }

        $table = $this->table('admin_remember_token', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '后台保持登录令牌表',
        ]);

        $table
            ->addColumn(Column::integer('admin_id')->setUnsigned()->setComment('管理员ID'))
            ->addColumn(Column::string('selector', 32)->setComment('令牌选择器（明文，用于检索）'))
            ->addColumn(Column::string('validator_hash', 64)->setComment('校验器 SHA-256 哈希'))
            ->addColumn(Column::string('credential_hash', 64)->setComment('签发时密码哈希指纹（改密即失效）'))
            ->addColumn(Column::datetime('expires_at')->setComment('过期时间'))
            ->addColumn(Column::datetime('last_used_time')->setNullable()->setComment('最近使用时间'))
            ->addColumn(Column::string('login_ip', 45)->setNullable()->setComment('签发IP'))
            ->addColumn(Column::string('user_agent', 255)->setNullable()->setComment('签发时 UA'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
            ->addIndex(['selector'], ['unique' => true, 'name' => 'uk_selector'])
            ->addIndex(['admin_id'], ['name' => 'idx_admin_id'])
            ->addIndex(['expires_at'], ['name' => 'idx_expires_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('admin_remember_token')) {
            $this->table('admin_remember_token')->drop()->save();
        }
    }
}
