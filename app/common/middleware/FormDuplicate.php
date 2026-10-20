<?php
declare(strict_types=1);

namespace app\common\middleware;


use wise\support\context\ContextualLogger;
use app\common\BaseMiddleware;
use think\facade\Cache;
use think\facade\View;
use think\Request;
use think\Response;
use Closure;

/**
 * 表单重复提交防护中间件
 *
 * 核心特性：
 *   - 每 <form> 独立 Token，互不干扰
 *   - Token 绑定 Session，防跨用户重放
 *   - 支持 AJAX（通过 View::assign 暴露 Token 列表）
 *   - 加密安全随机数生成
 *
 * 配置参数（路由注册时传入）：
 *   'expire'       => 300,    // Token 有效期（秒）
 *   'token_name'   => 'form_token',
 *   'cache_prefix' => 'form_token:',
 *   'methods'      => ['POST', 'PUT', 'PATCH', 'DELETE'],
 *   'actions'      => [],     // 受保护的方法名（大写），空 = 全部放行 + 日志警告
 *   'error_message'=> '表单已提交，请勿重复操作',
 */
class FormDuplicate extends BaseMiddleware
{
    /**
     * 本请求生成的全部 Token（用于模板注入）
     * @var string[]
     */
    protected array $tokensGenerated = [];

    // ==================== 入口 ====================

    public function handle(Request $request, Closure $next, array $options = []): Response
    {
        $options = array_merge([
            'expire'        => 300,
            'token_name'    => 'form_token',
            'cache_prefix'  => 'form_token:',
            'methods'       => ['POST', 'PUT', 'PATCH', 'DELETE'],
            'actions'       => [],
            'error_message' => '表单已提交，请勿重复操作',
        ], $options);

        $options['actions'] = array_map('strtoupper', $options['actions']);
        $options['methods'] = array_map('strtoupper', $options['methods']);

        // ---- 写请求：校验 Token ----
        if (in_array($request->method(), $options['methods'])
            && !empty($options['actions'])
            && in_array(strtoupper($request->action()), $options['actions'])
        ) {
            return $this->validateToken($request, $next, $options);
        }

        // ---- 读请求：生成 Token 并注入 HTML ----
        if ($request->method() === 'GET'
            && !empty($options['actions'])
            && in_array(strtoupper($request->action()), $options['actions'])
        ) {
            return $this->injectTokens($request, $next, $options);
        }

        // 配置了中间件但未指定 actions → 日志警告
        if (empty($options['actions'])) {
            ContextualLogger::warning(
                'FormDuplicate middleware loaded without configured actions — all requests bypassed.',
                ['url' => $request->url()]
            );
        }

        return $next($request);
    }

    // ==================== Token 生成 + 注入 ====================

    /**
     * GET 请求：生成 Token 并注入到 HTML 表单和模板变量
     */
    protected function injectTokens(Request $request, Closure $next, array $options): Response
    {
        $this->tokensGenerated = [];
        $expire = (int)$options['expire'];

        $response = $next($request);

        // HTML 响应：注入每个 <form> 独立 Token
        if ($this->isHtmlResponse($response)) {
            $content = $response->getContent();
            $content = $this->injectTokenToForms($content, $options['token_name'], $expire);
            $response->content($content);
        }

        // 注入模板变量，供 JS/AJAX 使用
        if (!empty($this->tokensGenerated)) {
            View::assign('__form_tokens__', $this->tokensGenerated);
            // 也注入第一个 Token 作为兜底
            View::assign('__form_token__', reset($this->tokensGenerated));
        }

        return $response;
    }

    /**
     * 为 HTML 中每个 <form> 注入独立 Token
     */
    protected function injectTokenToForms(string $content, string $tokenName, int $expire): string
    {
        $self    = $this;
        $pattern = '/<form\b[^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) use ($tokenName, $expire, $self) {
            $formTag = $matches[0];
            $newToken = $self->generateAndStoreToken($expire);

            // 表单已有 token hidden 字段 → 替换旧值为新 Token
            if (preg_match(
                '/name=["\']' . preg_quote($tokenName) . '["\']/i',
                $formTag
            )) {
                return preg_replace(
                    '/(name=["\']' . preg_quote($tokenName, '/') . '["\']\s+value=["\'])([^"\']*)(["\'])/i',
                    '${1}' . $newToken . '${3}',
                    $formTag
                );
            }

            // 无 token 字段 → 注入新 hidden input
            return $formTag . sprintf(
                '<input type="hidden" name="%s" value="%s">',
                $tokenName, $newToken
            );
        }, $content);
    }

    // ==================== Token 校验 ====================

    /**
     * POST/PUT/DELETE 请求：校验并消费 Token
     */
    protected function validateToken(Request $request, Closure $next, array $options): Response
    {
        $token = $request->param($options['token_name'])
            ?: $request->header('X-Form-Token')
            ?: '';

        if (empty($token)) {
            return $this->error('缺少表单令牌', [], 400);
        }

        $cacheKey = $this->buildCacheKey($token, $options['cache_prefix']);

        if (!Cache::has($cacheKey)) {
            return $this->error(
                $options['error_message'],
                [],
                400
            );
        }

        // 原子删除：消费后立即失效，防重复提交
        Cache::delete($cacheKey);

        return $next($request);
    }

    // ==================== Token 生命周期 ====================

    /**
     * 生成 Token 并存入缓存
     */
    public function generateAndStoreToken(int $expire): string
    {
        $token    = $this->generateToken();
        $cacheKey = $this->buildCacheKey($token);

        Cache::set($cacheKey, 1, $expire);

        $this->tokensGenerated[] = $token;

        return $token;
    }

    /**
     * 生成加密安全随机 Token
     */
    protected function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 构建缓存 Key（绑定 Session，防跨用户重放）
     */
    protected function buildCacheKey(string $token, string $prefix = 'form_token:'): string
    {
        $sessionId = session_id() ?: 'no_session';
        return $prefix . sha1($sessionId) . ':' . $token;
    }

    // ==================== 工具方法 ====================

    /**
     * 判断响应是否为 HTML
     */
    protected function isHtmlResponse($response): bool
    {
        if (!$response instanceof Response) {
            return false;
        }

        $contentType = $response->getHeader('Content-Type');
        if (!$contentType) {
            return false;
        }

        return strpos($contentType, 'text/html') !== false;
    }
}
