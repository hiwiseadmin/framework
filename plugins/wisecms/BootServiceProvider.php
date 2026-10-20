<?php
namespace plugin\wisecms;

use wise\plugin\WiseBootServiceProvider;
use plugin\wisecms\pipeline\ContentPipeline;

class BootServiceProvider extends WiseBootServiceProvider
{
    /**
     * 并入全局 view 域的配置白名单。
     *
     * config/view.php 声明 taglib_pre_load，由基类 boot() 的 Step 2c 合并进全局 view 域，
     * 模板中即可直接使用 {cms:arclist} 等标签（think-template 原生预加载机制，无需引擎改造）。
     * 前缀由类名末段推导（Cms → cms），故对外语法固定为 {cms:xxx}。
     *
     * taglib_pre_load 声明为「并集键」：多插件共存时取并集，不互相覆盖。
     *
     * 详见 docs/wisecms-template-taglib-design.md §7
     *
     * @var array<string, array<int, string>>
     */
    protected array $globalConfigMap = [
        'view' => ['taglib_pre_load'],
    ];

    public function register()
    {
        parent::register();

        // Phase 1 核心服务
        $this->app->bind('wisecms.content', \plugin\wisecms\service\content\ContentService::class);
        $this->app->bind('wisecms.category', \plugin\wisecms\service\content\CategoryService::class);
        $this->app->bind('wisecms.tag', \plugin\wisecms\service\content\TagService::class);
        $this->app->bind('wisecms.field', \plugin\wisecms\service\model\FieldFactory::class);
        $this->app->bind('wisecms.field.registry', \plugin\wisecms\service\model\FieldRegistry::class);
        $this->app->bind('wisecms.update', \plugin\wisecms\service\model\UpdateService::class);

        // Phase 2 增强服务
        $this->app->bind('wisecms.pipeline', ContentPipeline::class);
        $this->app->bind('wisecms.quality', \plugin\wisecms\service\engine\QualityScorer::class);
        $this->app->bind('wisecms.seo.audit', \plugin\wisecms\service\seo\SeoAuditService::class);
        $this->app->bind('wisecms.seo.push', \plugin\wisecms\service\seo\SeoPushService::class);
        $this->app->bind('wisecms.seo.sitemap', \plugin\wisecms\service\seo\SitemapService::class);
        $this->app->bind('wisecms.seo.structured', \plugin\wisecms\service\seo\StructuredDataService::class);
        $this->app->bind('wisecms.seo.ogimage', \plugin\wisecms\service\seo\OgImageService::class);
        $this->app->bind('wisecms.template', \plugin\wisecms\service\content\TemplateService::class);
        $this->app->bind('wisecms.internallink', \plugin\wisecms\service\seo\InternalLinkService::class);

        // Phase 3 服务
        $this->app->bind('wisecms.generate', \plugin\wisecms\service\engine\GenerationEngine::class);
        $this->app->bind('wisecms.scheduler', \plugin\wisecms\service\engine\SchedulerService::class);
        $this->app->bind('wisecms.search', \plugin\wisecms\service\content\SearchService::class);
        $this->app->bind('wisecms.comment', \plugin\wisecms\service\content\CommentService::class);
        $this->app->bind('wisecms.cache', \plugin\wisecms\service\site\CacheService::class);

        // Phase 4 集成服务
        $this->app->bind('wisecms.workflow', \plugin\wisecms\service\engine\WorkflowService::class);
        $this->app->bind('wisecms.notify', \plugin\wisecms\service\integration\NotificationService::class);
        $this->app->bind('wisecms.attach', \plugin\wisecms\service\content\AttachmentService::class);
        $this->app->bind('wisecms.agent', \plugin\wisecms\service\integration\AgentIntegrationService::class);
        $this->app->bind('wisecms.crontab', \plugin\wisecms\service\engine\CrontabService::class);

        // Phase 5 增值服务
        $this->app->bind('wisecms.site', \plugin\wisecms\service\site\SiteService::class);
        $this->app->bind('wisecms.spider', \plugin\wisecms\service\engine\SpiderService::class);
        $this->app->bind('wisecms.webhook', \plugin\wisecms\service\integration\WebhookService::class);
        $this->app->bind('wisecms.redirect', \plugin\wisecms\service\content\RedirectService::class);
        $this->app->bind('wisecms.analytics', \plugin\wisecms\service\integration\AnalyticsService::class);
    }

    public function boot()
    {
        parent::boot();

        // 注册 Pipeline 阶段
        $this->registerPipelineStages();

        // 注册定时任务（接线 CrontabService）
        $this->registerCronTasks();
    }

    protected function registerCronTasks(): void
    {
        try {
            (new \plugin\wisecms\service\engine\CrontabService())->registerTasks();
        } catch (\Throwable $e) {
            // 定时任务注册失败不阻断启动（wise-crontab 未安装时静默跳过）
        }
    }

    protected function registerPipelineStages(): void
    {
        // 在所有环境（Controller + CLI）中注册 Pipeline
        if ($this->app->has('wisecms.pipeline')) {
            $pipeline = $this->app->make('wisecms.pipeline');
            $pipeline->registerStage(new \plugin\wisecms\pipeline\stage\KeywordExtractStage());
            $pipeline->registerStage(new \plugin\wisecms\pipeline\stage\AutoDescriptionStage());
            $pipeline->registerStage(new \plugin\wisecms\pipeline\stage\InternalLinkStage());
        }
    }
}
