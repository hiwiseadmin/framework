<?php

declare(strict_types=1);

namespace plugin\match3game;

use think\facade\Config;
use think\facade\Route;
use wise\member\middleware\RequireMemberAuth;
use wise\plugin\WiseBootServiceProvider;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\context\ContextualLogger;
use plugin\match3game\contract\Match3Task;
use plugin\match3game\contract\Match3Achievement;
use plugin\match3game\handler\Match3AdRewardHandler;
use plugin\match3game\member\Match3PlayFeature;
use plugin\match3game\provider\Match3TaskProvider;

/**
 * 消消乐插件服务提供者
 *
 * register: 合并插件配置（config/*.php → config('match3game.*')）
 * boot:     注册广告奖励 Handler（wise-wx）+ 任务/成就定义（wise-game-task）
 *           + 会员中心入口（wise-member，独立门户形态）
 */
class BootServiceProvider extends WiseBootServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig($this->getPluginIdent());
    }

    public function boot(): void
    {
        parent::boot();

        // 1. 注册激励视频广告奖励 Handler（§4.1）
        $this->registerAdRewardHandler();

        // 2. 注册日常任务 / 成就定义（§4.1）
        $this->registerTasks();

        // 3. 注册会员中心入口（独立门户形态，见会员接入指南）
        $this->registerMemberIntegration();
    }

    /**
     * 注册会员中心入口 + 门户落地页（P1-8 第二接入样例：独立门户形态）
     *
     * 与 demo 插件（embed 形态，会员中心内承载页面）形成对照：
     * 本插件游戏前端是独立构建产物，故会员功能声明 `mode = portal`
     * —— 会员中心**不承载游戏页面**，只放跳转卡片。
     *
     * 入口地址取自 `config('match3game.member.portal_url')`（运营配置）：
     * 未配置时 `url()` 返回空串 → 装配期判定「入口不可达」→ 不渲染 + 告警，
     * 不会渲染出空链接。配置后入口立即出现（无需改代码）。
     *
     * 另投递**可选的**门户落地页 `/member/match3game`（P3-5）：由 `renderPortal()`
     * 用主题模板渲染跳转卡片，插件零模板。两种常见做法二选一：
     *  ① 直达（当前默认）：导航入口直接跳 `portal_url`，不投递落地页；
     *  ② 落地页：把 `url()` 指向 `/member/match3game`，由落地页承接「介绍 + 进入按钮」。
     * 本例两种都提供，便于对照验证（落地页不是必需项，删掉这段投递即为①）。
     */
    protected function registerMemberIntegration(): void
    {
        ExtPoint::register(
            \wise\member\registry\MemberFeatureRegistry::EXT_POINT,
            'match3game:play',
            Match3PlayFeature::class
        );

        // 门户落地页路由：相对前缀 'match3game'（应用名 member 已剥离）→ /member/match3game
        ExtPoint::register(ExtPoints::ROUTE_MEMBER, 'match3game:portal', static function (): void {
            $handler = 'plugin\match3game\controller\Member@portal';

            Route::group('match3game', static function () use ($handler): void {
                Route::get('/', $handler);
                Route::get('index', $handler);
            })->middleware([RequireMemberAuth::class]);
        });
    }

    /**
     * 注册广告奖励 Handler 到 wise-wx
     *
     * wise-wx 已接入运行时（vendor/services.php 含 WxServiceProvider），
     * 通过 config('wise-wx.ad.reward_handler') 注入，AdService 惰性实例化时读取。
     */
    protected function registerAdRewardHandler(): void
    {
        $adServiceClass = \wise\wx\service\AdService::class;
        if (!class_exists($adServiceClass)) {
            return;
        }

        $handlerClass = Match3AdRewardHandler::class;

        // 合并写入全局 wise-wx 配置（保留 ad 节其他项，仅覆盖 reward_handler）
        $wxConfig = Config::get('wise-wx', []);
        $wxConfig['ad'] = array_merge((array) ($wxConfig['ad'] ?? []), [
            'reward_handler' => $handlerClass,
        ]);
        Config::set($wxConfig, 'wise-wx');

        // 兜底：若 AdService 已实例化，直接注入
        try {
            if ($this->app->has($adServiceClass)) {
                $this->app->make($adServiceClass)->setRewardHandler(new $handlerClass());
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning('[match3] 广告 Handler 注入失败: ' . $e->getMessage());
        }
    }

    /**
     * 注册日常任务 / 成就定义（ExtPoint，§4.2）
     *
     * 扩展点 game.task.definition / game.task.achievement 由 wise-game-task
     * 的 GameTaskServiceProvider::bootExtPoints() 定义（POLICY_ROUTED）。
     *
     * ⚠️ 启动顺序：插件由 PluginServiceProvider 在 App::load() 阶段启动，早于
     * wise-game-task 等包服务的 boot()，故此处 register() 时扩展点尚未定义。
     * 该顺序问题由 wise-support\ExtPoint 自行消化：实现照常注册，
     * definePoint() 时补做契约校验，不会中断启动；若实现不符合 TaskInterface
     * 契约，会在 definePoint 阶段以 error_log 明确报出。
     */
    protected function registerTasks(): void
    {
        if (!class_exists(ExtPoint::class)) {
            return;
        }

        foreach (Match3TaskProvider::tasks() as $def) {
            ExtPoint::register('game.task.definition', $def['code'], fn () => new Match3Task($def));
        }

        foreach (Match3TaskProvider::achievements() as $def) {
            ExtPoint::register('game.task.achievement', $def['code'], fn () => new Match3Achievement($def));
        }
    }
}
