<?php
declare(strict_types=1);

namespace app\common\service;

use think\App;
use wise\support\contracts\PackageInstallable;

/**
 * 标准 Composer 包的「一次性装/卸副作用」执行器
 *
 * 从包声明的 `extra.think.services` 中挑出实现了 {@see PackageInstallable} 的类并调用：
 *   - `install()`：`wise:package:install` 在「迁移 + seeds 之后、菜单同步之前」调用；
 *   - `uninstall()`：`wise:package:uninstall`（由 compose `pre-package-uninstall` 触发，
 *     此时包文件仍在）在框架清理**之前**调用。
 *
 * ## 为什么用反射发现，而不是新增 composer 字段
 *
 * 包已经有 `extra.think.services` 这个唯一入口，再引入 `extra.think.installable` 只会
 * 造出第二个真相来源。用接口 + 反射发现，作者少记一个字段，宿主也不会漏读。
 *
 * ## 实例化
 *
 * 依次尝试：容器解析 → `new $class($app)` → `new $class()`。
 * `think\Service` 子类三种写法都存在，这里做兼容；失败只记错误，不影响其它包。
 */
final class PackageLifecycle
{
    private ?App $app;

    public function __construct(?App $app = null)
    {
        $this->app = $app;
    }

    /**
     * 反射判定包内实现了 PackageInstallable 的服务类（**不实例化**）
     *
     * 供 `wise:package:list` / `--dry-run` 使用：只看类是否实现接口，
     * 不会执行任何构造逻辑。
     *
     * @param  array<string, mixed> $meta PackageInspector 的元数据
     * @return string[]
     */
    public function detect(array $meta): array
    {
        if (!interface_exists(PackageInstallable::class)) {
            return [];
        }

        $found = [];

        foreach ((array) ($meta['services'] ?? []) as $class) {
            if (!is_string($class) || trim($class) === '') {
                continue;
            }

            $class = trim($class);

            if (!class_exists($class)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($class);
            } catch (\ReflectionException $e) {
                continue;
            }

            if ($reflection->isAbstract() || !$reflection->implementsInterface(PackageInstallable::class)) {
                continue;
            }

            $found[] = $class;
        }

        return array_values(array_unique($found));
    }

    /**
     * 调用包声明的 install()
     *
     * @param  array<string, mixed> $meta
     * @return array{called: string[], errors: array<string, string>}
     */
    public function install(array $meta): array
    {
        return $this->invoke($meta, 'install');
    }

    /**
     * 调用包声明的 uninstall()
     *
     * @param  array<string, mixed> $meta
     * @return array{called: string[], errors: array<string, string>}
     */
    public function uninstall(array $meta): array
    {
        return $this->invoke($meta, 'uninstall');
    }

    /**
     * @param  array<string, mixed> $meta
     * @return array{called: string[], errors: array<string, string>}
     */
    private function invoke(array $meta, string $method): array
    {
        $called = [];
        $errors = [];

        foreach ($this->detect($meta) as $class) {
            try {
                $instance = $this->make($class);
                $instance->{$method}();
                $called[] = $class;
            } catch (\Throwable $e) {
                $errors[$class] = $e->getMessage();
            }
        }

        return ['called' => $called, 'errors' => $errors];
    }

    /**
     * 实例化服务类（兼容容器注入 / 显式 App / 无参构造）
     *
     * @throws \RuntimeException 三种方式都失败，或类未实现契约
     */
    private function make(string $class): PackageInstallable
    {
        $instance = null;

        try {
            $instance = ($this->app ?? app())->make($class);
        } catch (\Throwable $e) {
        }

        if (!$instance instanceof PackageInstallable) {
            try {
                $instance = new $class($this->app ?? app());
            } catch (\Throwable $e) {
                try {
                    $instance = new $class();
                } catch (\Throwable $e2) {
                    throw new \RuntimeException(sprintf(
                        '无法实例化 %s（%s）',
                        $class,
                        $e2->getMessage()
                    ));
                }
            }
        }

        if (!$instance instanceof PackageInstallable) {
            throw new \RuntimeException(sprintf('%s 未实现 %s', $class, PackageInstallable::class));
        }

        return $instance;
    }
}
