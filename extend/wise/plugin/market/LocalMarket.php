<?php

declare(strict_types=1);

namespace wise\plugin\market;

use think\App;
use wise\plugin\PluginContainerKeys;
use wise\plugin\scanner\PluginScanner;

/**
 * LocalMarket 扫描本地已安装的插件作为市场数据源。
 *
 * 此驱动提供一个简单的本地市场，列出当前插件目录中所有已安装的插件。
 */
class LocalMarket extends MarketDriver
{
    /**
     * ThinkPHP 应用实例。
     *
     * @var App
     */
    protected App $app;

    /**
     * 创建一个新的 LocalMarket 实例。
     *
     * @param App   $app    ThinkPHP 应用实例
     * @param array $config 驱动配置
     */
    public function __construct(App $app, array $config = [])
    {
        parent::__construct($config);
        $this->app = $app;
    }

    /**
     * 在本地安装中搜索插件。
     *
     * @param string $query 搜索关键词（按名称过滤）
     * @return array 插件元数据数组列表
     */
    public function search(string $query = ''): array
    {
        $scanner = $this->app->get(PluginContainerKeys::SCANNER);
        $plugins = $scanner->scan();

        $results = [];
        foreach ($plugins as $name => $metadata) {
            if ($query !== '' && !str_contains(strtolower($name), strtolower($query))) {
                continue;
            }
            $results[] = $metadata->toArray();
        }

        return $results;
    }

    /**
     * 获取本地插件的详细信息。
     *
     * @param string $name 插件名称
     * @return array|null 插件元数据，未找到则返回 null
     */
    public function getPluginInfo(string $name): ?array
    {
        $scanner = $this->app->get(PluginContainerKeys::SCANNER);
        $plugins = $scanner->scan();

        if (!isset($plugins[$name])) {
            return null;
        }

        return $plugins[$name]->toArray();
    }

    /**
     * 下载（解析）本地插件。
     *
     * 由于插件已在本地，此方法直接返回插件目录路径。
     *
     * @param string $name    插件名称
     * @param string $version 要下载的版本（本地模式下忽略）
     * @return string 插件目录路径
     */
    public function download(string $name, string $version = 'latest'): string
    {
        $scanner = $this->app->get(PluginContainerKeys::SCANNER);

        if (!isset($scanner->scan()[$name])) {
            return '';
        }

        return $scanner->getPluginDir() . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * 获取驱动名称。
     *
     * @return string
     */
    public function getName(): string
    {
        return 'local';
    }
}
