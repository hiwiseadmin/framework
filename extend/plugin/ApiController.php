<?php
namespace plugin;

use think\App;
use think\Request;
use wise\api\ApiController as WiseApiController;
use wise\plugin\config\PluginConfigFacade;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;

/**
 * 插件 API 控制器基类
 *
 * 所有插件 **JSON API** 接口控制器（位于 controller/api/ 目录，经全局路由组
 * route/api.php → ApiDispatch catch-all 调度，URL 形如 /api/{plugin}/...）应继承此类。
 *
 * 继承链：
 *   plugin\ApiController
 *     -> wise\api\ApiController     （统一响应 / 参数校验 / 分页 / 字段过滤）
 *
 * 与 plugin\BaseController（后台）的区别：
 *   - 无视图渲染、无 JS 路径配置、无管理权限/RBAC 中间件
 *   - 纯 JSON 响应，适合对外 API
 *
 * 提供的增强能力（内联，不再依赖 PluginTrait）：
 *   - $this->request / $this->app 属性（兼容既有控制器写法）
 *   - $this->pluginIdent 插件标识（自动从命名空间解析）
 *   - getPluginConfig()     读取插件运行时配置
 *   - pluginUrl()           生成 /api/{plugin}/{controller}/{action} URL
 *
 * 继承自 wise\api\ApiController 的能力：
 *   - success() / error()              统一 JSON 响应
 *   - validateRequest()                参数校验
 *   - paginate()                       统一分页包装
 *   - allowFields() / allowFieldsDeep() 字段过滤
 *   - currentUser()                    当前认证用户
 */
abstract class ApiController extends WiseApiController
{
    /**
     * Request 实例（兼容既有控制器 $this->request 写法）
     *
     * @var Request
     */
    protected $request;

    /**
     * 应用实例
     *
     * @var App
     */
    protected $app;

    /**
     * 插件标识（如 "smartstock"），子类可显式覆盖，否则自动解析
     *
     * @var string
     */
    protected $pluginIdent = '';

    /**
     * 插件命名空间前缀（用于自动解析 pluginIdent）
     *
     * @var string
     */
    protected $pluginNamespacePrefix = 'plugin\\';

    /**
     * 构造函数
     *
     * 兼容 wise\api\ApiController 的无参构造，内部通过 app() 获取容器，
     * 并初始化 request / app / pluginIdent 属性。
     */
    public function __construct()
    {
        $this->app     = app();
        $this->request = $this->app->request;

        parent::__construct();

        $this->initialize();
    }

    /**
     * 初始化钩子（子类可覆盖）
     */
    protected function initialize(): void
    {
        if (empty($this->pluginIdent)) {
            $this->pluginIdent = $this->resolvePluginIdent();
        }
    }

    /**
     * 解析插件标识（从类命名空间自动提取）
     *
     * 例：plugin\smartstock\controller\api\Stock → "smartstock"
     *
     * @return string
     */
    protected function resolvePluginIdent(): string
    {
        $class   = static::class;
        $prefix  = $this->pluginNamespacePrefix;

        if (strpos($class, $prefix) === 0) {
            $parts = explode('\\', substr($class, strlen($prefix)));
            return $parts[0] ?? '';
        }

        return '';
    }

    /**
     * 获取插件配置（用户在插件中心保存的运行时配置）
     *
     * @param string $key     配置键（为空返回全部；支持点号分隔，如 'editor.toolbar'）
     * @param mixed  $default 默认值
     * @return mixed
     */
    protected function getPluginConfig(string $key = '', $default = null)
    {
        if (empty($this->pluginIdent)) {
            return $key === '' ? [] : $default;
        }

        $config = PluginConfigFacade::values($this->pluginIdent);

        if ($key === '') {
            return $config;
        }

        $keys = explode('.', $key);
        foreach ($keys as $k) {
            if (!is_array($config) || !array_key_exists($k, $config)) {
                return $default;
            }
            $config = $config[$k];
        }

        return $config;
    }

    /**
     * 生成插件 API 内部 URL
     *
     * 自动补全 /api/{plugin}/v{N}/{controller}/{action} 前缀（对应 ApiDispatch catch-all）。
     *
     * @param string $route  控制器/操作（如 "stock/search" 或 "search"）
     * @param array  $params URL 参数
     * @param bool   $full   是否返回完整域名 URL
     * @return string
     */
    protected function pluginUrl(string $route = '', array $params = [], bool $full = false): string
    {
        $plugin  = $this->pluginIdent ?: 'index';
        $version = $this->getApiVersion();

        if ($route === '') {
            $route = strtolower($this->getControllerShortName());
        }

        $url = rtrim("/api/{$plugin}/{$version}/" . trim($route, '/'), '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        if ($full) {
            $url = $this->request->domain() . $url;
        }

        return $url;
    }

    /**
     * 获取当前请求的插件 API 版本（未携带时回退插件默认版本）。
     *
     * @return string
     */
    protected function getApiVersion(): string
    {
        $version = $this->getRequest()->api_version ?? null;
        if (is_string($version) && $version !== '') {
            return $version;
        }

        return $this->getPluginApiDefaultVersion();
    }

    /**
     * 获取插件声明的默认 API 版本。
     *
     * @return string
     */
    protected function getPluginApiDefaultVersion(): string
    {
        if (empty($this->pluginIdent)) {
            return 'v1';
        }

        try {
            $manager = app()->get(PluginContainerKeys::MANAGER);
            if ($manager instanceof PluginManager) {
                $policy = $manager->getPlugin($this->pluginIdent)->getApiVersionPolicy();
                return (string) ($policy['default_version'] ?? 'v1');
            }
        } catch (\Throwable $e) {
        }

        return 'v1';
    }

    /**
     * 获取控制器短名称（小写+下划线）
     *
     * 例：StockController → "stock"，UserProfileController → "user_profile"
     *
     * @return string
     */
    protected function getControllerShortName(): string
    {
        $pos       = strrpos(static::class, '\\');
        $className = $pos !== false ? substr(static::class, $pos + 1) : static::class;
        $className = str_replace('Controller', '', $className);

        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $className);
        return strtolower($result ?? $className);
    }
}
