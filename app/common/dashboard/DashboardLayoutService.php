<?php
declare(strict_types=1);

namespace app\common\dashboard;

use app\admin\model\DashboardLayout;

/**
 * 仪表盘布局服务
 *
 * 布局持久化（独立表 dashboard_layouts）：
 *  - 每个用户（admin_id）只有一条 default 布局，保存即整行覆盖；
 *  - 用户无布局时自动克隆系统默认模板（admin_id=0 的系统模板行）；
 *  - 因保存按 admin_id 单点覆盖且无并发写路径，不需要乐观锁版本号。
 *
 * @package app\common\dashboard
 */
class DashboardLayoutService
{
    /**
     * 系统默认模板（迁移 seed 同构；无系统模板行时的兜底值）
     */
    public const SYSTEM_TEMPLATE = [
        'settings' => [
            'columns'    => 12,
            'cellHeight' => 70,
            'margin'     => 12,
        ],
        'panels' => [
            ['uid' => 'tpl.welcome',   'widgetKey' => 'system.welcome',       'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2, 'accent' => 'brand',   'props' => [], 'refresh' => 0,  'hidden' => false],
            ['uid' => 'tpl.shortcuts', 'widgetKey' => 'system.shortcuts',     'x' => 4, 'y' => 0, 'w' => 8, 'h' => 3, 'accent' => '',        'props' => [], 'refresh' => 0,  'hidden' => false],
            ['uid' => 'tpl.status',    'widgetKey' => 'system.server_status', 'x' => 0, 'y' => 2, 'w' => 4, 'h' => 3, 'accent' => 'info',    'props' => [], 'refresh' => 30, 'hidden' => false],
            ['uid' => 'tpl.notify',    'widgetKey' => 'system.notifications', 'x' => 4, 'y' => 3, 'w' => 4, 'h' => 3, 'accent' => 'success', 'props' => [], 'refresh' => 60, 'hidden' => false],
            ['uid' => 'tpl.activity',  'widgetKey' => 'system.activity',      'x' => 8, 'y' => 3, 'w' => 4, 'h' => 3, 'accent' => '',        'props' => [], 'refresh' => 60, 'hidden' => false],
        ],
    ];

    /** accent 白名单（'' = 跟随全局 scheme） */
    public const ACCENTS = ['', 'brand', 'success', 'warning', 'danger', 'info'];

    /** refresh 白名单（秒） */
    public const REFRESH_INTERVALS = [0, 30, 60, 300, 900];

    /**
     * 解析用户最终布局（无记录则克隆系统模板并落库）
     *
     * @param int    $adminId
     * @param string $name
     * @return array{settings:array, panels:array}
     */
    public static function resolve(int $adminId, string $name = DashboardLayout::DEFAULT_NAME): array
    {
        $adminId = max(0, (int) $adminId);
        $row = static::findRow($adminId, $name);
        if (!$row) {
            $template = static::systemTemplate();
            $row = DashboardLayout::create([
                'admin_id'      => $adminId,
                'role_id'       => 0,
                'name'          => $name,
                'settings_json' => $template['settings'],
                'layout_json'   => $template,
                'is_system'     => 0,
            ]);
            return $template;
        }
        $layout = (array) ($row->layout_json ?? []);
        $layout = array_merge(static::SYSTEM_TEMPLATE, $layout);
        return $layout;
    }

    /**
     * 保存用户布局（整行覆盖，含面板级校验）
     *
     * @param int   $adminId
     * @param array $payload ['settings' => [...], 'panels' => [...]]
     * @return bool
     */
    public static function save(int $adminId, array $payload): bool
    {
        $adminId = max(0, (int) $adminId);
        $name    = DashboardLayout::DEFAULT_NAME;

        $layout = static::normalizeLayout($payload);
        $row    = static::findRow($adminId, $name);

        $data = [
            'settings_json' => $layout['settings'],
            'layout_json'   => $layout,
        ];

        if ($row) {
            $row->save($data);
        } else {
            DashboardLayout::create(array_merge($data, [
                'admin_id'  => $adminId,
                'role_id'   => 0,
                'name'      => $name,
                'is_system' => 0,
            ]));
        }
        return true;
    }

    /**
     * 重置：删除用户布局记录，下次访问自动回到系统默认模板
     *
     * @param int $adminId
     * @return bool
     */
    public static function reset(int $adminId): bool
    {
        $adminId = max(0, (int) $adminId);
        if ($adminId <= 0) {
            return false;
        }
        $row = static::findRow($adminId, DashboardLayout::DEFAULT_NAME);
        if (!$row) {
            return true;
        }
        $row->delete(); // 模型未启用软删除 → 物理删除，避免唯一键冲突
        return true;
    }

    /**
     * 系统默认模板内容
     *
     * @return array{settings:array, panels:array}
     */
    public static function systemTemplate(): array
    {
        return static::SYSTEM_TEMPLATE;
    }

    /**
     * 归一化待保存布局：字段白名单、尺寸/主题色/刷新白名单、widgetKey 可见性过滤
     *
     * @param array $payload
     * @return array{settings:array, panels:array}
     */
    protected static function normalizeLayout(array $payload): array
    {
        // settings 归一化
        $rawSettings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        // margin = GridStack 单侧边距；兼容旧数据：曾以 gap(相邻间距) 存储，按 gap/2 换算一次。
        // marginX / marginY 可选，用于横纵独立间距（px，缺省回退到 margin），前端按 '纵 横' 传给 GridStack。
        $legacyGap  = isset($rawSettings['gap']) ? (int) $rawSettings['gap'] : null;
        $rawMargin  = array_key_exists('margin', $rawSettings)
            ? (int) $rawSettings['margin']
            : ($legacyGap !== null ? (int) round($legacyGap / 2) : 12);
        $hasMarginX = array_key_exists('marginX', $rawSettings);
        $hasMarginY = array_key_exists('marginY', $rawSettings);
        $settings = [
            'columns'    => (int) ($rawSettings['columns'] ?? 12),
            'cellHeight' => (int) ($rawSettings['cellHeight'] ?? 70),
            'margin'     => $rawMargin,
        ];
        $settings['columns']    = max(1, min(24, $settings['columns']));
        $settings['cellHeight'] = max(30, min(300, $settings['cellHeight']));
        $settings['margin']     = max(0, min(40, $settings['margin']));
        if ($hasMarginX) {
            $settings['marginX'] = max(0, min(40, (int) $rawSettings['marginX']));
        }
        if ($hasMarginY) {
            $settings['marginY'] = max(0, min(40, (int) $rawSettings['marginY']));
        }

        // 当前可见 widget key 集合（不可见面板不落库）
        $accessible = [];
        foreach (DashboardRegistry::catalog() as $meta) {
            $accessible[$meta['key']] = true;
        }

        // panels 归一化
        $panels = [];
        foreach ((is_array($payload['panels'] ?? null) ? $payload['panels'] : []) as $panel) {
            if (!is_array($panel)) {
                continue;
            }
            $widgetKey = (string) ($panel['widgetKey'] ?? '');
            if ($widgetKey === '' || !isset($accessible[$widgetKey])) {
                continue;
            }
            $accent  = (string) ($panel['accent'] ?? '');
            $refresh = (int) ($panel['refresh'] ?? 0);
            $panels[] = [
                'uid'       => (string) ($panel['uid'] ?? uniqid('p', true)),
                'widgetKey' => $widgetKey,
                'x'         => max(0, (int) ($panel['x'] ?? 0)),
                'y'         => max(0, (int) ($panel['y'] ?? 0)),
                'w'         => max(1, min(24, (int) ($panel['w'] ?? 2))),
                'h'         => max(1, min(24, (int) ($panel['h'] ?? 2))),
                'accent'    => in_array($accent, static::ACCENTS, true) ? $accent : '',
                'props'     => is_array($panel['props'] ?? null) ? $panel['props'] : [],
                'refresh'   => in_array($refresh, static::REFRESH_INTERVALS, true) ? $refresh : 0,
                'hidden'    => !empty($panel['hidden']),
            ];
        }

        // 面板数量上限保护（避免异常提交撑爆 longtext）
        if (count($panels) > 60) {
            $panels = array_slice($panels, 0, 60);
        }

        return ['settings' => $settings, 'panels' => $panels];
    }

    /**
     * 定位布局行
     *
     * @param int    $adminId
     * @param string $name
     * @return DashboardLayout|null
     */
    protected static function findRow(int $adminId, string $name): ?DashboardLayout
    {
        return DashboardLayout::where('admin_id', $adminId)
            ->where('role_id', 0)
            ->where('name', $name)
            ->find();
    }
}
