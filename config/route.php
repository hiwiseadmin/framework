<?php
// +----------------------------------------------------------------------
// | 路由设置
// +----------------------------------------------------------------------

return [
    // pathinfo分隔符
    'pathinfo_depr'         => '/',
    // 是否开启路由延迟解析
    'url_lazy_route'        => false,
    // 是否强制使用路由
    //
    // 【决策：保持 false，不全局开启】
    // 开启后所有未显式注册路由的请求一律 404。但本框架的菜单、面包屑、
    // 插件后台页大量使用「控制器/方法」默认解析路径（如 /admin/user/index），
    // 仅 admin 应用就有上千条此类地址，逐个补路由不现实；一旦开启，后台
    // 大面积 404。故不做全局强制。
    //
    // 该配置原本要防的是「未授权访问任意控制器方法」，补偿控制如下（更精准）：
    //   1. app\middleware\EntryVerify          —— 入口级校验
    //   2. wise\auth\middleware\RbacMiddleware —— 路由级 RBAC，whitelist 模式下非白名单一律 403
    //   3. #[AuthPermission] 属性              —— 控制器方法级权限点，缺权限即拒
    //   4. 敏感/破坏性动作已显式登记路由        —— 见 app/admin/route/*.php
    //
    // 若某个部署确实需要强制路由（例如纯 API 站点，路由已 100% 显式化），
    // 请在该部署的 .env 里置 URL_ROUTE_MUST=true，并在上线验证清单中
    // 逐条回归后台菜单入口，勿在框架默认值上改。
    'url_route_must'        => env('URL_ROUTE_MUST', false),
    // 是否区分大小写
    'url_case_sensitive'    => false,
    // 自动扫描子目录分组
    'route_auto_group'      => false,
    // 合并路由规则
    'route_rule_merge'      => false,
    // 路由是否完全匹配
    'route_complete_match'  => false,
    // 去除斜杠
    'remove_slash'          => false,
    // 默认的路由变量规则
    'default_route_pattern' => '[\w\.]+',
    // URL伪静态后缀
    'url_html_suffix'       => 'html',
    // 访问控制器层名称
    'controller_layer'      => 'controller',
    // 空控制器名
    'empty_controller'      => 'Error',
    // 是否使用控制器后缀
    'controller_suffix'     => false,
    // 默认模块名（开启自动多模块有效）
    'default_module'        => 'index',
    // 默认控制器名
    'default_controller'    => 'Index',
    // 默认操作名
    'default_action'        => 'index',
    // 操作方法后缀
    'action_suffix'         => '',
    // 非路由变量是否使用普通参数方式（用于URL生成）
    'url_common_param'      => true,
    // 操作方法的参数绑定方式 route get param
    'action_bind_param'     => 'get',
    // 请求缓存规则 true为自动规则
    'request_cache_key'     => false,
    // 请求缓存有效期
    'request_cache_expire'  => null,
    // 全局请求缓存排除规则
    'request_cache_except'  => [],
    // 请求缓存的Tag
    'request_cache_tag'     => '',
    // API版本header变量
    'api_version'           => 'Api-Version',
];
