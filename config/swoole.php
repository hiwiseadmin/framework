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
    //协程 hook：think-swoole 在每个 worker 启动时调用
    //  Swoole\Runtime::enableCoroutine(coroutine.enable, coroutine.flags)，官方默认
    //  true + SWOOLE_HOOK_ALL。此处显式声明一次，防止默认值调整或误删配置后，
    //  curl / 文件 IO / sleep 退化成阻塞调用（图片远程抓取、存储驱动、缓存写入都依赖它）。
    //两点 hook 覆盖不到的地方，各包已自行兜底：
    //  1. DNS（dns_get_record / gethostbynamel）不受 hook 影响 → RemoteFetcher 自带进程级 DNS 缓存；
    //  2. flock 是否被 hook 取决于 Swoole 版本 → 文件锁一律 LOCK_NB + Runtime::sleep 重试。
    'coroutine'  => array_merge(
        ['enable' => true],
        defined('SWOOLE_HOOK_ALL') ? ['flags' => SWOOLE_HOOK_ALL] : []
    ),
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
    //重置器：在「每个请求开始前」执行（think-swoole Sandbox::init() → resetApp()，
    //早于业务回调，等价于「清掉上一个请求的残留」）。请求结束的 clear() 不执行重置器。
    //执行顺序：内置 ClearInstances → ResetConfig → ResetEvent → ResetService，
    //之后执行本配置项。
    //
    //⚠️ 全站只保留这一个桥接项，不再逐包新增：
    //  框架 / wise-* 包 / 插件 统一实现 RequestStateResetterInterface 并注册到
    //  \wise\support\context\RequestStateRegistry（在各自 ServiceProvider::boot() 里注册），
    //  由 PackageStateResetter 一次性调度，按优先级排序、异常隔离。
    //  约定：一个包 / 插件一个重置器类（粒度是「包」而非「状态项」）。
    //  当前已接入（按优先级）：
    //    100-wise-support     Once 缓存 / ExtPoint 活跃态 / RequestContext
    //    150-slot-manager     插槽权限判定闭包（宿主业务）
    //    200-wise-config      ConfigManager 继承链/命名空间/onlyPublic
    //    300-wise-attachment  图片处理器缓存 / 统计计数
    //  非协程运行时（FPM / Workerman）由 wise-support 的 HttpEnd 监听兜底调用同一注册表。
    //未安装 topthink/think-swoole 时本配置无消费方，不影响 FPM。
    'resetters'  => [
        \app\common\resetter\PackageStateResetter::class,
    ],
    //每次请求前需要清空的实例
    'instances'  => [],
    //每次请求前需要重新执行的服务
    'services'   => [],
];
