<?php
/**
 * SmartStock 插件公共函数
 */

if (!function_exists('smartstock_order_no')) {
    /**
     * 生成业务单号
     *
     * 格式：{prefix}YYYYMMDD{4位序号}
     * 示例：IN202606190001, OUT202606190001, TF202606190001, CK202606190001
     *
     * @param string $prefix 单号前缀（IN/OUT/TF/CK）
     * @return string 生成的单号
     */
    function smartstock_order_no(string $prefix): string
    {
        // 单号前缀可从插件中心统一配置覆盖（manifest config 的 order.* 项）。
        // 映射表：默认前缀 → 配置键。配置为空/未设置时回落硬编码默认值，
        // 保证未配置前行为与旧版完全一致（调用点无需任何改动）。
        $configMap = [
            'IN'  => 'stock_in_prefix',
            'OUT' => 'stock_out_prefix',
            'TF'  => 'transfer_prefix',
            'CK'  => 'check_prefix',
        ];
        $configKey = $configMap[$prefix] ?? null;
        if ($configKey !== null) {
            $configured = (string) \think\facade\Config::get('smartstock.' . $configKey, '');
            if ($configured !== '') {
                $prefix = $configured;
            }
        }

        $date = date('Ymd');
        $key = "smartstock_order_no_{$prefix}_{$date}";

        // 使用原子递增操作获取序号
        // 注意：think\Cache 没有 expire() 方法，需用 set() 重新写入并指定 TTL
        // （缓存清空时序号会重置，生产建议改为 DB 计数器 + 行锁，见 P1 修复项）
        $seq = \think\facade\Cache::inc($key, 1);
        if ($seq === false) {
            throw new \RuntimeException('获取单号序号失败，请检查缓存配置');
        }
        // 回写并设置过期时间为次日凌晨，避免序号永久残留
        $tomorrow = strtotime('+1 day', strtotime($date));
        $ttl = max(86400, $tomorrow - time());
        \think\facade\Cache::set($key, $seq, $ttl);

        return $prefix . $date . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('smartstock_get_config')) {
    /**
     * 获取SmartStock配置项
     *
     * @param string $key 配置键名（支持点号分隔，如 'app.page_size'）
     * @param mixed $default 默认值
     * @return mixed
     */
    function smartstock_get_config(string $key, $default = null)
    {
        return \think\facade\Config::get("smartstock.{$key}", $default);
    }
}
