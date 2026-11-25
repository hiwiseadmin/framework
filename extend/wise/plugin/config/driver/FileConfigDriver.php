<?php

declare(strict_types=1);

namespace wise\plugin\config\driver;

use wise\plugin\config\PluginConfigSchema;
use wise\plugin\contract\PluginConfigDriverInterface;
use wise\plugin\exception\PluginConfigException;

/**
 * FileConfigDriver 将插件配置以 JSON 文件形式存储在磁盘上。
 *
 * 每个插件的配置存储在单独的 JSON 文件中，路径为
 * `{config_dir}/{plugin_name}.json`。所有写操作使用独占
 * 文件锁以防止并发访问导致数据丢失。
 */
class FileConfigDriver implements PluginConfigDriverInterface
{
    /**
     * 存储配置文件的目录路径。
     *
     * @var string
     */
    protected string $configDir;

    /**
     * 创建新的 FileConfigDriver 实例。
     *
     * @param string $configDir 存储配置文件的目录
     */
    public function __construct(string $configDir)
    {
        $this->configDir = rtrim($configDir, DIRECTORY_SEPARATOR);
    }

    /**
     * 读取插件的配置值。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @param mixed  $default    键不存在时的默认值
     * @return mixed
     */
    public function read(string $pluginName, string $key, mixed $default = null): mixed
    {
        $data = $this->readFile($pluginName);
        return $data[$key] ?? $default;
    }

    /**
     * 写入插件的配置值。
     *
     * 使用独占文件锁防止读写-修改-写入周期中
     * 并发写入导致的数据丢失。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @param mixed  $value      要存储的值
     * @return void
     */
    public function write(string $pluginName, string $key, mixed $value): void
    {
        $this->withFileLock($pluginName, function (array $data) use ($key, $value): array {
            $data[$key] = $value;
            return $data;
        });
    }

    /**
     * 删除插件的配置值。
     *
     * 使用独占文件锁防止并发访问导致的数据丢失。
     *
     * @param string $pluginName 插件名称
     * @param string $key        配置键
     * @return void
     */
    public function delete(string $pluginName, string $key): void
    {
        $this->withFileLock($pluginName, function (array $data) use ($key): array {
            if (isset($data[$key])) {
                unset($data[$key]);
            }
            return $data;
        });
    }

    /**
     * 读取插件的所有业务配置值。
     *
     * 与 DatabaseConfigDriver::readAll() 保持相同语义：
     * 过滤掉以 '__' 开头的框架内部状态键，仅返回业务配置项。
     *
     * 注意：read() / write() / delete() / clear() 不加该过滤，
     * PluginStateManager 依赖它们读写状态键。
     *
     * @param string $pluginName 插件名称
     * @return array
     */
    public function readAll(string $pluginName): array
    {
        $data   = $this->readFile($pluginName);
        $result = [];

        foreach ($data as $key => $value) {
            if (PluginConfigSchema::isReserved((string) $key)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * 清空插件的所有配置值。
     *
     * 直接删除该插件对应的配置文件。
     *
     * @param string $pluginName 插件名称
     * @return void
     */
    public function clear(string $pluginName): void
    {
        $filePath = $this->getFilePath($pluginName);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * 读取插件的整个配置文件（不加锁）。
     *
     * @param string $pluginName 插件名称
     * @return array
     */
    protected function readFile(string $pluginName): array
    {
        $filePath = $this->getFilePath($pluginName);

        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new PluginConfigException(sprintf(
                'Failed to read config file: %s',
                $filePath
            ));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginConfigException(sprintf(
                'Invalid JSON in config file: %s',
                $filePath
            ));
        }

        return $data ?? [];
    }

    /**
     * 使用独占文件锁（LOCK_EX）原子性地读取、修改并写入插件的配置文件。
     *
     * 回调函数接收当前数据数组，必须返回修改后的数组
     * 以便写回。
     *
     * @param string   $pluginName 插件名称
     * @param callable $callback   函数(array $data): array
     * @return void
     */
    protected function withFileLock(string $pluginName, callable $callback): void
    {
        $filePath = $this->getFilePath($pluginName);

        // 确保目录存在
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 以读写模式打开文件（不存在则创建）
        $handle = fopen($filePath, 'c+b');
        if ($handle === false) {
            throw new PluginConfigException(sprintf(
                'Failed to open config file for writing: %s',
                $filePath
            ));
        }

        // 获取独占锁以防止并发修改
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new PluginConfigException(sprintf(
                'Failed to acquire lock on config file: %s',
                $filePath
            ));
        }

        try {
            // 在锁内读取当前内容
            $rawContent = stream_get_contents($handle);
            if ($rawContent === false || $rawContent === '') {
                $data = [];
            } else {
                $data = json_decode($rawContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // 备份损坏文件后抛出异常，避免静默数据丢失
                    $backupPath = $filePath . '.corrupted.' . date('YmdHis');
                    copy($filePath, $backupPath);
                    throw new PluginConfigException(sprintf(
                        'Corrupted JSON in config file "%s". A backup has been saved to "%s". Manual recovery required.',
                        $filePath,
                        $backupPath
                    ));
                }
            }

            // 应用修改回调
            $data = $callback((array) $data);

            // 编码为 JSON
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new PluginConfigException(sprintf(
                    'Failed to encode config data for plugin: %s',
                    $pluginName
                ));
            }

            // 在锁内原子性地写回
            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, $json);
            if ($written === false) {
                throw new PluginConfigException(sprintf(
                    'Failed to write config file: %s',
                    $filePath
                ));
            }
            fflush($handle);
        } finally {
            // 始终释放锁并关闭句柄
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 获取插件配置文件的文件路径。
     *
     * @param string $pluginName 插件名称
     * @return string
     */
    protected function getFilePath(string $pluginName): string
    {
        return $this->configDir . DIRECTORY_SEPARATOR . $pluginName . '.json';
    }
}
