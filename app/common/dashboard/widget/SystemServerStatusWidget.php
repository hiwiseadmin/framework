<?php
declare(strict_types=1);

namespace app\common\dashboard\widget;

use plugin\contract\DashboardWidgetInterface;

/**
 * 内置：服务器状态面板（category=stat）
 *
 * CPU 负载 / PHP 内存占用 / 根分区磁盘 使用率。
 * Windows 环境无 sys_getloadavg 时对应指标回退为 null，前端渲染 “--”。
 * props: { demo: true } 时返回演示占位数据（仅用于无服务器指标的本地开发预览）。
 *
 * @package app\common\dashboard\widget
 */
class SystemServerStatusWidget implements DashboardWidgetInterface
{
    public function getKey(): string
    {
        return 'system.server_status';
    }

    public function getTitle(): string
    {
        return '服务器状态';
    }

    public function getDescription(): string
    {
        return 'CPU / 内存 / 磁盘实时负载';
    }

    public function getIcon(): array
    {
        return ['type' => 'bi', 'value' => 'bi bi-cpu', 'color' => ''];
    }

    public function getCategory(): string
    {
        return 'stat';
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
        return 'info';
    }

    public function getDefaultConfig(): array
    {
        return ['demo' => false];
    }

    public function getConfigSchema(): array
    {
        return [
            'demo' => [
                'type'    => 'switch',
                'title'   => '演示数据',
                'tips'    => '无服务器指标时显示演示占位值',
                'default' => false,
            ],
        ];
    }

    public function getData(array $config = []): array
    {
        if (!empty($config['demo'])) {
            return $this->demo();
        }
        return [
            'cpu'    => $this->cpu(),
            'memory' => $this->memory(),
            'disk'   => $this->disk(),
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

    /**
     * CPU 平均负载（前 1 分钟，Windows 不支持返回 null）
     */
    protected function cpu(): ?float
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $load = sys_getloadavg();
        return is_array($load) && isset($load[0]) ? (float) $load[0] : null;
    }

    /**
     * PHP 进程内存使用率
     */
    protected function memory(): ?float
    {
        $limit = ini_get('memory_limit');
        $bytes = $this->parseBytes($limit);
        if ($bytes <= 0) {
            return null;
        }
        $used = memory_get_usage(true);
        return round($used / $bytes * 100, 1);
    }

    /**
     * 磁盘使用率（以根分区探测，失败返回 null）
     */
    protected function disk(): ?float
    {
        $total = @disk_total_space(DIRECTORY_SEPARATOR);
        $free  = @disk_free_space(DIRECTORY_SEPARATOR);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }
        return round(($total - $free) / $total * 100, 1);
    }

    protected function demo(): array
    {
        return ['cpu' => 0.45, 'memory' => 62.0, 'disk' => 28.0];
    }

    /**
     * 解析 "128M" 这类 ini 容量为字节
     */
    protected function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num  = (float) $value;
        return match ($unit) {
            'g' => (int) ($num * 1024 * 1024 * 1024),
            'm' => (int) ($num * 1024 * 1024),
            'k' => (int) ($num * 1024),
            default => (int) $num,
        };
    }
}
