<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 安全设置
    'allowed_referer_hosts' => [
        
    ],
    // 是否验证请求来源
    'check_referer' => false,
    // 请求频率限制
    'request_rate_limit' => [
        'max_requests' => 60, // 最大请求数
        'time_window' => 60   // 时间窗口(秒)
    ],
    // XSS过滤设置
    'xss_filter' => true,
    // 密码加密配置
    'password_hash' => [
        'algo' => PASSWORD_DEFAULT,
        'options' => [
            'cost' => 12
        ]
    ]
];
