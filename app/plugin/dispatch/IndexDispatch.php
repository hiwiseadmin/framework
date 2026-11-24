<?php
namespace app\plugin\dispatch;

use think\App;
use think\exception\ClassNotFoundException;
use think\exception\HttpException;
use think\helper\Str;
use think\route\Dispatch;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;

/**
 * 默认插件前端调度器
 */
class IndexDispatch extends Dispatch
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
     * 初始化方法
     */
    public function init($app = null)
    {
        $this->app = $app ?? app();

        $uri = $this->request->pathinfo();

        $result = array_filter(explode('/', $uri));

        $this->plugin = config('app.default_plugin');

        if (!$this->plugin) {
            throw new HttpException(403, 'No default index on the website');
        }

        $pluginManager = $this->app->get(PluginContainerKeys::MANAGER);

        if (!$this->checkPluginStatus($this->plugin, $pluginManager)) {
            throw new HttpException(403, "Plugin '{$this->plugin}' is not enabled or does not exist");
        }

        $pluginManager->initServiceProvider($this->plugin);

        $controller = strip_tags($result[0] ?? $this->rule->config('default_controller'));
        if (strpos($controller, '.')) {
            $pos              = strrpos($controller, '.');
            $this->controller = substr($controller, 0, $pos) . '\\' . Str::studly(substr($controller, $pos + 1));
        } else {
            $this->controller = Str::studly($controller);
        }

        $this->actionName = strip_tags($result[1] ?? $this->rule->config('default_action'));

        $params = ['plugin' => $this->plugin];
        for ($i = 2; $i < count($result); $i += 2) {
            $key = $result[$i] ?? '';
            $value = $result[$i + 1] ?? '';
            
            if (!$this->isValidParamKey($key)) {
                continue;
            }
            
            $params[$key] = $this->normalizeValue($value);
        }

        $this->request
            ->setRoute($params)
            ->setController($this->controller)
            ->setAction($this->actionName);

        $this->request->view_scope = $this->plugin;
    }

    /**
     * 执行调度
     */
    public function exec()
    {
        $this->init();

        try {
            $instance = $this->controller($this->controller);
        } catch (ClassNotFoundException $e) {
            throw new HttpException(404, 'Plugin controller not exists: ' . $e->getClass());
        }

        $this->registerControllerMiddleware($instance);

        return $this->app->middleware->pipeline('controller')
            ->send($this->request)
            ->then(function () use ($instance) {
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
                        'Plugin method not exists: ' . get_class($instance) . '->' . $action . '()'
                    );
                }

                $data = $this->app->invokeReflectMethod($instance, $reflect, $vars);

                return $this->autoResponse($data);
            });
    }

    /**
     * 检查插件状态
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
            'defaultPlugin' => true,
            'config'        => $pluginMeta->getConfigSchema(),
        ]);

        return true;
    }

    /**
     * 实例化插件控制器
     *
     * 解析顺序（前台入口，匿名可达）：
     *   ① plugin\{ident}\controller\index\{Name}   ← 前台控制器约定（wisecms 等）
     *   ② plugin\{ident}\controller\{Name}         ← 兼容根级前台控制器（如 shortlink\Jump）
     *
     * 安全闸门：后台控制器基类（plugin\BaseController，带 AdminTrait + 权限中间件）
     * 与 API 基类（plugin\ApiController）一律拒绝。默认插件入口是**匿名**的，
     * 以前 `/{Controller}/{action}` 会直接命中 plugin\{ident}\controller\{Name}，
     * 导致 /category/index、/content/index、/category/tree 等后台页面与 JSON 接口
     * 免登录可读＼�。
     */
    public function controller(string $name)
    {
        $base = "plugin\\{$this->plugin}\\controller";

        $suffix = $this->rule->config('controller_suffix') ? 'Controller' : '';

        $candidates = [
            $base . '\\index\\' . $name . $suffix,
            $base . '\\index\\' . $name,
            $base . '\\' . $name . $suffix,
            $base . '\\' . $name,
        ];

        foreach (array_unique($candidates) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            if (!$this->isFrontController($class)) {
                continue;
            }

            return $this->app->make($class, [], true);
        }

        throw new ClassNotFoundException(
            'Plugin front controller not exists: ' . $base . '\\index\\' . $name,
            $base . '\\index\\' . $name
        );
    }

    /**
     * 是否允许由前台入口实例化。
     *
     * 后台控制器（继承 plugin\BaseController）和 API 控制器（继承 plugin\ApiController）
     * 只能走 PluginDispatch（/plugin/{ident}/...，带 PluginAdminAuth），永不由这里解析。
     */
    protected function isFrontController(string $class): bool
    {
        if (is_subclass_of($class, \plugin\BaseController::class)) {
            return false;
        }

        if (class_exists(\plugin\ApiController::class) && is_subclass_of($class, \plugin\ApiController::class)) {
            return false;
        }

        return true;
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