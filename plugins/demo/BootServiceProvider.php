<?php
declare(strict_types=1);

namespace plugin\demo;

use think\facade\Route;
use wise\member\middleware\RequireMemberAuth;
use wise\plugin\WiseBootServiceProvider;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * demo 插件服务提供者
 *
 * 继承 WiseBootServiceProvider 获得完整的 11 步 boot 链：
 *   1. copyStaticFiles    — debug 模式复制静态资源
 *   2. loadConfig         — 加载配置到 Config domain
 *   3. loadCommonFile     — 加载 common.php 公共函数
 *   4. registerNamespace  — 注册 PSR-4 命名空间
 *   5. registerThirdNamespace — 注册第三方命名空间
 *   6. registerCommands   — 注册 CLI 命令
 *   7. loadEventFile      — 加载事件绑定
 *   8. loadMiddleware     — 加载中间件
 *   9. registerRoute      — 注册路由
 *  10. registerProviders  — 注册 DI 绑定
 *  11. registerServices   — 注册子服务提供者
 *
 * 本插件同时演示「插件接入会员中心」——**只需下面两步注册**，
 * 不改动 wise-member 任何代码。参考 docs/wise-member-插件接入会员中心指南.md。
 */
class BootServiceProvider extends WiseBootServiceProvider
{
    public function register(): void
    {
        // 合并插件配置到 Config domain: config('demo.xxx')
        $this->mergeConfig($this->getPluginIdent());

        // 绑定自定义服务
        // $this->app->bind(SomeInterface::class, SomeService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerMemberIntegration();
    }

    /**
     * 注册会员中心接入（插件侧的全部工作）
     *
     * 时机说明：插件在全局中间件 PluginLoader 中 boot，早于 think-multi-app 切换应用
     * （应用路由文件在应用切换后才执行），因此此处注册的 ROUTE_MEMBER 分组一定能被
     * app/member/route/packages.php 的 applyRoutes() 落地。
     *
     * 停用插件 = 本方法不执行 = 入口与路由一并消失（插件级启停）；
     * 停用「能力市场」里的本实现 = 入口消失、路由仍在（装配面开关，见 §1 边界）。
     */
    protected function registerMemberIntegration(): void
    {
        // ── 1) 会员功能入口：与官方功能同一契约、同一注册面 ──
        // 注册名 `{vendor}:{code}`：vendor 为插件标识（来源追溯 + 能力市场分组）。
        ExtPoint::register(
            \wise\member\registry\MemberFeatureRegistry::EXT_POINT,
            'demo:achievements',
            \plugin\demo\member\AchievementsFeature::class
        );

        // ── 2) 会员应用路由：投递到 ExtPoints::ROUTE_MEMBER ──
        //
        // ⚠️ 前缀写**应用内相对前缀** 'demo'（实际 URL /member/demo/achievements）：
        //   应用名 member 已由 think-multi-app 从 pathinfo 剥离，
        //   写成 'member/demo' 会变成 /member/member/demo/** 而永远匹配不到。
        //
        // 另：插件 route/plugin.php 里的路由会被自动包裹 /plugin/{ident} 前缀，
        // 属于插件后台出口，不能用于会员前台；会员前台必须走本扩展点投递。
        ExtPoint::register(ExtPoints::ROUTE_MEMBER, 'demo:achievements', static function (): void {
            Route::group('demo', static function (): void {
                Route::get('achievements', 'plugin\demo\controller\Member@achievements');
            })->middleware([RequireMemberAuth::class]);
        });

        // ── 3) 会员中心插槽内容（P4-1）──
        // 注意与上面的区别：这里注册的是「一段内容」（公告/活动条/入口卡片），
        // 不进导航、不参与功能依赖与停用语义，渲染在中心页的 position 位置。
        ExtPoint::register(
            \wise\member\registry\CenterSlotRegistry::EXT_POINT,
            'demo:achievements-card',
            \plugin\demo\member\AchievementsSlot::class
        );
    }
}
