<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 标准 Composer 包「接入声明」探测器（**只读**）
 *
 * 回答一个问题：`vendor/` 里这么多包，哪些是**接入了 WiseAdmin** 的？
 *
 * 判据（满足其一即为「接入型包」）：
 *   - `extra.think.services`   —— 有 ServiceProvider（L2）
 *   - `extra.think.config`     —— 有可发布配置
 *   - `extra.think.publish`    —— 有可发布静态资源
 *   - `extra.think.menus`      —— 有后台菜单声明（menu.php）
 *   - 包内有 `database/migrations`（有表要建）
 *
 * 数据来源与 {@see \wise\command\package\VendorPublish} 一致：
 *   1. `vendor/composer/installed.json` 提供「已安装集合」与包根；
 *   2. 各声明**优先读包自身 `composer.json`**（path 仓库下 installed.json 快照会滞后），
 *      缺失时回落到 installed.json 里的 `extra` 快照。
 *
 * 标识（ident）沿用 {@see PackageRegistry::identFor()} 的既有约定：
 * `extra.think.ident` → 包名 basename（`wiseadmin/member` → `member`）。
 *
 * 本类不写盘、不落库（`pendingVersions()` 只读查询 migrations 表，且失败时 fail-open）。
 */
class PackageInspector
{
    /** 迁移目录（相对包根） */
    public const DIR_MIGRATIONS = 'database/migrations';

    /** 数据填充目录（相对包根） */
    public const DIR_SEEDS = 'database/seeds';

    protected ?PackageRegistry $registry;

    /**
     * 已安装包清单文件（默认 `vendor/composer/installed.json`；测试可注入）
     */
    protected ?string $installedFile;

    public function __construct(?PackageRegistry $registry = null, ?string $installedFile = null)
    {
        $this->registry      = $registry ?? new PackageRegistry();
        $this->installedFile = $installedFile;
    }

    /**
     * 全部「接入型」包
     *
     * @return array<string, array<string, mixed>> 包名 => 元数据
     */
    public function candidates(): array
    {
        $packages = $this->registry->loadInstalledPackages($this->installedFile);
        if ($packages === null) {
            return [];
        }

        $result = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $root = $this->registry->resolvePackageRoot($package, $this->installedFile);
            if ($root === null) {
                continue;
            }

            $meta = $this->inspect($name, $root, (string) ($package['version'] ?? ''), $package);

            if ($meta['declared']) {
                $result[$name] = $meta;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * 只取「声明了 `extra.think.menus`」的包（**热路径专用**）
     *
     * 与 {@see candidates()} 的区别：只解一次 `installed.json`，然后仅对**命中菜单声明**
     * 的包读取其 `composer.json`/`menu.php`；没有任何命中时成本 ≈ 一次 JSON 解码（~2ms）。
     *
     * 这是为 {@see PackageMenuRegistrar} 准备的：它会被后台每个 GET 的懒同步调用，
     * 而全量 `candidates()` 需要读取 100+ 个 composer.json（实测 ~90-120ms）。
     *
     * 代价：菜单声明以 `installed.json` 快照为准，包自身 composer.json 后加的 `menus`
     * 需要等下一次 `composer dump-autoload`（即 `composer require` 之后）才生效。
     *
     * @return array<string, array<string, mixed>>
     */
    public function menuCandidates(): array
    {
        $packages = $this->registry->loadInstalledPackages($this->installedFile);
        if ($packages === null) {
            return [];
        }

        $result = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $declared = $package['extra']['think']['menus'] ?? null;
            if (!is_string($declared) || trim($declared) === '') {
                continue;
            }

            $root = $this->registry->resolvePackageRoot($package, $this->installedFile);
            if ($root === null) {
                continue;
            }

            $result[$name] = $this->inspect($name, $root, (string) ($package['version'] ?? ''), $package);
        }

        return $result;
    }

    /**
     * 按包名取单个接入型包（非接入型包返回 null）
     *
     * @return array<string, mixed>|null
     */
    public function find(string $packageName): ?array
    {
        $packageName = trim($packageName);
        if ($packageName === '') {
            return null;
        }

        foreach ($this->registry->loadInstalledPackages($this->installedFile) ?? [] as $package) {
            if (!is_array($package) || (string) ($package['name'] ?? '') !== $packageName) {
                continue;
            }

            $root = $this->registry->resolvePackageRoot($package, $this->installedFile);
            if ($root === null) {
                return null;
            }

            $meta = $this->inspect($packageName, $root, (string) ($package['version'] ?? ''), $package);

            return $meta['declared'] ? $meta : null;
        }

        return null;
    }

    /**
     * 包是否已登记到 `.wise/package_registry.json`
     *
     * 注意：登记快照由 `wise:vendor:publish` 的 `recordAll()` 自动补齐，**不能**用来判断
     * 「是否已执行过安装动作」——它只用于卸载清理。本方法仅作展示用途。
     */
    public function isRegistered(string $packageName): bool
    {
        return $this->registry->get($packageName) !== null;
    }

    /**
     * 未执行的迁移版本号
     *
     * @param array<string, mixed> $meta
     * @return string[]|null null = 无法判定（无迁移文件 / 数据库不可用）
     */
    public function pendingVersions(array $meta): ?array
    {
        $versions = $meta['migrations'] ?? [];
        if ($versions === []) {
            return null;
        }

        try {
            $table = (string) config('database.migration_table', 'migrations');
            $done  = Db::name($table)->column('version');
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($done)) {
            return null;
        }

        $done = array_map('strval', $done);
        $left = array_values(array_filter(
            array_map('strval', $versions),
            static fn (string $version): bool => !in_array($version, $done, true)
        ));

        sort($left);

        return $left;
    }

    /**
     * 采集单个包的接入元数据
     *
     * @param array<string, mixed> $package installed.json 中的包条目（声明缺失时回落用）
     * @return array<string, mixed>
     */
    public function inspect(string $name, string $root, string $version = '', array $package = []): array
    {
        $composer = $this->readComposer($root);
        $think    = $composer['extra']['think'] ?? null;
        if (!is_array($think)) {
            $snapshot = $package['extra']['think'] ?? null;
            $think    = is_array($snapshot) ? $snapshot : [];
        }

        $services = $this->normalizeList($think['services'] ?? []);
        $config   = $this->normalizeMap($think['config'] ?? []);
        $publish  = $this->normalizeMap($think['publish'] ?? []);
        $menus    = isset($think['menus']) && is_string($think['menus']) ? trim($think['menus']) : '';

        $migrationDir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIR_MIGRATIONS);
        $seedDir      = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIR_SEEDS);

        $migrations = $this->scanMigrationVersions($migrationDir);
        $seeds      = $this->scanSeedFiles($seedDir);

        $menuFile       = '';
        $menuContainers = 0;
        $menuItems      = 0;
        $menuError      = '';

        if ($menus !== '') {
            $candidate = str_starts_with($menus, '/')
                || preg_match('#^[A-Za-z]:[\\\\/]#', $menus) === 1
                ? $menus
                : rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $menus);
            $menuFile = $candidate;

            try {
                $spec           = PackageMenuProvider::load((string) realpath($candidate) ?: $candidate);
                $menuContainers = count($spec['containers'] ?? []);
                $menuItems      = count($spec['items'] ?? []);
            } catch (\Throwable $e) {
                $menuError = $e->getMessage();
            }
        }

        $isFramework = str_starts_with($name, 'topthink/');

        $declared = !$isFramework && (
            $services !== []
            || $config !== []
            || $publish !== []
            || $menus !== ''
            || isset($think['ident'])
            || $migrations !== []
            || $seeds !== []
        );

        return [
            'name'          => $name,
            'version'       => $version,
            'root'          => $root,
            'ident'         => $this->identFrom($name, $composer),
            'services'      => $services,
            'config'        => $config,
            'publish'       => $publish,
            'menus'         => $menus,
            'migration_dir' => is_dir($migrationDir) ? $migrationDir : '',
            'seed_dir'      => is_dir($seedDir) ? $seedDir : '',
            'migrations'    => $migrations,
            'seeds'         => $seeds,
            'menu_file'     => $menuFile,
            'menu_error'    => $menuError,
            'menu_containers' => $menuContainers,
            'menu_items'    => $menuItems,
            'declared'      => $declared,
        ];
    }

    /**
     * 声明摘要（供 list 展示）：如 `services,config,publish,migrations`
     *
     * @param array<string, mixed> $meta
     */
    public function declarationSummary(array $meta): string
    {
        $parts = [];
        if (($meta['services'] ?? []) !== []) {
            $parts[] = 'svc';
        }
        if (($meta['config'] ?? []) !== []) {
            $parts[] = 'cfg';
        }
        if (($meta['publish'] ?? []) !== []) {
            $parts[] = 'pub';
        }
        if (($meta['menus'] ?? '') !== '') {
            $parts[] = ($meta['menu_error'] ?? '') !== ''
                ? 'menu!'
                : sprintf('menu(%d/%d)', (int) ($meta['menu_containers'] ?? 0), (int) ($meta['menu_items'] ?? 0));
        }
        if (($meta['migrations'] ?? []) !== []) {
            $parts[] = 'mig';
        }
        if (($meta['seeds'] ?? []) !== []) {
            $parts[] = 'seed';
        }

        return $parts === [] ? '-' : implode(',', $parts);
    }

    /**
     * 迁移目录内的版本号（文件名前缀数字，Phinx / think-migration 约定）
     *
     * @return string[]
     */
    protected function scanMigrationVersions(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $versions = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (preg_match('/^(\d{8,})/', $base, $m) === 1) {
                $versions[] = $m[1];
            }
        }

        sort($versions);

        return array_values(array_unique($versions));
    }

    /**
     * 数据填充文件（类名列表，按文件名排序）
     *
     * @return string[]
     */
    protected function scanSeedFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $seeds = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $class = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^[A-Z]\w*$/', $class) !== 1) {
                continue;
            }
            $seeds[] = $class;
        }

        sort($seeds);

        return $seeds;
    }

    /**
     * 从已解析的 composer.json 推导 ident（`extra.think.ident` → 包名短名）
     *
     * 与 {@see PackageRegistry::identFor()} 同规则，但复用已读取的 composer.json，
     * 避免每个包多一次磁盘读。
     *
     * @param array<string, mixed> $composer
     */
    protected function identFrom(string $packageName, array $composer): string
    {
        $declared = $composer['extra']['think']['ident'] ?? null;

        if (is_string($declared) && trim($declared) !== '') {
            return trim($declared);
        }

        $pos = strrpos($packageName, '/');

        return $pos === false ? $packageName : substr($packageName, $pos + 1);
    }

    /**
     * 读取包自身 composer.json（解析失败返回空数组）
     *
     * @return array<string, mixed>
     */
    protected function readComposer(string $root): array
    {
        $file = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * 归一化列表型声明（services）
     *
     * @param  mixed $value
     * @return string[]
     */
    protected function normalizeList($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 归一化映射型声明（config / publish），兼容列表写法
     *
     * @param  mixed $value
     * @return array<string, string>
     */
    protected function normalizeMap($value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        $result = [];

        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $result[$item] = $item;
                }
            }

            return $result;
        }

        foreach ($value as $source => $target) {
            if (is_string($source) && is_string($target) && $source !== '' && $target !== '') {
                $result[$source] = $target;
            }
        }

        return $result;
    }
}
