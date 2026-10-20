<?php

use app\AppService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
return [
    AppService::class,
    // 语言包注册：把包 / 插件 / 宿主语言包接入 lang.extend_list
    // ⚠️ 必须在 register() 阶段完成 —— App::loadLangPack() 早于服务 boot()
    //    就会以默认语言调用一次 switchLangSet()，此后该 range 永不重载。
    \app\common\service\LangPackService::class,
    // 扩展自动加载服务类
    \app\service\ExtendLoaderService::class,
    \app\admin\Service::class,
    // 会员能力已全部由 wiseadmin/wise-member 包提供（含 ServiceProvider 自动发现：
    // 配置合并 / 事件绑定 / 迁移登记 / 路由注册）。app/member 只保留应用入口
    // （route/packages.php 落地 ROUTE_MEMBER、middleware.php 挂软认证），不再注册应用级 Service。
    \app\install\Service::class,

];
