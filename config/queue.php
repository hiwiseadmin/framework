<?php
/**
 * WiseAdmin 队列配置
 *
 * 本配置是项目唯一生效的队列配置，覆盖 extend/think-queue/config/queue.php 的包默认值。
 *
 * 覆盖关系：
 *   extend/think-queue/config/queue.php（包默认，不生效）→ config/queue.php（项目覆盖，生效）
 *
 * 与 extend 默认值的差异：
 *   - default: sync → database（项目使用数据库持久化队列）
 *   - failed.type: none → database（项目记录失败任务供排查和重试）
 */
return [
    'default' => 'database',
    
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'database' => [
            'type' => 'database',
            'queue' => 'default',
            'table' => 'jobs',
            'connection' => null, // 使用默认数据库连接
        ],
        'redis' => [
            'type' => 'redis',
            'queue' => 'default',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'select' => 0,
            'timeout' => 0,
            'persistent' => false,
        ],
    ],
    
    'failed' => [
        'type' => 'database',
        'table' => 'failed_jobs',
    ]
];