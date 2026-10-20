<?php
namespace plugin\wisecms\service\integration;

use wise\notification\facade\Notification;

/**
 * 站内通知
 *
 * 通过 wise-notification 的 database 通道发送站内信：
 *   - admin  → 广播给所有启用状态的管理员（app\admin\model\Admin，已实现 Notifiable）；
 *   - member → 发送给指定会员（wise\member\model\Member，已实现 Notifiable）。
 *
 * 说明：
 *   - 站内信为本地写库，走 sync() 立即发送，不依赖队列 worker；
 *   - 后续接入邮件/短信等外部通道时，改用 channel(['database','email']) 并去掉 sync() 走队列。
 */
class NotificationService
{
    /**
     * 发送内容审核通知
     */
    public function notifyContentReview(int $contentId, string $action, string $reviewer = ''): void
    {
        $content = \plugin\wisecms\model\Content::find($contentId);
        if (!$content) return;

        $messages = [
            'submit'  => "「{$content->title}」已提交审核",
            'approve' => "「{$content->title}」审核通过",
            'reject'  => "「{$content->title}」被驳回",
            'publish' => "「{$content->title}」已发布",
        ];

        $msg = $messages[$action] ?? "内容状态变更: {$content->title}";
        $this->notifyAdmins($msg, 'content_review', ['content_id' => $contentId, 'action' => $action]);
    }

    /**
     * 发送评论通知
     */
    public function notifyNewComment(int $commentId, int $contentId): void
    {
        $comment = \plugin\wisecms\model\Comment::find($commentId);
        $content = \plugin\wisecms\model\Content::find($contentId);
        if (!$comment || !$content) return;

        $msg = "「{$content->title}」收到新评论: {$comment->username}";
        $this->notifyAdmins($msg, 'comment_new', ['content_id' => $contentId, 'comment_id' => $commentId]);
    }

    /**
     * 发送评论回复通知（给被回复的会员）
     */
    public function notifyCommentReply(int $commentId): void
    {
        $comment = \plugin\wisecms\model\Comment::find($commentId);
        if (!$comment || !$comment->parent_id) return;

        $parent = \plugin\wisecms\model\Comment::find($comment->parent_id);
        if (!$parent || !$parent->member_id) return;

        $msg = "您的评论收到回复: {$comment->username}";
        $this->notifyMember((int) $parent->member_id, $msg, 'comment_reply', [
            'comment_id' => $commentId,
            'parent_id'  => (int) $parent->id,
        ]);
    }

    /**
     * 发送定时发布提醒
     */
    public function notifyScheduledPublish(int $scheduleId): void
    {
        $schedule = \plugin\wisecms\model\Schedule::find($scheduleId);
        if (!$schedule) return;
        $content = \plugin\wisecms\model\Content::find($schedule->content_id);
        if (!$content) return;

        $msg = "定时发布提醒:「{$content->title}」将于 " . date('Y-m-d H:i', cms_timestamp($schedule->scheduled_time)) . " 发布";
        $this->notifyAdmins($msg, 'schedule_publish', ['content_id' => $schedule->content_id]);
    }

    /**
     * 站内信广播给所有启用管理员
     */
    protected function notifyAdmins(string $message, string $type, array $data = []): void
    {
        if (!class_exists(Notification::class) || !class_exists(\app\admin\model\Admin::class)) {
            // wise-notification / admin 模型不可用时降级为日志
            cms_log('info', '[CMS Notification] admin: ' . $message . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            return;
        }

        try {
            $admins = \app\admin\model\Admin::where('status', 1)->select();
            if (count($admins) === 0) {
                return;
            }

            $msg = Notification::createMessage('内容管理提醒', $message, array_merge(['type' => $type], $data));
            /** @var \app\admin\model\Admin $admin */
            foreach ($admins as $admin) {
                Notification::channel('database')->sync()->send($admin, $msg);
            }
        } catch (\Throwable $e) {
            cms_log('warning', '[CMS Notification] 发送失败: ' . $e->getMessage());
        }
    }

    /**
     * 站内信发送给指定会员
     */
    protected function notifyMember(int $memberId, string $message, string $type, array $data = []): void
    {
        if (!class_exists(Notification::class) || !class_exists(\wise\member\model\Member::class)) {
            cms_log('info', "[CMS Notification] member#{$memberId}: " . $message);
            return;
        }

        try {
            /** @var \wise\member\model\Member|null $member */
            $member = \wise\member\model\Member::find($memberId);
            if (!$member) {
                return;
            }

            $msg = Notification::createMessage('评论回复', $message, array_merge(['type' => $type], $data));
            Notification::channel('database')->sync()->send($member, $msg);
        } catch (\Throwable $e) {
            cms_log('warning', '[CMS Notification] 发送失败: ' . $e->getMessage());
        }
    }
}
