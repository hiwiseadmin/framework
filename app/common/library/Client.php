<?php
namespace app\common\library;
// https://github.com/WhichBrowser/Parser-PHP
// 可供参考的项目，以后优化

class Client
{
    /**
     * 获取 User-Agent 字符串（多途径回退）
     */
    protected static function getUserAgent(): string
    {
        // 优先从 ThinkPHP Request 对象获取
        try {
            $request = request();
            if ($request) {
                $ua = $request->header('user-agent');
                if (!empty($ua)) {
                    return $ua;
                }
            }
        } catch (\Throwable) {
            // request() 可能未初始化
        }

        // 回退：从 $_SERVER 直接获取（Swoole 下 request() 已可用，此兜底极少触发）
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * 获取浏览器信息
     * @param string|null $userAgent 用户代理字符串，为 null 时自动获取
     * @return array 包含浏览器名称和版本的关联数组
     */
    public static function getBrowser($userAgent = null): array
    {
        $userAgent = $userAgent ?? self::getUserAgent();

        $browser = 'Unknown';
        $browserVersion = '';

        if (preg_match('/Edg\/([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Microsoft Edge';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Chrome\/([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Chrome';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Firefox\/([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Firefox';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Safari\/([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Safari';
            $browserVersion = $matches[1];
        } elseif (preg_match('/MSIE ([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Internet Explorer';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Trident\/.*rv:([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Internet Explorer';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Opera\/([0-9.]+)/i', $userAgent, $matches)) {
            $browser = 'Opera';
            $browserVersion = $matches[1];
        }

        return [
            'name'      => $browser,
            'version'   => $browserVersion,
            'userAgent' => $userAgent,
            'string'    => trim($browser . ' ' . $browserVersion),
        ];
    }

    /**
     * 获取操作系统信息
     * @param string|null $userAgent 用户代理字符串，为 null 时自动获取
     * @return array 包含操作系统名称和版本的关联数组
     */
    public static function getOs($userAgent = null): array
    {
        $userAgent = $userAgent ?? self::getUserAgent();

        $os = 'Unknown';
        $version = '';

        if (preg_match('/Windows NT ([0-9.]+)/i', $userAgent, $matches)) {
            $os = 'Windows';
            $version = self::mapWindowsVersion($matches[1]);
        } elseif (preg_match('/Mac OS X ([0-9_]+)/i', $userAgent, $matches)) {
            $os = 'macOS';
            $version = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Macintosh/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/Android ([0-9.]+)/i', $userAgent, $matches)) {
            $os = 'Android';
            $version = $matches[1];
        } elseif (preg_match('/iPhone OS ([0-9_]+)/i', $userAgent, $matches)) {
            $os = 'iOS';
            $version = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/iPad/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/CrOS ([^\s]+)/i', $userAgent, $matches)) {
            $os = 'Chrome OS';
            $version = $matches[1];
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $os = 'Linux';
        }

        return [
            'name'      => $os,
            'version'   => $version,
            'userAgent' => $userAgent,
            'string'    => trim($os . ' ' . $version),
        ];
    }

    /**
     * Windows NT 版本号 → 友好名称
     */
    protected static function mapWindowsVersion(string $ntVersion): string
    {
        return match ($ntVersion) {
            '10.0'  => '10/11',
            '6.3'   => '8.1',
            '6.2'   => '8',
            '6.1'   => '7',
            '6.0'   => 'Vista',
            '5.1'   => 'XP',
            '5.0'   => '2000',
            default => $ntVersion,
        };
    }

    /**
     * 获取客户端真实 IP 地址
     *
     * 优先级（仅在信任代理时按序检查代理头）：
     *   1. HTTP_X_FORWARDED_FOR（取第一个，最靠近客户端的 IP）
     *   2. HTTP_X_REAL_IP
     *   3. REMOTE_ADDR
     *
     * FPM + Swoole 双模式兼容：
     *   优先使用 ThinkPHP request()->server() API，
     *   回退 $_SERVER（CLI/未初始化场景）。
     *
     * 注意：
     *   - HTTP_CLIENT_IP 不纳入检查（极易被伪造，无安全收益）
     *   - 仅信任私有网段内的代理 IP，防止外部请求伪造代理头
     *   - 多级代理时取最左侧（原始客户端）IP，过滤私有/保留地址
     *
     * @param bool $trustProxy 是否信任代理头（局域网环境下建议 true）
     */
    public static function getClientIP(bool $trustProxy = true): string
    {
        // 获取 server 参数（兼容 Swoole + FPM）
        $server = self::getServerParams();

        $ip = '';

        if ($trustProxy) {
            // 仅当 REMOTE_ADDR 是可信代理（私有/回环 IP）时才信任代理头
            $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
            $isTrustedProxy = self::isPrivateIP($remoteAddr);

            if ($isTrustedProxy) {
                // X-Forwarded-For: client, proxy1, proxy2
                $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
                if ($xff !== '') {
                    foreach (explode(',', $xff) as $candidate) {
                        $candidate = trim($candidate);
                        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            $ip = $candidate;
                            break;
                        }
                    }
                    if ($ip === '') {
                        $first = explode(',', $xff)[0];
                        $ip = trim($first);
                    }
                }

                // X-Real-IP（Nginx proxy_set_header 常用）
                if ($ip === '') {
                    $xri = (string) ($server['HTTP_X_REAL_IP'] ?? '');
                    if ($xri !== '' && filter_var($xri, FILTER_VALIDATE_IP)) {
                        $ip = $xri;
                    }
                }
            }
        }

        // 回退到 REMOTE_ADDR
        if ($ip === '') {
            $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        }

        // 最终清理：取多值中的第一个
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * 获取 server 参数（FPM + Swoole 兼容）
     *
     * 优先使用 ThinkPHP request()->server()，
     * 回退 $_SERVER（CLI 模式或未初始化时）。
     *
     * @return array
     */
    private static function getServerParams(): array
    {
        try {
            $request = request();
            if ($request && method_exists($request, 'server')) {
                $server = $request->server();
                if (!empty($server)) {
                    return $server;
                }
            }
        } catch (\Throwable) {
            // request() 未初始化
        }

        return $_SERVER;
    }

    /**
     * 判断 IP 是否为私有/保留地址
     */
    public static function isPrivateIP(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * 通过IP获取地理位置信息
     * @param string|null $ip IP地址，为null时自动获取客户端IP
     * @return array 包含country/region/city/isp的关联数组
     */
    public static function getLocationByIP($ip = null): array
    {
        if ($ip === null) {
            $ip = self::getClientIP();
        }

        // 无效 IP 或本地/私有地址返回未知
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP) || self::isPrivateIP($ip)) {
            return [
                'country' => '未知',
                'region'  => '未知',
                'city'    => '未知',
                'isp'     => '未知',
            ];
        }

        try {
            // 修正：IPLocation 使用链式调用
            $locationInfo = IPLocation::getInstance()->getLocation($ip)->all();
            return [
                'country' => $locationInfo['country'] ?? '未知',
                'region'  => $locationInfo['area'] ?? '未知',
                'city'    => $locationInfo['city'] ?? '未知',
                'isp'     => $locationInfo['isp'] ?? '未知',
            ];
        } catch (\Exception) {
            return [
                'country' => '未知',
                'region'  => '未知',
                'city'    => '未知',
                'isp'     => '未知',
            ];
        }
    }
}