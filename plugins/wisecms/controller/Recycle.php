<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\exception\ErrorCode;
use plugin\BaseController;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\model\Content;
class Recycle extends BaseController
{
    protected ContentService $contentService;

    protected function initPlugin(): void
    {
        $this->contentService = new ContentService();
    }

    /**
     * 回收站列表
     */
    public function index()
    {
        $params = [
            'keyword' => $this->request->param('keyword', ''),
            // P1-B6：分页上限收敛
            'page'    => max(1, (int)$this->request->param('page', 1)),
            'limit'   => min(100, max(1, (int)$this->request->param('limit', 15))),
        ];

        // P2：回收站按「后台当前站点」过滤（原实现把各站回收站混在一张列表里）
        $query = Content::onlyTrashed()
            // UIUX-P0-12：带出栏目名 —— 回收站的判断依据之一是「这篇属于哪个栏目」，
            // 只显示 catid 数字没有可读性。纯读增强，不触碰任何写路径。
            ->with(['category'])
            ->where('site_id', (new SiteService())->resolveAdminSiteId())
            ->order('delete_time', 'desc');
        if (!empty($params['keyword'])) {
            // P1-B6：转义 LIKE 通配符
            $keyword = addcslashes((string) $params['keyword'], '%_\\');
            $query->where('title', 'like', "%{$keyword}%");
        }

        $list = $query->paginate(['page' => $params['page'], 'list_rows' => $params['limit']]);

        if ($this->request->isAjax()) {
            return $this->success('', $list->toArray());
        }

        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $list->toArray());
        return $this->fetch('admin/recycle/index');
    }

    /**
     * 恢复内容
     */
    public function restore()
    {
        $id = $this->request->post('id');
        try {
            $this->assertSiteOwned((int) $id);
            $this->contentService->restore((int)$id);
            return $this->success('内容已恢复');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 彻底删除
     */
    public function destroy()
    {
        $id = $this->request->post('id');
        try {
            $this->assertSiteOwned((int) $id);
            $this->contentService->forceDelete((int)$id);
            return $this->success('内容已彻底删除');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 清空回收站
     *
     * P1-B4：单条失败不再中断整批（原实现一条异常即 500，前面的已删、后面的没删，
     * 且没有任何反馈）。forceDelete() 内部各自带事务（关联 + 版本 + 主表原子）。
     */
    public function clear()
    {
        // P2：只清空**当前站点**的回收站（否则在站点 A 的上下文里会连带清掉站点 B 的数据）
        $trashed = Content::onlyTrashed()
            ->where('site_id', (new SiteService())->resolveAdminSiteId())
            ->select();
        $failed  = 0;

        foreach ($trashed as $item) {
            try {
                $this->contentService->forceDelete((int) $item->id);
            } catch (\Throwable $e) {
                $failed++;
                cms_log('error', '清空回收站失败: ' . $e->getMessage(), ['content_id' => $item->id]);
            }
        }

        if ($failed > 0) {
            return $this->error('已清理 ' . ($trashed->count() - $failed) . " 条，{$failed} 条失败（详情见系统日志）");
        }

        return $this->success('回收站已清空');
    }

    /**
     * 批量恢复（UIUX-P1-19，单条失败不中断整批）
     */
    public function batchRestore()
    {
        $ids = $this->ownedTrashedIds();
        if (empty($ids)) {
            return $this->error('请选择要恢复的内容');
        }

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->contentService->restore((int) $id);
            } catch (\Throwable $e) {
                $failed++;
                cms_log('error', '批量恢复失败: ' . $e->getMessage(), ['content_id' => $id]);
            }
        }

        $ok = count($ids) - $failed;
        if ($failed > 0) {
            return $this->error("已恢复 {$ok} 条，{$failed} 条失败（详情见系统日志）");
        }
        return $this->success("已恢复 {$ok} 条");
    }

    /**
     * 批量彻底删除（UIUX-P1-19，单条失败不中断整批；forceDelete 内部各自带事务）
     */
    public function batchDestroy()
    {
        $ids = $this->ownedTrashedIds();
        if (empty($ids)) {
            return $this->error('请选择要彻底删除的内容');
        }

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->contentService->forceDelete((int) $id);
            } catch (\Throwable $e) {
                $failed++;
                cms_log('error', '批量彻底删除失败: ' . $e->getMessage(), ['content_id' => $id]);
            }
        }

        $ok = count($ids) - $failed;
        if ($failed > 0) {
            return $this->error("已彻底删除 {$ok} 条，{$failed} 条失败（详情见系统日志）");
        }
        return $this->success("已彻底删除 {$ok} 条");
    }

    /**
     * 取「本站点回收站」中被选中的内容 id（越界静默剔除）
     */
    protected function ownedTrashedIds(): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids/a', [])))));
        if (empty($ids)) {
            return [];
        }
        return Content::onlyTrashed()
            ->whereIn('id', $ids)
            ->where('site_id', (new SiteService())->resolveAdminSiteId())
            ->column('id');
    }

    /**
     * 校验回收站内容属于「后台当前站点」（P2 多站点隔离）
     *
     * 越界一律按「内容不存在」抛错，不暴露「该 id 存在但属于别的站点」这一信息。
     */
    protected function assertSiteOwned(int $id): void
    {
        $owned = Content::onlyTrashed()
            ->where('id', $id)
            ->where('site_id', (new SiteService())->resolveAdminSiteId())
            ->find();

        if (!$owned) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }
    }
}
