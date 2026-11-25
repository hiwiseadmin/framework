<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用地址
    'app_host'         => env('APP_HOST', 'http://192.168.31.151/'),
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => ['common'],

    // 异常页面的模板文件
    'exception_tmpl'   => base_path() . 'exception/exception.tpl',
    // 'exception_tmpl'   => base_path() . app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,
    // 调试模式
    'debug'            => env('APP_DEBUG', false),
    // 网站顶级域名默认插件应用
    // 前台任意非 /admin、/api 路径都交给该插件承载（官网场景 → wisecms）
    'default_plugin'   => env('APP_DEFAULT_PLUGIN', 'demo'),
    // 超级管理员ID列表
    'super_admin_ids'  => [1],
];