<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Category as CategoryModel;
use plugin\wisedocs\model\Document as DocumentModel;
use plugin\wisedocs\model\Project as ProjectModel;
use plugin\wisedocs\model\Revision as RevisionModel;
use plugin\wisedocs\model\Tag as TagModel;
use plugin\wisedocs\service\ComponentPreviewService;
use plugin\wisedocs\service\MarkdownRenderer;
use plugin\wisedocs\service\SanitizerService;
use plugin\wisedocs\validate\Document as DocumentValidate;
use think\facade\Db;

/**
 * 文档管理（P2-3 / D4）
 *
 * 支持文章 / 代码 / 组件 / API 四类内容：
 *  - 正文仅管理员录入，入库前按类型净化（D7）；除代码型外统一走白名单净化；
 *  - 组件型（D4）额外维护结构化示例代码（html/css/js），该部分不净化、
 *    改由 `preview/render` 端点的 CSP sandbox 隔离执行，
 *    且**仅超级管理员**可创建与修改（见 save()）。
 */
class Document extends Base
{
    /** 文档-标签关联表（Db 会自动补表前缀） */
    protected const PIVOT = 'wisedocs_document_tags';

    /** 内容类型文案 */
    protected const TYPE_TEXT = [
        DocumentModel::CONTENT_TYPE_ARTICLE   => '文章',
        DocumentModel::CONTENT_TYPE_CODE      => '代码',
        DocumentModel::CONTENT_TYPE_COMPONENT => '组件',
        DocumentModel::CONTENT_TYPE_API       => 'API',
    ];

    /** 正文格式文案 */
    protected const FORMAT_TEXT = [
        DocumentModel::CONTENT_FORMAT_HTML     => '富文本',
        DocumentModel::CONTENT_FORMAT_MARKDOWN => 'Markdown',
    ];

    /** 发布状态文案 */
    protected const PUBLISH_STATE_TEXT = [
        DocumentModel::PUBLISH_STATE_DRAFT     => '草稿',
        DocumentModel::PUBLISH_STATE_PUBLISHED => '已发布',
        DocumentModel::PUBLISH_STATE_PENDING   => '待发布',
    ];

    /**
     * 列表页（含筛选所需的下拉数据）
     *
     * 新增 / 编辑已拆为独立页面 {@see form()}，由列表页通过 ws-layer（iframe）载入，
     * 因此这里不再渲染正文编辑器容器。
     */
    public function index()
    {
        return $this->assign([
            'spaces'          => ProjectModel::order('sort_order DESC, id ASC')->select(),
            'categoryOptions' => $this->categoryOptions(),
            'tagOptions'      => TagModel::where('status', 1)->order('count DESC, id DESC')->limit(200)->select(),
        ])->fetch();
    }

    /**
     * 新增 / 编辑文档（独立页面）
     *
     * 与列表页分离：内容区更宽、可滚动，正文编辑器（wiseeditor）有充足高度；
     * 页面由列表页 `Wise.ui.dialog.open()` 以 iframe 载入，提交成功后回调父页刷新表格。
     */
    public function form(int $id = 0)
    {
        $editors = $this->buildContentEditors('');

        return $this->assign([
            'id'              => max(0, $id),
            'spaces'          => ProjectModel::order('sort_order DESC, id ASC')->select(),
            'categoryOptions' => $this->categoryOptions(),
            'tagOptions'      => TagModel::where('status', 1)->order('count DESC, id DESC')->limit(200)->select(),
            // 正文编辑器容器（wiseeditor 软集成；插件缺失时自动降级为 textarea）
            'editorMarkdown'  => $editors['markdown'],
            'editorRichtext'  => $editors['richtext'],
            'editorAvailable' => $editors['available'],
            // wiseeditor 自身的 CSS / ESM 入口（缺失时为空串，页面自动降级）
            'editorCss'       => $editors['css'],
            'editorJs'        => $editors['js'],
        ])->fetch();
    }

    /**
     * Bootstrap Table 分页数据（不含正文，减小报文）
     */
    public function getList()
    {
        [$page, $size] = $this->pageParams();

        $keyword      = trim((string) $this->request->param('keyword', ''));
        $categoryId   = (int) $this->request->param('category_id/d', 0);
        $contentType  = $this->request->param('content_type', '');
        $status       = $this->request->param('status', '');
        $publishState = $this->request->param('publish_state', '');
        $tagId        = (int) $this->request->param('tag_id/d', 0);

        $query = DocumentModel::with(['category'])->field(
            'id,project_id,category_id,title,slug,excerpt,content_type,content_format,publish_state,view_count,helpful_count,' .
            'unhelpful_count,sort_order,status,is_top,is_featured,author_id,published_at,create_time,update_time'
        );

        if ($keyword !== '') {
            $query->whereLike('title|excerpt|slug', "%{$keyword}%");
        }
        if ($categoryId > 0) {
            $ids = (new CategoryModel())->getChildrenIds($categoryId, true);
            $query->whereIn('category_id', $ids);
        }
        if ($contentType !== '' && $contentType !== null) {
            $query->where('content_type', (int) $contentType);
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }
        if ($publishState !== '' && $publishState !== null) {
            $query->where('publish_state', (int) $publishState);
        }
        if ($tagId > 0) {
            // 标签下钻：按关联表过滤文档（无关联时用 [0] 兜底，避免 whereIn 空数组报错）
            $docIds = Db::name(self::PIVOT)->where('tag_id', $tagId)->column('document_id');
            $query->whereIn('id', $docIds ?: [0]);
        }

        $list = $query->order('sort_order DESC, id DESC')->paginate([
            'list_rows' => $size,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row                      = $item->toArray();
            $row['category_name']       = $item->category ? $item->category->name : '-';
            $row['content_type_text']   = self::TYPE_TEXT[(int) $item->content_type] ?? '-';
            $row['content_format_text'] = self::FORMAT_TEXT[(string) $item->content_format] ?? '富文本';
            $row['publish_state_text']  = self::PUBLISH_STATE_TEXT[(int) $item->publish_state] ?? '草稿';
            $row['status_text']         = (int) $item->status === DocumentModel::STATUS_PUBLISHED ? '已发布' : '草稿';
            $rows[]                     = $row;
        }

        return $this->listResponse($list->total(), $rows);
    }

    /**
     * 详情（编辑用，含正文与标签）
     */
    public function detail(int $id = 0)
    {
        $document = DocumentModel::find($id);
        if (!$document) {
            return $this->error('文档不存在');
        }

        $row            = $document->toArray();
        $row['tag_ids'] = array_map(
            'intval',
            Db::name(self::PIVOT)->where('document_id', $id)->column('tag_id')
        );

        // 已发布文档若存在「编辑稿」，编辑表单必须回填编辑稿而非线上内容
        $draft            = json_decode((string) $document->draft_data, true);
        $hasDraft         = is_array($draft) && $draft !== [];
        $row['has_draft'] = $hasDraft;

        if ($hasDraft) {
            foreach (DocumentModel::CONTENT_FIELDS as $key) {
                if (array_key_exists($key, $draft)) {
                    $row[$key] = $draft[$key];
                }
            }

            if (array_key_exists('tag_ids', $draft)) {
                $row['tag_ids'] = array_values(array_unique(array_map('intval', (array) $draft['tag_ids'])));
            }
        }

        // 组件型：附带结构化演示代码，供表单回填与本地沙箱预览
        $row['demo'] = (new ComponentPreviewService())->decode(
            $hasDraft && !empty($draft['demo_config']) ? $draft['demo_config'] : $document->demo_config
        );

        return $this->success('', $row);
    }

    /**
     * 新增 / 编辑
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        // slug 为空时自动生成（验证器要求必填）
        if (empty($data['slug']) && !empty($data['title'])) {
            $data['slug'] = $this->makeSlug((string) $data['title'], $id);
        }

        $validate = new DocumentValidate();
        if (!$validate->scene($id > 0 ? 'update' : 'create')->check($data)) {
            return $this->error($validate->getError());
        }

        $sanitizer    = new SanitizerService();
        $componentSvc = new ComponentPreviewService();
        $contentType  = (int) ($data['content_type'] ?? DocumentModel::CONTENT_TYPE_ARTICLE);
        $rawContent   = (string) ($data['content'] ?? '');

        $isComponent = $contentType === DocumentModel::CONTENT_TYPE_COMPONENT;
        $isCode      = $contentType === DocumentModel::CONTENT_TYPE_CODE;
        $isApi       = $contentType === DocumentModel::CONTENT_TYPE_API;

        // 组件型的示例代码会在沙箱中执行，属高风险内容：仅超级管理员可维护（D4）
        if ($isComponent && !$this->isSuperAdmin()) {
            return $this->error('组件型文档包含可执行示例代码，仅超级管理员可维护');
        }

        // 正文格式：编辑已有文档时锁定格式（避免 HTML ↔ Markdown 互转损坏内容）
        $contentFormat = $this->normalizeContentFormat($data['content_format'] ?? null, $contentType, $id);
        $isMarkdown    = $contentFormat === DocumentModel::CONTENT_FORMAT_MARKDOWN;

        // 正文入库策略：
        //   - 代码型：纯文本，前台由 <pre> 输出
        //   - Markdown：纯文本原样入库，前台由 ws-markdown「先转义再解析」渲染，无 XSS 面
        //   - 富文本：白名单净化后入库（模板端 |raw 输出）
        $content      = ($isCode || $isMarkdown) ? $rawContent : $sanitizer->clean($rawContent);
        $codeLanguage = $isCode ? $sanitizer->cleanText($data['code_language'] ?? '', 30) : '';

        // 双保存（批次 7）：正文渲染后的 HTML，前台 / SEO 直出，不再运行时渲染
        $contentHtml = $this->renderContentHtml($content, $contentFormat, $contentType, $codeLanguage);

        // 组件型：结构化示例代码（html/css/js）**不净化**，改由沙箱隔离执行
        $demoConfig = null;
        if ($isComponent) {
            $demo = $componentSvc->decode([
                'html'   => $data['demo_html'] ?? '',
                'css'    => $data['demo_css'] ?? '',
                'js'     => $data['demo_js'] ?? '',
                'height' => $data['demo_height'] ?? ComponentPreviewService::DEFAULT_HEIGHT,
            ]);

            if ($componentSvc->isEmpty($demo)) {
                return $this->error('组件示例的 HTML 结构不能为空');
            }

            $sizeError = $componentSvc->sizeError($demo);
            if ($sizeError !== null) {
                return $this->error($sizeError);
            }

            $demoConfig = $componentSvc->encode($demo);
        }

        $excerpt = $sanitizer->cleanText($data['excerpt'] ?? '', 500);
        if ($excerpt === '') {
            // Markdown 需先剥离语法标记，否则摘要里会残留 # * ` > 等符号
            $plain   = $isMarkdown ? $this->markdownToPlainText($rawContent) : strip_tags($rawContent);
            $excerpt = $sanitizer->cleanText(mb_substr($plain, 0, 200), 500);
        }

        $status     = (int) ($data['status'] ?? DocumentModel::STATUS_DRAFT);
        $publishNow = (int) ($data['publish_now'] ?? 0) === 1;

        // 管理类字段：直接生效（不进入编辑稿）
        $manageFields = [
            'project_id'   => (int) ($data['project_id'] ?? 0),
            'category_id'  => (int) ($data['category_id'] ?? 0),
            'slug'         => (string) ($data['slug'] ?? ''),
            'content_type' => $contentType,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'status'       => $status,
            'is_featured'  => (int) ($data['is_featured'] ?? 0),
            'is_top'       => (int) ($data['is_top'] ?? 0),
        ];

        // 内容类字段：已发布文档写入「编辑稿」，未发布文档直接落库
        $contentFields = [
            'title'          => $sanitizer->cleanText($data['title'] ?? '', 200),
            'content'        => $content,
            'content_format' => $contentFormat,
            'excerpt'        => $excerpt,
            'cover'          => (string) ($data['cover'] ?? ''),
            // 类型专属字段只保留与当前类型相关的值，避免切换类型后残留脏数据
            'code_language'  => $codeLanguage,
            'api_method'     => $isApi ? (string) ($data['api_method'] ?? '') : '',
            'api_endpoint'   => $isApi ? (string) ($data['api_endpoint'] ?? '') : '',
            'demo_config'    => $demoConfig,
        ];

        $tagIds = $data['tag_ids'] ?? [];
        if (is_string($tagIds)) {
            $tagIds = array_filter(array_map('intval', explode(',', $tagIds)));
        }
        $tagIds = array_values(array_unique(array_map('intval', (array) $tagIds)));

        $publishedAt = $this->normalizeDateTime((string) ($data['published_at'] ?? ''));

        try {
            Db::transaction(function () use (&$id, $manageFields, $contentFields, $contentHtml, $status, $tagIds, $publishedAt, $publishNow) {
                if ($id > 0) {
                    $model = DocumentModel::find($id);
                    if (!$model) {
                        throw new \RuntimeException('文档不存在');
                    }

                    // 已发布（前台可见）→ 内容改动进「编辑稿」，不覆盖线上内容
                    $isLive = (int) $model->status === DocumentModel::STATUS_PUBLISHED;
                    $fields = $manageFields;

                    if ($isLive) {
                        $draft = json_decode((string) $model->draft_data, true);
                        $draft = is_array($draft) ? $draft : [];

                        // tag_ids 不是文档表字段：随编辑稿暂存，发布时再同步
                        $fields['draft_data']    = json_encode(
                            array_merge($draft, $contentFields, ['tag_ids' => $tagIds]),
                            JSON_UNESCAPED_UNICODE
                        );
                        $fields['publish_state'] = DocumentModel::PUBLISH_STATE_PENDING;
                    } else {
                        $fields                  = array_merge($fields, $contentFields);
                        $fields['content_html']  = $contentHtml;
                        $fields['draft_data']    = null;
                        $fields['publish_state'] = $status === DocumentModel::STATUS_PUBLISHED
                            ? DocumentModel::PUBLISH_STATE_PUBLISHED
                            : DocumentModel::PUBLISH_STATE_DRAFT;
                    }

                    // 显式定时时间优先；否则首次发布时补当前时间
                    if ($publishedAt !== '') {
                        $fields['published_at'] = $publishedAt;
                    } elseif ($status === DocumentModel::STATUS_PUBLISHED && empty($model->published_at)) {
                        $fields['published_at'] = date('Y-m-d H:i:s');
                    }

                    $model->save($fields);

                    // 编辑稿状态下的标签改动同样要等发布才生效
                    if (!$isLive) {
                        $this->syncTags((int) $id, $tagIds);
                    }
                } else {
                    $fields                 = array_merge($manageFields, $contentFields);
                    $fields['content_html'] = $contentHtml;
                    $fields['author_id']    = (int) auth_id('admin');
                    $fields['draft_data']    = null;
                    $fields['publish_state'] = $status === DocumentModel::STATUS_PUBLISHED
                        ? DocumentModel::PUBLISH_STATE_PUBLISHED
                        : DocumentModel::PUBLISH_STATE_DRAFT;

                    if ($publishedAt !== '') {
                        $fields['published_at'] = $publishedAt;
                    } elseif ($status === DocumentModel::STATUS_PUBLISHED) {
                        $fields['published_at'] = date('Y-m-d H:i:s');
                    }

                    $model = DocumentModel::create($fields);
                    $id    = (int) $model->id;

                    $this->syncTags($id, $tagIds);
                }

                // 「保存并发布」：同一事务内完成发布（合并编辑稿 + 写快照 + 置已发布态）
                if ($publishNow && $id > 0) {
                    $this->publishDocument((int) $id);
                }
            });

            $state = (int) DocumentModel::where('id', $id)->value('publish_state');
            $pending = $state === DocumentModel::PUBLISH_STATE_PENDING;

            return $this->success(
                $pending ? '已保存为编辑稿，发布后生效' : '保存成功',
                ['id' => $id, 'pending' => $pending]
            );
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document save failed: ' . $e->getMessage());

            return $this->error('保存失败：' . ($e instanceof \RuntimeException ? $e->getMessage() : '请稍后重试'));
        }
    }

    /**
     * 发布（单条或多条）
     *
     * 把「编辑稿」合并到正式字段并写入一条发布快照。
     * 无编辑稿时同样可调用（首次发布用于补快照、修正发布态）。
     */
    public function publish()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids = array_map('intval', (array) $this->request->post('ids/a', []));
        if (empty($ids)) {
            $single = (int) $this->request->post('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        if (empty($ids)) {
            return $this->error('请选择要发布的文档');
        }

        try {
            Db::transaction(function () use ($ids) {
                foreach ($ids as $id) {
                    $this->publishDocument((int) $id);
                }
            });

            return $this->success(count($ids) > 1 ? '批量发布成功' : '发布成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document publish failed: ' . $e->getMessage());

            return $this->error('发布失败：' . ($e instanceof \RuntimeException ? $e->getMessage() : '请稍后重试'));
        }
    }

    /**
     * 放弃未发布的改动（回滚到线上版本）
     */
    public function discard()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids = array_map('intval', (array) $this->request->post('ids/a', []));
        if (empty($ids)) {
            $single = (int) $this->request->post('id', 0);
            $ids    = $single > 0 ? [$single] : [];
        }

        if (empty($ids)) {
            return $this->error('请选择要操作的文档');
        }

        try {
            $affected = DocumentModel::whereIn('id', $ids)
                ->where('publish_state', DocumentModel::PUBLISH_STATE_PENDING)
                ->update([
                    'draft_data'    => null,
                    'publish_state' => DocumentModel::PUBLISH_STATE_PUBLISHED,
                ]);

            return $this->success($affected > 0 ? '已放弃未发布的改动' : '没有待放弃的改动');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document discard failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 发布前差异数据
     *
     * 返回「最近一次发布快照」与「当前编辑稿」（无编辑稿则为当前内容）两份原文，
     * 行级差异**由前端计算**：长文本 LCS 在 JS 侧成本可控，且能直接做成可视化对比，
     * 避免 PHP 侧对超大文本做 DP 的内存开销。
     */
    public function diff(int $id = 0)
    {
        $document = DocumentModel::find($id);
        if (!$document) {
            return $this->error('文档不存在');
        }

        $draft = json_decode((string) $document->draft_data, true);
        $draft = is_array($draft) ? $draft : [];

        $last = RevisionModel::where('document_id', $id)->order('id', 'DESC')->find();

        $pick = static function (array $draft, $model, string $key): string {
            if (array_key_exists($key, $draft)) {
                return (string) $draft[$key];
            }

            $value = $model->getAttr($key);

            return $value === null ? '' : (string) $value;
        };

        return $this->success('', [
            'has_revision' => (bool) $last,
            'state'        => (int) $document->publish_state,
            'old'          => [
                'title'   => $last ? (string) $last->title : '',
                'content' => $last ? (string) $last->content : '',
                'format'  => $last ? (string) $last->content_format : '',
            ],
            'new'          => [
                'title'   => $pick($draft, $document, 'title'),
                'content' => $pick($draft, $document, 'content'),
                'format'  => $pick($draft, $document, 'content_format'),
            ],
        ]);
    }

    /**
     * 行内切换对外可见性（status）
     *
     * 供 EnhancedTable 的 `toggle` formatter 调用：只影响前台是否可见，
     * 不改动编辑稿。由「隐藏」切到「可见」且从未发布过时，顺带补齐发布态与快照。
     */
    public function status()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);

        if ($id <= 0 || !in_array($status, [DocumentModel::STATUS_DRAFT, DocumentModel::STATUS_PUBLISHED], true)) {
            return $this->error('参数错误');
        }

        $document = DocumentModel::find($id);
        if (!$document) {
            return $this->error('文档不存在');
        }

        try {
            $document->save(['status' => $status]);

            // 从未发布过 → 上线时补一次发布（写快照、落到已发布态）
            if ($status === DocumentModel::STATUS_PUBLISHED
                && (int) $document->publish_state === DocumentModel::PUBLISH_STATE_DRAFT) {
                $this->publishDocument($id);

                return $this->success('已发布上线');
            }

            return $this->success($status === DocumentModel::STATUS_PUBLISHED ? '已上线' : '已下架');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document status failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 批量操作：转草稿 / 移动分类
     *
     * 批量发布请走 `publish`（ids 数组）—— 它需要写快照，逻辑集中在 publishDocument。
     */
    public function batch()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids    = array_map('intval', (array) $this->request->post('ids/a', []));
        $action = (string) $this->request->post('action', '');

        if (empty($ids)) {
            return $this->error('请选择要操作的文档');
        }

        try {
            switch ($action) {
                case 'draft':
                    $affected = DocumentModel::whereIn('id', $ids)
                        ->update(['status' => DocumentModel::STATUS_DRAFT]);

                    return $this->success($affected > 0 ? '已转为草稿' : '没有需要变更的文档');

                case 'move':
                    $categoryId = (int) $this->request->post('category_id', 0);
                    if ($categoryId <= 0) {
                        return $this->error('请选择目标分类');
                    }

                    $affected = DocumentModel::whereIn('id', $ids)
                        ->update(['category_id' => $categoryId]);

                    return $this->success('已移动 ' . $affected . ' 篇文档');

                default:
                    return $this->error('未知操作');
            }
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document batch failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 派生（克隆）文档
     *
     * 复制正文与全部配置，生成一个与源文档**无关联**的新文档：
     *   - 标题追加「（副本）」
     *   - slug 自动重新生成并保证唯一
     *   - 状态置草稿，浏览量/评价计数归零，推荐与置顶不带过来
     *   - 标签关联一并复制
     *
     * 附带用途：slug 创建后不宜随意变更，需要换标识时可派生一份。
     */
    public function clone()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $id     = (int) $this->request->post('id', 0);
        $source = DocumentModel::find($id);

        if (!$source) {
            return $this->error('文档不存在');
        }

        // 组件型示例代码可执行，仅超级管理员可派生（与 save 同口径）
        if ((int) $source->content_type === DocumentModel::CONTENT_TYPE_COMPONENT && !$this->isSuperAdmin()) {
            return $this->error('组件型文档仅超级管理员可派生');
        }

        try {
            $newId = 0;

            Db::transaction(function () use ($source, &$newId) {
                $title = mb_substr((string) $source->title, 0, 180) . '（副本）';

                $copy = DocumentModel::create([
                    'project_id'      => (int) $source->project_id,
                    'category_id'     => (int) $source->category_id,
                    'title'           => $title,
                    'slug'            => $this->makeSlug($title),
                    'content'         => (string) $source->content,
                    'content_format'  => (string) ($source->content_format ?: DocumentModel::CONTENT_FORMAT_MARKDOWN),
                    'excerpt'         => (string) $source->excerpt,
                    'cover'           => (string) $source->cover,
                    'description'     => (string) $source->description,
                    'content_type'    => (int) $source->content_type,
                    'code_language'   => (string) $source->code_language,
                    'api_method'      => (string) $source->api_method,
                    'api_endpoint'    => (string) $source->api_endpoint,
                    'demo_config'     => $source->demo_config,
                    'extra_fields'    => $source->extra_fields,
                    'view_count'      => 0,
                    'helpful_count'   => 0,
                    'unhelpful_count' => 0,
                    'is_featured'     => 0,
                    'is_top'          => 0,
                    'author_id'       => (int) auth_id('admin'),
                    'published_at'    => null,
                    'sort_order'      => (int) $source->sort_order,
                    'status'          => DocumentModel::STATUS_DRAFT,
                ]);

                $newId = (int) $copy->id;

                $tagIds = array_map(
                    'intval',
                    Db::name(self::PIVOT)->where('document_id', $source->id)->column('tag_id')
                );

                if (!empty($tagIds)) {
                    $this->syncTags($newId, $tagIds);
                }
            });

            return $this->success('派生成功', ['id' => $newId]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document clone failed: ' . $e->getMessage());

            return $this->error('派生失败，请稍后重试');
        }
    }

    /**
     * 删除（含关联表清理）
     */
    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids = array_map('intval', (array) $this->request->post('ids/a', []));
        if (empty($ids)) {
            return $this->error('请选择要删除的数据');
        }

        try {
            Db::transaction(function () use ($ids) {
                $tagIds = array_map('intval', Db::name(self::PIVOT)->whereIn('document_id', $ids)->column('tag_id'));

                Db::name(self::PIVOT)->whereIn('document_id', $ids)->delete();
                DocumentModel::destroy($ids);

                foreach (array_unique($tagIds) as $tagId) {
                    Db::name('wisedocs_tags')->where('id', $tagId)->update([
                        'count' => (int) Db::name(self::PIVOT)->where('tag_id', $tagId)->count(),
                    ]);
                }
            });

            return $this->success('删除成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] document delete failed: ' . $e->getMessage());

            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 同步文档标签并重建标签计数
     */
    protected function syncTags(int $documentId, array $tagIds): void
    {
        $now = date('Y-m-d H:i:s');

        $oldTagIds = array_map(
            'intval',
            Db::name(self::PIVOT)->where('document_id', $documentId)->column('tag_id')
        );

        Db::name(self::PIVOT)->where('document_id', $documentId)->delete();

        if (!empty($tagIds)) {
            $rows = [];
            foreach ($tagIds as $tagId) {
                $rows[] = [
                    'document_id' => $documentId,
                    'tag_id'      => $tagId,
                    'create_time' => $now,
                ];
            }
            Db::name(self::PIVOT)->insertAll($rows);
        }

        foreach (array_unique(array_merge($oldTagIds, $tagIds)) as $tagId) {
            Db::name('wisedocs_tags')->where('id', $tagId)->update([
                'count' => (int) Db::name(self::PIVOT)->where('tag_id', $tagId)->count(),
            ]);
        }
    }

    /**
     * 分类下拉选项（按层级平铺，便于模板渲染缩进）
     *
     * @return array<int, array{id:int,name:string,depth:int}>
     */
    protected function categoryOptions(int $parentId = 0, int $depth = 0): array
    {
        static $items = null;
        if ($items === null) {
            $items = CategoryModel::order('sort_order DESC, id ASC')
                ->field('id,parent_id,name')
                ->select()
                ->toArray();
        }

        $options = [];
        foreach ($items as $item) {
            if ((int) $item['parent_id'] !== $parentId) {
                continue;
            }

            $options[] = [
                'id'     => (int) $item['id'],
                'name'   => (string) $item['name'],
                'depth'  => $depth,
                'prefix' => str_repeat('　', $depth),
            ];

            $options = array_merge($options, $this->categoryOptions((int) $item['id'], $depth + 1));
        }

        return $options;
    }

    /**
     * 归一化日期时间入参（兼容 HTML datetime-local 的 T 分隔）
     */
    protected function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $timestamp = strtotime(str_replace('T', ' ', $value));

        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    /**
     * 当前登录管理员是否为超级管理员
     *
     * 判据与 `middleware\DocsAuth` 保持一致：`config('app.super_admin_ids')`。
     */
    protected function isSuperAdmin(): bool
    {
        $adminId = (int) auth_id('admin');
        if ($adminId <= 0) {
            return false;
        }

        $superIds = array_map('intval', (array) config('app.super_admin_ids', [1]));

        return in_array($adminId, $superIds, true);
    }

    /**
     * 执行发布（内部方法，供 publish / status 复用）
     *
     * 1) 把「编辑稿」中的内容类字段合并到正式字段（无编辑稿时仅修正发布态）
     * 2) 清空编辑稿、置为已发布态、确保 status=1 且 published_at 有值
     * 3) 同步标签（待发布的 tag_ids 只存在于编辑稿中）
     * 4) 写入一条发布快照 —— 它是「发布前差异对比」的基准
     *
     * @throws \RuntimeException 文档不存在
     */
    protected function publishDocument(int $id): void
    {
        $model = DocumentModel::find($id);
        if (!$model) {
            throw new \RuntimeException('文档不存在');
        }

        $draft = json_decode((string) $model->draft_data, true);
        $draft = is_array($draft) ? $draft : [];

        $fields = [];

        foreach (DocumentModel::CONTENT_FIELDS as $key) {
            if (array_key_exists($key, $draft)) {
                $fields[$key] = $draft[$key];
            }
        }

        $fields['draft_data']    = null;
        $fields['publish_state'] = DocumentModel::PUBLISH_STATE_PUBLISHED;
        $fields['status']        = DocumentModel::STATUS_PUBLISHED;

        if (empty($model->published_at)) {
            $fields['published_at'] = date('Y-m-d H:i:s');
        }

        $model->save($fields);

        // 双保存：发布后按最终正文重算渲染 HTML（正文可能来自编辑稿）
        $model->save([
            'content_html' => $this->renderContentHtml(
                (string) $model->content,
                (string) ($model->content_format ?: DocumentModel::CONTENT_FORMAT_HTML),
                (int) $model->content_type,
                (string) $model->code_language
            ),
        ]);

        // 标签：待发布的改动只存在于编辑稿中
        if (array_key_exists('tag_ids', $draft)) {
            $tagIds = array_values(array_unique(array_map('intval', (array) $draft['tag_ids'])));
            $this->syncTags($id, $tagIds);
        }

        // 发布快照（取发布后的正式内容）
        $fresh = DocumentModel::find($id);
        if (!$fresh) {
            return;
        }

        RevisionModel::create([
            'document_id'    => $id,
            'title'          => (string) $fresh->title,
            'content'        => (string) $fresh->content,
            'content_format' => (string) ($fresh->content_format ?: DocumentModel::CONTENT_FORMAT_HTML),
            'content_html'   => (string) ($fresh->content_html ?? ''),
            'excerpt'        => (string) $fresh->excerpt,
            'cover'          => (string) $fresh->cover,
            'admin_id'       => (int) auth_id('admin'),
            'create_time'    => date('Y-m-d H:i:s'),
        ]);

        // 每个文档只保留最近 20 条快照，避免无限增长
        $revisionIds = RevisionModel::where('document_id', $id)->order('id', 'DESC')->column('id');

        if (count($revisionIds) > 20) {
            RevisionModel::destroy(array_slice($revisionIds, 20));
        }
    }

    /**
     * 归一化正文格式
     *
     * 规则：
     *   - 代码型固定 html（正文是纯文本，前台由 <pre> 输出，与 Markdown 渲染无关）
     *   - 编辑已有文档：以库中已有值为准（**锁定格式**，避免 HTML ↔ Markdown 互转损坏内容）
     *   - 新建文档：取提交值，缺省 Markdown
     */
    protected function normalizeContentFormat($submitted, int $contentType, int $id): string
    {
        if ($contentType === DocumentModel::CONTENT_TYPE_CODE) {
            return DocumentModel::CONTENT_FORMAT_HTML;
        }

        $valid = [DocumentModel::CONTENT_FORMAT_HTML, DocumentModel::CONTENT_FORMAT_MARKDOWN];

        if ($id > 0) {
            $current = (string) DocumentModel::where('id', $id)->value('content_format');
            if (in_array($current, $valid, true)) {
                return $current;
            }
        }

        $format = (string) $submitted;

        return in_array($format, $valid, true) ? $format : DocumentModel::CONTENT_FORMAT_MARKDOWN;
    }

    /**
     * Markdown → 纯文本（仅用于自动生成摘要，不做完整解析）
     */
    protected function markdownToPlainText(string $markdown): string
    {
        $text = $markdown;

        $text = (string) preg_replace('/```[\s\S]*?```/', ' ', $text);                       // 围栏代码块
        $text = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1 ', $text);              // 图片
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1 ', $text);               // 链接
        $text = (string) preg_replace('/^\s{0,3}(#{1,6}|>|[-+*]|\d+\.)\s+/m', '', $text);     // 行首标记
        $text = (string) preg_replace('/^\s*\|?[\s:|-]+\|?\s*$/m', ' ', $text);               // 表格分隔行
        $text = (string) preg_replace('/[*_~`]+/', '', $text);                                // 强调符号

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * 渲染正文为 HTML（双保存）
     *
     * - 代码型：转义后包成 <pre><code class="language-x">（与 getFormattedContent 一致）
     * - Markdown：走 MarkdownRenderer（先转义再解析，无 XSS 面）
     * - 富文本：content 已在入库前净化，原样返回
     */
    protected function renderContentHtml(string $content, string $format, int $contentType, string $codeLanguage = ''): string
    {
        if ($contentType === DocumentModel::CONTENT_TYPE_CODE) {
            return '<pre><code class="language-' . htmlspecialchars($codeLanguage ?: 'text', ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }

        if ($format === DocumentModel::CONTENT_FORMAT_MARKDOWN) {
            return (new MarkdownRenderer())->render($content);
        }

        return $content;
    }

    /**
     * 构建正文编辑器容器（wiseeditor 软集成）
     *
     * 同时产出 Markdown 与富文本两个容器：表单里按 content_format 显示其一，
     * 另一个保持 disabled（disabled 字段不参与提交）。
     * wiseeditor 未安装 / 未启用 / 渲染异常时，对应容器降级为 textarea。
     *
     * `css` / `js` 是编辑器自身的静态资源标签：EditorManager::render() 只产出容器 DOM，
     * 不注入资源，必须由 collectAssets() 收集后随页面一起下发，否则容器永远不会初始化。
     *
     * @return array{markdown:string, richtext:string, available:array<string,bool>, css:string, js:string}
     */
    protected function buildContentEditors(string $value): array
    {
        return [
            'markdown'  => $this->renderContentEditor('content', $value, 'markdown', 'editor_content_md', '开始用 Markdown 写作…'),
            'richtext'  => $this->renderContentEditor('content', $value, 'richtext', 'editor_content_rt', '请输入正文…'),
            'available' => [
                'markdown' => $this->hasEditorDriver('markdown'),
                'richtext' => $this->hasEditorDriver('richtext'),
            ],
            'css'       => $this->editorAssetTags('css'),
            'js'        => $this->editorAssetTags('js'),
        ];
    }

    /**
     * 编辑器静态资源标签（路径相对 /statics/）
     *
     * 与 wisecms `editorAssetTags()` 同一套契约：CSS 走 `<link>`，JS 走同源外部 ESM
     * （后台 CSP 禁内联脚本，因此只能由 PHP 拼进页面，不能写死在内联 <script> 里）。
     */
    protected function editorAssetTags(string $kind): string
    {
        try {
            $manager = app('editor.manager');
            if (!$manager instanceof \app\common\library\EditorManager) {
                return '';
            }

            $assets = $manager->collectAssets();
            $tags   = '';

            foreach ((array) ($assets[$kind] ?? []) as $item) {
                $url = '/statics/' . ltrim((string) $item, '/');
                $tags .= $kind === 'css'
                    ? '<link rel="stylesheet" href="' . $url . '">' . "\n"
                    : '<script type="module" src="' . $url . '"></script>' . "\n";
            }

            return $tags;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * wiseeditor 是否提供指定类型的驱动（未安装 / 异常一律视为不可用）
     */
    protected function hasEditorDriver(string $type): bool
    {
        try {
            $manager = app('editor.manager');

            return $manager instanceof \app\common\library\EditorManager && $manager->has($type);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 渲染单个正文编辑器
     *
     * 字段名固定为 content；容器 id 与「同步目标」后缀遵循 WiseEditor 的约定
     * （富文本 `_input`、Markdown `_textarea`），降级 textarea 时同样沿用，
     * 使前端取值逻辑在两种模式下保持一致。
     */
    protected function renderContentEditor(string $name, string $value, string $type, string $id, string $placeholder): string
    {
        if ($this->hasEditorDriver($type)) {
            try {
                $manager = app('editor.manager');

                return $manager->render($name, $value, [
                    'type'        => $type,
                    'id'          => $id,
                    'height'      => $type === 'markdown' ? 480 : 460,
                    'placeholder' => $placeholder,
                ]);
            } catch (\Throwable $e) {
                // 渲染异常同样降级，不阻断页面
            }
        }

        $suffix = $type === 'markdown' ? '_textarea' : '_input';

        return '<textarea name="' . $name . '" id="' . $id . $suffix . '" class="form-control font-monospace" '
            . 'style="height:480px" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>';
    }

    /**
     * 生成唯一 slug
     */
    protected function makeSlug(string $title, int $excludeId = 0): string
    {
        // 仅保留 ASCII 字母/数字，其余（中文、空格、符号）折叠为 '-'
        // 不能用 /[^\w]+/u：UTF-8 下 \w 会匹配中文，生成含中文的标识会被 alphaDash 拒绝。
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'doc-' . substr(md5($title . microtime(true)), 0, 8);
        }

        $base = $slug;
        $i    = 1;
        while (DocumentModel::where('slug', $slug)->where('id', '<>', $excludeId)->count() > 0) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
