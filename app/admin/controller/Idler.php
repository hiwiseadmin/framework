<?php
namespace app\admin\controller;

use app\common\service\CacheClearService;
use think\facade\Log;
use wise\auth\attribute\AuthPermission;
use wise\operationlog\attribute\OperationLog;

/**
 * 临时工（运维类操作）
 */
#[OperationLog(channel: 'admin', tags: ['admin', 'cache'])]
class Idler extends BaseController
{
    /**
     * 清理运行时缓存
     *
     * POST /admin/idler/clearCache
     * 参数 type：all | template | plugin | config | module | log（支持逗号分隔多选）
     *
     * 具体清理对象与顺序由 CacheClearService 的目标注册表决定，本方法不碰文件系统。
     */
    #[AuthPermission(
        ident: 'admin.idler.clearCache',
        name: '清理缓存',
        type: 'route',
        route: '/admin/idler/clearCache',
        method: 'POST',
        description: '清理模板/插件/配置缓存，影响全站，建议仅授予超级管理员'
    )]
    #[OperationLog(template: '清理缓存 {type}', level: 'WARNING')]
    public function clearCache()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }

        $type = (string) $this->request->post('type', CacheClearService::TARGET_TEMPLATE);

        try {
            $result = (new CacheClearService())->clear($type);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[cache-clear] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->error('缓存清理失败，请查看运行日志');
        }

        if ($result['failed'] === []) {
            $msg = sprintf('已清理 %d 个文件，耗时 %dms', $result['deleted'], $result['took_ms']);
        } else {
            $msg = sprintf(
                '部分清理失败（成功 %d / 失败 %d），请检查 runtime 目录权限',
                $result['deleted'],
                count($result['failed'])
            );
        }

        return $this->success($msg, $result);
    }
}
