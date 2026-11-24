<?php

return [
    // 默认使用的数据库连接配置
    'default'         => env('DB_DRIVER', 'mysql'),

    // 自定义时间查询规则
    'time_query_rule' => [],

    // 自动写入时间戳字段
    // true为自动识别类型 false关闭
    // 字符串则明确指定时间字段类型 支持 int timestamp datetime date
    'auto_timestamp'  => true,

    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 时间字段配置 配置格式：create_time,update_time
    'datetime_field'  => '',

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            // 数据库类型
            'type'            => env('DB_TYPE', 'mysql'),
            // 服务器地址
            'hostname'        => env('DB_HOST', '127.0.0.1'),
            // 数据库名
            'database'        => env('DB_NAME', ''),
            // 用户名
            'username'        => env('DB_USER', ''),
            // 密码
            'password'        => env('DB_PASS', ''),
            // 端口
            'hostport'        => env('DB_PORT', '3306'),
            // 数据库连接参数
            'params'          => [],
            // 数据库编码
            'charset'         => env('DB_CHARSET', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => env('DB_PREFIX', 'ws_'),
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
            // 是否需要断线重连：
            //   Swoole 常驻下必须 true —— worker 复用连接，空闲超过 wait_timeout 后失效，
            //   复用旧连接会抛 "MySQL server has gone away" 且不会自动恢复；
            //   FPM 下每请求独立进程，连接本就随进程销毁，该检测分支几乎不走，开销可忽略。
            //   两环境最优解一致，故全局 true（详见 docs/swoole-fpm-config-strategy.md）。
            'break_reconnect' => true,
            // 监听SQL
            'trigger_sql'     => env('APP_DEBUG', true),
            // 开启字段缓存
            'fields_cache'    => false,
            // 自定义查询类（支持 whenIf 等扩展方法）
            // 'query'           => \app\common\model\query\Query::class,
        ],

        // 更多的数据库配置信息
    ],
];
