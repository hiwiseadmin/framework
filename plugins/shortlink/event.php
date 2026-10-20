<?php
/**
 * 短链接插件事件注册
 */
return [
    'listen' => [
        'shortlink.visit' => [
            \plugin\shortlink\event\listener\VisitLogListener::class,
        ],
    ],
];
