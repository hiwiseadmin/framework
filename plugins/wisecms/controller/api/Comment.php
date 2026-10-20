<?php
namespace plugin\wisecms\controller\api;


use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\content\CommentService;
/**
 * CMS 评论 API 控制器
 *
 * 限流：所有操作 20次/分钟（令牌桶：容量20, 速率0.333/s）
 *
 * P1-D：评论从属内容，故所有入口先校验「目标内容属于当前站点」——
 * 原实现只校验 `content_id` 存在，遍历 id 即可读/写其它站点内容的评论。
 */
class Comment extends Base
{
    protected $middleware = [
        \wise\api\middleware\RateLimitMiddleware::class . ':20,0.333',
    ];

    /**
     * 评论列表
     */
    public function index()
    {
        $params = $this->validateRequest([
            'content_id' => 'require|integer|egt:1',
            'page'       => 'integer|egt:1',
            'limit'      => 'integer|between:1,50',
        ]);

        // P1-D：只能读当前站点内容的评论（越界按「内容不存在」处理，不暴露存在性）
        if (!$this->findSiteContent((int) $params['content_id'])) {
            return $this->error('内容不存在', [], 4001);
        }

        $svc = new CommentService();
        $list = $svc->getList(
            (int)$params['content_id'],
            (int)($params['page'] ?? 1),
            (int)($params['limit'] ?? 20)
        );
        return $this->success('ok', $list);
    }

    /**
     * 发表评论
     */
    public function create()
    {
        $params = $this->validateRequest([
            'content_id' => 'require|integer|egt:1',
            'parent_id'  => 'integer|egt:0',
            'username'   => 'max:50',
            'content'    => 'require|max:5000',
        ], [
            'content_id.require' => '内容ID不能为空',
            'content.require'    => '评论内容不能为空',
            'content.max'        => '评论内容不能超过5000字',
        ]);

        // P1-D：不允许给其它站点的内容发表评论
        if (!$this->findSiteContent((int) $params['content_id'])) {
            return $this->error('内容不存在', [], 4001);
        }

        $username = $params['username'] ?? '网友';
        $data = [
            'content_id' => (int)$params['content_id'],
            'parent_id'  => (int)($params['parent_id'] ?? 0),
            'username'   => htmlspecialchars(strip_tags($username), ENT_QUOTES, 'UTF-8'),
            'content'    => $params['content'],
            'ip'         => request()->ip(),
        ];

        try {
            $comment = (new CommentService())->create($data);
            return $this->success('评论发表成功', $comment);
        } catch (WisecmsException $e) {
            cms_log('error', '评论发表失败: ' . $e->getMessage(), ['code' => $e->getErrorCode()]);
            return $this->error($e->getMessage(), [], 4000);
        } catch (\Exception $e) {
            cms_log('error', '评论发表失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('评论发表失败，请稍后重试', [], 1500);
        }
    }

    /**
     * 点赞/踩
     */
    public function like()
    {
        $params = $this->validateRequest([
            'comment_id' => 'require|integer|egt:1',
            'type'       => 'in:0,1',
        ]);

        $commentId = (int)$params['comment_id'];
        $type      = (int)($params['type'] ?? 1);

        // 点赞必须绑定真实用户：原先未登录时 member_id 兜底为 0，
        // 导致所有匿名用户共用同一条记录、可互相「取消点赞」
        $memberId = (int)(request()->member['id'] ?? 0);
        if ($memberId <= 0) {
            return $this->error('请先登录后再操作', [], 4001);
        }
        // P1-D：评论须存在、已通过审核，且其所属内容属于当前站点
        $comment = \plugin\wisecms\model\Comment::where('id', $commentId)->where('status', 1)->find();
        if (!$comment || !$this->findSiteContent((int) $comment->content_id)) {
            return $this->error('评论不存在', [], 4001);
        }

        $result = (new CommentService())->toggleLike($commentId, $memberId, $type);
        return $this->success('ok', $result);
    }
}
