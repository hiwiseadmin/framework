<?php

declare(strict_types=1);

namespace wise\plugin\contract;

/**
 * MarketDriverInterface 定义了插件市场驱动的契约。
 *
 * 实现类可以从本地目录或远程 HTTP API 获取插件信息。
 */
interface MarketDriverInterface
{
    /**
     * 在市场中搜索插件。
     *
     * @param string $query 搜索关键词
     * @return array 插件元数据数组列表
     */
    public function search(string $query = ''): array;

    /**
     * 从市场获取插件的详细信息。
     *
     * @param string $name 插件名称
     * @return array|null 插件元数据，未找到则返回 null
     */
    public function getPluginInfo(string $name): ?array;

    /**
     * 从市场下载插件。
     *
     * @param string $name    插件名称
     * @param string $version 要下载的版本
     * @return string 下载的插件归档文件路径
     */
    public function download(string $name, string $version = 'latest'): string;

    /**
     * 获取驱动名称。
     *
     * @return string
     */
    public function getName(): string;
}
