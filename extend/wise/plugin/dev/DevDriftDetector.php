<?php

declare(strict_types=1);

namespace wise\plugin\dev;

use think\facade\Db;
use wise\plugin\adapter\AssetManager;
use wise\plugin\adapter\MigrationRunner;
use wise\plugin\manifest\MenuPathNormalizer;
use wise\plugin\PluginManager;
use wise\plugin\scanner\PluginMetadata;
use wise\support\menu\MenuSchema;

/**
 * 开发期漂移检测器（`plugin:dev --status` 的唯一事实来源）。
 *
 * **只读**：不写文件、不写库、不清缓存。所有写操作都在 {@see DevSyncer}。
 *
 * 检测四类漂移（与方桼�：
 *  1. `assets`   静态资源副本与源目录不一致（size + mtime 口径，与 `AssetManager::syncAssets()` 同源）；
 *  2. `cache`    扫描缓存指纹与磁盘 `manifest.json` 不一致（复用 `PluginScanner::cacheDrift()`）；
 *  3. `migrations` 迁移文件未执行（文件版本号 ∉ `ws_plugin_migrations`）；
 *  4. `menus` / `permissions` manifest 声明与库内实际节点不一致。
 *
 * ## 为什么这里直接用 `Db::name()` 而不是模型
 *
 * 检测器必须**只读且零副作用**：模型会带全局作用域（软删除）、自动时间戳、事件与
 * 请求级实例缓存，一条诊断命令不该触发这些。字段名与表名以迁移为准
 * （`ws_menus` / `ws_auth_permissions` / `ws_plugin_migrations`）。
 *
 * ## 数据库不可用时怎么办
 *
 * 方案里 `plugin:dev` 的定位是「不需要 DB（迁移除外）」。因此 DB 不可达时
 * 数据库相关的三段一律落 `skipped` 并在 `note` 里说明，**不**据此判定漂移，
 * 也不让命令失败 —— 只改 JS/CSS 的开发机上不该被库连接卡住。
 */
final class DevDriftDetector
{
    /** 每类漂移最多展示的明细条数（总数照常统计） */
    protected const DETAIL_LIMIT = 10;

    /** 扫描缓存诊断只在一次运行内计算一次（缓存是全局的，与单个插件无关） */
    protected ?array $cacheSnapshot = null;

    /** DB 可用性探测结果（null = 尚未探测） */
    protected ?bool $dbProbe = null;

    protected ?MenuPathNormalizer $normalizer = null;

    public function __construct(
        protected PluginManager $manager,
        protected ?AssetManager $assets = null,
        protected ?MigrationRunner $migrations = null
    ) {
    }

    /**
     * 检测单个插件的全部漂移。
     *
     * @param PluginMetadata $metadata 已扫描到的插件元数据（启用状态已合并）
     *
     * @return array{
     *     ident: string,
     *     display_name: string,
     *     installed: bool,
     *     enabled: bool,
     *     drift: bool,
     *     sections: array<int, array{key:string,title:string,state:string,summary:string,details:array<int,string>,note:string}>
     * } state = clean | drift | skipped | error
     */
    public function detect(PluginMetadata $metadata): array
    {
        $ident     = $metadata->getIdent();
        $installed = $this->isInstalled($ident);

        $sections = [
            $this->sectionAssets($metadata),
            $this->sectionCache($metadata),
            $this->sectionMigrations($metadata, $installed),
            $this->sectionMenus($metadata, $installed),
            $this->sectionPermissions($metadata, $installed),
        ];

        $drift = false;

        foreach ($sections as $section) {
            if ($section['state'] === 'drift' || $section['state'] === 'error') {
                $drift = true;
                break;
            }
        }

        return [
            'ident'        => $ident,
            'display_name' => $metadata->getDisplayName(),
            'installed'    => $installed,
            'enabled'      => $metadata->isEnabled(),
            'drift'        => $drift,
            'sections'     => $sections,
        ];
    }


    /**
     * 静态资源：源目录 vs `public/` 副本。
     */
    protected function sectionAssets(PluginMetadata $metadata): array
    {
        $ident  = $metadata->getIdent();
        $result = $this->assets()->syncAssets($ident, true);

        $details = [];

        foreach ($result['entries'] as $entry) {
            if ($entry['state'] !== 'copy') {
                continue;
            }

            if (count($details) >= self::DETAIL_LIMIT) {
                break;
            }

            $details[] = sprintf('待同步 %s → public/%s', $entry['source'], $entry['target']);
        }

        $state = 'clean';

        if ($result['errors'] > 0) {
            $state     = 'error';
            $details[] = sprintf('%d 个文件比对失败（详见日志）', $result['errors']);
        } elseif ($result['copied'] > 0) {
            $state = 'drift';
        }

        return $this->section(
            'assets',
            '静态资源',
            $state,
            sprintf('待同步 %d / 已一致 %d', $result['copied'], $result['same']),
            $details,
            $result['copied'] > self::DETAIL_LIMIT
                ? sprintf('仅列出前 %d 项，完整差异请加 --json 查看', self::DETAIL_LIMIT)
                : ''
        );
    }

    /**
     * 扫描缓存：manifest 指纹 vs 缓存。
     *
     * 缓存是全局的（一个 key 装全部插件），因此只取本插件那一项。
     */
    protected function sectionCache(PluginMetadata $metadata): array
    {
        $ident = $metadata->getIdent();
        $drift = $this->cacheDrift();
        $state = 'clean';
        $note  = '';
        $details = [];

        switch ($drift['state']) {
            case 'disabled':
                return $this->section('cache', '扫描缓存', 'clean', '缓存已关闭（plugin.cache_enabled=false）', [], '');

            case 'empty':
                return $this->section('cache', '扫描缓存', 'clean', '无缓存条目（下次读取会自动扫描）', [], '');

            case 'stale':
                $reason = $drift['stale_plugins'][$ident] ?? null;

                if ($reason === null) {
                    $others = count($drift['stale_plugins']);
                    $note   = sprintf('缓存整体已过期（%d 个插件的 manifest 变化），可用 --refresh 主动刷新', $others);

                    return $this->section('cache', '扫描缓存', 'clean', '本插件指纹一致', [], $note);
                }

                $state     = 'drift';
                $details[] = sprintf('manifest 指纹与缓存不一致（%s）', $reason);
                $note      = '下次读取会自动重扫；--refresh 可立即清缓存';

                break;

            default:
                break;
        }

        return $this->section('cache', '扫描缓存', $state, $state === 'drift' ? '缓存已过期' : '缓存与磁盘一致', $details, $note);
    }

    /**
     * 迁移：文件版本号 vs `ws_plugin_migrations` 记录。
     */
    protected function sectionMigrations(PluginMetadata $metadata, bool $installed): array
    {
        $ident = $metadata->getIdent();

        if (!$installed) {
            return $this->section('migrations', '数据库迁移', 'skipped', '插件未安装，跳过', [], '迁移只对已安装插件有意义');
        }

        $pending = $this->pendingMigrations($ident);

        if ($pending === null) {
            return $this->section('migrations', '数据库迁移', 'skipped', '数据库不可达，跳过', [], '检查 database 连接配置');
        }

        if ($pending === []) {
            return $this->section('migrations', '数据库迁移', 'clean', '无待执行迁移', [], '');
        }

        $details = [];

        foreach (array_slice($pending, 0, self::DETAIL_LIMIT) as $item) {
            $details[] = sprintf('%s  %s', $item['version'], $item['file']);
        }

        return $this->section(
            'migrations',
            '数据库迁移',
            'drift',
            sprintf('待执行 %d 个迁移', count($pending)),
            $details,
            '--migrate 会先列出再执行（写库，需确认）'
        );
    }

    /**
     * 菜单：manifest 声明节点 vs `ws_menus` 实际节点（path + name + sort 三元组）。
     *
     * 只比**本插件作用域内的非根节点**（`is_plugin_root=1` 的容器由 registrar 自动生成，
     * 名字可能与 manifest 无关，比它只会制造假漂移）。
     */
    protected function sectionMenus(PluginMetadata $metadata, bool $installed): array
    {
        $declaredNodes = MenuSchema::normalizeTree($metadata->getMenus());

        if ($declaredNodes === []) {
            return $this->section('menus', '菜单', 'clean', 'manifest 未声明菜单', [], '');
        }

        if (!$installed) {
            return $this->section('menus', '菜单', 'skipped', '插件未安装，跳过', [], '安装后菜单才会落库');
        }

        $rows = $this->queryRows(
            'menus',
            ['plugin' => $metadata->getIdent()],
            'id,name,path,sort,is_plugin_root,delete_time'
        );

        if ($rows === null) {
            return $this->section('menus', '菜单', 'skipped', '数据库不可达，跳过', [], '检查 database 连接配置');
        }

        $declared = [];
        $this->flattenDeclared($declaredNodes, $declared);

        $actual = [];

        foreach ($rows as $row) {
            if ((int) ($row['is_plugin_root'] ?? 0) === 1) {
                continue;
            }

            if (($row['delete_time'] ?? null) !== null && $row['delete_time'] !== '') {
                continue;
            }

            $identity = $this->menuIdentity((string) ($row['path'] ?? ''), (string) ($row['name'] ?? ''));

            if ($identity === '') {
                continue;
            }

            $actual[$identity] = [
                'name' => (string) ($row['name'] ?? ''),
                'sort' => (int) ($row['sort'] ?? 0),
                'path' => (string) ($row['path'] ?? ''),
            ];
        }

        $missing = array_diff_key($declared, $actual);
        $extra   = array_diff_key($actual, $declared);
        $changed = [];

        foreach ($declared as $identity => $node) {
            if (!isset($actual[$identity])) {
                continue;
            }

            if ($actual[$identity]['name'] !== $node['name'] || $actual[$identity]['sort'] !== $node['sort']) {
                $changed[$identity] = $node;
            }
        }

        $details = [];

        foreach (array_slice($missing, 0, self::DETAIL_LIMIT, true) as $identity => $node) {
            $details[] = sprintf('未落库  %-40s %s', $identity, $node['path']);
        }

        foreach (array_slice($changed, 0, self::DETAIL_LIMIT, true) as $identity => $node) {
            $details[] = sprintf(
                '字段不同  %-40s name: %s → %s / sort: %d → %d',
                $identity,
                $actual[$identity]['name'],
                $node['name'],
                $actual[$identity]['sort'],
                $node['sort']
            );
        }

        foreach (array_slice($extra, 0, self::DETAIL_LIMIT, true) as $identity => $node) {
            $details[] = sprintf('库里多出  %-40s %s（register 不会删除，需人工/--prune 处理）', $identity, $node['path']);
        }

        $drift = $missing !== [] || $changed !== [] || $extra !== [];

        return $this->section(
            'menus',
            '菜单',
            $drift ? 'drift' : 'clean',
            sprintf('声明 %d / 缺失 %d / 字段不同 %d / 多出 %d', count($declared), count($missing), count($changed), count($extra)),
            $details,
            $drift ? '--refresh 只补建/恢复并保留运营改动（不覆盖、不删除）' : ''
        );
    }

    /**
     * 权限：manifest 独立权限（type != menu）vs `ws_auth_permissions`。
     *
     * 只查"声明了但库里没有（或已软删）"——多出的权限行可能已被角色引用，不参与判定。
     */
    protected function sectionPermissions(PluginMetadata $metadata, bool $installed): array
    {
        $declared = [];

        foreach ($metadata->getPermissions() as $permission) {
            if (!is_array($permission)) {
                continue;
            }

            if (($permission['type'] ?? 'custom') === 'menu') {
                continue;
            }

            $ident = trim((string) ($permission['ident'] ?? ''));

            if ($ident !== '') {
                $declared[$ident] = (string) ($permission['name'] ?? $ident);
            }
        }

        if ($declared === []) {
            return $this->section('permissions', '权限点', 'clean', 'manifest 未声明独立权限', [], '');
        }

        if (!$installed) {
            return $this->section('permissions', '权限点', 'skipped', '插件未安装，跳过', [], '');
        }

        $rows = $this->queryRows(
            'auth_permissions',
            ['plugin' => $metadata->getIdent()],
            'ident,type,delete_time'
        );

        if ($rows === null) {
            return $this->section('permissions', '权限点', 'skipped', '数据库不可达，跳过', [], '检查 database 连接配置');
        }

        $active = [];

        foreach ($rows as $row) {
            if (($row['delete_time'] ?? null) !== null && $row['delete_time'] !== '') {
                continue;
            }

            $active[(string) ($row['ident'] ?? '')] = true;
        }

        $missing = [];

        foreach ($declared as $ident => $name) {
            if (!isset($active[$ident])) {
                $missing[$ident] = $name;
            }
        }

        $details = [];

        foreach (array_slice($missing, 0, self::DETAIL_LIMIT, true) as $ident => $name) {
            $details[] = sprintf('未注册  %-40s %s', $ident, $name);
        }

        return $this->section(
            'permissions',
            '权限点',
            $missing === [] ? 'clean' : 'drift',
            sprintf('声明 %d / 未注册 %d', count($declared), count($missing)),
            $details,
            $missing === [] ? '' : '--refresh 会调用 PermissionRegistrar 注册（含软删恢复）'
        );
    }


    /**
     * 待执行迁移清单（文件版本号不在迁移记录表中）。
     *
     * @return array<int, array{version:string, file:string}>|null null = DB 不可达
     */
    protected function pendingMigrations(string $ident): ?array
    {
        $dir  = rtrim(str_replace('\\', '/', plugin_path()), '/') . '/' . $ident . '/database/migrations';
        $files = is_dir($dir) ? (glob($dir . '/*.php') ?: []) : [];

        if ($files === []) {
            return [];
        }

        if (!$this->dbReady()) {
            return null;
        }

        $executed = [];

        try {
            if ($this->migrations()->hasMigrationTable()) {
                $versions = Db::name('plugin_migrations')
                    ->where('plugin', $ident)
                    ->column('version');

                foreach ((array) $versions as $version) {
                    $executed[(string) $version] = true;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        $pending = [];

        foreach ($files as $file) {
            $base = basename($file);

            if (preg_match('/^(\d+)_/', $base, $m) !== 1) {
                continue;
            }

            $versionKey = (string) $m[1];
            $normalized = strval((int) $m[1]);

            if (isset($executed[$versionKey]) || isset($executed[$normalized])) {
                continue;
            }

            $pending[] = ['version' => $m[1], 'file' => $base];
        }

        return $pending;
    }

    /**
     * 只读查询一张表（失败/不可达返回 null）。
     *
     * 软删除判定交给调用方：这里不带 `delete_time` 过滤，避免与模型语义耦合。
     *
     * @param array<string, mixed> $where
     * @return array<int, array<string, mixed>>|null
     */
    protected function queryRows(string $table, array $where, string $field): ?array
    {
        if (!$this->dbReady()) {
            return null;
        }

        try {
            $rows = Db::name($table)->where($where)->field($field)->select()->toArray();

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 数据库是否可用（一次运行只探测一次）。
     */
    protected function dbReady(): bool
    {
        if ($this->dbProbe !== null) {
            return $this->dbProbe;
        }

        try {
            Db::query('SELECT 1');
            $this->dbProbe = true;
        } catch (\Throwable $e) {
            $this->dbProbe = false;
        }

        return $this->dbProbe;
    }

    protected function isInstalled(string $ident): bool
    {
        try {
            return $this->manager->isInstalled($ident, false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function cacheDrift(): array
    {
        if ($this->cacheSnapshot !== null) {
            return $this->cacheSnapshot;
        }

        try {
            $snapshot = $this->manager->scannerCacheDrift();
        } catch (\Throwable $e) {
            $snapshot = [
                'enabled'       => true,
                'state'         => 'empty',
                'current'       => [],
                'cached'        => null,
                'stale_plugins' => [],
            ];
        }

        return $this->cacheSnapshot = $snapshot;
    }

    /**
     * 把 manifest 菜单树拍平为 identity => {name, sort, path}。
     *
     * identity 与落库原语同口径（{@see MenuPathNormalizer::identity()}）：
     * 有 path 用归一后的 path，无 path 用 name（目录节点）。
     *
     * ⚠️ 无 path 的目录节点**只按 name 比对**，不带父链：库侧要还原父链得额外查
     * `parent_id` 并跳过自动生成的插件根容器，容易因根容器层级差异产生假漂移。
     * 同名目录出现在不同分支时本检测可能漏报 —— 可以接受：漂移检测只是提示，
     * 真正的权威链路是 `MenuRegistrar::register()` 与 `plugin:menu-derive`。
     *
     * @param array<int, array<string, mixed>>                         $nodes
     * @param array<string, array{name:string, sort:int, path:string}> $out
     */
    protected function flattenDeclared(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            $name = (string) ($node['name'] ?? '');
            $path = (string) ($node['path'] ?? '');
            $key  = $this->menuIdentity($path, $name);

            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = [
                    'name' => $name,
                    'sort' => (int) ($node['sort'] ?? 0),
                    'path' => $path,
                ];
            }

            $children = (array) ($node['children'] ?? []);

            if ($children !== []) {
                $this->flattenDeclared($children, $out);
            }
        }
    }

    /**
     * 菜单节点身份键。
     *
     * - 有 path：`p:{归一化 path}`（`/plugin/x/y` 与 `/plugin/x/y/index` 视为同一节点）；
     * - 无 path：`n:{name}`（目录节点）。
     */
    protected function menuIdentity(string $path, string $name): string
    {
        $path = trim($path);

        if ($path !== '') {
            $identity = $this->normalizer()->identity($path);

            return $identity === '' ? 'p:' . trim($path, '/') : 'p:' . $identity;
        }

        $name = trim($name);

        return $name === '' ? '' : 'n:' . $name;
    }

    protected function normalizer(): MenuPathNormalizer
    {
        return $this->normalizer ??= MenuPathNormalizer::fromConfig();
    }

    protected function assets(): AssetManager
    {
        return $this->assets ??= new AssetManager();
    }

    protected function migrations(): MigrationRunner
    {
        return $this->migrations ??= new MigrationRunner();
    }

    /**
     * 构造一个 section（统一结构，便于 text / json 两种渲染）。
     *
     * @param array<int, string> $details
     *
     * @return array{key:string,title:string,state:string,summary:string,details:array<int,string>,note:string}
     */
    protected function section(
        string $key,
        string $title,
        string $state,
        string $summary,
        array $details,
        string $note
    ): array {
        return [
            'key'     => $key,
            'title'   => $title,
            'state'   => $state,
            'summary' => $summary,
            'details' => array_values($details),
            'note'    => $note,
        ];
    }
}
