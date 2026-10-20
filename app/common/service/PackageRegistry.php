<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 库包发布登记（快照）
 *
 * ## 为什么需要
 *
 * `composer remove` 之后，包目录 / `vendor/composer/installed.json` / `composer.lock`
 * **都不再含该包**，任何"反查"都无从下手 —— 但卸载清理恰恰需要知道：
 *   - 它的静态资源发布到了哪些宿主目录（`extra.think.publish` 的 target）；
 *   - 它建了哪些表（包内 `database/migrations/*` 的表名）；
 *   - 它的菜单 / 配置用什么标识（菜单 `plugin` 字段、wise-config namespace）。
 *
 * 因此在**包还在的时候**把这三样登记下来，卸载时按快照清理。
 *
 * ## 存储
 *
 * `{应用根}/.wise/package_registry.json`（**不在 `runtime/` 内** —— 该目录会被
 * `php think clear` 整目录清空，而本快照不可再生；读取时兼容旧位置 `runtime/`），条目结构：
 *
 * ```json
 * {
 *   "wiseadmin/wise-member": {
 *     "ident": "wise-member",
 *     "targets": ["public/statics/plugins/wise-member", "public/statics/member/themes/default"],
 *     "versions": ["20251027000001", "20251027000002"],
 *     "tables": ["member", "member_group"],
 *     "namespace": "wise-member",
 *     "first_recorded_at": 1759300000,
 *     "recorded_at": 1759300000
 *   }
 * }
 * ```
 *
 * ## 自愈
 *
 * {@see PackageRegistry::recordAll()} 在每次静态资源发布（composer `post-autoload-dump`）
 * 时为**当前已安装的全部包**补登记；此外 `composer remove` 的 **`pre-package-uninstall`**
 * 事件会在包文件被删之前现场登记一次（{@see \wise\composer\PackageHook::onPackagePreUninstall}），
 * 因此即使快照曾被清空（`php think clear` 会整目录清 runtime）也能保证依据齐全。
 * 已登记但不再安装的条目**不会被删除**（那正是卸载清理的依据），只能由
 * {@see PackageRegistry::forget()} 显式移除。
 *
 * ## 标识约定
 *
 * `ident` 优先取包的 `extra.think.ident`（包自述，最精确）；未声明时回退为
 * composer 包名的 basename（`wiseadmin/wise-member` → `wise-member`）——
 * 与本仓 `wise-*` 包的菜单 `MenuItemProvider::ident()` / wise-config namespace 约定一致。
 */
class PackageRegistry
{
    /**
     * 快照目录名（应用根下）
     *
     * 刻意**不放 `runtime/`**：`php think clear` 会整目录清空 runtime
     * （`think\console\command\Clear` 默认 `path = runtime/`，逐个 unlink），
     * 而本快照是**不可再生**的业务元数据 —— 包一旦被删，发布目标与表名就再也查不到。
     */
    public const DIR = '.wise';

    /** 快照文件名 */
    public const FILE = 'package_registry.json';

    /**
     * 应用根目录（显式传入时可在**无框架容器**的环境使用，如 composer 事件钩子进程）
     */
    protected ?string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = ($rootPath !== null && $rootPath !== '') ? rtrim($rootPath, '/\\') : null;
    }

    /**
     * 应用根目录
     */
    public function rootPath(): string
    {
        return $this->rootPath ?? rtrim(app()->getRootPath(), '/\\');
    }

    /**
     * 登记单个包（内容无变化时不写盘，只刷新返回值）
     *
     * @return array<string, mixed> 条目
     */
    public function record(string $packageName, string $packageRoot): array
    {
        $entry = $this->inspect($packageName, $packageRoot);

        $data  = $this->read();
        $exist = $data[$packageName] ?? null;

        if (is_array($exist) && $this->sameEntry($exist, $entry)) {
            // 无变化：保留首次登记时间，不写盘（避免每次 dump-autoload 抖动文件）
            $entry['first_recorded_at'] = (int) ($exist['first_recorded_at'] ?? $entry['recorded_at']);
            return $entry;
        }

        $entry['first_recorded_at'] = (int) ($exist['first_recorded_at'] ?? $entry['recorded_at']);
        $data[$packageName]         = $entry;
        $this->write($data);

        return $entry;
    }

    /**
     * 为「当前已安装的全部包」补登记（自愈；不删除已消失包的条目）
     *
     * @return array{registered:int, unchanged:int, skipped:int}
     */
    public function recordAll(?string $installedFile = null): array
    {
        $installedFile ??= $this->installedFile();

        // 首次运行（升级自旧版本）时把 runtime/ 里的快照迁到 .wise/，
        // 否则"内容无变化即不写盘"会让旧位置长期占位（一旦被 clear 清掉就白迁移）。
        $migrated = $this->migrateLegacyFile();

        $stat = ['registered' => 0, 'unchanged' => 0, 'skipped' => 0, 'migrated' => $migrated];

        $packages = $this->loadInstalledPackages($installedFile);
        if ($packages === null) {
            return $stat;
        }

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $root = $this->resolvePackageRoot($package, $installedFile);
            if ($root === null) {
                $stat['skipped']++;
                continue;
            }

            $before = $this->get($name);
            $entry  = $this->inspect($name, $root);

            // 只登记"有清理价值"的包：wise 库包（可能注册菜单/配置）、声明了发布映射，
            // 或带数据库迁移。否则 vendor 里上百个第三方包都会被写进快照
            // （既无意义，也让对账输出变噪音）。
            if (!$this->worthRecording($name, $entry, $root)) {
                $stat['skipped']++;
                continue;
            }

            if ($before !== null && $this->sameEntry($before, $entry)) {
                $stat['unchanged']++;
                continue;
            }

            $this->record($name, $root);
            $stat['registered']++;
        }

        return $stat;
    }

    /**
     * 是否值得登记
     *
     * 判据（满足其一）：
     *  - `wiseadmin/*` 库包：即便没有静态资源与迁移，也可能经 `ExtPoints::MENU_ITEM`
     *    注册菜单、写 wise-config 配置行 —— 这些都要靠卸载清理（此时 ident 会退化为
     *    包名 basename，仍需有条目才会被 `post-package-uninstall` 钩子处理）；
     *  - 声明了发布映射 `extra.think.publish` 或自述标识 `extra.think.ident`；
     *  - 包内带 `database/migrations`（有表要清）。
     *
     * @param array<string, mixed> $entry
     */
    protected function worthRecording(string $packageName, array $entry, string $packageRoot): bool
    {
        if (str_starts_with($packageName, 'wiseadmin/')) {
            return true;
        }

        if (($entry['targets'] ?? []) !== [] || ($entry['tables'] ?? []) !== []) {
            return true;
        }

        $composer = $this->readComposerJson($packageRoot);

        return isset($composer['extra']['think']['ident']);
    }

    /**
     * 全部快照片段
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * 已登记（不区分是否仍安装）的包名列表
     *
     * @return string[]
     */
    public function packages(): array
    {
        return array_keys($this->read());
    }

    /**
     * 取单个条目
     *
     * @return array<string, mixed>|null
     */
    public function get(string $packageName): ?array
    {
        $data = $this->read();
        $entry = $data[$packageName] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * 移除条目（卸载清理完成后调用）
     */
    public function forget(string $packageName): void
    {
        $data = $this->read();
        if (!array_key_exists($packageName, $data)) {
            return;
        }

        unset($data[$packageName]);
        $this->write($data);
    }

    /**
     * 解析标识：`extra.think.ident` → 包名 basename
     */
    public function identFor(string $packageName, ?string $packageRoot = null): string
    {
        if ($packageRoot !== null) {
            $composer = $this->readComposerJson($packageRoot);
            $declared = $composer['extra']['think']['ident'] ?? null;
            if (is_string($declared) && trim($declared) !== '') {
                return trim($declared);
            }
        }

        $pos = strrpos($packageName, '/');

        return $pos === false ? $packageName : substr($packageName, $pos + 1);
    }

    /**
     * 采集单个包的登记信息（纯读，不写盘）
     *
     * @return array<string, mixed>
     */
    public function inspect(string $packageName, string $packageRoot): array
    {
        $composer = $this->readComposerJson($packageRoot);
        $ident    = $this->identFor($packageName, $packageRoot);

        $targets = array_values(array_unique(array_values(
            $this->normalizePublishMap($composer['extra']['think']['publish'] ?? [])
        )));

        [$versions, $tables] = $this->scanMigrations($packageRoot);

        return [
            'package'    => $packageName,
            'ident'      => $ident,
            'targets'    => $targets,
            'versions'   => $versions,
            'tables'     => $tables,
            // wise-config 命名空间约定与 ident 同名
            'namespace'  => $ident,
            'recorded_at' => time(),
        ];
    }

    /**
     * 扫描包内迁移，提取「版本号集合」与「建表名集合」
     *
     * - 版本号 = 文件名前缀数字（ThinkPHP/Phinx 的 version）；
     * - 表名 = `$this->table('x')` 与 `getTable()` 的 `return 'x'` 两种写法（多表迁移都覆盖）。
     *
     * @return array{0:string[], 1:string[]}
     */
    public function scanMigrations(string $packageRoot): array
    {
        $dir = rtrim($packageRoot, '/\\') . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        if (!is_dir($dir)) {
            return [[], []];
        }

        $versions = [];
        $tables   = [];

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (preg_match('/^(\d+)/', $base, $m) === 1) {
                $versions[] = $m[1];
            }

            $code = (string) file_get_contents($file);

            // $this->table('member', ...)
            if (preg_match_all('/\$this->table\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $code, $mm) > 0) {
                $tables = array_merge($tables, $mm[1]);
            }

            // protected function getTable(): string { return 'member'; }
            if (preg_match(
                '/function\s+getTable\s*\([^)]*\)\s*(?::\s*string\s*)?\{\s*return\s*[\'"]([A-Za-z0-9_]+)[\'"]/s',
                $code,
                $mm
            ) === 1) {
                $tables[] = $mm[1];
            }
        }

        sort($versions);

        return [array_values(array_unique($versions)), array_values(array_unique($tables))];
    }

    /**
     * 把旧位置（`runtime/`）的快照迁移到新位置（`.wise/`）
     *
     * 只在「新位置不存在 且 旧位置存在」时执行一次；旧文件保留（无害，读取已不再优先它）。
     *
     * @return bool 是否发生迁移
     */
    protected function migrateLegacyFile(): bool
    {
        if (is_file($this->stateFile()) || !is_file($this->legacyStateFile())) {
            return false;
        }

        $data = $this->read();

        if ($data === []) {
            return false;
        }

        $this->write($data);

        return true;
    }

    /**
     * 快照文件路径（`{应用根}/.wise/package_registry.json`）
     */
    public function stateFile(): string
    {
        return $this->rootPath() . DIRECTORY_SEPARATOR . self::DIR . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * 旧位置（`runtime/package_registry.json`）
     *
     * 仅用于**读取迁移**（历史版本写在 runtime，或不曾在 clear 之后重跑 dump-autoload），
     * 不再写入。
     */
    protected function legacyStateFile(): string
    {
        try {
            $runtime = rtrim(app()->getRuntimePath(), '/\\');
        } catch (\Throwable $e) {
            $runtime = $this->rootPath() . DIRECTORY_SEPARATOR . 'runtime';
        }

        return $runtime . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * installed.json 路径
     */
    public function installedFile(): string
    {
        return $this->rootPath()
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json';
    }

    /**
     * 读取 installed.json 的包列表（兼容 Composer 2.1+ 的 packages 键与旧版顶层数组）
     *
     * @return array<int, mixed>|null null = 文件缺失或解析失败
     */
    public function loadInstalledPackages(?string $installedFile = null): ?array
    {
        $installedFile ??= $this->installedFile();

        if (!is_file($installedFile)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($installedFile), true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['packages']) && is_array($data['packages'])) {
            return $data['packages'];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * 包根目录（installed.json 的 install-path 相对 vendor/composer/）
     */
    public function resolvePackageRoot(array $package, ?string $installedFile = null): ?string
    {
        $installedFile ??= $this->installedFile();

        $installPath = (string) ($package['install-path'] ?? '');
        if ($installPath === '') {
            return null;
        }

        if ($this->isAbsolutePath($installPath)) {
            $real = realpath($installPath);

            return ($real !== false && is_dir($real)) ? $real : null;
        }

        $composerDir = dirname($installedFile);
        $vendorDir   = dirname($composerDir);

        foreach ([
            $composerDir . DIRECTORY_SEPARATOR . $installPath,
            $vendorDir . DIRECTORY_SEPARATOR . $installPath,
            $this->rootPath() . DIRECTORY_SEPARATOR . $installPath,
        ] as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * 归一化发布映射（map: source=>target 与 list: [source] 两种写法）
     *
     * @param  mixed $map
     * @return array<string, string>
     */
    public function normalizePublishMap($map): array
    {
        if (!is_array($map) || $map === []) {
            return [];
        }

        $result = [];

        if (array_is_list($map)) {
            foreach ($map as $source) {
                if (is_string($source) && $source !== '') {
                    $result[$source] = $source;
                }
            }

            return $result;
        }

        foreach ($map as $source => $target) {
            if (is_string($source) && is_string($target) && $source !== '' && $target !== '') {
                $result[$source] = $target;
            }
        }

        return $result;
    }

    /**
     * 包根下的 composer.json（解析失败返回空数组）
     *
     * @return array<string, mixed>
     */
    protected function readComposerJson(string $packageRoot): array
    {
        $file = rtrim($packageRoot, '/\\') . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * 条目内容比对（忽略 recorded_at / first_recorded_at）
     */
    protected function sameEntry(array $a, array $b): bool
    {
        unset($a['recorded_at'], $a['first_recorded_at'], $b['recorded_at'], $b['first_recorded_at']);

        return $a == $b;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function read(): array
    {
        // 优先新位置（.wise/），缺失时兼容读取旧位置（runtime/，历史版本写入）
        $file = $this->stateFile();
        if (!is_file($file)) {
            $file = $this->legacyStateFile();
        }

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        return array_filter($data, 'is_array');
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    protected function write(array $data): void
    {
        $file = $this->stateFile();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
