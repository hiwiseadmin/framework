<?php
declare(strict_types=1);

namespace plugin\match3game\controller;

use wise\member\controller\web\MemberFrontController;

/**
 * 消消乐插件 · 会员中心门户控制器（P3-5 示例：`mode=portal` 落地页）
 *
 * 与 demo 插件的会员控制器（{@see \plugin\demo\controller\Member}）形成对照：
 *
 * | | demo（embed） | match3game（portal） |
 * |---|---|---|
 * | 页面承载 | 插件自带模板 + 业务逻辑（成就列表） | **落在会员中心之外的独立前端** |
 * | 控制器职责 | `renderMember()` 渲染自己的业务页 | `renderPortal()` 渲染**跳转卡片**，自身不承载游戏 |
 * | 模板 | `plugins/demo/view/member/achievements.html` | **不需要**（用主题的 `portal.html`） |
 *
 * ── 为什么 portal 形态只需要一行 ──
 * `renderPortal()` 由 wise-member 基类提供：它复用导航装配结果（同一套
 * 停用/场景/依赖/可达性规则）拼出门户数据，再用主题的 `portal.html` 渲染，
 * 因此插件**零模板**即得到一张与会员中心风格一致的落地页。
 * 想自定义这张卡片时，提供自己的 `plugins/match3game/view/portal.html` 即可覆盖（P3-2 回退链）。
 *
 * 本页**不读游戏数据、不调游戏服务** —— 门户形态的边界就是「只做入口」，
 * 游戏侧的数据由游戏前端经插件既有 API 自行处理。
 *
 * 路由由 BootServiceProvider 经 ExtPoints::ROUTE_MEMBER 投递（相对前缀 'match3game'，
 * 应用名 member 已剥离），实际 URL：GET /member/match3game（由 RequireMemberAuth 保护）。
 */
class Member extends MemberFrontController
{
    /**
     * 消消乐门户落地页
     *
     * 卡片按钮指向 `config('match3game.member.portal_url')`；
     * 未配置时本页展示提示文案（`portal.available = false`），**不会渲染空链接**。
     */
    public function portal()
    {
        return $this->renderPortal('match3game:play', [
            'intro' => '本页由 match3game 插件提供：门户形态下会员中心只放「跳转卡片」，'
                . '游戏页面由独立部署的游戏前端承载（地址由运营配置）。',
        ]);
    }
}
