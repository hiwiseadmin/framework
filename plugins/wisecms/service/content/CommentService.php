<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Comment;
use plugin\wisecms\model\CommentLike;
use plugin\wisecms\model\Content;
use plugin\wisecms\service\integration\NotificationService;
use plugin\wisecms\service\integration\WebhookService;
use think\facade\Db;

class CommentService
{
    /** 评论审核模式 */
    const CHECK_NONE   = 0;
    const CHECK_FIRST  = 1;
    const CHECK_AFTER  = 2;

    /** 评论审核状态（`wisecms_comment.status`） */
    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    /**
     * 获取内容评论列表（树形结构）
     */
    public function getList(int $contentId, int $page = 1, int $limit = 20): array
    {
        $comments = Comment::where('content_id', $contentId)
            ->where('parent_id', 0)
            ->where('status', 1)
            ->order('id', 'desc')
            ->with(['children' => function($q) { $q->where('status', 1)->order('id', 'asc')->limit(5); }])
            ->page($page, $limit)
            ->select();

        $total = Comment::where('content_id', $contentId)->where('parent_id', 0)->where('status', 1)->count();

        return [
            'list' => $comments->toArray(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * 获取子评论
     */
    public function getChildren(int $parentId, int $page = 1, int $limit = 10): array
    {
        $list = Comment::where('parent_id', $parentId)
            ->where('status', 1)
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()->toArray();
        $total = Comment::where('parent_id', $parentId)->where('status', 1)->count();
        return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * 发表评论
     */
    public function create(array $data): Comment
    {
        if (empty($data['content'])) throw new WisecmsException('评论内容不能为空', ErrorCode::VALIDATE_FAILED);
        if (empty($data['content_id'])) throw new WisecmsException('内容ID不能为空', ErrorCode::VALIDATE_FAILED);

        // P1-26：评论从属内容 —— 内容必须存在（软删除内容由 SoftDelete 自动排除），
        // 并由内容推导 site_id。原实现完全不校验内容存在性 → 可以给不存在的 content_id
        // 落一条"孤儿评论"，且站点归属无从判断。
        $content = Content::find((int) $data['content_id']);
        if (!$content) throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);

        // site_id 一律服务端推导：忽略调用方传值，防越权把评论挂到别的站点名下
        $data['site_id'] = (int) $content->site_id;

        // XSS 防护：过滤评论内容
        $data['content'] = htmlspecialchars(strip_tags(trim($data['content'])));

        // 评论间隔检测（使用统一 IP 来源）
        $ip = $data['ip'] ?? request()->ip();
        $lastComment = Comment::where('ip', $ip)
            ->order('id', 'desc')->find();
        // create_time 已统一为 DATETIME，比较前需转时间戳（原实现依赖 int 列的隐式转换）
        if ($lastComment && (time() - cms_timestamp($lastComment->create_time)) < 60) {
            throw new WisecmsException('评论过于频繁，请稍后再试', ErrorCode::VALIDATE_FAILED);
        }

        // create_time / update_time 由模型 autoWriteTimestamp 维护
        $data['status'] = $this->getDefaultStatus($data['content_id']);
        $data['ip'] = $ip;

        // 计算深度和根评论
        if (!empty($data['parent_id'])) {
            $parent = Comment::find($data['parent_id']);
            // P1-26：父评论必须存在且属于**同一篇内容**。
            // 原实现 `if ($parent)` 对不存在的 parent_id 静默放过 —— 评论会带着悬空的
            // parent_id 落库，前台既不在根列表（parent_id≠0）也不在任何树下，等于"消失"。
            if (!$parent || (int) $parent->content_id !== (int) $data['content_id']) {
                throw new WisecmsException('父评论不存在', ErrorCode::VALIDATE_FAILED);
            }
            $data['depth'] = $parent->depth + 1;
            $data['root_id'] = $parent->root_id ?: $parent->id;
            if ($data['depth'] > 3) throw new WisecmsException('评论嵌套过深', ErrorCode::VALIDATE_FAILED);
        }

        $comment = Comment::create($data);

        // 事件通知 + Webhook（接线 NotificationService / WebhookService）
        (new NotificationService())->notifyNewComment($comment->id, $comment->content_id);
        (new WebhookService())->trigger('comment.created', [
            'comment_id' => $comment->id,
            'content_id' => $comment->content_id,
            'username'   => $comment->username,
        ]);

        // 更新内容评论计数（可选）

        return $comment;
    }

    /**
     * 审核评论
     */
    public function approve(int $id): Comment
    {
        $comment = Comment::find($id);
        if (!$comment) throw new WisecmsException('评论不存在', ErrorCode::NOT_FOUND);
        $comment->status = self::STATUS_APPROVED;
        $comment->save();
        return $comment;
    }

    /**
     * 拒绝评论
     */
    public function reject(int $id): Comment
    {
        $comment = Comment::find($id);
        if (!$comment) throw new WisecmsException('评论不存在', ErrorCode::NOT_FOUND);
        $comment->status = self::STATUS_REJECTED;
        $comment->save();
        return $comment;
    }

    /**
     * 删除评论（递归删除所有子孙评论，避免孤儿节点）
     *
     * P1-C：同时级联删除点赞/踩记录 —— `comment_like` 以 `comment_id` 归属评论，
     * 只删评论会让点赞行成为永久孤儿（且内容 id 复用时会「继承」历史点赞数）。
     */
    public function delete(int $id): bool
    {
        $comment = Comment::find($id);
        if (!$comment) throw new WisecmsException('评论不存在', ErrorCode::NOT_FOUND);

        // 递归收集所有需要删除的子孙评论 ID
        $allIds = $this->collectDescendantIds($id);
        $allIds[] = $id;

        Db::startTrans();
        try {
            CommentLike::whereIn('comment_id', $allIds)->delete();
            Comment::whereIn('id', $allIds)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return true;
    }

    /**
     * 递归收集所有子孙评论 ID
     */
    protected function collectDescendantIds(int $parentId): array
    {
        $ids = [];
        $children = Comment::where('parent_id', $parentId)->column('id');
        foreach ($children as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, $this->collectDescendantIds($childId));
        }
        return $ids;
    }

    /**
     * 点赞/踩
     */
    public function toggleLike(int $commentId, int $memberId, int $type = 1): array
    {
        $exists = CommentLike::where('comment_id', $commentId)->where('member_id', $memberId)->find();
        if ($exists) {
            $exists->delete();
            $action = 'removed';
        } else {
            try {
                // create_time 由模型 autoWriteTimestamp 维护
                CommentLike::create(['comment_id' => $commentId, 'member_id' => $memberId, 'type' => $type]);
                $action = 'added';
            } catch (\Throwable $e) {
                // P1-C：唯一键 (comment_id, member_id) 冲突 = 并发重复投票（连点/多标签页），
                // 语义等价于「已投票」，按幂等处理而不是抛 500
                if (!cms_is_duplicate_key($e)) {
                    throw $e;
                }
                $action = 'added';
            }
        }

        // 更新计数
        $likes = CommentLike::where('comment_id', $commentId)->where('type', 1)->count();
        $dislikes = CommentLike::where('comment_id', $commentId)->where('type', 0)->count();
        Comment::where('id', $commentId)->update(['like_count' => $likes, 'dislike_count' => $dislikes]);

        return ['action' => $action, 'likes' => $likes, 'dislikes' => $dislikes];
    }

    /**
     * 后台评论列表（可按审核状态过滤）—— UIUX-P0-13
     *
     * 背景：原 `Comment::index` 只特判 `status === '0'`，其余一律走「全部」分支
     * → 前端做「已通过 / 已拒绝」筛选时，传 `1`/`2` 会被**静默忽略并返回全部数据**
     * （筛选器看着能点，实际没生效）。这里把状态过滤收进服务层，两种列表共用一条查询。
     *
     * @param int      $siteId 后台当前站点（必传：P1-26 站点隔离，调用方从 SiteService::resolveAdminSiteId 取）
     * @param int|null $status 0 待审 / 1 已通过 / 2 已拒绝；`null` 或非法值 = 全部
     * @param int      $page
     * @param int      $limit
     * @return array 标准 paginate 形状（total / per_page / current_page / last_page / data）
     */
    public function getAdminList(int $siteId, ?int $status, int $page = 1, int $limit = 20): array
    {
        $query = Comment::with(['article'])
            ->where('site_id', $siteId)
            ->order('id', 'desc');

        $allowed = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
        if ($status !== null && in_array($status, $allowed, true)) {
            $query->where('status', $status);
        }

        return $query->paginate([
            'page'      => max(1, $page),
            'list_rows' => max(1, $limit),
        ])->toArray();
    }

    /**
     * 获取待审核评论
     *
     * 保留公开方法（语义 = `getAdminList($siteId, STATUS_PENDING, ...)`），避免调用点断链。
     */
    public function getPendingList(int $siteId, int $page = 1, int $limit = 20): array
    {
        return $this->getAdminList($siteId, self::STATUS_PENDING, $page, $limit);
    }

    /**
     * 获取默认审核状态
     */
    protected function getDefaultStatus(int $contentId): int
    {
        $check = config('wisecms.comment_check', 1);
        return match((int)$check) {
            self::CHECK_NONE => 1,
            self::CHECK_FIRST => 0,
            self::CHECK_AFTER => 1,
            default => 0,
        };
    }
}
