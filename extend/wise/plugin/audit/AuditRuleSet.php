<?php

declare(strict_types=1);

namespace wise\plugin\audit;

use RuntimeException;

/**
 * 本地预检规则集（随包冻结的 JSON 规则库，可显式替换为外部规则库）。
 *
 * ## 为什么规则是 JSON 而不是 PHP 常量
 *
 * 规则库需要**构建期快照**：市场（私有包）与服务端审计共用同一套 rule id / 级别 / 文案，
 * 客户端只携带「低敏子集」。规则如果写在 PHP 常量里：
 *   - 无法在构建期与服务端做同一份 JSON 的比对（「预检一致率」没有数据源）；
 *   - 每次调规则都要改代码、走发版；
 *   - 规则的可读性与 diff 都差（一条规则散落在 4 行数组里）。
 *
 * 因此规则元数据与正则统一放在 `rules/local/bundled.json`（构建期由脚本导出，见
 * `scripts/export-plugin-audit-rules.php`），PHP 只保留**rule id 常量**（调用方与测试仍可按常量引用）。
 *
 * ## 边界（不可随意扩大）
 *
 *  - 结构与清单规则是**确定性规则**，可阻断；
 *  - 代码层只提供**低敏正则提示**，一律 `warn`，不阻断；
 *  - AST 深度扫描、人工审核、抄袭比对全部在服务端（`wise-market`，私有包）。
 *
 * 外部规则库只允许覆盖/追加 `code.regex.*`，且**强制降级为 warn** ——
 * 即使规则库被篡改，也不能让本地预检拦住一个其实能上架的包。
 *
 * 外部规则库 JSON 结构：
 * ```json
 * {
 *   "version": "2026.11.1",
 *   "regex_rules": [
 *     {
 *       "id": "code.regex.dangerous_function",
 *       "pattern": "~\\b(eval)\\s*\\(~i",
 *       "message": "检测到 eval( 调用",
 *       "hint": "请改用安全写法"
 *     }
 *   ]
 * }
 * ```
 */
final class AuditRuleSet
{
    /** 内置规则库文件（构建期从服务端导出后打进框架包） */
    private const BUNDLED_FILE = __DIR__ . '/rules/local/bundled.json';

    /** 规则库最大读取体积（1MB） */
    private const MAX_RULESET_BYTES = 1048576;

    /** rule id 命名空间（本地可见的三类；`code.ast.*` 仅服务端） */
    private const ID_PATTERN = '~^(structure|manifest|code\.regex)\.[a-z0-9_]+$~';

    /** 内置规则库缓存（一次运行只读一次盘） */
    private static ?array $bundled = null;

    public const STRUCTURE_MANIFEST_MISSING = 'structure.manifest_missing';

    public const STRUCTURE_MANIFEST_INVALID = 'structure.manifest_invalid';

    public const STRUCTURE_MULTIPLE_ROOTS = 'structure.multiple_roots';

    public const STRUCTURE_UNSAFE_PATH = 'structure.unsafe_path';

    public const STRUCTURE_FORBIDDEN_EXTENSION = 'structure.forbidden_extension';

    public const STRUCTURE_PACKAGE_TOO_LARGE = 'structure.package_too_large';

    public const STRUCTURE_VENDOR_DIR = 'structure.vendor_dir';

    public const STRUCTURE_DIR_NAME_MISMATCH = 'structure.dir_name_mismatch';

    public const MANIFEST_REQUIRED_FIELD = 'manifest.required_field';

    public const MANIFEST_NAME_FORMAT = 'manifest.name_format';

    public const MANIFEST_IDENT_MISMATCH = 'manifest.ident_mismatch';

    public const MANIFEST_VERSION_FORMAT = 'manifest.version_format';

    public const MANIFEST_TITLE_MISSING = 'manifest.title_missing';

    public const MANIFEST_DESCRIPTION_MISSING = 'manifest.description_missing';

    public const MANIFEST_TYPE_INVALID = 'manifest.type_invalid';

    public const MANIFEST_UNKNOWN_FIELD = 'manifest.unknown_field';

    public const MANIFEST_DEVELOP_FLAG = 'manifest.develop_flag';

    public const MANIFEST_SERVICE_PROVIDER_MISSING = 'manifest.service_provider_missing';

    public const MANIFEST_SERVICE_PROVIDER_MISMATCH = 'manifest.service_provider_mismatch';

    public const MANIFEST_DEPENDENCY_INVALID = 'manifest.dependency_invalid';

    public const MANIFEST_DEPENDENCY_SELF = 'manifest.dependency_self';

    public const MANIFEST_PACKAGE_INVALID = 'manifest.package_invalid';

    public const MANIFEST_MENU_INVALID = 'manifest.menu_invalid';

    public const MANIFEST_MENU_DUPLICATE_PATH = 'manifest.menu_duplicate_path';

    public const MANIFEST_MENU_TYPE_UNKNOWN = 'manifest.menu_type_unknown';

    public const MANIFEST_PERMISSION_INVALID = 'manifest.permission_invalid';

    public const MANIFEST_PERMISSION_DUPLICATE = 'manifest.permission_duplicate';

    public const MANIFEST_CONFIG_INVALID = 'manifest.config_invalid';

    public const MANIFEST_CONFIG_TYPE_UNKNOWN = 'manifest.config_type_unknown';

    public const MANIFEST_CONFIG_OPTIONS_MISSING = 'manifest.config_options_missing';

    public const MANIFEST_CONFIG_KEY_RESERVED = 'manifest.config_key_reserved';

    public const MANIFEST_PUBLISH_INVALID = 'manifest.publish_invalid';

    public const MANIFEST_PUBLISH_SOURCE_MISSING = 'manifest.publish_source_missing';

    public const MANIFEST_CAPABILITY_INVALID = 'manifest.capability_invalid';

    public const CODE_DANGEROUS_FUNCTION = 'code.regex.dangerous_function';

    public const CODE_EVAL_CHAIN = 'code.regex.eval_chain';

    public const CODE_SUPERGLOBAL_INCLUDE = 'code.regex.superglobal_include';

    public const CODE_SUPERGLOBAL_EXTRACT = 'code.regex.superglobal_extract';

    public const CODE_SUPERGLOBAL_UNSERIALIZE = 'code.regex.superglobal_unserialize';

    /**
     * 内置规则库版本（来自 JSON，而不是常量）。
     */
    public static function version(): string
    {
        return (string) self::bundled()['version'];
    }

    /**
     * 内置规则库文件绝对路径（构建期导出脚本与 `--show-rules` 用）。
     */
    public static function bundledFile(): string
    {
        return self::BUNDLED_FILE;
    }

    /**
     * 规则元数据（`plugin:audit --show-rules` 与文档的唯一来源）。
     *
     * @return array<string, array{level: string, title: string, why: string}>
     */
    public static function metadata(): array
    {
        return self::bundled()['rules'];
    }

    /**
     * 单条规则元数据；未登记返回 null。
     *
     * @return array{level: string, title: string, why: string}|null
     */
    public static function rule(string $id): ?array
    {
        return self::bundled()['rules'][$id] ?? null;
    }

    /**
     * @return array<int, array{id: string, pattern: string, message: string, hint: string}>
     */
    public static function regexRules(): array
    {
        return self::bundled()['regex'];
    }

    /**
     * 载入规则集（内置，或外部 JSON）。
     *
     * @param string|null $source 本地路径或 http(s) URL
     * @param string|null $sha256 期望的 sha256（可选；提供则强校验）
     *
     * @return array{version: string, regex: array<int, array{id: string, pattern: string, message: string, hint: string}>}
     *
     * @throws RuntimeException 读取失败 / 哈希不匹配 / 结构非法
     */
    public static function load(?string $source, ?string $sha256 = null): array
    {
        $bundled = self::bundled();

        if ($source === null || trim($source) === '') {
            return ['version' => (string) $bundled['version'], 'regex' => array_values($bundled['regex'])];
        }

        $source = trim($source);
        $raw    = self::readSource($source);

        if ($sha256 !== null && trim($sha256) !== '') {
            $actual = hash('sha256', $raw);

            if (!hash_equals(strtolower(trim($sha256)), $actual)) {
                throw new RuntimeException(sprintf(
                    '规则库 sha256 不匹配（期望 %s，实际 %s）',
                    trim($sha256),
                    $actual
                ));
            }
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('规则库不是合法 JSON：' . json_last_error_msg());
        }

        $version = trim((string) ($data['version'] ?? ''));
        if ($version === '') {
            throw new RuntimeException('规则库缺少 version 字段');
        }

        $incoming = $data['regex_rules'] ?? [];
        if (!is_array($incoming)) {
            throw new RuntimeException('规则库 regex_rules 必须是数组');
        }

        $regex = [];

        foreach ($bundled['regex'] as $rule) {
            $regex[$rule['id']] = $rule;
        }

        foreach ($incoming as $index => $rule) {
            if (!is_array($rule)) {
                throw new RuntimeException("regex_rules[{$index}] 必须是对象");
            }

            $id = (string) ($rule['id'] ?? '');

            if (!preg_match('~^code\.regex\.[a-z0-9_]+$~', $id)) {
                throw new RuntimeException("regex_rules[{$index}].id 必须以 code.regex. 开头：{$id}");
            }

            $pattern = (string) ($rule['pattern'] ?? '');

            if ($pattern === '' || @preg_match($pattern, '') === false) {
                throw new RuntimeException("规则 {$id} 的正则表达式非法");
            }

            $regex[$id] = [
                'id'      => $id,
                'pattern' => $pattern,
                'message' => (string) ($rule['message'] ?? '外部规则命中'),
                'hint'    => (string) ($rule['hint'] ?? ''),
            ];
        }

        return ['version' => $version, 'regex' => array_values($regex)];
    }

    /**
     * 清空内置规则库缓存（测试与「规则库热替换」场景用）。
     */
    public static function flushBundledCache(): void
    {
        self::$bundled = null;
    }

    /**
     * 校验并归一一份规则库数据（内置与构建期导出共用）。
     *
     * 之所以公开：`scripts/export-plugin-audit-rules.php` 必须在**写入前**用同一套规则校验，
     * 否则会把一份客户端跑不起来的 JSON 打进包里。
     *
     * @param mixed $data 已 json_decode 的数据（assoc 数组）
     *
     * @return array{
     *     version: string,
     *     rules: array<string, array{level: string, title: string, why: string}>,
     *     regex: array<int, array{id: string, pattern: string, message: string, hint: string}>
     * }
     *
     * @throws RuntimeException 结构/命名空间/级别/正则非法
     */
    public static function validate(mixed $data): array
    {
        if (!is_array($data)) {
            throw new RuntimeException('规则库不是 JSON 对象');
        }

        $version = trim((string) ($data['version'] ?? ''));

        if ($version === '') {
            throw new RuntimeException('规则库缺少 version 字段');
        }

        $rules = self::normalizeRules($data['rules'] ?? []);
        $regex = self::normalizeRegex($data['regex_rules'] ?? [], $rules);

        return ['version' => $version, 'rules' => $rules, 'regex' => $regex];
    }


    /**
     * 读取并校验内置规则库（一次运行只读一次盘）。
     *
     * 校验失败一律抛异常：规则库随包分发，缺失或结构错误说明打包坏了，
     * 继续跑会给出「规则全绿」的假结论，比直接失败更危险。
     *
     * @return array{
     *     version: string,
     *     rules: array<string, array{level: string, title: string, why: string}>,
     *     regex: array<int, array{id: string, pattern: string, message: string, hint: string}>
     * }
     */
    private static function bundled(): array
    {
        if (self::$bundled !== null) {
            return self::$bundled;
        }

        $file = self::BUNDLED_FILE;

        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('内置规则库不存在或不可读：' . $file);
        }

        $raw = (string) @file_get_contents($file);

        if ($raw === '') {
            throw new RuntimeException('内置规则库为空：' . $file);
        }

        if (strlen($raw) > self::MAX_RULESET_BYTES) {
            throw new RuntimeException('内置规则库超过 1MB，拒绝加载：' . $file);
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('内置规则库不是合法 JSON：' . json_last_error_msg());
        }

        return self::$bundled = self::validate($data);
    }

    /**
     * @param mixed $raw
     *
     * @return array<string, array{level: string, title: string, why: string}>
     */
    private static function normalizeRules(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new RuntimeException('内置规则库 rules 必须是非空数组');
        }

        $rules = [];

        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("rules[{$index}] 必须是对象");
            }

            $id = trim((string) ($item['id'] ?? ''));

            if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new RuntimeException("rules[{$index}].id 非法：{$id}");
            }

            if (isset($rules[$id])) {
                throw new RuntimeException('内置规则库存在重复 rule id：' . $id);
            }

            $level = strtolower(trim((string) ($item['level'] ?? '')));

            if (!in_array($level, [AuditIssue::ERROR, AuditIssue::WARN], true)) {
                throw new RuntimeException("规则 {$id} 的 level 非法：{$level}");
            }

            if (str_starts_with($id, 'code.regex.') && $level !== AuditIssue::WARN) {
                throw new RuntimeException("规则 {$id} 必须是 warn（正则无法判定上下文）");
            }

            $title = trim((string) ($item['title'] ?? ''));

            if ($title === '') {
                throw new RuntimeException("规则 {$id} 缺少 title");
            }

            $rules[$id] = [
                'level' => $level,
                'title' => $title,
                'why'   => trim((string) ($item['why'] ?? '')),
            ];
        }

        return $rules;
    }

    /**
     * @param mixed                                            $raw
     * @param array<string, array{level: string, title: string, why: string}> $rules
     *
     * @return array<int, array{id: string, pattern: string, message: string, hint: string}>
     */
    private static function normalizeRegex(mixed $raw, array $rules): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException('内置规则库 regex_rules 必须是数组');
        }

        $regex = [];

        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("regex_rules[{$index}] 必须是对象");
            }

            $id = trim((string) ($item['id'] ?? ''));

            if (preg_match('~^code\.regex\.[a-z0-9_]+$~', $id) !== 1) {
                throw new RuntimeException("regex_rules[{$index}].id 非法：{$id}");
            }

            if (!isset($rules[$id])) {
                throw new RuntimeException("regex_rules 中的 {$id} 未在 rules 中登记");
            }

            $pattern = (string) ($item['pattern'] ?? '');

            if ($pattern === '' || @preg_match($pattern, '') === false) {
                throw new RuntimeException("规则 {$id} 的正则表达式非法");
            }

            $regex[] = [
                'id'      => $id,
                'pattern' => $pattern,
                'message' => (string) ($item['message'] ?? ''),
                'hint'    => (string) ($item['hint'] ?? ''),
            ];
        }

        return $regex;
    }

    private static function readSource(string $source): string
    {
        if (preg_match('~^https?://~i', $source)) {
            $context = stream_context_create([
                'http' => ['timeout' => 10, 'follow_location' => 1, 'max_redirects' => 3],
            ]);

            $raw = @file_get_contents($source, false, $context);

            if ($raw === false) {
                throw new RuntimeException('规则库下载失败：' . $source);
            }
        } else {
            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException('规则库文件不存在或不可读：' . $source);
            }

            $raw = (string) @file_get_contents($source);
        }

        if (strlen($raw) > self::MAX_RULESET_BYTES) {
            throw new RuntimeException('规则库超过 1MB，拒绝加载');
        }

        return $raw;
    }
}
