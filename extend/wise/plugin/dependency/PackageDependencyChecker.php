<?php

declare(strict_types=1);

namespace wise\plugin\dependency;

use wise\plugin\scanner\PluginMetadata;

/**
 * 库包依赖检查器 —— 插件 ↔ composer 库包
 *
 * 与 {@see DependencyChecker} 的分工：
 *   - `DependencyChecker`：插件 ↔ 插件（`manifest.dependencies`）；
 *   - 本类：插件 ↔ **composer 库包**（`manifest.packages`，如 `wiseadmin/wise-member`）。
 *
 * ## 为什么需要
 *
 * `src/packages/wiseadmin/wise-*` 是**可选安装**的库包。插件对它们的耦合有强弱之分：
 *   - **硬依赖**：继承基类（`extends MemberFrontController`）、中间件数组里写类名
 *     （`\wise\member\middleware\JwtAuth::class`）—— 加载/路由解析即致命错误；
 *   - **软依赖**：只在一个模型关联（`belongsTo(Member::class)`）或带 `class_exists`
 *     守卫的服务里引用 —— 仅相关页面降级或报错，插件主体仍可用。
 *
 * 硬依赖缺失必须**阻止加载**（否则请求 500），软依赖只应**诊断提示**（不该因此停用整个插件），
 * 因此 manifest 支持两档声明：
 *
 * ```json
 * "packages": {
 *     "wiseadmin/wise-member": "*",                                        // 简写 = 硬依赖
 *     "wiseadmin/wise-oauth": { "constraint": "^1.0", "required": false }  // 软依赖
 * }
 * ```
 *
 * ## 判定依据
 *
 * `vendor/composer/installed.json`（包是否仍在**安装集合**里）—— 与
 * `wise:package:cleanup` 的卸载判定同源，避免"源码目录还在（path 仓库软链已删）
 * 就以为还装着"的误判。
 *
 * 该文件不可读时**一律视为可用（fail-open）**：宁可漏拦，不可误拦。
 */
class PackageDependencyChecker
{
    /**
     * 已安装包（包名 => 版本）；null = 无法判定（文件缺失/非法 JSON）
     *
     * @var array<string, string>|null
     */
    protected ?array $installed = null;

    public function __construct(protected ?string $installedFile = null)
    {
    }

    /**
     * installed.json 路径
     */
    public function installedFile(): string
    {
        if ($this->installedFile !== null && $this->installedFile !== '') {
            return $this->installedFile;
        }

        return rtrim(app()->getRootPath(), '/\\')
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json';
    }

    /**
     * 已安装包集合（包名 => 版本）
     *
     * @return array<string, string>|null null = 无法判定
     */
    public function installedPackages(): ?array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $file = $this->installedFile();
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }

        // Composer 2.1+：{packages: [...]}；旧版：顶层数组
        $packages = isset($data['packages']) && is_array($data['packages'])
            ? $data['packages']
            : array_values(array_filter($data, 'is_array'));

        $result = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['name'])) {
                continue;
            }

            $result[(string) $package['name']] = (string) ($package['version'] ?? '');
        }

        return $this->installed = $result;
    }

    /**
     * 包是否可用（未安装 → false；无法判定 → true）
     */
    public function isAvailable(string $package): bool
    {
        $installed = $this->installedPackages();

        if ($installed === null) {
            return true;
        }

        return isset($installed[$package]);
    }

    /**
     * 全部缺失的库包依赖（含软依赖，供诊断展示）
     *
     * @return array<string, array{package:string, constraint:string, required:bool, version:?string, message:string}>
     */
    public function missing(PluginMetadata $metadata): array
    {
        if (!$metadata->hasPackages()) {
            return [];
        }

        $installed = $this->installedPackages();
        $result    = [];

        foreach ($metadata->getPackages() as $package => $spec) {
            $required   = (bool) $spec['required'];
            $constraint = (string) $spec['constraint'];

            if ($installed === null) {
                continue; // 无法判定 → fail-open
            }

            $version = $installed[$package] ?? null;

            if ($version === null) {
                $result[$package] = [
                    'package'    => $package,
                    'constraint' => $constraint,
                    'required'   => $required,
                    'version'    => null,
                    'message'    => $required
                        ? sprintf('库包 %s 未安装（硬依赖，插件将不加载）', $package)
                        : sprintf('库包 %s 未安装（软依赖，相关功能降级）', $package),
                ];
                continue;
            }

            if (!$this->satisfies($version, $constraint)) {
                $result[$package] = [
                    'package'    => $package,
                    'constraint' => $constraint,
                    'required'   => $required,
                    'version'    => $version,
                    'message'    => sprintf(
                        '库包 %s 版本不满足：需要 %s，已安装 %s',
                        $package,
                        $constraint,
                        $version
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * 缺失的**硬依赖**（= 应阻止插件加载/启用的集合）
     *
     * @return array<string, array{package:string, constraint:string, required:bool, version:?string, message:string}>
     */
    public function missingRequired(PluginMetadata $metadata): array
    {
        return array_filter(
            $this->missing($metadata),
            static fn (array $item): bool => (bool) $item['required']
        );
    }

    /**
     * 版本约束校验
     *
     * 有 composer/semver 时严格判定；否则只支持常见比较运算符，
     * **复杂约束（^ / ~ / 范围）一律放行**（fail-open，避免误拦）。
     */
    public function satisfies(string $actual, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (class_exists(\Composer\Semver\Semver::class)) {
            try {
                return (bool) \Composer\Semver\Semver::satisfies($actual, $constraint);
            } catch (\Throwable $e) {
                // 落到下面的保守判定
            }
        }

        foreach (['>=', '<=', '!=', '>', '<', '='] as $operator) {
            if (str_starts_with($constraint, $operator)) {
                $target = trim(substr($constraint, strlen($operator)));

                return $target !== '' && version_compare($actual, $target, $operator);
            }
        }

        return true;
    }
}
