<?php
// +----------------------------------------------------------------------
// | 模板设置
// +----------------------------------------------------------------------

return [
    // 模板引擎类型使用Think（自定义驱动：提前应用 tpl_replace_string，支持 __PLUGIN_VIEW__ 路径式引用）
    'type'          => \app\common\view\driver\Think::class,
    // 默认模板渲染规则 1 解析为小写+下划线 2 全部转换小写 3 保持操作方法
    'auto_rule'     => 1,
    // 模板目录名
    'view_dir_name' => 'view',
    // 模板后缀
    'view_suffix'   => 'html',
    // 模板文件名分隔符
    'view_depr'     => DIRECTORY_SEPARATOR,
    // 模板引擎普通标签开始标记
    'tpl_begin'     => '{',
    // 模板引擎普通标签结束标记
    'tpl_end'       => '}',
    // 标签库标签开始标记
    'taglib_begin'  => '{',
    // 标签库标签结束标记
    'taglib_end'    => '}',

    // 自定义模板函数文件
    'tpl_replace_string' => [
        '__CDN__' => '',
        '__STATIC__' => '/statics',
        '__CSS__' => '/statics/css',
        '__JS__' => '/statics/js',
        '__IMG__' => '/statics/images',
        '__PLUGINS__' => '/statics/plugins',
        // 插件共用视图根目录：模板中用 {extend name="__PLUGIN_VIEW__/base"} 等路径式引用
        '__PLUGIN_VIEW__' => root_path('extend') . 'plugin/view',
        // 包（wise-*）共用视图根目录：模板中用 {extend name="__WISE_VIEW__/base"} 等路径式引用
        // 与 __PLUGIN_VIEW__ 刻意分成两个目录、不合并：
        //   插件 = 第三方扩展，壳层需自持且精简（见 extend/plugin/view/base.html）
        //   包   = 框架一方能力，壳层须与后台应用一致（见 extend/wise/view/base.html）
        // 两者的演进节奏与依赖面不同，合并会让插件被包的升级牵着走。
        '__WISE_VIEW__' => root_path('extend') . 'wise/view',
    ]
];
