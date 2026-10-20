<?php

declare(strict_types=1);

namespace plugin\wisedocs\model;

use plugin\BaseModel;

/**
 * 文档模型（P1 修复版）
 *
 * 相比原实现修复：
 *   - 显式声明表名 `wisedocs_documents`（原实现未声明 $name，ThinkPHP 会推导为
 *     `ws_document`，所有查询都打到不存在的表）；
 *   - 补齐 `category()` / `project()` / `tags()` 关联（原实现缺失，控制器
 *     `with(['category'])` 直接抛「关联不存在」）；
 *   - 去掉引用不存在方法/视图的 `formatCodeContent()`，`getFormattedContent()`
 *     改为自包含实现。
 */
class Document extends BaseModel
{
    /** 表名（必须显式指定） */
    protected $name = 'wisedocs_documents';

    // 内容类型
    const CONTENT_TYPE_ARTICLE   = 1; // 普通文章
    const CONTENT_TYPE_CODE      = 2; // 代码示例
    const CONTENT_TYPE_COMPONENT = 3; // 组件展示（D4：示例代码在沙箱 iframe 中运行）
    const CONTENT_TYPE_API       = 4; // API 文档

    // 状态
    const STATUS_DRAFT     = 0;
    const STATUS_PUBLISHED = 1;

    // 正文格式（决定前台如何渲染，也决定用哪个编辑器编辑）
    const CONTENT_FORMAT_HTML     = 'html';     // 富文本（历史数据）
    const CONTENT_FORMAT_MARKDOWN = 'markdown'; // Markdown（新文档默认）

    // 发布状态（内容生命周期）
    //   status        : 对外可见性（0 隐藏 / 1 前台可见）
    //   publish_state : 生命周期阶段（下）
    const PUBLISH_STATE_DRAFT     = 0; // 从未发布
    const PUBLISH_STATE_PUBLISHED = 1; // 已发布，且没有未发布的改动
    const PUBLISH_STATE_PENDING   = 2; // 已发布，但存在未发布的改动（draft_data 非空）

    /**
     * 内容类字段
     *
     * 已发布文档再次保存时，这些字段的改动写入 `draft_data`（编辑稿），
     * **不覆盖线上内容**；只有执行「发布」才会合并到正式字段。
     * 其余字段（分类 / 排序 / 状态 / 推荐等）属管理类，直接生效。
     */
    const CONTENT_FIELDS = [
        'title',
        'content',
        'content_format',
        'excerpt',
        'cover',
        'code_language',
        'api_method',
        'api_endpoint',
        'demo_config',
    ];

    protected $type = [
        'id'              => 'integer',
        'project_id'      => 'integer',
        'category_id'     => 'integer',
        'content_type'    => 'integer',
        'view_count'      => 'integer',
        'helpful_count'   => 'integer',
        'unhelpful_count' => 'integer',
        'sort_order'      => 'integer',
        'status'          => 'integer',
        'is_featured'     => 'integer',
        'is_top'          => 'integer',
        'author_id'       => 'integer',
    ];

    /**
     * 所属分类
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * 所属空间
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * 标签关联（多对多，关联表 wisedocs_document_tags）
     *
     * P3 起 `wisedocs_documents.tags` 遗留列已删除，属性访问 `$doc->tags`
     * 即返回本关联（配合 `with(['tags'])` 使用）。
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'wisedocs_document_tags', 'tag_id', 'document_id');
    }

    /**
     * 只取已发布
     */
    public function scopePublished($query)
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * 限定空间
     */
    public function scopeOfProject($query, $projectId)
    {
        $query->where('project_id', (int) $projectId);
    }

    /**
     * 是否组件型（D4：演示代码由沙箱隔离运行，仅超级管理员可维护）
     */
    public function isComponent(): bool
    {
        return (int) $this->content_type === self::CONTENT_TYPE_COMPONENT;
    }

    /**
     * 按内容类型格式化正文（自包含，无外部视图依赖）
     *
     * 注意：文章型正文由写入方在入库时经 SanitizerService 净化；
     * 此处不对 content 二次净化，避免破坏已净化的富文本结构。
     */
    public function getFormattedContent(): string
    {
        if ((int) $this->content_type === self::CONTENT_TYPE_CODE) {
            $language = htmlspecialchars((string) $this->code_language, ENT_QUOTES, 'UTF-8');
            $code     = htmlspecialchars((string) $this->content, ENT_QUOTES, 'UTF-8');

            return '<pre><code class="language-' . $language . '">' . $code . '</code></pre>';
        }

        return (string) $this->content;
    }
}
