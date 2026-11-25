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
 * 插件调度器
 */
class PluginDispatch extends Dispatch
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
    public function init()
    {
        $this->app = app();

        $uri = $this->request->pathinfo();
        $result = array_filter(explode('/', $uri));

        $this->plugin = $result[0] ?? '';

        if (!$this->plugin) {
            throw new HttpException(403, 'Plugin name is required');
        }

        $pluginManager = $this->app->get(PluginContainerKeys::MANAGER);
        
        if (!$this->checkPluginStatus($this->plugin, $pluginManager)) {
            throw new HttpException(403, "Plugin '{$this->plugin}' is not enabled or does not exist");
        }

        $pluginManager->initServiceProvider($this->plugin);

        $controller = strip_tags($result[1] ?? $this->rule->config('default_controller'));
        if (strpos($controller, '.')) {
            $pos              = strrpos($controller, '.');
            $this->controller = substr($controller, 0, $pos) . '\\' . Str::studly(substr($controller, $pos + 1));
        } else {
            $this->controller = Str::studly($controller);
        }

        $this->actionName = strip_tags($result[2] ?? $this->rule->config('default_action'));

        $params = ['plugin' => $this->plugin];
        for ($i = 3; $i < count($result); $i += 2) {
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
            throw new HttpException(403, 'Plugin controller not exists: ' . $e->getClass());
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
                    throw new HttpException(403, 
                        'Plugin method not exists: ' . get_class($instance) . '->' . $action . '()'
                    );
                }

                $data = $this->app->invokeReflectMethod($instance, $reflect, $vars);

                return $this->autoResponse($data);
            });
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
     */
    public function controller(string $name)
    {
        $controllerLayer = 'controller';
        
        $class = "plugin\\{$this->plugin}\\{$controllerLayer}\\{$name}";
        
        if (!class_exists($class)) {
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