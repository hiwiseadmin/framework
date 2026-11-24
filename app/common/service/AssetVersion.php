<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 静态资源版本号（浏览器缓存 buster）
 *
 * 单一真源：runtime/asset_version 里的一行整数。
 *  - 读：一次文件读；文件缺失时以当前时间戳落盘作种子（跨请求稳定，且不小于
 *    历史 module_version 的 time() 口径，不会让浏览器回退到更旧的缓存）；
 *  - 写：递增 1，flock 串行 + 临时文件 rename 原子替换；
 *  - 只有「清理缓存 → 组件缓存」会触发自增，任何 clean*() 都不删除它。
 *
 * 为什么不再放在 shared_file store 的 module_version：
 *  `Cache::store('shared_file')->clear()` 会把这个键一起删掉，只能靠「清完立刻
 *  写回 time()」维持单调。任何不走 CacheClearService 的清理（部署脚本
 *  `rm -rf runtime/cache`、Redis flush、人工误删）都会让版本回到默认 '1'，
 *  浏览器于是继续复用旧 js/css —— 比不清更糟。改用独立文件后，版本与缓存
 *  生命周期解耦，只增不减。
 *
 * @see \app\common\service\CacheClearService::cleanModule()
 * @see \app\middleware\Environment::resolveAssetVersion()
 */
final class AssetVersion
{
    /** 计数器文件名（放 runtime 根：不在任何清理目标目录内） */
    public const FILE_NAME = 'asset_version';

    /** 计数锁文件名 */
    private const LOCK_NAME = 'asset_version.lock';

    /** 计数器文件绝对路径 */
    public static function file(?string $runtimePath = null): string
    {
        $root = rtrim($runtimePath ?? runtime_path(), '/\\') . DIRECTORY_SEPARATOR;

        return $root . self::FILE_NAME;
    }

    /**
     * 当前版本号
     *
     * 文件缺失（首次部署）时以 time() 播种并落盘，保证同一秒内多次请求拿到同值。
     */
    public static function get(?string $runtimePath = null): int
    {
        return self::read($runtimePath) ?? self::bump($runtimePath);
    }

    /**
     * 自增并返回新版本号（「组件缓存」按钮与 CLI 的唯一入口）
     */
    public static function bump(?string $runtimePath = null): int
    {
        $file = self::file($runtimePath);
        $lock = @fopen(dirname($file) . DIRECTORY_SEPARATOR . self::LOCK_NAME, 'c');

        if ($lock === false) {
            return max((int) time(), (self::read($runtimePath) ?? 0) + 1);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return max((int) time(), (self::read($runtimePath) ?? 0) + 1);
            }

            $current = self::read($runtimePath);
            $next = $current === null ? (int) time() : $current + 1;

            self::write($file, $next);

            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 给 URL 追加版本参数
     *
     * 已带 _v=（含 `?v=xx&_v=yy`）时原样返回；同时保留 #hash 在原位。
     *
     * @param string|int|null $version 不传则取当前版本号
     */
    public static function appendTo(string $url, string|int|null $version = null): string
    {
        $version = $version === null ? (string) self::get() : (string) $version;

        if ($url === '' || $version === '' || preg_match('/(^|[?&])_v=/', $url) === 1) {
            return $url;
        }

        $hashAt = strpos($url, '#');
        $hash   = $hashAt === false ? '' : substr($url, $hashAt);
        $base   = $hashAt === false ? $url : substr($url, 0, $hashAt);

        return $base . (str_contains($base, '?') ? '&' : '?') . '_v=' . rawurlencode($version) . $hash;
    }

    /**
     * 读取计数器（缺失 / 非纯数字一律视为未知）
     */
    private static function read(?string $runtimePath = null): ?int
    {
        $file = self::file($runtimePath);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * 写入计数器
     *
     * 调用方必须已持有 asset_version.lock 的排他锁（bump() 内保证），
     * 因此这里只需防止「读一半」。先写临时文件再 rename，避免读到空内容。
     */
    private static function write(string $file, int $value): bool
    {
        $payload = (string) $value;
        $tmp     = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload) !== false && @rename($tmp, $file)) {
            return true;
        }

        @unlink($tmp);

        return @file_put_contents($file, $payload) !== false;
    }
}
