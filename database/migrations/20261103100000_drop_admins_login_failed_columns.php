<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 删除 admins 表的废弃列 login_failed / last_failed_time
 *
 * 背景
 * ----
 * 后台登录失败计数与锁定已由 wise-login-security 的 `LoginLockManager` 接管，
 * 状态存在其自有 store（`auth_login_attempts` 表 / Redis / 缓存）。
 * `admins` 表这两列**全库无任何读取方**：
 *   - 写入方只有旧的 `admin:clearfailures` 命令（已重写为调用 LoginLockManager）；
 *   - `LoginGuardMiddleware` / `LockCheckAction` / 后台页面均不读取。
 * 保留只会让排查者误以为失败次数存在这里，故删除。
 *
 * 回滚
 * ----
 * 按原建表迁移（20251001000001_create_admins_table）的列定义恢复：
 *   login_failed    tinyint  NOT NULL DEFAULT 0
 *   last_failed_time datetime NULL
 */
class DropAdminsLoginFailedColumns extends Migrator
{
    private const TABLE = 'admins';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }

        foreach (['login_failed', 'last_failed_time'] as $column) {
            $table = $this->table(self::TABLE);

            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
            }
        }
    }

    public function down(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }

        $table = $this->table(self::TABLE);

        if (!$table->hasColumn('login_failed')) {
            $table->addColumn(
                Column::tinyInteger('login_failed')->setDefault(0)->setComment('错误次数：0-正确，其它数字-错误次数')
            )->update();
        }

        $table = $this->table(self::TABLE);

        if (!$table->hasColumn('last_failed_time')) {
            $table->addColumn(
                Column::datetime('last_failed_time')->setNullable()->setComment('最近错误时间')
            )->update();
        }
    }
}
