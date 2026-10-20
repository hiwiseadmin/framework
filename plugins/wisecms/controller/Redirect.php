<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Redirect as RedirectModel;
use plugin\wisecms\service\content\RedirectService;
use plugin\wisecms\service\site\SiteService;

/**
 * URL 重定向（后台）
 *
 * UIUX-P0-20 期间核实并修正的三处站点问题：
 *   1. 列表原为全表查询（`Redirect::order(...)->paginate(20)`）→ **跨站混合显示**；
 *   2. 删除只按 id 删 → 站点 A 的编辑者可删站点 B 的规则；
 *   3. 死链检测硬编码 `checkDeadLinks(1)` → **无论当前站点是几，都在扫站点 1 的内容**。
 *
 * 重定向**没有「全站通用」语义**（`RedirectService::findRedirect()` 在 siteId>0 时精确匹配站点，
 * 唯一键也是 `(site_id, source_url)`），故列表按精确站点过滤即可 —— 与 `UrlRule` 的
 * 「0=全站通用」不同，不要照搬。
 */
class Redirect extends BaseController
{
    use PageFallbackTrait;

    /**
     * 后台当前站点（会话上下文，见 SiteService::resolveAdminSiteId）
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /**
     * 校验重定向属于「后台当前站点」
     *
     * 越界一律按「重定向不存在」处理，不暴露「该 id 存在但属于别的站点」。
     */
    protected function assertSiteOwned(int $id): void
    {
        $owned = RedirectModel::where('id', $id)->where('site_id', $this->siteId())->find();

        if (!$owned) {
            throw new WisecmsException('重定向不存在', ErrorCode::NOT_FOUND);
        }
    }

    public function index()
    {
        // ⚠️ 固定页长 20（不读 `page`/`limit` 之外的参数）→ 前端用 fixedPageSize 隐藏每页条数选择器
        $list = RedirectModel::where('site_id', $this->siteId())
            ->order('id', 'desc')
            ->paginate(20);

        if ($this->request->isAjax()) {
            return $this->success('', $list->toArray());
        }

        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $list->toArray());
        return $this->fetch('admin/redirect/index');
    }

    public function create()
    {
        if ($this->request->isPost()) {
            try {
                // 服务层负责 P1-1 字段防护 + P1-A2 目标 URL 协议白名单 + 站点归属
                (new RedirectService())->create($this->request->post());
                return $this->success('重定向创建成功');
            } catch (\Throwable $e) {
                cms_log('error', '重定向创建失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        return $this->fetch('admin/redirect/create');
    }

    /**
     * 编辑重定向（UIUX-P1-17，站点归属校验 + 服务层 update）
     */
    public function edit()
    {
        $id = (int) $this->request->param('id');

        // ⚠️ `assertSiteOwned()` 是**抛异常**式校验 —— 原实现漏了 catch，
        //    于是「无 ?id 直访」抛 WisecmsException 未捕获 → **500**
        //    （UIUX-V-03 复跑实测）。与其它动作（toggleStatus 等）保持同口径。
        try {
            $this->assertSiteOwned($id);
        } catch (WisecmsException $e) {
            return $this->pageFallback(
                WisecmsException::userMessage($e),
                plugin_url('wisecms/redirect/index')
            );
        }

        if ($this->request->isPost()) {
            try {
                (new RedirectService())->update($id, $this->request->post());
                return $this->success('重定向更新成功');
            } catch (\Throwable $e) {
                cms_log('error', '重定向更新失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }

        $this->assign('redirect', RedirectModel::find($id));
        return $this->fetch('admin/redirect/edit');
    }

    /**
     * 启用 / 禁用重定向（UIUX-P1-17，目标值语义 + 站点归属校验）
     */
    public function toggleStatus()
    {
        try {
            $id     = (int) $this->request->post('id');
            $status = (int) $this->request->post('status');
            $status = $status === 1 ? 1 : 0;

            $this->assertSiteOwned($id);
            $redirect = RedirectModel::find($id);
            $redirect->status = $status;
            $redirect->save();
            return $this->success($status === 1 ? '已启用' : '已禁用');
        } catch (\Throwable $e) {
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    public function import()
    {
        $file = $this->request->file('file');
        if (!$file) return $this->error('请选择CSV文件');
        try {
            $count = (new RedirectService())->importFromCsv($file->getRealPath());
            return $this->success("导入完成，共 {$count} 条", ['count' => $count]);
        } catch (\Throwable $e) {
            // P1-A5：文件/编码类底层异常不回显
            cms_log('error', '重定向 CSV 导入失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('导入失败，请检查 CSV 格式后重试');
        }
    }

    public function delete()
    {
        try {
            $this->assertSiteOwned((int) $this->request->post('id', 0));
            RedirectModel::destroy($this->request->post('id'));
            return $this->success('已删除');
        } catch (\Throwable $e) {
            cms_log('error', '重定向删除失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 死链检测（**同步**全表扫描，无进度接口）
     *
     * 只扫「当前站点」的已发布内容 —— 原实现硬编码 `checkDeadLinks(1)`。
     */
    public function checkDead()
    {
        try {
            $result = (new RedirectService())->checkDeadLinks($this->siteId());
            return $this->success('', ['dead_count' => count($result), 'list' => $result]);
        } catch (\Throwable $e) {
            cms_log('error', '死链检测失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('死链检测失败，请稍后重试');
        }
    }
}
