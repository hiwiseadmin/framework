<?php
namespace app\plugin\base;

use app\common\controller\BaseController as CommonBaseController;
use think\facade\View;
use app\plugin\middleware\TempleteReplace;

/**
 * 插件后端控制器基类
 */
class BaseController extends CommonBaseController
{
    /**
     * 中间件
     * @var array
     */
    protected $middleware = [
        TempleteReplace::class,
    ];
    
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 插件名称
     * @var string
     */
    protected $pluginName;

    /**
     * 初始化操作
     */
    protected function _initialize()
    {
        // 自动检测插件名称
        $this->detectPluginName();

        // 修正 JS 控制器路径（ESM 模式，相对于 wise-bootstrap.js）
        // 与 admin 侧 AdminTrait 同口径：页面控制器 JS 是可选增强，
        // 仅当发布后的文件真实存在时才下发 `js`，否则 wise-bootstrap 会对不存在的文件
        // 发起必然 403 的动态 import，并在控制台留下「[Wise:core] 控制器加载失败」报错。
        if ($this->pluginName) {
            $wsconfig = View::get('wsconfig') ?: [];
            $controllerJs = $this->getControllerShortName();

            $pluginJsFile = public_path('statics') . DIRECTORY_SEPARATOR . 'plugins'
                . DIRECTORY_SEPARATOR . $this->pluginName . DIRECTORY_SEPARATOR . 'js'
                . DIRECTORY_SEPARATOR . $controllerJs . '.js';

            $wsconfig['js'] = is_file($pluginJsFile)
                ? '../plugins/' . $this->pluginName . '/js/' . $controllerJs
                : '';
            View::assign('wsconfig', $wsconfig);
        }

        // 设置插件视图路径
        if ($this->pluginName) {
            $viewPath = plugin_path() . "{$this->pluginName}/view/";
            if (is_dir($viewPath) && !View::getConfig('view_path')) {
                View::config(['view_path' => $viewPath]);
            }
            $this->initPlugin();
        }
    }

    /**
     * 初始化插件，在子类中实现
     */
    protected function initPlugin()
    {

    }
    /**
     * 检测插件名称
     * @return void
     */
    protected function detectPluginName()
    {
        $class = get_class($this);
        
        // 从命名空间解析插件名称
        if (preg_match('/^plugin\\\\([a-zA-Z0-9_]+)\\\\controller/', $class, $matches)) {
            $this->pluginName = $matches[1];
        } elseif ($this->request->param('plugin')) {
            $this->pluginName = $this->request->param('plugin');
        } elseif ($this->request->plugin) {
            $this->pluginName = $this->request->plugin;
        }

        View::assign([
            'pluginName' => $this->pluginName,
            'pluginBaseView' => root_path('extend') . 'plugin/view/base.html',
        ]);
    }

    /**
     * 获取插件配置
     *
     * 读取的是「用户在插件中心保存的运行时配置」：
     *   manifest.json 的 config 声明（提供默认值）⊕ plugin_config 表（用户值）。
     *
     * 修复前读的是 \think\facade\Config::get($pluginName)，那是插件的静态配置域
     * （config/*.php 经 loadConfig 装载），用户保存的值根本不在其中，
     * 导致插件中心改了配置、业务侧却永远读到默认值。
     *
     * 该运行时配置同时会被注入 Config domain（见 WiseBootServiceProvider::injectRuntimeConfig），
     * 因此 config('ident.key') 也能读到用户值，两种写法等价。
     *
     * @param string|null $key 配置键名（可选，支持点号分隔的多级键）
     * @param mixed $default 默认值（可选）
     * @return mixed 配置值或配置数组
     */
    protected function getPluginConfig($key = null, $default = null)
    {
        if (!$this->pluginName) {
            return $default;
        }

        $config = \wise\plugin\config\PluginConfigFacade::values($this->pluginName);

        if ($key === null) {
            return $config;
        }

        // 支持点号分隔的多级键
        if (strpos((string) $key, '.') === false) {
            return $config[$key] ?? $default;
        }

        $value = $config;
        foreach (explode('.', (string) $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 生成插件内部URL
     */
    protected function pluginUrl($route = '', $params = [], $full = false)
    {
        if (empty($route)) {
            $route = $this->request->action();
        }
        
        // 如果路由以 / 开头，使用绝对路径
        if (strpos($route, '/') === 0) {
            $url = $route;
        } else {
            // 解析控制器和方法
            $parts = explode('/', $route);
            if (count($parts) === 1) {
                $controller = $this->getControllerShortName();
                $action = $parts[0];
            } else {
                $controller = $parts[0];
                $action = $parts[1] ?? 'index';
            }
            
            $url = "/plugin/{$this->pluginName}/{$controller}/{$action}";
        }
        
        // 添加参数
        if (!empty($params)) {
            $query = http_build_query($params);
            $url .= '?' . $query;
        }
        
        // 完整URL
        if ($full) {
            $url = $this->request->domain() . $url;
        }
        
        return $url;
    }
    
    /**
     * 获取控制器短名称
     * @return string 控制器短名称（小写+下划线）
     */
    protected function getControllerShortName()
    {
        $className = get_class($this);
        $className = substr($className, strrpos($className, '\\') + 1);
        $className = str_replace('Controller', '', $className);
        
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
    }
    
    /**
     * 重定向到插件内部URL
     * @param string $route 路由规则（可选）
     * @param array $params URL参数（可选）
     * @param int $code HTTP状态码（默认302）
     * @return \think\Response 重定向响应
     */
    protected function pluginRedirect($route = '', $params = [], $code = 302)
    {
        $url = $this->pluginUrl($route, $params);
        return redirect($url, $code);
    }
    
    /**
     * 注册插件内部路由（供插件在初始化时使用）
     * @param string $rule 路由规则（不包含插件前缀）
     * @param string $route 目标路由（控制器@操作）
     * @param string $method 允许的HTTP方法（默认'*'）
     * @return \think\Route 路由实例
     */
    protected function registerPluginRoute($rule, $route, $method = '*')
    {
        $prefix = "plugin/{$this->pluginName}";
        
        if ($rule !== '/') {
            $fullRule = $prefix . $rule;
        } else {
            $fullRule = $prefix;
        }
        
        // 注册路由
        $router = Route::rule($fullRule, $route, $method);
        
        return $router;
    }
    
    /**
     * 渲染插件视图
     * @param string $template 视图模板（不包含插件前缀）
     * @param array $vars 视图变量（可选）
     * @return string 渲染后的HTML内容
     */
    protected function pluginView($template = '', $vars = [])
    {
        // 自动添加插件视图路径
        if ($this->pluginName) {
            $viewPath = plugin_path() . "{$this->pluginName}/view/";
            if (is_dir($viewPath) && !View::getConfig('view_path')) {
                View::config(['view_path' => $viewPath]);
            }
        }
        
        return View::fetch($template, $vars);
    }

    /**
     * 调用插件函数
     * @param string $function 插件函数名
     * @param mixed ...$args 函数参数
     * @return mixed 函数返回值
     */
    protected function callPluginFunction($function, ...$args)
    {
        if (!$this->pluginName) {
            throw new \Exception('无法确定插件名称');
        }
        
        $funcName = $this->pluginName . '_' . $function;
        
        if (function_exists($funcName)) {
            return call_user_func_array($funcName, $args);
        }
        
        throw new \Exception("插件函数 {$funcName} 不存在");
    }
}