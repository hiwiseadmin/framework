<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\content\CategoryService;
use plugin\wisecms\service\content\PreviewService;
use plugin\wisecms\service\model\FormService;
use plugin\wisecms\service\model\InputService;
use plugin\wisecms\service\model\UpdateService;
use plugin\wisecms\service\model\FieldRegistry;
use plugin\wisecms\service\engine\QualityScorer;
use plugin\wisecms\model\Model as ModelModel;
use plugin\wisecms\model\Content as ContentModel;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\service\url\UrlService;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\exception\ErrorCode;
class Content extends BaseController
{
    use PageFallbackTrait;

    protected ContentService $contentService;
    protected CategoryService $categoryService;

    protected function initPlugin(): void
    {
        $this->contentService = new ContentService();
        $this->categoryService = new CategoryService();

        // UIUX-P1-06：正文是富文本 → 必须登记为「允许 HTML 的字段」。
        // 框架 `app\Request::applySecurityFilter()` 对**未登记**字段一律 `strip_tags()`
        // （`app/Request.php:182`），编辑器产出的 HTML 会在**入库前**被剥成纯文本。
        $this->request->registerHtmlField(['content']);
    }

    /**
     * 推断本次请求的 `model_id`（UIUX-P1-06）
     *
     * ⚠️ 编辑页表单**不提交 `model_id`**（它是内容的固有属性，不由表单决定），
     *    故缺省时按内容 ID 回查一次（走主键，成本可忽略）。
     */
    protected function resolveRequestModelId(int $contentId = 0): int
    {
        $modelId = (int) $this->request->post('model_id', 0);
        if ($modelId > 0) {
            return $modelId;
        }

        return $contentId > 0 ? (int) ContentModel::where('id', $contentId)->value('model_id') : 0;
    }

    /**
     * 登记「该模型的自定义富文本字段」（UIUX-P1-06）
     *
     * 自定义字段名**由模型动态决定**，无法在 `initPlugin()` 里静态登记，
     * 故在读取请求体之前按 `model_id` 现查现登记：
     *   · `editor`   → 允许 HTML；
     *   · `markdown` → 允许 Markdown（框架不做 HTML 实体转义，避免破坏语法）。
     *
     * 不登记的话，这两类字段的富文本内容同样会在入库前被 `strip_tags` 剥掉。
     *
     * @param int $modelId 内容模型 ID（0 表示无模型 → 只登记主表正文）
     */
    protected function registerModelRichFields(int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }

        $htmlFields     = [];
        $markdownFields = [];

        foreach ((new FieldRegistry())->getCustomFields($modelId) as $field) {
            $name = (string) ($field['field'] ?? '');
            if ($name === '') {
                continue;
            }

            $formType = (string) ($field['form_type'] ?? '');
            if ($formType === 'editor') {
                $htmlFields[] = $name;
            } elseif ($formType === 'markdown') {
                $markdownFields[] = $name;
            }
        }

        if ($htmlFields !== []) {
            $this->request->registerHtmlField($htmlFields);
        }
        if ($markdownFields !== []) {
            $this->request->registerMarkdownField($markdownFields);
        }
    }

    /**
     * 后台当前站点（P2 站点选择器：会话上下文，见 SiteService::resolveAdminSiteId）
     */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /**
     * 内容是否属于「后台当前站点」（P2 多站点隔离）
     *
     * 越界一律按「内容不存在」处理，不暴露「该 id 存在但属于别的站点」这一信息。
     */
    protected function ownsContent($content): bool
    {
        return $content && (int) $content->site_id === $this->siteId();
    }

    /**
     * 取「后台当前站点」名下的内容 ID
     *
     * 批量操作（批量删除 / 批量移动）统一先过一遍本站点过滤，
     * 否则拥有内容权限的站点 A 编辑者可对站点 B 的 id 批量改数据。
     *
     * @param array<int, mixed> $ids
     * @return array<int, mixed>
     */
    protected function ownedIds(array $ids): array
    {
        return ContentModel::whereIn('id', array_map('intval', $ids))
            ->where('site_id', $this->siteId())
            ->column('id');
    }

    /**
     * 内容列表
     */
    public function index()
    {
        $params = [
            // P2：内容列表按「后台当前站点」过滤（ContentService 早已支持 site_id，
            // 原控制器未传 → 多站点下列表跨站混合）
            'site_id'   => $this->siteId(),
            'catid'     => (int)$this->request->param('catid', 0),
            'status'    => $this->request->param('status', ''),
            'keyword'   => $this->request->param('keyword', ''),
            // P2-01：转发排序参数，让「工作台」卡片能下钻到「按浏览量排序」的列表。
            // 白名单校验在 `ContentService::getList()` 内（非法列名静默回落 `id desc`）。
            'order'     => (string) $this->request->param('order', ''),
            'page'      => (int)$this->request->param('page', 1),
            'limit'     => (int)$this->request->param('limit', 15),
        ];
        if ($params['status'] === '') unset($params['status']);
        else $params['status'] = (int)$params['status'];

        $list = $this->contentService->getList($params);

        if ($this->request->isAjax()) {
            return $this->success('', $list);
        }

        $categoryOptions = $this->categoryService->getOptions();
        $this->assign('list', $list);
        $this->assign('categoryOptions', $categoryOptions);
        $this->assign('params', $params);
        return $this->fetch('admin/content/index');
    }

    /**
     * 创建内容
     */
    public function create()
    {
        if ($this->request->isPost()) {
            // UIUX-P1-06：先把该模型的富文本字段登记为「允许 HTML」，再读请求体
            $this->registerModelRichFields($this->resolveRequestModelId());

            // 仅接收白名单字段，避免批量赋值写入 view_count/seo_score/id 等非表单字段
            $data = $this->request->only([
                'title', 'catid', 'model_id', 'content', 'keywords', 'description',
                'slug', 'alias', 'thumb', 'og_image', 'canonical_url', 'username',
                'source_type', 'source_url', 'is_top', 'is_recommend',
                'listorder', 'publish_time',
            ], 'post');
            $tags = (string) $this->request->post('tags', '');

            // P2：站点归属由服务端注入（白名单已排除 site_id，此处显式落当前站点）
            $data['site_id'] = $this->siteId();

            // 状态不再由客户端指定：统一按 content_check 推导（P0-5）
            // （0 无需审核→已发布；1 先审后发 / 2 先发后审→待审核），
            // 否则 manifest 中声明的审核模式形同虚设
            $data['status'] = ContentService::derivePublishStatus(true);

            if (empty($data['title'])) {
                return $this->error('标题不能为空');
            }
            $maxTitleLen = (int)config('wisecms.max_title_length', 200);
            if (mb_strlen($data['title']) > $maxTitleLen) {
                return $this->error("标题不能超过{$maxTitleLen}个字符");
            }
            try {
                // 处理自定义字段（校验 + 输入过滤）
                $modelId = (int) ($data['model_id'] ?? 0);
                $customData = $modelId > 0
                    ? (new InputService())->processData($modelId, $this->request->post())
                    : [];

                // 事务：主表 + 自定义字段 + 标签
                $content = $this->contentService->createWithFields($data, $customData, $tags ? explode(',', $tags) : []);

                return $this->success('内容创建成功', $content);
            } catch (WisecmsException $e) {
                cms_log('error', '内容操作失败: ' . $e->getMessage(), ['code' => $e->getErrorCode(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error($e->getMessage());
            } catch (\Exception $e) {
                cms_log('error', '内容操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error('操作失败，请稍后重试');
            }
        }

        $catid = $this->request->param('catid', 0);
        $modelId = (int) $this->request->param('model_id', 0);
        $categoryOptions = $this->categoryService->getOptions();
        $models = ModelModel::where('status', 1)->select();
        $customForm = ['base' => [], 'senior' => []];
        if ($modelId > 0) {
            $customForm = (new FormService())->generateForm($modelId, [], 'add');
        }
        $this->assign('categoryOptions', $categoryOptions);
        $this->assign('models', $models);
        $this->assign('modelId', $modelId);
        $this->assign('customForm', $customForm);
        $this->assign('catid', $catid);

        // UIUX-P1-06：正文接 wiseeditor（未启用则自动回退 textarea）
        $this->assignBodyEditor('');

        return $this->fetch('admin/content/create');
    }

    /**
     * 按模型返回自定义字段表单（UIUX-P1-02：切换模型时 AJAX 局部重载字段区，不整页刷新）
     *
     * 返回 `{base: {...}, senior: {...}}`，与 create 页 `customForm` 结构一致；
     * 前端据此重渲染 `#tab-fields`，系统字段（标题/栏目/正文等）保留在表单里不丢。
     */
    public function customFields()
    {
        $modelId = (int) $this->request->param('model_id', 0);
        $customForm = ['base' => [], 'senior' => []];
        if ($modelId > 0) {
            $customForm = (new FormService())->generateForm($modelId, [], 'add');
        }
        return $this->success('', $customForm);
    }

    /**
     * 质量评分（UIUX-P2-02）
     *
     * 补齐 `QualityScorer` 的入口：该服务早已实现（4 个维度 + `scoreAndSave`），
     * 但**全插件无任何后台调用点** —— 交互方案 §1.10 点名的「死能力」。
     * 唯一入口此前只有 CLI `wisecms:quality:rescore`（全站批量重评）。
     *
     * 行为与 SEO 检测面板（`seo/audit`）**刻意同款**，便于两处 UI 共用一套心智：
     *   · GET，返回 `QualityScorer::score()` 的完整结果；
     *   · **有副作用**：`scoreAndSave()` 会写 `wisecms_content_quality` 并同步
     *     `content.quality_score` / `quality_level`（即列表页「质量」列的数据来源）；
     *   · 越界防护：内容必须属于**后台当前站点**（沿用 `ownsContent`，与其他写操作同口径）。
     */
    public function quality()
    {
        $id = (int) $this->request->param('id', 0);

        $content = $this->contentService->getDetail($id);
        if (!$this->ownsContent($content)) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        try {
            $result = (new QualityScorer())->scoreAndSave($id);

            return $this->success('', $result);
        } catch (WisecmsException $e) {
            return $this->error(WisecmsException::userMessage($e));
        } catch (\Throwable $e) {
            cms_log('error', '内容质量评分失败: ' . $e->getMessage(), ['id' => $id, 'file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('质量评分失败，请稍后重试');
        }
    }

    /**
     * 自动草稿（UIUX-P1-03 的轻量保存接口）
     *
     * 与 `create`/`edit` 的差异（「轻量」的边界）：
     *   1. **不强制标题必填**：草稿允许空标题，静默保存不打断用户；
     *   2. **不触发审核状态推导**：新建恒为 `STATUS_DRAFT`，编辑不传 `status`（走
     *      `ContentService::update` 时不触发 `assertStatusTransition`）→ 不会把草稿
     *      意外置为待审核/已发布；
     *   3. **不产生版本快照**：直接调 `create`/`update`（非 `*WithFields`，后者事务末尾
     *      会 `capture` 版本）→ 高频自动保存不刷版本表。
     *
     * 仍保留的必要处理：正文净化 / slug 唯一性 / 字数统计 / 别名同步（走 Service 的
     * `create`/`update` 内部逻辑）。返回 `{id, saved_at}` 供前端显示「最后保存时间」。
     */
    public function autosave()
    {
        $id = (int) $this->request->post('id', 0);

        // UIUX-P1-06：先登记富文本字段再读请求体（自动草稿同样走这里，否则富文本会被剥标签）
        $this->registerModelRichFields($this->resolveRequestModelId($id));

        $data = $this->request->only([
            'title', 'catid', 'model_id', 'content', 'keywords', 'description',
            'slug', 'alias', 'thumb', 'og_image', 'canonical_url', 'username',
            'source_type', 'source_url', 'is_top', 'is_recommend',
            'listorder', 'publish_time',
        ], 'post');
        $tags = (string) $this->request->post('tags', '');

        try {
            $post = $this->request->post();

            if ($id > 0) {
                // 编辑：只更新主表，不触发状态机（不传 status → 保持原状态）
                $content = ContentModel::find($id);
                if (!$this->ownsContent($content)) {
                    return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
                }
                unset($data['status']);

                $modelId = (int) $content->model_id;
                $post['id'] = $id;

                $customData = [];
                if ($modelId > 0) {
                    $customData = (new InputService())->processData($modelId, $post);
                    $customData = array_merge($customData, (new UpdateService())->processData($modelId, $post));
                }

                $content = $this->contentService->update($id, $data);
                if ($modelId > 0 && !empty($customData)) {
                    $this->contentService->saveCustomData($id, $modelId, $customData);
                }
                if ($tags !== '') {
                    $this->contentService->saveTags($id, explode(',', $tags), $modelId, (int) $content->site_id);
                }

                return $this->success('', ['id' => $id, 'saved_at' => cms_now()]);
            }

            // 新建草稿：恒为草稿，站点由服务端注入
            $data['site_id'] = $this->siteId();
            $data['status']  = ContentService::STATUS_DRAFT;

            $modelId = (int) ($data['model_id'] ?? 0);
            $customData = [];
            if ($modelId > 0) {
                $customData = (new InputService())->processData($modelId, $post);
            }

            $content = $this->contentService->create($data);
            if ($modelId > 0 && !empty($customData)) {
                $this->contentService->saveCustomData($content->id, $modelId, $customData);
            }
            if ($tags !== '') {
                $this->contentService->saveTags($content->id, explode(',', $tags), $modelId, (int) $content->site_id);
            }

            return $this->success('', ['id' => $content->id, 'saved_at' => cms_now()]);
        } catch (WisecmsException $e) {
            cms_log('error', '自动保存失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', '自动保存失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('自动保存失败，请稍后重试');
        }
    }

    /**
     * 导出内容为 CSV（UIUX-P1-22）
     *
     * 两种口径：
     *   - `ids` 非空 → 导出选中的内容（逗号分隔，先按站点过滤，越界静默剔除）；
     *   - `ids` 为空 → 按当前筛选（catid/status/keyword）+ 站点导出。
     *
     * ⚠️ 同步导出（流式返回 CSV）；大数据量建议改异步任务（属 P1-13 后端任务化前置），
     * 当前不引入队列依赖。
     */
    public function export()
    {
        $siteId  = $this->siteId();
        $catid   = (int) $this->request->param('catid', 0);
        $status  = $this->request->param('status', '');
        $keyword = trim((string) $this->request->param('keyword', ''));
        $idsRaw  = trim((string) $this->request->param('ids', ''));

        $query = ContentModel::where('site_id', $siteId)->where('delete_time', null);

        if ($idsRaw !== '') {
            $idArr = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
            if (empty($idArr)) {
                return $this->error('未选择要导出的内容');
            }
            // 站点过滤：越界 id 静默剔除（不暴露"该 id 属于别的站点"）
            $idArr = ContentModel::whereIn('id', $idArr)->where('site_id', $siteId)->column('id');
            if (empty($idArr)) {
                return $this->error('所选内容均不属于当前站点');
            }
            $query->whereIn('id', $idArr);
        } else {
            if ($catid > 0) {
                $query->where('catid', $catid);
            }
            if ($status !== '') {
                $query->where('status', (int) $status);
            }
            if ($keyword !== '') {
                $escaped = addcslashes($keyword, '%_\\');
                $query->where('title|keywords', 'like', "%{$escaped}%");
            }
        }

        $list = $query->with(['category'])->order('id', 'desc')->select();
        $csv  = $this->buildContentCsv($list);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="content_export_' . date('YmdHis') . '.csv"',
        ]);
    }

    /** 生成内容导出 CSV（UTF-8 BOM + 表头 + 数据行） */
    protected function buildContentCsv($list): string
    {
        $statusMap = [0 => '草稿', 1 => '待审核', 2 => '已发布', 3 => '已归档'];
        $header    = ['ID', '标题', '栏目', '状态', '发布时间', '浏览量', '置顶', '推荐', '关键词', '描述', '别名'];

        $rows = [$header];
        foreach ($list as $c) {
            $rows[] = [
                (string) $c->id,
                (string) $c->title,
                $c->category ? (string) $c->category->name : '',
                $statusMap[(int) $c->status] ?? (string) $c->status,
                (string) $c->publish_time,
                (string) $c->view_count,
                $c->is_top ? '是' : '否',
                $c->is_recommend ? '是' : '否',
                (string) $c->keywords,
                (string) $c->description,
                (string) $c->slug,
            ];
        }

        $out = "\xEF\xBB\xBF"; // UTF-8 BOM（Excel 打开中文不乱码）
        foreach ($rows as $row) {
            $out .= implode(',', array_map([$this, 'csvCell'], $row)) . "\n";
        }
        return $out;
    }

    /** CSV 单元格转义（含逗号/引号/换行时加引号，内部引号翻倍） */
    protected function csvCell($value): string
    {
        $v = (string) $value;
        if (strpos($v, ',') !== false || strpos($v, '"') !== false
            || strpos($v, "\n") !== false || strpos($v, "\r") !== false) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    /**
     * 编辑内容
     */
    public function edit()
    {
        $id = $this->request->param('id');
        $content = $this->contentService->getDetail((int)$id);
        // P2：只允许编辑「后台当前站点」的内容（越界按不存在处理，不暴露存在性）
        if (!$this->ownsContent($content)) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        if ($this->request->isPost()) {
            // UIUX-P1-06：先把该模型的富文本字段登记为「允许 HTML」，再读请求体
            $this->registerModelRichFields((int) $content->model_id);

            // 仅接收白名单字段，避免批量赋值写入 view_count/seo_score/id 等非表单字段
            $data = $this->request->only([
                'title', 'catid', 'model_id', 'content', 'keywords', 'description',
                'slug', 'alias', 'thumb', 'og_image', 'canonical_url', 'username',
                'source_type', 'source_url', 'is_top', 'is_recommend',
                'listorder', 'publish_time',
            ], 'post');
            $tags = (string) $this->request->post('tags', '');

            // 先审后发（content_check=1）时，非「已发布」内容不允许经表单直接置为已发布，
            // 必须走工作流审核；对「已发布」内容的再次编辑不受影响
            $check = (int) config('wisecms.content_check', 1);
            $newStatus = (int) $this->request->post('status', (int) $content->status);
            if ($check === 1
                && $newStatus === ContentService::STATUS_PUBLISHED
                && (int) $content->status !== ContentService::STATUS_PUBLISHED) {
                $newStatus = ContentService::STATUS_PENDING;
            }
            $data['status'] = $newStatus;

            if (!empty($data['title'])) {
                $maxTitleLen = (int)config('wisecms.max_title_length', 200);
                if (mb_strlen($data['title']) > $maxTitleLen) {
                    return $this->error("标题不能超过{$maxTitleLen}个字符");
                }
            }
            
            try {
                // 处理自定义字段（校验 + 输入/更新过滤）
                $modelId = (int) $content->model_id;
                $post = $this->request->post();
                $post['id'] = (int) $id;
                $customData = [];
                if ($modelId > 0) {
                    $customData = (new InputService())->processData($modelId, $post);
                    $customData = array_merge($customData, (new UpdateService())->processData($modelId, $post));
                }

                // 事务：主表 + 自定义字段 + 标签
                $content = $this->contentService->updateWithFields((int) $id, $data, $customData, $tags ? explode(',', $tags) : []);

                return $this->success('内容更新成功', $content);
            } catch (WisecmsException $e) {
                cms_log('error', '内容操作失败: ' . $e->getMessage(), ['code' => $e->getErrorCode(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error($e->getMessage());
            } catch (\Exception $e) {
                cms_log('error', '内容操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error('操作失败，请稍后重试');
            }
        }

        $categoryOptions = $this->categoryService->getOptions();
        $tagsText = $content->tags ? implode(',', array_column($content->tags->toArray(), 'name')) : '';
        $customForm = ['base' => [], 'senior' => []];
        if ((int) $content->model_id > 0) {
            $customData = $this->contentService->getCustomData((int) $content->id, (int) $content->model_id);
            $customForm = (new FormService())->generateForm((int) $content->model_id, $customData, 'edit');
        }
        $this->assign('content', $content);
        $this->assign('categoryOptions', $categoryOptions);
        $this->assign('tagsText', $tagsText);
        $this->assign('customForm', $customForm);

        // UIUX-P1-06：正文接 wiseeditor（未启用则自动回退 textarea）
        $this->assignBodyEditor((string) $content->content);

        return $this->fetch('admin/content/edit');
    }

    /**
     * 正文编辑器渲染 + 资源下发（UIUX-P1-06）
     *
     * 优先用框架 `EditorManager` 渲染 wiseeditor；**未安装/未启用/异常时回退 `<textarea>`**
     * ——「检测到即启用、检测不到就退化」，保证无编辑器环境下表单照常可用。
     *
     * 契约（与 `field/editor/Form::renderWithWiseEditor()` 同一套，勿改）：
     *   · 字段名固定 `content`；
     *   · 值的承载元素 id 固定 `editor_content_input`（驱动产出的隐藏域 / 回退的 textarea 同名），
     *     前端「字数统计」与「插入内链」兜底都读它；
     *   · 编辑器容器 id = `editor_content`（`WiseEditor.get('editor_content')`）。
     *
     * @param string $value 现有正文（HTML）
     */
    protected function assignBodyEditor(string $value): void
    {
        $fallback = '<textarea name="content" id="editor_content_input" class="form-control" rows="24">'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>';

        $html     = $fallback;
        $assets   = ['css' => [], 'js' => []];
        $mounted  = false;

        try {
            $manager = app('editor.manager');
            if ($manager instanceof \app\common\library\EditorManager && $manager->has('richtext')) {
                $html    = $manager->render('content', $value, [
                    'type'        => 'richtext',
                    'height'      => 460,
                    'placeholder' => '请输入正文…',
                ]);
                $assets  = $manager->collectAssets('richtext');
                $mounted = true;
            }
        } catch (\Throwable $e) {
            // 编辑器异常不应阻断内容表单
            cms_log('warning', '正文编辑器渲染失败，已回退 textarea: ' . $e->getMessage());
        }

        $this->assign('bodyEditorHtml', $html . ($mounted ? $this->editorAssetTags($assets) : ''));
        $this->assign('bodyEditorMounted', $mounted);
    }

    /**
     * 编辑器静态资源标签（CSS + ESM 入口）
     *
     * ⚠️ 刻意**由 PHP 拼进 HTML**，而不是写进模板：本插件模板受「零内联脚本」守卫约束
     *    （模板内一律不得出现 `<script`），而 wiseeditor 只能靠**同源外部模块**加载
     *    （后台 CSP `script-src 'self'`，禁内联、禁 CDN）。
     *    与 `field/editor/Form::wiseEditorAssets()` 的做法完全一致（那里同样拼进字段 HTML）。
     *
     * @param array{css?: array, js?: array} $assets `EditorManager::collectAssets()` 结果
     *                                              （路径相对 `/statics/`）
     */
    protected function editorAssetTags(array $assets): string
    {
        $html = '';

        foreach ((array) ($assets['css'] ?? []) as $css) {
            $html .= '<link rel="stylesheet" href="/statics/' . ltrim((string) $css, '/') . '">' . "\n";
        }
        foreach ((array) ($assets['js'] ?? []) as $js) {
            $html .= '<script type="module" src="/statics/' . ltrim((string) $js, '/') . '"></script>' . "\n";
        }

        return $html;
    }

    /**
     * 内链候选（UIUX-P1-06：编辑器「插入内联链接」的选择器数据源）
     *
     * 只出**本站已发布**内容；URL 由 `UrlService` 生成（全站唯一事实源，
     * 与前台真实链接、RSS 一致），并转成**绝对 URL**（内链在 RSS / 移动端同样可用）。
     */
    public function linkOptions()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $limit   = min(20, max(1, (int) $this->request->param('limit', 10)));

        $query = ContentModel::where('site_id', $this->siteId())
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->limit($limit);

        if ($keyword !== '') {
            $query->whereLike('title', '%' . addcslashes($keyword, '%_\\') . '%');
        }

        $urlService = new UrlService();
        $items      = [];

        foreach ($query->field('id,title,slug,catid,site_id,create_time,publish_time')->select() as $row) {
            $items[] = [
                'id'    => (int) $row->id,
                'title' => (string) $row->title,
                'url'   => $urlService->absolute($urlService->contentPath($row)),
            ];
        }

        return $this->success('', ['items' => $items]);
    }

    /**
     * 删除内容
     */
    public function delete()
    {
        $id = (int) $this->request->post('id');
        try {
            // P2：只允许删除「后台当前站点」的内容
            if (!$this->ownsContent(ContentModel::find($id))) {
                throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
            }
            $this->contentService->delete($id);
            return $this->success('已移至回收站');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 批量删除
     */
    public function batchDelete()
    {
        // P2：先按「后台当前站点」过滤，跨站 id 静默剔除（不暴露存在性）
        $ids = $this->ownedIds($this->request->post('ids/a', []));
        if (empty($ids)) {
            return $this->error('请选择要删除的内容');
        }
        $count = $this->contentService->batchDelete(array_map('intval', $ids));
        return $this->success("成功删除 {$count} 条内容");
    }

    /**
     * 切换状态
     */
    public function toggleStatus()
    {
        $id = (int) $this->request->post('id');
        $status = $this->request->post('status', 2);
        try {
            // P2：只允许切换「后台当前站点」内容的状态（含发布/下线）
            if (!$this->ownsContent(ContentModel::find($id))) {
                throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
            }
            $this->contentService->updateStatus($id, (int)$status);
            return $this->success('状态更新成功');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 切换内容标志位（置顶 / 推荐）—— UIUX-P0-46
     *
     * 为什么需要独立 action：`toggleStatus` 只处理 `status`(0..3)，且状态值经
     * `ContentService::updateStatus` 的 `[0,1,2,3]` 白名单与审核态约束
     * （`ContentService.php:570-590`）；`is_top` / `is_recommend` 此前**没有任何切换入口**，
     * 只能在 create/edit 提交时随白名单字段一次性写入 → 列表页无法提供行内开关
     * （表头列 `is_top` / `is_recommend` 有值却"看得见改不了"）。
     *
     * 入参兼容两种形状，都是 EnhancedTable `toggle` formatter 的产物：
     *   ① 动态列名：`id=5&is_top=1`
     *      —— formatter 产出 `data-params="id=5&is_top=1"`（`enhanced-table.js:2250`），
     *         经 `$.ajax({type:'POST', data:'...'})` 原样发出；且它从 `data.msg` 取提示文案
     *         （`:1769`）→ 本方法必须回 `msg`；`code !== 0` 会让开关自动回弹（`:1781-1784`）。
     *   ② 显式键：`id=5&field=is_top&value=1`（便于自定义触发器/脚本调用）。
     */
    public function toggleFlags()
    {
        $id = (int) $this->request->post('id');
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        // 白名单只放行两个布尔标志位：
        //   · posids 是「位运算多选」（推荐位），用布尔开关表达会丢信息；
        //   · listorder 属排序，语义与开关不同。
        $allowed = ['is_top', 'is_recommend'];

        $field = (string) $this->request->post('field', '');
        $value = $this->request->post('value', null);

        if ($field === '') {
            $post = $this->request->post();
            foreach ($allowed as $candidate) {
                if (array_key_exists($candidate, $post)) {
                    $field = $candidate;
                    $value = $post[$candidate];
                    break;
                }
            }
        }

        if (!in_array($field, $allowed, true) || $value === null) {
            return $this->error('参数错误');
        }

        try {
            // P2：只允许操作「后台当前站点」的内容（越界按「内容不存在」拒绝）
            $content = ContentModel::find($id);
            if (!$this->ownsContent($content)) {
                throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
            }

            $flag = in_array((string) $value, ['1', 'true', 'on'], true) ? 1 : 0;
            $content->save([$field => $flag]);

            // 列表 / 推荐位 / 标签云 / sitemap / feed 均受标志位影响，
            // 统一走 onContentSaved（与 ContentService::updateStatus 同一套失效口径）
            (new CacheService())->onContentSaved($id, (int) $content->catid, (int) $content->site_id);

            return $this->success($flag === 1 ? '已开启' : '已关闭', [$field => $flag]);
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }

    /**
     * 移动栏目
     */
    public function moveCategory()
    {
        // P2：同上，批量移动只在「后台当前站点」范围内生效
        $ids = $this->ownedIds($this->request->post('ids/a', []));
        $catid = $this->request->post('catid', 0);
        if (empty($ids) || empty($catid)) {
            return $this->error('参数错误');
        }
        $this->contentService->batchMoveCategory(array_map('intval', $ids), (int)$catid);
        return $this->success('移动成功');
    }

    /**
     * 版本历史列表
     *
     * 版本语义（保存后快照）：vN = 第 N 次保存后状态，最新版本即当前线上内容。
     */
    public function versions()
    {
        $id    = (int) $this->request->param('id', 0);
        $page  = (int) $this->request->param('page', 1);
        $limit = (int) $this->request->param('limit', 20);

        $content = $this->contentService->getDetail($id);
        // P2：只允许查看「后台当前站点」内容的版本历史
        if (!$this->ownsContent($content)) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        $result = $this->contentService->listVersions($id, $page, $limit);

        if ($this->request->isAjax()) {
            return $this->success('', $result);
        }

        // 兼容 admin/pager 模板的分页字段契约
        $list = [
            'total'        => $result['total'],
            'per_page'     => $result['limit'],
            'current_page' => $result['page'],
            'last_page'    => max(1, (int) ceil($result['total'] / max(1, $result['limit']))),
            'data'         => $result['data'],
        ];

        $this->assign('content', $content);
        $this->assign('versions', $result['data']);
        $this->assign('list', $list);
        $this->assign('keep', $result['keep']);
        $this->assign('currentVersion', $result['current_version']);
        $this->assign('baseUpdateTime', (string) $content->update_time);
        return $this->fetch('admin/content/versions');
    }

    /**
     * 版本对比（base 缺省为「当前线上内容」）
     */
    public function versionDiff()
    {
        $id            = (int) $this->request->param('id', 0);
        $versionId     = (int) $this->request->param('version_id', 0);
        $baseVersionId = (int) $this->request->param('base_version_id', 0);

        // P2：版本对比同样限定在「后台当前站点」的内容上
        if (!$this->ownsContent(ContentModel::find($id))) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        try {
            $result = $this->contentService->diffVersion(
                $id,
                $versionId,
                $baseVersionId > 0 ? $baseVersionId : null
            );
            return $this->success('', $result);
        } catch (WisecmsException $e) {
            cms_log('error', '版本对比失败: ' . $e->getMessage(), [
                'content_id' => $id,
                'version_id' => $versionId,
                'code'       => $e->getErrorCode(),
            ]);
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', '版本对比失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('对比失败，请稍后重试');
        }
    }

    /**
     * 回滚到指定版本
     *
     * 回滚会在事务内先锁定内容与版本行，再以白名单载荷复用 updateWithFields()，
     * 因此回滚前状态仍保留在版本表中（可再次回退）。
     */
    public function rollback()
    {
        $id             = (int) $this->request->post('id', 0);
        $versionId      = (int) $this->request->post('version_id', 0);
        // 乐观锁基线：客户端提交的「当前更新时间」，用于拒绝覆盖他人修改
        $baseUpdateTime = $this->request->post('base_update_time', null);

        // P2：只允许回滚「后台当前站点」的内容
        if (!$this->ownsContent(ContentModel::find($id))) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        try {
            $content = $this->contentService->rollback($id, $versionId, [
                'base_update_time' => $baseUpdateTime,
            ]);

            return $this->success('已回滚到该版本，并生成了一条新的版本记录', [
                'update_time' => (string) $content->update_time,
            ]);
        } catch (WisecmsException $e) {
            cms_log('error', '内容版本回滚失败: ' . $e->getMessage(), [
                'content_id' => $id,
                'version_id' => $versionId,
                'code'       => $e->getErrorCode(),
            ]);
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', '内容版本回滚失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 删除单个版本快照
     */
    public function versionDelete()
    {
        $id        = (int) $this->request->post('id', 0);
        $versionId = (int) $this->request->post('version_id', 0);

        // P2：只允许删除「后台当前站点」内容的版本快照
        if (!$this->ownsContent(ContentModel::find($id))) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        try {
            $this->contentService->deleteVersion($id, $versionId);
            return $this->success('版本已删除');
        } catch (WisecmsException $e) {
            cms_log('error', '版本删除失败: ' . $e->getMessage(), [
                'content_id' => $id,
                'version_id' => $versionId,
                'code'       => $e->getErrorCode(),
            ]);
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', '版本删除失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 签发内容预览令牌（B-3）
     *
     * 位于后台控制器（route/plugin.php，带 plugin/wisecms 前缀），自动继承 RBAC：
     * 只有拥有该控制器权限的后台用户才能签发预览链接。
     */
    public function previewToken()
    {
        $id = (int) $this->request->post('id', 0);

        $content = $this->contentService->getDetail($id);
        // P2：只为「后台当前站点」的内容签发预览令牌
        if (!$this->ownsContent($content)) {
            return $this->pageFallback('内容不存在', plugin_url('wisecms/content/index'));
        }

        try {
            $result = (new PreviewService())->generate(
                $id,
                (int) ($content->site_id ?: 1),
                null,
                (int) ($this->currentAdminId() ?: 0)
            );

            // ['token', 'url', 'expires_at']
            return $this->success('预览链接已生成', $result);
        } catch (\Throwable $e) {
            cms_log('error', '预览令牌生成失败: ' . $e->getMessage(), ['content_id' => $id]);
            return $this->error('预览功能未开启或未配置密钥');
        }
    }
}
