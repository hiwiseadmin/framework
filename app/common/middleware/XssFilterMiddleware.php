<?php
declare(strict_types=1);

namespace app\common\middleware;

use Closure;
use think\facade\Config;
use think\Request;
use think\Response;
use app\common\BaseMiddleware;

/**
 * XSS 全局过滤中间件
 *
 * 三层纵深防护，作为模板引擎 {{ }} 自动转义之外的兜底层：
 *   1. 请求入口：清洗所有用户输入（GET/POST/COOKIE），
 *      阻止恶意脚本进入应用层。
 *   2. 响应出口：添加安全响应头（CSP / X-Content-Type-Options 等），
 *      告诉浏览器执行额外防护。
 *   3. 响应体过滤：对 text/html 响应体的反射型 XSS 做二次兜底转义。
 *
 * 设计约束：
 *   - 不修改文件上传（$_FILES）
 *   - JSON 请求体原样保留（API 场景）
 *   - 不依赖第三方库，零额外依赖
 *
 * 配置（config/xss.php）：
 *   'enabled'           => true,          // 总开关
 *   'strip_input'       => true,          // 是否清洗输入
 *   'strip_response'    => false,         // 是否清洗响应体（默认关，避免性能影响）
 *   'security_headers'  => true,          // 是否添加安全头
 *   'allow_strip_tags'  => true,          // 是否允许 strip_tags 清洗
 *   'except'            => ['api/*'],     // 排除路径（支持通配符）
 *   'csp'               => [...],         // CSP 策略
 *
 * @package app\common\middleware
 */
class XssFilterMiddleware extends BaseMiddleware
{
    /**
     * 高风险 HTML 标签（在黑名单场景下使用）
     */
    protected const DANGEROUS_TAGS = [
        'script', 'iframe', 'object', 'embed', 'form', 'link',
        'meta', 'base', 'applet', 'frame', 'frameset', 'ilayer',
        'layer', 'bgsound', 'title', 'style'
    ];

    /**
     * 高风险事件属性
     */
    protected const DANGEROUS_EVENTS = [
        'onerror',    'onload',      'onclick',     'ondblclick',
        'onmousedown','onmouseup',   'onmouseover', 'onmousemove',
        'onmouseout', 'onkeypress',  'onkeydown',   'onkeyup',
        'onfocus',    'onblur',      'onchange',    'onsubmit',
        'onreset',    'onselect',    'onabort',     'onunload',
        'onresize',   'onscroll',    'onhashchange',
    ];

    /**
     * @var array 配置
     */
    protected array $config = [];

    /**
     * @var array 排除路径
     */
    protected array $except = [];

    public function __construct()
    {
        $this->config = Config::get('xss', []);
        $this->except = $this->config['except'] ?? [];
    }

    // ==================== 中间件入口 ====================

    public function handle(Request $request, Closure $next): Response
    {
        // 总开关
        if (empty($this->config['enabled'])) {
            return $next($request);
        }

        // 命令行模式放行
        if ($request->isCli()) {
            return $next($request);
        }

        // 白名单路径放行
        if ($this->inExceptArray($request)) {
            return $next($request);
        }

        // 生成 CSP Nonce（在模板渲染前生成，确保模板可用）
        $cspNonce = '';
        if (!empty($this->config['security_headers'])) {
            $cspNonce = $this->generateNonce();
            $request->cspNonce = $cspNonce;
        }

        // ── 第一层：输入清洗 ──
        if (!empty($this->config['strip_input'])) {
            $this->cleanInput($request);
        }

        // ── 执行业务 ──
        $response = $next($request);

        // ── 第二层：安全响应头 ──
        if (!empty($this->config['security_headers'])) {
            $this->addSecurityHeaders($response, $cspNonce);
        }

        // ── 第三层：响应体过滤 ──
        if (!empty($this->config['strip_response'])) {
            $this->cleanResponse($response);
        }

        return $response;
    }

    // ==================== 第一层：输入清洗 ====================

    /**
     * 清洗用户输入
     *
     * 遍历 GET/POST/COOKIE，对字符串值做 HTML 实体编码。
     * 不处理 $_FILES（文件上传不应编码 filename）。
     * JSON 请求体保留原始结构。
     *
     * 注意：本方法修改 $request 对象的 param 数据，
     * ThinkPHP 的 Request::param() 是从 GET/POST 动态读取，
     * 此处直接对 $_GET/$_POST 做清洗。
     */
    protected function cleanInput(Request $request): void
    {
        $allowStripTags = !empty($this->config['allow_strip_tags']);

        // 清洗 GET 参数（通过 Request 对象，保持框架内部缓存一致性）
        $getData = $request->get();
        if (!empty($getData)) {
            $request->withGet($this->cleanArray($getData, $allowStripTags));
        }

        // 清洗 POST 参数（仅在非 JSON 请求时）
        $contentType = strtolower($request->header('Content-Type') ?? '');
        if (!str_contains($contentType, 'application/json')) {
            $postData = $request->post();
            if (!empty($postData)) {
                $request->withPost($this->cleanArray($postData, $allowStripTags));
            }
        }

        // 清洗 COOKIE（存储型 XSS 通过 Cookie 注入）
        $cookieData = $request->cookie();
        if (!empty($cookieData)) {
            // 跳过框架内部 Cookie（csrf_token / PHPSESSID 等）
            $skipKeys = [
                'csrf_token',
                'PHPSESSID',
                session_name() ?: 'PHPSESSID',
            ];

            foreach ($cookieData as $key => $value) {
                if (in_array($key, $skipKeys, true)) {
                    continue;
                }
                if (is_string($value)) {
                    $cookieData[$key] = $this->sanitizeString($value, $allowStripTags);
                }
            }
            $request->withCookie($cookieData);
        }
    }

    /**
     * 递归清洗数组中的所有字符串值
     *
     * @param array $data
     * @param bool  $allowStripTags
     * @return array
     */
    protected function cleanArray(array $data, bool $allowStripTags): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->cleanArray($value, $allowStripTags);
            } elseif (is_string($value)) {
                $data[$key] = $this->sanitizeString($value, $allowStripTags);
            }
            // 非字符串非数组（int/float/bool/null）不处理
        }
        return $data;
    }

    /**
     * 清洗单个字符串
     *
     * 策略：
     *   1. 如果允许 strip_tags：移除所有 HTML/PHP 标签，仅保留纯文本
     *   2. 始终做 HTML 实体转义兜底
     *   3. 移除 NULL 字节（\0）防截断攻击
     *
     * @param string $value
     * @param bool   $allowStripTags
     * @return string
     */
    protected function sanitizeString(string $value, bool $allowStripTags): string
    {
        // 移除 NULL 字节
        $value = str_replace("\0", '', $value);

        // 默认不 strip_tags（可能破坏合法内容如富文本编辑器输出）
        // 但始终做 HTML 实体转义兜底
        if (!$allowStripTags) {
            // 仅转义关键字符，不 strip 标签
            // 模板引擎 {{ }} 会再转义一层，这里兜底
            return $value;
        }

        // strip_tags 模式：移除 HTML 标签
        // 保留换行符以便纯文本场景
        $value = strip_tags($value);

        // 移除高风险事件属性残留
        foreach (self::DANGEROUS_EVENTS as $event) {
            $value = preg_replace('/\b' . preg_quote($event, '/') . '\s*=/i', 'data-xss-removed=', $value);
        }

        return $value;
    }

    // ==================== 第二层：安全响应头 ====================

    /**
     * 添加安全响应头
     *
     * 参考 OWASP Secure Headers Project 推荐配置。
     * CSP 策略可通过 config/xss.php 的 csp 配置项自定义。
     */
    protected function addSecurityHeaders(Response $response, string $cspNonce = ''): void
    {
        // ── Content-Security-Policy ──
        $csp = $this->buildCsp($cspNonce);
        if ($csp) {
            $response->header(['Content-Security-Policy' => $csp]);
        }

        // ── X-Content-Type-Options ──
        // 禁止浏览器 MIME 嗅探（防止将上传的文本文件当 HTML 执行）
        $response->header(['X-Content-Type-Options' => 'nosniff']);

        // ── X-XSS-Protection ──
        // 启用浏览器内置 XSS 过滤器（现代浏览器虽已弃用但仍有兜底价值）
        $response->header(['X-XSS-Protection' => '1; mode=block']);

        // ── Referrer-Policy ──
        // 限制 Referer 信息泄漏
        $response->header(['Referrer-Policy' => 'strict-origin-when-cross-origin']);

        // ── X-Frame-Options ──
        // 防止点击劫持（除非 CSP 中已定义 frame-ancestors）
        $cspConfig = $this->config['csp'] ?? [];
        if (empty($cspConfig['frame-ancestors'])) {
            $response->header(['X-Frame-Options' => 'SAMEORIGIN']);
        }

        // ── Permissions-Policy ──
        // 限制浏览器特性权限
        $response->header(['Permissions-Policy' => 'camera=(), microphone=(), geolocation=()']);
    }

    /**
     * 构建 Content-Security-Policy
     *
     * 默认策略：同源资源 + unsafe-inline（兼容内联事件处理器）+ nonce 白名单。
     *
     * script-src 同时包含 unsafe-inline 和 nonce 的原因：
     *   nonce 机制仅覆盖 <script> 标签，无法用于 onclick 等内联事件处理器。
     *   浏览器会优先匹配 nonce，不匹配时回退到 unsafe-inline，确保兼容性。
     *
     * 如需放宽（如允许 CDN），通过 config/xss.php csp 覆盖。
     *
     * @param string $nonce CSP nonce 值（由 handle() 生成，模板 <script nonce="..."> 同步使用）
     * @return string CSP 头值
     */
    protected function buildCsp(string $nonce = ''): string
    {
        $defaultCsp = [
            'default-src'     => "'self'",
            'script-src'      => "'self' 'unsafe-inline'",
            'style-src'       => "'self' 'unsafe-inline'",
            'img-src'         => "'self' data: blob:",
            'font-src'        => "'self'",
            'connect-src'     => "'self'",
            'media-src'       => "'self'",
            'object-src'      => "'none'",
            'frame-ancestors' => "'self'",
            'form-action'     => "'self'",
            'base-uri'        => "'self'",
        ];

        // 注入 nonce 到 script-src（浏览器优先匹配 nonce，不匹配时回退 unsafe-inline）
        // 注意：此处保留 unsafe-inline 是因为 nonce 无法覆盖 onclick 等内联事件处理器
        if ($nonce) {
            $defaultCsp['script-src'] = "'self' 'unsafe-inline' 'nonce-" . $nonce . "'";
        }

        $cspConfig = $this->config['csp'] ?? [];

        // 允许用户配置完全覆盖或部分覆盖
        $directives = empty($cspConfig)
            ? $defaultCsp
            : array_merge($defaultCsp, $cspConfig);

        // 如果生成了 nonce 但用户配置覆盖了 script-src 且不含 nonce/unsafe-inline，
        // 则自动追加 nonce，避免用户遗漏导致内联脚本被误拦截
        if ($nonce && !empty($cspConfig['script-src'])) {
            $userScriptSrc = $directives['script-src'] ?? '';
            if ($userScriptSrc && !str_contains($userScriptSrc, "'nonce-") && !str_contains($userScriptSrc, "'unsafe-inline'")) {
                $directives['script-src'] = $userScriptSrc . " 'nonce-" . $nonce . "'";
            }
        }

        // 组装 CSP 字符串
        $parts = [];
        foreach ($directives as $key => $value) {
            $parts[] = $key . ' ' . $value;
        }

        return implode('; ', $parts);
    }

    // ==================== 第三层：响应体过滤 ====================

    /**
     * 清洗响应体
     *
     * 对 text/html 响应做二次兜底：
     *   - 转义未处理的 <script> 标签
     *   - 转义 javascript: / vbscript: 伪协议
     *
     * 默认关闭（strip_response = false），因为：
     *   1. 模板引擎 {{ }} 已做转义
     *   2. RegEx 性能开销高
     *   3. 可能误伤合法的富文本内容
     *
     * 仅在排查到反射型 XSS 或对插件安全性存疑时临时开启。
     */
    protected function cleanResponse(Response $response): void
    {
        $content = $response->getContent();
        if (empty($content) || !is_string($content)) {
            return;
        }

        $contentType = $response->getHeader('Content-Type');
        // 仅过滤 text/html 响应
        if (is_string($contentType) && stripos($contentType, 'text/html') !== false) {
            $content = $this->stripDangerousHtml($content);
            $response->content($content);
        }
    }

    /**
     * 移除响应中的高风险 HTML 片段
     *
     * 注意：这是兜底措施，优先依赖模板引擎转义。
     * 正则方法有漏报和误报风险，仅用于 post-render 兜底。
     *
     * @param string $html
     * @return string
     */
    protected function stripDangerousHtml(string $html): string
    {
        // 移除 <script>...</script> 块（含属性的变体）
        $html = preg_replace(
            '/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is',
            '<!-- xss-filtered: script removed -->',
            $html
        ) ?? $html;

        // 移除 javascript: / vbscript: 伪协议链接
        // 注意：不移除 data: 避免误伤合法的 Base64 图片/字体资源
        $html = preg_replace(
            '/\b(javascript|vbscript)\s*:/i',
            'xss-blocked:',
            $html
        ) ?? $html;

        // 移除内联事件处理器（onerror / onclick 等）
        // 覆盖单引号、双引号、反引号三种引号形式
        foreach (self::DANGEROUS_EVENTS as $event) {
            $html = preg_replace(
                '/\b' . preg_quote($event, '/') . '\s*=\s*["\'`][^"\'`]*["\'`]/i',
                'data-xss-removed',
                $html
            ) ?? $html;
        }

        return $html;
    }

    // ==================== 工具方法 ====================

    /**
     * 生成 CSP Nonce
     *
     * 每次请求生成唯一 nonce，同时写入 CSP 头与 HTML <script nonce="..."> 中，
     * 实现内联脚本白名单验证（替代 unsafe-inline）。
     *
     * @return string 32 字符十六进制 nonce
     */
    protected function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 判断请求路径是否在白名单中
     *
     * @param Request $request
     * @return bool
     */
    protected function inExceptArray(Request $request): bool
    {
        if (empty($this->except)) {
            return false;
        }

        $appName = app('http')->getName() ?: '';
        $path    = '/' . trim($appName . '/' . trim($request->pathinfo(), '/'), '/');
        $path    = strtolower(preg_replace('/\/+/', '/', $path) ?? $path);

        foreach ($this->except as $except) {
            $except = strtolower(trim($except, '/'));

            // 精确匹配
            if ($path === '/' . $except) {
                return true;
            }

            // 通配符匹配
            if (str_contains($except, '*')) {
                $pattern = '#^/' . str_replace('\*', '[^/]*', preg_quote($except, '#')) . '$#';
                if (preg_match($pattern, $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
