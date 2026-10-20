<?php
declare(strict_types=1);

namespace app\admin\controller;

use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\router\RouteRegistrar;

/**
 * 能力市场管理
 *
 * 展示所有已注册的扩展点及其实现，支持启用/停用/切换活跃状态。
 *
 * route.{app} 扩展点承载 wise-* 包投递的应用路由，其实现由
 * RouteRegistrar 提供清单信息（挂载路径 / 分组 / 来源），
 * 停用的实现不会被该应用的路由文件落地，等价于关闭整组接口。
 */
class Capability extends BaseController
{
    /**
     * 动态扩展点前缀 → 展示名
     *
     * 'bus.handler.{action}' / 'ws.handler.{action}' 的 action 由运行时输入拼接产生，
     * 编码期无法穷举，因此不会出现在 ExtPoints::getPointMeta() 中，
     * 只能从注册表反查「实际已注册」的子点。
     *
     * 前缀须与 ExtPoint 的 DYNAMIC_PREFIXES 保持一致；
     * 'slot.' 前缀下的 slot.definition / slot.content 已作为固定条目登记在
     * getPointMeta() 中，由 collectDynamicPoints() 去重，故不在此重复列出。
     */
    protected const DYNAMIC_POINT_PREFIXES = [
        'bus.handler.' => '服务总线',
        'ws.handler.'  => 'WebSocket',
    ];

    /**
     * 能力市场首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取所有扩展点及实现列表
     */
    public function getList()
    {
        // 以存储中的覆盖表为准重新应用，保证展示状态与持久化一致
        // （常驻模式下注册表可能滞后于存储，此处强制刷新）
        // ExtPoint::applyPersistedOverrides();

        $result = [];
        $seen   = [];

        // 1. 框架静态扩展点：编码期即可穷举，由 ExtPoints::getPointMeta() 声明
        foreach (ExtPoints::getPointMeta() as $def) {
            $result[] = $this->buildCapability($def['point'], $def['name'], $def['policy']);
            $seen[$def['point']] = true;
        }

        // 2. 动态扩展点：名称由运行时拼接产生，只能从注册表反查实际注册的子点
        foreach ($this->collectDynamicPoints() as $point => $meta) {
            if (isset($seen[$point])) {
                continue;
            }
            $result[] = $this->buildCapability($point, $meta['name'], $meta['policy']);
            $seen[$point] = true;
        }

        // 3. 框架外扩展点：各包 / 插件经 ExtPoint::definePoint(..., $meta) 或
        //    ExtPoint::describePoint() 自声明元数据的点（如 wise-member 的 member.*）。
        //    能力市场据此自动发现 —— 新增业务包无需修改本控制器。
        foreach (ExtPoint::describedPoints() as $point => $meta) {
            if (isset($seen[$point]) || ($meta['visible'] ?? true) === false) {
                continue;
            }
            $result[] = $this->buildCapability(
                $point,
                (string) ($meta['name'] ?? $point),
                ExtPoint::getPolicy($point)
            );
            $seen[$point] = true;
        }

        return $this->success('ok', ['capabilities' => $result]);
    }

    /**
     * 从注册表中反查实际已注册的动态扩展点子点
     *
     * 只返回真正被 register() 过的子点：未被注册过的 action 不占注册表槽位，
     * 也就不会出现在这里，避免列出大量空条目。
     *
     * @return array<string, array{name: string, policy: string}>
     */
    protected function collectDynamicPoints(): array
    {
        // 已在 getPointMeta() 中作为固定条目列出的点不再重复展开
        $staticPoints = array_column(ExtPoints::getPointMeta(), 'point');
        $result       = [];

        foreach (ExtPoint::points() as $point) {
            if (in_array($point, $staticPoints, true)) {
                continue;
            }

            foreach (self::DYNAMIC_POINT_PREFIXES as $prefix => $label) {
                if (!str_starts_with($point, $prefix)) {
                    continue;
                }

                $action = substr($point, strlen($prefix));
                if ($action === '') {
                    continue;
                }

                $result[$point] = [
                    'name'   => $label . ': ' . $action,
                    // 动态扩展点全命名空间共享同一策略，且无统一契约
                    'policy' => ExtPoint::POLICY_MULTI,
                ];
                break;
            }
        }

        // 稳定排序，避免每次请求输出顺序抖动
        ksort($result);

        return $result;
    }

    /**
     * 组装单个扩展点的能力市场条目
     *
     * @return array<string, mixed>
     */
    protected function buildCapability(string $point, string $name, string $policy): array
    {
        // route.{app} 的实现是延迟注册的闭包，仅靠 concrete 无法看出挂载位置，
        // 因此额外取出 RouteRegistrar 在投递时记录的路由清单。
        $descriptors = str_starts_with($point, 'route.')
            ? RouteRegistrar::describe($point)
            : [];

        $items = [];
        foreach (ExtPoint::all($point) as $implName => $item) {
            $desc   = $descriptors[$implName] ?? [];
            $vendor = $this->describeVendor((string) $implName, $item['concrete'] ?? null, $desc);

            $items[] = [
                'name'     => $implName,
                'class'    => is_string($item['concrete']) ? $item['concrete'] : 'Closure',
                'priority' => $item['priority'] ?? 100,
                'active'   => $item['active'] ?? true,
                'endpoint' => $desc['endpoint'] ?? '',
                'group'    => $desc['group'] ?? '',
                'source'   => $desc['source'] ?? '',
                // P4-4：来源分类（官方 / 插件 / 宿主）与「不可卸载，可停用」标记
                'vendor'            => $vendor['vendor'],
                'vendor_label'      => $vendor['label'],
                'vendor_kind'       => $vendor['kind'],
                'vendor_kind_label' => $vendor['kind_label'],
                'locked'            => $vendor['locked'],
                'locked_hint'       => $vendor['locked_hint'],
                // 实现级业务标记（如会员功能「待确认上线」）：由各包经
                // ExtPoint::registerImplementationDescriber() 自注册提供，
                // 能力市场只做汇总，不内联判断任何业务包。
                'pending'           => (bool) (ExtPoint::describeImplementation(
                    $point,
                    (string) $implName,
                    $item['concrete'] ?? null
                )['pending'] ?? false),
            ];
        }

        // 同一扩展点内按来源分组展示（官方在前，插件在后；组内按注册名稳定排序）
        usort($items, static function (array $a, array $b): int {
            $rank = ['core' => 0, 'official' => 1, 'app' => 2, 'plugin' => 3, 'unknown' => 4];

            return [
                $rank[$a['vendor_kind']] ?? 9,
                $a['vendor'],
                $a['name'],
            ] <=> [
                $rank[$b['vendor_kind']] ?? 9,
                $b['vendor'],
                $b['name'],
            ];
        });

        return [
            'point'           => $point,
            'name'            => $name,
            'policy'          => $policy,
            'policy_label'    => $this->policyLabel($policy),
            'count'           => count($items),
            'vendors'         => $this->summarizeVendors($items),
            'implementations' => $items,
        ];
    }

    /**
     * 汇总各来源（P4-4：能力市场按 vendor 分组展示的头部信息）
     *
     * @param  array<int, array<string, mixed>> $items
     * @return array<int, array{vendor:string, label:string, kind:string, kind_label:string, count:int, locked:bool}>
     */
    protected function summarizeVendors(array $items): array
    {
        $summary = [];

        foreach ($items as $item) {
            $key = (string) $item['vendor'];

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'vendor'      => $key,
                    'label'       => (string) $item['vendor_label'],
                    'kind'        => (string) $item['vendor_kind'],
                    'kind_label'  => (string) $item['vendor_kind_label'],
                    'count'       => 0,
                    'locked'      => (bool) $item['locked'],
                    'pending'     => false,
                ];
            }

            $summary[$key]['count']++;
            $summary[$key]['pending'] = $summary[$key]['pending'] || !empty($item['pending']);
        }

        return array_values($summary);
    }

    /**
     * 判定实现来源（P4-4）
     *
     * 判据顺序（先可靠后兜底）：**类命名空间** → 路由描述 source → 注册名前缀 `{vendor}:{code}`。
     * `locked` 语义是「**不可卸载，可停用**」：官方包 / 宿主应用 / 内核能力随代码发布，
     * 只能停用不能卸载；插件能力可经插件管理卸载。
     *
     * @param  mixed                $concrete 实现类名或工厂闭包
     * @param  array<string, mixed> $desc     RouteRegistrar 记录的路由描述（仅 route.* 扩展点有）
     * @return array{vendor:string, label:string, kind:string, kind_label:string, locked:bool, locked_hint:string}
     */
    protected function describeVendor(string $implName, mixed $concrete, array $desc = []): array
    {
        $class = is_string($concrete) ? $concrete : '';
        $source = trim((string) ($desc['source'] ?? ''));

        // 1) 类命名空间（最可靠）：plugin\{ident}\… 与 wise\{pkg}\…
        if ($class !== '' && preg_match('/^plugin\\\\([A-Za-z0-9_]+)\\\\/', $class, $matches)) {
            return $this->vendorInfo('plugin', $matches[1]);
        }

        if ($class !== '' && preg_match('/^wise\\\\([A-Za-z0-9_]+)\\\\/', $class, $matches)) {
            return $this->vendorInfo('official', 'wise-' . $matches[1]);
        }

        if (str_starts_with($class, 'app\\')) {
            return $this->vendorInfo('app', 'app');
        }

        // 2) 路由描述来源（RouteRegistrar 投递时记录）
        if ($source !== '') {
            $normalized = str_replace('/', '-', $source);

            return str_starts_with($normalized, 'wise-') || in_array($normalized, ['core', 'app'], true)
                ? $this->vendorInfo($normalized === 'app' ? 'app' : 'official', $normalized)
                : $this->vendorInfo('plugin', $normalized);
        }

        // 3) 注册名前缀 `{vendor}:{code}`（闭包实现只能靠这条）
        if (str_contains($implName, ':')) {
            $prefix = (string) strstr($implName, ':', true);

            if (in_array($prefix, ['core', 'system'], true)) {
                return $this->vendorInfo($prefix === 'core' ? 'core' : 'official', $prefix);
            }

            if (in_array($prefix, ['app', 'host'], true)) {
                return $this->vendorInfo('app', 'app');
            }

            return str_starts_with($prefix, 'wise-')
                ? $this->vendorInfo('official', $prefix)
                : $this->vendorInfo('plugin', $prefix);
        }

        return $this->vendorInfo('unknown', 'unknown');
    }

    /**
     * 来源信息结构（P4-4）
     *
     * @return array{vendor:string, label:string, kind:string, kind_label:string, locked:bool, locked_hint:string}
     */
    protected function vendorInfo(string $kind, string $vendor): array
    {
        $labels = [
            'core'     => '内核',
            'official' => '官方',
            'app'      => '宿主应用',
            'plugin'   => '插件',
            'unknown'  => '未识别',
        ];

        // 「不可卸载」= 非插件来源（随代码/包发布，只能停用）
        $locked = $kind !== 'plugin' && $kind !== 'unknown';

        return [
            'vendor'      => $vendor,
            'label'       => ($labels[$kind] ?? $kind) . ($vendor === $kind ? '' : '（' . $vendor . '）'),
            'kind'        => $kind,
            'kind_label'  => $labels[$kind] ?? $kind,
            'locked'      => $locked,
            'locked_hint' => $locked
                ? '官方能力：不可卸载，可停用'
                : ($kind === 'plugin' ? '插件能力：卸载插件即移除' : '来源未识别（工厂闭包或无前缀注册名）'),
        ];
    }

    /**
     * 切换实现活跃状态
     */
    public function toggle()
    {
        $point = $this->request->post('point', '');
        $name  = $this->request->post('name', '');
        $active = $this->request->post('active/b', false);

        if (empty($point) || empty($name)) {
            return $this->error('参数不完整');
        }

        // 动态扩展点（bus.handler.{action} 等）从不进入 $definitions，
        // 只判断 defined() 会让能力市场无法启用/停用这类实现，故一并接受动态扩展点。
        if (!ExtPoint::defined($point) && !ExtPoint::isDynamicPoint($point)) {
            return $this->error("扩展点 {$point} 未定义");
        }

        if (!ExtPoint::has($point, $name)) {
            return $this->error("实现 {$name} 不存在");
        }

        // 安全相关扩展点（auth.guard / jwt.provider / connector）需要宿主授权：
        // 未授权时 setActive 返回 false 且不会改动内存状态。
        // 来源标记为 'admin'，随 EXT_ACTIVE_SWITCHED 事件派发，供关注方（如会员显隐审计）区分。
        if (!ExtPoint::setActive($point, $name, $active, 'admin')) {
            return $this->error('无权限变更该扩展点的活跃实现，或该实现不存在');
        }

        // 持久化活跃覆盖表，保证切换状态跨请求生效
        if (!ExtPoint::persistOverrides()) {
            return $this->error('状态已切换，但持久化失败，刷新后可能恢复原状');
        }

        return $this->success($active ? '已启用' : '已停用');
    }

    protected function policyLabel(string $policy): string
    {
        return match ($policy) {
            ExtPoint::POLICY_SINGLE => '独占（单活跃）',
            ExtPoint::POLICY_MULTI  => '共享（并行）',
            ExtPoint::POLICY_ROUTED => '路由（按名查找）',
            default => $policy,
        };
    }
}
