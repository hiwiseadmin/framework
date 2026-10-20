<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use app\api\service\AdminNavigationService;
use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;

/**
 * 后台 App 菜单树（/api/admin/menus）
 *
 * 返回当前管理员可见的菜单（已按角色过滤，超管返回全量），
 * 与后台 Web 侧边栏同源（menus 表 + MenuPermissionBridge），App 可直接渲染导航。
 *
 * 取数逻辑统一收敛在 AdminNavigationService（工作台快捷入口共用同一份）。
 */
class Menu extends Base
{
    /**
     * GET /api/admin/menus
     */
    public function index(Request $request): Json
    {
        $admin = $this->admin($request);
        if ($admin === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        return $this->success('ok', [
            'menus' => (new AdminNavigationService())->menus($admin),
        ]);
    }
}
