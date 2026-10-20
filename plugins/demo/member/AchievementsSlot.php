<?php
declare(strict_types=1);

namespace plugin\demo\member;

use wise\member\contract\CenterEntryProvider;
use wise\member\feature\Concerns\ResolvesMemberWebUrl;

/**
 * demo 插件 · 会员中心插槽内容（P4-1 示例：内容 ≠ 入口）
 *
 * 与 {@see AchievementsFeature} 的对照：
 *
 * | | MEMBER_FEATURE（成就功能） | MEMBER_CENTER_ENTRY（本类） |
 * |---|---|---|
 * | 表达 | 一个**功能入口**：进导航、可在能力市场停用 | 一段**内容**：只渲染，不进导航 |
 * | 停用影响 | 入口消失（功能不可达） | 与功能启停无关，内容照常展示 |
 * | 排序 | `sort()` + 分组 | `position` + `sort` |
 *
 * ── 为什么要分成两个扩展点 ──
 * 若把公告/活动条这类内容塞进 `MEMBER_FEATURE`，运营停用「成就」功能时会把
 * 「成就速览」当成功能一起停掉 —— 但那段内容本身仍然成立。二者生命周期不同，
 * 必须分开（见指南 §4.5）。
 *
 * ── URL 不硬编码 ──
 * 入口地址用会员前台基址解析 trait 拼（与 AchievementsFeature 同一规则），
 * 宿主把 `/member` 前缀改掉时这里自动跟随。
 */
final class AchievementsSlot implements CenterEntryProvider
{
    use ResolvesMemberWebUrl;

    /**
     * 插槽内容（字段契约见 {@see CenterEntryProvider}）
     */
    public function entry(): array
    {
        return [
            'position' => 'center.top',
            'code'     => 'demo:achievements-card',
            'title'    => '成就速览',
            'content'  => '你已解锁 2 项成就，去看看还有哪些可以拿 →',
            'url'      => $this->web('/demo/achievements'),
            'icon'     => 'bi bi-trophy',
            // 同位置内靠前（官方内容通常用 100 以内）
            'sort'     => 50,
        ];
    }
}
