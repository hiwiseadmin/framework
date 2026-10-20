<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

class Comment extends BaseModel
{
    protected $name = 'wisecms_comment';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    // 表已补 update_time 列：恢复自动维护（原为关闭）
    protected $updateTime = 'update_time';

    /**
     * 关联内容（文章）
     *
     * 注意：不可命名为 content()。评论表本身有 `content` 列（评论正文），
     * 同名关联在 toArray() 时会覆盖该列，导致后台列表取不到评论正文。
     */
    public function article()
    {
        return $this->belongsTo(Content::class, 'content_id', 'id');
    }

    /**
     * 关联父评论
     */
    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id', 'id');
    }

    /**
     * 关联子评论
     */
    public function children()
    {
        return $this->hasMany(Comment::class, 'parent_id', 'id')->where('status', 1);
    }
}
