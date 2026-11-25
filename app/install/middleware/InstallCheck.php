<?php
declare(strict_types=1);

namespace app\install\middleware;

use app\install\service\InstallService;
use think\facade\View;

class InstallCheck
{
    public function handle($request, \Closure $next)
    {
        if (InstallService::checkInstalled()) {
            $reinstallAllowed = InstallService::reinstallAllowed();

            $path = strtolower(trim((string) $request->pathinfo(), '/'));
            $isReinstall = (bool) preg_match('#/reinstall$#', $path)
                || (bool) preg_match('#^index/reinstall$#', $path);

            if ($request->isPost()) {
                if ($isReinstall) {
                    if (!$reinstallAllowed) {
                        abort(403, '系统已安装且已禁用重装（ALLOW_REINSTALL=false），禁止执行安装操作');
                    }
                } elseif (!preg_match('#/reinstall$#', $path)) {
                    abort(403, '系统已安装，禁止执行安装操作');
                }
            } else {
                $safeRead = $path === ''
                    || $path === 'install'
                    || $path === 'install/index'
                    || (bool) preg_match('#/(index|complete|status)$#', $path)
                    || (bool) preg_match('#^index/(index|complete|status)$#', $path);
                if ($isReinstall && !$reinstallAllowed) {
                    $safeRead = false;
                }
                if (!$safeRead) {
                    return redirect('/install');
                }
            }
        }

        $baseUrl = $request->rootUrl();
        View::assign('base_url', $baseUrl);

        return $next($request);
    }
}
