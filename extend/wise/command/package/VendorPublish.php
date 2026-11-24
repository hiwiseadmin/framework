<?php
declare(strict_types=1);

namespace wise\command\package;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:vendor:publish — 发布各包内 public/ 下的纯静态资源
 *
 * 职责边界（非常重要）：
 *   本命令只负责"必须由 Web 服务器按 URL 直读"的静态资源（js / css / html / 图片等），
 *   即包内 public/ 目录下的文件复制到应用 public/ 下。
 *
 *   以下三类资源**不**由本命令处理：
 *     - 配置：由各包 ServiceProvider 的 mergeConfig() 自动合并，无需复制
 *     - 迁移：由 RegistersMigrations 把包内 database/migrations 按引用注册进
 *             config('migration.paths')，由 WiseMigrate 直接扫描，无需复制
 *     - 视图：由 ViewScope（app\common\view\ViewScope）按 scope 解析，
 *             包内视图随包发布、独立升级，无需复制
 *
 * 声明方式（方案 B：每个包在自己的 composer.json 中自描述）：
 *
 *   {
 *       "extra": {
 *           "think": {
 *               "publish": {
 *                   "public": "public/statics/plugins/wise-agent"
 *               }
 *           }
 *       }
 *   }
 *
 *   键（source）为相对包根目录的路径，值（target）为相对应用根目录的路径。
 *   也支持列表写法：["public/css", "public/js"]（源与目标同相对路径）。
 *
 * 数据来源：
 *   1. 以 vendor/composer/installed.json 作为"已安装包集合"的事实来源；
 *   2. 发布映射优先读**包自身 composer.json** 的 extra.think.publish；
 *   3. 包自身未声明时才读 installed.json 的快照 —— path 仓库（本仓库）下
 *      installed.json 仅在 composer install/update 时写入，开发者新加的映射
 *      在快照里是滞后的，若只认快照就会"声明了却不发布"。
 *
 * 幂等与安全：
 *   - 默认**增量发布**：目标不存在、或源比目标新、或大小不同 → 复制；
 *     仅当目标已存在且与源一致时才跳过。这一条是必要的 —— 本命令挂在
 *     composer.json 的 post-autoload-dump 上，若「存在即跳过」，包升级后
 *     改动过的 js/css 将永远停在宿主旧副本上；
 *   - --force 无条件覆盖；
 *   - --dry-run 只预览；
 *   - 拒绝绝对路径与 ../ 路径穿越；
 *   - vendor/composer/installed.json 不存在时静默跳过（零副作用）；
 *   - 包内有 public/ 却未声明 extra.think.publish 时**明确指出**（不静默）。
 *
 * 用法：
 *   php think wise:vendor:publish
 *   php think wise:vendor:publish --force
 *   php think wise:vendor:publish --package=wiseadmin/wise-agent
 *   php think wise:vendor:publish --dry-run
 */
class VendorPublish extends Command
{
    /**
     * 发布统计
     *
     * @var array{copied:int, skipped:int, missing:int}
     */
    protected array $stats = ['copied' => 0, 'skipped' => 0, 'missing' => 0];

    /**
     * 含 public/ 但未声明 extra.think.publish 的包名（仅提示，不发布）
     *
     * @var array<int, string>
     */
    protected array $undeclared = [];

    protected function configure(): void
    {
        $this->setName('wise:vendor:publish')
            ->setDescription('发布各包 public/ 下的静态资源到应用 public/ 目录（读取 extra.think.publish）')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的文件（默认跳过）')
            ->addOption('package', 'p', Option::VALUE_REQUIRED, '仅发布指定包（composer 包名，如 wiseadmin/wise-agent）')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅预览将要执行的操作，不写入任何文件')
            ->setHelp(<<<EOT
读取 vendor/composer/installed.json，遍历所有声明了 extra.think.publish 的包，
把包内 public/ 静态资源复制到应用 public/ 目录。

<info>php think wise:vendor:publish</info>
<info>php think wise:vendor:publish --force</info>
<info>php think wise:vendor:publish --package=wiseadmin/wise-agent</info>
<info>php think wise:vendor:publish --dry-run</info>

EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $force     = (bool) $input->getOption('force');
        $onlyName  = $input->getOption('package');
        $dryRun    = (bool) $input->getOption('dry-run');

        $rootPath      = $this->app->getRootPath();
        $installedFile = $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (!is_file($installedFile)) {
            $output->writeln('<comment>[wise:vendor:publish] 未找到 vendor/composer/installed.json，跳过静态资源发布。</comment>');
            return 0;
        }

        $packages = $this->loadPackages($installedFile);
        if ($packages === null) {
            $output->writeln('<error>[wise:vendor:publish] vendor/composer/installed.json 解析失败。</error>');
            return 1;
        }

        $output->writeln('<info>wise:vendor:publish</info>' . ($dryRun ? ' <comment>(dry-run)</comment>' : ''));

        $published = 0;
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($onlyName !== null && $name !== $onlyName) {
                continue;
            }

            $packageRoot = $this->resolvePackageRoot($package, $installedFile, $rootPath);
            if ($packageRoot === null) {
                continue;
            }

            $publish = $this->resolvePublishMap($package, $packageRoot);
            if (empty($publish)) {
                $this->detectUndeclared($name, $packageRoot);
                continue;
            }

            $this->publishPackage($output, $name, $packageRoot, $publish, $rootPath, $force, $dryRun);
            $published++;
        }

        if ($published === 0) {
            $output->writeln('<comment>没有发现声明 extra.think.publish 的包。</comment>');
        }

        $this->reportUndeclared($output);

        if (!$dryRun) {
            $this->registerPackageSnapshot($output);
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>完成：</info>复制 %d 个文件，跳过 %d 个，缺失 %d 个。',
            $this->stats['copied'],
            $this->stats['skipped'],
            $this->stats['missing']
        ));

        return 0;
    }

    /**
     * 发布登记快照（.wise/package_registry.json）
     *
     * 为**当前已安装的全部包**登记：标识（ident）、静态资源目标、迁移版本号与表名。
     * 这些信息在 `composer remove` 之后无从反查（包目录、installed.json、composer.lock
     * 都不再含该包），因此必须在包还在的时候落盘，供 `wise:package:cleanup` 使用。
     *
     * 幂等：内容无变化时只比对不写盘；已登记但不再安装的条目**不删除**
     * （那正是卸载清理的依据）。
     *
     * @see \app\common\service\PackageRegistry
     * @see \app\common\service\PackageUninstaller
     */
    protected function registerPackageSnapshot(Output $output): void
    {
        try {
            $stat = $this->app->make(\app\common\service\PackageRegistry::class)->recordAll();

            if ((int) $stat['registered'] > 0 || !empty($stat['migrated'])) {
                $output->writeln('');
                $output->writeln(sprintf(
                    '<info>发布登记：</info>%s新增/更新 %d 个包（%d 个无变化，%d 个跳过）。',
                    !empty($stat['migrated']) ? '快照已迁移到 .wise/ ；' : '',
                    $stat['registered'],
                    $stat['unchanged'],
                    $stat['skipped']
                ));
            }
        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<comment>[wise:vendor:publish] 发布登记失败：' . $e->getMessage() . '</comment>');
        }
    }

    /**
     * 读取 installed.json 的包列表，兼容 Composer 2.1+（packages 键）与 2.0/1.x（顶层数组）。
     *
     * @return array|null
     */
    protected function loadPackages(string $installedFile): ?array
    {
        $raw = file_get_contents($installedFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['packages']) && is_array($data['packages'])) {
            return $data['packages'];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * 解析包根目录（绝对路径）。
     *
     * installed.json 中的 install-path 相对于 vendor/composer/ 目录（如 ../wiseadmin/wise-agent），
     * 这里依次尝试 vendor/composer、vendor、项目根 三个基准，兼容不同 Composer 版本的写法。
     */
    protected function resolvePackageRoot(array $package, string $installedFile, string $rootPath): ?string
    {
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

        $candidates = [
            $composerDir . DIRECTORY_SEPARATOR . $installPath,
            $vendorDir . DIRECTORY_SEPARATOR . $installPath,
            rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . $installPath,
        ];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * 取包的发布映射
     *
     * 优先级：**包自身 composer.json** → installed.json 的 extra.think.publish。
     *
     * 为什么以包自身为准：packages 是 path 仓库（`repositories: {type: path}`）时，
     * installed.json 只在 composer install/update 时写入快照 —— 开发者新加一条 publish
     * 映射后（本仓库常态）快照是**滞后**的，若不回退就会"声明了却不发布"且没有任何提示。
     * vendor 正式安装场景下两者内容一致，故以包自身为准不会造成行为差异；
     * 同时这样也支持"删除映射"立即生效（合并写法会残留旧键）。
     *
     * @return array<string, string> source => target
     */
    protected function resolvePublishMap(array $package, string $packageRoot): array
    {
        $composerFile = $packageRoot . DIRECTORY_SEPARATOR . 'composer.json';

        if (is_file($composerFile)) {
            $data = json_decode((string) file_get_contents($composerFile), true);

            if (is_array($data)) {
                $own = $this->normalizePublishMap($data['extra']['think']['publish'] ?? []);

                if ($own !== []) {
                    return $own;
                }
            }
        }

        return $this->normalizePublishMap($package['extra']['think']['publish'] ?? []);
    }

    /**
     * 归一化发布映射，支持 map（source => target）与 list（source 即 target）两种写法。
     *
     * @param mixed $map
     * @return array<string, string>
     */
    protected function normalizePublishMap($map): array
    {
        if (!is_array($map) || empty($map)) {
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
     * 登记「包内有 public/ 但未声明 extra.think.publish」的包。
     *
     * 这类包**不会**被发布（协议要求显式声明），但必须给开发者明确反馈 ——
     * 否则新增了 public/js 却看不到任何输出，会被当成命令失效。
     */
    protected function detectUndeclared(string $name, string $packageRoot): void
    {
        if (is_dir($packageRoot . DIRECTORY_SEPARATOR . 'public')) {
            $this->undeclared[] = $name;
        }
    }

    /**
     * 输出未声明清单（无则静默）。
     */
    protected function reportUndeclared(Output $output): void
    {
        if (empty($this->undeclared)) {
            return;
        }

        $output->writeln('');
        $output->writeln('<comment>以下包含 public/ 但未声明 extra.think.publish，已跳过：</comment>');

        foreach ($this->undeclared as $name) {
            $output->writeln("  - {$name}");
        }

        $output->writeln('<comment>  如需发布，请在其 composer.json 中补：</comment>');
        $output->writeln('<comment>  "extra": {"think": {"publish": {"public": "public/statics/plugins/{包标识}"}}}</comment>');
        $output->writeln('<comment>  然后执行 composer dump-autoload（或 php think wise:vendor:publish）。</comment>');
    }

    /**
     * 发布单个包的静态资源。
     *
     * @param array<string, string> $map source => target
     */
    protected function publishPackage(
        Output $output,
        string $name,
        string $packageRoot,
        array $map,
        string $rootPath,
        bool $force,
        bool $dryRun
    ): void {
        $output->writeln('');
        $output->writeln("<info>● {$name}</info>");

        foreach ($map as $sourceRel => $targetRel) {
            if (!$this->isSafeRelative($sourceRel) || !$this->isSafeRelative($targetRel)) {
                $this->stats['skipped']++;
                $output->writeln("  <error>[skip] 非法路径（绝对路径或包含 ..）：{$sourceRel} → {$targetRel}</error>");
                continue;
            }

            $source = $this->joinPath($packageRoot, $sourceRel);
            $target = $this->joinPath($rootPath, $targetRel);

            if (!file_exists($source)) {
                $this->stats['missing']++;
                $output->writeln("  <comment>[missing]</comment> {$sourceRel}");
                continue;
            }

            try {
                $result = $this->copyPath($source, $target, $force, $dryRun);
            } catch (\Throwable $e) {
                $this->stats['skipped']++;
                $output->writeln("  <error>[fail]</error> {$sourceRel} → {$targetRel}：{$e->getMessage()}");
                continue;
            }

            $this->stats['copied']  += $result['copied'];
            $this->stats['skipped'] += $result['skipped'];

            $output->writeln(sprintf(
                '  %s %s → %s <comment>(copy %d / skip %d)</comment>',
                $dryRun ? '[dry-run]' : '[ok]',
                $sourceRel,
                $targetRel,
                $result['copied'],
                $result['skipped']
            ));
        }
    }

    /**
     * 复制文件或目录（保持目录结构）。
     *
     * @return array{copied:int, skipped:int}
     */
    protected function copyPath(string $source, string $target, bool $force, bool $dryRun): array
    {
        $stats = ['copied' => 0, 'skipped' => 0];

        if (is_file($source)) {
            if (file_exists($target) && !$force && !$this->isStale($source, $target)) {
                $stats['skipped']++;
                return $stats;
            }
            if (!$dryRun) {
                $this->ensureDir(dirname($target));
                if (!copy($source, $target)) {
                    throw new \RuntimeException("复制文件失败：{$source}");
                }
            }
            $stats['copied']++;
            return $stats;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $prefix = rtrim($source, '/\\') . DIRECTORY_SEPARATOR;

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($prefix));
            $dest     = $target . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (!$dryRun) {
                    $this->ensureDir($dest);
                }
                continue;
            }

            if (file_exists($dest) && !$force && !$this->isStale($item->getPathname(), $dest)) {
                $stats['skipped']++;
                continue;
            }

            if (!$dryRun) {
                $this->ensureDir(dirname($dest));
                if (!copy($item->getPathname(), $dest)) {
                    throw new \RuntimeException("复制文件失败：{$item->getPathname()}");
                }
            }
            $stats['copied']++;
        }

        return $stats;
    }

    /**
     * 判断目标是否为「过期副本」——需要重新发布。
     *
     * 判定依据：源比目标新，或两者字节数不同。用字节数兜底是可选的保险 ——
     * 同一次 git checkout 出来的文件 mtime 可能一致，仅靠时间戳会漏掉真正的内容变化。
     *
     * @param string $source 包内源文件
     * @param string $target 宿主目标文件
     * @return bool true = 需要复制
     */
    protected function isStale(string $source, string $target): bool
    {
        $sourceTime = @filemtime($source);
        $targetTime = @filemtime($target);

        if ($sourceTime === false || $targetTime === false) {
            return true;
        }

        if ($sourceTime > $targetTime) {
            return true;
        }

        return @filesize($source) !== @filesize($target);
    }

    /**
     * 拼接路径（统一目录分隔符）。
     */
    protected function joinPath(string $base, string $relative): string
    {
        return rtrim($base, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, '/\\'));
    }

    /**
     * 目录不存在则递归创建。
     */
    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * 判断是否绝对路径（*nix / Windows）。
     */
    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * 判断是否为安全的相对路径（不含 .. 段，也不是绝对路径）。
     */
    protected function isSafeRelative(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '' || $this->isAbsolutePath($normalized)) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
