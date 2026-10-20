<?php
namespace plugin\wisecms\service\security;

/**
 * 出站 URL 安全守卫（P1-A2 / P1-A3 / P1-A4）
 *
 * 收敛插件内所有「服务端/浏览器要跳出去」的 URL 校验，避免同一策略多处实现
 * 导致的强弱不一（审计 P1-3 / P1-4 / P1-5）：
 *
 *   - {@see isSafeScheme()}   协议白名单：仅 http/https 与相对路径
 *     → 用于 301/302 目标、栏目外链等「开放重定向 / javascript: 注入」场景；
 *   - {@see isSafeOutbound()} 协议 + 域名全量解析 + 内网拒绝（SSRF）
 *     → 用于服务端主动抓取（远程图片下载、采集、死链检测）；
 *   - {@see outboundIp()}     返回「已校验通过的首个可连接 IP」，用于连接期 IP 固定
 *     （CURLOPT_RESOLVE），消除「校验与连接之间 DNS 变更」的 TOCTOU 窗口。
 *
 * 判定规则与 `service/engine/SpiderService` 原实现完全一致（A/AAAA 全量解析），
 * 因此把原先只做 `gethostbyname()` 单次校验的调用方统一过来即可消除 IPv6 与
 * 多记录 rebinding 缺口。
 */
final class UrlGuard
{
    /**
     * 协议白名单：仅允许 http / https，以及不含协议的相对路径
     *
     * 相对路径（`/a/b`、`//host/x`、`?q=1`、`#f`）一律视为安全，
     * 由调用方在拼装绝对地址时再补齐。
     */
    public static function isSafeScheme(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // 解码 HTML 实体 + 剔除控制字符（防 `jav\tascript:` / `&#106;avascript:` 绕过）
        $probe = (string) preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // 无协议头 → 相对路径 / 锚点 / 查询串
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $probe)) {
            return true;
        }

        return in_array(strtolower((string) parse_url($probe, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * SSRF 校验：协议白名单 + 域名解析到全部 A/AAAA 记录并逐一拒绝内网地址
     */
    public static function isSafeOutbound(string $url): bool
    {
        return self::outboundIp($url) !== null;
    }

    /**
     * 返回已通过 SSRF 校验的首个可连接 IP；非法或无法解析时返回 null
     *
     * 调用方可将返回值交给 `CURLOPT_RESOLVE` 固定连接目标，
     * 避免「校验用的 DNS 结果」与「实际连接用的 DNS 结果」不一致。
     */
    public static function outboundIp(string $url): ?string
    {
        if (!self::isSafeScheme($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            // 相对路径：由调用方补齐域名后重新校验
            return null;
        }

        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (self::isForbiddenIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * 解析主机到全部 IPv4/IPv6 地址（IP 字面量直接返回）
     *
     * @return string[]
     */
    public static function resolveHostIps(string $host): array
    {
        $host = trim($host, '[]');

        // 主机本身是 IP 字面量（含 IPv6）
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips    = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? ($record['ipv6'] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        // 回退：仅 IPv4
        if ($ips === []) {
            $ipv4 = @gethostbyname($host);
            if ($ipv4 && $ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP)) {
                $ips[] = $ipv4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * 判断 IP 是否属于禁止访问的内网 / 保留 / 环回 / 链路本地 / 多播地址
     */
    public static function isForbiddenIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * 构造 curl `CURLOPT_RESOLVE` 条目：`host:port:ip`（IPv6 自动加方括号）
     *
     * @param string $url 目标 URL
     * @param string $ip  已校验的 IP
     */
    public static function curlResolveEntry(string $url, string $ip): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) parse_url($url, PHP_URL_PORT);
        if ($port <= 0) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        }

        $formatted = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        return $host . ':' . $port . ':' . $formatted;
    }
}
