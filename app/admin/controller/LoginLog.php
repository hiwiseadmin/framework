<?php
namespace app\admin\controller;

use app\admin\model\LoginLog as LoginLogModel;
use app\admin\service\LogAnalysisService;
use app\common\controller\traits\CrudTrait;
use wise\auth\attribute\AuthPermission;
use wise\operationlog\attribute\OperationLog;
use think\Model;

#[OperationLog(channel: 'admin', tags: ['admin', 'login_log'])]
class LoginLog extends BaseController
{   
    use CrudTrait;

    protected function getModel(): Model
    {
        return new LoginLogModel();
    }
    
    /**
     * 首页
     * @return string
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 登录日志列表
     */
    public function getList()
    {
        $this->buildQueryParams();
        
        $username = $this->request->param('username');
        $status = $this->request->param('status');
        $dateRange = $this->request->param('date_range');
        
        $query = LoginLogModel::with('admin');
        
        
        
        $total = $query->count();
        $list = $query->order('id', 'desc')
                        ->page($this->page, $this->pagesize)
                        ->select()
                        ->toArray();
            
        return $this->success('success',[
                'total' => $total,
                'list' => $list,
                'page' => $this->page,
                'limit' => $this->pagesize
            ]);
    }

    /**
     * 导出登录日志（服务端 CSV）
     *
     * 权限节点：`/admin/login_log/export`（POST）。
     * 由 wise-auth 既有 `PermissionRegistrar` 扫描本注解落库
     * （`php think wise:auth:import --from=attribute --dir=app/admin/controller`）。
     */
    #[AuthPermission(ident: 'admin.login_log.export', name: '登录日志 - 导出', type: 'route', route: '/admin/login_log/export', method: 'POST')]
    #[OperationLog(template: '导出登录日志（{context.export_scope}），共 {context.export_rows} 行', tags: ['admin', 'login_log', 'export'])]
    public function export()
    {
        return $this->_export();
    }

    /**
     * 导出复用与列表一致的查询（登录日志按 id 倒序，无筛选）
     */
    protected function buildListQuery()
    {
        return LoginLogModel::order('id', 'desc');
    }

    /**
     * 登录日志预览页面
     * @return string
     */
    public function preview()
    {
        $id = $this->request->param('id/d', 0);

        $loginLog = LoginLogModel::with(['admin'])->findOrEmpty($id);
        if ($loginLog->isEmpty()) {
            return $this->error('登录日志不存在');
        }

        return $this->assign([
            'loginLog' => $loginLog,
        ])->fetch();
    }

    /**
     * 数据分析仪表板
     */
    public function dashboard()
    {
        $analysisService = new LogAnalysisService();
        
        $data = [
            'login_stats' => $analysisService->getLoginStats(7),
            'operation_stats' => $analysisService->getOperationStats(7),
            'api_performance' => $analysisService->getApiPerformance(7),
            'security_risks' => $analysisService->securityRiskDetection(),
            'system_health' => $analysisService->getSystemHealth()
        ];
        
        return $this->success('success', $data);
    }
    
    /**
     * 清理日志
     */
    #[OperationLog(template: '清理 {days} 天前的登录日志', level: 'WARNING')]
    public function clearLog()
    {
        $days = $this->request->param('days', 30);
        $clearDate = date('Y-m-d H:i:s', strtotime("-$days days"));
        LoginLogModel::where('login_time', '<', $clearDate)->delete();
        
        return $this->success('日志清理成功');
    }
}