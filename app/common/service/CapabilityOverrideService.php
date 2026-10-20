<?php
declare(strict_types=1);

namespace app\common\service;

use wise\config\facade\WiseConfig;

/**
 * 能力活跃状态覆盖存储服务
 *
 * 负责将 ExtPoint 的 active 覆盖表持久化到 wise-config（数据库 + 缓存），
 * 使能力市场中的「设为活跃 / 停用」操作跨请求生效。
 *
 * 数据结构：
 *   [ point => [ name => bool ] ]
 *   例如 [ 'example.single' => ['alpha' => true, 'beta' => false] ]
 *
 * 集中封装读写，后续如需更换持久化方案（独立配置表、Redis 等），
 * 只需修改本类，ExtPoint 与控制器无需感知。
 */
class CapabilityOverrideService
{
    /** 配置命名空间 */
    public const NAMESPACE = 'system';

    /** 配置分组 */
    public const GROUP = 'capability';

    /** 配置键名 */
    public const KEY = 'active_overrides';

    /**
     * 读取全部活跃覆盖表
     *
     * 存储不可用时（如未迁移、数据库异常）降级为空覆盖，
     * 保证 ExtPoint 注册流程不受影响。
     *
     * @return array<string, array<string, bool>>
     */
    public static function load(): array
    {
        try {
            $data = WiseConfig::namespace(self::NAMESPACE)
                ->get(self::GROUP . ':' . self::KEY, []);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            // 数据库/缓存不可用时降级为空覆盖
            return [];
        }
    }

    /**
     * 保存全部活跃覆盖表
     *
     * @param array<string, array<string, bool>> $overrides 覆盖表
     * @return bool 是否保存成功
     */
    public static function save(array $overrides): bool
    {
        try {
            // 仅保留合法的 point => [name => bool] 结构
            $clean = array_filter($overrides, static fn ($value) => is_array($value));

            return WiseConfig::namespace(self::NAMESPACE)->set(
                self::GROUP . ':' . self::KEY,
                $clean,
                [
                    'type'        => 'json',
                    'group'       => self::GROUP,
                    'title'       => '能力活跃状态覆盖',
                    'description' => '扩展点各实现的活跃状态持久化（由能力市场管理）',
                    // 仅供能力市场内部私有使用，不向系统配置页面展示
                    'visibility'  => 0,
                ]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
