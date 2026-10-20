<?php

declare(strict_types=1);

namespace wise\plugin\composer_bridge;

/**
 * 插件桥接器 — 将 Composer 安装的 wise-plugin 类型包同步到应用插件目录
 *
 * 工作原理：
 * 1. 读取 vendor/composer/installed.json 获取所有已安装包信息
 * 2. 筛选出 type 为 "wise-plugin" 的包
 * 3. 将包从 vendor 路径 软链接（优先）或复制 到应用插件目录
 *
 * 调用时机：composer install/update 后通过 post-autoload-dump 脚本触发
 * 命令：php think wise:plugin:bridge
 *
 * 注意：正式生产环境没有 vendor/ 和 packages/ 目录，此命令直接跳过，零副作用
 */
class WisePluginInstaller
{
    /**
     * 项目根目录路径
     */
    protected string $rootPath;

    /**
     * 构造函数
     *
     * @param string|null $rootPath 项目根目录，默认自动检测
     */
    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? $this->detectRootPath();
    }

    /**
     * 执行桥接：扫描并同步所有 wise-plugin 包
     *
     * @return array{success: int, skipped: int, errors: array} 执行结果统计
     */
    public function bridge(): array
    {
        $installedPath = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if (!file_exists($installedPath)) {
            return ['success' => 0, 'skipped' => 0, 'errors' => ['vendor/composer/installed.json 不存在，跳过桥接']];
        }

        $rawJson = file_get_contents($installedPath);
        if ($rawJson === false) {
            return ['success' => 0, 'skipped' => 0, 'errors' => ['无法读取 vendor/composer/installed.json']];
        }
        $installed = json_decode($rawJson, true);
        if (!is_array($installed)) {
            return ['success' => 0, 'skipped' => 0, 'errors' => ['vendor/composer/installed.json 解析失败']];
        }

        // Composer 2.x: installed.json 直接是 packages 数组；Composer 1.x: 包在 packages 键下
        $packages = $installed;
        if (isset($installed['packages']) && is_array($installed['packages'])) {
            $packages = $installed['packages'];
        }

        $pluginDir = $this->getPluginDir();
        $result = ['success' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($packages as $package) {
            if (!isset($package['type']) || $package['type'] !== 'wise-plugin') {
                continue;
            }

            $packageName = $package['name'] ?? '';
            $installPath = $package['install-path'] ?? '';

            if (empty($installPath)) {
                $result['errors'][] = "包 {$packageName} 缺少 install-path";
                continue;
            }

            // 解析安装路径（可能是相对于 vendor-dir 的路径）
            $sourcePath = $this->resolveInstallPath($installPath);
            if ($sourcePath === null || !is_dir($sourcePath)) {
                $result['errors'][] = "包 {$packageName} 路径不存在: {$sourcePath}";
                continue;
            }

            $targetPath = $pluginDir . DIRECTORY_SEPARATOR . $this->getPluginName($packageName);

            try {
                // 原子化替换：先复制到临时目录，成功后再替换目标
                $tmpPath = $targetPath . '.tmp_' . uniqid('', true);
                try {
                    // 先尝试软链接到临时路径
                    if ($this->createSymlink($sourcePath, $tmpPath)) {
                        // 软链接成功，原子替换
                        $this->removeTarget($targetPath);
                        rename($tmpPath, $targetPath);
                        $result['success']++;
                    } else {
                        // 软链接失败，复制到临时目录再替换
                        $this->copyDirectory($sourcePath, $tmpPath);
                        $this->removeTarget($targetPath);
                        rename($tmpPath, $targetPath);
                        $result['success']++;
                    }
                } catch (\Throwable $e) {
                    // 清理临时目录
                    $this->removeTarget($tmpPath);
                    throw $e;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "同步 {$packageName} 失败: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * 获取插件目录路径
     *
     * 优先级：composer.json extra.wise.plugin_dir → config/plugin.json → config/plugin.php → 默认 plugins/
     *
     * @return string
     */
    protected function getPluginDir(): string
    {
        // 优先从 composer.json 的 extra 配置读取（无代码执行风险）
        $composerJsonPath = $this->rootPath . DIRECTORY_SEPARATOR . 'composer.json';
        if (file_exists($composerJsonPath)) {
            $composerData = json_decode((string) file_get_contents($composerJsonPath), true);
            if (is_array($composerData)
                && isset($composerData['extra']['wise']['plugin_dir'])
                && is_string($composerData['extra']['wise']['plugin_dir'])
            ) {
                return $composerData['extra']['wise']['plugin_dir'];
            }
        }

        // 回退到 config/plugin.json（JSON 安全读取，比 PHP include 安全）
        $jsonConfigPath = $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugin.json';
        if (file_exists($jsonConfigPath)) {
            $config = json_decode((string) file_get_contents($jsonConfigPath), true);
            if (is_array($config) && isset($config['plugin_dir'])) {
                return $config['plugin_dir'];
            }
        }

        // 最后尝试从 PHP 配置文件安全提取值（正则匹配，不执行 PHP 代码）
        $phpConfigPath = $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugin.php';
        if (file_exists($phpConfigPath)) {
            $pluginDir = $this->extractPluginDirFromPhpConfig($phpConfigPath);
            if ($pluginDir !== null) {
                return $pluginDir;
            }
        }

        // 默认插件目录
        return $this->rootPath . DIRECTORY_SEPARATOR . 'plugins';
    }

    /**
     * 从 PHP 配置文件中安全提取 plugin_dir 值（不执行 PHP 代码）
     *
     * @param string $configPath PHP 配置文件路径
     * @return string|null
     */
    protected function extractPluginDirFromPhpConfig(string $configPath): ?string
    {
        $content = file_get_contents($configPath);
        if ($content === false) {
            return null;
        }

        if (preg_match("/['\"]plugin_dir['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", (string) $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 从包名提取插件短名（移除 vendor 前缀）
     *
     * @param string $packageName 完整包名，如 wiseadmin/wise-auth
     * @return string 插件短名，如 wise-auth
     */
    protected function getPluginName(string $packageName): string
    {
        $parts = explode('/', $packageName);
        $name = $parts[1] ?? $parts[0];

        // 防止路径穿越：插件名只能包含字母数字、下划线和连字符
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name)) {
            throw new \RuntimeException(sprintf(
                'Invalid plugin package name "%s": unsafe characters detected.',
                $packageName
            ));
        }

        return $name;
    }

    /**
     * 解析 installed.json 中的 install-path 为绝对路径
     *
     * install-path 可能是相对于 vendor-dir 的相对路径，也可能是绝对路径
     *
     * @param string $installPath installed.json 中的安装路径
     * @return string|null
     */
    protected function resolveInstallPath(string $installPath): ?string
    {
        // 绝对路径直接返回
        if (str_starts_with($installPath, '/') || preg_match('/^[A-Z]:\\\\/', $installPath)) {
            return $installPath;
        }

        // 相对路径：基于 vendor 目录解析
        $vendorDir = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor';
        $fullPath = $vendorDir . DIRECTORY_SEPARATOR . $installPath;

        // 处理路径中的 ../ 和多余的 /
        $realPath = realpath($fullPath);
        return $realPath !== false ? $realPath : $fullPath;
    }

    /**
     * 移除目标路径（软链接或目录）
     *
     * @param string $target 目标路径
     */
    protected function removeTarget(string $target): void
    {
        if (is_link($target)) {
            unlink($target);
        } elseif (is_dir($target)) {
            $this->removeDirectory($target);
        }
    }

    /**
     * 创建软链接
     *
     * @param string $source 源目录
     * @param string $target 目标路径
     * @return bool 成功返回 true
     */
    protected function createSymlink(string $source, string $target): bool
    {
        try {
            $parentDir = dirname($target);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            return symlink($source, $target);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 递归复制目录
     *
     * @param string $source 源目录
     * @param string $target 目标目录
     */
    protected function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $sourcePrefix = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourcePrefix));
            $targetPath = $target . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                if (!copy($item->getPathname(), $targetPath)) {
                    throw new \RuntimeException(sprintf(
                        '复制文件失败: %s → %s',
                        $item->getPathname(),
                        $targetPath
                    ));
                }
            }
        }
    }

    /**
     * 递归删除目录
     *
     * @param string $dir 要删除的目录
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * 自动检测项目根目录
     *
     * @return string
     */
    protected function detectRootPath(): string
    {
        // 从当前文件路径向上逐级查找包含 composer.json 和 vendor/ 的目录
        $dir = __DIR__;
        // 使用 while 循环，仅靠文件系统根作为终止条件，无硬编码上限
        while (true) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . 'composer.json')
                && file_exists($dir . DIRECTORY_SEPARATOR . 'vendor')
            ) {
                return $dir;
            }
            $parentDir = dirname($dir);
            if ($parentDir === $dir) {
                break; // 已到达文件系统根
            }
            $dir = $parentDir;
        }

        return getcwd() ?: '';
    }
}
