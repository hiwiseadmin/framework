<?php
declare(strict_types=1);

namespace app\common\dashboard\widget;

use app\admin\facade\CurrentAdmin;
use app\admin\model\Menu;
use plugin\contract\DashboardWidgetInterface;
use app\common\service\MenuLauncherService;

/**
 * 内置：快捷入口面板（category=entries）
 *
 * 满足 Dashboard“展示框架和插件的图标快捷入口”的预设定位：
 * 数据 = 控制台（框架功能） + 插件 两组可点击图标入口，来源与后台首页启动台一致。
 *
 * @package app\common\dashboard\widget
 */
class SystemShortcutsWidget implements DashboardWidgetInterface
{
    public function getKey(): string
    {
        return 'system.shortcuts';
    }

    public function getTitle(): string
    {
        return '快捷入口';
    }

    public function getDescription(): string
    {
        return '框架功能与已装插件图标入口';
    }

    public function getIcon(): array
    {
        return ['type' => 'bi', 'value' => 'bi bi-grid-3x3-gap', 'color' => ''];
    }

    public function getCategory(): string
    {
        return 'entries';
    }

    public function getDefaultSize(): array
    {
        return ['w' => 8, 'h' => 3];
    }

    public function getMinSize(): array
    {
        return ['w' => 4, 'h' => 2];
    }

    public function getSuggestedAccent(): string
    {
        return '';
    }

    public function getDefaultConfig(): array
    {
        return [];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function getData(array $config = []): array
    {
        try {
            $menus = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());
        } catch (\Throwable $e) {
            $menus = [];
        }
        return ['groups' => MenuLauncherService::entries($menus)];
    }

    public function getCacheKey(array $config = []): string
    {
        return '';
    }

    public function getCacheTTL(): int
    {
        return 0;
    }

    public function isAccessible(): bool
    {
        return true;
    }
}
