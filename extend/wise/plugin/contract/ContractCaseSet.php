<?php

declare(strict_types=1);

namespace wise\plugin\contract;

use RuntimeException;

/**
 * 契约用例集（随框架包冻结，可显式替换为外部用例集）。
 *
 * ## 为什么是「用例集」而不是「同步代码」
 *
 * 因此本地与服务端共享的是「case id + 级别 + 人话标题」这一层**声明**，
 * 具体执行交给各自实现：
 *  - 本地 `ContractRunner` 按 case id 分派内置检查器（无 DB、无网络）；
 *  - 服务端 CI 按同一批 case id 跑多框架版本矩阵。
 *
 * 这样服务端新增 `contract.*` 用例时，**旧版客户端不会误判「通过」**：不认识的 case id
 * 一律落进 `skipped`，命令输出会明确提示「用例集与服务端版本不一致」。
 *
 * ## 边界（写死）
 *
 * - 只读**文件**，不接受 URL（`plugin:test` 定位是无网络，能离线复现）；
 * - 外部用例集只影响「检查哪些 case」，**不能把 error 降级为 warn**（级别以本地 case 元数据为准，
 *   外部文件只提供 id 清单与版本号，避免被篡改成「全绿」）。
 */
final class ContractCaseSet
{
    /** 内置用例集文件（与引擎同版本随包发布） */
    public const BUILTIN_FILE = __DIR__ . '/contract_cases.json';

    /** 用例集文件大小上限（256KB） */
    private const MAX_BYTES = 262144;

    /** 内置用例集缓存（一次运行只读一次盘） */
    private static ?array $builtin = null;

    /** case id 命名空间约束 */
    public const ID_PATTERN = '/^contract\.[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    /**
     * 加载用例集。
     *
     * @param string|null $source 外部用例集文件路径（null = 内置）
     * @param string|null $sha256 外部用例集的 sha256（提供则强校验）
     *
     * @return array{
     *     version: string,
     *     engine: string,
     *     cases: array<string, array{group:string, level:string, title:string}>,
     *     builtin_version: string,
     *     mismatch: bool
     * }
     *
     * @throws RuntimeException 文件不可读 / JSON 非法 / sha256 不匹配
     */
    public static function load(?string $source = null, ?string $sha256 = null): array
    {
        $builtin = self::builtin();

        $builtinVersion = (string) ($builtin['version'] ?? 'unknown');

        $source = $source === null ? '' : trim($source);

        if ($source === '') {
            return [
                'version'         => $builtinVersion,
                'engine'          => (string) ($builtin['engine'] ?? 'plugin:test/unit'),
                'cases'           => self::normalizeCases($builtin['cases'] ?? []),
                'builtin_version' => $builtinVersion,
                'mismatch'        => false,
            ];
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $source) === 1) {
            throw new RuntimeException('契约用例集只接受本地文件（plugin:test 不联网）：' . $source);
        }

        $raw = self::readFile($source);

        if ($sha256 !== null && trim($sha256) !== '') {
            $actual = hash('sha256', $raw);

            if (!hash_equals(strtolower(trim($sha256)), $actual)) {
                throw new RuntimeException(sprintf('用例集 sha256 不匹配（期望 %s，实际 %s）', trim($sha256), $actual));
            }
        }

        $external = self::decode($raw);
        $version  = (string) ($external['version'] ?? 'unknown');

        $builtinCases  = self::normalizeCases($builtin['cases'] ?? []);
        $externalCases = self::normalizeCases($external['cases'] ?? []);

        $cases = [];

        foreach ($externalCases as $id => $meta) {
            $cases[$id] = $builtinCases[$id] ?? $meta;
        }

        return [
            'version'         => $version,
            'engine'          => (string) ($external['engine'] ?? 'plugin:test/unit'),
            'cases'           => $cases,
            'builtin_version' => $builtinVersion,
            'mismatch'        => $version !== $builtinVersion,
        ];
    }

    /**
     * 内置用例集元数据（`--show-cases` 用）。
     *
     * @return array<string, array{group:string, level:string, title:string}>
     */
    public static function metadata(): array
    {
        return self::load()['cases'];
    }

    /**
     * 内置用例集（一次运行只读一次盘：`publish` 预检与 `--show-cases` 会重复取用）。
     *
     * @return array<string, mixed>
     */
    protected static function builtin(): array
    {
        return self::$builtin ??= self::decode(self::readFile(self::BUILTIN_FILE));
    }

    protected static function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('用例集文件不存在或不可读：' . $path);
        }

        $size = (int) @filesize($path);

        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException(sprintf('用例集大小异常（%d 字节）：%s', $size, $path));
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('用例集读取失败：' . $path);
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException('用例集不是合法 JSON：' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * 归一 case 列表：非法项直接丢弃（宁可少检查，也不因脏数据把命令打崩）。
     *
     * @param mixed $cases
     *
     * @return array<string, array{group:string, level:string, title:string}>
     */
    protected static function normalizeCases($cases): array
    {
        if (!is_array($cases)) {
            return [];
        }

        $out = [];

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $id = trim((string) ($case['id'] ?? ''));

            if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }

            $level = strtolower(trim((string) ($case['level'] ?? 'error')));

            $out[$id] = [
                'group' => (string) ($case['group'] ?? 'general'),
                'level' => $level === 'warn' ? 'warn' : 'error',
                'title' => (string) ($case['title'] ?? $id),
            ];
        }

        return $out;
    }
}
