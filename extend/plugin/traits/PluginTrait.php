<?php
namespace plugin\traits;

use think\facade\View;

/**
 * 插件控制器 Trait
 *
 * 提供插件控制器通用能力：插件标识解析、视图路径、模板数据注入、配置访问。
 *
 * 使用方式：被插件 BaseController 自动引入。
 *
 * 插件 BaseController 继承链:
 *   app\BaseController → app\common\controller\BaseController → plugin\BaseController
 */
trait PluginTrait
{
    /**
     * 插件标识（如 "cms", "shop"）
     * 子类可显式设置，否则自动从命名空间解析
     * @var string
     */
    protected $pluginIdent = '';

    /**
     * 插件命名空间前缀（用于自动解析 pluginIdent）
     * @var string
     */
    protected $pluginNamespacePrefix = 'plugin\\';

    /**
     * 初始化插件上下文
     */
    protected function pluginInitialize(): void
    {
        if (empty($this->pluginIdent)) {
            $this->pluginIdent = $this->resolvePluginIdent();
        }

        // 注入插件通用模板变量
        $this->assignPluginData();
    }

    /**
     * 解析插件标识（从类命名空间自动提取）
     *
     * 例如 plugin\cms\controller\Article → "cms"
     * @return string
     */
    protected function resolvePluginIdent(): string
    {
        $class = static::class;
        $prefix = $this->pluginNamespacePrefix;

        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $parts = explode('\\', $relative);
            return $parts[0] ?? '';
        }

        return '';
    }

    /**
     * 注入插件通用模板变量
     */
    protected function assignPluginData(): void
    {
        View::assign([
            'plugin_ident' => $this->pluginIdent,
            'plugin_url'   => $this->getPluginUrl(),
            'plugin_asset' => $this->getPluginAssetUrl(),
        ]);
    }

    /**
     * 获取插件视图的「显式 scope」模板名
     *
     * 用于**跨插件 / 跨包**引用时明确指定归属，例如：
     * - pluginView('article/list') → "cms@article/list"
     *
     * 插件内引用自身视图无需本方法 —— 空参数 `fetch()` 已按当前上下文
     * 自动落位（见 bindViewScope()）。
     *
     * @param string $template 模板相对路径
     * @return string
     */
    protected function pluginView(string $template): string
    {
        return "{$this->pluginIdent}@{$template}";
    }

    /**
     * 获取插件访问 URL 根路径
     * 例如: /plugin/cms
     * @return string
     */
    protected function getPluginUrl(): string
    {
        return (string) url("plugin/{$this->pluginIdent}/index/index", [], false);
    }

    /**
     * 获取插件静态资源 URL 根路径
     * 例如: /static/plugin/cms
     * @return string
     */
    protected function getPluginAssetUrl(): string
    {
        return "/statics/plugins/{$this->pluginIdent}";
    }

    /**
     * 获取插件配置（用户在插件中心保存的运行时配置）
     *
     * 数据来源：manifest.json 的 config 声明（默认值）⊕ plugin_config 表（用户值）。
     *
     * 修复前读的是 Config::get("plugin_{$ident}") —— 该 Config domain 从未被
     * 任何代码写入过，因此本方法恒返回 [] / $default，形同虚设。
     *
     * @param string $key  配置键（为空时返回全部；支持点号分隔，如 'editor.toolbar'）
     * @param mixed  $default 默认值
     * @return mixed
     */
    protected function getPluginConfig(string $key = '', $default = null)
    {
        if (empty($this->pluginIdent)) {
            return $key === '' ? [] : $default;
        }

        $config = \wise\plugin\config\PluginConfigFacade::values($this->pluginIdent);

        if ($key === '') {
            return $config;
        }

        // 支持点号分隔的多级键
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
     * 声明当前请求的视图 scope（当前插件）。
     *
     * 写入 Request 的「中间件传递数据」（`think\Request::__set` → `$this->middleware`）。
     * 该载体是**请求级**的，不随进程常驻，Swoole 协程下天然隔离。
     *
     * 视图驱动读到该键后，**空参数 `fetch()`** 即可自动落到本插件视图，
     * 无需任何配置、也无需改写调用方：
     *
     *     fetch()              → plugins/{ident}/view/{controller}/{action}.html
     *     fetch('admin/page')  → plugins/{ident}/view/admin/page.html
     *
     * 相比旧的 `View::config(['view_path' => ...])` 方案：
     *   1. 不写任何全局配置（旧方案污染 Config，且「已被设置过就不再设置」）；
     *   2. 无顺序依赖 —— 每个请求由控制器重新声明；
     *   3. 应用层 `app/{app}/view/` 仍然优先，宿主覆盖层天然可用。
     */
    protected function bindViewScope(): void
    {
        if ($this->pluginIdent !== '') {
            $this->request->view_scope = $this->pluginIdent;
        }
    }

    /**
     * 获取控制器短名称（小写+下划线）
     *
     * 例: ArticleController → "article"，UserProfileController → "user_profile"
     */
    protected function getControllerShortName(): string
    {
        $pos = strrpos(static::class, '\\');
        $className = $pos !== false ? substr(static::class, $pos + 1) : static::class;
        $className = str_replace('Controller', '', $className);

        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $className);
        return strtolower($result ?? $className);
    }
}
