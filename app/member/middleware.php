<?php
// 会员应用中间件定义文件（应用入口层，实现全部位于 wiseadmin/wise-member 包内）
//
// 应用级只挂「不拦人」的共性中间件：
//  - wise\member\middleware\MemberContext（软认证）：解析当前会员并注入
//    $request->member，游客放行。统一挂载后，会员应用内所有路由（含各包经
//    ExtPoints::ROUTE_MEMBER 投递的 /member/<feature> 功能路由）都自动获得会员上下文。
//
// 「强制登录」不下沉到应用级（会拦掉登录/注册/找回等公开页），由各路由分组显式
// 声明：包内 config('wise-member.web.groups.web.middleware') 已挂
// wise\member\middleware\RequireMemberAuth。
return [
    // 多语言侦测（应用级，必须最先执行）
    //   think-multi-app 切换应用时会调用 App::loadLangPack() 把当前语言重置为
    //   default_lang（见 MultiApp::loadApp() 末行），而全局的 LoadLangPack 跑在
    //   应用切换之前，其侦测结果会被这一步覆盖。故应用级需要再侦测一次。
    \think\middleware\LoadLangPack::class,

    // 会员上下文（软认证）：实现位于 wiseadmin/wise-member 包。
    // 该包非框架必需依赖 —— 未安装时中间件类不存在，若直接写死类名，
    // 本应用的**每次请求**都会因中间件解析失败而 500。
    // 故按运行时存在性守卫：装了即挂载，未装则整条链自然降级（会员路由也随包一起消失）。
    ...(class_exists(\wise\member\middleware\MemberContext::class)
        ? [\wise\member\middleware\MemberContext::class]
        : []),
];
