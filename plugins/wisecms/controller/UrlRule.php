<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\BaseController;
use plugin\wisecms\model\UrlRule as UrlRuleModel;
use plugin\wisecms\service\url\UrlRuleService;
use plugin\wisecms\service\url\UrlService;

/**
 * URL 规则后台管理
 */
class UrlRule extends BaseController
{
    use PageFallbackTrait;

    protected UrlRuleService $service;

    protected function initPlugin(): void
    {
        $this->service = new UrlRuleService();
    }

    /**
     * 规则列表
     */
    public function index()
    {
        $filter = [];
        if ($this->request->param('type', '') !== '') {
            $filter['type'] = (string) $this->request->param('type');
        }

        // P2：URL 规则按站点隔离（服务层早已支持 site_id 过滤，原控制器未传 → 列表跨站混合）
        // UIUX-P0-19：同时带上 `0`（全站通用）—— 该值在 matchRules() 里对任何站点都生效，
        // 只查精确站点会让「全站规则」在后台列表里消失。
        $filter['site_id'] = [0, (new \plugin\wisecms\service\site\SiteService())->resolveAdminSiteId()];

        $list = $this->service->getRules($filter);

        if ($this->request->isAjax()) {
            return $this->success('', $list);
        }

        $this->assign('list', $list);
        return $this->fetch('admin/url_rule/index');
    }

    /**
     * 预设模板与可用占位符
     */
    public function presets()
    {
        return $this->success('', [
            'presets' => UrlRuleService::PRESETS,
            'tokens'  => ['id', 'catid', 'model', 'slug', 'alias', 'tag', 'catdir', 'year', 'month', 'day', 'page'],
            'rewrite' => (new UrlService())->rewriteEnabled(),
        ]);
    }

    /**
     * 新建规则
     */
    public function create()
    {
        if ($this->request->isPost()) {
            try {
                return $this->success('规则创建成功', $this->service->saveRule($this->request->post()));
            } catch (\Throwable $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }

        $this->assign('presets', UrlRuleService::PRESETS);
        $this->assign('presetsJson', json_encode(UrlRuleService::PRESETS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->fetch('admin/url_rule/create');
    }

    /**
     * 编辑规则
     */
    public function edit()
    {
        $id = (int) $this->request->param('id', 0);

        if ($this->request->isPost()) {
            try {
                $data       = $this->request->post();
                $data['id'] = $id;
                return $this->success('规则更新成功', $this->service->saveRule($data));
            } catch (\Throwable $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }

        $rule = UrlRuleModel::find($id);
        if (!$rule) {
            return $this->pageFallback('规则不存在', plugin_url('wisecms/urlRule/index'));
        }

        $this->assign('rule', $rule);
        $this->assign('presets', UrlRuleService::PRESETS);
        return $this->fetch('admin/url_rule/edit');
    }

    /**
     * 删除规则
     */
    public function delete()
    {
        $this->service->deleteRule((int) $this->request->post('id', 0));
        return $this->success('已删除');
    }

    /**
     * 启用/停用
     *
     * 传 `status` 时按**目标值**写入（幂等）—— 列表的行内开关发的就是目标值；
     * 只做取反的话，重复/并发点击会把状态翻回去，表现为「开关随机失灵」。
     * 与 `Category::toggleStatus` / `Model::toggleStatus` 同一处理，见任务清单 §1.1⑬。
     */
    public function toggleStatus()
    {
        $rule = UrlRuleModel::find((int) $this->request->post('id', 0));
        if (!$rule) {
            return $this->pageFallback('规则不存在', plugin_url('wisecms/urlRule/index'));
        }

        $target = $this->request->post('status', null);
        if ($target === null) {
            // 兼容只传 id 的旧调用：取反
            $status = (int) $rule->status === 1 ? 0 : 1;
        } else {
            // 白名单：非法值一律保持原状态，不写入
            $status = in_array((int) $target, [0, 1], true) ? (int) $target : (int) $rule->status;
        }

        $rule->save(['status' => $status]);
        $this->service->clearCache();

        return $this->success('状态已更新');
    }

    /**
     * 重建别名索引（全站 URL 按当前规则重新生成）
     */
    public function rebuild()
    {
        try {
            $stats = (new UrlService())->rebuildAliases();
            return $this->success(
                "重建完成：内容 {$stats['content']} / 栏目 {$stats['category']} / 标签 {$stats['tag']} / 单页 {$stats['page']}",
                $stats
            );
        } catch (\Throwable $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }
}
