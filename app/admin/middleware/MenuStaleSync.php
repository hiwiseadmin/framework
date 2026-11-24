<?php
declare(strict_types=1);

namespace app\admin\middleware;

use app\admin\service\MenuItemSync;
use Closure;
use think\Request;

/**
 * 菜单声明懒同步（后台应用级中间件）
 *
 * 后台请求到达时，比较 ExtPoints::MENU_ITEM 各实现的「声明指纹」与上次同步结果：
 * 不一致（典型场景：包升级改了菜单声明；能力市场停用/启用某实现）→ 自动同步一次。
 *
 * 安全与成本约束：
 *  - 仅在幂等方法（GET/HEAD）上触发，避免对写请求产生副作用；
 *  - `MenuItemSync::syncIfStale()` 内部带节流窗口（默认 300s），失败也不会反复重试；
 *  - 任何异常静默吞掉，绝不影响后台正常访问。
 *
 * 生效位置：本中间件置于 app/admin/middleware.php 末尾（最靠近控制器），
 * 即 RBAC 等鉴权已通过之后才可能触发写操作。
 */
class MenuStaleSync
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD'], true)) {
            try {
                app(MenuItemSync::class)->syncIfStale();
            } catch (\Throwable $e) {
            }
        }

        return $next($request);
    }
}
