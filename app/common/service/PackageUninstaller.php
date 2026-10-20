<?php
declare(strict_types=1);

namespace app\common\service;

use app\admin\model\Menu;
use app\admin\service\MenuItemSync;
use think\facade\Db;

/**
 * 库包卸载清理器
 *
 * 把「卸载一个 composer 库包」在框架内留下的全部痕迹清掉。清理顺序与依据：
 *
 * | 痕迹 | 位置 | 依据 |
 * |---|---|---|
 * | 菜单（含自持容器） | `menus.plugin = {ident}` | 实现标识字符串，**不依赖 provider 类**（包删了也能清） |
 * | 菜单派生权限 | `auth_permissions` / `auth_role_permission` | 菜单的 `permission_id`，**必须在删菜单前采集** |
 * | 静态资源 | `extra.think.publish` 的 target 目录 | {@see PackageRegistry} 快照（包删后无法反查） |
 * | 配置行 | `config` 表 `namespace = {ident}` | 逐条走 wise-config 官方删除（连带 history / tags / 缓存） |
 * | 能力市场启停 | `config` 的 `system/capability/active_overrides` JSON | 按 `{ident}:` 前缀摘键（**不可整行删**，否则清掉其它实现） |
 * | 数据表 + 迁移记录 | `{prefix}{table}` / `migrations` | 快照登记的包内迁移表名与版本号 |
 * | 依赖插件 | 各插件 `manifest.json` 的 `packages` 段 | 默认**只报告**；加 `--disable-dependent-plugins` 才停用 |
 *
 * 默认**只报告不执行**（dry-run）；`apply = true` 才真正清理 —— 调用方（CLI / composer 钩子）
 * 负责决定是否带 `--apply`。
 *
 * 安全护栏：
 *  - 静态资源目标必须是 `public/` 下深度 ≥ 4 段的相对路径，拒绝绝对路径与 `..`；
 *  - 表名只接受 `[A-Za-z0-9_]+`，且只在"表确实存在"时才 DROP；
 *  - 能力覆盖按前缀摘键，绝不整行/整 namespace 删除；
 *  - 硬编码类引用（`app/**`、`plugins/**` 里 `use wise\member\...`）**只报告，绝不自动改代码**。
 *
 * @see \wise\command\PackageCleanup 命令行入口：php think wise:package:cleanup
 * @see \wise\composer\PackageHook composer 的 post-package-uninstall 事件回调
 */
class PackageUninstaller
{
    /**
     * 硬引用扫描的根目录（相对应用根）
     *
     * @var string[]
     */
    protected const SCAN_ROOTS = ['app', 'extend', 'plugins', 'config', 'public/statics'];

    /**
     * 硬引用扫描的文件后缀
     *
     * @var string[]
     */
    protected const SCAN_EXT = ['php', 'js', 'html', 'json', 'md'];

    /**
     * 扫描排除项：清理能力自身的文件（它们的注释里必然出现 ident，属自噪音）
     *
     * @var string[]
     */
    protected const SCAN_SELF = [
        'app/common/service/PackageRegistry.php',
        'app/common/service/PackageUninstaller.php',
        'extend/wise/command/PackageCleanup.php',
        'extend/wise/composer/PackageHook.php',
    ];

    /** 扫描命中上限（超出只标记截断，不继续读文件） */
    protected const SCAN_LIMIT = 200;

    public function __construct(protected ?PackageRegistry $registry = null)
    {
        $this->registry ??= new PackageRegistry();
    }

    /**
     * 清理单个包的痕迹
     *
     * @param  string $package composer 包名（如 wiseadmin/wise-member）
     * @param  array{
     *     apply?:bool, keepMenus?:bool, keepPermissions?:bool, keepAssets?:bool,
     *     keepConfig?:bool, keepCapability?:bool, keepTables?:bool
     * } $options
     * @return array<string, mixed>
     */
    public function uninstall(string $package, array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $entry = $this->resolveEntry($package);
        $ident = (string) $entry['ident'];

        $report = [
            'package' => $package,
            'ident'   => $ident,
            'apply'   => $apply,
            'source'  => $entry['source'],
            'steps'   => [],
        ];

        $menuStep = ($options['keepMenus'] ?? false)
            ? $this->skip('菜单（保留）')
            : $this->cleanupMenus($ident, $apply);

        $report['steps']['menus'] = $menuStep;

        // 权限与菜单强耦合（permission_id 必须在删菜单前采集），这里只做呈现，
        // 真正的删除发生在菜单步骤内 —— 拆开是为了让「权限/角色绑定是否被清」显式可见。
        $permCount = (int) ($menuStep['permissions'] ?? 0);

        $report['steps']['permissions'] = ($options['keepPermissions'] ?? false)
            ? $this->skip('菜单派生权限（保留）')
            : [
                'label'        => '菜单派生权限',
                'mode'         => $permCount === 0 ? 'none' : ($apply ? 'deleted' : 'dry-run'),
                'count'        => $permCount,
                'roleBindings' => (int) ($menuStep['roleBindings'] ?? 0),
                'keys'         => $menuStep['permIdents'] ?? [],
                'note'         => $permCount === 0
                    ? '无菜单派生权限'
                    : sprintf(
                        '派生权限 %d 条、角色绑定 %d 条（随菜单步骤一并物理删除）',
                        $permCount,
                        (int) ($menuStep['roleBindings'] ?? 0)
                    ),
            ];

        $report['steps']['assets'] = ($options['keepAssets'] ?? false)
            ? $this->skip('静态资源（保留）')
            : $this->cleanupAssets($entry['targets'] ?? [], $apply);

        $report['steps']['config'] = ($options['keepConfig'] ?? false)
            ? $this->skip('配置行（保留）')
            : $this->cleanupConfig((string) ($entry['namespace'] ?? $ident), $apply);

        $report['steps']['capability'] = ($options['keepCapability'] ?? false)
            ? $this->skip('能力市场启停记录（保留）')
            : $this->cleanupCapability($ident, $apply);

        $report['steps']['plugins'] = ($options['keepPlugins'] ?? false)
            ? $this->skip('依赖插件（保留）')
            : $this->handleDependentPlugins(
                $package,
                $apply,
                (bool) ($options['disableDependentPlugins'] ?? false)
            );

        $report['steps']['tables'] = ($options['keepTables'] ?? false)
            ? $this->skip('数据表与迁移记录（保留）')
            : $this->cleanupTables($entry['tables'] ?? [], $entry['versions'] ?? [], $apply);

        $report['steps']['references'] = $this->scanHardReferences($ident, $entry['targets'] ?? []);

        if ($apply) {
            $this->registry->forget($package);
            $report['steps']['registry'] = $this->summary('发布登记快照', 1, "已移除 {$package} 条目");
        }

        return $report;
    }

    /**
     * 对账：清理「登记过但已不在安装集合里」的包
     *
     * 判定同时要求：不在 `installed.json` **且**不在 `composer.lock`
     * —— 任一仍在（部分安装/回滚中途）就跳过，避免误删。
     *
     * @param  array{apply?:bool} $options
     * @return array<string, mixed>
     */
    public function reconcile(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);

        $registered = $this->registry->recordAll();

        $installed = $this->installedPackageNames();
        $locked    = $this->lockedPackageNames();

        $missing = [];
        foreach ($this->registry->packages() as $package) {
            if (isset($installed[$package]) || isset($locked[$package])) {
                continue;
            }
            $missing[] = $package;
        }

        $results = [];
        foreach ($missing as $package) {
            $results[$package] = $this->uninstall($package, $options);
        }

        return [
            'apply'            => $apply,
            'registered'       => $registered,
            'installed'        => count($installed),
            'missing'          => $missing,
            'results'          => $results,
            'unclaimed_assets' => $this->unclaimedAssetDirs(),
        ];
    }

    // ========================================================================
    // 各清理步骤
    // ========================================================================

    /**
     * 菜单 + 菜单派生权限
     *
     * 权限 id 必须在删除菜单**之前**采集（菜单删了就没了），
     * 且要用 `withTrashed()` —— 上游 `menu:sync --prune-orphans` 可能已把菜单软删。
     *
     * @return array<string, mixed>
     */
    protected function cleanupMenus(string $ident, bool $apply): array
    {
        if (!class_exists(Menu::class)) {
            return $this->skip('菜单（宿主无 Menu 模型，跳过）');
        }

        $menus = Menu::withTrashed()->where('plugin', $ident)->select()->toArray();
        if ($menus === []) {
            return $this->summary('菜单', 0, '库中无该实现的菜单');
        }

        $permissionIds = array_values(array_filter(array_map(
            static fn(array $m): int => (int) ($m['permission_id'] ?? 0),
            $menus
        )));

        $permIdents = $permissionIds === []
            ? []
            : Db::name('auth_permissions')->whereIn('id', $permissionIds)->column('ident');

        $deletedMenus = 0;
        $deletedPerms = 0;
        $deletedBinds = 0;

        if ($apply) {
            // 物理删菜单（同时会软删其派生权限）
            $deletedMenus = app(MenuItemSync::class)->uninstall($ident, true);

            if ($permissionIds !== []) {
                // 权限本来只是软删；用户要求「彻底清理」→ 连角色绑定一并物理删
                $deletedBinds = Db::name('auth_role_permission')
                    ->whereIn('permission_id', $permissionIds)
                    ->delete();
                $deletedPerms = Db::name('auth_permissions')
                    ->whereIn('id', $permissionIds)
                    ->delete();

                $this->clearAuthCaches();
            }
        } else {
            $deletedMenus = count($menus);
            $deletedPerms = count($permissionIds);
        }

        return [
            'label'          => '菜单',
            'mode'           => $apply ? 'dropped' : 'dry-run',
            'count'          => $deletedMenus,
            'permissionIds'  => count($permissionIds),
            'permissions'    => $deletedPerms,
            'roleBindings'   => $deletedBinds,
            'permIdents'     => array_slice(array_values($permIdents), 0, 20),
            'note'           => sprintf(
                '菜单 %d 项（含软删）、派生权限 %d 条%s',
                $deletedMenus,
                count($permissionIds),
                $apply ? '，权限与角色绑定已物理删除' : '（预览）'
            ),
        ];
    }

    /**
     * 静态资源：按快照登记的发布目标目录直接删除
     *
     * @param  string[] $targets
     * @return array<string, mixed>
     */
    protected function cleanupAssets(array $targets, bool $apply): array
    {
        $root  = $this->appRoot();
        $items = [];
        $files = 0;
        $bytes = 0;

        foreach ($targets as $target) {
            $rel = $this->safePublicPath((string) $target);

            if ($rel === null) {
                $items[] = ['target' => $target, 'status' => 'unsafe', 'note' => '非 public/ 下的安全相对路径，已拒绝'];
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

            if (!file_exists($path)) {
                $items[] = ['target' => $rel, 'status' => 'absent'];
                continue;
            }

            [$fileCount, $byteCount] = $this->measurePath($path);
            $files += $fileCount;
            $bytes += $byteCount;

            if ($apply) {
                $this->deletePath($path);
            }

            $items[] = [
                'target' => $rel,
                'status' => $apply ? 'deleted' : 'dry-run',
                'files'  => $fileCount,
                'bytes'  => $byteCount,
            ];
        }

        return [
            'label' => '静态资源',
            'mode'  => $apply ? 'deleted' : 'dry-run',
            'count' => count($items),
            'files' => $files,
            'bytes' => $bytes,
            'items' => $items,
            'note'  => sprintf('%d 个发布目标，%d 个文件 / %s', count($items), $files, $this->humanBytes($bytes)),
        ];
    }

    /**
     * 配置行：按 namespace 逐条走 wise-config 官方删除（连带 history / tags / 缓存）
     *
     * @return array<string, mixed>
     */
    protected function cleanupConfig(string $namespace, bool $apply): array
    {
        if ($namespace === '' || !$this->tableExists('config')) {
            return $this->summary('配置行', 0, '无 config 表或命名空间为空');
        }

        $rows = Db::name('config')
            ->where('namespace', $namespace)
            ->field('id,group,key')
            ->select()
            ->toArray();

        if ($rows === []) {
            return $this->summary('配置行', 0, "命名空间 {$namespace} 无配置行");
        }

        $keys = [];
        foreach ($rows as $row) {
            $keys[] = $namespace . ':' . ($row['group'] ?? '') . ':' . ($row['key'] ?? '');
        }

        if ($apply) {
            $facade = 'wise\\config\\facade\\WiseConfig';

            foreach ($rows as $row) {
                $path = (string) ($row['group'] ?? '') . ':' . (string) ($row['key'] ?? '');

                try {
                    if (class_exists($facade)) {
                        $facade::namespace($namespace)->delete($path);
                    } else {
                        // 兜底：官方门面不可用（包缺失）时直接删行
                        Db::name('config')->where('namespace', $namespace)->where('key', $row['key'] ?? '')->delete();
                    }
                } catch (\Throwable $e) {
                    // 单条失败不阻断整体清理
                }
            }

            try {
                if (class_exists($facade)) {
                    $facade::clearAllCache();
                }
            } catch (\Throwable $e) {
                // 缓存清理失败不影响数据一致性（TTL 自然过期）
            }
        }

        return [
            'label' => '配置行',
            'mode'  => $apply ? 'deleted' : 'dry-run',
            'count' => count($rows),
            'keys'  => array_slice($keys, 0, 20),
            'note'  => sprintf('命名空间 %s 共 %d 行', $namespace, count($rows)),
        ];
    }

    /**
     * 能力市场启停记录：从 system/capability/active_overrides 的 JSON 中摘除 `{ident}:` 前缀键
     *
     * 该 JSON 是「唯一一行」，整行删除会清掉所有实现（含其它包/插件）的启停状态，故按前缀精确摘键。
     *
     * @return array<string, mixed>
     */
    protected function cleanupCapability(string $ident, bool $apply): array
    {
        $service = 'app\\common\\service\\CapabilityOverrideService';
        if (!class_exists($service)) {
            return $this->skip('能力市场启停记录（服务不可用）');
        }

        $overrides = $service::load();
        if ($overrides === []) {
            return $this->summary('能力市场启停记录', 0, '无启停覆盖记录');
        }

        $removed = [];
        foreach ($overrides as $point => $impls) {
            if (!is_array($impls)) {
                continue;
            }

            foreach (array_keys($impls) as $name) {
                if ($name === $ident || str_starts_with((string) $name, $ident . ':')) {
                    unset($overrides[$point][$name]);
                    $removed[] = $point . ':' . $name;
                }
            }
        }

        if ($apply && $removed !== []) {
            $service::save($overrides);
        }

        return [
            'label' => '能力市场启停记录',
            'mode'  => $apply ? 'pruned' : 'dry-run',
            'count' => count($removed),
            'keys'  => array_slice($removed, 0, 20),
            'note'  => sprintf('摘除 %d 个 %s* 键', count($removed), $ident),
        ];
    }

    /**
     * 数据表（物理 DROP）与迁移记录
     *
     * DROP 之后必须同时删 `migrations` 记录 —— 否则重装后 `migrate:run` 认为"已执行"，
     * 表不会被重建。
     *
     * @param  string[] $tables
     * @param  string[] $versions
     * @return array<string, mixed>
     */
    protected function cleanupTables(array $tables, array $versions, bool $apply): array
    {
        $prefix = $this->dbPrefix();
        $existing = $this->allTables();

        $dropped = [];
        $absent  = [];
        $skipped = [];

        foreach ($tables as $name) {
            $name = (string) $name;

            if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                $skipped[] = $name;
                continue;
            }

            $full = $prefix . $name;

            if (!isset($existing[$full])) {
                $absent[] = $full;
                continue;
            }

            if ($apply) {
                Db::execute('DROP TABLE IF EXISTS `' . $full . '`');
            }

            $dropped[] = $full;
        }

        $versionRows = 0;
        if ($versions !== [] && $this->tableExists('migrations')) {
            $versionRows = $apply
                ? Db::name('migrations')->whereIn('version', array_map('intval', $versions))->delete()
                : Db::name('migrations')->whereIn('version', array_map('intval', $versions))->count();
        }

        return [
            'label'        => '数据表与迁移记录',
            'mode'         => $apply ? 'dropped' : 'dry-run',
            'count'        => count($dropped),
            'dropped'      => $dropped,
            'absent'       => $absent,
            'skippedNames' => $skipped,
            'versions'     => count($versions),
            'versionRows'  => $versionRows,
            'note'         => sprintf(
                '表 %d 张（不存在 %d）、迁移记录 %d 条',
                count($dropped),
                count($absent),
                $versionRows
            ),
        ];
    }

    /**
     * 列出声明依赖该库包的插件（读各插件 manifest.json 的 `packages` 段）
     *
     * 刻意**不经过插件引擎**（不扫描、不初始化插件）：卸载清理时库包可能已经不在，
     * 引擎的相关链路未必可用，直接读文件最稳。
     *
     * @return array<string, array{ident:string, required:bool, constraint:string, path:string}>
     */
    public function dependentPlugins(string $package): array
    {
        $dir = $this->appRoot() . DIRECTORY_SEPARATOR . 'plugins';

        if (!is_dir($dir)) {
            return [];
        }

        $result = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
            $manifest = $pluginDir . DIRECTORY_SEPARATOR . 'manifest.json';

            if (!is_file($manifest)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($manifest), true);

            if (!is_array($data)) {
                continue;
            }

            $packages = $data['packages'] ?? [];

            if (!is_array($packages) || !isset($packages[$package])) {
                continue;
            }

            $spec = $packages[$package];

            $result[basename($pluginDir)] = [
                'ident'      => basename($pluginDir),
                'required'   => is_array($spec) ? (bool) ($spec['required'] ?? true) : true,
                'constraint' => is_array($spec) ? (string) ($spec['constraint'] ?? '*') : (string) $spec,
                'path'       => $this->relative($this->appRoot(), $pluginDir),
            ];
        }

        return $result;
    }

    /**
     * 依赖插件：默认只报告；`--disable-dependent-plugins` 时才调用插件引擎停用
     *
     * 为什么默认不停用：这些插件可能还有与会员无关的功能（如 smartstock 的库存后台），
     * 自动停用会连带失效；而库包缺失后的运行时加载/s启用已被
     * {@see \wise\plugin\middleware\PluginLoader} 与 `DependencyChecker` 拦截，
     * 不会出现"已启用却崩溃"。需要彻底停用时显式加参数。
     *
     * @return array<string, mixed>
     */
    protected function handleDependentPlugins(string $package, bool $apply, bool $disable): array
    {
        $dependent = $this->dependentPlugins($package);

        if ($dependent === []) {
            return $this->summary('依赖插件', 0, '无插件声明依赖该库包');
        }

        $required = array_filter($dependent, static fn (array $item): bool => $item['required']);
        $disabled = [];
        $failed   = [];

        if ($apply && $disable) {
            $manager = $this->pluginManager();

            foreach ($dependent as $ident => $info) {
                if ($manager === null) {
                    $failed[$ident] = '插件引擎不可用';
                    continue;
                }

                try {
                    $manager->disable($ident);
                    $disabled[] = $ident;
                } catch (\Throwable $e) {
                    $failed[$ident] = $e->getMessage();
                }
            }
        }

        $note = sprintf(
            '声明依赖 %s 的插件 %d 个（硬依赖 %d）',
            $package,
            count($dependent),
            count($required)
        );

        if ($apply && $disable) {
            $note .= sprintf('；已停用 %d 个', count($disabled));
        } elseif ($apply) {
            $note .= '；未停用（加 --disable-dependent-plugins 可一并停用）';
        } else {
            $note .= '（预览）';
        }

        return [
            'label'    => '依赖插件',
            'mode'     => $apply && $disable ? 'done' : ($apply ? 'report' : 'dry-run'),
            'count'    => count($dependent),
            'items'    => $dependent,
            'disabled' => $disabled,
            'failed'   => $failed,
            'note'     => $note,
        ];
    }

    /**
     * 插件引擎（未注册时返回 null）
     */
    protected function pluginManager(): ?object
    {
        try {
            $key = 'wise\\plugin\\PluginContainerKeys';

            if (!class_exists($key)) {
                return null;
            }

            $containerKey = constant($key . '::MANAGER');
            $app          = app();

            return $app->has($containerKey) ? $app->get($containerKey) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 硬编码类引用扫描（只报告，不改代码）
     *
     * 真正会让站点崩的不是"遗留数据"，而是 `app/member/middleware.php` 这类
     * 直接写死 `wise\member\...` 的代码 —— 必须让用户看见。
     *
     * @param  string[] $excludeTargets 待删除的静态资源目标（其内容派生自包本身，不算宿主引用）
     * @return array<string, mixed>
     */
    public function scanHardReferences(string $ident, array $excludeTargets = []): array
    {
        $needles = array_values(array_unique(array_filter([
            $ident,
            $this->namespaceOf($ident),
        ])));

        $root = $this->appRoot();

        // 排除前缀：清理能力自身的文件 + 该包的已发布静态资源
        $excludePrefix = self::SCAN_SELF;
        foreach ($excludeTargets as $target) {
            $rel = $this->safePublicPath((string) $target);
            if ($rel !== null) {
                $excludePrefix[] = $rel;
            }
        }

        $hits = [];
        $truncated = false;

        foreach (self::SCAN_ROOTS as $scanRoot) {
            $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $scanRoot);
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $current): bool {
                        if ($current->isDir()) {
                            return !in_array($current->getFilename(), ['vendor', 'node_modules', 'runtime', '.git', 'packages'], true);
                        }

                        $ext = strtolower($current->getExtension());
                        if (!in_array($ext, self::SCAN_EXT, true)) {
                            return false;
                        }

                        return !str_ends_with($current->getFilename(), '.min.js');
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $relative = $this->relative($root, (string) $file->getPathname());

                if ($this->isExcluded($relative, $excludePrefix)) {
                    continue;
                }

                $lines = @file((string) $file->getPathname()) ?: [];

                foreach ($lines as $no => $line) {
                    foreach ($needles as $needle) {
                        if ($needle !== '' && stripos($line, $needle) !== false) {
                            $hits[] = sprintf('%s:%d: %s', $relative, $no + 1, trim($line));

                            if (count($hits) >= self::SCAN_LIMIT) {
                                $truncated = true;
                                break 3;
                            }

                            break;
                        }
                    }
                }
            }
        }

        return [
            'label'     => '硬编码引用扫描',
            'mode'      => 'report',
            'count'     => count($hits),
            'needles'   => $needles,
            'hits'      => $hits,
            'truncated' => $truncated,
            'note'      => count($hits) > 0
                ? '发现代码级引用，需人工改造（本命令不自动改代码）'
                : '未发现代码级引用',
        ];
    }

    /**
     * 扫描排除：清理能力自身文件、待删除的静态资源目录
     *
     * @param string[] $excludePrefix
     */
    protected function isExcluded(string $relative, array $excludePrefix): bool
    {
        foreach ($excludePrefix as $prefix) {
            if ($prefix !== '' && ($relative === $prefix || str_starts_with($relative, rtrim($prefix, '/') . '/'))) {
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // 条目解析
    // ========================================================================

    /**
     * 解析清理依据：快照 → 现场（包目录仍在时）→ 仅包名 basename
     *
     * @return array<string, mixed>
     */
    public function resolveEntry(string $package): array
    {
        $entry = $this->registry->get($package);
        if ($entry !== null) {
            $entry['source'] = 'registry';

            return $entry;
        }

        $live = $this->livePackageRoot($package);
        if ($live !== null) {
            $entry = $this->registry->inspect($package, $live);
            $entry['source'] = 'live';

            return $entry;
        }

        $ident = $this->registry->identFor($package);

        return [
            'package'   => $package,
            'ident'     => $ident,
            // 约定兜底：静态资源的发布目标按 `public/statics/plugins/{ident}` 命名
            // （见包 composer.json 的 extra.think.publish 约定）。快照被清空
            // （`php think clear` 会清整个 runtime/）且包目录已删（vendor 安装）时，
            // 至少还能按约定清理主静态资源目录；表名无法凭空得知，只能报告。
            'targets'   => ['public/statics/plugins/' . $ident],
            'versions'  => [],
            'tables'    => [],
            'namespace' => $ident,
            'source'    => 'fallback',
        ];
    }

    /**
     * 包目录仍然存在时的现场路径（path 仓库：`composer remove` 只删 vendor 里的软链）
     */
    protected function livePackageRoot(string $package): ?string
    {
        $candidates = [
            rtrim(app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR
                . 'packages' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $package),
            rtrim(app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR
                . 'vendor' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $package),
        ];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real) && is_file($real . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $real;
            }
        }

        return null;
    }

    /**
     * 已安装包集合（installed.json）
     *
     * @return array<string, true>
     */
    protected function installedPackageNames(): array
    {
        $names = [];
        foreach ($this->registry->loadInstalledPackages() ?? [] as $package) {
            if (is_array($package) && isset($package['name'])) {
                $names[(string) $package['name']] = true;
            }
        }

        return $names;
    }

    /**
     * lock 中的包集合（composer.lock；文件缺失返回空数组）
     *
     * @return array<string, true>
     */
    protected function lockedPackageNames(): array
    {
        $file = rtrim(app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        $names = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) ($data[$section] ?? []) as $package) {
                if (is_array($package) && isset($package['name'])) {
                    $names[(string) $package['name']] = true;
                }
            }
        }

        return $names;
    }

    /**
     * 无人认领的插件静态目录（报告项）
     *
     * @return string[]
     */
    protected function unclaimedAssetDirs(): array
    {
        $root = $this->appRoot() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'statics' . DIRECTORY_SEPARATOR . 'plugins';
        if (!is_dir($root)) {
            return [];
        }

        // 已登记目标的 basename 集合
        $claimed = [];
        foreach ($this->registry->all() as $entry) {
            foreach ((array) ($entry['targets'] ?? []) as $target) {
                $claimed[] = basename(str_replace('\\', '/', (string) $target));
            }
        }

        // 真实插件（plugins/ 目录）的静态资源由插件引擎管理，不算孤儿
        $pluginRoot = $this->appRoot() . DIRECTORY_SEPARATOR . 'plugins';
        foreach (glob($pluginRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $claimed[] = basename($dir);
        }

        $unclaimed = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!in_array($name, $claimed, true)) {
                $unclaimed[] = 'public/statics/plugins/' . $name;
            }
        }

        return $unclaimed;
    }

    // ========================================================================
    // 工具
    // ========================================================================

    /**
     * 相对路径 → 可安全删除的 public 路径（拒绝绝对路径 / `..` / 过浅目录）
     */
    protected function safePublicPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = trim($normalized, '/');

        if ($normalized === '' || $this->isAbsolutePath($normalized)) {
            return null;
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        // 必须是 public/ 下、深度 >= 4（如 public/statics/plugins/xxx），
        // 避免误删 public/ 或 public/statics 这类共享目录
        if ($segments[0] !== 'public' || count($segments) < 4) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * 标识 → 命名空间（wise-member → wise\member；wise-game-core → wise\game\core）
     */
    protected function namespaceOf(string $ident): string
    {
        $bare = preg_replace('/^wise-/', '', $ident);
        $bare = is_string($bare) && $bare !== '' ? $bare : $ident;

        return 'wise\\' . str_replace('-', '\\', $bare);
    }

    /**
     * @return array{0:int, 1:int} [文件数, 字节数]
     */
    protected function measurePath(string $path): array
    {
        if (is_file($path)) {
            return [1, (int) (@filesize($path) ?: 0)];
        }

        if (!is_dir($path)) {
            return [0, 0];
        }

        $files = 0;
        $bytes = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $files++;
                $bytes += (int) ($item->getSize() ?: 0);
            }
        }

        return [$files, $bytes];
    }

    /**
     * 递归删除文件或目录
     */
    protected function deletePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * 清 RBAC 权限缓存（权限/角色绑定被删后必须失效）
     */
    protected function clearAuthCaches(): void
    {
        try {
            app(\wise\auth\service\RbacManager::class)->clearAllCache();
        } catch (\Throwable $e) {
            // 缓存清理失败不影响数据一致性（TTL 自然过期）
        }
    }

    protected function tableExists(string $table): bool
    {
        return isset($this->allTables()[$this->dbPrefix() . $table]);
    }

    /**
     * 库中全部表名（含前缀）集合
     *
     * @return array<string, true>
     */
    protected function allTables(): array
    {
        $names = [];

        try {
            foreach (Db::query('SHOW TABLES') as $row) {
                $name = reset($row);
                if (is_string($name) && $name !== '') {
                    $names[$name] = true;
                }
            }
        } catch (\Throwable $e) {
            // 数据库不可用时按"表不存在"处理（不会误删）
        }

        return $names;
    }

    protected function dbPrefix(): string
    {
        try {
            return (string) Db::connect()->getConfig('prefix');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function appRoot(): string
    {
        return rtrim(app()->getRootPath(), '/\\');
    }

    protected function relative(string $root, string $path): string
    {
        $root = str_replace('\\', '/', $root);

        return ltrim(str_replace($root, '', str_replace('\\', '/', $path)), '/');
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f%s', $value, $units[$i]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function skip(string $label): array
    {
        return ['label' => $label, 'mode' => 'skipped', 'count' => 0, 'note' => '按参数跳过'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(string $label, int $count, string $note = ''): array
    {
        return ['label' => $label, 'mode' => 'summary', 'count' => $count, 'note' => $note];
    }
}
