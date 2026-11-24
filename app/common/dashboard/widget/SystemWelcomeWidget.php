<?php
declare(strict_types=1);

namespace app\common\dashboard\widget;

use app\admin\facade\CurrentAdmin;
use plugin\contract\DashboardWidgetInterface;

/**
 * 内置：欢迎问候卡（category=html）
 *
 * @package app\common\dashboard\widget
 */
class SystemWelcomeWidget implements DashboardWidgetInterface
{
    public function getKey(): string
    {
        return 'system.welcome';
    }

    public function getTitle(): string
    {
        return '欢迎';
    }

    public function getDescription(): string
    {
        return '当前管理员问候与日期信息';
    }

    public function getIcon(): array
    {
        return ['type' => 'bi', 'value' => 'bi bi-stars', 'color' => ''];
    }

    public function getCategory(): string
    {
        return 'html';
    }

    public function getDefaultSize(): array
    {
        return ['w' => 4, 'h' => 2];
    }

    public function getMinSize(): array
    {
        return ['w' => 2, 'h' => 1];
    }

    public function getSuggestedAccent(): string
    {
        return 'brand';
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
            $nickname = CurrentAdmin::getNickname();
        } catch (\Throwable $e) {
            $nickname = '';
        }
        $weekMap = ['日', '一', '二', '三', '四', '五', '六'];
        return [
            'nickname' => $nickname,
            'date'     => date('Y年m月d日'),
            'week'     => '星期' . $weekMap[(int) date('w')],
            'time'     => date('H:i'),
        ];
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
