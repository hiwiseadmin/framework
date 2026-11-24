<?php
namespace app\api\dispatch;

use think\App;
use think\exception\ClassNotFoundException;
use think\exception\HttpException;
use think\facade\Log;
use think\helper\Str;
use think\route\Dispatch;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;
use wise\support\exception\WiseException;

/**
 * API 插件调度器
 *
 * 解析应用内路由 :plugin/[:v{N}/][:controller/[:action]] 并调度到对应插件控制器。
 * 注意 URL 是 /api/{plugin}/...，但 'api' 段作为参数进来前已被 think-multi-app 剥离。
 *
 * 插件 API 版本（v{N}）属于插件自身：由 manifest.json 的 api 字段声明，
 * 网关据此执行版本闸门（supported / require_version / deprecated / sunset）。
 * 控制器解析按 "版本差异目录 → 共享基线" 顺序回退。
 *
 * 异常处理（2026-07-09 wise-api 接入后）：
 *   - 版本类异常 → WiseException(4100/4101) → 统一 {code, msg, data}
 *   - init() 阶段 HttpException → errorToJson() 返回 {code, msg, data}
 *   - 控制器不存在 → ErrorCode::NOT_FOUND JSON
 *   - 方法不存在 → HttpException(404) → errorToJson() JSON
 *   - 业务异常 → WiseException 透传，其余 Throwable → ErrorCode::SERVER_ERROR JSON
 *   - 正常响应 → 通过 autoResponse() 返回，并附加版本相关响应头
 */
class ApiDispatch extends Dispatch
{
    /**
     * 插件名
     * @var string
     */
    protected $plugin;

    /**
     * 控制器名
     * @var string
     */
    protected $controller;

    /**
     * 操作名
     * @var string
     */
    protected $actionName;

    /**
     * 当前请求解析出的插件 API 版本（如 'v1'），由版本闸门注入。
     * @var string
     */
    protected $version = '';

    /**
     * 插件 API 版本策略（来自 manifest.json 的 api 字段，已归一化）。
     * @var array
     */
    protected $apiPolicy = [];

    /**
     * 控制器命名空间前缀。
     *
     * 空串 = 插件模式：按 plugin\{ident}\controller\api\ 解析（含插件生命周期/版本闸门）；
     * 非空 = Bundle 模式：wise-* 库包经 config('wise-api.bundles') 注册后，
     *        直接在该命名空间下解析控制器，跳过插件状态检查与 ServiceProvider 初始化。
     *
     * @var string
     */
    protected $controllerNamespace = '';

    /**
     * 请求是否显式携带了版本段。
     * @var bool
     */
    protected $versionExplicit = false;

    /**
     * 初始化方法
     */
    public function init()
    {
        $this->app = app();

        $uri = trim((string) $this->request->pathinfo(), '/');
        $seg = array_values(array_filter(explode('/', $uri)));

        $this->plugin = $seg[0] ?? $this->request->route('plugin');

        if (!$this->plugin) {
            throw new HttpException(403, 'Plugin name is required');
        }

        $pluginManager = $this->app->get(PluginContainerKeys::MANAGER);

        $bundle = $this->resolveBundle($this->plugin);
        if ($bundle !== null) {
            $this->controllerNamespace = $bundle['namespace'];
            $this->apiPolicy           = $bundle['policy'];
        } else {
            if (!$this->checkPluginStatus($this->plugin, $pluginManager)) {
                throw new HttpException(403, "Plugin '{$this->plugin}' is not enabled or does not exist");
            }

            $pluginManager->initServiceProvider($this->plugin);

            $this->apiPolicy = $pluginManager->getPlugin($this->plugin)->getApiVersionPolicy();
        }

        [$version, $explicit, $offset] = $this->resolveApiVersion($seg);
        $this->version         = $version;
        $this->versionExplicit = $explicit;
        $this->request->api_version = $version;

        $controller = strip_tags($seg[1 + $offset] ?? $this->rule->config('default_controller'));
        if (strpos($controller, '.')) {
            $pos              = strrpos($controller, '.');
            $this->controller = substr($controller, 0, $pos) . '\\' . Str::studly(substr($controller, $pos + 1));
        } else {
            $this->controller = Str::studly($controller);
        }

        $this->actionName = strip_tags($seg[2 + $offset] ?? $this->rule->config('default_action'));

        $params = ['plugin' => $this->plugin];
        for ($i = 3 + $offset; $i < count($seg); $i += 2) {
            $key = $seg[$i] ?? '';
            $value = $seg[$i + 1] ?? '';

            if (!$this->isValidParamKey($key)) {
                continue;
            }

            $params[$key] = $this->normalizeValue($value);
        }

        $this->request
            ->setRoute($params)
            ->setController($this->controller)
            ->setAction($this->actionName);
    }

    /**
     * 解析插件 API 版本并执行版本闸门校验。
     *
     * 返回 [$version, $explicit, $offset]：
     *   - $version  实际服务版本（显式或插件默认）
     *   - $explicit 请求是否显式携带了版本段
     *   - $offset   版本段占用的位置偏移（用于定位 controller/action 起始下标）
     *
     * @param string[] $seg 已按 '/' 切分的 pathinfo 段
     * @return array{0:string,1:bool,2:int}
     */
    protected function resolveApiVersion(array $seg): array
    {
        $policy   = $this->apiPolicy;
        $versions = $policy['versions'] ?? [];
        $default  = (string) ($policy['default_version'] ?? ($versions[0] ?? 'v1'));

        $version  = $default;
        $explicit = false;
        $offset   = 0;

        if (isset($seg[1]) && preg_match('/^v[1-9]\d*$/', $seg[1])) {
            $requested = $seg[1];
            if (!in_array($requested, $versions, true)) {
                $this->versionError(
                    ErrorCode::VERSION_NOT_SUPPORTED,
                    "[{$this->plugin}] 不支持 API 版本 {$requested}",
                    [
                        'plugin'            => $this->plugin,
                        'requested_version' => $requested,
                        'supported'         => $versions,
                    ]
                );
            }
            $version  = $requested;
            $explicit = true;
            $offset   = 1;
        } elseif (!empty($policy['require_version'])) {
            $this->versionError(
                ErrorCode::VERSION_NOT_SUPPORTED,
                "[{$this->plugin}] 要求显式指定 API 版本",
                ['plugin' => $this->plugin, 'supported' => $versions]
            );
        }

        $deprecated = $policy['deprecated'] ?? [];
        if (isset($deprecated[$version]['sunset'])) {
            $sunsetTs = strtotime((string) $deprecated[$version]['sunset']);
            if ($sunsetTs !== false && time() >= $sunsetTs) {
                $this->versionError(
                    ErrorCode::VERSION_GONE,
                    "插件 [{$this->plugin}] 的 API 版本 {$version} 已下线",
                    [
                        'plugin'  => $this->plugin,
                        'version' => $version,
                        'sunset'  => $deprecated[$version]['sunset'],
                        'latest'  => $versions ? end($versions) : null,
                    ]
                );
            }
        }

        return [$version, $explicit, $offset];
    }

    /**
     * 抛出版本类业务异常（经全局统一渲染为 {code, msg, data}）。
     *
     * @param ErrorCode $code    版本错误码（VERSION_NOT_SUPPORTED / VERSION_GONE）
     * @param string    $message 错误描述
     * @param array     $data    附加上下文数据
     * @return never
     */
    protected function versionError(ErrorCode $code, string $message, array $data = []): void
    {
        $httpCode = $code === ErrorCode::VERSION_GONE ? 410 : 404;
        throw new WiseException($code->value, $message, $httpCode, null, $data);
    }

    /**
     * 执行调度
     *
     * 捕获所有异常并转换为 wise-api 统一 JSON 响应格式 {code, msg, data}，
     * 确保中间件链路完整、ApiLogMiddleware 能记录异常请求。
     */
    public function exec()
    {
        try {
            $this->init();
        } catch (WiseException $e) {
            return $this->errorToJson($e->getErrorCode(), $e->getMessage(), $e->getContext());
        } catch (HttpException $e) {
            return $this->errorToJson($e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->logUnexpected($e);
            return $this->errorToJson(ErrorCode::SERVER_ERROR->value, $this->safeErrorMessage($e));
        }

        try {
            $instance = $this->controller($this->controller);
        } catch (ClassNotFoundException $e) {
            return $this->errorToJson(ErrorCode::NOT_FOUND->value, 'API 控制器不存在: ' . $e->getClass());
        }

        $this->registerControllerMiddleware($instance);

        try {
            return $this->withVersionHeaders(
                $this->app->middleware->pipeline('controller')
                    ->send($this->request)
                    ->then(function () use ($instance) {
                        return $this->invokeAction($instance);
                    })
            );
        } catch (WiseException $e) {
            return $this->errorToJson($e->getErrorCode(), $e->getMessage(), $e->getContext());
        } catch (HttpException $e) {
            return $this->errorToJson($e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->logUnexpected($e);
            return $this->errorToJson(ErrorCode::SERVER_ERROR->value, $this->safeErrorMessage($e));
        }
    }

    /**
     * 记录"非业务异常"—— 也就是被兜成 `10501 服务器内部错误` 的那一类
     *
     * 【为什么必须打】响应里只给通用文案（不泄露内部结构），但这意味着**真因（表缺失 /
     * 空指针 / 配置错）在线上只剩一句「服务器内部错误」** —— 2026-09-27 排查肉鸽 10501
     * 就卡在这里：日志里查不到任何线索，只能靠猜。
     * 日志级别用 `error`：`config/log.php` 的 `level` 只收 `error` 与 `info`，
     * `warning` **不会落盘**（所以这里不能用 warning）。
     */
    protected function logUnexpected(\Throwable $e): void
    {
        try {
            Log::error('[api] 未捕获异常（已兜成 10501）：' . $e->getMessage(), [
                'class'  => get_class($e),
                'at'     => $e->getFile() . ':' . $e->getLine(),
                'plugin' => $this->plugin,
                'path'   => $this->request->pathinfo(),
                'method' => $this->request->method(),
                'trace'  => $e->getTraceAsString(),
            ]);
        } catch (\Throwable $ignore) {
        }
    }

    /**
     * 调用插件控制器的操作方法
     */
    protected function invokeAction($instance)
    {
        $suffix = $this->rule->config('action_suffix');
        $action = $this->actionName . $suffix;

        if (is_callable([$instance, $action])) {
            $vars = $this->request->param();

            try {
                $reflect = new \ReflectionMethod($instance, $action);
                $actionName = $reflect->getName();
                if ($suffix) {
                    $actionName = substr($actionName, 0, -strlen($suffix));
                }
                $this->request->setAction($actionName);
            } catch (\ReflectionException $e) {
                $reflect = new \ReflectionMethod($instance, '__call');
                $vars    = [$action, $vars];
                $this->request->setAction($action);
            }
        } else {
            throw new HttpException(404,
                '插件方法不存在: ' . get_class($instance) . '->' . $action . '()'
            );
        }

        $data = $this->app->invokeReflectMethod($instance, $reflect, $vars);

        return $this->autoResponse($data);
    }

    /**
     * 将异常转换为 wise-api 统一 JSON 错误响应
     *
     * HttpException::getCode() 返回 HTTP 状态码（403/404 等），
     * 此处映射为 wise-api ErrorCode 枚举值，再委托给 ResponseWrapper::error()。
     *
     * @param int    $errorCode 错误码（可能为 HTTP 状态码或 ErrorCode 枚举值）
     * @param string $message   错误描述
     * @param mixed  $data      附加数据（如校验错误/版本上下文）
     */
    protected function errorToJson(int $errorCode, string $message, $data = []): \think\Response
    {
        $code = $this->mapHttpStatusToErrorCode($errorCode);
        $wrapper = $this->resolveResponseWrapper();
        return $this->withVersionHeaders($wrapper->error($message, $data, $code));
    }

    /**
     * 为统一响应附加版本相关响应头。
     */
    protected function withVersionHeaders(\think\Response $response): \think\Response
    {
        $version = $this->version ?? '';
        if ($version === '') {
            return $response;
        }

        $policy     = $this->apiPolicy ?? [];
        $deprecated = $policy['deprecated'] ?? [];
        $versions   = $policy['versions'] ?? [];

        $headers = ['X-API-Version' => $version];

        if (isset($deprecated[$version])) {
            $headers['X-API-Deprecated'] = 'true';

            if ($versions) {
                $headers['X-API-Latest-Version'] = (string) end($versions);
            }

            $sunset = $deprecated[$version]['sunset'] ?? '';
            if (!empty($sunset) && ($ts = strtotime((string) $sunset)) !== false) {
                $sunsetGmt = gmdate('D, d M Y H:i:s', $ts) . ' GMT';
                $headers['Sunset']       = $sunsetGmt;
                $headers['X-API-Sunset'] = $sunsetGmt;
            }

            $message = $deprecated[$version]['message'] ?? '';
            if (!empty($message)) {
                $headers['X-API-Deprecation-Message'] = $message;
            }
        } elseif (!$this->versionExplicit) {
            $headers['X-API-Default-Version'] = $version;
        }

        $response->header($headers);

        return $response;
    }

    /**
     * 生成安全的错误描述：生产环境隐藏内部异常详情（G7）。
     */
    protected function safeErrorMessage(\Throwable $e): string
    {
        return app()->isDebug() ? '内部服务异常: ' . $e->getMessage() : '内部服务异常';
    }

    /**
     * HTTP 状态码 → wise-api ErrorCode 映射
     *
     * 若传入值已是 ErrorCode 枚举值范围（≥1000），直接返回；
     * 否则按 HTTP 状态码映射到对应业务错误码。
     */
    private function mapHttpStatusToErrorCode(int $code): int
    {
        if ($code >= 1000 || $code === 0) {
            return $code;
        }

        return match ($code) {
            400 => ErrorCode::INVALID_PARAM->value,
            401 => ErrorCode::UNAUTHORIZED->value,
            403 => ErrorCode::FORBIDDEN->value,
            404 => ErrorCode::NOT_FOUND->value,
            405 => ErrorCode::METHOD_NOT_ALLOWED->value,
            429 => ErrorCode::RATE_LIMITED->value,
            500 => ErrorCode::SERVER_ERROR->value,
            503 => ErrorCode::UPSTREAM_ERROR->value,
            default => ErrorCode::UNKNOWN_ERROR->value,
        };
    }

    /**
     * 从容器解析 ResponseWrapper（延迟解析，避免调度前加载）
     */
    protected function resolveResponseWrapper(): ResponseWrapper
    {
        if ($this->app->bound(ResponseWrapper::class)) {
            return $this->app->get(ResponseWrapper::class);
        }

        return new ResponseWrapper($this->app);
    }

    /**
     * 解析 URL 首段标识是否为已注册的 API Bundle（wise-* 库包）。
     *
     * 注册表来源：config('wise-api.bundles')，形如：
     * ```php
     * 'bundles' => [
     *     'workflow' => [
     *         'enable'    => true,                             // 总开关，false 时该端点 403
     *         'namespace' => 'wise\\workflow\\controller',     // 控制器命名空间根
     *         // 可选：与插件 manifest api 字段同构的版本策略（默认无版本管理）
     *         'policy'    => [],
     *     ],
     * ],
     * ```
     *
     * 命中且命名空间有效时返回归一化配置；未注册或命名空间为空返回 null（回落插件解析）。
     *
     * @param string $ident URL 首段标识
     * @return array{namespace:string, policy:array}|null
     */
    protected function resolveBundle(string $ident): ?array
    {
        $bundles = config('wise-api.bundles', []);
        if (!is_array($bundles) || !isset($bundles[$ident]) || !is_array($bundles[$ident])) {
            return null;
        }

        $item     = $bundles[$ident];
        $enabled  = $item['enable'] ?? true;
        $namespace = trim((string) ($item['namespace'] ?? ''), '\\');

        if (empty($enabled)) {
            throw new HttpException(403, "API bundle '{$ident}' is disabled");
        }
        if ($namespace === '') {
            return null;
        }

        $policy = $item['policy'] ?? [];
        if (!is_array($policy)) {
            $policy = [];
        }

        return [
            'namespace' => $namespace,
            'policy'    => $policy,
        ];
    }

    /**
     * 检查插件状态（使用新系统 PluginManager）
     */
    protected function checkPluginStatus(string $plugin, PluginManager $pluginManager): bool
    {
        try {
            $pluginMeta = $pluginManager->getPlugin($plugin);
        } catch (\wise\plugin\exception\PluginNotFoundException $e) {
            return false;
        }

        if (!$pluginMeta->isDevelop() && !$pluginMeta->isEnabled()) {
            return false;
        }

        $this->request->plugin = array_merge($pluginMeta->toArray(), [
            'defaultPlugin' => false,
            'config'        => $pluginMeta->getConfigSchema(),
        ]);

        return true;
    }

    /**
     * 实例化插件控制器
     *
     * 版本化解析顺序：
     *   1. plugin\{p}\controller\api\{version}\{Name}[Controller]   （版本差异实现，可选）
     *   2. plugin\{p}\controller\api\{Name}[Controller]             （共享基线实现）
     */
    public function controller(string $name)
    {
        $suffix  = $this->rule->config('controller_suffix') ? 'Controller' : '';
        $version = $this->version ?? '';

        $candidates = [];

        if ($this->controllerNamespace !== '') {
            $ns = $this->controllerNamespace;
            if ($version !== '' && preg_match('/^v[1-9]\d*$/', $version)) {
                $candidates[] = "{$ns}\\{$version}\\{$name}";
                $candidates[] = "{$ns}\\{$version}\\{$name}Controller";
            }
            $candidates[] = "{$ns}\\{$name}";
            $candidates[] = "{$ns}\\{$name}Controller";
        } else {
            if ($version !== '' && preg_match('/^v[1-9]\d*$/', $version)) {
                $candidates[] = "plugin\\{$this->plugin}\\controller\\api\\{$version}\\{$name}";
                $candidates[] = "plugin\\{$this->plugin}\\controller\\api\\{$version}\\{$name}{$suffix}";
            }
            $candidates[] = "plugin\\{$this->plugin}\\controller\\api\\{$name}";
            $candidates[] = "plugin\\{$this->plugin}\\controller\\api\\{$name}{$suffix}";
        }

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $this->app->make($class, [], true);
            }
        }

        throw new ClassNotFoundException('API controller class not exists: ' . implode(' | ', $candidates), $name);
    }

    /**
     * 使用反射机制注册控制器中间件
     */
    protected function registerControllerMiddleware($controller): void
    {
        $class = new \ReflectionClass($controller);

        if ($class->hasProperty('middleware')) {
            $reflectionProperty = $class->getProperty('middleware');
            if (version_compare(PHP_VERSION, '8.1.0', '<')) {
                $reflectionProperty->setAccessible(true);
            }

            $middlewares = $reflectionProperty->getValue($controller);
            $action      = $this->request->action(true);

            foreach ($middlewares as $key => $val) {
                if (!is_int($key)) {
                    $middleware = $key;
                    $options    = $val;
                } elseif (isset($val['middleware'])) {
                    $middleware = $val['middleware'];
                    $options    = $val['options'] ?? [];
                } else {
                    $middleware = $val;
                    $options    = [];
                }

                if (isset($options['only']) && !in_array($action, $this->parseActions($options['only']))) {
                    continue;
                } elseif (isset($options['except']) && in_array($action, $this->parseActions($options['except']))) {
                    continue;
                }

                if (is_string($middleware) && strpos($middleware, ':')) {
                    $middleware = explode(':', $middleware);
                    if (count($middleware) > 1) {
                        $middleware = [$middleware[0], array_slice($middleware, 1)];
                    }
                }

                $this->app->middleware->controller($middleware);
            }
        }
    }

    /**
     * 解析中间件动作
     */
    protected function parseActions($actions)
    {
        return array_map(function ($item) {
            return strtolower($item);
        }, is_string($actions) ? explode(",", $actions) : $actions);
    }

    /**
     * 验证参数键名
     */
    protected function isValidParamKey(string $key): bool
    {
        return !empty($key) && preg_match('/^[a-zA-Z0-9-_]\w*$/', $key) === 1;
    }
    
    /**
     * 值标准化
     */
    protected function normalizeValue($value)
    {
        if ($value === '') {
            return '';
        }
        
        if (is_numeric($value)) {
            return (int)$value;
        }
        
        return $value;
    }
}