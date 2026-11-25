<?php

declare(strict_types=1);

namespace wise\plugin\adapter;

use wise\plugin\scanner\PluginMetadata;
use wise\support\tools\DirFile;
use wise\support\context\ContextualLogger;

/**
 * 静态资源管理器 — 管理插件静态资源的复制和清理。
 *
 * 两类发布来源：
 *  1. **约定**：`plugins/{ident}/statics/` → `public/statics/plugins/{ident}/`
 *  2. **声明**：`manifest.json` 的 `publish`（源相对插件根 → 目标相对 public），
 *     用于 `statics/` 之外的资源（如会员主题 `theme/{theme}/assets`
 *     → `public/statics/member/themes/{theme}`）。
 *
 * 目标一律落在 `public/` 下（`..` 越界的目标会被忽略）。
 */
class AssetManager
{
    /**
     * 插件目录根路径。
     *
     * @var string
     */
    protected string $pluginPath;

    /**
     * 公共静态资源目录根路径。
     *
     * @var string
     */
    protected string $publicPath;

    public function __construct()
    {
        $this->pluginPath = plugin_path();
        $this->publicPath = public_path() . 'statics' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR;
    }

    /**
     * 复制插件静态资源到公共目录。
     *
     * @param string $pluginIdent 插件标识
     * @return bool 全部成功（含"无资源可复制"）返回 true
     */
    public function copyAssets(string $pluginIdent): bool
    {
        $ok = true;

        $sourceDir = $this->pluginPath . $pluginIdent . DIRECTORY_SEPARATOR . 'statics' . DIRECTORY_SEPARATOR;

        if (is_dir($sourceDir)) {
            $targetDir = $this->publicPath . $pluginIdent . DIRECTORY_SEPARATOR;

            try {
                DirFile::copyDirs($sourceDir, $targetDir);
                ContextualLogger::info("插件静态资源复制成功：{$pluginIdent}");
            } catch (\Throwable $e) {
                ContextualLogger::error("插件静态资源复制失败：{$pluginIdent}，" . $e->getMessage());
                $ok = false;
            }
        }

        foreach ($this->publishMap($pluginIdent) as $source => $target) {
            if (!is_dir($source)) {
                continue;
            }

            try {
                DirFile::copyDirs($source, $target . DIRECTORY_SEPARATOR);
                ContextualLogger::info("插件声明资源复制成功：{$pluginIdent} → " . $target);
            } catch (\Throwable $e) {
                ContextualLogger::error("插件声明资源复制失败：{$pluginIdent}，" . $e->getMessage());
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * 增量同步插件静态资源（开发期 `plugin:dev --assets` 专用）。
     *
     * 与 {@see self::copyAssets()} 的语义差别（**不要互相替代**）：
     *  - `copyAssets()`：安装 / 启用 / 升级时调用，整目录覆盖，保证产物与源码一致；
     *  - `syncAssets()`：开发期显式触发，只复制**有差异**的文件（size 或 mtime 不同），
     *    并在复制后用 `touch()` 把目标 mtime 对齐源文件 —— 这样下次比较仍成立，
     *    命令天然幂等，也不会因为整目录重写而丢失 `public/` 下运维手改的其它文件。
     *
     * 资源来源与 `copyAssets()` 完全同源，保证两条链路不会各复制各的：
     *  1. 约定：`plugins/{ident}/statics/` → `public/statics/plugins/{ident}/`
     *  2. 声明：manifest 的 `publish` 映射（越界目标已被 {@see self::publishMap()} 过滤）
     *
     * @param string $pluginIdent 插件标识
     * @param bool   $dryRun      true = 只统计差异，不写盘（`--status` / `--dry-run`）
     * @return array{
     *     entries: array<int, array{source:string, target:string, state:string}>,
     *     copied: int, same: int, errors: int, missing_source: int
     * } `source` 相对插件目录、`target` 相对 `public/`；`state` = copy | same | error
     */
    public function syncAssets(string $pluginIdent, bool $dryRun = false): array
    {
        $result = [
            'entries'        => [],
            'copied'         => 0,
            'same'           => 0,
            'errors'         => 0,
            'missing_source' => 0,
        ];

        $pairs = [];

        $staticsDir = $this->pluginPath . $pluginIdent . DIRECTORY_SEPARATOR . 'statics';
        if (is_dir($staticsDir)) {
            $pairs[$staticsDir] = $this->publicPath . $pluginIdent;
        }

        foreach ($this->publishMap($pluginIdent) as $source => $target) {
            $pairs[$source] = $target;
        }

        foreach ($pairs as $sourceDir => $targetDir) {
            if (!is_dir($sourceDir)) {
                $result['missing_source']++;
                continue;
            }

            $this->syncDirectory($pluginIdent, $sourceDir, $targetDir, $dryRun, $result);
        }

        return $result;
    }

    /**
     * 递归比对并复制单个「源目录 → 目标目录」。
     *
     * 只按 `size + mtime` 判定差异：不读文件内容、不算哈希 —— 开发期静态资源改动必然
     * 改变 size 或 mtime，而大目录全量哈希会让一条诊断命令变慢。
     *
     * @param string                                                                                    $pluginIdent
     * @param string                                                                                    $sourceDir
     * @param string                                                                                    $targetDir
     * @param bool                                                                                      $dryRun
     * @param array{entries: array<int, array{source:string,target:string,state:string}>, copied:int, same:int, errors:int, missing_source:int} $result
     */
    protected function syncDirectory(
        string $pluginIdent,
        string $sourceDir,
        string $targetDir,
        bool $dryRun,
        array &$result
    ): void {
        $sourceBase = rtrim(str_replace('\\', '/', $sourceDir), '/');
        $targetBase = rtrim(str_replace('\\', '/', $targetDir), '/');

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (\Throwable $e) {
            $result['errors']++;
            ContextualLogger::warning('插件静态资源比对失败：' . $sourceBase . '，' . $e->getMessage());

            return;
        }

        $pluginRoot = rtrim(str_replace('\\', '/', $this->pluginPath . $pluginIdent), '/');
        $publicRoot = rtrim(str_replace('\\', '/', public_path()), '/');

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $sourcePath = str_replace('\\', '/', $file->getPathname());
            $relative   = ltrim(substr($sourcePath, strlen($sourceBase)), '/');
            $targetPath = $targetBase . '/' . $relative;

            $entry = [
                'source' => ltrim(substr($sourcePath, strlen($pluginRoot)), '/'),
                'target' => ltrim(substr($targetPath, strlen($publicRoot)), '/'),
                'state'  => 'copy',
            ];

            $sourceSize  = (int) @filesize($file->getPathname());
            $sourceMtime = (int) @filemtime($file->getPathname());

            if (is_file($targetPath) && (int) @filesize($targetPath) === $sourceSize) {
                $targetMtime = (int) @filemtime($targetPath);

                if ($targetMtime === $sourceMtime || $this->sameContent($file->getPathname(), $targetPath)) {
                    if (!$dryRun && $targetMtime !== $sourceMtime) {
                        @touch($targetPath, $sourceMtime);
                    }

                    $entry['state']    = 'same';
                    $result['same']++;
                    $result['entries'][] = $entry;

                    continue;
                }
            }

            if ($dryRun) {
                $result['copied']++;
                $result['entries'][] = $entry;

                continue;
            }

            $targetDirPath = dirname($targetPath);

            if (!is_dir($targetDirPath) && !@mkdir($targetDirPath, 0755, true) && !is_dir($targetDirPath)) {
                $entry['state'] = 'error';
                $result['errors']++;
                $result['entries'][] = $entry;
                ContextualLogger::error('插件静态资源目录创建失败：' . $targetDirPath);

                continue;
            }

            if (!@copy($file->getPathname(), $targetPath)) {
                $entry['state'] = 'error';
                $result['errors']++;
                $result['entries'][] = $entry;
                ContextualLogger::error('插件静态资源复制失败：' . $targetPath);

                continue;
            }

            @touch($targetPath, $sourceMtime);

            $result['copied']++;
            $result['entries'][] = $entry;
        }
    }

    /**
     * 两个文件内容是否一致（仅在 size 相等且 mtime 不同时才会被调用）。
     *
     * 用 md5 而不是逐字节比较：静态资源通常不大，md5 一次读盘即可，
     * 且不会像 `file_get_contents` 那样把大文件整个读进内存。
     */
    protected function sameContent(string $source, string $target): bool
    {
        $a = @md5_file($source);
        $b = @md5_file($target);

        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * 删除插件静态资源。
     *
     * @param string $pluginIdent 插件标识
     * @return bool
     */
    public function removeAssets(string $pluginIdent): bool
    {
        $targets = [$this->publicPath . $pluginIdent . DIRECTORY_SEPARATOR];

        foreach ($this->publishMap($pluginIdent) as $target) {
            $targets[] = $target . DIRECTORY_SEPARATOR;
        }

        $ok = true;

        foreach ($targets as $targetDir) {
            if (!is_dir($targetDir)) {
                continue;
            }

            try {
                DirFile::rmDirs($targetDir);
                ContextualLogger::info('插件静态资源删除成功：' . $targetDir);
            } catch (\Throwable $e) {
                ContextualLogger::error('插件静态资源删除失败：' . $targetDir . '，' . $e->getMessage());
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * 读取插件清单的 `publish` 映射（源目录 → 目标目录，均为绝对路径）。
     *
     * 清单解析统一走 {@see PluginMetadata::fromArray()}（唯一入口），
     * 本方法只负责把声明的相对路径解析为绝对路径，并过滤越界目标。
     *
     * ```json
     * "publish": {
     *     "theme/default/assets": "public/statics/member/themes/default"
     * }
     * ```
     *
     * @return array<string,string>
     */
    protected function publishMap(string $pluginIdent): array
    {
        $root = $this->pluginPath . $pluginIdent . DIRECTORY_SEPARATOR;
        $file = $root . 'manifest.json';

        if (!is_file($file)) {
            return [];
        }

        $metadata = PluginMetadata::fromJson((string) file_get_contents($file));
        $publish  = $metadata->getPublish();

        if ($publish === []) {
            return [];
        }

        $map = [];

        foreach ($publish as $source => $target) {
            $source = trim(str_replace('\\', '/', (string) $source), '/');
            $target = trim(str_replace('\\', '/', (string) $target), '/');

            if ($source === '' || $target === '' || str_contains($target, '..')) {
                continue;
            }

            if (str_starts_with($target, 'public/')) {
                $target = substr($target, strlen('public/'));
            }

            $map[$root . str_replace('/', DIRECTORY_SEPARATOR, $source)]
                = public_path() . str_replace('/', DIRECTORY_SEPARATOR, $target);
        }

        return $map;
    }
}
