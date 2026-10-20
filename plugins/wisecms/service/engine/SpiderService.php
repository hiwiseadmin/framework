<?php
namespace plugin\wisecms\service\engine;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\model\MassAssignmentGuard;
use plugin\wisecms\service\security\UrlGuard;


/**
 * 内容采集引擎
 */
class SpiderService
{
    public function createTask(array $data): array
    {
        // P1-1：与后台 Spider 控制器写的是同一张表，此处同样过黑名单，
        // 避免新增调用方时绕过字段防护
        $data = MassAssignmentGuard::filter($data, 'wisecms_spider_task');

        // create_time / update_time 由模型 autoWriteTimestamp 维护
        $task = \plugin\wisecms\model\SpiderTask::create($data);
        return $task->toArray();
    }

    public function runTask(int $taskId): array
    {
        $task = \plugin\wisecms\model\SpiderTask::find($taskId);
        if (!$task) throw new WisecmsException('任务不存在', ErrorCode::NOT_FOUND);

        $config = json_decode($task->config, true) ?: [];
        $urls = $this->fetchList($config);
        $items = [];
        foreach ($urls as $url) {
            try {
                $article = $this->fetchContent($url, $config);
                if ($article) {
                    $content = (new ContentService())->createWithFields(array_merge($article, [
                        'catid' => $task->target_catid,
                        'source_type' => 'spider',
                        'source_url' => $url,
                        'status' => ContentService::STATUS_DRAFT,
                    ]));
                    $items[] = $content->id;
                }
            } catch (\Exception $e) {
                cms_log('warning', "CMS Spider fetch failed for URL [{$url}]: " . $e->getMessage());
            }
        }

        $task->last_run_time = cms_now();
        $task->last_count = count($items);
        $task->total_count = $task->total_count + count($items);
        $task->save();

        return ['count' => count($items), 'ids' => $items];
    }

    /**
     * SSRF 防护：验证 URL 安全性
     *
     * 1) 协议白名单（http/https）
     * 2) 域名解析到全部 A/AAAA 记录并逐一校验（覆盖 IPv6 + DNS 多记录 rebinding）
     * 3) 拒绝内网 / 保留 / 环回 / 链路本地地址
     *
     * P1-A3：策略已收敛到 {@see UrlGuard}，与远程图片下载、死链检测共用同一实现；
     * 本方法保留为薄门面（供子类覆盖 / 保持调用点不变）。
     */
    protected function isUrlSafe(string $url): bool
    {
        return UrlGuard::isSafeOutbound($url);
    }

    /**
     * 解析主机到全部 IPv4/IPv6 地址（IP 字面量直接返回）
     *
     * @return string[]
     */
    protected function resolveHostIps(string $host): array
    {
        return UrlGuard::resolveHostIps($host);
    }

    /**
     * 判断 IP 是否属于禁止访问的内网 / 保留 / 环回 / 链路本地 / 多播地址
     */
    protected function isForbiddenIp(string $ip): bool
    {
        return UrlGuard::isForbiddenIp($ip);
    }

    protected function fetchList(array $config): array
    {
        $url = $config['list_url'] ?? '';
        if (empty($url) || !$this->isUrlSafe($url)) return [];
        try {
            $html = $this->fetchHtml($url);
            if ($html === null) return [];
            preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/', $html, $matches);
            return array_slice(array_unique($matches[1] ?? []), 0, 20);
        } catch (\Exception $e) { return []; }
    }

    protected function fetchContent(string $url, array $config): ?array
    {
        if (!$this->isUrlSafe($url)) return null;
        try {
            $html = $this->fetchHtml($url);
            if ($html === null) return null;
            preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $titleMatch);
            $title = $titleMatch[1] ?? basename($url);
            preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $bodyMatch);
            $body = strip_tags($bodyMatch[1] ?? $html);
            return [
                'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                'content' => substr($body, 0, 50000),
                'description' => mb_substr(strip_tags($body), 0, 200),
            ];
        } catch (\Exception $e) { return null; }
    }

    /**
     * 安全抓取网页：手动跟随重定向并逐跳校验，防止重定向绕过 SSRF 防护
     */
    protected function fetchHtml(string $url): ?string
    {
        $maxRedirects = 5;
        $current = $url;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            if (!$this->isUrlSafe($current)) {
                return null;
            }

            $result = $this->httpGet($current);
            if ($result === null) {
                return null;
            }

            if (isset($result['redirect'])) {
                $current = $result['redirect'];
                continue;
            }

            return $result['body'] ?? null;
        }

        return null;
    }

    /**
     * 发起一次 HTTP GET（不自动跟随重定向），返回 ['body'=>..., 'redirect'=>...]
     */
    protected function httpGet(string $url): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WiseCMS-Spider/1.0)',
                CURLOPT_HEADER => true,
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                curl_close($ch);
                return null;
            }
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            if (in_array($httpCode, [301, 302, 303, 307, 308], true)
                && preg_match('/^Location:\s*([^\r\n]+)/im', $headers, $m)) {
                return ['redirect' => $this->resolveRedirectUrl($url, trim($m[1]))];
            }

            return ['body' => $body];
        }

        // 降级：stream（禁用自动跟随重定向）
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "User-Agent: Mozilla/5.0 (compatible; WiseCMS-Spider/1.0)\r\n",
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $location = null;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('/^Location:\s*([^\r\n]+)/i', $headerLine, $m)) {
                    $location = trim($m[1]);
                    break;
                }
            }
        }
        if ($location !== null) {
            return ['redirect' => $this->resolveRedirectUrl($url, $location)];
        }

        return ['body' => $response];
    }

    /**
     * 将重定向目标解析为绝对 URL
     */
    protected function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';

        if (strpos($location, '/') === 0) {
            return $scheme . '://' . $host . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $scheme . '://' . $host . $dir . $location;
    }
}
