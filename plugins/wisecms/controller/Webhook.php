<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Webhook as WebhookModel;
use plugin\wisecms\service\model\MassAssignmentGuard;

/**
 * Webhook 订阅管理（后台）
 *
 * 注：`wisecms_webhook` **没有 `site_id`**（全站共享订阅表），故列表不跟随后台站点选择器
 * —— 页面需显式说明，否则"切站点后列表没变"会被当成 bug。
 *
 * 投递依赖 `wise-webhook` 包（`WebhookService::trigger()` 首行 `class_exists` 短路）
 * 且 `webhook_mode=queue` 时需要队列 worker，故列表/表单都下发这两个状态供前端警示（P0-23/P0-40）。
 */
class Webhook extends BaseController
{
    use PageFallbackTrait;

    /**
     * wise-webhook 是否可用：未安装时 `trigger()` 会**静默短路**，订阅形同虚设
     */
    private function dependencyInstalled(): bool
    {
        return class_exists('wise\webhook\facade\Webhook');
    }

    /**
     * 投递模式：auto / queue / sync（`queue` 必须部署 `php think queue:work`）
     */
    private function deliveryMode(): string
    {
        return (string) config('wisecms.webhook_mode', 'auto');
    }

    public function index()
    {
        // ⚠️ 固定页长 15（不读 limit）→ 前端 fixedPageSize 隐藏每页条数选择器
        $list   = WebhookModel::order('id', 'desc')->paginate(15);
        $result = $list->toArray();

        // UIUX-P0-23：密钥脱敏 —— 列表**不需要**明文密钥（前端只显示「已设置 / 未设置」）。
        // 回传明文会让密钥进入浏览器内存、代理缓存与日志（"不全量回显"）。
        $result['data'] = array_map(static function (array $row): array {
            $row['has_secret'] = !empty($row['secret']);
            unset($row['secret']);

            return $row;
        }, $result['data']);

        if ($this->request->isAjax()) {
            return $this->success('', $result);
        }

        $this->assign('list', $result);
        $this->assign('webhookMode', $this->deliveryMode());
        $this->assign('webhookInstalled', $this->dependencyInstalled());

        return $this->fetch('admin/webhook/index');
    }

    public function create()
    {
        if ($this->request->isPost()) {
            // P1-1：黑名单剔除 id / create_time / retry_count / last_status 等非表单字段
            // （投递统计列由 WebhookService 写入，不接受客户端提交）
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_webhook');

            // 空事件 = 订阅了一个**永远不会触发**的钩子（前端已校验，这里是服务端兜底）
            if (trim((string) ($data['events'] ?? '')) === '') {
                return $this->error('请至少指定一个触发事件');
            }

            // create_time / update_time 由模型 autoWriteTimestamp 维护
            WebhookModel::create($data);
            return $this->success('Webhook创建成功');
        }

        $this->assign('webhookMode', $this->deliveryMode());
        $this->assign('webhookInstalled', $this->dependencyInstalled());

        return $this->fetch('admin/webhook/create');
    }

    public function delete()
    {
        try {
            WebhookModel::destroy($this->request->post('id'));
            return $this->success('Webhook已删除');
        } catch (\Throwable $e) {
            cms_log('error', 'Webhook 删除失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 编辑 Webhook（UIUX-P1-16）
     *
     * GET 回显：`secret` **不回显**（密钥不全量回显，与列表脱敏一致），留空表示不修改；
     * POST 更新：`secret` 留空 = 保持原值，填写 = 更新。
     */
    public function edit()
    {
        $id   = (int) $this->request->param('id');
        $hook = WebhookModel::find($id);
        if (!$hook) {
            return $this->pageFallback('Webhook 不存在', plugin_url('wisecms/webhook/index'));
        }

        if ($this->request->isPost()) {
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_webhook');

            if (trim((string) ($data['events'] ?? '')) === '') {
                return $this->error('请至少指定一个触发事件');
            }

            // 密钥留空 = 不修改（避免编辑时误把密钥清空）
            if (array_key_exists('secret', $data) && trim((string) $data['secret']) === '') {
                unset($data['secret']);
            }

            $hook->save($data);
            return $this->success('Webhook 已更新');
        }

        $hook->secret = ''; // 不回显密钥
        $this->assign('hook', $hook);
        $this->assign('webhookMode', $this->deliveryMode());
        $this->assign('webhookInstalled', $this->dependencyInstalled());
        return $this->fetch('admin/webhook/edit');
    }

    /**
     * 启用 / 禁用 Webhook（UIUX-P1-16，目标值语义）
     */
    public function toggleStatus()
    {
        $id     = (int) $this->request->post('id');
        $status = (int) $this->request->post('status');
        $status = $status === 1 ? 1 : 0;

        $hook = WebhookModel::find($id);
        if (!$hook) {
            return $this->pageFallback('Webhook 不存在', plugin_url('wisecms/webhook/index'));
        }

        $hook->status = $status;
        $hook->save();
        return $this->success($status === 1 ? '已启用' : '已禁用');
    }

    /**
     * 测试投递（UIUX-P1-16）：发一个 `test` 事件到回调 URL，返回状态码 + 耗时
     */
    public function test()
    {
        $id   = (int) $this->request->post('id');
        $hook = WebhookModel::find($id);
        if (!$hook) {
            return $this->pageFallback('Webhook 不存在', plugin_url('wisecms/webhook/index'));
        }

        if (!class_exists('wise\\webhook\\facade\\Webhook')) {
            return $this->error('wise-webhook 组件未安装，无法测试投递');
        }

        try {
            $call = \wise\webhook\facade\Webhook::serverCreate()
                ->url($hook->url)
                ->payload(['event' => 'test', 'timestamp' => time(), 'data' => ['test' => true]])
                ->method('POST')
                ->timeout(max(1, (int) config('wisecms.webhook_timeout', 5)))
                ->connectTimeout(3)
                ->useSecret((string) $hook->secret)
                ->withHeaders(['X-WISECMS-Event' => 'test']);

            $result = $call->dispatchSync();

            return $this->success('', [
                'success'    => (bool) ($result['success'] ?? false),
                'code'       => (int) ($result['code'] ?? 0),
                'elapsed_ms' => (int) ($result['duration_ms'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            cms_log('warning', 'Webhook 测试投递失败: ' . $e->getMessage(), ['url' => $hook->url]);
            return $this->error('测试投递失败：' . WisecmsException::userMessage($e));
        }
    }
}
