<?php

declare(strict_types=1);

namespace wise\plugin\dev;

use think\App;
use wise\plugin\adapter\AssetManager;
use wise\plugin\adapter\MenuRegistrar;
use wise\plugin\adapter\MigrationRunner;
use wise\plugin\adapter\PermissionRegistrar;
use wise\plugin\PluginManager;

/**
 * 开发期同步器（`plugin:dev` 的**全部写操作**都收敛在这里）。
 *
 *  1. 每一步只做一件事，可单独调用（`--assets` / `--refresh` / `--migrate`）；
 *  2. 全部可重复执行：静态资源按差异复制、菜单注册是幂等的、迁移按记录表跳过已执行项；
 *  3. 不做任何"猜你要什么"的动作：没有文件监听、没有守护进程、不安装/卸载插件。
 *
 * 明确**不清** opcache / 路由缓存：命令行进程与 FPM 是两套内存，清了也影响不到
 * 正在跑 Web 的进程，反而会让开发者误以为"刷新过了"。要清缓存请用 `php think clear`。
 */
final class DevSyncer
{
    public function __construct(
        protected PluginManager $manager,
        protected ?App $app = null,
        protected ?AssetManager $assets = null,
        protected ?MenuRegistrar $menus = null,
        protected ?PermissionRegistrar $permissions = null,
        protected ?MigrationRunner $migrations = null
    ) {
    }

    /**
     * 增量同步静态资源。
     *
     * @see AssetManager::syncAssets()
     *
     * @return array{entries:array<int,array{source:string,target:string,state:string}>,copied:int,same:int,errors:int,missing_source:int}
     */
    public function syncAssets(string $ident): array
    {
        return $this->assets()->syncAssets($ident, false);
    }

    /**
     * 刷新扫描缓存，并按需同步菜单 / 权限。
     *
     * 顺序刻意如此：先刷缓存，再**重新读取**元数据 —— 开发者刚改过 `manifest.json`
     * 时，必须用磁盘上最新声明去同步菜单，而不是用刷缓存之前那份。
     *
     * @return array{
     *     ok: bool,
     *     error: string,
     *     cache: string,
     *     menus: array{attempted:bool, ok:bool, message:string},
     *     permissions: array{attempted:bool, ok:bool, message:string}
     * }
     */
    public function refresh(string $ident): array
    {
        $result = [
            'ok'          => true,
            'error'       => '',
            'cache'       => 'cleared',
            'menus'       => ['attempted' => false, 'ok' => true, 'message' => ''],
            'permissions' => ['attempted' => false, 'ok' => true, 'message' => ''],
        ];

        try {
            $this->manager->refreshScannerCache();

            $this->manager->clearPluginCache();

            $metadata = $this->manager->getPlugin($ident);
        } catch (\Throwable $e) {
            return array_merge($result, [
                'ok'    => false,
                'error' => '刷新扫描缓存后读取插件失败：' . $e->getMessage(),
            ]);
        }

        $installed = false;

        try {
            $installed = $this->manager->isInstalled($ident, false);
        } catch (\Throwable $e) {
            $installed = false;
        }

        if (!$installed) {
            $result['menus']['message']       = '插件未安装，跳过菜单/权限同步';
            $result['permissions']['message'] = '插件未安装，跳过菜单/权限同步';

            return $result;
        }

        if ($metadata->getMenus() !== []) {
            $result['menus']['attempted'] = true;

            try {
                $this->menus()->register($metadata, false);
                $result['menus']['message'] = '已按 manifest 补建/恢复菜单（不覆盖、不删除）';
            } catch (\Throwable $e) {
                $result['menus']['ok']      = false;
                $result['menus']['message'] = '菜单同步失败：' . $e->getMessage();
                $result['ok']               = false;
            }
        }

        if ($metadata->getPermissions() !== []) {
            $result['permissions']['attempted'] = true;

            try {
                $this->permissions()->register($metadata);
                $result['permissions']['message'] = '已注册 manifest 声明的独立权限';
            } catch (\Throwable $e) {
                $result['permissions']['ok']      = false;
                $result['permissions']['message'] = '权限同步失败：' . $e->getMessage();
                $result['ok']                     = false;
            }
        }

        return $result;
    }

    /**
     * 执行插件迁移（复用 {@see MigrationRunner}，即 `plugin:migrate:run` 同一条链路）。
     *
     * 命令侧负责"先列出再确认"，本方法只负责在确认之后真正执行。
     *
     * @return array{ok:bool, message:string}
     */
    public function migrate(string $ident): array
    {
        try {
            if (!$this->migrations()->hasMigrations($ident)) {
                return ['ok' => true, 'message' => '无迁移文件，跳过'];
            }

            $runner = $this->migrations()->migrate($ident);

            return [
                'ok'      => (bool) ($runner['success'] ?? false),
                'message' => trim((string) ($runner['message'] ?? '')),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '迁移执行失败：' . $e->getMessage()];
        }
    }

    protected function assets(): AssetManager
    {
        return $this->assets ??= new AssetManager();
    }

    protected function menus(): MenuRegistrar
    {
        return $this->menus ??= new MenuRegistrar();
    }

    protected function permissions(): PermissionRegistrar
    {
        return $this->permissions ??= new PermissionRegistrar();
    }

    protected function migrations(): MigrationRunner
    {
        return $this->migrations ??= new MigrationRunner($this->app ?? app());
    }
}
