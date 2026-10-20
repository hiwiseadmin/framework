<?php

// ⚠️ 本文件由 `php think vendor:publish` 从 topthink/think-swoole 发布而来（Host 配置，可自行修改）。
//
// 【为什么不直接用 swoole_cpu_num()】
// 1. 框架启动时会加载 config/ 下**全部** *.php 文件（think\App::load() 的 glob 循环），本文件在生产
//    环境也会被解析。
// 2. 生产按 `composer install --no-dev` 部署时，think-swoole 与其依赖 swoole/ide-helper 均**不存在**，
//    `swoole_cpu_num()` 未定义 → fatal error，**全站 500**。
// 3. 故对 swoole_* 函数统一加 function_exists 守卫：无扩展时退化为静态值，配置本身仍然可读。
//    （think-swoole 官方模板默认带扩展运行，不会遇到此问题；本框架要求 Swoole 为「可选依赖」，必须加。）
$cpuNum = function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4;

return [
    'http'       => [
        'enable'     => true,
        'host'       => '0.0.0.0',
        'port'       => 8080,
        'worker_num' => $cpuNum,
        'options'    => [],
    ],
    'websocket'  => [
        'enable'        => false,
        'route'         => true,
        'handler'       => \think\swoole\websocket\Handler::class,
        'ping_interval' => 25000,
        'ping_timeout'  => 60000,
        'room'          => [
            'type'  => 'table',
            'table' => [
                'room_rows'   => 8192,
                'room_size'   => 2048,
                'client_rows' => 4096,
                'client_size' => 2048,
            ],
            'redis' => [
                'host'          => '127.0.0.1',
                'port'          => 6379,
                'max_active'    => 3,
                'max_wait_time' => 5,
            ],
        ],
        'listen'        => [],
        'subscribe'     => [],
    ],
    'rpc'        => [
        'server' => [
            'enable'     => false,
            'host'       => '0.0.0.0',
            'port'       => 9000,
            'worker_num' => $cpuNum,
            'services'   => [],
        ],
        'client' => [],
    ],
    //队列
    'queue'      => [
        'enable'  => false,
        'workers' => [],
    ],
    'hot_update' => [
        'enable'  => env('APP_DEBUG', false),
        'name'    => ['*.php'],
        'include' => [app_path()],
        'exclude' => [],
    ],
    //连接池
    'pool'       => [
        'db'    => [
            'enable'        => true,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
        'cache' => [
            'enable'        => true,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
        //自定义连接池
    ],
    'ipc'        => [
        'type'  => 'unix_socket',
        'redis' => [
            'host'          => '127.0.0.1',
            'port'          => 6379,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
    ],
    //锁
    'lock'       => [
        'enable' => false,
        'type'   => 'table',
        'redis'  => [
            'host'          => '127.0.0.1',
            'port'          => 6379,
            'max_active'    => 3,
            'max_wait_time' => 5,
        ],
    ],
    'tables'     => [],
    //每个worker里需要预加载以共用的实例
    'concretes'  => [],
    //重置器
    'resetters'  => [],
    //每次请求前需要清空的实例
    'instances'  => [],
    //每次请求前需要重新执行的服务
    'services'   => [],
];
