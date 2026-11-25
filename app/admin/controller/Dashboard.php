<?php
namespace app\admin\controller;

use app\admin\model\Menu;
use app\admin\facade\CurrentAdmin;
use app\common\dashboard\DashboardLayoutService;
use app\common\dashboard\DashboardRegistry;
use app\common\service\MenuLauncherService;

/**
 * 后台数据面板（Dashboard）控制器
 *
 * 面板编排器后端：
 *  - 面板内容来源统一为 DashboardRegistry（内置 system.* + 插件注册的数据面板）；
 *  - 布局持久化到独立表 dashboard_layouts（按 admin_id 单行覆盖）；
 *  - 页面首屏注入 layout/catalog/entries 三份数据（Phase1），前端编排器消费。
 *
 * 接口动作名与控制器方法一一对应（驼峰直调，与 /admin/index/saveTheme 等约定一致）：
 *  GET  /admin/dashboard/getLayout
 *  POST /admin/dashboard/saveLayout
 *  POST /admin/dashboard/resetLayout
 *  GET  /admin/dashboard/catalog
 *  GET  /admin/dashboard/widgetData?key=xxx
 *  GET  /admin/dashboard/entries
 */
class Dashboard extends BaseController
{
    /**
     * 展示面板页
     */
    public function index()
    {
        $adminId = (int) CurrentAdmin::getAdminId();

        $this->assign('dashboardData', json_encode(
            $this->buildPayload($adminId),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        ));

        return $this->fetch();
    }

    /**
     * 获取布局 + 目录 + 入口数据（供弹层/整页刷新）
     *
     * @return \think\response\Json
     */
    public function getLayout()
    {
        $adminId = (int) CurrentAdmin::getAdminId();
        return $this->success('ok', $this->buildPayload($adminId));
    }

    /**
     * 保存布局
     *
     * @return \think\response\Json
     */
    public function saveLayout()
    {
        $adminId = (int) CurrentAdmin::getAdminId();
        if ($adminId <= 0) {
            return $this->error('未登录，无法保存布局', [], 401);
        }

        $payload = $this->parseBody();
        $layout  = $payload['layout'] ?? null;
        if (!is_array($layout)) {
            return $this->error('无效的布局数据');
        }

        try {
            DashboardLayoutService::save($adminId, $layout);
        } catch (\Throwable $e) {
            return $this->error('布局保存失败：' . $e->getMessage());
        }

        return $this->success('布局已保存');
    }

    /**
     * 重置为系统默认布局
     *
     * @return \think\response\Json
     */
    public function resetLayout()
    {
        $adminId = (int) CurrentAdmin::getAdminId();
        if ($adminId <= 0) {
            return $this->error('未登录，无法重置布局', [], 401);
        }

        try {
            DashboardLayoutService::reset($adminId);
        } catch (\Throwable $e) {
            return $this->error('重置失败：' . $e->getMessage());
        }

        return $this->success('已重置为默认布局', ['layout' => DashboardLayoutService::resolve($adminId)]);
    }

    /**
     * 可用面板目录（添加面板 / 布局校验）
     *
     * @return \think\response\Json
     */
    public function catalog()
    {
        return $this->success('ok', [
            'catalog' => DashboardRegistry::catalog(),
        ]);
    }

    /**
     * 取面板数据
     *
     * GET /admin/dashboard/widget_data?key=system.server_status&props={"demo":false}
     *
     * @return \think\response\Json
     */
    public function widgetData()
    {
        $key = (string) $this->request->get('key', '');
        if ($key === '') {
            return $this->error('缺少 key 参数');
        }

        $props = $this->request->get('props', []);
        if (is_string($props)) {
            $decoded = json_decode($props, true);
            $props   = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($props)) {
            $props = [];
        }

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
     * 快捷入口数据（框架功能 + 插件图标）
     *
     * @return \think\response\Json
     */
    public function entries()
    {
        $menus = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());
        return $this->success('ok', [
            'groups' => MenuLauncherService::entries($menus),
        ]);
    }

    /**
     * 组装页面所需数据
     *
     * @param int $adminId
     * @return array{layout:array, catalog:array, entries:array}
     */
    protected function buildPayload(int $adminId): array
    {
        $menus = Menu::getMenuByRoleId(CurrentAdmin::getRoleIds());
        return [
            'layout'  => DashboardLayoutService::resolve($adminId),
            'catalog' => DashboardRegistry::catalog(),
            'entries' => MenuLauncherService::entries($menus),
        ];
    }

    /**
     * 解析请求体：兼容表单与 JSON body
     *
     * @return array
     */
    protected function parseBody(): array
    {
        $data = $this->request->post();
        if (!$data) {
            $content = (string) $this->request->getContent();
            if ($content !== '') {
                $parsed = json_decode($content, true);
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            }
        }
        return is_array($data) ? $data : [];
    }
}
