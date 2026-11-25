<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 权限同步注册表 —— 宿主侧统一驱动各包 / 插件的权限同步提供者
 *
 * 解耦点
 * ------
 * 宿主（安装期权限初始化、后台「更新权限列表」）不感知任何具体包，只按扩展点
 * {@see \wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER} 遍历实现并调用其 sync()。
 * 包在自身 ServiceProvider::boot() 阶段注册实现，卸载即从结果中消失 ——
 * 因此框架代码中不出现任何具体包名。
 *
 * 实现约定
 * --------
 * 扩展点实现可以是「类名字符串」（走容器解析）或对象，需提供：
 *
 *     sync(bool $dryRun = false, bool $prune = false): array
 *
 * 返回数组至少包含 created / updated 计数键（可选 deleted / errors）。
 * 任何拥有自有权限注册器的包 / 插件均可通过该扩展点接入，示例见各包
 * ServiceProvider::boot() 中的实现注册。
 *
 * @package app\common\service
 */
class PermissionSyncRegistry
{
    /**
     * 遍历已注册的权限同步提供者并汇总结果
     *
     * @param bool $dryRun 仅预览，不写库
     * @param bool $prune  允许提供者清理自身已废弃的权限
     * @return array{providers:int, created:int, updated:int, deleted:int, details:array<string,array>, errors:array<int,string>}
     */
    public static function run(bool $dryRun = false, bool $prune = false): array
    {
        $summary = [
            'providers' => 0,
            'created'   => 0,
            'updated'   => 0,
            'deleted'   => 0,
            'details'   => [],
            'errors'    => [],
        ];

        foreach (self::providers() as $name => $provider) {
            if (!($provider['active'] ?? true)) {
                continue;
            }

            $instance = self::instantiate($provider['concrete'] ?? null);

            if ($instance === null || !method_exists($instance, 'sync')) {
                continue;
            }

            try {
                $result = (array) $instance->sync($dryRun, $prune);

                $created = (int) ($result['created'] ?? 0);
                $updated = (int) ($result['updated'] ?? 0);
                $deleted = (int) ($result['deleted'] ?? 0);

                $summary['providers']++;
                $summary['created'] += $created;
                $summary['updated'] += $updated;
                $summary['deleted'] += $deleted;
                $summary['details'][(string) $name] = [
                    'created' => $created,
                    'updated' => $updated,
                    'deleted' => $deleted,
                    'errors'  => (array) ($result['errors'] ?? []),
                ];

                foreach ((array) ($result['errors'] ?? []) as $err) {
                    $summary['errors'][] = $name . ': ' . (string) $err;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = $name . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * 扩展点下的权限同步提供者清单（按优先级排序）
     *
     * 首次访问时防御式 definePoint（重复定义不抛异常），
     * 因此与各包的启动顺序无关。
     *
     * @return array<string, array>
     */
    public static function providers(): array
    {
        if (!class_exists(\wise\support\ExtPoint::class) || !class_exists(\wise\support\ExtPoints::class)) {
            return [];
        }

        try {
            if (!\wise\support\ExtPoint::defined(\wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER)) {
                \wise\support\ExtPoint::definePoint(
                    \wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER,
                    \wise\support\ExtPoint::POLICY_MULTI
                );
            }

            return \wise\support\ExtPoint::all(\wise\support\ExtPoints::PERMISSION_SYNC_PROVIDER);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 把扩展点实现解析为可调用 sync() 的对象
     */
    protected static function instantiate(mixed $concrete): ?object
    {
        if (is_object($concrete)) {
            return $concrete;
        }

        if (is_string($concrete) && class_exists($concrete)) {
            try {
                return app($concrete);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
