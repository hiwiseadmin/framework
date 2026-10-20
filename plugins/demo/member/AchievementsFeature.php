<?php
declare(strict_types=1);

namespace plugin\demo\member;

use wise\member\contract\AbstractMemberFeature;
use wise\member\feature\Concerns\ResolvesMemberWebUrl;

/**
 * demo 插件 · 会员功能「我的成就」（首个三方接入样例）
 *
 * 这是插件接入会员中心的**最小可用形态**：一个契约实现 + 一次注册，
 * 不改动 wise-member 任何代码，即可在会员中心获得入口，并可在能力市场启停。
 *
 * 参考文档：docs/wise-member-插件接入会员中心指南.md
 *
 * ── 注册方式（见 plugins/demo/BootServiceProvider::boot()）──
 * ```php
 * ExtPoint::register(MemberFeatureRegistry::EXT_POINT, 'demo:achievements', AchievementsFeature::class);
 * ```
 * 注册名格式 `{vendor}:{code}`：vendor 为插件标识（用于来源追溯与能力市场分组），
 * code 为功能标识（全局唯一）。
 *
 * ── 关于「隐藏 / 显示」──
 * 插件功能条目的显示条件由三层共同决定，插件**只声明、不运行时改写全局开关**：
 *  1. 本实现在能力市场的 active 状态（停用即入口消失）；
 *  2. `feature()` 声明的场景开关（本功能无独立开关，返回 null）；
 *  3. `scenes()` 限定的会员场景（本功能全场景可见）。
 */
final class AchievementsFeature extends AbstractMemberFeature
{
    /** 提供 webBase() / web()，由基址推导入口 URL（勿硬编码 /member 前缀） */
    use ResolvesMemberWebUrl;

    /**
     * 功能标识（全局唯一，建议 `{插件}:{功能}`）
     */
    public function code(): string
    {
        return 'demo:achievements';
    }

    /**
     * 会员中心导航显示名
     */
    public function name(): string
    {
        return '我的成就';
    }

    /**
     * 入口地址
     *
     * 由基址推导（P1-5 归一后为 /member），**勿硬编码** ——
     * 前缀属宿主可配置项，硬编码会导致配置变更后入口失效。
     */
    public function url(): string
    {
        return $this->web('/demo/achievements');
    }

    /**
     * 图标（Bootstrap Icons）
     */
    public function icon(): string
    {
        return 'bi bi-trophy';
    }

    /**
     * 仅登录后可见（游客时导航条目标记 visible=false，由模板决定是否渲染）
     */
    public function requireAuth(): bool
    {
        return true;
    }

    /**
     * 导航分组：互动
     */
    public function group(): string
    {
        return self::GROUP_SOCIAL;
    }

    /**
     * 排序（官方功能为 10~90，插件默认 100）
     */
    public function sort(): int
    {
        return 88;
    }
}
