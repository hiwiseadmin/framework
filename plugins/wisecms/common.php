<?php
/**
 * CMS 插件公共函数
 * 所有函数名前缀: cms_
 */

if (!function_exists('cms_config')) {
    function cms_config($key = null, $default = null)
    {
        $config = \think\facade\Config::get('wisecms', []);
        
        if ($key === null) {
            return $config;
        }
        
        return $config[$key] ?? $default;
    }
}

if (!function_exists('cms_url')) {
    function cms_url($url = '', array $vars = [])
    {
        return (string) \think\facade\Route::buildUrl($url, $vars);
    }
}

if (!function_exists('cms_datetime')) {
    /**
     * 时间入参归一化：任意输入 → `Y-m-d H:i:s` 或 null
     *
     * 时间列一律为 DATETIME，入参需在此收敛：
     *   · 后台 `datetime-local` 控件：`2026-10-05T12:00`（含 T，MySQL 不接受）
     *   · 纯日期：`2026-10-05` → 当天 00:00:00
     *   · 空串 / null / '0' / 0 → null（语义「未设置 / 不限」）
     */
    function cms_datetime($value): ?string
    {
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $ts = strtotime((string) $value);

        return $ts !== false && $ts > 0 ? date('Y-m-d H:i:s', $ts) : null;
    }
}

if (!function_exists('cms_now')) {
    /**
     * 当前时间字符串（DATETIME）：统一时间源，便于阅读与替换
     */
    function cms_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('cms_timestamp')) {
    /**
     * 把库中的 DATETIME 值转为 unix 时间戳（0 表示无效/未设置）
     *
     * 用于仍需数值比较的场景：时间窗口筛选、时间衰减打分、Feed 排序等
     * （datetime 字符串直接转 int 会得到年份，必须先归一）。
     */
    function cms_timestamp($value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        $ts = strtotime((string) $value);

        return $ts !== false && $ts > 0 ? $ts : 0;
    }
}

if (!function_exists('cms_is_duplicate_key')) {
    /**
     * 判断异常是否为 MySQL 唯一键冲突（1062 / SQLSTATE 23000）
     *
     * 「先查后写」在并发下必然存在竞态窗口，唯一索引是最终防线。命中冲突时调用方
     * 通常应把本次操作视为**幂等成功**或转成业务提示，而不是把 500 抛给用户。
     */
    function cms_is_duplicate_key(\Throwable $e): bool
    {
        $current = $e;
        $depth   = 0;

        while ($current !== null && $depth < 5) {
            $message = $current->getMessage();
            if (str_contains($message, '1062')
                || str_contains($message, '23000')
                || stripos($message, 'duplicate entry') !== false) {
                return true;
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return false;
    }
}

if (!function_exists('cms_log')) {
    /**
     * 上下文日志（带子包缺失兜底）
     *
     * 优先使用 wise-support 的 ContextualLogger（自动注入 request_id / user_id 等上下文），
     * 当 wise-support 不可用（vendor 未安装 / path-repo 软链失效）时回落到框架 Log。
     *
     * 之所以必须兜底：日志调用大量位于 catch 块中，若此处再抛 "Class not found"，
     * 会顶替掉原始业务异常，导致排查时只看到日志器报错而看不到真实错误。
     *
     * @param string $level   info|error|warning|debug|notice|alert|critical|emergency
     * @param string $message 日志内容
     * @param array  $context 附加上下文
     */
    function cms_log(string $level, string $message, array $context = []): void
    {
        try {
            if (class_exists(\wise\support\context\ContextualLogger::class)) {
                \wise\support\context\ContextualLogger::{$level}($message, $context);
                return;
            }

            \think\facade\Log::{$level}($message, $context);
        } catch (\Throwable $e) {
            // 日志永不应成为业务异常的替代者：即使日志通道/驱动自身出错也绝不外抛，
            // 否则会把 catch 块里的原始业务异常顶替掉，导致排查时只见日志报错不见真实错误
        }
    }
}