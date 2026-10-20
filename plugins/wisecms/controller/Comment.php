<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Comment as CommentModel;
use plugin\wisecms\service\content\CommentService;
use plugin\wisecms\service\site\SiteService;

/**
 * 评论管理（后台）
 *
 * P1-26：评论按站点隔离 —— 列表按「后台当前站点」过滤，审核/删除先校验评论归属，
 * 与 `Content` / `Recycle` 控制器使用同一套站点口径（`SiteService::resolveAdminSiteId()`）。
 */
class Comment extends BaseController
{
    protected CommentService $commentService;

    protected function initPlugin(): void
    {
        $this->commentService = new CommentService();
    }

    /**
     * 后台当前站点（会话上下文，不是域名 —— 见 SiteService::resolveAdminSiteId）
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /**
     * 校验评论属于「后台当前站点」（P1-26 多站点隔离）
     *
     * 原实现只校验 id 存在 → 站点 A 的编辑者（持有评论权限）可对站点 B 的评论 id
     * 审核 / 拒绝 / 删除。越界一律按「评论不存在」处理，不暴露
     * 「该 id 存在但属于别的站点」这一信息。
     */
    protected function assertSiteOwned(int $id): void
    {
        $owned = CommentModel::where('id', $id)
            ->where('site_id', $this->siteId())
            ->find();

        if (!$owned) {
            throw new WisecmsException('评论不存在', ErrorCode::NOT_FOUND);
        }
    }

    /**
     * 评论管理列表
     */
    public function index()
    {
        $status = $this->request->param('status', '');
        $page = max(1, (int)$this->request->param('page', 1));
        // P1-19b（评论侧）：原实现无上限，`limit=999999` 可拉全表 → 与其它列表统一收敛到 100
        $limit = min(100, max(1, (int)$this->request->param('limit', 20)));

        // UIUX-P0-13：status 只认 '0'/'1'/'2' 三个字面值，其余（含 '' 与非法串）= 全部。
        // 不能直接 (int) 强转 —— `status=abc` 会变成 0，反而筛出「待审核」。
        $statusFilter = in_array((string) $status, ['0', '1', '2'], true) ? (int) $status : null;

        // P1-26：列表按站点过滤（此前跨站显示）
        $list = $this->commentService->getAdminList($this->siteId(), $statusFilter, $page, $limit);

        if ($this->request->isAjax()) {
            return $this->success('', $list);
        }

        $this->assign('list', $list);
        $this->assign('status', $status);
        return $this->fetch('admin/comment/index');
    }

    /**
     * 审核通过
     */
    public function approve()
    {
        $id = $this->request->post('id');
        try {
            $this->assertSiteOwned((int) $id);
            $this->commentService->approve((int)$id);
            return $this->success('审核通过');
        } catch (\Exception $e) {
            return $this->fail($e);
        }
    }

    /**
     * 拒绝
     */
    public function reject()
    {
        $id = $this->request->post('id');
        try {
            $this->assertSiteOwned((int) $id);
            $this->commentService->reject((int)$id);
            return $this->success('已拒绝');
        } catch (\Exception $e) {
            return $this->fail($e);
        }
    }

    /**
     * 删除评论（级联删除其下所有回复与点赞记录）
     */
    public function delete()
    {
        $id = $this->request->post('id');
        try {
            $this->assertSiteOwned((int) $id);
            $this->commentService->delete((int)$id);
            return $this->success('评论已删除');
        } catch (\Exception $e) {
            return $this->fail($e);
        }
    }

    /**
     * 批量审核通过（UIUX-P1-19，单条失败不中断整批）
     */
    public function batchApprove()
    {
        return $this->batchAct('approve', '审核通过');
    }

    /**
     * 批量拒绝（UIUX-P1-19）
     */
    public function batchReject()
    {
        return $this->batchAct('reject', '拒绝');
    }

    /**
     * 批量审核 / 拒绝的统一实现（站点过滤 + 单条容错）
     */
    protected function batchAct(string $method, string $verb): mixed
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids/a', [])))));
        if (empty($ids)) {
            return $this->error('请选择要操作的评论');
        }

        // 站点过滤：越界静默剔除
        $owned = CommentModel::whereIn('id', $ids)->where('site_id', $this->siteId())->column('id');
        if (empty($owned)) {
            return $this->error('所选评论均不属于当前站点');
        }

        $failed = 0;
        foreach ($owned as $id) {
            try {
                $this->commentService->{$method}((int) $id);
            } catch (\Throwable $e) {
                $failed++;
                cms_log('error', "评论批量{$verb}失败: " . $e->getMessage(), ['comment_id' => $id]);
            }
        }

        $ok = count($owned) - $failed;
        if ($failed > 0) {
            return $this->error("已{$verb} {$ok} 条，{$failed} 条失败（详情见系统日志）");
        }
        return $this->success("已{$verb} {$ok} 条");
    }

    /**
     * 统一失败出口
     *
     * 业务异常（如「评论不存在」）如实回显，其余落日志并给通用文案 ——
     * 原实现把两者混为一谈，越界访问会显示「操作失败，请稍后重试」，掩盖了真实原因。
     */
    protected function fail(\Exception $e)
    {
        cms_log('error', '评论操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

        return $this->error(WisecmsException::userMessage($e));
    }
}
