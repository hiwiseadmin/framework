<?php
declare(strict_types=1);

namespace wise\plugin\compat;

/**
 * 版本范围解析与判定（运行时侧，`manifest.compatible_with` 专用）。
 *
 * ── 为什么这里要有一份独立实现 ────────────────────────────────────────
 * 市场侧已有 `wise\market\service\VersionConstraint`，但市场包必须能独立安装、
 * **不得引用宿主 `extend/`**（见 market 包 `tests/verify-dependencies.php` 护栏 D）；
 * 运行时侧同样不能反向依赖市场包（市场包可能没装）。
 *
 * 因此范围判定必然有两份实现。防漂移手段不是"共享代码"，而是：
 *   1. 本类逐行对齐市场侧算法（同一套语法表与边界处理）；
 *   2. `scripts/verify-compat-parity.php` 用同一批用例双向比对两个实现，
 *      任一不一致即失败（CI 可跑）。
 *
 * ── 支持语法（与 market VersionConstraint 完全一致）──────────────────
 * | 写法 | 含义 |
 * |---|---|
 * | `` / `*` / `x` | 任意版本 |
 * | `1.2.3` | 精确 |
 * | `1.2.*` / `1.2.x` / `1.*` | 通配区间 |
 * | `^1.2.3` | 兼容（`>=1.2.3 <2.0.0`；`0.x` 锁次版本） |
 * | `~1.2.3` | 补丁级（`>=1.2.3 <1.3.0`；`~1.2` → `<2.0.0`） |
 * | `>=1.2 <2.0` / `>=1.2,<2.0` | 与 |
 * | `^1.0 \| ^2.0` | 或 |
 * | `1.2.3 - 2.0.0` | 闭区间 |
 *
 * ── 失败策略 ──────────────────────────────────────────────────────────
 * `satisfies()` 对非法约束返回 false（fail-closed）：声明写错时宁可拦住安装
 * （作者会立刻看到 `plugin:audit` 的报错并修），也不要"静默放行到不兼容的框架上"。
 */
final class VersionRange
{
    /**
     * 是否合法约束（空串视为「任意」，合法）。
     */
    public static function isValid(string $constraint): bool
    {
        return trim($constraint) === '' || self::parse($constraint) !== null;
    }

    /**
     * 解析为「或 → 与」两层比较器结构。
     *
     * @return array<int, array<int, array{0:string,1:string}>>|null null = 语法非法
     */
    public static function parse(string $constraint): ?array
    {
        $constraint = trim($constraint);

        if ($constraint === '') {
            return [[]];
        }

        $groups = [];

        foreach (preg_split('/\|\|?/', $constraint) ?: [] as $orPart) {
            $orPart = trim($orPart);

            if ($orPart === '') {
                continue;
            }

            $and = self::parseAnd($orPart);

            if ($and === null) {
                return null;
            }

            $groups[] = $and;
        }

        return $groups === [] ? [[]] : $groups;
    }

    /**
     * 版本是否满足约束。
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $groups = self::parse($constraint);

        if ($groups === null) {
            return false;
        }

        $version = self::pad($version);

        foreach ($groups as $and) {
            $hit = true;

            foreach ($and as [$operator, $target]) {
                if (!version_compare($version, $target, $operator)) {
                    $hit = false;
                    break;
                }
            }

            if ($hit) {
                return true;
            }
        }

        return false;
    }


    /**
     * 解析单组「与」表达式。
     *
     * @return array<int, array{0:string,1:string}>|null
     */
    private static function parseAnd(string $part): ?array
    {
        if (preg_match('/^([^\s,]+)\s+-\s+([^\s,]+)$/', $part, $m)) {
            $low  = self::parseVersionToken($m[1]);
            $high = self::parseVersionToken($m[2]);

            if ($low === null || $high === null) {
                return null;
            }

            return [['>=', $low], ['<=', $high]];
        }

        $comparators = [];

        foreach (preg_split('/[\s,]+/', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $expanded = self::expandToken($token);

            if ($expanded === null) {
                return null;
            }

            foreach ($expanded as $comparator) {
                $comparators[] = $comparator;
            }
        }

        return $comparators;
    }

    /**
     * 展开单个 token 为比较器列表。
     *
     * @return array<int, array{0:string,1:string}>|null
     */
    private static function expandToken(string $token): ?array
    {
        $token = trim($token);

        if ($token === '' || in_array(strtolower($token), ['*', 'x'], true)) {
            return [];
        }

        if (preg_match('/^(>=|<=|!=|==|=|>|<)\s*(.+)$/', $token, $m)) {
            $target = self::parseVersionToken($m[2]);

            if ($target === null) {
                return null;
            }

            return [[$m[1] === '=' ? '==' : $m[1], $target]];
        }

        if (str_contains($token, '*') || preg_match('/\.x$/i', $token)) {
            return self::expandWildcard($token);
        }

        if ($token[0] === '~') {
            return self::expandTilde(substr($token, 1));
        }

        if ($token[0] === '^') {
            return self::expandCaret(substr($token, 1));
        }

        $exact = self::parseVersionToken($token);

        if ($exact === null) {
            return null;
        }

        return [['==', $exact]];
    }

    /**
     * 通配展开：`1.2.*` → `>=1.2.0 <1.3.0`
     *
     * @return array<int, array{0:string,1:string}>|null
     */
    private static function expandWildcard(string $token): ?array
    {
        $parts = explode('.', ltrim($token, 'vV'));
        $hit   = null;

        foreach ($parts as $i => $part) {
            if ($part === '*' || strcasecmp($part, 'x') === 0) {
                $hit = $i;
                break;
            }
        }

        if ($hit === null) {
            return null;
        }

        for ($i = $hit; $i < count($parts); $i++) {
            if ($parts[$i] !== '*' && strcasecmp($parts[$i], 'x') !== 0) {
                return null;
            }
        }

        $prefix = array_slice($parts, 0, $hit);

        for ($i = 0; $i < $hit; $i++) {
            if (!ctype_digit((string) $prefix[$i])) {
                return null;
            }
        }

        if ($prefix === []) {
            return [];
        }

        $lower = array_pad($prefix, 3, '0');
        $upper = array_pad($prefix, 3, '0');
        $upper[$hit - 1] = (string) ((int) $upper[$hit - 1] + 1);

        for ($i = $hit; $i < 3; $i++) {
            $upper[$i] = '0';
        }

        return [
            ['>=', implode('.', array_slice($lower, 0, 3))],
            ['<', implode('.', $upper)],
        ];
    }

    /**
     * 波浪号展开（补丁级）：
     *   `~1.2.3` → `>=1.2.3 <1.3.0`；`~1.2` → `>=1.2.0 <2.0.0`
     *
     * @return array<int, array{0:string,1:string}>|null
     */
    private static function expandTilde(string $raw): ?array
    {
        $base = self::parseVersionToken($raw);

        if ($base === null) {
            return null;
        }

        [$nums] = self::splitVersion($base);

        $main = ltrim(trim($raw), 'vV');

        if (($pos = strpos($main, '-')) !== false) {
            $main = substr($main, 0, $pos);
        }

        $written = substr_count($main, '.') + 1;

        $upper = $written >= 3
            ? [$nums[0], (string) ((int) ($nums[1] ?? 0) + 1), '0']
            : [(string) ((int) $nums[0] + 1), '0', '0'];

        return [['>=', $base], ['<', implode('.', $upper)]];
    }

    /**
     * 脱字符展开（兼容）：
     *   `^1.2.3` → `>=1.2.3 <2.0.0`；`^0.3.0` → `>=0.3.0 <0.4.0`；`^0.0.3` → `>=0.0.3 <0.0.4`
     *
     * @return array<int, array{0:string,1:string}>|null
     */
    private static function expandCaret(string $raw): ?array
    {
        $base = self::parseVersionToken($raw);

        if ($base === null) {
            return null;
        }

        [$nums] = self::splitVersion($base);

        $major = (int) $nums[0];
        $minor = (int) ($nums[1] ?? 0);
        $patch = (int) ($nums[2] ?? 0);

        if ($major > 0) {
            $upper = [(string) ($major + 1), '0', '0'];
        } elseif ($minor > 0) {
            $upper = ['0', (string) ($minor + 1), '0'];
        } else {
            $upper = ['0', '0', (string) ($patch + 1)];
        }

        return [['>=', $base], ['<', implode('.', $upper)]];
    }


    /**
     * 校验并归一一个**具体版本**（非约束）。
     *
     * @return string|null 归一后的版本（补齐为 3 段）；null = 不是具体版本
     */
    private static function parseVersionToken(string $token): ?string
    {
        $token = ltrim(trim($token), 'vV');

        if ($token === '' || !preg_match('/^\d+(\.\d+)*(-[0-9A-Za-z.\-]+)?$/', $token)) {
            return null;
        }

        return self::pad($token);
    }

    /**
     * 版本补段：`1.2` → `1.2.0`（`version_compare('1.2','1.2.0')` 不相等）。
     */
    private static function pad(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        if ($version === '') {
            return '0.0.0';
        }

        [$nums, $pre] = self::splitVersion($version);

        foreach ($nums as $num) {
            if (!ctype_digit($num)) {
                return $version;
            }
        }

        while (count($nums) < 3) {
            $nums[] = '0';
        }

        return implode('.', $nums) . ($pre !== '' ? '-' . $pre : '');
    }

    /**
     * 拆分主版本段与预发布段。
     *
     * @return array{0:string[],1:string} [数字段列表, 预发布段（不含 `-`）]
     */
    private static function splitVersion(string $version): array
    {
        $version = ltrim($version, 'vV');
        $pre     = '';

        if (($pos = strpos($version, '-')) !== false) {
            $pre     = substr($version, $pos + 1);
            $version = substr($version, 0, $pos);
        }

        return [explode('.', $version), $pre];
    }
}
