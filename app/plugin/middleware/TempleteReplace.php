<?php
namespace app\plugin\middleware;

/**
 * 插件视图替换中间件
 * 替换动态变量
 */
class TempleteReplace
{
    public function handle($request, \Closure $next)
    {
        // 如果是GET请求，HTML响应，替换模板变量
        if($request->method() == 'GET') {
            $response = $next($request);
            if($response instanceof \think\Response && strpos($response->getHeader('Content-Type'), 'text/html') !== false) {
                $content = $response->getContent();
                
                // 动态构建替换数组
                $pluginName = $request->route('plugin');
                $replace = [
                    '__PLUGIN__'     => '/statics/plugins/' . $pluginName,
                    '__PLUGIN_IMG__' => '/statics/plugins/' . $pluginName . '/images',
                    '__PLUGIN_CSS__' => '/statics/plugins/' . $pluginName . '/css',
                    '__PLUGIN_JS__'  => '/statics/plugins/' . $pluginName . '/js',
                ];
                
                // 替换模板变量
                $content = str_replace(array_keys($replace), array_values($replace), $content);
                $response->content($content);
            }
            return $response;
        }
        
        return $next($request);
    }
}