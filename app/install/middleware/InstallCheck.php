<?php
declare(strict_types=1);

namespace app\install\middleware;

use app\install\service\InstallService;
use think\facade\View;

class InstallCheck
{
    public function handle($request, \Closure $next)
    {
        // 严格判断是否已安装（存在 {root}/app/install/install.lock）
        if (InstallService::checkInstalled()) {
            // 与 CLI 安装命令 checkLockFile() 的 --force 守卫保持一致：
            // .env 显式 ALLOW_REINSTALL=false 时，网页重装入口同样禁止。
            $reinstallAllowed = env('ALLOW_REINSTALL', true) !== false;

            $path = strtolower(trim((string) $request->pathinfo(), '/'));
            $isReinstall = (bool) preg_match('#/reinstall$#', $path)
                || (bool) preg_match('#^index/reinstall$#', $path);

            if ($request->isPost()) {
                // 已安装：reinstall 提交仅在允许重装时放行，其余安装步骤 POST 一律拒绝
                if ($isReinstall) {
                    if (!$reinstallAllowed) {
                        abort(403, '系统已安装且已禁用重装（ALLOW_REINSTALL=false），禁止执行安装操作');
                    }
                } elseif (!preg_match('#/reinstall$#', $path)) {
                    abort(403, '系统已安装，禁止执行安装操作');
                }
            } else {
                // 已安装后仍允许的只读页面：安装首页（显示“已安装”提示）、完成页、状态查询
                $safeRead = $path === ''
                    || $path === 'install'
                    || $path === 'install/index'
                    || (bool) preg_match('#/(index|complete|status)$#', $path)
                    || (bool) preg_match('#^index/(index|complete|status)$#', $path);
                // 重装入口页仅在允许重装时开放
                if ($isReinstall && !$reinstallAllowed) {
                    $safeRead = false;
                }
                if (!$safeRead) {
                    // 其它安装向导步骤不允许进入，跳回安装首页展示“已安装”提示
                    return redirect('/install');
                }
            }
        }

        // 获取基础URL
        $baseUrl = $request->rootUrl();
        View::assign('base_url', $baseUrl);

        return $next($request);
    }
}
