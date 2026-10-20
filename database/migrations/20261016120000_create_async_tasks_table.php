<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 异步任务注册表 async_tasks + 后台「任务中心」菜单/权限
 *
 * ── 为什么需要这张表 ────────────────────────────────────────────────────
 * think-queue 只提供「投递 / 领取 / 重试」的传输语义：
 *   - database 驱动下 `jobs` 表只有 queue/payload/attempts/reserve_time/... ，
 *     **没有任何 status/progress 字段**，任务成功后整行被删除，连历史都不留；
 *   - Worker 的 `$paused` 字段虽然接了 SIGUSR2，但 daemon 主循环从不读取它
 *     （见 vendor/topthink/think-queue/src/queue/Worker.php:80-98、226-232），
 *     故官方「暂停」在本版本是死代码；
 *   - 没有任何「按任务查进度 / 停止某个任务」的控制通道。
 *
 * 因此进度与停止能力**必须由一张独立于 jobs 的注册表承担**：
 * 队列依旧只负责投递，控制面（进度 / 取消标记）全部落在本表。
 *
 * ── 与 jobs 表的分工 ───────────────────────────────────────────────────
 *   jobs / failed_jobs  = think-queue 原生，传输层，随时被驱动改写；
 *   async_tasks         = 业务层注册表，与驱动（database / redis）无关。
 *
 * ── 状态机 ─────────────────────────────────────────────────────────────
 *   pending → running → success | failed | canceled
 *                     ↳ paused → running（Phase 2 预留）
 *   running 且心跳超时 → stale（由清理命令回收）
 *
 * ── 菜单/权限补种说明 ───────────────────────────────────────────────────
 * 内置菜单历史写法是在 create_menus_table 迁移里一次性种子；该迁移早已执行完毕，
 * 对存量站点不再生效，故此处**独立补种**（幂等：Menu::createMenus 按
 * plugin+parent_id+path 复用/恢复软删项）。
 * 权限节点走与安装期完全相同的路径（Menu::syncPermissionForMenu →
 * MenuPermissionBridge），并按 menu_permission.php 的 path_to_dot 策略生成 ident。
 *
 * ⚠️ path_suffix 方法推断只认 save/update/delete/remove/store/create/edit，
 * 故 cancel / retry / clear 会被推断为 GET，与实际 POST 请求不匹配导致
 * 非超管 403（whitelist 模式）。这里显式纠正（见 $methodOverrides）。
 */
class CreateAsyncTasksTable extends Migrator
{
    /**
     * 任务中心菜单声明（父级为下方新建的「系统工具」目录）
     *
     * @var array<int, array<string, mixed>>
     */
    private array $menuItems = [
        ['name' => '任务中心', 'icon' => 'bi bi-list-task', 'path' => '/admin/async_task/index',    'type' => 'menu',   'sort' => 0],
        // ⚠️ 动作名一律小写：`RbacMiddleware::getDispatchedRoute()` 在后置场景会把
        //    controller/action 全部小写，若此处声明 camelCase 的 getList，会出现
        //    「预分派路径匹配、分派后路径不匹配」的大小写陷阱。框架自身种子
        //    （/admin/admin/getlist 等）同样使用小写，此处对齐该约定。
        ['name' => '任务列表', 'path' => '/admin/async_task/getlist',  'type' => 'button'],
        ['name' => '任务详情', 'path' => '/admin/async_task/detail',   'type' => 'button'],
        ['name' => '查询进度', 'path' => '/admin/async_task/progress', 'type' => 'button'],
        ['name' => '队列健康', 'path' => '/admin/async_task/health',   'type' => 'button'],
        ['name' => '取消任务', 'path' => '/admin/async_task/cancel',   'type' => 'button'],
        ['name' => '重试任务', 'path' => '/admin/async_task/retry',    'type' => 'button'],
        ['name' => '删除任务', 'path' => '/admin/async_task/delete',   'type' => 'button'],
        ['name' => '清理记录', 'path' => '/admin/async_task/clear',    'type' => 'button'],
    ];

    /**
     * 需要显式纠正 HTTP 方法的动作
     *
     * 依据：config/menu_permission.php::method.pattern 只覆盖
     * save/update/delete/remove/store/create/edit，其余默认 GET。
     *
     * @var array<string, string>
     */
    private array $methodOverrides = [
        'cancel' => 'POST',
        'retry'  => 'POST',
        'clear'  => 'POST',
        'delete' => 'DELETE',
    ];

    /** 系统目录名（收纳本页） */
    private const CATALOG_NAME = '系统工具';

    public function up(): void
    {
        $this->createAsyncTasksTable();
        $this->seedMenus();
    }

    public function down(): void
    {
        $this->removeMenus();

        if ($this->hasTable('async_tasks')) {
            $this->table('async_tasks')->drop()->save();
        }
    }

    // ========================================================================
    // 建表
    // ========================================================================

    private function createAsyncTasksTable(): void
    {
        if ($this->hasTable('async_tasks')) {
            return;
        }

        $table = $this->table('async_tasks', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => '异步任务注册表（进度/取消控制面）',
        ]);

        $table
            ->addColumn(Column::string('task_no', 64)->setComment('任务编号（对外可见）'))
            ->addColumn(Column::string('handler', 128)->setComment('回调标识，如 cms:export'))
            ->addColumn(Column::string('name', 255)->setDefault('')->setComment('任务名称（可读）'))
            ->addColumn(Column::string('queue', 64)->setDefault('default')->setComment('队列名'))
            ->addColumn(Column::string('connection', 32)->setDefault('')->setComment('队列连接名'))
            ->addColumn(Column::string('status', 16)->setDefault('pending')->setComment('状态 pending/running/paused/success/failed/canceled/stale'))
            ->addColumn(Column::integer('progress')->setDefault(0)->setComment('进度百分比，-1=不确定'))
            ->addColumn(Column::bigInteger('total')->setUnsigned()->setDefault(0)->setComment('总数，0=未知'))
            ->addColumn(Column::bigInteger('done')->setUnsigned()->setDefault(0)->setComment('已完成数'))
            ->addColumn(Column::string('message', 500)->setDefault('')->setComment('最近一条进度消息'))
            ->addColumn(Column::text('result')->setNullable()->setComment('结果 JSON'))
            ->addColumn(Column::text('error')->setNullable()->setComment('错误信息'))
            ->addColumn(Column::text('payload')->setNullable()->setComment('载荷 JSON（便于重试）'))
            ->addColumn(Column::integer('pid')->setUnsigned()->setDefault(0)->setComment('执行进程 PID'))
            ->addColumn(Column::integer('created_by')->setUnsigned()->setDefault(0)->setComment('创建人 admin id'))
            ->addColumn(Column::tinyInteger('cancel_requested')->setDefault(0)->setComment('取消标记 0/1'))
            ->addColumn(Column::tinyInteger('pause_requested')->setDefault(0)->setComment('暂停标记 0/1（预留）'))
            ->addColumn(Column::datetime('started_at')->setNullable()->setComment('开始时间'))
            ->addColumn(Column::datetime('finished_at')->setNullable()->setComment('结束时间'))
            ->addColumn(Column::datetime('heartbeat_at')->setNullable()->setComment('心跳时间（判断卡死）'))
            ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
            ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
            ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'))
            ->addIndex(['task_no'], ['unique' => true, 'name' => 'uk_task_no'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['handler'], ['name' => 'idx_handler'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->addIndex(['heartbeat_at'], ['name' => 'idx_heartbeat_at'])
            ->create();
    }

    // ========================================================================
    // 菜单 + 权限补种
    // ========================================================================

    /**
     * 补种「系统工具 / 任务中心」菜单，并派生权限节点
     *
     * 全程 try/catch：迁移不应因权限子系统尚未就绪（auth_permissions 未建）
     * 而阻断安装；菜单缺失时后台不显示入口，可后续手动补建。
     */
    private function seedMenus(): void
    {
        if (!$this->hasTable('menus')) {
            return;
        }

        try {
            /** @var \app\admin\model\Menu $menuModel */
            $menuModel = '\\app\\admin\\model\\Menu';

            $consoleRootId = $menuModel::resolveConsoleRoot();
            if ($consoleRootId === null) {
                return;
            }

            // 1. 幂等获取「系统工具」目录
            $container = $menuModel::ensureContainer($consoleRootId, 'system', self::CATALOG_NAME, [
                'icon' => 'bi bi-tools',
                'sort' => 750, // 介于「日志管理」(700) 与「插件管理」(800) 之间
            ]);
            $catalogId = (int) $container['id'];

            // 2. 幂等补种菜单项
            $menuModel::createMenus('system', $this->menuItems, $catalogId);

            // 3. 派生权限（与安装期 PermissionInitService 同一路径）
            if (!$this->hasTable('auth_permissions')) {
                return;
            }

            $paths = array_column($this->menuItems, 'path');
            $menus = $menuModel::where('plugin', 'system')->whereIn('path', $paths)->select();
            foreach ($menus as $menu) {
                $menuModel::syncPermissionForMenu($menu);
            }

            // 4. 纠正 cancel/retry/clear/delete 的 HTTP 方法
            $this->fixPermissionMethods();

            // 5. 路由匹配缓存必须显式失效，否则新权限在 TTL 内不生效
            $this->clearRouteCache();
        } catch (\Throwable $e) {
            // 迁移期静默降级（首次安装时权限子系统可能尚未就绪）
            try {
                \wise\support\context\ContextualLogger::warning(
                    'CreateAsyncTasksTable: 菜单/权限补种失败（可稍后手动补建）- ' . $e->getMessage(),
                    ['migration', 'async_task']
                );
            } catch (\Throwable) {
                // 日志子系统不可用时忽略
            }
        }
    }

    private function fixPermissionMethods(): void
    {
        if (!$this->hasTable('auth_permissions')) {
            return;
        }

        $bridge = app(\app\admin\service\MenuPermissionBridge::class);

        foreach ($this->methodOverrides as $action => $method) {
            $ident = $bridge->generateIdent('/admin/async_task/' . $action);
            Db::name('auth_permissions')->where('ident', $ident)->update(['method' => $method]);
        }
    }

    private function clearRouteCache(): void
    {
        try {
            app(\wise\auth\service\RbacManager::class)->clearRouteMatchCache();
        } catch (\Throwable) {
            // 包未完全启动等场景静默忽略（缓存会随 TTL 自然过期）
        }
    }

    private function removeMenus(): void
    {
        if (!$this->hasTable('menus')) {
            return;
        }

        try {
            $paths = array_column($this->menuItems, 'path');

            if ($this->hasTable('auth_permissions')) {
                $bridge = app(\app\admin\service\MenuPermissionBridge::class);
                $idents = array_map(static fn ($path) => $bridge->generateIdent($path), $paths);
                Db::name('auth_permissions')->whereIn('ident', $idents)->delete(true);
            }

            Db::name('menus')->where('plugin', 'system')->whereIn('path', $paths)->delete(true);

            // 目录本身：仅当确由本页创建且已无子节点时才移除
            $catalog = Db::name('menus')
                ->where('plugin', 'system')
                ->where('name', self::CATALOG_NAME)
                ->where('type', 'dir')
                ->find();
            if ($catalog) {
                $children = Db::name('menus')->where('parent_id', (int) $catalog['id'])->count();
                if ($children === 0) {
                    Db::name('menus')->where('id', (int) $catalog['id'])->delete(true);
                }
            }

            $this->clearRouteCache();
        } catch (\Throwable) {
            // 忽略
        }
    }
}
