<?php
// 事件定义文件
return [
    'bind'      => [
        'AdminLogin'  => 'app\event\AdminLogin',
        'AdminLogout' => 'app\event\AdminLogout',
    ],

    'listen'    => [
        'AppInit'  => [],
        'HttpRun'  => [
            // TpConfigBridge 注入：将动态配置注入到 config('wise.*')
            // 挂在 HttpRun（仅 HTTP 请求触发）而非 AppInit：
            // Swoole 常驻下 runningInConsole() 恒为 true，原 AppInit 判断会失效导致永不注入。
            'app\listener\WiseConfigBridgeInit',
        ],
        'HttpEnd'  => [],
        'LogLevel' => [],
        'LogWrite' => [],
        // wise-config 配置变更事件监听
        \wise\config\event\ConfigSaved::class    => [
            'app\listener\WiseConfigChanged',
        ],
        \wise\config\event\ConfigReverted::class => [
            'app\listener\WiseConfigChanged',
        ],
        \wise\config\event\DictChangedEvent::class => [
            'app\listener\WiseConfigChanged',
        ],
    ],

    'subscribe' => [
        'app\subscribe\Admin',
    ],
];
