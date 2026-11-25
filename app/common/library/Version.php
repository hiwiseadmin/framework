<?php
declare(strict_types=1);

namespace app\common\library;

/**
 * 框架内部版本信息（只读）
 *
 * 版本真源：应用根目录 `version.json`（字段 product / channel / commit / builtAt），
 * 随发布包分发。文件缺失或非法时回落到 {@see self::UNKNOWN}，并把 `known` 标为 false，
 * 供 `php think wise:version` / 部署脚本据此告警，而不是静默显示一个假版本。
 *
 * 约束
 * ----
 * 只读文件，不碰容器、Db、Console —— 引导阶段（`config/*.php`）即可安全调用，
 * 因此 `site.version` 与 `update.current_version` 都从这里取值，避免多处手写漂移。
 */
final class Version
{
    /** 运行期版本清单文件名（应用根目录） */
    public const FILE_NAME = 'version.json';

    /** 完全找不到版本信息时的兜底版本号 */
    public const UNKNOWN = '0.0.0';

    /**
     * 进程内缓存
     *
     * 版本随发布固定，属部署级元数据（非请求级），可安全缓存；
     * 常驻运行时（Swoole）热更新文件后需重载进程或调用 {@see self::flush()}。
     *
     * @var array<string,mixed>|null
     */
    private static ?array $info = null;

    /**
     * 当前框架版本号（形如 `1.0.1`；未知时为 {@see self::UNKNOWN}）
     */
    public static function get(): string
    {
        return (string) self::info()['product'];
    }

    /**
     * 发布通道（默认 stable）
     */
    public static function channel(): string
    {
        return (string) self::info()['channel'];
    }

    /**
     * 构建提交号（未知时为空串）
     */
    public static function commit(): string
    {
        return (string) self::info()['commit'];
    }

    /**
     * 构建时间（ISO 8601，未知时为空串）
     */
    public static function builtAt(): string
    {
        return (string) self::info()['builtAt'];
    }

    /**
     * 是否读到了真实版本清单（false = 回落到 {@see self::UNKNOWN}）
     */
    public static function isKnown(): bool
    {
        return (bool) self::info()['known'];
    }

    /**
     * 完整版本信息
     *
     * @return array{product:string,channel:string,commit:string,builtAt:string,known:bool,source:string}
     */
    public static function info(): array
    {
        if (self::$info === null) {
            self::$info = self::resolve();
        }

        return self::$info;
    }

    /**
     * 清空进程内缓存（测试 / 热更新后使用）
     */
    public static function flush(): void
    {
        self::$info = null;
    }

    /**
     * 解析版本信息（读取失败时回落到 {@see self::UNKNOWN}）
     *
     * @return array{product:string,channel:string,commit:string,builtAt:string,known:bool,source:string}
     */
    private static function resolve(): array
    {
        $path    = self::manifestPath();
        $data    = self::readJson($path);
        $product = $data === null ? '' : trim((string) ($data['product'] ?? ''));

        if ($product === '') {
            return [
                'product' => self::UNKNOWN,
                'channel' => 'stable',
                'commit'  => '',
                'builtAt' => '',
                'known'   => false,
                'source'  => '',
            ];
        }

        return [
            'product' => self::normalize($product),
            'channel' => trim((string) ($data['channel'] ?? '')) ?: 'stable',
            'commit'  => trim((string) ($data['commit'] ?? '')),
            'builtAt' => trim((string) ($data['builtAt'] ?? '')),
            'known'   => true,
            'source'  => $path,
        ];
    }

    /**
     * 版本清单绝对路径
     */
    private static function manifestPath(): string
    {
        return rtrim(self::rootPath(), '/\\') . '/' . self::FILE_NAME;
    }

    /**
     * 应用根目录（不依赖容器，保证引导阶段可用）
     */
    private static function rootPath(): string
    {
        if (function_exists('root_path')) {
            return (string) root_path();
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /**
     * 读取并解析 JSON 清单（文件缺失 / 非法 JSON 一律返回 null）
     *
     * @return array<string,mixed>|null
     */
    private static function readJson(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 归一化版本串：去掉手写常见的 `v` / `V` 前缀与空白
     */
    private static function normalize(string $product): string
    {
        $product = trim($product);

        if ($product !== '' && ($product[0] === 'v' || $product[0] === 'V')) {
            $product = substr($product, 1);
        }

        return $product;
    }
}
