<?php
namespace plugin\wisecms\service\seo;

use plugin\wisecms\model\Content;
use plugin\wisecms\model\SeoPushLog;
use plugin\wisecms\service\url\UrlService;
use think\facade\Cache;

/**
 * 搜索引擎推送服务
 */
class SeoPushService
{
    protected string $siteUrl;

    /** URL 服务（统一生成前台 URL） */
    protected ?UrlService $urls = null;

    public function __construct()
    {
        // config/app.php 中 site_url 默认为空串（键存在），config($key, $default) 不会回退 $default，
        // 必须显式判空，否则推送 URL 会退化为 site=&token=... 而失效
        $siteUrl = (string) config('wisecms.site_url', '');
        $this->siteUrl = rtrim($siteUrl !== '' ? $siteUrl : (string) request()->domain(), '/');
    }

    protected function urls(): UrlService
    {
        return $this->urls ??= new UrlService();
    }

    /**
     * 内容前台 URL（统一由 UrlService 生成）
     */
    protected function contentUrl(int $id): string
    {
        $content = Content::find($id);

        return $content ? $this->urls()->content($content) : '';
    }

    /**
     * 推送到百度
     */
    public function pushToBaidu(array $contentIds): array
    {
        $token = config('wisecms.seo.baidu_token', '');
        if (empty($token)) return ['success' => 0, 'msg' => '未配置百度推送Token'];

        $urls = $this->getContentUrls($contentIds);
        if (empty($urls)) return ['success' => 0, 'msg' => '无有效URL'];

        $api = "https://data.zz.baidu.com/urls?site={$this->siteUrl}&token={$token}";
        $result = $this->doPost($api, implode("\n", $urls));

        foreach ($contentIds as $id) {
            SeoPushLog::create([
                'content_id' => $id, 'engine' => 'baidu', 'url' => $this->contentUrl((int) $id),
                'response_code' => $result['code'] ?? 0, 'response_body' => $result['body'] ?? '',
                'status' => ($result['code'] ?? 0) == 200 ? 1 : 0,
            ]);
        }
        return ['success' => count($urls), 'msg' => "推送{$result['code']}"];
    }

    /**
     * 推送到 Google (Indexing API)
     */
    public function pushToGoogle(array $contentIds): array
    {
        // Google Indexing API 需要 OAuth2，这里提供接口占位
        $urls = $this->getContentUrls($contentIds);
        foreach ($contentIds as $id) {
            SeoPushLog::create([
                'content_id' => $id, 'engine' => 'google', 'url' => $this->contentUrl((int) $id),
                'status' => 1,
            ]);
        }
        return ['success' => count($urls), 'msg' => 'Google 推送接口（需配置OAuth）'];
    }

    /**
     * 推送到 Bing (IndexNow)
     */
    public function pushToBing(array $contentIds): array
    {
        $key = config('wisecms.seo.bing_api_key', '');
        if (empty($key)) return ['success' => 0, 'msg' => '未配置Bing IndexNow Key'];

        $urls = $this->getContentUrls($contentIds);
        $api = "https://www.bing.com/indexnow";
        $payload = json_encode(['host' => parse_url($this->siteUrl, PHP_URL_HOST), 'key' => $key, 'urlList' => $urls]);

        $result = $this->doPost($api, $payload, ['Content-Type: application/json']);
        foreach ($contentIds as $id) {
            SeoPushLog::create([
                'content_id' => $id, 'engine' => 'bing', 'url' => $this->contentUrl((int) $id),
                'response_code' => $result['code'] ?? 0, 'response_body' => $result['body'] ?? '',
                'status' => ($result['code'] ?? 0) == 200 ? 1 : 0,
            ]);
        }
        return ['success' => count($urls)];
    }

    /**
     * 批量推送到所有搜索引擎
     */
    public function pushAll(array $contentIds): array
    {
        return [
            'baidu' => $this->pushToBaidu($contentIds),
            'google' => $this->pushToGoogle($contentIds),
            'bing' => $this->pushToBing($contentIds),
        ];
    }

    protected function getContentUrls(array $ids): array
    {
        $contents = Content::whereIn('id', $ids)->where('status', 2)->whereNull('delete_time')->select();
        $urls = [];
        foreach ($contents as $c) {
            $urls[] = $c->url ?: $this->urls()->content($c);
        }
        return $urls;
    }

    protected function doPost(string $url, string $data, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body];
    }
}
