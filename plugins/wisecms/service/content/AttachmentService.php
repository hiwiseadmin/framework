<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\service\security\UrlGuard;

class AttachmentService
{
    /**
     * 处理内容中的图片附件
     */
    public function processContentImages(string $content, array $options = []): string
    {
        // 提取所有图片
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches);
        if (empty($matches[1])) return $content;

        foreach ($matches[1] as $i => $src) {
            // 跳过外部URL
            if (strpos($src, 'http') === 0 && strpos($src, request()->host()) === false) continue;
            
            $processedSrc = $this->processImage($src, $options);
            if ($processedSrc !== $src) {
                $content = str_replace($matches[0][$i], str_replace($src, $processedSrc, $matches[0][$i]), $content);
            }
        }
        return $content;
    }

    /**
     * 处理单张图片
     */
    protected function processImage(string $src, array $options): string
    {
        // wise-image 只接受本地文件路径（不支持远程 URL），且要求文件真实存在；
        // 远程图需先经 downloadImage() 落地后再交给本方法处理。
        if (!function_exists('wise_image')) {
            return $src;
        }

        $localPath = $this->resolveLocalPath($src);
        if ($localPath === null) {
            return $src;
        }

        try {
            $quality  = (int) ($options['quality'] ?? 85);
            $maxWidth = (int) ($options['max_width'] ?? 1920);

            // 与源图同目录生成 webp，不覆盖原图
            $target = (string) preg_replace('/\.[a-zA-Z0-9]+$/', '', $localPath) . '.webp';

            $saved = wise_image($localPath)
                ->parseUrlParams("format,f_webp/quality,q_{$quality}/resize,w_{$maxWidth},m_lfit")
                ->save($target, 'webp');

            if ($saved !== true || !is_file($target)) {
                return $src;
            }

            return $this->fileToWebPath($target) ?? $src;
        } catch (\Throwable $e) {
            // 处理失败保持原图
            return $src;
        }
    }

    /**
     * 把内容中的图片 src 解析为本地文件路径
     *
     * 仅接受「站内相对路径」与「本站绝对 URL」；外部域名返回 null
     * （外部图需由 downloadImage() 先落地为本地文件）。
     */
    protected function resolveLocalPath(string $src): ?string
    {
        $src = trim($src);
        if ($src === '') {
            return null;
        }

        // 外站绝对 URL 不处理
        if (preg_match('#^https?://#i', $src) && stripos($src, (string) request()->host()) === false) {
            return null;
        }

        $path = (string) (parse_url($src, PHP_URL_PATH) ?: $src);
        if ($path === '') {
            return null;
        }

        $candidate = public_path() . ltrim($path, '/');

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * 本地文件路径 → 可访问的相对 URL（不在 public 目录下时返回 null）
     */
    protected function fileToWebPath(string $localPath): ?string
    {
        $root = rtrim(str_replace('\\', '/', public_path()), '/') . '/';
        $file = str_replace('\\', '/', $localPath);

        if (!str_starts_with($file, $root)) {
            return null;
        }

        return '/' . ltrim(substr($file, strlen($root)), '/');
    }

    /**
     * 富文本中的远程图片本地化（UIUX-P1-06）
     *
     * 把 `<img src="http(s)://…">` 逐个下载到本地并替换 src；**下载失败保持原图不变**
     * （容错口径与 `downloadImage()` 一致，不因一张图失败而丢正文）。
     *
     * 原为 `field/editor/Input::downloadRemoteImages()` 的私有实现；「正文」也需要同一能力，
     * 故收敛到这里作为**唯一事实源**（`field/editor/Input` 与 `ContentService` 共用）。
     *
     * @param string $html
     * @return string
     */
    public function localizeRemoteImages(string $html): string
    {
        if ($html === '' || !str_contains($html, '<img')) {
            return $html;
        }

        // ⚠️ 原实现只匹配双引号 src —— 编辑器导出的 HTML 可能是单引号，会整批漏掉。
        //    此处单/双引号都匹配，并保留引号原样。
        if (preg_match_all('/<img[^>]+src=(["\'])([^"\']+)\1[^>]*>/i', $html, $matches, PREG_SET_ORDER) === 0) {
            return $html;
        }

        foreach ($matches as $match) {
            $src = trim((string) $match[2]);
            if ($src === '' || !preg_match('#^https?://#i', $src)) {
                continue;   // 已是相对路径 / data: 等，不处理
            }

            try {
                $local = $this->downloadImage($src);
            } catch (\Throwable $e) {
                continue;   // 下载失败：保持原图
            }
            if ($local === '' || $local === $src) {
                continue;
            }

            $html = str_replace($match[0], str_replace($src, $local, $match[0]), $html);
        }

        return $html;
    }

    /**
     * 上传文件
     */
    public function upload($file, array $config = []): array
    {
        // 通过 wise-attachment 上传
        // 真实入口：AttachmentStore 工厂 + StorageDriverInterface::upload($localPath, $objectName)
        // 注意：wise-attachment 使用「驱动名」（local/qiniu/alioss/cos...），不再是 disk 名
        if (class_exists(\wise\attachment\factory\AttachmentStore::class)) {
            try {
                $localPath = (string) $file->getRealPath();
                $folder    = trim((string) ($config['folder'] ?? ('wisecms/' . date('Y/m'))), '/');
                $ext       = (string) ($file->extension() ?: 'bin');
                $objectName = $folder . '/' . md5_file($localPath) . '.' . $ext;

                /** @var \wise\attachment\factory\AttachmentStore $store */
                $store  = app(\wise\attachment\factory\AttachmentStore::class);
                $driver = $store->driver($config['driver'] ?? null);
                $result = $driver->upload($localPath, $objectName);

                return [
                    'url'  => (string) ($result['url'] ?? $driver->url($objectName)),
                    'path' => (string) ($result['path'] ?? $objectName),
                    'raw'  => $result,
                ];
            } catch (\Throwable $e) {
                // 落到下方 Filesystem 降级
            }
        }

        // 降级：基础上传
        $saveName = \think\facade\Filesystem::disk('public')
            ->putFile('wisecms/' . date('Y/m'), $file);
        return ['url' => \think\facade\Filesystem::disk('public')->url($saveName), 'path' => $saveName];
    }

    /**
     * 获取附件URL
     */
    public function getUrl(string $path): string
    {
        // 真实入口：AttachmentStore::driver()->url($objectName, $options)
        if (class_exists(\wise\attachment\factory\AttachmentStore::class)) {
            try {
                /** @var \wise\attachment\factory\AttachmentStore $store */
                $store = app(\wise\attachment\factory\AttachmentStore::class);

                return (string) $store->driver()->url($path);
            } catch (\Throwable $e) {
                // 落到下方 Filesystem 降级
            }
        }

        return \think\facade\Filesystem::disk('public')->url($path);
    }

    /**
     * 下载远程图片到本地并返回可访问 URL
     *
     * 失败或非法时返回原 URL（保持远程图不变）。
     */
    public function downloadImage(string $url): string
    {
        if (empty($url) || !preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // SSRF 防护（P1-A3）：与 SpiderService 统一为「A/AAAA 全量解析 + 内网拒绝」。
        // 原实现只做 @gethostbyname() 单次校验：不覆盖 IPv6、漏掉多记录 rebinding，
        // 且校验与连接之间存在 DNS 变更（TOCTOU）窗口。
        // 现取回已校验 IP，并在 curl 侧用 CURLOPT_RESOLVE 固定连接目标。
        $pinnedIp = UrlGuard::outboundIp($url);
        if ($pinnedIp === null) {
            return $url;
        }

        try {
            $data = $this->httpDownload($url, 8 * 1024 * 1024, $pinnedIp);
            if ($data === null || $data === '') {
                return $url;
            }

            // 校验是否为图片并确定扩展名
            $ext = '.jpg';
            if (function_exists('getimagesizefromstring')) {
                $info = @getimagesizefromstring($data);
                if ($info === false) {
                    return $url;
                }
                $ext = image_type_to_extension($info[2]) ?: '.jpg';
            } else {
                $pathExt = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                $allowed = ['jpg' => '.jpg', 'jpeg' => '.jpg', 'png' => '.png', 'gif' => '.gif', 'webp' => '.webp'];
                $ext = $allowed[$pathExt] ?? '.jpg';
            }

            $path = 'wisecms/remote/' . date('Y/m') . '/' . md5($url) . $ext;
            \think\facade\Filesystem::disk('public')->put($path, $data);

            return \think\facade\Filesystem::disk('public')->url($path);
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /**
     * 下载远程资源（curl + 严格超时 + 大小上限，失败返回 null）
     *
     * @param string|null $pinnedIp 已通过 SSRF 校验的 IP；非空时用 CURLOPT_RESOLVE
     *                              固定连接目标，消除校验/连接之间的 DNS rebinding 窗口
     */
    protected function httpDownload(string $url, int $maxSize, ?string $pinnedIp = null): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $body = '';
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                // 禁止自动跟随重定向：重定向目标可能指向内网（127.0.0.1 / 169.254.169.254），
                // 从而绕过调用方基于 gethostbyname 的前置校验（SSRF）。此处只取第一跳，
                // 由调用方校验后再决定是否继续（与 SpiderService 的逐跳校验策略一致）。
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WiseCMS/1.0)',
                // 仅允许 http/https，防止 file:// 等协议
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // 下载过程中超限立即中断
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, $maxSize): int {
                    $body .= $chunk;
                    return strlen($body) > $maxSize ? 0 : strlen($chunk);
                },
            ];

            if ($pinnedIp !== null && $pinnedIp !== '') {
                // 连接期固定 IP：即使 DNS 在校验后被改写到内网地址，curl 也不会重新解析
                $options[CURLOPT_RESOLVE] = [UrlGuard::curlResolveEntry($url, $pinnedIp)];
            }

            curl_setopt_array($ch, $options);
            $ok = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($ok === false || ($httpCode >= 400 && $httpCode > 0) || $body === '') {
                return null;
            }
            return $body;
        }

        // 降级：file_get_contents（需 allow_url_fopen）
        $ctx = stream_context_create(['http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            // 降级分支同样禁止自动跟随重定向，避免绕过内网校验（SSRF）
            'follow_location' => 0,
            'header' => "User-Agent: Mozilla/5.0 (compatible; WiseCMS/1.0)\r\n",
        ]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '' || strlen($data) > $maxSize) {
            return null;
        }
        return $data;
    }
}
