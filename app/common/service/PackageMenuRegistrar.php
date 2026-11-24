<?php
declare(strict_types=1);

namespace app\common\service;

use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\contracts\MenuItemProvider;

/**
 * 把标准 Composer 包的 `menu.php` 注册进 `ExtPoints::MENU_ITEM`
 *
 * ## 为什么需要
 *
 * 库包没有 `manifest.json`，菜单只能靠 PHP：要么自己实现 {@see MenuItemProvider}
 * 并用 ServiceProvider 注册，要么（本类负责的）在包根放一个 `menu.php` 声明。
 * 后者让「零 PHP 包」也能拥有后台菜单，同时**不引入任何新机制** ——
 * 最终仍然是一个标准的 `MenuItemProvider` 交给 {@see \app\admin\service\MenuItemSync} 落库。
 *
 * ## 注册时机
 *
 * 由 {@see \app\admin\service\MenuItemSync::syncAll()} / `staleIdents()` 在收集实现前调用：
 *   - CLI（`php think menu:sync`、composer 钩子）与后台懒同步都会覆盖；
 *   - 不在 ServiceProvider::boot() 里做，避免每个非后台请求都读一遍 installed.json。
 *
 * ## 与 Provider 的优先级
 *
 * 同一 ident 若已被**真实 Provider**注册（包自己实现了 `MenuItemProvider`），
 * 则 `menu.php` **跳过**并记入 skipped（Provider 优先，避免双写导致菜单抖动）。
 *
 * ## 成本
 *
 * 走 {@see PackageInspector::menuCandidates()} 的热路径：只解一次 `installed.json`
 * （约 200KB / 2ms），仅对**命中 `extra.think.menus` 的包**读 composer.json 与 menu.php。
 * 全量 `candidates()`（100+ 个 composer.json，实测 ~90-120ms）**不能**放在这条链路上 ——
 * 它会被后台每个 GET 的懒同步调用。结果另有进程内静态缓存（`$cached`）。
 */
final class PackageMenuRegistrar
{
    /**
     * 进程内缓存（同一请求 / 同一 CLI 进程只扫描一次）
     *
     * 按扫描模式分档：`fast`（快照，热路径）/ `full`（读包自身 composer.json，CLI 同步用）。
     * 两种模式的结果可能不同（新增声明尚未进快照时），因此不能共用一份缓存。
     *
     * @var array<string, array{registered: array<string, array<string, mixed>>, skipped: array<string, string>, errors: array<string, string>}>
     */
    private static array $cached = [];

    private ?PackageInspector $inspector;

    private ?string $installedFile;

    public function __construct(?PackageInspector $inspector = null, ?string $installedFile = null)
    {
        $this->inspector     = $inspector;
        $this->installedFile = $installedFile;
    }

    /**
     * 清空进程内缓存（测试 / 长驻进程重载时用）
     */
    public static function flush(): void
    {
        self::$cached = [];
    }

    /**
     * 注册全部 `menu.php` 声明
     *
     * @param  bool $force true = 忽略进程内缓存，重新扫描
     * @param  bool $full  true = 读包自身 composer.json（CLI 真同步，声明变更即时生效）；
     *                     false = 只认 installed.json 快照（HTTP 热路径，成本 ≈ 一次 JSON 解码）
     * @return array{registered: array<string, array<string, mixed>>, skipped: array<string, string>, errors: array<string, string>}
     */
    public function register(bool $force = false, bool $full = false): array
    {
        $mode = $full ? 'full' : 'fast';

        if (isset(self::$cached[$mode]) && !$force) {
            return self::$cached[$mode];
        }

        $result = ['registered' => [], 'skipped' => [], 'errors' => []];

        if (!class_exists(ExtPoint::class) || !interface_exists(MenuItemProvider::class)
            || !class_exists(ExtPoints::class)) {
            return self::$cached[$mode] = $result;
        }

        $inspector = $this->inspector ?? new PackageInspector(null, $this->installedFile);

        try {
            $packages = $full ? $inspector->candidates() : $inspector->menuCandidates();
        } catch (\Throwable $e) {
            $result['errors']['*'] = '扫描 Composer 包失败：' . $e->getMessage();

            return self::$cached[$mode] = $result;
        }

        if ($packages === []) {
            return self::$cached[$mode] = $result;
        }

        $existing = $this->registeredIdents();

        foreach ($packages as $name => $meta) {
            $declared = (string) ($meta['menus'] ?? '');
            if ($declared === '') {
                continue;
            }

            $ident = (string) ($meta['ident'] ?? '');
            if ($ident === '') {
                $result['errors'][$name] = '无法确定 ident（包名解析失败）';
                continue;
            }

            if (isset($existing[$ident])) {
                $result['skipped'][$name] = sprintf('ident %s 已由真实 MenuItemProvider 注册，menu.php 已忽略', $ident);
                continue;
            }

            try {
                $file = $this->resolveMenuFile((string) $meta['root'], $declared);
                $spec = PackageMenuProvider::load($file);

                ExtPoint::register(
                    ExtPoints::MENU_ITEM,
                    $ident,
                    static fn (): PackageMenuProvider => new PackageMenuProvider($spec, $ident, $file)
                );

                $result['registered'][$ident] = [
                    'package'    => $name,
                    'file'       => $file,
                    'containers' => count($spec['containers'] ?? []),
                    'items'      => count($spec['items'] ?? []),
                ];
                $existing[$ident] = true;
            } catch (\Throwable $e) {
                $result['errors'][$name] = $e->getMessage();
            }
        }

        return self::$cached[$mode] = $result;
    }

    /**
     * 已注册的 MENU_ITEM 实现标识（实现名 + 尽力解析出的 ident）
     *
     * @return array<string, bool>
     */
    private function registeredIdents(): array
    {
        $idents = [];

        foreach (ExtPoint::all(ExtPoints::MENU_ITEM) as $name => $item) {
            $idents[(string) $name] = true;

            $concrete = $item['concrete'] ?? null;

            try {
                if ($concrete instanceof MenuItemProvider) {
                    $idents[$concrete->ident()] = true;
                } elseif (is_string($concrete) && class_exists($concrete)) {
                    $instance = new $concrete();
                    if ($instance instanceof MenuItemProvider) {
                        $idents[$instance->ident()] = true;
                        unset($instance);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $idents;
    }

    /**
     * 解析 `extra.think.menus` 指向的文件（相对包根 / 绝对路径，禁止越出包根）
     *
     * @throws \RuntimeException 越界或文件不存在
     */
    private function resolveMenuFile(string $packageRoot, string $declared): string
    {
        $packageRoot = rtrim($packageRoot, '/\\');
        $declared    = trim($declared);

        $isAbsolute = str_starts_with($declared, '/')
            || str_starts_with($declared, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $declared) === 1;

        $file = $isAbsolute
            ? $declared
            : $packageRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $declared);

        $real      = realpath($file);
        $realRoot  = realpath($packageRoot);

        if ($real === false || !is_file($real)) {
            throw new \RuntimeException('菜单声明文件不存在：' . $file);
        }

        if ($realRoot !== false) {
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($real), strtolower($realRoot) . DIRECTORY_SEPARATOR)
                : str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR);

            if (!$inside) {
                throw new \RuntimeException(sprintf('菜单声明文件越出包根，已拒绝：%s', $real));
            }
        }

        return $real;
    }
}
