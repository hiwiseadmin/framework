<?php

declare(strict_types=1);

namespace wise\plugin\market;

use wise\support\context\ContextualLogger;
use wise\plugin\exception\PluginInstallException;

/**
 * HttpMarket 从远程 HTTP API 获取插件信息。
 *
 * 此驱动与远程市场服务器通信，搜索插件、获取插件详情并下载插件包。
 */
class HttpMarket extends MarketDriver
{
    /**
     * API 基础 URL。
     *
     * @var string
     */
    protected string $apiUrl;

    /**
     * 用于认证的 API 密钥。
     *
     * @var string
     */
    protected string $apiKey;

    /**
     * HTTP 客户端实例（复用，避免重复创建连接）
     *
     * @var \GuzzleHttp\Client|null
     */
    protected ?\GuzzleHttp\Client $httpClient = null;

    /**
     * 创建一个新的 HttpMarket 实例。
     *
     * @param array $config 驱动配置（必须包含 api_url，可选 api_key）
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->apiUrl = rtrim($config['api_url'] ?? '', '/');
        $this->apiKey = $config['api_key'] ?? '';
    }

    /**
     * 获取 HTTP 客户端实例（懒加载单例）
     *
     * @return \GuzzleHttp\Client
     */
    protected function getHttpClient(): \GuzzleHttp\Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new \GuzzleHttp\Client([
                'timeout' => $this->config['timeout'] ?? 30,
                'connect_timeout' => $this->config['connect_timeout'] ?? 10,
            ]);
        }
        return $this->httpClient;
    }

    /**
     * 在远程市场中搜索插件。
     *
     * @param string $query 搜索关键词
     * @return array 插件元数据数组列表
     */
    public function search(string $query = ''): array
    {
        $response = $this->request('GET', '/plugins/search', ['query' => $query]);

        if ($response === null) {
            return [];
        }

        return $response;
    }

    /**
     * 从远程市场获取插件的详细信息。
     *
     * @param string $name 插件名称
     * @return array|null 插件元数据，未找到则返回 null
     */
    public function getPluginInfo(string $name): ?array
    {
        $response = $this->request('GET', '/plugins/' . urlencode($name));

        if ($response === null) {
            return null;
        }

        return $response;
    }

    /**
     * 从远程市场下载插件。
     *
     * 将插件归档文件下载到临时路径并返回文件路径。
     *
     * @param string $name    插件名称
     * @param string $version 要下载的版本
     * @return string 下载的插件归档文件路径
     * @throws PluginInstallException 如果下载失败
     */
    public function download(string $name, string $version = 'latest'): string
    {
        $endpoint = sprintf('/plugins/%s/download', urlencode($name));
        $params = ['version' => $version];

        try {
            $client = $this->getHttpClient();
            $queryString = http_build_query($params);
            $url = $this->apiUrl . $endpoint . ($queryString !== '' ? '?' . $queryString : '');

            $options = [
                'headers' => $this->getAuthHeaders(),
                'sink' => $this->getTempPath($name, $version),
            ];

            $response = $client->get($url, $options);

            return $options['sink'];
        } catch (\Throwable $e) {
            throw new PluginInstallException(sprintf(
                'Failed to download plugin "%s": %s',
                $name,
                $e->getMessage()
            ));
        }
    }

    /**
     * 获取驱动名称。
     *
     * @return string
     */
    public function getName(): string
    {
        return 'http';
    }

    /**
     * 向市场 API 发起 HTTP 请求。
     *
     * @param string $method   HTTP 方法
     * @param string $endpoint API 端点
     * @param array  $params   请求参数
     * @return array|null 响应数据，失败时返回 null
     */
    protected function request(string $method, string $endpoint, array $params = []): ?array
    {
        if ($this->apiUrl === '') {
            return null;
        }

        try {
            $client = $this->getHttpClient();
            $url = $this->apiUrl . $endpoint;

            $options = [
                'headers' => $this->getAuthHeaders(),
            ];

            if ($method === 'GET') {
                $options['query'] = $params;
            } else {
                $options['json'] = $params;
            }

            $response = $client->request($method, $url, $options);
            $body = (string) $response->getBody();

            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '市场 API 请求失败 [%s %s]: %s',
                $method,
                $endpoint,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * 获取 API 请求的认证头信息。
     *
     * @return array
     */
    protected function getAuthHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $headers;
    }

    /**
     * 获取用于下载插件的临时文件路径。
     *
     * @param string $name    插件名称
     * @param string $version 插件版本
     * @return string
     */
    protected function getTempPath(string $name, string $version): string
    {
        $tempDir = sys_get_temp_dir();
        return $tempDir . DIRECTORY_SEPARATOR . sprintf('%s-%s.zip', $name, $version);
    }
}
