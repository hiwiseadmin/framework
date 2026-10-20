<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 后台仪表盘布局表
 *
 * 作用域规则：
 *  - admin_id > 0              —— 用户个人布局（每个用户一条 default）
 *  - admin_id = 0 & role_id = 0 —— 系统默认模板（seed 一条，新用户无布局时克隆）
 *  - role_id > 0                —— 预留：角色模板维度（暂不启用）
 *
 * 布局持久化一步到位使用独立表；因保存按 admin_id 单行覆盖，无需乐观锁版本号。
 */
class CreateDashboardLayoutsTable extends Migrator
{
    public function up()
    {
        $table = $this->table('dashboard_layouts', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '后台仪表盘布局表',
        ]);

        $table
            ->addColumn(Column::bigInteger('admin_id')->setUnsigned()->setDefault(0)->setComment('管理员ID（0=系统/角色模板）'))
            ->addColumn(Column::integer('role_id')->setUnsigned()->setDefault(0)->setComment('角色ID（预留模板维度，0=未启用）'))
            ->addColumn(Column::string('name', 32)->setDefault('default')->setComment('布局名，默认 default'))
            ->addColumn(Column::text('settings_json')->setNullable()->setComment('面板级设置 {columns,cellHeight,gap}'))
            ->addColumn(Column::longText('layout_json')->setComment('面板列表 JSON（含 settings 与 panels）'))
            ->addColumn(Column::tinyInteger('is_system')->setDefault(0)->setComment('是否系统模板（不可删）'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间（暂未启用软删除，预留）'))
            ->addIndex(['admin_id'], ['name' => 'idx_admin'])
            ->addIndex(['role_id'], ['name' => 'idx_role'])
            ->addIndex(['admin_id', 'role_id', 'name'], ['name' => 'uk_admin_role_name', 'unique' => true])
            ->create();

        // 系统默认模板：新用户无布局时由 DashboardLayoutService 克隆此条
        $this->seedSystemTemplate();
    }

    public function down(): void
    {
        if ($this->hasTable('dashboard_layouts')) {
            $this->table('dashboard_layouts')->drop()->save();
        }
    }

    /**
     * 播种系统默认模板行（layout_json 与 DashboardLayoutService::SYSTEM_TEMPLATE 同构）
     */
    protected function seedSystemTemplate(): void
    {
        $time   = date('Y-m-d H:i:s');
        $layout = [
            'settings' => [
                'columns'    => 12,
                'cellHeight' => 70,
                'margin'     => 12,
            ],
            'panels' => [
                ['uid' => 'tpl.welcome',   'widgetKey' => 'system.welcome',        'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2, 'accent' => 'brand',   'props' => [], 'refresh' => 0, 'hidden' => false],
                ['uid' => 'tpl.shortcuts', 'widgetKey' => 'system.shortcuts',      'x' => 4, 'y' => 0, 'w' => 8, 'h' => 3, 'accent' => '',        'props' => [], 'refresh' => 0, 'hidden' => false],
                ['uid' => 'tpl.status',    'widgetKey' => 'system.server_status',  'x' => 0, 'y' => 2, 'w' => 4, 'h' => 3, 'accent' => 'info',    'props' => [], 'refresh' => 30, 'hidden' => false],
                ['uid' => 'tpl.notify',    'widgetKey' => 'system.notifications',  'x' => 4, 'y' => 3, 'w' => 4, 'h' => 3, 'accent' => 'success', 'props' => [], 'refresh' => 60, 'hidden' => false],
                ['uid' => 'tpl.activity',  'widgetKey' => 'system.activity',       'x' => 8, 'y' => 3, 'w' => 4, 'h' => 3, 'accent' => '',        'props' => [], 'refresh' => 60, 'hidden' => false],
            ],
        ];

        $this->table('dashboard_layouts')
            ->insert([[
                'admin_id'      => 0,
                'role_id'       => 0,
                'name'          => 'default',
                'settings_json' => json_encode($layout['settings'], JSON_UNESCAPED_UNICODE),
                'layout_json'   => json_encode($layout, JSON_UNESCAPED_UNICODE),
                'is_system'     => 1,
                'create_time'   => $time,
                'update_time'   => $time,
                'delete_time'   => null,
            ]])
            ->saveData();
    }
}
