<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\model\MassAssignmentGuard;

class Spider extends BaseController
{
    use PageFallbackTrait;

    public function index()
    {
        $list = \plugin\wisecms\model\SpiderTask::order('id', 'desc')->paginate(15);
        $arr  = $list->toArray();

        // UIUX-P0-22：附加目标栏目名。`spider_task` 只有 `target_catid` 数字，
        // 列表显示栏目名才有可读性；栏目被软删/禁用时降级显示 catid 数字（纯读增强，不碰写路径）。
        $catNames = \plugin\wisecms\model\Category::column('name', 'id');
        foreach ($arr['data'] as &$row) {
            $row['cat_name'] = $catNames[$row['target_catid']] ?? '';
        }
        unset($row);

        if ($this->request->isAjax()) return $this->success('', $arr);
        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $arr);
        return $this->fetch('admin/spider/index');
    }

    public function create()
    {
        if ($this->request->isPost()) {
            // P1-1：黑名单剔除 id / create_time / last_run_time / 计数列等非表单字段
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_spider_task');
            $data['config'] = json_encode(['list_url' => $data['list_url'] ?? '', 'content_rules' => []], JSON_UNESCAPED_UNICODE);
            unset($data['list_url']);
            // create_time / update_time 由模型 autoWriteTimestamp 维护
            \plugin\wisecms\model\SpiderTask::create($data);
            return $this->success('采集任务创建成功');
        }
        $catOpts = (new \plugin\wisecms\service\content\CategoryService())->getOptions();
        $this->assign('catOpts', $catOpts);
        return $this->fetch('admin/spider/create');
    }

    /**
     * 编辑采集任务（UIUX-P1-15）
     *
     * GET 回显：`config` 是 JSON（`list_url` + `content_rules`），表单只回显 `list_url`；
     * POST 更新：与 create 口径一致，仅更新 `list_url`，保留原 `content_rules`。
     */
    public function edit()
    {
        $id   = (int) $this->request->param('id');
        $task = \plugin\wisecms\model\SpiderTask::find($id);
        if (!$task) {
            return $this->pageFallback('采集任务不存在', plugin_url('wisecms/spider/index'));
        }

        if ($this->request->isPost()) {
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_spider_task');
            $oldConfig = json_decode((string) $task->config, true);
            if (!is_array($oldConfig)) {
                $oldConfig = [];
            }
            $data['config'] = json_encode([
                'list_url'      => $data['list_url'] ?? '',
                'content_rules' => $oldConfig['content_rules'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
            unset($data['list_url']);
            $task->save($data);
            return $this->success('采集任务更新成功');
        }

        $config = json_decode((string) $task->config, true);
        if (!is_array($config)) {
            $config = [];
        }
        $taskArr = $task->toArray();
        $taskArr['list_url'] = $config['list_url'] ?? '';
        $catOpts = (new \plugin\wisecms\service\content\CategoryService())->getOptions();
        $this->assign('task', $taskArr);
        $this->assign('catOpts', $catOpts);
        return $this->fetch('admin/spider/edit');
    }

    public function run()
    {
        $id = (int)$this->request->post('id');
        try {
            $result = (new \plugin\wisecms\service\engine\SpiderService())->runTask($id);
            return $this->success("采集完成，共 {$result['count']} 篇", $result);
        } catch (WisecmsException $e) {
            // 业务异常（如任务不存在）文案可安全回显
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            // P1-A5：底层异常细节只落日志，不回显（防泄露 SQL / 路径）
            cms_log('error', '采集任务执行失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'id' => $id]);
            return $this->error('采集失败，详情请查看系统日志');
        }
    }

    public function delete()
    {
        \plugin\wisecms\model\SpiderTask::destroy($this->request->post('id'));
        return $this->success('任务已删除');
    }
}
