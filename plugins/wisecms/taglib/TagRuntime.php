<?php
declare(strict_types=1);

namespace plugin\wisecms\taglib;

use plugin\wisecms\model\Category;
use plugin\wisecms\service\content\FriendLinkService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\tag\TagQueryService;
use plugin\wisecms\service\url\UrlService;

/**
 * 模板标签库运行期薄门面（C-3）
 *
 * 编译产物直接调用本类的静态方法（见 taglib/Cms.php）。职责仅四件：
 *   1. 解析当前站点（编译产物运行在模板作用域，$this 不可靠，故不用控制器上下文）；
 *   2. 转发到 TagQueryService / FriendLinkService（本类不写 SQL、不做业务）；
 *   3. 页面级小片段（page / field / position）的渲染与转义；
 *   4. 全部方法 try/catch 兜底返回 []/''：标签参数写错、插件或依赖缺失（如 B-1 未落地）、
 *      表未迁移时只是该区块空白，绝不 500 整页。
 *
 * 详见 docs/wisecms-template-taglib-design.md §6.1。
 */
class TagRuntime
{
    /** 请求内站点 ID 缓存 */
    protected static ?int $siteId = null;

    /**
     * 当前站点 ID（静态缓存）
     */
    public static function siteId(): int
    {
        if (self::$siteId !== null) {
            return self::$siteId;
        }

        try {
            return self::$siteId = (int) (new SiteService())->resolveCurrentSiteId();
        } catch (\Throwable $e) {
            return self::$siteId = 1;
        }
    }

    /**
     * 标签库总开关（config wisecms.tag_enable）
     */
    public static function enabled(): bool
    {
        try {
            return (bool) config('wisecms.tag_enable', true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    // ══════════════════════════ 列表类标签 ══════════════════════════

    /**
     * 内容列表（{cms:arclist}）
     *
     * @param array    $attrs  编译期固定的标签属性
     * @param int|null $siteId 显式站点（默认运行期解析）
     * @return array<int, array<string, mixed>>
     */
    public static function arclist(array $attrs, ?int $siteId = null): array
    {
        return self::delegate('arclist', $attrs, $siteId);
    }

    /**
     * 栏目列表（{cms:channel}）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function channel(array $attrs, ?int $siteId = null): array
    {
        return self::delegate('channel', $attrs, $siteId);
    }

    /**
     * 标签云（{cms:tags}）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tags(array $attrs, ?int $siteId = null): array
    {
        return self::delegate('tags', $attrs, $siteId);
    }

    /**
     * 友情链接（{cms:links}，对接 B-1 FriendLinkService）
     *
     * 依赖未落地（类/方法不存在）时静默返回空，`{cms:links}` 不报错。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function links(array $attrs, ?int $siteId = null): array
    {
        if (!self::enabled()) {
            return [];
        }

        try {
            if (!class_exists(FriendLinkService::class)
                || !method_exists(FriendLinkService::class, 'forFront')) {
                return [];
            }

            $group = trim((string) ($attrs['group'] ?? ''));
            $limit = max(0, (int) ($attrs['limit'] ?? 0));

            $rows = (new FriendLinkService())->forFront($siteId ?? self::siteId(), $group, $limit);

            return self::prepareLinks(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            self::log('links', $e, $attrs);

            return [];
        }
    }

    /**
     * 自定义 SQL（{cms:sql}，默认禁用）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sql(array $attrs): array
    {
        if (!self::enabled()) {
            return [];
        }

        try {
            if (!(bool) config('wisecms.tag_sql_enable', false)) {
                return [];
            }

            return (new TagQueryService())->sql($attrs);
        } catch (\Throwable $e) {
            self::log('sql', $e, $attrs);

            return [];
        }
    }

    // ══════════════════════════ 页面级小片段 ══════════════════════════

    /**
     * 分页条（{cms:page /}）：渲染当前 $pager
     *
     * 结构与类名与 view/index/pager.html 完全一致，保证视觉/行为统一。
     *
     * @param array $pager Base::pager() 产出的分页数组
     * @param array $attrs ['class'=>'mt-4', 'prev'=>'上一页', 'next'=>'下一页']
     */
    public static function page(array $pager, array $attrs = []): string
    {
        if (!self::enabled()) {
            return '';
        }

        try {
            return self::renderPager($pager, $attrs);
        } catch (\Throwable $e) {
            self::log('page', $e);

            return '';
        }
    }

    /**
     * 详情页字段（{cms:field name="custom_price" default="-" /}）
     *
     * 支持点号路径（`author.name`）；缺失或非标量时返回默认值。
     * 输出默认 HTML 转义（与模板 `{$var}` 的默认转义一致，防存储型 XSS）；
     * 富文本正文如需直出，请改用 `{$content.content|raw}`。
     */
    public static function field(array $content, string $name, string $default = ''): string
    {
        if (!self::enabled()) {
            return '';
        }

        try {
            $value = self::readField($content, $name);
            if ($value === null || $value === '' || !is_scalar($value)) {
                return $default;
            }

            return self::e((string) $value);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * 面包屑（{cms:position /}）：首页 › 栏目链 › 当前标题
     *
     * @param array $content 当前内容（需含 catid / title，可选 site_id）
     * @param array $attrs   ['separator'=>' &gt; ', 'home'=>'首页']
     */
    public static function position(array $content, array $attrs = []): string
    {
        if (!self::enabled()) {
            return '';
        }

        try {
            $separator = (string) ($attrs['separator'] ?? ' &gt; ');
            $homeText  = trim((string) ($attrs['home'] ?? '首页'));

            $siteId = (int) ($content['site_id'] ?? self::siteId());
            $urls   = new UrlService();

            $parts = [];
            if ($homeText !== '') {
                $parts[] = '<a href="' . self::e($urls->home()) . '">' . self::e($homeText) . '</a>';
            }

            foreach (self::categoryChain((int) ($content['catid'] ?? 0), $siteId) as $category) {
                $parts[] = '<a href="' . self::e($urls->category($category)) . '">'
                    . self::e((string) ($category['name'] ?? '')) . '</a>';
            }

            $title = (string) ($content['title'] ?? '');
            if ($title !== '') {
                $parts[] = '<span>' . self::e($title) . '</span>';
            }

            return implode($separator, $parts);
        } catch (\Throwable $e) {
            self::log('position', $e);

            return '';
        }
    }

    // ══════════════════════════ 内部工具 ══════════════════════════

    /**
     * 列表类标签统一转发：开关判定 + siteId 解析 + 兜底
     */
    protected static function delegate(string $tag, array $attrs, ?int $siteId): array
    {
        if (!self::enabled()) {
            return [];
        }

        try {
            $service = new TagQueryService();

            return match ($tag) {
                'arclist' => $service->arclist($attrs, $siteId ?? self::siteId()),
                'channel' => $service->channel($attrs, $siteId ?? self::siteId()),
                'tags'    => $service->tags($attrs, $siteId ?? self::siteId()),
                default   => [],
            };
        } catch (\Throwable $e) {
            self::log($tag, $e, $attrs);

            return [];
        }
    }

    /**
     * 友链条目预处理：target 收敛 + rel 组装 + logo 绝对地址
     *
     * `target="_blank"` 强制补 `noopener noreferrer`，否则被链页面可通过
     * `window.opener` 反向控制本站（B-1 §10.4）。
     */
    protected static function prepareLinks(array $rows): array
    {
        foreach ($rows as &$row) {
            $target        = (string) ($row['target'] ?? '_blank');
            $row['target'] = in_array($target, ['_blank', '_self'], true) ? $target : '_blank';

            $rel = [];
            if (!empty($row['nofollow'])) {
                $rel[] = 'nofollow';
            }
            if ($row['target'] === '_blank') {
                $rel[] = 'noopener';
                $rel[] = 'noreferrer';
            }

            $row['rel']      = implode(' ', $rel);
            $row['is_image'] = !empty($row['is_image']);
            $row['logo_url'] = self::absoluteUrl((string) ($row['logo'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    /**
     * 分页片段渲染（与 view/index/pager.html 同结构）
     */
    protected static function renderPager(array $pager, array $attrs): string
    {
        if ($pager === [] || (int) ($pager['last_page'] ?? 1) <= 1) {
            return '';
        }

        $url      = (string) ($pager['url'] ?? '');
        $navClass = (string) ($attrs['class'] ?? 'mt-4');
        $prevText = (string) ($attrs['prev'] ?? '上一页');
        $nextText = (string) ($attrs['next'] ?? '下一页');

        $link = static function (int $page) use ($url): string {
            return $url === '' ? '#' : str_replace('__PAGE__', (string) $page, $url);
        };

        $prevClass = !empty($pager['has_prev']) ? '' : 'disabled';
        $nextClass = !empty($pager['has_next']) ? '' : 'disabled';

        return '<nav class="' . self::e($navClass) . '">'
            . '<ul class="pagination justify-content-center">'
            . '<li class="page-item ' . $prevClass . '">'
            . '<a class="page-link" href="' . self::e($link((int) ($pager['prev'] ?? 1))) . '">' . self::e($prevText) . '</a>'
            . '</li>'
            . '<li class="page-item disabled">'
            . '<span class="page-link">第 ' . (int) ($pager['page'] ?? 1) . ' / ' . (int) ($pager['last_page'] ?? 1) . ' 页</span>'
            . '</li>'
            . '<li class="page-item ' . $nextClass . '">'
            . '<a class="page-link" href="' . self::e($link((int) ($pager['next'] ?? 1))) . '">' . self::e($nextText) . '</a>'
            . '</li>'
            . '</ul></nav>';
    }

    /**
     * 读字段：先精确匹配，再按点号路径下钻（支持数组 / ArrayAccess）
     *
     * @return mixed 缺失返回 null
     */
    protected static function readField(array $content, string $name)
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (array_key_exists($name, $content)) {
            return $content[$name];
        }

        $value = $content;
        foreach (explode('.', $name) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            if ($value instanceof \ArrayAccess && $value->offsetExists($segment)) {
                $value = $value[$segment];
                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * 栏目链（祖先 → 当前，一次查询）
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function categoryChain(int $catid, int $siteId): array
    {
        if ($catid <= 0) {
            return [];
        }

        try {
            $rows = Category::where('site_id', $siteId)
                ->field('id,site_id,parent_id,type,name,alias')
                ->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        $chain   = [];
        $current = $catid;
        $guard   = 0;
        while ($current > 0 && isset($map[$current]) && $guard++ < 30) {
            array_unshift($chain, $map[$current]);
            $current = (int) $map[$current]['parent_id'];
        }

        return $chain;
    }

    /**
     * 相对路径 → 绝对 URL（已是 http(s) 或失败时原样返回）
     */
    protected static function absoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        try {
            return rtrim((string) request()->domain(), '/') . '/' . ltrim($path, '/');
        } catch (\Throwable $e) {
            return $path;
        }
    }

    /**
     * HTML 转义（标签内部所有用户数据输出的唯一出口）
     */
    protected static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 统一的失败日志（cms_log 不可用时静默）
     */
    protected static function log(string $tag, \Throwable $e, array $attrs = []): void
    {
        if (!function_exists('cms_log')) {
            return;
        }

        cms_log('warning', '[cms:' . $tag . '] 标签执行失败: ' . $e->getMessage(), ['attrs' => $attrs]);
    }
}
