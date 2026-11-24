<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\api\service\AdminNavigationService;
use app\common\dashboard\DashboardLayoutService;
use app\common\dashboard\DashboardRegistry;
use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;

/**
 * 工作台（后台 App API）
 *
 * GET /api/admin/dashboard           布局 + 面板目录 + 快捷入口
 * GET /api/admin/dashboard/widgets   取面板数据（单取 / 批取）
 *
 * 权限：ident `admin.dashboard.index`（与 Web 后台「控制台」菜单派生节点同源）
 *
 * 与 Web 侧 `app\admin\controller\Dashboard` 的关系：
 *   数据组装全部复用同一批服务（DashboardLayoutService / DashboardRegistry /
 *   MenuLauncherService），本控制器只负责「身份来自 JWT」与「输出 JSON」。
 */
class Dashboard extends Base
{
    /**
     * 单次批量取面板数据的上限，防止 App 一次拉爆
     */
    protected const MAX_BATCH_KEYS = 12;

    /**
     * 工作台首屏数据
     *
     * GET /api/admin/dashboard
     */
    public function index(Request $request): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $navigation = new AdminNavigationService();

        return $this->success('ok', [
            'layout'  => DashboardLayoutService::resolve((int) $admin->id),
            'catalog' => DashboardRegistry::catalog(),
            'entries' => $navigation->entries($admin),
        ]);
    }

    /**
     * 面板数据
     *
     * GET /api/admin/dashboard/widgets?key=system.server_status&props={"demo":false}
     * GET /api/admin/dashboard/widgets?keys[]=system.server_status&keys[]=system.task_stats
     *
     * props 仅对单取生效；批取一律按空 props 处理（与 Web 编排器的批量刷新一致）。
     *
     * @return Json
     */
    public function widgets(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $key  = trim((string) $request->param('key', ''));
        $keys = $request->param('keys/a', []);
        $keys = is_array($keys) ? array_values(array_filter(array_map('strval', $keys))) : [];

        if ($key === '' && $keys === []) {
            return $this->error('缺少 key 或 keys 参数');
        }

        if ($key !== '') {
            return $this->widget($key, $this->resolveProps($request));
        }

        if (count($keys) > self::MAX_BATCH_KEYS) {
            return $this->error('单次最多取 ' . self::MAX_BATCH_KEYS . ' 个面板');
        }

        $data  = [];
        $error = [];

        foreach ($keys as $item) {
            try {
                $data[$item] = DashboardRegistry::data($item, []);
            } catch (\Throwable $e) {
                $error[$item] = $e->getMessage();
            }
        }

        return $this->success('ok', [
            'data'  => (object) $data,
            'error' => (object) $error,
        ]);
    }

    /**
     * 取单个面板数据
     */
    protected function widget(string $key, array $props): Json
    {
        try {
            $data = DashboardRegistry::data($key, $props);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('面板数据获取失败：' . $e->getMessage());
        }

        return $this->success('ok', $data);
    }

    /**
     * props 兼容数组与 JSON 字符串两种入参（与 Web 侧一致）
     */
    protected function resolveProps(Request $request): array
    {
        $props = $request->param('props', []);

        if (is_string($props)) {
            $decoded = json_decode($props, true);
            $props   = is_array($decoded) ? $decoded : [];
        }

        return is_array($props) ? $props : [];
    }
}
