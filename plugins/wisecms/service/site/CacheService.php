<?php
namespace plugin\wisecms\service\site;

use think\facade\Cache as CacheFacade;

class CacheService
{
    // 缓存键命名规范
    const KEY_CONTENT_DETAIL   = 'wisecms:content:%d';
    const KEY_CONTENT_LIST     = 'wisecms:list:%d';
    const KEY_CATEGORY_TREE    = 'wisecms:category:tree:%d';
    const KEY_CATEGORY_MAP     = 'wisecms:category:map';
    // 标签云：%d=siteId、%d=limit、%d=page（P2 标签词表按站点隔离后，键必须含站点）
    const KEY_TAG_CLOUD        = 'wisecms:tag:cloud:%d:%d:%d';
    const KEY_TAG_CONTENT      = 'wisecms:tag:content:%d';

    /**
     * 标签云失效前缀
     *
     * 键含 limit/page 无法枚举，统一按前缀整体失效（家族级 clear，宁多清不漏清）。
     */
    const TAG_CLOUD_PATTERN    = 'wisecms:tag:cloud:*';
    const KEY_HOT_SEARCH       = 'wisecms:search:hot';
    // 推荐位缓存（A-2，详见 docs/wisecms-recommend-design.md §4.2）
    // 键中不含 limit：按 recommend_cache_limit 一次性取满，调用方 array_slice
    const KEY_RECOMMEND_RELATED = 'wisecms:recommend:related:%d';   // 内容 ID
    const KEY_RECOMMEND_HOT     = 'wisecms:recommend:hot:%d';       // 站点 ID
    const KEY_RECOMMEND_PICKED  = 'wisecms:recommend:picked:%d';    // 站点 ID
    const KEY_MODEL_FIELDS     = 'wisecms:model:fields:%d';
    const KEY_CONFIG           = 'wisecms:config';
    const KEY_SITEMAP          = 'wisecms:sitemap';
    const KEY_SITE_CURRENT     = 'wisecms:site:current';
    const KEY_ANALYTICS_PV     = 'wisecms:analytics:pv:%d:%s';
    // Feed 订阅缓存（详见 docs/wisecms-feed-design.md §5.2）
    // %s=type(site|category|tag)、%d=scopeId、%d=siteId、%s=format(rss|atom)
    const KEY_FEED             = 'wisecms:feed:%s:%d:%d:%s';
    // 模板标签库缓存（详见 docs/wisecms-template-taglib-design.md §8.1）
    // %d=siteId、%s=参数指纹（normalize 后固定键序序列化再 md5 前 12 位）
    const KEY_TAG_ARCLIST      = 'wisecms:taglib:arclist:%d:%s';
    const KEY_TAG_CHANNEL      = 'wisecms:taglib:channel:%d:%s';
    const KEY_TAG_TAGS         = 'wisecms:taglib:tags:%d:%s';
    const KEY_TAG_LINKS        = 'wisecms:taglib:links:%d:%s';
    // 友情链接（B-1，详见 docs/wisecms-friendlink-design.md §7）
    // %d=siteId、%s=group（空分组用 '_' 占位）、%d=limit（0=全部）
    const KEY_FRIEND_LINK      = 'wisecms:friendlink:%d:%s:%d';

    /**
     * L1: 请求级缓存 (static array)
     */
    protected static array $requestCache = [];

    /**
     * 由缓存键 / 模式推导「失效标签」（家族粒度，P1-B2）
     *
     * 取键的前两段作为家族：`wisecms:taglib:arclist:1:abc` → `wisecms:taglib`。
     * 写入与失效使用**同一规则**，因此 `deletePattern()` 的 tag clear 能真正删掉
     * L3 条目（原实现写入侧从未打过 tag，`tag('cms')->clear()` 等同空操作）。
     */
    protected static function tagOf(string $keyOrPattern): string
    {
        $parts = explode(':', $keyOrPattern, 3);

        return $parts[0] . ':' . ($parts[1] ?? '');
    }

    /**
     * 全部缓存家族（由 KEY_* 常量自动推导，新增键无需手工登记）
     *
     * @return string[]
     */
    public static function families(): array
    {
        try {
            $constants = (new \ReflectionClass(self::class))->getConstants();
        } catch (\Throwable $e) {
            return [];
        }

        $families = [];
        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'KEY_') && is_string($value)) {
                $families[] = self::tagOf($value);
            }
        }

        return array_values(array_unique($families));
    }

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        // L1: 请求缓存
        if (isset(self::$requestCache[$key])) {
            return self::$requestCache[$key];
        }

        // L3: Redis/File 缓存（P1-B2：带 tag 写入，使 deletePattern() 真正生效）
        $value = CacheFacade::tag(self::tagOf($key))->remember($key, $callback, $ttl);

        // 存入 L1
        self::$requestCache[$key] = $value;
        return $value;
    }

    public function get(string $key, $default = null): mixed
    {
        if (isset(self::$requestCache[$key])) return self::$requestCache[$key];
        $value = CacheFacade::get($key, $default);
        if ($value !== null) self::$requestCache[$key] = $value;
        return $value;
    }

    public function set(string $key, $value, int $ttl = 3600): bool
    {
        self::$requestCache[$key] = $value;
        return CacheFacade::tag(self::tagOf($key))->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        unset(self::$requestCache[$key]);
        return CacheFacade::delete($key);
    }

    /**
     * 按模式失效缓存（P1-B2）
     *
     * L1 按 fnmatch 精确清理；L3 按**家族标签**整体清理。
     * 家族粒度意味着「同族跨站点」也会一并失效（如站点 1 的内容发布清掉站点 2 的
     * taglib 缓存）——这是驱动的 tag 机制不支持下钻的取舍：宁多清、不漏清，
     * 且各键本身有 900~1800s TTL 兜底。
     */
    public function deletePattern(string $pattern): void
    {
        // 清理 L1 中匹配的键
        foreach (self::$requestCache as $k => $v) {
            if (fnmatch($pattern, $k)) unset(self::$requestCache[$k]);
        }

        try {
            CacheFacade::tag(self::tagOf($pattern))->clear();
        } catch (\Throwable $e) {
            // 驱动不支持 tag 时降级为 TTL 兜底，不影响主流程
        }
    }

    /**
     * 清空本插件全部缓存
     *
     * 只清 wisecms 自己的缓存家族；**不再调用 `CacheFacade::clear()`**
     * ——那会清空整个缓存存储，波及其它插件与框架自身（审计 L-14）。
     */
    public function flush(): void
    {
        self::$requestCache = [];

        foreach (self::families() as $family) {
            try {
                CacheFacade::tag($family)->clear();
            } catch (\Throwable $e) {
                // 单个家族失败不影响其它家族
            }
        }
    }

    /**
     * 内容发布后清除相关缓存
     *
     * @param int $contentId 内容 ID
     * @param int $catid     所属栏目 ID
     * @param int $siteId    所属站点 ID（>0 时同步失效 feed 缓存；0 则仅靠 TTL）
     */
    public function onContentSaved(int $contentId, int $catid = 0, int $siteId = 0): void
    {
        $this->delete(sprintf(self::KEY_CONTENT_DETAIL, $contentId));
        if ($catid > 0) $this->delete(sprintf(self::KEY_CONTENT_LIST, $catid));
        // 标签云键含 site/limit/page，无法枚举 → 按前缀整体失效
        $this->deletePattern(self::TAG_CLOUD_PATTERN);
        $this->delete(self::KEY_SITEMAP);

        // 推荐位：该内容的 related 精确失效（键含内容 ID），hot/picked 为站点级
        // （内容增删改会改变站点热度的排序依据，键中不含 limit 故整键失效）
        $this->delete(sprintf(self::KEY_RECOMMEND_RELATED, $contentId));
        if ($siteId > 0) {
            $this->delete(sprintf(self::KEY_RECOMMEND_HOT, $siteId));
            $this->delete(sprintf(self::KEY_RECOMMEND_PICKED, $siteId));
        }

        // Feed 订阅：新内容进入全站 feed，同时可能进入所属栏目 feed
        if ($siteId > 0) {
            $this->deleteFeed('site', 0, $siteId);
            if ($catid > 0) {
                $this->deleteFeed('category', $catid, $siteId);
            }
        }

        // 模板标签库：内容增删改会改变 {cms:arclist} 的候选集与排序依据
        $this->onTaglibChanged($siteId, 'arclist');
    }

    /**
     * 栏目变更后清除
     */
    public function onCategoryChanged(int $siteId = 1): void
    {
        $this->delete(sprintf(self::KEY_CATEGORY_TREE, $siteId));
        $this->delete(self::KEY_CATEGORY_MAP);

        // 推荐位：栏目改名/层级变更会影响卡片展示，hot/picked 为站点级缓存整键失效
        $this->delete(sprintf(self::KEY_RECOMMEND_HOT, $siteId));
        $this->delete(sprintf(self::KEY_RECOMMEND_PICKED, $siteId));

        // 栏目名会出现在 feed 条目的 <category> 文本中，同步失效全站 feed
        $this->deleteFeed('site', 0, $siteId);

        // 模板标签库：栏目名/层级/别名变更会影响 {cms:channel} 以及 {cms:arclist}
        // 的 category_name 与 URL（'arclist' 条目内嵌了栏目名）。
        // $tag='' 表示清理该站点全部标签缓存，一次 deletePattern 同时覆盖两者。
        $this->onTaglibChanged($siteId);
    }

    /**
     * 清除某范围的 Feed 缓存
     *
     * format 参与缓存键（KEY_FEED 末位），rss / atom 两种格式必须都清；
     * 标签范围 feed 还受标签关联变更影响，现由 TTL 兜底
     * （见 docs/wisecms-feed-design.md §5.3）。
     */
    protected function deleteFeed(string $type, int $scopeId, int $siteId): void
    {
        foreach (['rss', 'atom'] as $format) {
            $this->delete(sprintf(self::KEY_FEED, $type, $scopeId, $siteId, $format));
        }
    }

    /**
     * 标签变更后清除
     *
     * @param int $siteId 站点 ID（<=0 表示清理全部站点的标签云缓存）
     */
    public function onTagChanged(int $siteId = 0): void
    {
        $this->deletePattern(self::TAG_CLOUD_PATTERN);
        $this->onTaglibChanged($siteId, 'tags');
    }

    /**
     * 友情链接变更后清除（B-1）
     *
     * 两条缓存都要清：
     *   1. 页脚直出（KEY_FRIEND_LINK）：group/limit 参与键，无法枚举，按站点前缀整体清；
     *   2. 模板标签 {cms:links}（KEY_TAG_LINKS）：标签参数指纹无法枚举，按站点 / 标签前缀整体清。
     */
    public function onFriendLinkChanged(int $siteId = 1): void
    {
        if ($siteId > 0) {
            $this->deletePattern(sprintf('wisecms:friendlink:%d:*', $siteId));
        }

        $this->onTaglibChanged($siteId, 'links');
    }

    /**
     * 模板标签库缓存失效（C-3，详见 docs/wisecms-template-taglib-design.md §8.2）
     *
     * 标签参数指纹（normalize 后 hash）无法枚举，故按「站点 / 标签」前缀整体清理：
     *   · tag=''   → wisecms:taglib:{siteId}:*           该站点全部标签
     *   · tag=xx   → wisecms:taglib:xx:{siteId}:*        该站点某标签
     *   · siteId<=0 → 省略站点段，即清理全部站点
     *
     * 说明：P1-B2 之后 deletePattern() 的 L3 失效**已真正生效**（写入侧按家族打 tag），
     * 无需再依赖 tag_cache_ttl 兜底。
     *
     * @param int    $siteId 站点 ID；<=0 表示全部站点
     * @param string $tag    标签名（arclist / channel / tags / links）；空串表示全部标签
     */
    public function onTaglibChanged(int $siteId = 1, string $tag = ''): void
    {
        $prefix = 'wisecms:taglib';

        $tag = (string) preg_replace('/[^a-z0-9_]/i', '', $tag);
        if ($tag !== '') {
            $prefix .= ':' . $tag;
        }

        if ($siteId > 0) {
            $prefix .= ':' . $siteId;
        }

        $this->deletePattern($prefix . ':*');
    }

    /**
     * 配置变更后清除
     */
    public function onConfigChanged(): void
    {
        $this->delete(self::KEY_CONFIG);
    }
}
