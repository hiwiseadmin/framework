<?php
// 应用公共文件

use think\facade\Config;
use think\facade\Cookie;
use wise\support\tools\Random;
use think\facade\Request;
use think\facade\Route;

// +----------------------------------------------------------------------
// | CSRF令牌相关函数
// +----------------------------------------------------------------------

if (!function_exists('csrf_token')) {
    /**
     * 获取CSRF Token值
     * 如果Cookie中不存在，则生成一个新的Token并设置Cookie
     */
    function csrf_token(): string
    {
        $cookieKey = Config::get('csrf.cookie_key', 'csrf_token');
        $token = Cookie::get($cookieKey);
        if (!$token) {
            $token = sha1(uniqid(mt_rand(), true) . Random::alpha(32));
            // 设置一个长期有效的Cookie（例如1天）
            Cookie::set($cookieKey, $token, Config::get('csrf.expire', 3600), ['httponly' => true]);
        }
        return $token;
    }
}

if (!function_exists('csrf_field')) {
    /**
     * 生成包含CSRF Token隐藏域的表单HTML
     */
    function csrf_field(): string
    {
        $paramKey = Config::get('csrf.param_key', '_token');
        return sprintf('<input type="hidden" name="%s" value="%s">%s', $paramKey, csrf_token(), PHP_EOL);
    }
}

if (!function_exists('csrf_meta')) {
    /**
     * 生成包含CSRF Token的Meta标签HTML，用于AJAX
     */
    function csrf_meta(): string
    {
        return sprintf('<meta name="csrf-token" content="%s">%s', csrf_token(), PHP_EOL);
    }
}

if (!function_exists('plugin_path')) {
    /**
     * 获取插件根目录路径
     */
    function plugin_path(): string
    {
        return root_path() . 'plugins/';
    }
}

// +----------------------------------------------------------------------
// | 主题相关函数
// +----------------------------------------------------------------------

if (!function_exists('theme_bootstrap_script')) {
    /**
     * 生成主题防闪烁（FOUC）引导脚本
     *
     * 必须在 <head> 内、所有样式表之前同步输出。
     * 脚本在首帧渲染前确定并写入 <html> 的主题属性，
     * 避免「先按默认主题绘制、再切换」造成的白屏闪烁。
     *
     * 取值优先级：localStorage（本浏览器最近选择）> 服务端注入（DB/网站配置）> 默认值
     *
     * 供前端读取的服务端上下文写入 window.__WS_THEME__，
     * 随后由 wise-theme.js 接管后续切换与持久化。
     *
     * @param string $nonce CSP nonce，空字符串表示不启用 CSP
     * @return string
     */
    function theme_bootstrap_script(string $nonce = ''): string
    {
        $theme = \think\facade\View::get('wstheme');
        if (!is_array($theme)) {
            $theme = \app\common\service\ThemeService::toBootstrapContext();
        }

        $json = json_encode([
            'mode'     => (string) ($theme['mode'] ?? 'dark'),
            'scheme'   => (string) ($theme['scheme'] ?? 'blue'),
            'sidebar'  => (string) ($theme['sidebar'] ?? 'auto'),
            'editable' => !empty($theme['editable']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        return <<<HTML
<script{$nonceAttr}>window.__WS_THEME__={$json};</script>
<script{$nonceAttr}>
(function(){
  var MODES=['light','dark','auto'],SCHEMES=['blue','green','red','purple'],SIDEBARS=['auto','dark','light'];
  var D={mode:'dark',scheme:'blue',sidebar:'auto'},K='wise.theme';
  function pick(v,a,f){return a.indexOf(v)>-1?v:f;}
  function norm(t){t=(t&&typeof t==='object')?t:{};return{
    mode:pick(t.mode,MODES,D.mode),
    scheme:pick(t.scheme,SCHEMES,D.scheme),
    sidebar:pick(t.sidebar,SIDEBARS,D.sidebar)};}
  var server=norm(window.__WS_THEME__),local=null;
  try{local=JSON.parse(localStorage.getItem(K)||'null');}catch(e){local=null;}
  var t=local?norm(local):server;
  var dark=false;
  try{dark=window.matchMedia('(prefers-color-scheme: dark)').matches;}catch(e){}
  var mode=(t.mode==='auto')?(dark?'dark':'light'):t.mode;
  var side=(t.sidebar==='auto')?mode:t.sidebar;
  var r=document.documentElement;
  r.setAttribute('data-bs-theme',mode);
  r.setAttribute('data-color-scheme',t.scheme);
  r.setAttribute('data-ws-sidebar',side);
  r.style.colorScheme=mode;
  window.__WS_THEME__={mode:t.mode,scheme:t.scheme,sidebar:t.sidebar};
})();
</script>
HTML;
    }
}

// +----------------------------------------------------------------------
// | 模板自定义函数文件
// +----------------------------------------------------------------------

if (!function_exists('plugin_url')) {
    /**
     * 生成插件URL
     *
     * 产出形态与后台菜单 path 一致：`/plugin/{ident}/{controller}/{action}`。
     *
     * 两个必须遵守的约束（否则链接 404 / 403）：
     *   1. 必须以「/」开头：否则 Route\Url::parseUrl() 会把路径按
     *      “controller/action” 反向解析，丢掉前面的 plugin/{ident} 前缀；
     *   2. 默认不附加伪静态后缀（$suffix = false）：插件后台请求由
     *      {@see \app\plugin\dispatch\PluginDispatch} 直接解析 pathinfo 取 action，
     *      而 pathinfo 的后缀不会被剥离 —— 带 `.html` 时 action 会变成 `index.html`，
     *      控制器方法不存在 → 403。
     *
     * @param string      $url    插件路由地址，如 'wisecms/category/index'
     * @param array       $vars   变量
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return UrlBuild
     */
    function plugin_url(string $url = '', array $vars = [], $suffix = false, $domain = false)
    {
        $url = '/plugin/' . ltrim($url, '/');

        return Route::buildUrl($url, $vars)->suffix($suffix)->domain($domain);
    }
}

if (!function_exists('fetch_display')) {
    /**
     * 动态包含模板内容
     * @param string $content 模板内容
     * @param array $data 传递给模板内容的数据
     * @return string
     */
    function fetch_display($content, $data = [])
    {
        try {
            // 使用ThinkPHP的视图引擎渲染
            return \think\facade\View::display($content, $data);
        } catch (\Exception $e) {
            return '<div class="alert alert-danger">模板内容包含错误: ' . $e->getMessage() . '</div>';
        }
    }
}


if (!function_exists('fetch_url')) {
    /**
     * 动态包含模板文件
     * @param string $template 模板文件路径
     * @param array $data 传递给模板的数据
     * @return string
     */
    function fetch_url($template, $data = [])
    {
        try {
            $viewPath = root_path();
            $modulename = app('http')->getName();
            $path = request()->pathinfo();

            // 处理插件路径
            if (strpos($path, 'plugin/') === 0) {
                $viewPath .= "plugins/{$modulename}/view/";
            } else {
                $viewPath .= "app/{$modulename}/view/";
            }

            // 构建完整模板路径
            \think\facade\View::config(['view_path' => $viewPath]);

            // 使用ThinkPHP的视图引擎渲染
            return \think\facade\View::assign($data)->fetch($template);
        } catch (\Exception $e) {
            return '<div class="alert alert-danger">模板包含错误: ' . $e->getMessage() . '</div>';
        }
    }
}