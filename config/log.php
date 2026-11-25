<?php

// +----------------------------------------------------------------------
// | 日志设置
// +----------------------------------------------------------------------
return [
    // 默认日志记录通道
    'default'      => 'file',
    // 日志记录级别
    'level'        => ['error', 'info'],
    // 日志类型记录的通道 ['error'=>'email',...]
    'type_channel' => [],
    // 关闭全局日志写入
    'close'        => false,
    // 全局日志处理 支持闭包
    'processor'    => null,

    // 日志通道列表
    'channels'     => [
        'file' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => '',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => [],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
        // AI（wise-agent）专用日志通道
        // 由 wise-agent 包 config('wise-agent.log.channel') 引用，独立目录便于审计与排障
        'ai' => [
            'type'           => 'File',
            // 独立日志目录 runtime/log/ai
            'path'           => '',
            'single'         => false,
            'apart_level'    => [],
            'max_files'      => 30,
            'json'           => false,
            'processor'      => null,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => false,
        ],
        // 备份（wise-backup）专用日志通道
        // wise-backup 全部日志走 ContextualLogger::channel('backup')，
        // 若通道未定义，think 的 Log::channel() 会抛「Channel [backup] not found」，
        // 备份/还原命令会整体失败（连第一条日志都写不出去）。
        'backup' => [
            'type'           => 'File',
            // 独立日志目录 runtime/log/backup
            'path'           => '',
            'single'         => false,
            'apart_level'    => [],
            'max_files'      => 30,
            'json'           => false,
            'processor'      => null,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => false,
        ],
        // 其它日志通道配置
    ],

];
