<?php
// +----------------------------------------------------------------------
// | 多语言设置
// +----------------------------------------------------------------------

return [
    // 默认语言
    'default_lang'        => env('DEFAULT_LANG', 'zh-cn'),
    // 自动侦测浏览器语言
    'auto_detect_browser' => true,
    // 允许的语言列表
    //
    // ⚠️ 必须是「语言标识的平铺列表」，不能写成 ['zh-cn' => '简体中文', ...]：
    //    think\middleware\LoadLangPack::detect() 用 in_array($langSet, $allow_lang_list)
    //    校验（LoadLangPack.php:90），关联数组会按其**值**匹配，导致切换全部失效。
    //    显示名请写在下方的 locale_labels。
    'allow_lang_list'     => ['zh-cn', 'en-us'],
    // 语言显示名（供语言切换器 / `php think lang:check` 展示）
    // 未声明的语言回退到语言包内的 __locale_name，再回退到语言标识本身。
    'locale_labels'       => [
        'zh-cn' => '简体中文',
        'en-us' => 'English',
    ],
    // 多语言自动侦测变量名
    'detect_var'          => 'lang',
    // 是否使用Cookie记录
    'use_cookie'          => true,
    // 多语言cookie变量
    'cookie_var'          => 'think_lang',
    // 多语言header变量
    'header_var'          => 'think-lang',
    // 扩展语言包
    //
    // 由 \app\common\service\LangPackService 在服务 register() 阶段自动填充：
    // 按约定扫描 packages / plugins / app 下的 lang/ 目录并登记为文件列表
    // （ThinkPHP 的 Lang::load() 只认文件、不认目录）。
    //
    // 此处手工填写的内容优先级高于自动发现结果 —— 宿主可用它覆盖包/插件文案。
    'extend_list'         => [],
    // Accept-Language转义为对应语言包名称
    'accept_language'     => [
        'zh-hans-cn' => 'zh-cn',
    ],
    // 是否支持语言分组
    'allow_group'         => false,
];
