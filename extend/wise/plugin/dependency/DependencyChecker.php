<?php

declare(strict_types=1);

namespace wise\plugin\dependency;

use think\App;
use wise\plugin\PluginContainerKeys;
use wise\plugin\scanner\PluginScanner;
use wise\plugin\exception\CircularDependencyException;
use wise\plugin\exception\PluginDependencyException;
use wise\plugin\exception\PluginNotFoundException;

/**
 * DependencyChecker 验证插件的依赖关系并检测循环引用。
 *
 * 它检查插件的所有声明依赖是否已安装，以及它们的版本是否满足声明的约束条件。
 * 它还能检测循环依赖，并解析反向依赖链。
 */
class DependencyChecker
{
    /**
     * ThinkPHP 应用实例。
     *
     * @var App
     */
    protected App $app;

    /**
     * 库包依赖检查器（进程内复用，避免重复读取 installed.json）
     *
     * @var PackageDependencyChecker|null
     */
    protected ?PackageDependencyChecker $packageChecker = null;

    /**
     * 创建新的 DependencyChecker 实例。
     *
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 库包依赖检查器
     */
    protected function packageChecker(): PackageDependencyChecker
    {
        return $this->packageChecker ??= new PackageDependencyChecker();
    }

    /**
     * 检查某个插件的所有依赖是否已满足。
     *
     * @param string $name 要检查依赖的插件名称
     * @return bool 如果所有依赖都已满足则返回 true
     * @throws PluginDependencyException 如果任何依赖未满足
     */
    public function checkDependencies(string $name): bool
    {
        $scanner = $this->app->get(PluginContainerKeys::SCANNER);
        $plugins = $scanner->scan();

        if (!isset($plugins[$name])) {
            throw new PluginNotFoundException($name);
        }

        $metadata = $plugins[$name];
        $dependencies = $metadata->getDependencies();
        $missing = [];

        foreach ($dependencies as $depName => $versionConstraint) {
            if (!isset($plugins[$depName])) {
                $missing[] = $depName;
                continue;
            }

            $actualVersion = $plugins[$depName]->getVersion();
            if (!$this->satisfiesVersion($versionConstraint, $actualVersion)) {
                $missing[] = sprintf('%s (requires %s, installed %s)', $depName, $versionConstraint, $actualVersion);
            }
        }

        if (!empty($missing)) {
            throw new PluginDependencyException(
                sprintf('Plugin "%s" has unsatisfied dependencies.', $name),
                $missing
            );
        }

        $missingPackages = $this->packageChecker()->missingRequired($metadata);

        if (!empty($missingPackages)) {
            throw new PluginDependencyException(
                sprintf(
                    '插件 "%s" 依赖的库包未安装：%s',
                    $name,
                    implode('、', array_map(
                        static fn (array $item): string => $item['package'],
                        $missingPackages
                    ))
                ),
                array_map(static fn (array $item): string => $item['message'], $missingPackages)
            );
        }

        return true;
    }

    /**
     * 检查一组插件之间是否存在循环依赖。
     *
     * 使用深度优先遍历来检测环。
     *
     * @param array $names   要检查的插件名称列表
     * @param array $visited 已访问过的插件（用于递归）
     * @return bool 如果未发现循环依赖则返回 true
     * @throws CircularDependencyException 如果检测到循环依赖
     */
    public function checkCircularDependency(array $names, array $visited = []): bool
    {
        $scanner = $this->app->get(PluginContainerKeys::SCANNER);
        $plugins = $scanner->scan();

        return $this->doCheckCircularDependency($names, $visited, $plugins);
    }

    /**
     * 内部递归实现，共享一次扫描结果以避免重复 I/O。
     *
     * @param string[]                                       $names
     * @param string[]                                       $visited
     * @param array<string, \wise\plugin\scanner\PluginMetadata> $plugins
     * @return bool
     * @throws CircularDependencyException
     */
    protected function doCheckCircularDependency(array $names, array $visited, array $plugins): bool
    {
        $visitedMap = array_flip($visited);

        foreach ($names as $name) {
            if (!isset($plugins[$name])) {
                continue;
            }

            if (isset($visitedMap[$name])) {
                $cycle = array_merge($visited, [$name]);
                throw new CircularDependencyException($cycle);
            }

            $dependencies = array_keys($plugins[$name]->getDependencies());
            if (!empty($dependencies)) {
                $newVisited = array_merge($visited, [$name]);
                $this->doCheckCircularDependency($dependencies, $newVisited, $plugins);
            }
        }

        return true;
    }

    /**
     * 解析所有依赖给定插件的插件（反向依赖）。
     *
     * 用于确定哪些插件会受到卸载或禁用给定插件的影响。
     *
     * @param string $name 要查找被依赖者的插件名称
     * @return array 依赖于给定插件的插件名称列表
     */
    public function resolveDependents(string $name): array
    {
        $scanner = $this->app->get(PluginContainerKeys::SCANNER);
        $plugins = $scanner->scan();
        $dependents = [];

        foreach ($plugins as $pluginName => $metadata) {
            $dependencies = $metadata->getDependencies();
            if (array_key_exists($name, $dependencies)) {
                $dependents[] = $pluginName;
            }
        }

        return $dependents;
    }

    /**
     * 检查实际版本是否满足版本约束。
     *
     * 支持简单的比较运算符：>=、<=、>、<、=、!=
     * 同时支持语义化版本范围，如 "^1.0.0" 和 ">=1.0.0"。
     *
     * @param string $required 所需的版本约束（例如 ">=1.0.0"）
     * @param string $actual   实际安装的版本
     * @return bool 如果版本约束已满足则返回 true
     */
    public function satisfiesVersion(string $required, string $actual): bool
    {
        if (class_exists(\Composer\Semver\Semver::class)) {
            try {
                return \Composer\Semver\Semver::satisfies($actual, $required);
            } catch (\Throwable $e) {
            }
        }

        return $this->simpleVersionCompare($required, $actual);
    }

    /**
     * 执行简单的版本比较。
     *
     * 支持运算符：>=、<=、>、<、!=、= 以及裸版本号。
     * 同时支持插入符 (^) 和波浪符 (~) 约束，遵循语义化版本规范：
     *   - ^1.2.3 → >=1.2.3 <2.0.0
     *   - ^0.1.0 → >=0.1.0 <0.2.0
     *   - ^0.0.3 → >=0.0.3 <0.0.4
     *   - ~1.2.3 → >=1.2.3 <1.3.0
     *   - ~0.2.0 → >=0.2.0 <1.0.0（波浪符在 0.x 时允许次版本号递增）
     *
     * @param string $required 所需的版本约束
     * @param string $actual   实际安装的版本
     * @return bool
     */
    protected function simpleVersionCompare(string $required, string $actual): bool
    {
        $required = trim($required);

        if (str_starts_with($required, '^')) {
            $baseVersion = ltrim($required, '^');
            $baseParts = $this->parseVersionParts($baseVersion);

            if ($baseParts[0] !== 0) {
                $upperVersion = ($baseParts[0] + 1) . '.0.0';
            } elseif ($baseParts[1] !== 0) {
                $upperVersion = '0.' . ($baseParts[1] + 1) . '.0';
            } else {
                $upperVersion = '0.0.' . ($baseParts[2] + 1);
            }

            return version_compare($actual, $baseVersion, '>=')
                && version_compare($actual, $upperVersion, '<');
        }

        if (str_starts_with($required, '~')) {
            $baseVersion = ltrim($required, '~');
            $baseParts = $this->parseVersionParts($baseVersion);

            if ($baseParts[0] !== 0 || $baseParts[1] !== 0) {
                $upperVersion = sprintf('%d.%d', $baseParts[0], $baseParts[1] + 1);
            } else {
                $upperVersion = '0.0.1';
            }

            return version_compare($actual, $baseVersion, '>=')
                && version_compare($actual, $upperVersion, '<');
        }

        $operators = ['>=', '<=', '!=', '>', '<', '='];
        foreach ($operators as $op) {
            if (str_starts_with($required, $op)) {
                $version = trim(ltrim($required, $op));
                return version_compare($actual, $version, $op);
            }
        }

        return version_compare($actual, $required, '=');
    }

    /**
     * 将版本号字符串解析为主版本、次版本、补丁版本三个部分。
     *
     * @param string $version 版本号字符串（例如 "1.2.3"）
     * @return int[] [主版本, 次版本, 补丁版本]
     */
    protected function parseVersionParts(string $version): array
    {
        $parts = explode('.', $version);
        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
            (int) ($parts[2] ?? 0),
        ];
    }
}
