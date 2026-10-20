<?php

declare(strict_types=1);

namespace wise\plugin\scanner;

use think\App;
use wise\plugin\PluginManager;
use wise\plugin\scanner\PluginMetadata;
use wise\plugin\exception\PluginException;

/**
 * PluginScanner 通过扫描插件目录来发现插件。
 *
 * 它读取每个子目录中的 manifest.json 清单文件并缓存结果，
 * 以避免每次请求都重复访问文件系统。
 */
class PluginScanner
{
    /**
     * 清单文件名。
     *
     * @var string
     */
    protected const MANIFEST_FILE = 'manifest.json';

    /**
     * 缓存结构版本
     *
     * 结构变更（如新增指纹字段）时递增即可让旧结构缓存自然失效 ——
     * `getCache()` 读到版本不符会直接重扫，避免反序列化出半旧数据。
     */
    protected const CACHE_VERSION = 3;

    /**
     * ThinkPHP 应用实例。
     *
     * @var App
     */
    protected App $app;

    /**
     * 插件目录路径。
     *
     * @var string
     */
    protected string $pluginDir;

    /**
     * 是否启用缓存。
     *
     * @var bool
     */
    protected bool $cacheEnabled;

    /**
     * 缓存 TTL（秒）。
     *
     * @var int
     */
    protected int $cacheTtl;

    /**
     * 用于存储扫描结果的缓存键。
     *
     * @var string
     */
    protected string $cacheKey = 'wise_plugin_scanner';

    /**
     * 创建一个新的 PluginScanner 实例。
     *
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $config = $this->app->config->get('plugin', []);
        $this->pluginDir = $config['plugin_dir'] ?? $this->app->getRootPath() . 'plugins';
        $this->cacheEnabled = $config['cache_enabled'] ?? true;
        $this->cacheTtl = $config['cache_ttl'] ?? 3600;
    }

    /**
     * 扫描插件目录并返回所有发现的插件元数据。
     *
     * 如果启用了缓存，结果会被缓存。只有包含有效插件名称
     * （字母数字、下划线和连字符）的目录才会被扫描，以防止
     * 路径遍历攻击。
     *
     * @return PluginMetadata[]
     */
    public function scan(): array
    {
        // 如果有缓存，返回缓存结果
        if ($this->cacheEnabled) {
            $cached = $this->getCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $plugins = [];

        // 检查插件目录是否存在
        if (!is_dir($this->pluginDir)) {
            return $plugins;
        }

        // 扫描每个子目录
        if (!is_readable($this->pluginDir)) {
            throw new PluginException(
                '插件目录不可读: ' . $this->pluginDir
            );
        }

        $directories = scandir($this->pluginDir);
        if ($directories === false) {
            return $plugins;
        }

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            // 跳过名称不符合插件命名规则的目录。
            // 这可以防止路径遍历攻击并过滤掉非插件内容。
            if (!$this->isValidPluginName($dir)) {
                continue;
            }

            $metadata = $this->validateAndLoadPlugin($dir);
            if ($metadata !== null) {
                $plugins[$dir] = $metadata;
            }
        }

        // 缓存结果
        if ($this->cacheEnabled) {
            $this->setCache($plugins);
        }

        return $plugins;
    }

    /**
     * 获取指定插件的元数据。
     *
     * @param string $name 插件名称（目录名）
     * @return PluginMetadata
     * @throws PluginException 如果清单文件无法读取或解析，或插件名称无效
     */
    public function getPluginMetadata(string $name): PluginMetadata
    {
        if (!$this->isValidPluginName($name)) {
            throw new PluginException(sprintf(
                'Invalid plugin name "%s". Names must match pattern: [a-zA-Z0-9][a-zA-Z0-9_-]*',
                $name
            ));
        }

        $pluginPath = $this->pluginDir . DIRECTORY_SEPARATOR . $name;
        $manifestPath = $this->findManifestFile($pluginPath);

        if ($manifestPath === null) {
            throw new PluginException(sprintf(
                'Plugin manifest not found in: %s (looked for %s)',
                $pluginPath,
                self::MANIFEST_FILE
            ));
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new PluginException(sprintf(
                'Failed to read plugin manifest: %s',
                $manifestPath
            ));
        }

        return PluginMetadata::fromJson($json);
    }

    /**
     * 清除扫描缓存并在下次访问时强制重新扫描。
     *
     * @return void
     */
    public function refreshCache(): void
    {
        try {
            $cache = $this->app->cache;
            $cache->delete($this->cacheKey);
        } catch (\Throwable $e) {
            // 忽略缓存清除错误
        }
    }

    /**
     * 检查目录是否包含有效插件（是否有 manifest.json）。
     *
     * @param string $dir 要检查的目录路径
     * @return bool
     */
    public function isValidPlugin(string $dir): bool
    {
        $manifestPath = $this->findManifestFile($dir);
        if ($manifestPath === null) {
            return false;
        }

        // 验证清单文件是否为有效 JSON
        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // 有效插件必须至少有一个名称或标识
        return (isset($data['name']) && $data['name'] !== '')
            || (isset($data['ident']) && $data['ident'] !== '');
    }

    /**
     * 验证并加载插件元数据，仅读取一次清单文件。
     *
     * 合并 isValidPlugin 的验证逻辑与 getPluginMetadata 的解析逻辑，
     * 避免在 scan() 中对清单文件进行重复读取和 JSON 解码。
     *
     * @param string $dir 插件目录名（不含完整路径）
     * @return PluginMetadata|null 成功返回元数据，失败返回 null
     */
    public function validateAndLoadPlugin(string $dir): ?PluginMetadata
    {
        $pluginPath = $this->pluginDir . DIRECTORY_SEPARATOR . $dir;
        $manifestPath = $this->findManifestFile($pluginPath);

        if ($manifestPath === null) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // 有效插件必须至少有一个名称或标识
        $hasName = isset($data['name']) && $data['name'] !== '';
        $hasIdent = isset($data['ident']) && $data['ident'] !== '';
        if (!$hasName && !$hasIdent) {
            return null;
        }

        return PluginMetadata::fromJson($content);
    }

    /**
     * 在指定目录中查找清单文件。
     *
     * @param string $pluginPath 插件目录路径
     * @return string|null 清单文件完整路径，或 null
     */
    protected function findManifestFile(string $pluginPath): ?string
    {
        $path = $pluginPath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        return file_exists($path) ? $path : null;
    }

    /**
     * 检查目录名是否为有效插件名称。
     *
     * 有效插件名称以字母或数字开头，且只包含
     * 字母、数字、下划线和连字符。这可以防止
     * 目录遍历攻击（例如 "../../etc/passwd"）。
     *
     * @param string $name 要检查的目录名
     * @return bool
     */
    protected function isValidPluginName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name);
    }

    /**
     * 获取缓存的插件扫描结果。
     *
     * @return PluginMetadata[]|null
     */
    protected function getCache(): ?array
    {
        try {
            $cached = $this->app->cache->get($this->cacheKey);

            // 结构版本不符（含历史版本写入的裸数组缓存）→ 重扫
            if (!is_array($cached) || (int) ($cached['version'] ?? 0) !== self::CACHE_VERSION) {
                return null;
            }

            if (!isset($cached['fingerprint'], $cached['plugins']) || !is_array($cached['plugins'])) {
                return null;
            }

            // 声明指纹校验：任一 manifest.json 被增删改（mtime/size 变化）→ 立即失效重扫。
            // 没有这一步，"改了 manifest 却一直读旧缓存"就无法自愈 —— 只能靠人记得
            // 跑 refreshCache()，而 php think clear 并不保证使缓存失效（实测）。
            if ($cached['fingerprint'] !== $this->manifestFingerprint()) {
                return null;
            }

            $plugins = [];
            foreach ($cached['plugins'] as $name => $data) {
                if (is_array($data)) {
                    $plugins[$name] = PluginMetadata::fromArray($data);
                }
            }

            return $plugins;
        } catch (\Throwable $e) {
            // 缓存异常时退回扫描
        }
        return null;
    }

    /**
     * 清单指纹：各插件 manifest.json 的 `mtime:size`
     *
     * 只做 stat、不读文件内容，成本可忽略；用于让缓存**自证是否过期**。
     * 覆盖"手改 manifest / git 切分支 / 增删插件目录"三类场景。
     *
     * @return array<string, string> 插件目录名 => 指纹
     */
    protected function manifestFingerprint(): array
    {
        $fingerprint = [];

        foreach (glob($this->pluginDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);

            if (!$this->isValidPluginName($name)) {
                continue;
            }

            $manifest = $this->findManifestFile($dir);

            if ($manifest === null) {
                continue;
            }

            $fingerprint[$name] = ($manifest === '' ? '' : basename($manifest) . ':')
                . (@filemtime($manifest) ?: 0) . ':' . (@filesize($manifest) ?: 0);
        }

        // glob 顺序不保证稳定 → 归一后再比较
        ksort($fingerprint);

        return $fingerprint;
    }

    /**
     * 将插件扫描结果存储到缓存中。
     *
     * @param PluginMetadata[] $plugins 要缓存的扫描结果
     * @return void
     */
    protected function setCache(array $plugins): void
    {
        try {
            // 将 PluginMetadata 对象转为数组后再缓存，避免跨驱动序列化问题
            $cacheData = [];
            foreach ($plugins as $name => $metadata) {
                $cacheData[$name] = $metadata->toArray();
            }

            // 与 getCache() 约定同一结构：版本 + 声明指纹 + 数据
            $this->app->cache->set($this->cacheKey, [
                'version'     => self::CACHE_VERSION,
                'fingerprint' => $this->manifestFingerprint(),
                'plugins'     => $cacheData,
            ], $this->cacheTtl);
        } catch (\Throwable $e) {
            // 忽略缓存写入错误
        }
    }

    /**
     * 获取插件目录路径。
     *
     * @return string
     */
    public function getPluginDir(): string
    {
        return $this->pluginDir;
    }
}
