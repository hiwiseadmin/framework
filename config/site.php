<?php
// +----------------------------------------------------------------------
// | 网站配置
// +----------------------------------------------------------------------

return [
    // 应用地址
    'name' => '智慧网站',                // 网站名称
    'icp' => '',                        // 备案号
    'host' => config('app.app_host'),   // 基础URL
    'cdnurl' => '',                     // CDN加速域名
    'version' => app_version(),         // 版本号（真源 version.json，见 app\common\library\Version）
    'timezone' => 'Asia/Shanghai',      // 时区
    'forbiddenip' => '',                // 禁止访问的IP，多个IP用逗号分隔
    'languages' => [
        'framework' => 'zh-cn',         // 框架语言
        'frontend' => 'zh-cn',          // 前端语言
    ],
];
