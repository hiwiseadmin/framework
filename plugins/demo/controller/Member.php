<?php
declare(strict_types=1);

namespace plugin\demo\controller;

use wise\member\controller\web\MemberFrontController;

/**
 * demo 插件 · 会员前台页面控制器（出口① 会员本人 Web）
 *
 * 继承 wise-member 的会员前台基类即可自动获得：
 *  - 会员上下文与场景解析（scene / features）；
 *  - 会员中心导航契约（nav / nav_groups / nav_sections）；
 *  - 前台基址（web_base）与站点文案（texts / site）；
 *  - **视图 scope 解析与主题回退链**（P3-1 / P3-2）。
 *
 * ── 模板解析（P3-1 起）──
 * 本类位于 `plugin\demo\controller\`，基类据**命名空间自动识别 scope = demo**，
 * 因此模板放 `plugins/demo/view/` 即可被渲染，**无需手动切换 view_path**。
 * 解析优先级（逐模板判定）：
 *   1. `plugins/demo/theme/{theme}/view/{page}.html` —— 插件主题覆盖
 *   2. `plugins/demo/view/{page}.html`              —— 插件自有模板（本页走这一条）
 *   3. 插件未提供该页面 → 回退主包主题 / default（不白屏）
 *
 * 路由由 BootServiceProvider 经 ExtPoints::ROUTE_MEMBER 投递（**相对前缀** 'demo'，
 * 应用名 member 已由 think-multi-app 剥离），实际 URL：
 *     GET /member/demo/achievements
 * 由 RequireMemberAuth 保护（未登录返回 401）。
 */
class Member extends MemberFrontController
{
    /**
     * 我的成就（示例页）
     */
    public function achievements()
    {
        return $this->renderMember('member/achievements', [
            'member' => $this->request->member ?? null,
            'intro'  => '本页由 demo 插件提供：模板在 plugins/demo/view/member/achievements.html，'
                . '由会员前台基类按视图 scope 自动解析（未切换 view_path、未改动会员中心任何代码）。',
            'items'  => $this->sampleAchievements(),
        ]);
    }

    /**
     * 示例成就数据
     *
     * 真实插件应从自己的 service / model 取数，并在前端按需分页；
     * 此处仅为演示契约装配与页面渲染。
     *
     * @return array<int, array{name:string, desc:string, done:bool}>
     */
    protected function sampleAchievements(): array
    {
        return [
            ['name' => '首次登录', 'desc' => '完成一次登录', 'done' => true],
            ['name' => '连续签到 7 天', 'desc' => '连续签到 7 天', 'done' => true],
            ['name' => '完成 10 个任务', 'desc' => '累计完成 10 个任务', 'done' => false],
        ];
    }
}
