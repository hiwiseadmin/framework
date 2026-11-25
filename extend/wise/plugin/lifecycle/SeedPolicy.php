<?php

declare(strict_types=1);

namespace wise\plugin\lifecycle;

/**
 * 数据填充策略（manifest `seed` 字段的唯一解析口径）。
 *
 * ## 为什么需要三种模式
 *
 * `database/seeds/` 下的文件有两种完全不同的语义，历史实现把它们混成了「装完一律执行」：
 *
 * | 模式 | 含义 | 典型内容 | 安装默认 | 失败处理 |
 * |:--|:--|:--|:--|:--|
 * | `required` | 开箱即用的**基线数据** | 默认站点、内容模型、栏目 | 执行 | 阻断安装（缺了就是半个系统） |
 * | `demo` | **演示数据**，只为看效果 | 示例文档、示例订单 | **跳过**，`--with-demo` 才执行 | 只告警（演示数据不该让安装失败） |
 * | `none` | 明确没有数据填充 | — | 跳过 | — |
 *
 * 缺省（manifest 未声明）按 `required` 处理 —— 这是**向后兼容**口径：
 * 存量第三方插件若依赖 seeds 完成初始化，升级到本行为后不会被静默跳过。
 *
 * 新插件若只是灌演示数据，应显式声明 `"seed": "demo"`，否则用户装完就会看到
 * 指的是可选项，不是默认塞满）。
 */
final class SeedPolicy
{
    /** 基线数据：安装时执行，失败阻断 */
    public const REQUIRED = 'required';

    /** 演示数据：默认跳过，`--with-demo` 执行，失败只告警 */
    public const DEMO = 'demo';

    /** 无数据填充 */
    public const NONE = 'none';

    /** 未声明时的口径（向后兼容：保持历史「装完即执行」行为） */
    public const DEFAULT_MODE = self::REQUIRED;

    /**
     * 归一 manifest 值。
     *
     * 宽容处理：布尔 `true` → demo（历史写法 `demo_seed: true`），`false` → none；
     * 大小写与空白忽略；无法识别时回落默认值（值错误由 `plugin:audit` 提示，不在这里抛异常）。
     */
    public static function normalize(mixed $raw): string
    {
        if (is_bool($raw)) {
            return $raw ? self::DEMO : self::NONE;
        }

        $mode = strtolower(trim((string) $raw));

        return in_array($mode, [self::REQUIRED, self::DEMO, self::NONE], true)
            ? $mode
            : self::DEFAULT_MODE;
    }

    /**
     * 从 manifest 数据解析（`seed` 优先，兼容历史 `demo_seed` 布尔写法）。
     *
     * @param array<string, mixed>|null $manifest
     */
    public static function fromManifest(?array $manifest): string
    {
        if ($manifest === null) {
            return self::DEFAULT_MODE;
        }

        if (array_key_exists('seed', $manifest)) {
            return self::normalize($manifest['seed']);
        }

        return self::normalize($manifest['demo_seed'] ?? null);
    }

    /**
     * 从磁盘 manifest 解析（升级流程用：此时扫描缓存还是旧清单）。
     *
     * 文件缺失 / JSON 非法时回落默认值：升级不该因为读不到清单而改行为。
     */
    public static function fromManifestFile(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return self::DEFAULT_MODE;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return self::DEFAULT_MODE;
        }

        $data = json_decode($raw, true);

        return self::fromManifest(is_array($data) ? $data : null);
    }

    /**
     * 本次安装/升级是否执行数据填充。
     *
     * `required` 恒执行；`demo` 只在显式 `--with-demo` 时执行；`none` 永不执行。
     */
    public static function shouldRun(string $mode, bool $withDemo = false): bool
    {
        $mode = self::normalize($mode);

        if ($mode === self::REQUIRED) {
            return true;
        }

        return $mode === self::DEMO && $withDemo;
    }

    /**
     * 填充失败是否应阻断安装（回滚 + 抛异常）。
     *
     * 只有「基线数据」失败才阻断：演示数据缺失不影响系统可用性，
     * 而且 `--with-demo` 是显式可选项，失败应降级为告警（方桼�。
     */
    public static function failureIsFatal(string $mode, bool $withDemo = false): bool
    {
        return self::normalize($mode) === self::REQUIRED && !$withDemo;
    }

    /**
     * 人话说明（命令输出 / 日志）。
     */
    public static function label(string $mode): string
    {
        return match (self::normalize($mode)) {
            self::DEMO    => '演示数据（默认跳过，--with-demo 执行）',
            self::NONE    => '无数据填充',
            default       => '基线数据（安装时执行）',
        };
    }
}
