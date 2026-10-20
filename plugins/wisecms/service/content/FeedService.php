<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\model\Category;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\url\UrlService;

/**
 * CMS 内容订阅服务（RSS 2.0 / Atom 1.0）
 *
 * 设计要点（详见 docs/wisecms-feed-design.md）：
 *   · 零新表、零迁移：内容表字段齐备，直接查询；
 *   · 链接唯一来源：条目 <link>/<guid> 一律由 UrlService 生成（伪静态开关自动生效）；
 *   · XML 有效性三条硬性规则：文本节点 ENT_XML1 转义、CDATA 内拆分 `]]>`、
 *     剔除 XML 1.0 非法控制字符 —— 任一缺失都会让聚合器拒收整个 feed；
 *   · 输出链路返回字符串，由控制器包成 Response（不使用 echo + exit）；
 *   · 缓存包裹在 try/catch 中，缓存驱动异常时直接查库渲染（feed 不应因缓存故障 500）。
 *
 * renderRss()/renderAtom() 只依赖传入的 $ctx 与 $items（不查库、不调 UrlService），
 * 条目 URL 在 items() 阶段写入，便于脱离数据库做纯渲染测试。
 */
class FeedService
{
    /** 允许的订阅范围类型 */
    const TYPE_SITE     = 'site';
    const TYPE_CATEGORY = 'category';
    const TYPE_TAG      = 'tag';

    /** 允许的输出格式 */
    const FORMAT_RSS  = 'rss';
    const FORMAT_ATOM = 'atom';

    /** 摘要兜底长度（与 OutputService::generateDescription 同口径） */
    const EXCERPT_LENGTH = 200;

    /** URL 服务（请求内复用，保留栏目路径缓存） */
    protected ?UrlService $urls = null;

    protected function urls(): UrlService
    {
        return $this->urls ??= new UrlService();
    }

    // ══════════════════════════ 组装 ══════════════════════════

    /**
     * 组装完整 feed XML（含缓存）
     *
     * @param string $type    site|category|tag
     * @param int    $scopeId 栏目 ID / 标签 ID（site 忽略）
     * @param int    $siteId  站点 ID（<=0 时解析当前站点）
     * @param string $format  rss|atom（非法值回落 feed_format 配置）
     * @return string 合法 XML 文档
     */
    public function build(string $type = self::TYPE_SITE, int $scopeId = 0, int $siteId = 0, string $format = ''): string
    {
        $type    = $this->normalizeType($type);
        $scopeId = max(0, $scopeId);
        $siteId  = $siteId > 0 ? $siteId : (new SiteService())->resolveCurrentSiteId();
        $format  = $this->normalizeFormat($format);

        $key = self::cacheKey($type, $scopeId, $siteId, $format);
        $ttl = (int) config('wisecms.feed_ttl', 900);
        if ($ttl <= 0) {
            $ttl = 900;
        }

        try {
            return (new CacheService())->remember(
                $key,
                fn() => $this->render($type, $scopeId, $siteId, $format),
                $ttl
            );
        } catch (\Throwable $e) {
            // 缓存驱动异常时降级为实时渲染，feed 不应因缓存故障 500
            return $this->render($type, $scopeId, $siteId, $format);
        }
    }

    /**
     * 实际渲染（无缓存）：取条目 → 取上下文 → 分派渲染器
     */
    protected function render(string $type, int $scopeId, int $siteId, string $format): string
    {
        $items = $this->items($type, $scopeId, $siteId);
        $ctx   = $this->context($type, $scopeId, $siteId);

        if ($items !== []) {
            $last = 0;
            foreach ($items as $item) {
                $last = max(
                    $last,
                    cms_timestamp($item['update_time'] ?? null),
                    cms_timestamp($item['create_time'] ?? null)
                );
            }
            if ($last > 0) {
                $ctx['lastBuildDate'] = $last;
            }
        }

        $scope = $this->normalizeScope((string) config('wisecms.feed_scope', 'full'));

        return $format === self::FORMAT_ATOM
            ? $this->renderAtom($ctx, $items, $scope)
            : $this->renderRss($ctx, $items, $scope);
    }

    /**
     * 取订阅条目（仅已发布 status=2、未删除、当前站点，按发布时间倒序）
     *
     * @param string $type    site|category|tag
     * @param int    $scopeId 栏目 / 标签 ID
     * @param int    $siteId  站点 ID
     * @param int|null $limit 条目数（null 取 feed_limit）
     * @return array<int, array<string, mixed>> 每项含 url / catname / description（已兜底）
     */
    public function items(string $type, int $scopeId, int $siteId, ?int $limit = null): array
    {
        $limit = self::limit($limit);

        $query = Content::where('site_id', $siteId)
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->order('create_time', 'desc')
            ->limit($limit)
            ->field('id,catid,title,description,content,slug,alias,create_time,update_time,publish_time');

        if ($type === self::TYPE_CATEGORY && $scopeId > 0) {
            $query->where('catid', $scopeId);
        } elseif ($type === self::TYPE_TAG && $scopeId > 0) {
            $contentIds = TagRelation::where('tag_id', $scopeId)->column('content_id');
            if ($contentIds === []) {
                return [];
            }
            $query->whereIn('id', $contentIds);
        }

        $rows = $query->select()->toArray();
        if ($rows === []) {
            return [];
        }

        // 栏目名批量查询，避免逐条查询
        $catids   = array_values(array_unique(array_filter(array_map(
            static fn($row) => (int) ($row['catid'] ?? 0),
            $rows
        ))));
        $catNames = $catids === [] ? [] : Category::whereIn('id', $catids)->column('name', 'id');

        $urls = $this->urls();
        foreach ($rows as &$row) {
            $row['url']     = $urls->content($row);
            $row['catname'] = (string) ($catNames[$row['catid'] ?? 0] ?? '');
            if (empty($row['description'])) {
                $row['description'] = $this->excerpt($row);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * 订阅上下文（站点名 / 描述 / 首页 URL / self URL / lastBuildDate）
     *
     * @return array<string, mixed>
     */
    public function context(string $type, int $scopeId, int $siteId): array
    {
        $siteName = trim((string) config('wisecms.feed_title', ''));
        if ($siteName === '') {
            $siteName = (string) config('wisecms.site_name', 'WiseCMS');
        }

        $description = trim((string) config('wisecms.feed_description', ''));
        if ($description === '') {
            $description = $siteName . ' 内容订阅';
        }

        $urls = $this->urls();

        return [
            'site_name'     => $siteName,
            'description'   => $description,
            'copyright'     => (string) config('wisecms.feed_copyright', ''),
            'language'      => 'zh-CN',
            'generator'     => 'WiseCMS',
            'home_url'      => $urls->home(),
            'self_url'      => $this->feedUrl($urls, $type, $scopeId),
            'lastBuildDate' => time(),
        ];
    }

    /**
     * 本 feed 的规范 URL（self）
     */
    protected function feedUrl(UrlService $urls, string $type, int $scopeId): string
    {
        return match ($type) {
            self::TYPE_CATEGORY => $urls->absolute('cms/feed/category?catid=' . $scopeId),
            self::TYPE_TAG      => $urls->absolute('cms/feed/tag?id=' . $scopeId),
            default             => $urls->absolute('cms/feed/index'),
        };
    }

    // ══════════════════════════ 渲染 ══════════════════════════

    /**
     * RSS 2.0 渲染（只依赖 $ctx / $items）
     *
     * @param array $ctx   context() 结果（lastBuildDate 已按条目修正）
     * @param array $items items() 结果
     * @param string $scope full|summary
     */
    public function renderRss(array $ctx, array $items, string $scope = 'full'): string
    {
        $full = $scope !== 'summary';

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . self::escape((string) ($ctx['site_name'] ?? '')) . '</title>' . "\n";
        $xml .= '    <link>' . self::escape((string) ($ctx['home_url'] ?? '')) . '</link>' . "\n";
        $xml .= '    <description>' . self::escape((string) ($ctx['description'] ?? '')) . '</description>' . "\n";
        $xml .= '    <language>' . self::escape((string) ($ctx['language'] ?? 'zh-CN')) . '</language>' . "\n";
        $xml .= '    <lastBuildDate>' . self::rfc822((int) ($ctx['lastBuildDate'] ?? time())) . '</lastBuildDate>' . "\n";
        $xml .= '    <generator>' . self::escape((string) ($ctx['generator'] ?? 'WiseCMS')) . '</generator>' . "\n";
        if (!empty($ctx['copyright'])) {
            $xml .= '    <copyright>' . self::escape((string) $ctx['copyright']) . '</copyright>' . "\n";
        }
        $xml .= '    <atom:link href="' . self::escape((string) ($ctx['self_url'] ?? '')) . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($items as $item) {
            $xml .= $this->rssItem($item, $full);
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    /**
     * Atom 1.0 渲染（只依赖 $ctx / $items）
     *
     * @param array $ctx   context() 结果
     * @param array $items items() 结果
     * @param string $scope full|summary
     */
    public function renderAtom(array $ctx, array $items, string $scope = 'full'): string
    {
        $full = $scope !== 'summary';
        $self = (string) ($ctx['self_url'] ?? '');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <title>' . self::escape((string) ($ctx['site_name'] ?? '')) . '</title>' . "\n";
        $xml .= '  <id>' . self::escape($self) . '</id>' . "\n";
        $xml .= '  <updated>' . self::iso8601((int) ($ctx['lastBuildDate'] ?? time())) . '</updated>' . "\n";
        $xml .= '  <link rel="alternate" type="text/html" href="' . self::escape((string) ($ctx['home_url'] ?? '')) . '"/>' . "\n";
        $xml .= '  <link rel="self" type="application/atom+xml" href="' . self::escape($self) . '"/>' . "\n";
        if (!empty($ctx['copyright'])) {
            $xml .= '  <rights>' . self::escape((string) $ctx['copyright']) . '</rights>' . "\n";
        }
        if (!empty($ctx['description'])) {
            $xml .= '  <subtitle>' . self::escape((string) $ctx['description']) . '</subtitle>' . "\n";
        }

        foreach ($items as $item) {
            $xml .= $this->atomEntry($item, $full);
        }

        $xml .= '</feed>';

        return $xml;
    }

    /**
     * 单个 RSS <item>
     */
    protected function rssItem(array $item, bool $full): string
    {
        $url     = (string) ($item['url'] ?? '');
        $title   = (string) ($item['title'] ?? '');
        $summary = (string) ($item['description'] ?? '');
        $content = (string) ($item['content'] ?? '');
        $pubTime = self::pubTime($item);

        $xml  = '    <item>' . "\n";
        $xml .= '      <title>' . self::escape($title) . '</title>' . "\n";
        $xml .= '      <link>' . self::escape($url) . '</link>' . "\n";
        $xml .= '      <guid isPermaLink="true">' . self::escape($url) . '</guid>' . "\n";
        $xml .= '      <pubDate>' . self::rfc822($pubTime) . '</pubDate>' . "\n";

        $catname = (string) ($item['catname'] ?? '');
        if ($catname !== '') {
            $xml .= '      <category>' . self::escape($catname) . '</category>' . "\n";
        }

        $xml .= '      <description>' . self::cdata($summary) . '</description>' . "\n";
        if ($full && $content !== '') {
            $xml .= '      <content:encoded>' . self::cdata($content) . '</content:encoded>' . "\n";
        }
        $xml .= '    </item>' . "\n";

        return $xml;
    }

    /**
     * 单个 Atom <entry>
     */
    protected function atomEntry(array $item, bool $full): string
    {
        $url     = (string) ($item['url'] ?? '');
        $title   = (string) ($item['title'] ?? '');
        $summary = (string) ($item['description'] ?? '');
        $content = (string) ($item['content'] ?? '');
        $pubTime = self::pubTime($item);
        $updTime = cms_timestamp($item['update_time'] ?? null) ?: $pubTime;

        $xml  = '  <entry>' . "\n";
        $xml .= '    <title>' . self::escape($title) . '</title>' . "\n";
        $xml .= '    <id>' . self::escape($url) . '</id>' . "\n";
        $xml .= '    <link rel="alternate" type="text/html" href="' . self::escape($url) . '"/>' . "\n";
        $xml .= '    <published>' . self::iso8601($pubTime) . '</published>' . "\n";
        $xml .= '    <updated>' . self::iso8601($updTime) . '</updated>' . "\n";

        $catname = (string) ($item['catname'] ?? '');
        if ($catname !== '') {
            $xml .= '    <category term="' . self::escape($catname) . '"/>' . "\n";
        }

        $xml .= '    <summary type="html">' . self::cdata($summary) . '</summary>' . "\n";
        if ($full && $content !== '') {
            $xml .= '    <content type="html">' . self::cdata($content) . '</content>' . "\n";
        }
        $xml .= '  </entry>' . "\n";

        return $xml;
    }

    // ══════════════════════════ 纯函数 ══════════════════════════

    /**
     * 剔除 XML 1.0 非法控制字符（保留 \t \n \r）
     *
     * XML 非法字符位于 CDATA 内同样非法，因此正文/摘要也必须先过本函数。
     */
    public static function stripInvalidXmlChars(string $text): string
    {
        $clean = preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $text
        );

        if ($clean === null) {
            // 非合法 UTF-8：preg /u 失败，退回字节级清理（保留可见 ASCII 与多字节高位）
            $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', $text);
        }

        return $clean ?? '';
    }

    /**
     * 文本节点转义（ENT_XML1）
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars(self::stripInvalidXmlChars($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 包裹 CDATA：先剔除非法字符，再拆分内容中的 `]]>`
     */
    public static function cdata(string $text): string
    {
        $text = self::stripInvalidXmlChars($text);
        $text = str_replace(']]>', ']]]]><![CDATA[>', $text);

        return '<![CDATA[' . $text . ']]>';
    }

    /**
     * RFC 822 时间（RSS pubDate）
     */
    public static function rfc822(int $ts): string
    {
        return date('D, d M Y H:i:s O', $ts);
    }

    /**
     * ISO 8601 时间（Atom updated）
     */
    public static function iso8601(int $ts): string
    {
        return date('c', $ts);
    }

    /**
     * 格式对应的 Content-Type
     */
    public static function contentType(string $format): string
    {
        return $format === self::FORMAT_ATOM ? 'application/atom+xml' : 'application/rss+xml';
    }

    /**
     * 条目数收敛到 1..100（null 取 feed_limit）
     */
    public static function limit(?int $limit = null): int
    {
        if ($limit === null) {
            $limit = (int) config('wisecms.feed_limit', 20);
        }

        return max(1, min(100, $limit));
    }

    /**
     * 缓存键（与 CacheService::KEY_FEED 一致）
     */
    public static function cacheKey(string $type, int $scopeId, int $siteId, string $format): string
    {
        return sprintf(CacheService::KEY_FEED, $type, $scopeId, $siteId, $format);
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 条目发布时间：优先 publish_time（发布时写入），回退 create_time / update_time / 当前时间
     */
    protected static function pubTime(array $item): int
    {
        return cms_timestamp($item['publish_time'] ?? null)
            ?: cms_timestamp($item['create_time'] ?? null)
            ?: cms_timestamp($item['update_time'] ?? null)
            ?: time();
    }

    /**
     * 摘要兜底（与 OutputService::generateDescription 同口径）
     */
    protected function excerpt(array $item): string
    {
        $content = (string) ($item['content'] ?? '');
        if ($content !== '') {
            $text = strip_tags($content);
            $desc = mb_substr($text, 0, self::EXCERPT_LENGTH, 'UTF-8');
            if (mb_strlen($text, 'UTF-8') > self::EXCERPT_LENGTH) {
                $desc .= '...';
            }

            return $desc;
        }

        return (string) ($item['title'] ?? '');
    }

    /**
     * 范围类型白名单（非法回落 site）
     */
    protected function normalizeType(string $type): string
    {
        return in_array($type, [self::TYPE_SITE, self::TYPE_CATEGORY, self::TYPE_TAG], true)
            ? $type
            : self::TYPE_SITE;
    }

    /**
     * 格式白名单（非法回落 feed_format 配置，再兜底 rss）
     */
    protected function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (in_array($format, [self::FORMAT_RSS, self::FORMAT_ATOM], true)) {
            return $format;
        }

        $config = strtolower(trim((string) config('wisecms.feed_format', self::FORMAT_RSS)));

        return in_array($config, [self::FORMAT_RSS, self::FORMAT_ATOM], true) ? $config : self::FORMAT_RSS;
    }

    /**
     * 内容范围白名单（非法回落 full）
     */
    protected function normalizeScope(string $scope): string
    {
        return $scope === 'summary' ? 'summary' : 'full';
    }
}
