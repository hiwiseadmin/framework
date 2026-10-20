<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\engine\WorkflowService;
class Workflow extends BaseController
{
    /**
     * 工作流面板（iframe 弹层视图，UIUX-P0-34）
     *
     * 状态 / 可用动作 / 历史由 `statics/js/workflow.js::panel()` 异步拉取
     * （`workflow/status|available`），本方法只负责渲染骨架视图。
     * ⚠️ 此前缺该 action + 路由，`content.js` 三处 `cmsUrl('workflow/panel')` 打开的是 404。
     */
    public function panel()
    {
        return $this->fetch('admin/workflow/panel');
    }

    /**
     * 获取内容工作流状态
     */
    public function status()
    {
        $id = (int)$this->request->param('id', 0);
        try {
            $wf = new WorkflowService();
            $status = $wf->getWorkflowStatus($id);
            return $this->success('', $status);
        } catch (WisecmsException $e) {
            cms_log('error', '工作流操作失败: ' . $e->getMessage(), ['code' => $e->getErrorCode(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', '工作流操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 执行状态转换
     */
    public function transition()
    {
        $id = (int)$this->request->post('id', 0);
        $action = $this->request->post('action', '');
        try {
            $wf = new WorkflowService();
            $content = $wf->transition($id, $action);
            return $this->success("{$action} 操作成功", $content);
        } catch (WisecmsException $e) {
            cms_log('error', '工作流操作失败: ' . $e->getMessage(), ['code' => $e->getErrorCode(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', '工作流操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 获取可用转换列表
     */
    public function available()
    {
        $id = (int)$this->request->param('id', 0);
        $wf = new WorkflowService();
        $transitions = $wf->getAvailableTransitions($id);
        return $this->success('', $transitions);
    }
}
