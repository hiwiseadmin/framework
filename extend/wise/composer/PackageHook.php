<?php
declare(strict_types=1);

namespace wise\composer;

use app\common\service\PackageRegistry;

/**
 * Composer 事件钩子（根 composer.json 的 scripts 回调）
 *
 * ## 为什么需要它
 *
 * Composer **没有** `pre-remove-cmd` / `post-remove-cmd` 事件，但有
 * `post-package-uninstall` —— `composer remove <pkg>` 与"`composer update` 导致某包被移除"
 * 都会触发它，并且**能拿到被卸载的包名**（这是纯 shell 脚本拿不到的）。
 *
 * ## 为什么放在 extend/ 而不是包内
 *
 * - 依赖包自身 `composer.json` 里的 scripts **永远不会被执行**，只有**根包**的 scripts 会；
 * - 本类位于 `extend/wise/composer/`，命中根 `composer.json` 既有的 PSR-0 规则
 *   （`"psr-0": {"": "extend/"}` → `extend/wise/composer/PackageHook.php`），
 *   因此**不依赖任何被卸载的包**，也不需要额外 autoload 配置。
 *
 * ## 职责边界
 *
 * 只做两件事：**取包名** → **转发给 `php think wise:package:cleanup <pkg> --apply --silent`**。
 * 清理逻辑一律在 {@see \app\common\service\PackageUninstaller}，本类不碰数据库、
 * 也不调用 `composer dump-autoload`（会与当前 Composer 进程重入）。
 *
 * 异常一律吞掉并记日志：清理失败**不能**让 `composer remove` 失败；
 * 未清理干净的包会留在 `.wise/package_registry.json` 里，
 * 由 `post-autoload-dump` 的对账模式（`wise:package:cleanup --reconcile`）自动重试。
 *
 * @see \wise\command\PackageCleanup
 */
class PackageHook
{
    /**
     * post-package-uninstall 回调
     *
     * 签名保持宽松（不写 `Composer\...` 类型）：本文件只在 Composer 进程里被调用，
     * 但不应因此变成"只能在 Composer 环境加载"的类。
     *
     * @param mixed $event    Composer\Installer\PackageEvent
     * @param mixed $composer Composer\Composer
     */
    public static function onPackageUninstall($event = null, $composer = null): void
    {
        try {
            $package = self::packageName($event);

            if ($package === '') {
                self::log('SKIP', '未能从事件中解析包名');
                return;
            }

            $root = self::appRoot($composer);

            if (!self::shouldClean($root, $package)) {
                self::log('SKIP', $package . '：无库包清理登记（非 wise 库包或无需清理），跳过');
                return;
            }

            [$exitCode, $output] = self::runCleanup($root, $package);

            self::log(
                $exitCode === 0 ? 'OK' : 'FAIL',
                sprintf('%s：exit=%d %s', $package, $exitCode, trim($output))
            );
        } catch (\Throwable $e) {
            self::log('ERROR', $e->getMessage());
        }
    }

    /**
     * pre-package-uninstall 回调：在文件被删除**之前**写发布快照
     *
     * 为什么必须有这一步：快照记录了"静态资源发布到哪、建了哪些表"，而这些信息
     * 在包被删后就无从反查（包目录 / installed.json / composer.lock 全部更新）。
     * `pre-package-uninstall` 触发时**包文件仍在**，可以现场解析，因此不依赖
     * 任何持久文件是否完好 —— 即使 `runtime/` 被 `php think clear` 清空也能正确清理。
     *
     * @param mixed $event    Composer\Installer\PackageEvent
     * @param mixed $composer Composer\Composer
     */
    public static function onPackagePreUninstall($event = null, $composer = null): void
    {
        try {
            $package = self::packageName($event);

            if ($package === '') {
                self::log('SKIP', 'pre-uninstall：未能从事件中解析包名');
                return;
            }

            $root = self::appRoot($composer);

            if (!self::shouldClean($root, $package)) {
                self::log('SKIP', 'pre-uninstall ' . $package . '：无库包清理登记，跳过');
                return;
            }

            $dir = self::locatePackageDir($root, $package);

            if ($dir === null) {
                self::log('SKIP', 'pre-uninstall ' . $package . '：未定位到包目录，无法快照');
                return;
            }

            // 不经过框架容器（composer 进程里没有 ThinkPHP App），显式传入应用根
            $entry = (new PackageRegistry($root))->record($package, $dir);

            self::log('SNAPSHOT', sprintf(
                '%s：ident=%s，发布目标 %d 个，迁移表 %d 张，版本 %d 个',
                $package,
                (string) $entry['ident'],
                count((array) $entry['targets']),
                count((array) $entry['tables']),
                count((array) $entry['versions'])
            ));
        } catch (\Throwable $e) {
            self::log('ERROR', 'pre-uninstall：' . $e->getMessage());
        }
    }

    /**
     * 定位包目录（pre-package-uninstall 阶段文件仍在；path 仓库下 vendor 里是软链）
     */
    protected static function locatePackageDir(string $root, string $package): ?string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $package);

        foreach ([
            $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . $relative,
            $root . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . $relative,
        ] as $candidate) {
            $real = realpath($candidate);

            if ($real !== false && is_dir($real) && is_file($real . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $real;
            }
        }

        return null;
    }

    /**
     * 从 PackageEvent 取被卸载的包名
     *
     * @param mixed $event
     */
    public static function packageName($event): string
    {
        if (is_string($event)) {
            return trim($event);
        }

        if (!is_object($event)) {
            return '';
        }

        // Composer\Installer\PackageEvent::getOperation() → Composer\DependencyResolver\Operation\UninstallOperation::getPackage()
        try {
            if (method_exists($event, 'getOperation')) {
                $operation = $event->getOperation();

                if (is_object($operation) && method_exists($operation, 'getPackage')) {
                    $package = $operation->getPackage();

                    if (is_object($package) && method_exists($package, 'getName')) {
                        return trim((string) $package->getName());
                    }
                }
            }
        } catch (\Throwable $e) {
            // 落到下面的兜底
        }

        return '';
    }

    /**
     * 应用根目录：优先问 Composer 的 vendor-dir，回退到本文件位置推导
     *
     * @param mixed $composer
     */
    public static function appRoot($composer = null): string
    {
        try {
            if (is_object($composer) && method_exists($composer, 'getConfig')) {
                $vendorDir = (string) $composer->getConfig()->get('vendor-dir');

                if ($vendorDir !== '') {
                    return rtrim(dirname($vendorDir), '/\\');
                }
            }
        } catch (\Throwable $e) {
            // 落到兜底
        }

        // extend/wise/composer → extend/wise → extend → 应用根
        return rtrim(dirname(__DIR__, 3), '/\\');
    }

    /**
     * 是否值得清理：登记过（快照）或包目录仍在且声明了清理相关的元数据
     *
     * 目的：避免对无关的普通 composer 包做任何动作。
     *
     * 快照读取一律走 {@see PackageRegistry}（优先 `.wise/`，并兼容旧位置 `runtime/`）：
     * 早期版本此处硬编码 `runtime/package_registry.json`，快照迁到 `.wise/` 之后，
     * 若启动时读不到 runtime 旧文件，`pre-package-uninstall` 就会误判为"无登记"而漏拍快照。
     */
    protected static function shouldClean(string $root, string $package): bool
    {
        if ((new PackageRegistry($root))->get($package) !== null) {
            return true;
        }

        // wiseadmin/* 库包即便没有静态资源与迁移，也可能注册菜单 / wise-config 配置行，
        // 判据与 PackageRegistry::worthRecording() 保持一致（快照丢失时仍能兜住）。
        if (str_starts_with($package, 'wiseadmin/')) {
            return true;
        }

        // 快照缺失（首次安装还没跑 dump-autoload、快照被手工删）时现场看包目录：
        // vendor 与 packages（path 仓库）都要看 —— pre-uninstall 阶段包文件仍在。
        $dir = self::locatePackageDir($root, $package);

        if ($dir === null) {
            return false;
        }

        $composerFile = $dir . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerFile)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($composerFile), true);
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['extra']['think']['publish']) || isset($data['extra']['think']['ident'])) {
            return true;
        }

        return is_dir($dir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');
    }

    /**
     * 转发到 CLI
     *
     * @return array{0:int, 1:string} [退出码, 输出]
     */
    protected static function runCleanup(string $root, string $package): array
    {
        $php  = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $think = $root . DIRECTORY_SEPARATOR . 'think';

        $command = escapeshellarg($php)
            . ' ' . escapeshellarg($think)
            . ' wise:package:cleanup ' . escapeshellarg($package)
            . ' --apply --silent';

        // 优先用 Composer 自带的 Symfony Process（能拿到输出与退出码）
        $processClass = 'Symfony\\Component\\Process\\Process';

        if (class_exists($processClass)) {
            try {
                /** @var object $process */
                $process = $processClass::fromShellCommandline($command, $root, null, null, 600);
                $process->run();

                return [
                    (int) $process->getExitCode(),
                    (string) $process->getOutput() . (string) $process->getErrorOutput(),
                ];
            } catch (\Throwable $e) {
                // 落到 exec 兜底
            }
        }

        $output   = [];
        $exitCode = 1;

        if (function_exists('exec')) {
            @exec($command . ' 2>&1', $output, $exitCode);
        }

        return [$exitCode, implode(PHP_EOL, $output)];
    }

    /**
     * 追加日志到 runtime/package_cleanup.log（与 CLI 共用）
     */
    protected static function log(string $action, string $message): void
    {
        try {
            $root = self::appRoot();
            $dir  = $root . DIRECTORY_SEPARATOR . 'runtime';

            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $file = $dir . DIRECTORY_SEPARATOR . 'package_cleanup.log';

            $line = sprintf('[%s] composer:%s %s', date('Y-m-d H:i:s'), $action, $message);

            if (strlen($line) > 2000) {
                $line = substr($line, 0, 2000) . '…';
            }

            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            // 日志失败不影响 Composer 主流程
        }
    }
}
