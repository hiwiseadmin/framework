<?php
// demo 插件 — 公共函数
// 函数命名约定：demo_xxx()

if (!function_exists('demo_info')) {
    /**
     * 获取插件基本信息。
     */
    function demo_info(): array
    {
        return config('demo.info', []);
    }
}

if (!function_exists('demo_config')) {
    /**
     * 获取插件配置项。
     *
     * @param string|null $key     配置键
     * @param mixed       $default 默认值
     */
    function demo_config(?string $key = null, $default = null)
    {
        $config = config('demo');
        if ($key === null) {
            return $config;
        }
        return $config[$key] ?? $default;
    }
}
