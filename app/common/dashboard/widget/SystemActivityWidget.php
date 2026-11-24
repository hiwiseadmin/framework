<?php
declare(strict_types=1);

namespace app\common\dashboard\widget;

use app\admin\facade\CurrentAdmin;
use think\facade\Db;
use plugin\contract\DashboardWidgetInterface;

/**
 * 内置：最近登录活动面板（category=list）
 *
 * 数据源：login_log 表（当前管理员最近登录记录）。表不可用时静默返回空列表。
 *
 * @package app\common\dashboard\widget
 */
class SystemActivityWidget implements DashboardWidgetInterface
{
    public function getKey(): string
    {
        return 'system.activity';
    }

    public function getTitle(): string
    {
        return '最近登录';
    }

    public function getDescription(): string
    {
        return '当前管理员最近登录记录';
    }

    public function getIcon(): array
    {
        return ['type' => 'bi', 'value' => 'bi bi-clock-history', 'color' => ''];
    }

    public function getCategory(): string
    {
        return 'list';
    }

    public function getDefaultSize(): array
    {
        return ['w' => 4, 'h' => 3];
    }

    public function getMinSize(): array
    {
        return ['w' => 2, 'h' => 1];
    }

    public function getSuggestedAccent(): string
    {
        return '';
    }

    public function getDefaultConfig(): array
    {
        return ['limit' => 5];
    }

    public function getConfigSchema(): array
    {
        return [
            'limit' => [
                'type'    => 'number',
                'title'   => '显示条数',
                'default' => 5,
                'options' => [],
            ],
        ];
    }

    public function getData(array $config = []): array
    {
        $adminId = 0;
        try {
            $adminId = (int) CurrentAdmin::getAdminId();
        } catch (\Throwable $e) {
        }
        if ($adminId <= 0) {
            return ['items' => []];
        }

        $limit = max(1, min(20, (int) ($config['limit'] ?? 5)));
        try {
            $rows = Db::name('login_log')
                ->where('admin_id', $adminId)
                ->where('status', 1)
                ->field('login_time, login_ip, location, browser, os')
                ->order('login_time', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'time'     => $row['login_time'] ?? '',
                    'ip'       => $row['login_ip'] ?? '',
                    'location' => $row['location'] ?? '',
                    'browser'  => $row['browser'] ?? '',
                    'os'       => $row['os'] ?? '',
                ];
            }
            return ['items' => $items];
        } catch (\Throwable $e) {
            return ['items' => []];
        }
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
