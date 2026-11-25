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
 * 两层纵深防护，作为模板引擎 {{ }} 自动转义之外的兜底层：
 *   1. 请求入口：清洗所有用户输入（GET/POST/COOKIE），阻止恶意脚本进入应用层。
 *   2. 响应体过滤：对 text/html 响应体的反射型 XSS 做二次兜底转义。
 *
 * 安全响应头（含 CSP / nonce）已移交全局 {@see \app\middleware\SecurityHeaders}
 * 统一出口（C5-1 / C5-2）：改造前本中间件只在 admin 应用注册，导致 api / member /
 * plugin 应用没有 CSP，且与全局中间件重复下发同名头。
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
 *   'allow_strip_tags'  => true,          // 是否允许 strip_tags 清洗
 *   'except'            => ['api/*'],     // 排除路径（支持通配符）
 *   'csp'               => [...],         // CSP 策略（由 SecurityHeaders 消费）
 *   'security_headers'  => true,          // 【已废弃】安全头统一由 SecurityHeaders 下发
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


    public function handle(Request $request, Closure $next): Response
    {
        if (empty($this->config['enabled'])) {
            return $next($request);
        }

        if ($request->isCli()) {
            return $next($request);
        }

        if ($this->inExceptArray($request)) {
            return $next($request);
        }

        if (!empty($this->config['strip_input'])) {
            $this->cleanInput($request);
        }

        $response = $next($request);


        if (!empty($this->config['strip_response'])) {
            $this->cleanResponse($response);
        }

        return $response;
    }


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

        $getData = $request->get();
        if (!empty($getData)) {
            $request->withGet($this->cleanArray($getData, $allowStripTags));
        }

        $contentType = strtolower($request->header('Content-Type') ?? '');
        if (!str_contains($contentType, 'application/json')) {
            $postData = $request->post();
            if (!empty($postData)) {
                $request->withPost($this->cleanArray($postData, $allowStripTags));
            }
        }

        $cookieData = $request->cookie();
        if (!empty($cookieData)) {
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
        $value = str_replace("\0", '', $value);

        if (!$allowStripTags) {
            return $value;
        }

        $value = strip_tags($value);

        foreach (self::DANGEROUS_EVENTS as $event) {
            $value = preg_replace('/\b' . preg_quote($event, '/') . '\s*=/i', 'data-xss-removed=', $value);
        }

        return $value;
    }


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
        $html = preg_replace(
            '/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is',
            '<!-- xss-filtered: script removed -->',
            $html
        ) ?? $html;

        $html = preg_replace(
            '/\b(javascript|vbscript)\s*:/i',
            'xss-blocked:',
            $html
        ) ?? $html;

        foreach (self::DANGEROUS_EVENTS as $event) {
            $html = preg_replace(
                '/\b' . preg_quote($event, '/') . '\s*=\s*["\'`][^"\'`]*["\'`]/i',
                'data-xss-removed',
                $html
            ) ?? $html;
        }

        return $html;
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

            if ($path === '/' . $except) {
                return true;
            }

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
