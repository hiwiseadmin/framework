<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\controller\traits\WiseConfigTrait;
use wise\config\model\ConfigHistoryModel;

/**
 * 配置历史记录控制器
 *
 * 路由: admin/wise_config/history/*
 * 实际使用 admin/wise_config/history 等旧路由（通过路由映射保持兼容）
 */
class WiseConfigHistory extends BaseController
{
    use WiseConfigTrait;

    protected function _initialize()
    {
        $this->initWiseConfig();
    }

    /**
     * 获取单个配置的变更历史
     */
    public function history()
    {
        $configId = $this->request->param('config_id/d', 0);
        $page     = (int)($this->request->param('page', 1));
        $limit    = (int)($this->request->param('limit', 20));

        $query = (new ConfigHistoryModel())->search([
            'config_id' => $configId ?: null,
        ]);

        $total = $query->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        return $this->success('ok', [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
        ]);
    }

    /**
     * 回滚到指定历史版本
     */
    public function historyRevert()
    {
        $id = $this->request->post('id/d', 0);
        try {
            if ($this->configManager->revert($id)) {
                return $this->success('回滚成功');
            }
            return $this->error('回滚失败');
        } catch (\Exception $e) {
            return $this->error('回滚失败: ' . $e->getMessage());
        }
    }
}
