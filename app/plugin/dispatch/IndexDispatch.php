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

        // 解析调度参数
        $uri = $this->request->pathinfo();

        // 处理插件路由
        $result = array_filter(explode('/', $uri));

        // 获取默认插件名
        $this->plugin = config('app.default_plugin');

        if (!$this->plugin) {
            throw new HttpException(403, 'No default index on the website');
        }

        // 获取插件管理器（插件扫描和懒加载绑定已由全局 PluginLoader 中间件完成）
        $pluginManager = $this->app->get(PluginContainerKeys::MANAGER);

        // 检查插件状态
        if (!$this->checkPluginStatus($this->plugin, $pluginManager)) {
            throw new HttpException(403, "Plugin '{$this->plugin}' is not enabled or does not exist");
        }

        // 加载插件
        $pluginManager->initServiceProvider($this->plugin);

        // 获取控制器名 (第二个参数，默认为 'Index')
        $controller = strip_tags($result[0] ?? $this->rule->config('default_controller'));
        if (strpos($controller, '.')) {
            $pos              = strrpos($controller, '.');
            $this->controller = substr($controller, 0, $pos) . '\\' . Str::studly(substr($controller, $pos + 1));
        } else {
            $this->controller = Str::studly($controller);
        }

        // 获取操作名 (第三个参数，默认为 'index')
        $this->actionName = strip_tags($result[1] ?? $this->rule->config('default_action'));

        // 获取pathinfo中其他参数
        $params = ['plugin' => $this->plugin];
        for ($i = 2; $i < count($result); $i += 2) {
            $key = $result[$i] ?? '';
            $value = $result[$i + 1] ?? '';
            
            // 键名过滤
            if (!$this->isValidParamKey($key)) {
                continue;
            }
            
            $params[$key] = $this->normalizeValue($value);
        }

        // 设置当前请求的插件、控制器、操作
        $this->request
            ->setRoute($params)
            ->setController($this->controller)
            ->setAction($this->actionName);

        // 声明视图 scope（当前插件）——供 ViewScope 解析「空参数 fetch()」用。
        // 详见 PluginDispatch 中的同类说明：请求命中的是应用层兜底规则，
        // 插件路由分组上挂的中间件不会执行，必须在此注入。
        $this->request->view_scope = $this->plugin;
    }

    /**
     * 执行调度
     */
    public function exec()
    {
        $this->init();

        try {
            // 实例化插件控制器
            $instance = $this->controller($this->controller);
        } catch (ClassNotFoundException $e) {
            throw new HttpException(403, 'Plugin controller not exists: ' . $e->getClass());
        }

        // 注册控制器中间件
        $this->registerControllerMiddleware($instance);

        return $this->app->middleware->pipeline('controller')
            ->send($this->request)
            ->then(function () use ($instance) {
                // 获取当前操作名
                $suffix = $this->rule->config('action_suffix');
                $action = $this->actionName . $suffix;

                if (is_callable([$instance, $action])) {
                    $vars = $this->request->param();
                    
                    try {
                        $reflect = new \ReflectionMethod($instance, $action);
                        // 严格获取当前操作方法名
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
                    // 操作不存在
                    throw new HttpException(403, 
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

        // 保存插件配置到请求中
        $this->request->plugin = array_merge($pluginMeta->toArray(), [
            'defaultPlugin' => true,
            'config'        => $pluginMeta->getConfigSchema(),
        ]);

        return true;
    }

    /**
     * 实例化插件控制器
     */
    public function controller(string $name)
    {
        // 插件控制器层
        $controllerLayer = 'controller';
        
        // 构建完整的类名
        $class = "plugin\\{$this->plugin}\\{$controllerLayer}\\{$name}";
        
        if (!class_exists($class)) {
            // 尝试使用控制器后缀
            $suffix = $this->rule->config('controller_suffix') ? 'Controller' : '';
            $class = "plugin\\{$this->plugin}\\{$controllerLayer}\\{$name}{$suffix}";
        }

        if (class_exists($class)) {
            return $this->app->make($class, [], true);
        }

        throw new ClassNotFoundException('Plugin class not exists: ' . $class, $class);
    }

    /**
     * 使用反射机制注册控制器中间件
     */
    protected function registerControllerMiddleware($controller): void
    {
        $class = new \ReflectionClass($controller);

        if ($class->hasProperty('middleware')) {
            $reflectionProperty = $class->getProperty('middleware');
            // PHP 8.1+ 兼容性处理
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
        // ThinkPHP路由参数名规则
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