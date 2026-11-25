<?php
declare(strict_types=1);

namespace wise\plugin\compat;

/**
 * 框架兼容声明（`manifest.compatible_with`）的归一与判定。
 *
 * ## 语义
 *
 * `compatible_with` 是**作者声明的支持范围**，不是"实测通过"：
 *  - 声明 `"^1.0"` → 框架 1.x（< 2.0）可装；
 *  - 声明 `["^1.0", "^1.1"]` → 任一满足即可（等价于 `"^1.0 | ^1.1"`）；
 *  - 未声明 → `unknown`：**不拦、不标红**，因为绝大多数存量插件没有这个字段。
 *
 * 三态刻意分开：
 *  - `compatible`   声明覆盖当前框架 → 放行；
 *  - `incompatible` 声明不覆盖 → 安装/升级前拒绝（可用 `--ignore-compat` 强行绕过）；
 *  - `unknown`      未声明 → 放行，只在市场/列表里提示「未声明兼容范围」。
 *
 * 为什么不把「未声明」当 `incompatible`：存量 8 个内置插件与大量第三方包都没有该字段，
 * 一律拦截等于把升级路径直接砸断；正确地做法是"声明了才约束"。
 *
 * ## 与市场侧的关系
 *
 * 范围语法与 `wise\market\service\VersionConstraint` 完全一致，但**不能共享代码**
 * （市场包可独立安装、运行时也不能依赖市场包）。防漂移靠
 * `scripts/verify-compat-parity.php` 的双向用例比对。
 */
final class FrameworkCompatibility
{
    public const COMPATIBLE = 'compatible';

    public const INCOMPATIBLE = 'incompatible';

    public const UNKNOWN = 'unknown';

    /** 单条声明长度上限（防止把整段 JSON 塞进字段） */
    private const MAX_RANGE_LENGTH = 100;

    /** 声明条数上限（超出视为脏数据，只取前 N 条） */
    private const MAX_RANGES = 8;

    /**
     * 归一 manifest 声明：字符串 / 字符串数组 → 去重后的范围列表。
     *
     * 非法形态（对象、数字、空串）一律丢弃；语法是否合法由调用方用
     * {@see VersionRange::isValid()} 判定（`plugin:audit` 会报 error）。
     *
     * @return array<int, string>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_object($raw)) {
            $raw = json_decode((string) json_encode($raw), true);
        }

        if (is_string($raw)) {
            $trim = trim($raw);

            if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
                $decoded = json_decode($trim, true);

                if (is_array($decoded)) {
                    $raw = $decoded;
                }
            }
        }

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $ranges = [];

        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '' || strlen($item) > self::MAX_RANGE_LENGTH) {
                continue;
            }

            $ranges[$item] = true;

            if (count($ranges) >= self::MAX_RANGES) {
                break;
            }
        }

        return array_keys($ranges);
    }

    /**
     * 从磁盘 manifest 读取（升级流程用：此时扫描缓存还是旧清单）。
     *
     * @return array<int, string>
     */
    public static function fromManifestFile(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? self::normalize($data['compatible_with'] ?? null) : [];
    }

    /**
     * 判定声明与某个框架版本的关系。
     *
     * @param array<int, string> $ranges
     */
    public static function check(array $ranges, ?string $frameworkVersion = null): string
    {
        if ($ranges === []) {
            return self::UNKNOWN;
        }

        $version = $frameworkVersion !== null && trim($frameworkVersion) !== ''
            ? trim($frameworkVersion)
            : self::currentVersion();

        foreach ($ranges as $range) {
            if (VersionRange::satisfies($version, $range)) {
                return self::COMPATIBLE;
            }
        }

        return self::INCOMPATIBLE;
    }

    /**
     * 当前框架版本（`version.json` 的 product 字段，经 `app_version()` 归一）。
     *
     * 单测/独立脚本里没有宿主 helper 时回落 `0.0.0`：此时声明范围基本都不会满足，
     * 但我们不会在那种环境下做拦截判定（调用方只在安装/升级链路里用）。
     */
    public static function currentVersion(): string
    {
        if (function_exists('app_version')) {
            try {
                $version = (string) app_version();
            } catch (\Throwable $e) {
                $version = '';
            }
        } else {
            $version = '';
        }

        return trim($version) !== '' ? trim($version) : '0.0.0';
    }

    /**
     * 人话描述（错误消息 / 徽章 tooltip）。
     *
     * @param array<int, string> $ranges
     */
    public static function describe(array $ranges): string
    {
        if ($ranges === []) {
            return '未声明兼容范围';
        }

        return implode(' | ', $ranges);
    }

    /**
     * 不兼容时的统一错误消息（安装 / 升级共用，避免两处口径不一致）。
     *
     * @param array<int, string> $ranges
     */
    public static function violationMessage(string $plugin, array $ranges, ?string $frameworkVersion = null): string
    {
        $version = $frameworkVersion !== null && trim($frameworkVersion) !== ''
            ? trim($frameworkVersion)
            : self::currentVersion();

        return sprintf(
            '插件 %s 声明的兼容范围为 %s，当前框架版本 %s 不满足。'
            . '请升级插件或框架；确认风险后可加 --ignore-compat 强制继续。',
            $plugin,
            self::describe($ranges),
            $version
        );
    }
}
