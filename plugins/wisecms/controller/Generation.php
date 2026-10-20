<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\BaseController;
use plugin\wisecms\service\content\CategoryService;
use plugin\wisecms\service\engine\GenerationEngine;
class Generation extends BaseController
{
    protected GenerationEngine $engine;

    protected function initPlugin(): void
    {
        $this->engine = new GenerationEngine();
    }

    /**
     * 栏目下拉项（生成表单需要选择目标栏目）
     */
    protected function categoryOptions(): array
    {
        return (new CategoryService())->getOptions();
    }

    /**
     * 生成工作台
     *
     * UIUX-P0-44：补齐 `isAjax()` JSON 分支 —— 此前 AJAX 请求同样返回**渲染后的 HTML**，
     * 导致列表页无法用 `Wise.table` 取数。改为在同一方法内提供 View + JSON 双通道，
     * 与其余 16 个后台列表一致。
     */
    public function index()
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 15)));

        $list = \plugin\wisecms\model\GenerationTask::order('id', 'desc')
            ->paginate(['page' => $page, 'list_rows' => $limit]);

        if ($this->request->isAjax()) {
            // 标准 paginate 契约：{total, per_page, current_page, last_page, data}
            // —— 前端 `cmsTable({ shape: 'standard' })` 可直接消费
            return $this->success('', $list->toArray());
        }

        // 视图分支保持原渲染结构（模板按 {foreach} 迭代）。
        // 用 items() 返回数组而非 Collection：顺带修掉 `!empty($tasks)` 对空 Collection
        // 恒为 true、「暂无生成任务」空态永不显示的老问题。
        $this->assign('tasks', $list->items());
        return $this->fetch('admin/generation/index');
    }

    /**
     * 自然语言生成
     */
    public function natural()
    {
        if ($this->request->isPost()) {
            $params = [
                'task_name' => $this->request->post('task_name', ''),
                'topic' => $this->request->post('topic', ''),
                'style' => $this->request->post('style', '专业'),
                'requirements' => $this->request->post('requirements', ''),
                'category_id' => (int)$this->request->post('catid', 0),
                'auto_publish' => $this->request->post('auto_publish', 0),
            ];
            try {
                $result = $this->engine->generateNatural($params);
                return $this->success('生成完成', $result);
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        $this->assign('categoryOptions', $this->categoryOptions());
        return $this->fetch('admin/generation/natural');
    }

    /**
     * 批量生成
     */
    public function batch()
    {
        if ($this->request->isPost()) {
            $keywords = $this->request->post('keywords', '');
            $kwList = array_filter(array_map('trim', explode("\n", $keywords)));
            $params = [
                'task_name' => '批量生成_' . date('YmdHis'),
                'keywords' => $kwList,
                'category_id' => (int)$this->request->post('catid', 0),
                'daily_limit' => (int)$this->request->post('daily_limit', 10),
                'auto_publish' => $this->request->post('auto_publish', 0),
            ];
            try {
                $result = $this->engine->generateBatch($params);
                return $this->success('批量生成任务已创建', $result);
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        $this->assign('categoryOptions', $this->categoryOptions());
        return $this->fetch('admin/generation/batch');
    }

    /**
     * 任务状态
     */
    public function task()
    {
        $id = $this->request->param('id');
        $task = $this->engine->getTaskStatus((int)$id);
        return $this->success('', $task);
    }

    /**
     * 取消任务
     */
    public function cancel()
    {
        $id = $this->request->post('id');
        $this->engine->cancelTask((int)$id);
        return $this->success('任务已取消');
    }
}
