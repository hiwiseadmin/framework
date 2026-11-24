<?php
return [
    'bind'      => [
        'AdminLogin'  => 'app\event\AdminLogin',
        'AdminLogout' => 'app\event\AdminLogout',
    ],

    'listen'    => [
        'AppInit'  => [],
        'HttpRun'  => [
            'app\listener\WiseConfigBridgeInit',
        ],
        'HttpEnd'  => [],
        'LogLevel' => [],
        'LogWrite' => [],
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
