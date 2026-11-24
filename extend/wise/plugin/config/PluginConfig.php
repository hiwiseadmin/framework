<?php

declare(strict_types=1);

namespace wise\plugin\config;

use think\App;
use think\facade\Cache;
use wise\plugin\PluginContainerKeys;
use wise\plugin\contract\PluginConfigDriverInterface;
use wise\plugin\exception\PluginConfigException;

/**
 * PluginConfig 使用可插拔驱动后端管理插件配置。
 *
 * 支持点号分隔的键（例如 "plugin_name.key"），这些键会被解析
 * 为插件名称和配置键。实际存储委托给可配置的驱动（数据库或文件）。
 */
class PluginConfig
{
    /**
     * 跨请求缓存键前缀。
     */
    protected const CACHE_PREFIX = 'plugin_config_values_';

    /**
     * ThinkPHP 应用实例。
     *
     * @var App
     */
    protected App $app;

    /**
     * 配置驱动。
     *
     * @var PluginConfigDriverInterface|null
     */
    protected ?PluginConfigDriverInterface $driver = null;

    /**
     * 请求级缓存：避免同一次请求内对同一插件重复读取驱动。
     *
     * 仅进程内有效，不跨请求。写操作与驱动切换会主动失效对应条目。
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $valueCache = [];

    /**
     * 本次进程已写入的跨请求缓存键（供 flushCache(null) 全量清理）。
     *
     * 仅记录本进程写过的键，无需也无法枚举全站缓存。
     *
     * @var array<string, bool>
     */
    protected array $knownCacheKeys = [];

    /**
     * 创建新的 PluginConfig 实例。
     *
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 使用点号分隔的键获取配置值。
     *
     * 键格式为 "plugin_name.config_key"。
     *
     * @param string $key     点号分隔的配置键
     * @param mixed  $default 键不存在时的默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$pluginName, $configKey] = $this->parseKey($key);
        return $this->getDriver()->read($pluginName, $configKey, $default);
    }

    /**
     * 使用点号分隔的键设置配置值。
     *
     * 键格式为 "plugin_name.config_key"。
     *
     * @param string $key   点号分隔的配置键
     * @param mixed  $value 要设置的值
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        [$pluginName, $configKey] = $this->parseKey($key);
        $this->getDriver()->write($pluginName, $configKey, $value);

        // 写后失效，保证后续读取拿到新值
        unset($this->valueCache[$pluginName]);
        Cache::delete($this->getCacheKey($pluginName));
    }

    /**
     * 使用点号分隔的键删除配置值。
     *
     * 键格式为 "plugin_name.config_key"。
     *
     * @param string $key 点号分隔的配置键
     * @return void
     */
    public function delete(string $key): void
    {
        [$pluginName, $configKey] = $this->parseKey($key);
        $this->getDriver()->delete($pluginName, $configKey);

        unset($this->valueCache[$pluginName]);
        Cache::delete($this->getCacheKey($pluginName));
    }

    /**
     * 获取插件的所有业务配置值。
     *
     * 仅返回已存储的业务配置项，不含 schema 默认值，
     * 也不含以 '__' 开头的框架内部状态键（由驱动层过滤）。
     * 需要「默认值 ⊕ 已存值」的合并结果请改用 {@see values()}。
     *
     * @param string $pluginName 插件名称
     * @return array<string, mixed>
     */
    public function all(string $pluginName): array
    {
        return $this->getDriver()->readAll($pluginName);
    }

    /**
     * 清空插件的所有配置值。
     *
     * @param string $pluginName 插件名称
     * @return void
     */
    public function clear(string $pluginName): void
    {
        $this->getDriver()->clear($pluginName);

        // clear() 会清掉状态键（__state.*），缓存必须一并失效
        unset($this->valueCache[$pluginName]);
        Cache::delete($this->getCacheKey($pluginName));
    }

    /**
     * 获取插件的生效配置值（schema 默认值 ⊕ 已存储值）。
     *
     * 合并优先级：已存储值 > schema 默认值。
     * 这样即使从未保存过配置，调用方也能拿到 manifest 声明的默认值；
     * 同时 schema 中已删除的历史键会被自然丢弃。
     *
     * @param string $pluginName 插件名称（目录名）
     * @param array  $schema     已归一化的 schema；为空时退化为 all()
     * @return array<string, mixed>
     */
    public function values(string $pluginName, array $schema = []): array
    {
        if (isset($this->valueCache[$pluginName])) {
            return $this->valueCache[$pluginName];
        }

        // 跨请求缓存：插件业务侧（BaseController::getPluginConfig 等）会高频调用，
        // 每次查库在高并发下开销显著。仅在提供 schema 时启用 ——
        // 无 schema 的 values() 等价于 all()，由调用方决定是否缓存。
        $cacheKey = $schema === [] ? '' : $this->getCacheKey($pluginName);

        if ($cacheKey !== '') {
            $cached = $this->readCache($cacheKey, $schema);
            if ($cached !== null) {
                return $this->valueCache[$pluginName] = $cached;
            }
        }

        $stored = $this->all($pluginName);

        if ($schema === []) {
            return $this->valueCache[$pluginName] = $stored;
        }

        // 以 schema 默认值打底，已存值覆盖
        $merged = PluginConfigSchema::defaults($schema);
        foreach (array_keys($merged) as $key) {
            if (array_key_exists($key, $stored)) {
                $merged[$key] = $stored[$key];
            }
        }

        if ($cacheKey !== '') {
            $this->writeCache($cacheKey, $schema, $merged);
        }

        return $this->valueCache[$pluginName] = $merged;
    }

    /**
     * 批量写入配置值。
     *
     * @param string $pluginName 插件名称
     * @param array  $config     键值对
     * @return void
     */
    public function setMany(string $pluginName, array $config): void
    {
        foreach ($config as $key => $value) {
            $this->set($pluginName . '.' . (string) $key, $value);
        }

        // 写后失效，保证同请求内后续读取能拿到新值
        unset($this->valueCache[$pluginName]);
    }

    /**
     * 清除配置缓存（请求级 + 跨请求）。
     *
     * @param string|null $pluginName 指定插件名称；为 null 时清空全部
     * @return void
     */
    public function flushCache(?string $pluginName = null): void
    {
        if ($pluginName === null) {
            $this->valueCache = [];
            foreach (array_keys($this->knownCacheKeys) as $key) {
                Cache::delete((string) $key);
            }
            $this->knownCacheKeys = [];
            return;
        }

        unset($this->valueCache[$pluginName]);
        Cache::delete($this->getCacheKey($pluginName));
    }

    /**
     * 获取跨请求缓存键。
     *
     * 键名固定（不含 schema 哈希），便于写操作时精确删除；
     * schema 变更通过值内签名比对失效（见 readCache）。
     *
     * @param string $pluginName 插件名称
     * @return string
     */
    protected function getCacheKey(string $pluginName): string
    {
        return self::CACHE_PREFIX . $pluginName;
    }

    /**
     * 读取跨请求缓存（签名不匹配时视为失效）。
     *
     * @param string $cacheKey 缓存键
     * @param array  $schema   当前 schema
     * @return array|null 命中返回配置数组；未命中或已失效返回 null
     */
    protected function readCache(string $cacheKey, array $schema): ?array
    {
        $raw = Cache::get($cacheKey);

        if (!is_array($raw) || !isset($raw['sig'], $raw['values'])) {
            return null;
        }

        // schema 变更（新增/删除/改默认值）时自动失效
        if ($raw['sig'] !== $this->schemaSignature($schema)) {
            return null;
        }

        return is_array($raw['values']) ? $raw['values'] : null;
    }

    /**
     * 写入跨请求缓存。
     *
     * @param string $cacheKey 缓存键
     * @param array  $schema   当前 schema
     * @param array  $values   生效配置值
     * @return void
     */
    protected function writeCache(string $cacheKey, array $schema, array $values): void
    {
        $this->knownCacheKeys[$cacheKey] = true;

        Cache::set($cacheKey, [
            'sig'    => $this->schemaSignature($schema),
            'values' => $values,
        ], $this->getCacheTtl());
    }

    /**
     * 计算 schema 签名（用于缓存失效判定）。
     *
     * 只取「键集 + 类型 + 默认值」，与展示相关的 title/tips/sort 变化不应使缓存失效。
     *
     * @param array $schema 已归一化的 schema
     * @return string
     */
    protected function schemaSignature(array $schema): string
    {
        $slim = [];
        foreach ($schema as $key => $field) {
            $slim[$key] = [
                $field['type'] ?? 'string',
                $field['value'] ?? null,
            ];
        }
        ksort($slim);

        return md5(json_encode($slim, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * 获取跨请求缓存 TTL（秒）。
     *
     * @return int
     */
    protected function getCacheTtl(): int
    {
        $ttl = config('plugin.cache_ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * 设置配置驱动。
     *
     * 切换驱动会同时清空请求级缓存，避免新旧驱动的缓存值串味。
     *
     * @param PluginConfigDriverInterface $driver 要使用的驱动
     * @return void
     */
    public function setDriver(PluginConfigDriverInterface $driver): void
    {
        $this->driver = $driver;
        $this->valueCache = [];
    }

    /**
     * 获取当前配置驱动，必要时初始化它。
     *
     * @return PluginConfigDriverInterface
     * @throws PluginConfigException 如果未配置驱动
     */
    public function getDriver(): PluginConfigDriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        // 驱动由容器根据 plugin.config_driver 配置自动绑定
        $this->driver = $this->app->get(PluginContainerKeys::CONFIG_DRIVER);

        return $this->driver;
    }

    /**
     * 将点号分隔的键解析为插件名称和配置键。
     *
     * @param string $key 点号分隔的键（例如 "plugin_name.config_key"）
     * @return array{0: string, 1: string} [plugin_name, config_key]
     * @throws PluginConfigException 如果键格式无效
     */
    protected function parseKey(string $key): array
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new PluginConfigException(sprintf(
                'Invalid config key format: "%s". Expected "plugin_name.key".',
                $key
            ));
        }

        return [$parts[0], $parts[1]];
    }
}
