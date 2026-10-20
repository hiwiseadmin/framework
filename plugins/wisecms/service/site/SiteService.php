<?php
namespace plugin\wisecms\service\site;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Category;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\FriendLink;
use plugin\wisecms\model\InternalLink;
use plugin\wisecms\model\Page;
use plugin\wisecms\model\Redirect;
use plugin\wisecms\model\Site;
use plugin\wisecms\model\Tag;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\model\UrlAlias;
use plugin\wisecms\model\UrlRule;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\content\TagService;
use plugin\wisecms\service\url\UrlService;
use think\facade\Db;

/**
 * 多站点管理 + 站群SEO + 站点上下文解析
 *
 * 提供当前站点解析（域名匹配 > 会话切换 > 默认站点）与内容跨站复制。
 *
 * 安全约定（P1-B3）：
 *   · **不信任 `?site_id=` 请求参数** —— 站点归属只由域名/绑定决定；
 *   · 后台「切换站点」按**会话**隔离，不再写全局缓存键（否则影响所有访客）。
 */
class SiteService
{
    public function getSites(): array
    {
        return \plugin\wisecms\model\Site::where('status', 1)->order('sort')->select()->toArray();
    }

    /**
     * 切换当前站点（后台「切换站点」用）
     *
     * P1-B3：③ 会话级键 —— 原实现写全局键 `wisecms:site:current`（TTL 86400），
     * 一次调用会改变**所有访客**的默认站点，既是越权也是缓存污染源。
     * 现按会话隔离；无会话（CLI/API）时写入进程内静态，不落共享缓存。
     */
    public function switchSite(int $siteId): void
    {
        $siteId = max(0, $siteId);

        try {
            if (function_exists('session')) {
                session(CacheService::KEY_SITE_CURRENT, $siteId);
                return;
            }
        } catch (\Throwable $e) {
            // 会话不可用则降级到请求内静态，避免污染全局缓存
        }

        self::$sessionSiteId = $siteId;
    }

    /**
     * 会话级站点覆盖（switchSite 写入）
     */
    protected static int $sessionSiteId = 0;

    /**
     * 读取会话级站点覆盖（0 = 未设置）
     */
    protected function sessionSiteId(): int
    {
        if (self::$sessionSiteId > 0) {
            return self::$sessionSiteId;
        }

        try {
            if (function_exists('session')) {
                return max(0, (int) session(CacheService::KEY_SITE_CURRENT));
            }
        } catch (\Throwable $e) {
            // 忽略：取不到会话按未设置处理
        }

        return 0;
    }

    /**
     * 解析当前站点 ID
     *
     * 优先级：域名匹配 > 会话切换 > 默认站点。
     *
     * P1-B3：**不再信任 `?site_id=N` 请求参数** —— 原实现把它排在最高优先级，
     * 任何前台请求加个参数即可切换到任意站点，读到别的站点内容（越权 + 缓存污染）。
     * 站点归属只应由域名/绑定决定；后台需要跨站操作时应显式传参并走权限校验，
     * 而非依赖「当前站点」这一隐式上下文。
     */
    public function resolveCurrentSiteId(): int
    {
        // 1. 域名匹配（wisecms_site.domain）—— 站点归属的第一事实源
        $host = request()->host();
        if ($host !== '') {
            $site = \plugin\wisecms\model\Site::where('domain', $host)
                ->where('status', 1)
                ->find();
            if ($site) {
                return (int) $site->id;
            }
        }

        // 2. 会话切换（后台显式切换，按会话隔离）
        $sessionSiteId = $this->sessionSiteId();
        if ($sessionSiteId > 0) {
            return $sessionSiteId;
        }

        // 3. 默认站点
        return $this->getDefaultSiteId();
    }

    /**
     * 获取当前站点记录（不存在返回 null）
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentSite(): ?array
    {
        $site = \plugin\wisecms\model\Site::find($this->resolveCurrentSiteId());
        return $site ? $site->toArray() : null;
    }

    /**
     * 站点级设置（UIUX-P1-30）：读 `wisecms_site.setting`（模型 `$json` 自动解数组）
     *
     * 此前该列「只写没人读」—— 多站点下各站的站点名/备案号等应独立，但前台一直读
     * 插件全局配置。此 getter 是站点级设置的第一读取点，供前台 `assignFrontData` 等消费。
     *
     * @return array<string, mixed> 空数组表示该站点未设置任何站点级配置
     */
    public function getSiteSetting(int $siteId): array
    {
        $site = \plugin\wisecms\model\Site::find($siteId);
        return is_array($site->setting ?? null) ? $site->setting : [];
    }

    /**
     * 站点级 SEO 设置（UIUX-P1-30）：读 `wisecms_site.seo_setting`
     *
     * 键约定（与后台编辑器字段一一对应，见 P1-30）：
     *   `keywords` / `description` / `title_format` / `og_image`
     *
     * @return array<string, mixed> 空数组表示未设置，消费方回退到实体级/全局默认
     */
    public function getSiteSeoSetting(int $siteId): array
    {
        $site = \plugin\wisecms\model\Site::find($siteId);
        return is_array($site->seo_setting ?? null) ? $site->seo_setting : [];
    }

    /**
     * 后台「当前站点」（P2：会话切换优先，**不看域名**）
     *
     * 前台以域名判定站点归属（{@see resolveCurrentSiteId()}），但后台的「当前站点」是
     * **编辑上下文**：管理员在站点 A 的域名上访问后台时，仍应能用选择器切到站点 B 编辑它的内容。
     * 若后台也走域名优先，选择器在「后台域名 == 某站点域名」时就会完全失效。
     *
     * 故后台链路统一使用本方法：**会话切换 > 默认站点**。
     */
    public function resolveAdminSiteId(): int
    {
        $sessionSiteId = $this->sessionSiteId();

        return $sessionSiteId > 0 ? $sessionSiteId : $this->getDefaultSiteId();
    }

    /**
     * 切换后台当前站点（后台站点选择器的唯一写入口）
     *
     * 校验：站点必须存在且启用 —— 禁用站点不应被切为当前编辑上下文
     * （否则后台编辑的内容会落到一个前台不再展示的站点上）。
     *
     * @return array<string, mixed> 切换后的站点
     * @throws WisecmsException 站点不存在或已禁用
     */
    public function switchTo(int $siteId): array
    {
        $site = \plugin\wisecms\model\Site::find($siteId);
        if (!$site || (int) $site->status !== 1) {
            throw new WisecmsException('站点不存在或已禁用', ErrorCode::NOT_FOUND);
        }

        $this->switchSite($siteId);

        // 站点上下文影响所有按站点缓存的读路径，整体失效最稳妥
        (new CacheService())->flush();

        return $site->toArray();
    }

    /**
     * 站点选择器数据源（后台下拉框）
     *
     * @return array{current:int, list:array<int, array<string, mixed>>}
     */
    public function getSwitchOptions(): array
    {
        $list = \plugin\wisecms\model\Site::where('status', 1)
            ->field('id,name,domain')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return [
            'current' => $this->resolveAdminSiteId(),
            'list'    => $list,
        ];
    }

    /**
     * 默认站点：第一个启用站点，否则回退 1
     */
    public function getDefaultSiteId(): int
    {
        $site = \plugin\wisecms\model\Site::where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find();
        return $site ? (int) $site->id : 1;
    }

    /**
     * 删除站点（P1-C 数据加固）
     *
     * 插件全表零外键，原实现 `Site::destroy()` 会把 content / category / page /
     * url_alias / friend_link / internal_link / redirect 的 `site_id` 全留成悬空值：
     * 内容查不到、别名唯一键被占、前台按站点过滤后什么都不显示。现改为：
     *   ① 站点下仍有内容/栏目/单页（含回收站）→ **拒绝删除**，避免整站数据变孤儿；
     *   ② 只剩站点级附属数据时一并清理，不留悬空行。
     */
    public function deleteSite(int $id): void
    {
        $site = Site::find($id);
        if (!$site) {
            throw new WisecmsException('站点不存在', ErrorCode::NOT_FOUND);
        }

        $contentCount  = Content::withTrashed()->where('site_id', $id)->count();
        $categoryCount = Category::withTrashed()->where('site_id', $id)->count();
        $pageCount     = Page::withTrashed()->where('site_id', $id)->count();

        if ($contentCount + $categoryCount + $pageCount > 0) {
            throw new WisecmsException(
                "该站点下仍有 {$contentCount} 篇内容 / {$categoryCount} 个栏目 / {$pageCount} 个单页（含回收站），请先清空后再删除站点",
                ErrorCode::VALIDATE_FAILED
            );
        }

        Db::startTrans();
        try {
            UrlAlias::where('site_id', $id)->delete();
            UrlRule::where('site_id', $id)->delete();
            FriendLink::where('site_id', $id)->delete();
            InternalLink::where('site_id', $id)->delete();
            Redirect::where('site_id', $id)->delete();
            // 标签（P2：词表按站点隔离）——先删关联再删标签，不留悬空 tag_id；
            // 此处站点已无内容（上方已拒绝），故本站关联必然是残留行
            TagRelation::where('site_id', $id)->delete();
            Tag::where('site_id', $id)->delete();
            $site->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // 站点相关缓存（列表 / 别名 / SEO / feed）整体失效
        (new CacheService())->flush();
    }

    /**
     * 跨站复制内容（P1-C：副本必须是**完整内容**）
     *
     * 原实现只复制主表字段，标签关联与自定义字段（副表）全部丢失，副本成为
     * 「半残内容」：详情页缺自定义字段、标签页与标签云统计不到它。
     */
    public function copyContentToSite(int $contentId, int $targetSiteId, int $targetCatId): int
    {
        $content = Content::find($contentId);
        if (!$content) throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);

        $data = $content->toArray();
        unset($data['id'], $data['create_time'], $data['update_time'], $data['delete_time']);
        $data['site_id'] = $targetSiteId;
        $data['catid'] = $targetCatId;
        $data['canonical_url'] = $content->url ?: (new UrlService())->content($content);
        // 注意：(catid, slug) 有唯一键，跨站复制保留原 slug 会撞键 —— 置空由 URL 规则回退
        $data['slug'] = null;

        Db::startTrans();
        try {
            $newContent = Content::create($data);

            $modelId  = (int) $content->model_id;
            $newId    = (int) $newContent->id;
            $tagIds   = $this->copyTags((int) $content->id, $newId, $targetSiteId, $modelId);
            $this->copyCustomData((int) $content->id, $newId, $modelId);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // 标签计数重算放到事务外（口径：已发布 + 未回收）
        (new ContentService())->recountTags($tagIds);

        return $newId;
    }

    /**
     * 复制标签关联：**在目标站点内重建词表**，返回受影响的标签 ID
     *
     * P2 关键变化：标签词表已按站点隔离，跨站复制**不能**直接复用源站 `tag_id` ——
     * 那会让同一条标签行同时挂到两个站点的内容上，破坏「标签归属唯一站点」的前提
     * （也导致「删 A 站标签会连带清掉 B 站关联」）。
     *
     * 现按名称在目标站点 find-or-create，关联指向目标站点的标签行。
     *
     * @return int[] 受影响的标签 ID（源站 + 目标站，供计数重算）
     */
    protected function copyTags(int $sourceId, int $targetId, int $targetSiteId, int $modelId): array
    {
        $sourceTagIds = array_map('intval', TagRelation::where('content_id', $sourceId)->column('tag_id'));
        if ($sourceTagIds === []) {
            return [];
        }

        $names      = Tag::whereIn('id', $sourceTagIds)->column('name');
        $tagService = new TagService();
        $affected   = $sourceTagIds;

        foreach ($names as $name) {
            $tag = $tagService->findOrCreate($targetSiteId, (string) $name);

            TagRelation::create([
                'tag_id'     => $tag->id,
                'content_id' => $targetId,
                'site_id'    => $targetSiteId,
                'model_id'   => $modelId,
            ]);

            $affected[] = (int) $tag->id;
        }

        return $affected;
    }

    /**
     * 复制自定义字段副表数据（`wisecms_content_{model}_data`，主键与主表同值）
     */
    protected function copyCustomData(int $sourceId, int $targetId, int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }

        $model = \plugin\wisecms\model\Model::find($modelId);
        $table = $model ? (string) $model->table_name : '';
        if ($table === '' || !preg_match('/^wisecms_content_[a-z0-9_]+$/', $table)) {
            return;
        }

        try {
            $row = Db::name($table . '_data')->where('id', $sourceId)->find();
            if (!$row) {
                return;
            }

            // 副表可能带唯一索引，冲突时记录并继续（副本仍可用，仅缺该字段）
            $row['id'] = $targetId;
            Db::name($table . '_data')->insert($row);
        } catch (\Throwable $e) {
            cms_log('warning', '跨站复制自定义字段失败: ' . $e->getMessage(), [
                'content_id' => $sourceId,
                'table'      => $table . '_data',
            ]);
        }
    }

    protected function getSiteUrl(int $siteId): string
    {
        $site = \plugin\wisecms\model\Site::find($siteId);
        return $site ? $site->domain : config('wisecms.site_url', request()->domain());
    }
}
