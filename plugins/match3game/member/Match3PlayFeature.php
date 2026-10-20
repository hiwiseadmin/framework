<?php
declare(strict_types=1);

namespace plugin\match3game\member;

use wise\member\contract\AbstractMemberFeature;

/**
 * match3game 插件 · 会员中心入口「消消乐」（第二接入样例：独立门户形态）
 *
 * 与 demo 插件（{@see \plugin\demo\member\AchievementsFeature}）形成两种形态对照：
 *
 * | | demo | match3game |
 * |---|---|---|
 * | 形态 | embed（会员中心内的一个功能区） | **portal（独立门户，会员中心只放入口）** |
 * | 页面 | 插件自带模板，由会员中心壳渲染 | 游戏前端（Cocos 构建产物，独立部署） |
 * | URL | 由会员前台基址推导（`/member/demo/achievements`） | **由运营配置**（游戏前端地址） |
 * | 资源 | 插件自有前缀 | 声明 `assets()`（`/statics/plugins/match3game`） |
 *
 * ── 为什么 URL 来自配置 ──
 * 游戏前端是独立构建产物（仓库根 `match3game/`，Cocos 工程），不随 PHP 应用发布，
 * 因此入口地址属**部署/运营配置项**，不能硬编码在插件代码里。
 *
 * ── 未配置时的行为（契约约定，非缺陷）──
 * `url()` 返回空串 → 装配期判定「入口不可达」→ **该入口不渲染并记录告警**
 * （见 registry\FeatureDependencyValidator 的可达性校验），绝不会渲染出空链接。
 * 配置 `config('match3game.member.portal_url')` 后入口即出现。
 *
 * 参考文档：docs/wise-member-插件接入会员中心指南.md
 */
final class Match3PlayFeature extends AbstractMemberFeature
{
    /** 游戏静态资源发布前缀（插件 statics/ 由框架发布到此目录） */
    public const ASSET_PREFIX = '/statics/plugins/match3game';

    /**
     * 功能标识（全局唯一，`{插件}:{功能}`）
     */
    public function code(): string
    {
        return 'match3game:play';
    }

    /**
     * 会员中心入口名称
     */
    public function name(): string
    {
        return '消消乐';
    }

    /**
     * 游戏前端入口（运营配置，未配置则返回空串 → 入口不渲染）
     */
    public function url(): string
    {
        $url = config('match3game.member.portal_url', '');

        return is_string($url) ? trim($url) : '';
    }

    /**
     * 图标（Bootstrap Icons，与插件 manifest 的图标一致）
     */
    public function icon(): string
    {
        return 'bi bi-grid-3x3-gap';
    }

    /**
     * 形态：独立门户 —— 会员中心只放入口，不承载游戏页面
     */
    public function mode(): string
    {
        return self::MODE_PORTAL;
    }

    /**
     * 前端资源前缀（供插件页/主题消费，避免各处硬编码发布路径）
     */
    public function assets(): ?string
    {
        return self::ASSET_PREFIX;
    }

    /**
     * 分组：内容（游戏本体属内容消费类入口）
     */
    public function group(): string
    {
        return self::GROUP_CONTENT;
    }

    /**
     * 排序（官方功能为 10~90，第三方默认 100；此处取 93 排在官方功能之后）
     */
    public function sort(): int
    {
        return 93;
    }

    // ── 按需覆写（本样例未用到，指南有完整说明）──
    // requires()  ：依赖的其它功能（如结算依赖会员钱包 → ['wise-member:wallet']）
    // conflicts() ：互斥功能
    // scenes()    ：限定的会员场景（仅在确知运营场景码时声明，否则会永不显示）
    // adminPages()：本功能自带的会员后台页（实现 AdminPageProvider）
}
