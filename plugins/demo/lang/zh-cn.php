<?php
/**
 * demo 插件 · 简体中文语言包
 *
 * 目录约定：plugins/{ident}/lang/{locale}.php
 * 由 \app\common\service\LangPackService 自动登记进 lang.extend_list，
 * 无需在插件 BootServiceProvider 或 config 中做任何注册。
 *
 * 约定：
 *  - 文件名即语言标识（zh-cn），须出现在 config/lang.php 的 allow_lang_list 中；
 *  - 键统一小写（ThinkPHP 的 Lang::load() 会对键做 array_change_key_case）；
 *  - 插件自有文案建议用 `{插件名}.` 前缀，避免与其他包/宿主文案撞键；
 *  - __locale_name 为保留键，供语言切换器展示，不参与覆盖度审计。
 */
return [
    '__locale_name' => '简体中文',

    'demo.title'      => 'demo 插件',
    'demo.intro'      => '插件开发模板，在此基础上开始构建你的功能。',
    'demo.version'    => '版本',
    'demo.author'     => '作者',
    'demo.created_at' => '创建时间',
    'demo.locale'     => '当前语言',
];
