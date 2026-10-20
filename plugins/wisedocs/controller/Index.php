<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use app\common\middleware\VerifyCsrfToken;
use plugin\FrontController;
use plugin\wisedocs\model\Category as CategoryModel;
use plugin\wisedocs\model\Document as DocumentModel;
use plugin\wisedocs\model\Feedback as FeedbackModel;
use plugin\wisedocs\model\Tag as TagModel;
use plugin\wisedocs\service\ComponentPreviewService;
use plugin\wisedocs\service\HelpService;
use plugin\wisedocs\service\SanitizerService;
use plugin\wisedocs\service\ScheduleService;
use plugin\wisedocs\service\SearchService;
use plugin\wisedocs\service\SpaceService;
use plugin\wisedocs\service\StatService;
use think\facade\Cache;
use think\facade\Db;

/**
 * 帮助中心前台（P3-1）
 *
 * 前台控制器继承 plugin\FrontController：免登录（不受后台鉴权中间件约束），
 * 但所有写操作（反馈）均做纯文本净化、频率限制与 CSRF 校验（见 $middleware）。
 */
class Index extends FrontController
{
    /** 文档-标签关联表 */
    protected const PIVOT = 'wisedocs_document_tags';

    /**
     * 前台中间件（H1）
     *
     * 前台**不需要登录**，但写操作（`feedback` 提交）必须校验 CSRF 令牌，
     * 防止第三方站点诱导访客/管理员浏览器发起跨站提交。
     * 只读动作（GET/HEAD/OPTIONS）仅补齐 Token，不受影响。
     */
    protected $middleware = [
        VerifyCsrfToken::class,
    ];

    protected SpaceService $spaceService;

    protected function initPlugin(): void
    {
        $this->spaceService = new SpaceService($this->request);

        // 多主题：按当前空间切换主题（P5-3）
        $this->spaceService->applyTheme();
    }

    protected function helpService(): HelpService
    {
        return new HelpService($this->spaceService->currentId());
    }

    /**
     * 前台可见的空间信息（键恒定，避免模板访问缺失键触发告警）
     */
    protected function spaceView(): array
    {
        $space = $this->spaceService->current();

        return [
            'id'          => (int) ($space['id'] ?? 0),
            'name'        => (string) ($space['name'] ?? ''),
            'code'        => (string) ($space['code'] ?? ''),
            'description' => (string) ($space['description'] ?? ''),
            'logo'        => (string) ($space['logo'] ?? ''),
            'theme'       => (string) ($space['theme'] ?? 'default'),
        ];
    }

    /**
     * 全部启用空间（前台多空间切换入口）
     */
    protected function spaceOptions(): array
    {
        return array_map(static function (array $item): array {
            return [
                'id'   => (int) ($item['id'] ?? 0),
                'name' => (string) ($item['name'] ?? ''),
            ];
        }, $this->spaceService->all());
    }

    /**
     * 首页
     */
    public function index()
    {
        // 惰性发布到期的定时文档（P5-2，60 秒节流）
        (new ScheduleService())->publishDue();

        $categoryId = (int) $this->request->param('category_id/d', 0);
        $help       = $this->helpService();

        $currentCategory = $categoryId > 0 ? CategoryModel::find($categoryId) : null;

        $this->assign([
            'space'           => $this->spaceView(),
            'spaces'          => $this->spaceOptions(),
            'categoryTree'    => $help->categoryTree(),
            'currentCategory' => $currentCategory,
            'categoryId'      => $categoryId,
            'featured'        => $help->featuredDocuments(),
            'hot'             => $help->hotDocuments(),
            'documents'       => $help->documents($categoryId, 15),
            'keyword'         => '',
            'title'           => $currentCategory ? $currentCategory->name : '帮助中心',
            // 前台主题（视图）所需：侧栏高亮分类 + 主区宽度修饰类
            'activeCategoryId' => $categoryId,
            'mainClass'        => 'wd-main__inner--wide',
        ]);

        return $this->fetch();
    }

    /**
     * 文档详情
     */
    public function detail($slug = '')
    {
        (new ScheduleService())->publishDue();

        $document = DocumentModel::with(['category'])
            ->where('slug', $slug)
            ->where('status', DocumentModel::STATUS_PUBLISHED)
            ->find();

        if (!$document) {
            return $this->error('文档不存在');
        }

        // 浏览计数：统计表按天累加 + 文档冗余计数（P4-2）
        (new StatService())->recordView((int) $document->id);
        $document->view_count = (int) $document->view_count + 1;

        $help      = $this->helpService();
        $neighbors = $help->neighbors($document);

        $tagIds = array_map('intval', Db::name(self::PIVOT)->where('document_id', $document->id)->column('tag_id'));
        $tags   = empty($tagIds)
            ? []
            : TagModel::whereIn('id', $tagIds)->where('status', 1)->select()->toArray();

        // 组件型（D4）：解析演示配置，供详情页渲染沙箱 iframe 与源码面板
        $isComponent = (int) $document->content_type === DocumentModel::CONTENT_TYPE_COMPONENT;
        $demo        = $isComponent
            ? (new ComponentPreviewService())->decode($document->demo_config)
            : [];

        // 左侧「详情页入口」：当前文档所属分类下的全部已发布文档
        $categoryDocs = $help->documents((int) $document->category_id, 100);

        $this->assign([
            'space'               => $this->spaceView(),
            'spaces'              => $this->spaceOptions(),
            'document'            => $document,
            'category'            => $document->category,
            'categoryTree'        => $help->categoryTree(),
            'categoryDocs'        => $categoryDocs,
            'currentCategoryName' => $document->category ? (string) $document->category->name : '全部文档',
            'tags'                => $tags,
            'prev'         => $neighbors['prev'],
            'next'         => $neighbors['next'],
            'related'      => $help->related($document),
            'demo'         => $demo,
            // 沙箱演示页地址（同源 + CSP sandbox 隔离）
            'previewUrl'   => $isComponent
                ? '/plugin/wisedocs/preview/render?slug=' . urlencode((string) $document->slug)
                : '',
            'keyword'      => '',
            'title'        => $document->title,
            // 侧栏高亮所属分类
            'activeCategoryId' => (int) $document->category_id,
        ]);

        return $this->fetch();
    }

    /**
     * 搜索
     */
    public function search()
    {
        (new ScheduleService())->publishDue();

        $keyword    = trim((string) $this->request->param('keyword', ''));
        $categoryId = (int) $this->request->param('category_id/d', 0);
        $page       = max(1, (int) $this->request->param('page/d', 1));

        $searchService = new SearchService($this->spaceService->currentId());
        $result        = $searchService->search($keyword, $categoryId, $page, 15);

        // 热搜统计（P5-1）：仅在有关键词时记录；无关键词时展示热搜榜
        $searchService->recordSearch($keyword);
        $hotSearches = $keyword === '' ? $searchService->hotSearches(10) : [];

        $this->assign([
            'space'        => $this->spaceView(),
            'spaces'       => $this->spaceOptions(),
            'categoryTree' => $this->helpService()->categoryTree(),
            'categoryId'   => $categoryId,
            'keyword'      => $keyword,
            'documents'    => $result['documents'],
            'total'        => $result['total'],
            'suggestions'  => $result['suggestions'],
            'hotSearches'  => $hotSearches,
            'title'        => $keyword === '' ? '搜索' : '搜索：' . $keyword,
            // 视图所需：侧栏分类链接保持搜索上下文 + 主区宽度
            'activeCategoryId'      => $categoryId,
            'mainClass'             => 'wd-main__inner--wide',
            'sidebarCategoryBase'   => '/plugin/wisedocs/index/search',
            'sidebarCategoryQuery'  => $keyword === '' ? '' : '&keyword=' . urlencode($keyword),
        ]);

        return $this->fetch();
    }

    /**
     * 提交反馈（有用 / 无用 / 建议）
     */
    public function feedback()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $type = (int) $this->request->post('type', FeedbackModel::TYPE_HELPFUL);
        if (!in_array($type, [
            FeedbackModel::TYPE_HELPFUL,
            FeedbackModel::TYPE_UNHELPFUL,
            FeedbackModel::TYPE_SUGGESTION,
        ], true)) {
            return $this->error('反馈类型不合法');
        }

        $documentId = (int) $this->request->post('document_id', 0);
        $document   = DocumentModel::find($documentId);
        if (!$document) {
            return $this->error('文档不存在');
        }

        // 简易频率限制（同 IP 10 秒一次）
        $throttleKey = 'wisedocs:feedback:' . md5((string) $this->request->ip());
        if (Cache::get($throttleKey)) {
            return $this->error('提交过于频繁，请稍后再试');
        }
        Cache::set($throttleKey, 1, 10);

        $sanitizer = new SanitizerService();

        try {
            FeedbackModel::create([
                'document_id' => $documentId,
                'type'        => $type,
                // 显式落库，避免列为 NULL 导致「待处理」筛选漏数据
                'processed'   => FeedbackModel::STATUS_PENDING,
                'comment'     => $sanitizer->cleanText($this->request->post('comment', ''), 2000),
                'ip'          => $this->request->ip(),
                'user_agent'  => mb_substr((string) $this->request->server('HTTP_USER_AGENT', ''), 0, 255),
                'user_id'     => (int) (session('member_id') ?: session('user_id') ?: 0),
            ]);

            if ($type === FeedbackModel::TYPE_HELPFUL) {
                (new StatService())->recordFeedback($documentId, FeedbackModel::TYPE_HELPFUL);
            } elseif ($type === FeedbackModel::TYPE_UNHELPFUL) {
                (new StatService())->recordFeedback($documentId, FeedbackModel::TYPE_UNHELPFUL);
            }

            return $this->success('感谢您的反馈');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] feedback failed: ' . $e->getMessage());

            return $this->error('提交失败，请稍后重试');
        }
    }
}
