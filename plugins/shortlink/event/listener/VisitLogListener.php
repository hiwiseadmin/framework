<?php
namespace plugin\shortlink\event\listener;

use think\facade\Cache;

/**
 * 访问日志监听器
 * 监听 shortlink.visit 事件，记录额外访问信息
 */
class VisitLogListener
{
    public function handle($event)
    {
        // 提取事件数据
        $link = $event['link'] ?? null;
        $log  = $event['log'] ?? null;

        if (!$link || !$log) {
            return;
        }

        // 解析 User-Agent 获取设备、浏览器、操作系统信息
        $ua = $log->getData('ua') ?? '';
        if (!empty($ua)) {
            $uaInfo = $this->parseUserAgent($ua);
            $log->device  = $uaInfo['device'];
            $log->browser = $uaInfo['browser'];
            $log->os      = $uaInfo['os'];
        }

        // 通过 IP 异步获取地理位置（使用缓存避免频繁请求）
        $ip = $log->getData('ip') ?? '';
        if (!empty($ip) && $ip !== '127.0.0.1') {
            $geoData = $this->getGeoByIp($ip);
            $log->country  = $geoData['country'] ?? '';
            $log->province = $geoData['province'] ?? '';
            $log->city     = $geoData['city'] ?? '';
        }

        $log->save();
    }

    /**
     * 解析 User-Agent 获取设备/浏览器/操作系统
     */
    protected function parseUserAgent(string $ua): array
    {
        $device  = 'Desktop';
        $browser = 'Unknown';
        $os      = 'Unknown';

        // 检测操作系统
        if (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Mac OS') !== false || stripos($ua, 'Macintosh') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false || stripos($ua, 'X11') !== false) {
            $os = 'Linux';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = 'iOS';
        }

        // 检测设备类型
        if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false) {
            $device = 'Mobile';
        } elseif (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) {
            $device = 'Tablet';
        }

        // 检测浏览器
        if (stripos($ua, 'Edg/') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (stripos($ua, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident') !== false) {
            $browser = 'IE';
        } elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) {
            $browser = 'Opera';
        }

        return compact('device', 'browser', 'os');
    }

    /**
     * 通过 IP 获取地理位置（带缓存）
     * 可替换为实际的地理位置服务（如 GeoIP2、第三方 API 等）
     */
    protected function getGeoByIp(string $ip): array
    {
        $cacheKey = 'shortlink_geo:' . md5($ip);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 默认返回空，可接入实际 IP 地理库
        $geoData = [
            'country'  => '',
            'province' => '',
            'city'     => '',
        ];

        // 缓存 24 小时
        Cache::set($cacheKey, $geoData, 86400);

        return $geoData;
    }
}
