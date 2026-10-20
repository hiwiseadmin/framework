<?php
/**
 * WiseCMS 视图配置（插件级）
 *
 * 本文件**不直接生效**，而是由 \wise\plugin\WiseBootServiceProvider::mergeGlobalConfig()
 * 按 BootServiceProvider::$globalConfigMap 声明的白名单（'view'）合并进全局 view 域。
 *
 * 这样框架 src/config/view.php 无需出现任何 wisecms 字样，插件与框架解耦；
 * 同时仍走 think-template 的原生 taglib_pre_load 机制，无需任何引擎层改造。
 */
return [
    // 预加载 WiseCMS 标签库：模板中可直接使用 {cms:arclist} / {cms:channel} 等标签，
    // 无需 {taglib} 导入。
    //
    // think-template 支持完整类名注册，模板前缀由类名末段推导（Cms → cms），
    // 故对外语法固定为 {cms:xxx}。
    //
    // 该键声明为「并集键」：多插件共存时取逗号并集、去重，不会互相覆盖。
    // 详见 docs/wisecms-template-taglib-design.md §7
    'taglib_pre_load' => 'plugin\\wisecms\\taglib\\Cms',
];
