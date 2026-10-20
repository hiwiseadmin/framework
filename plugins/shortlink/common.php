<?php
/**
 * 短链接插件公共函数
 */

if (!function_exists('shortlink_config')) {
    /**
     * 获取短链接插件配置
     */
    function shortlink_config(string $key, $default = null)
    {
        return \think\facade\Config::get("shortlink.app.{$key}", $default);
    }
}

if (!function_exists('shortlink_random_code')) {
    /**
     * 生成随机短码
     */
    function shortlink_random_code(int $length = 6): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, 61)];
        }
        return $code;
    }
}

if (!function_exists('shortlink_base62_encode')) {
    /**
     * Base62 编码
     */
    function shortlink_base62_encode(int $num, int $length = 6): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $code = '';
        while ($num > 0) {
            $code = $chars[$num % 62] . $code;
            $num = intdiv($num, 62);
        }
        return str_pad($code, $length, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('shortlink_base62_decode')) {
    /**
     * Base62 解码
     * @throws \InvalidArgumentException 当输入包含无效字符时
     */
    function shortlink_base62_decode(string $code): int
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $num = 0;
        for ($i = 0, $len = strlen($code); $i < $len; $i++) {
            $pos = strpos($chars, $code[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException("Invalid Base62 character: '{$code[$i]}' at position {$i}");
            }
            $num = $num * 62 + $pos;
        }
        return $num;
    }
}

if (!function_exists('shortlink_url')) {
    /**
     * 生成短链接完整URL
     */
    function shortlink_url(string $code): string
    {
        return '/s/' . $code;
    }
}
