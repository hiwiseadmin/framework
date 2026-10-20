<?php

declare(strict_types=1);

/**
 * WiseCMS 动态字段服务自检（零依赖：无需 composer / PHPUnit / 数据库）
 *
 *   php src/plugins/wisecms/tests/run.php
 *
 * 覆盖（纯逻辑，脱离框架与数据库）：
 *   - InputService::validateField  必填 / 长度 / 正则 校验
 *   - FieldRegistry::clear         缓存失效（fake CacheService）
 *   - FieldRegistry::normalize     setting 解码 + type_config 合并 + data_type 回退
 *   - SeoHtmlParser                HTML 节点/文本解析（DOM 优先，正则降级）
 *   - UrlRuleService               URL 规则模板渲染 / 编译为正则 / 占位符校验
 *
 * 数据库依赖项（createWithFields 事务回滚、validateUnique）见 FieldServicesTest / ContentServiceTest。
 */

// ---------- 自动加载 plugin\wisecms ----------
spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\wisecms\\';
    if (str_starts_with($class, $prefix)) {
        $rel  = substr($class, strlen($prefix));
        $path = __DIR__ . '/../' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// ---------- 插件公共函数（cms_log / cms_datetime / cms_timestamp ...） ----------
// 这些函数被服务层广泛调用（如时间字段归一、日志兜底），自检必须一并加载
require_once __DIR__ . '/../common.php';

// ---------- 框架函数替身 ----------
// 本文件刻意脱离框架运行（不引导 think\App），此处给出最小 config() 替身，
// 仅覆盖 FeedService / PreviewService 读取的键；未命中键返回传入默认值。
if (!function_exists('config')) {
    function config(string $key = '', $default = null)
    {
        $stub = [
            'wisecms.feed_limit'      => 20,
            'wisecms.feed_format'     => 'rss',
            'wisecms.feed_scope'      => 'full',
            'wisecms.feed_ttl'        => 900,
            'wisecms.preview_enable'  => true,
            'wisecms.preview_ttl'     => 1800,
            'wisecms.preview_secret'  => 'unit-test-preview-secret',
            'wisecms.preview_bind_user' => false,
        ];

        return $stub[$key] ?? $default;
    }
}

// ---------- 断言工具 ----------
$GLOBALS['_pass'] = 0;
$GLOBALS['_fail'] = 0;
$GLOBALS['_failures'] = [];

function check(string $name, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['_pass']++;
        echo "  [PASS] {$name}\n";
    } else {
        $GLOBALS['_fail']++;
        $GLOBALS['_failures'][] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  [FAIL] {$name}\n";
    }
}

function callProtected(object $obj, string $method, array $args = [])
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

/**
 * Fake CacheService：只记录 delete / deletePattern 调用，避免依赖 think\facade\Cache
 *
 * deletePattern 在真实实现里会调用 CacheFacade::tag('cms')->clear()，零依赖环境下不可用，
 * 故此处覆写为「仅记录 pattern」，用于断言标签库缓存失效接线（C-3 / P4）。
 */
class FakeCacheService extends \plugin\wisecms\service\site\CacheService
{
    /** @var string[] */
    public array $deleted = [];

    /** @var string[] */
    public array $patterns = [];

    public function delete(string $key): bool
    {
        $this->deleted[] = $key;
        return true;
    }

    public function deletePattern(string $pattern): void
    {
        $this->patterns[] = $pattern;
    }
}

echo "==============================================================\n";
echo " WiseCMS 动态字段服务自检\n";
echo ' PHP ' . PHP_VERSION . "   entry: " . basename(__FILE__) . "\n";
echo "==============================================================\n\n";

// ===================== InputService::validateField =====================

$input = (new ReflectionClass(\plugin\wisecms\service\model\InputService::class))->newInstanceWithoutConstructor();

try {
    callProtected($input, 'validateField', [['name' => '标题', 'is_required' => 1], '']);
    check('validateField 拦截必填空值', false);
} catch (\Exception $e) {
    check('validateField 拦截必填空值', str_contains($e->getMessage(), '不能为空'), $e->getMessage());
}

try {
    callProtected($input, 'validateField', [['name' => '标题', 'length' => 5], '123456']);
    check('validateField 拦截超长', false);
} catch (\Exception $e) {
    check('validateField 拦截超长', str_contains($e->getMessage(), '不能超过'), $e->getMessage());
}

try {
    callProtected($input, 'validateField', [['name' => '邮箱', 'pattern' => '/^[\w.]+@[\w.]+$/'], 'not-an-email']);
    check('validateField 拦截非法正则格式', false);
} catch (\Exception $e) {
    check('validateField 拦截非法正则格式', str_contains($e->getMessage(), '格式不正确'), $e->getMessage());
}

try {
    callProtected($input, 'validateField', [['name' => '编号', 'is_required' => 1, 'length' => 10, 'pattern' => '/^\d+$/'], '12345']);
    check('validateField 合法值通过', true);
} catch (\Exception $e) {
    check('validateField 合法值通过', false, $e->getMessage());
}

// ===================== FieldRegistry::clear =====================

$fake = new FakeCacheService();
$registry = new \plugin\wisecms\service\model\FieldRegistry($fake);
$registry->clear(42);
check('FieldRegistry::clear 删除正确缓存键', in_array('wisecms:model:fields:42', $fake->deleted, true));

// ===================== FieldRegistry::normalize =====================

$registry2 = (new ReflectionClass(\plugin\wisecms\service\model\FieldRegistry::class))->newInstanceWithoutConstructor();
$normalized = callProtected($registry2, 'normalize', [[
    ['field' => 'name', 'form_type' => 'text', 'setting' => '{"max":10}', 'data_type' => ''],
]]);
$f = $normalized[0] ?? [];

check('normalize 解码 setting 为数组', is_array($f['setting'] ?? null) && ($f['setting']['max'] ?? null) === 10);
check('normalize 合并 type_config', ($f['type_config']['field_type'] ?? '') === 'varchar');
check('normalize 回退 data_type', ($f['data_type'] ?? '') === 'varchar');

// ===================== SeoHtmlParser（HTML 节点解析） =====================

$seoHtml = '<H2 class="t">A</H2><!-- <h2>x</h2> --><h3>B</h3><h20>C</h20>';
$hc = \plugin\wisecms\service\seo\SeoHtmlParser::headingCounts($seoHtml);
check('SeoHtmlParser 标题层级（忽略注释/非法标签名）', $hc[2] === 1 && $hc[3] === 1);

$seoLinks = '<a  href = "/a">1</a><A HREF="/b">2</A><a href="https://ext.com/c">3</a><a href="#top">4</a>';
$ls = \plugin\wisecms\service\seo\SeoHtmlParser::linkStats($seoLinks, 'ext.com');
check('SeoHtmlParser 链接统计（属性空白/大小写）', $ls['total'] === 4 && $ls['internal'] === 3);

$ls2 = \plugin\wisecms\service\seo\SeoHtmlParser::linkStats($seoLinks, 'mysite.com');
check('SeoHtmlParser 内链按域名判定', $ls2['internal'] === 2);

$ia = \plugin\wisecms\service\seo\SeoHtmlParser::imageAltStats('<img alt="a > b" src="x"><img src="y"><img alt="" src="z">');
check('SeoHtmlParser 图片 alt（属性值含 ">"）', $ia['total'] === 3 && $ia['missing'] === 2);

$txt = \plugin\wisecms\service\seo\SeoHtmlParser::textContent('<style>.x{}</style><script>var a=1;</script><p>你好 世界</p>');
check('SeoHtmlParser 纯文本（剔除 script/style）', $txt === '你好 世界');

$first = \plugin\wisecms\service\seo\SeoHtmlParser::firstImageSrc('<p>x</p><img data-x="a>b" src="/up/1.jpg"><img src="/up/2.jpg">');
check('SeoHtmlParser 提取首图 src', $first === '/up/1.jpg');

// ===================== UrlRuleService（URL 规则：渲染 / 编译） =====================

$urlRules = new \plugin\wisecms\service\url\UrlRuleService();

check(
    'UrlRule 路径规范化（去查询串/折叠斜杠/去首尾斜杠）',
    \plugin\wisecms\service\url\UrlRuleService::normalizePath('//news///a.html?x=1') === 'news/a.html'
);

check(
    'UrlRule 渲染内容模板（{catdir}/{slug}.html）',
    $urlRules->render('/{catdir}/{slug}.html', ['catdir' => 'news/tech', 'slug' => 'hello']) === 'news/tech/hello.html'
);

check(
    'UrlRule 渲染（catdir 为空时折叠重复斜杠）',
    $urlRules->render('/{catdir}/{slug}.html', ['catdir' => '', 'slug' => 'hello']) === 'hello.html'
);

check(
    'UrlRule 分页渲染（{page}）',
    $urlRules->render('/{catdir}/list_{page}.html', ['catdir' => 'news'], 3) === 'news/list_3.html'
);

check(
    'UrlRule 第 1 页去掉页码段',
    $urlRules->render('/{catdir}/list_{page}.html', ['catdir' => 'news'], 1) === 'news/list.html'
);

$ruleRegex = $urlRules->toRegex('/{catdir}/{slug}.html');
$ruleMatched = $ruleRegex !== null && preg_match($ruleRegex, 'news/tech/hello.html', $rm) === 1;
check(
    'UrlRule 模板编译为正则并提取 token',
    $ruleMatched && ($rm['catdir'] ?? '') === 'news/tech' && ($rm['slug'] ?? '') === 'hello'
);

check(
    'UrlRule 识别未知占位符',
    $urlRules->unknownTokens('/{catdir}/{foo}.html') === ['foo']
);

// ===================== ContentVersionService（版本快照，A-1） =====================

$versionSvc = new \plugin\wisecms\service\content\ContentVersionService();

check(
    'Version::planPrune 保留最新 N 条',
    \plugin\wisecms\service\content\ContentVersionService::planPrune([1, 2, 3, 4, 5], 2) === [1, 2, 3]
);
check(
    'Version::planPrune 未超限时不删除',
    \plugin\wisecms\service\content\ContentVersionService::planPrune([1, 2], 5) === []
);
check(
    'Version::planPrune keep=0 表示不限制',
    \plugin\wisecms\service\content\ContentVersionService::planPrune([1, 2, 3], 0) === []
);
check(
    'Version::planPrune 容忍重复/字符串版本号',
    \plugin\wisecms\service\content\ContentVersionService::planPrune(['3', 3, '1', 2], 2) === [1]
);

$snap = $versionSvc->normalize(json_encode([
    '_schema' => 1,
    'content' => ['title' => 'T', 'slug' => 's'],
    'custom'  => ['price' => '1'],
    'tags'    => ['a', 'b'],
], JSON_UNESCAPED_UNICODE));
check(
    'Version::normalize 解析三段结构',
    ($snap['content']['title'] ?? '') === 'T'
        && ($snap['custom']['price'] ?? '') === '1'
        && ($snap['tags'] ?? []) === ['a', 'b']
);
check('Version::normalize 拒绝非法 JSON', $versionSvc->normalize('{not-json') === null);
check('Version::normalize 拒绝缺少 content 段', $versionSvc->normalize('{"custom":{}}') === null);

check(
    'Version::FROZEN 覆盖 status/site_id/model_id/view_count',
    in_array('status', \plugin\wisecms\service\content\ContentVersionService::FROZEN, true)
        && in_array('site_id', \plugin\wisecms\service\content\ContentVersionService::FROZEN, true)
        && in_array('model_id', \plugin\wisecms\service\content\ContentVersionService::FROZEN, true)
        && in_array('view_count', \plugin\wisecms\service\content\ContentVersionService::FROZEN, true)
);

// ===================== TextDiffService（版本对比，A-1） =====================

$diffSvc = new \plugin\wisecms\service\content\TextDiffService();

$lines = $diffSvc->normalizeToLines("<p>a</p><div>b</div>\n\n<li>c</li><br><hr>");
check('Diff::normalizeToLines 按块级标签切行并去空行', $lines === ['<p>a</p>', '<div>b</div>', '<li>c</li>', '<br>', '<hr>']);

$equalDiff = $diffSvc->diff(['a', 'b', 'c'], ['a', 'b', 'c']);
check('Diff::diff 完全相同时无增删', $diffSvc->stats($equalDiff['ops'])['added'] === 0 && $diffSvc->stats($equalDiff['ops'])['removed'] === 0);
check('Diff::diff 完全相同时不降级', $equalDiff['degraded'] === false);

$insertDiff = $diffSvc->diff(['a', 'c'], ['a', 'b', 'c']);
$insertStats = $diffSvc->stats($insertDiff['ops']);
check('Diff::diff 纯插入计数正确', $insertStats['added'] === 1 && $insertStats['removed'] === 0);
check('Diff::diff 纯插入保留公共前后缀', $insertStats['unchanged'] === 2);

$replaceDiff = $diffSvc->diff(['a', 'b', 'c'], ['a', 'x', 'c']);
$replaceStats = $diffSvc->stats($replaceDiff['ops']);
check('Diff::diff 替换为 1 增 1 删', $replaceStats['added'] === 1 && $replaceStats['removed'] === 1);

$deleteDiff = $diffSvc->diff(['a', 'b', 'c'], ['a', 'c']);
check('Diff::diff 纯删除计数正确', $diffSvc->stats($deleteDiff['ops'])['removed'] === 1);

// 降级护栏：600 × 600 = 36 万单元格 > MAX_CELLS(25 万)
$bigOld = [];
$bigNew = [];
for ($i = 0; $i < 600; $i++) {
    $bigOld[] = 'old-' . $i;
    $bigNew[] = 'new-' . $i;
}
$bigDiff = $diffSvc->diff($bigOld, $bigNew);
check('Diff::diff 超限降级为整块替换', $bigDiff['degraded'] === true && count($bigDiff['ops']) === 1200);

$html = $diffSvc->toHtml([['op' => 'ins', 'text' => '<script>alert(1)</script>']]);
check('Diff::toHtml 转义脚本（不输出裸标签）', strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false);

$html2 = $diffSvc->toHtml([['op' => 'del', 'text' => 'x']]);
check('Diff::toHtml 删除行为红色样式', strpos($html2, 'diff-del') !== false);

$longEqual = [];
for ($i = 0; $i < 50; $i++) {
    $longEqual[] = ['op' => 'equal', 'text' => 'line-' . $i];
}
$folded = $diffSvc->toHtml(array_merge(
    [['op' => 'del', 'text' => 'x']],
    $longEqual,
    [['op' => 'ins', 'text' => 'y']]
), 3);
check('Diff::toHtml 长 equal 段折叠', strpos($folded, '省略') !== false);

$fieldDiff = $diffSvc->diffFields(
    ['title' => 'A', 'content' => '<p>1</p>', 'catid' => 1],
    ['title' => 'B', 'content' => '<p>2</p>', 'catid' => 1]
);
check('Diff::diffFields 跳过正文且识别标题变更', count($fieldDiff) === 1 && $fieldDiff[0]['field'] === 'title' && $fieldDiff[0]['label'] === '标题');

// ===================== FeedService（A-3 RSS/Atom） =====================

$feedSvc = new \plugin\wisecms\service\content\FeedService();

// 1) stripInvalidXmlChars：XML 1.0 非法控制字符剔除（保留 \t \n \r）
check(
    'Feed::stripInvalidXmlChars 剔除控制字符',
    \plugin\wisecms\service\content\FeedService::stripInvalidXmlChars("a\x00b\x0Bc") === 'abc'
);
check(
    'Feed::stripInvalidXmlChars 保留 \\t \\n \\r',
    \plugin\wisecms\service\content\FeedService::stripInvalidXmlChars("a\tb\nc\rd") === "a\tb\nc\rd"
);

// 2) cdata：先剔除非法字符，再拆分内容中的 ]]>
$feedCdata = \plugin\wisecms\service\content\FeedService::cdata('x]]>y');
check(
    'Feed::cdata 拆分 ]]> 且首尾为 CDATA 标记',
    $feedCdata === '<![CDATA[x]]]]><![CDATA[>y]]>'
);
check(
    'Feed::cdata 剔除控制字符',
    \plugin\wisecms\service\content\FeedService::cdata("a\x00b") === '<![CDATA[ab]]>'
);

// 3) escape：ENT_XML1 文本节点转义
$feedEsc = \plugin\wisecms\service\content\FeedService::escape('A & B < C "D" \'E\'');
check(
    'Feed::escape 转义 & < " \'',
    str_contains($feedEsc, '&amp;') && str_contains($feedEsc, '&lt;')
        && str_contains($feedEsc, '&quot;') && str_contains($feedEsc, '&apos;')
);

// 4) rfc822（RSS pubDate）
$feedTs = mktime(9, 0, 0, 9, 18, 2026);
check(
    'Feed::rfc822 精确匹配 D, d M Y H:i:s O',
    \plugin\wisecms\service\content\FeedService::rfc822($feedTs) === date('D, d M Y H:i:s O', $feedTs)
);

// 5) iso8601（Atom updated）
check(
    'Feed::iso8601 匹配 c 格式',
    \plugin\wisecms\service\content\FeedService::iso8601($feedTs) === date('c', $feedTs)
);

// 6) contentType
check('Feed::contentType rss', \plugin\wisecms\service\content\FeedService::contentType('rss') === 'application/rss+xml');
check('Feed::contentType atom', \plugin\wisecms\service\content\FeedService::contentType('atom') === 'application/atom+xml');

// 7) limit：收敛到 1..100，null 取 feed_limit
check('Feed::limit 下限收敛（0 → 1）', \plugin\wisecms\service\content\FeedService::limit(0) === 1);
check('Feed::limit 上限收敛（999 → 100）', \plugin\wisecms\service\content\FeedService::limit(999) === 100);
check('Feed::limit null 取 feed_limit', \plugin\wisecms\service\content\FeedService::limit(null) === 20);

// 8) cacheKey：与 CacheService::KEY_FEED 常量一致
check(
    'Feed::cacheKey 与 KEY_FEED 常量一致',
    \plugin\wisecms\service\content\FeedService::cacheKey('category', 3, 1, 'atom')
        === sprintf(\plugin\wisecms\service\site\CacheService::KEY_FEED, 'category', 3, 1, 'atom')
);

// 9) 渲染全链路（无 DB：注入假 ctx / items）
$feedCtx = [
    'site_name'     => '站点名',
    'description'   => '订阅描述',
    'copyright'     => '© 2026',
    'language'      => 'zh-CN',
    'generator'     => 'WiseCMS',
    'home_url'      => 'https://example.com/',
    'self_url'      => 'https://example.com/cms/feed/index',
    'lastBuildDate' => $feedTs,
];
$feedItems = [[
    'title'       => '标题 <script>alert(1)</script>',
    'url'         => 'https://example.com/news/hello.html',
    'description' => '摘要 ]]> 结束',
    'content'     => '<p>全文</p>',
    'catname'     => '新闻',
    'create_time' => date('Y-m-d H:i:s', $feedTs),
    'update_time' => date('Y-m-d H:i:s', $feedTs),
]];

$feedRss = $feedSvc->renderRss($feedCtx, $feedItems, 'full');
check(
    'Feed::renderRss 以 <?xml 开头且结构完整',
    str_starts_with($feedRss, '<?xml') && str_contains($feedRss, '<channel>') && str_contains($feedRss, '</rss>')
);
check(
    'Feed::renderRss 条目标题转义（无裸 <script>）',
    !str_contains($feedRss, '标题 <script>') && str_contains($feedRss, '&lt;script&gt;')
);
check('Feed::renderRss 含 content:encoded 全文', str_contains($feedRss, '<content:encoded>'));
check('Feed::renderRss 摘要 CDATA 拆分', str_contains($feedRss, ']]]]><![CDATA[>'));
check('Feed::renderRss 含栏目名 category', str_contains($feedRss, '<category>新闻</category>'));

$feedRssEmpty = $feedSvc->renderRss($feedCtx, [], 'full');
check(
    'Feed::renderRss 0 条目仍为合法结构',
    str_contains($feedRssEmpty, '<channel>') && str_contains($feedRssEmpty, '</rss>') && !str_contains($feedRssEmpty, '<item>')
);

$feedAtom = $feedSvc->renderAtom($feedCtx, $feedItems, 'summary');
check(
    'Feed::renderAtom 结构完整',
    str_starts_with($feedAtom, '<?xml')
        && str_contains($feedAtom, 'xmlns="http://www.w3.org/2005/Atom"')
        && str_contains($feedAtom, '<entry>') && str_contains($feedAtom, '</feed>')
);
check('Feed::renderAtom summary 模式仅摘要（无 content）', !str_contains($feedAtom, '<content type="html">'));

// 产物必须是真正可解析的 XML（DOM 可用时校验）
if (class_exists(\DOMDocument::class)) {
    libxml_use_internal_errors(true);
    $feedRssOk  = (new \DOMDocument())->loadXML($feedRss);
    $feedAtomOk = (new \DOMDocument())->loadXML($feedAtom);
    libxml_clear_errors();
    libxml_use_internal_errors(false);
    check('Feed RSS/Atom 产物通过 DOM 解析', $feedRssOk && $feedAtomOk);
} else {
    check('Feed RSS/Atom DOM 解析（跳过：无 ext-dom）', true);
}

// ===================== RecommendService（A-2 相关推荐） =====================

$recNow = 1700000000;
// 时间列为 DATETIME：夹具统一用 date() 生成字符串（cms_timestamp 不再接受 int 入参）
$recAt  = static fn(int $ts): string => date('Y-m-d H:i:s', $ts);
$recCtx = ['content_id' => 1, 'catid' => 5, 'model_id' => 2, 'site_id' => 1, 'tag_ids' => [1, 2], 'create_time' => $recAt($recNow - 86400)];

$recHit2 = \plugin\wisecms\service\content\RecommendService::score(
    ['id' => 2, 'catid' => 9, 'tag_hits' => 2, 'create_time' => $recAt($recNow), 'view_count' => 0],
    $recCtx,
    $recNow
);
$recHit1 = \plugin\wisecms\service\content\RecommendService::score(
    ['id' => 3, 'catid' => 9, 'tag_hits' => 1, 'create_time' => $recAt($recNow), 'view_count' => 0],
    $recCtx,
    $recNow
);
check('Recommend::score 共同标签数递增', $recHit2 > $recHit1);

$recSameCat = \plugin\wisecms\service\content\RecommendService::score(
    ['id' => 4, 'catid' => 5, 'tag_hits' => 1, 'create_time' => $recAt($recNow), 'view_count' => 0],
    $recCtx,
    $recNow
);
check('Recommend::score 同栏目加成', $recSameCat > $recHit1);

$recOld = \plugin\wisecms\service\content\RecommendService::score(
    ['id' => 5, 'catid' => 9, 'tag_hits' => 1, 'create_time' => $recAt($recNow - 30 * 86400), 'view_count' => 0],
    $recCtx,
    $recNow
);
check('Recommend::score 时间衰减（30 天前低于今天）', $recOld < $recHit1);

$recHot = \plugin\wisecms\service\content\RecommendService::score(
    ['id' => 6, 'catid' => 9, 'tag_hits' => 1, 'create_time' => $recAt($recNow), 'view_count' => 1000],
    $recCtx,
    $recNow
);
check('Recommend::score 热度（1000 > 0）', $recHot > $recHit1);

$recCandidates = [
    ['id' => 1, 'catid' => 5, 'tag_hits' => 9, 'create_time' => $recAt($recNow), 'view_count' => 0],          // 自身，应排除
    ['id' => 3, 'catid' => 9, 'tag_hits' => 1, 'create_time' => $recAt($recNow), 'view_count' => 0],
    ['id' => 3, 'catid' => 9, 'tag_hits' => 1, 'create_time' => $recAt($recNow), 'view_count' => 0],          // 重复，应去重
    ['id' => 2, 'catid' => 5, 'tag_hits' => 2, 'create_time' => $recAt($recNow), 'view_count' => 0],
    ['id' => 4, 'catid' => 9, 'tag_hits' => 1, 'create_time' => $recAt($recNow - 10 * 86400), 'view_count' => 0],
];
$recRanked = \plugin\wisecms\service\content\RecommendService::rank($recCandidates, $recCtx, 2, $recNow);
check('Recommend::rank 排序/截断/排除自身且去重', count($recRanked) === 2 && array_column($recRanked, 'id') === [2, 3]);

$recTie = \plugin\wisecms\service\content\RecommendService::rank([
    ['id' => 10, 'catid' => 0, 'tag_hits' => 0, 'create_time' => $recAt($recNow - 100), 'view_count' => 0],
    ['id' => 11, 'catid' => 0, 'tag_hits' => 0, 'create_time' => $recAt($recNow), 'view_count' => 0],
], ['content_id' => 1, 'catid' => 0], 2, $recNow);
check('Recommend::rank 同分按 create_time 降序（稳定）', array_column($recTie, 'id') === [11, 10]);

check('Recommend::normalizeLimit 下限（0 → 1）', \plugin\wisecms\service\content\RecommendService::normalizeLimit(0) === 1);
check('Recommend::normalizeLimit 上限（999 → 20）', \plugin\wisecms\service\content\RecommendService::normalizeLimit(999) === 20);
check("Recommend::normalizeLimit 字符串（'8' → 8）", \plugin\wisecms\service\content\RecommendService::normalizeLimit('8') === 8);

check(
    'Recommend::relatedKey/hotKey 与常量一致（键中不含 limit）',
    \plugin\wisecms\service\content\RecommendService::relatedKey(7)
        === sprintf(\plugin\wisecms\service\site\CacheService::KEY_RECOMMEND_RELATED, 7)
    && \plugin\wisecms\service\content\RecommendService::hotKey(3)
        === sprintf(\plugin\wisecms\service\site\CacheService::KEY_RECOMMEND_HOT, 3)
);

// ===================== TagService::slugify（A-2 / P5 标签别名） =====================

check(
    'Tag::slugify 小写 + 非字母数字转 -',
    \plugin\wisecms\service\content\TagService::slugify('Hello World!') === 'hello-world'
);
check(
    'Tag::slugify 去首尾 - 并折叠连续分隔符',
    \plugin\wisecms\service\content\TagService::slugify('--SEO 优化--') === 'seo'
);
check(
    'Tag::slugify 纯中文返回空（回退 tag-{id}）',
    \plugin\wisecms\service\content\TagService::slugify('中文标签') === ''
);
check(
    'Tag::slugify 中段空白转单个 -',
    \plugin\wisecms\service\content\TagService::slugify('  a  b  ') === 'a-b'
);

// ===================== FriendLinkService（B-1 友情链接纯函数） =====================

$friendLink = \plugin\wisecms\service\content\FriendLinkService::class;

check('FriendLink::normalizeUrl 合法 https 原样返回', $friendLink::normalizeUrl('https://a.com') === 'https://a.com');
check('FriendLink::normalizeUrl 协议相对地址补全 https', $friendLink::normalizeUrl('//a.com/x') === 'https://a.com/x');
check('FriendLink::normalizeUrl 拒绝 javascript:', $friendLink::normalizeUrl('javascript:alert(1)') === null);
check('FriendLink::normalizeUrl 拒绝 data:', $friendLink::normalizeUrl('data:text/html,x') === null);
check('FriendLink::normalizeUrl 去除首尾空格后合法', $friendLink::normalizeUrl('  http://a.com  ') === 'http://a.com');
check(
    'FriendLink::cacheKey 与 KEY_FRIEND_LINK 常量一致（空分组用 _ 占位）',
    $friendLink::cacheKey(1, '', 0)
        === sprintf(\plugin\wisecms\service\site\CacheService::KEY_FRIEND_LINK, 1, '_', 0)
);
check('FriendLink::normalizeUrl 空串返回 null', $friendLink::normalizeUrl('   ') === null);
check(
    'FriendLink::cacheKey 分组 / limit 参与键',
    $friendLink::cacheKey(2, '合作媒体', 10) !== $friendLink::cacheKey(2, '', 10)
    && $friendLink::cacheKey(2, 'g', 10) !== $friendLink::cacheKey(2, 'g', 0)
);

// ===================== C-3 模板标签库（编译期标签 + 取数 + 失效） =====================

// think-template 基类替身：Cms 继承 think\template\TagLib，而本文件刻意不引导 vendor。
// vendor 存在时 class_exists 命中真实类，替身不会被引入；编译产物用例只用字面量属性，
// 不会触碰 $this->tpl（autoBuildVar），故可零依赖断言。
if (!class_exists(\think\template\TagLib::class)) {
    require_once __DIR__ . '/stub/TagLib.php';
}

$tagQuery = \plugin\wisecms\service\tag\TagQueryService::class;

// ---- §11.1 零依赖用例（8 项） ----

$tagN = $tagQuery::normalizeArclist(['limit' => '0']);
check('TagLib::normalizeArclist limit=0 收敛为 1', $tagN['limit'] === 1);

$tagN = $tagQuery::normalizeArclist(['limit' => '999']);
check('TagLib::normalizeArclist limit=999 收敛为上限 50', $tagN['limit'] === 50);

$tagN = $tagQuery::normalizeArclist(['orderby' => 'view_count desc;drop']);
check('TagLib::normalizeArclist orderby 注入回落默认 id', $tagN['orderby'] === 'id', (string) $tagN['orderby']);

$tagN = $tagQuery::normalizeArclist(['flag' => 'xxx']);
check('TagLib::normalizeArclist 非法 flag 置空', $tagN['flag'] === '', (string) $tagN['flag']);

$tagN = $tagQuery::normalizeArclist(['typeid' => '1,2,abc']);
check('TagLib::normalizeArclist typeid 丢弃非法项', $tagN['typeid'] === [1, 2]);

$tagKeyA = $tagQuery::cacheKey('arclist', ['limit' => 8, 'flag' => 'top'], 1);
$tagKeyB = $tagQuery::cacheKey('arclist', ['flag' => 'top', 'limit' => 8], 1);
check(
    'TagLib::cacheKey 同参数稳定且与键序无关',
    $tagKeyA === $tagKeyB
    && $tagKeyA === sprintf(
        \plugin\wisecms\service\site\CacheService::KEY_TAG_ARCLIST,
        1,
        substr(md5((string) json_encode(['flag' => 'top', 'limit' => 8], JSON_UNESCAPED_UNICODE)), 0, 12)
    )
);

$taglibObj   = (new ReflectionClass(\plugin\wisecms\taglib\Cms::class))->newInstanceWithoutConstructor();
$tagBreak    = '<!--###break###--!>';
$tagInner    = '<a class="cms-title" href="{$vo.url}">{$vo.title}</a>';
$tagCompiled = $taglibObj->tagArclist(['typeid' => '3', 'limit' => '8', 'id' => 'vo'], $tagBreak);

check('Cms::tagArclist 产物是字符串', is_string($tagCompiled));
check(
    'Cms::tagArclist 含 TagRuntime::arclist 与 foreach',
    str_contains($tagCompiled, '\\plugin\\wisecms\\taglib\\TagRuntime::arclist')
    && str_contains($tagCompiled, 'foreach')
);
check('Cms::tagArclist 保留 inner 占位（引擎据此原地回填）', substr_count($tagCompiled, $tagBreak) === 1);
check('Cms::tagArclist 不含原始 {cms:arclist}', !str_contains($tagCompiled, '{cms:arclist}'));
check(
    'Cms::tagArclist 回填后保留 inner 原文',
    str_contains(str_replace($tagBreak, $tagInner, $tagCompiled), '{$vo.url}')
);

check('TagRuntime::field 缺失返回默认值', \plugin\wisecms\taglib\TagRuntime::field(['a' => ['b' => 1]], 'a.c', '-') === '-');
check('TagRuntime::field 点号路径命中', \plugin\wisecms\taglib\TagRuntime::field(['a' => ['b' => 'x']], 'a.b', '-') === 'x');
check(
    'TagRuntime::field 默认转义（防存储型 XSS）',
    \plugin\wisecms\taglib\TagRuntime::field(['t' => '<i>'], 't') === '&lt;i&gt;'
);

check(
    'TagRuntime::page 单页返回空',
    \plugin\wisecms\taglib\TagRuntime::page(['last_page' => 1, 'page' => 1]) === ''
);
$tagPager = \plugin\wisecms\taglib\TagRuntime::page([
    'last_page' => 3,
    'page'      => 2,
    'prev'      => 1,
    'next'      => 3,
    'has_prev'  => true,
    'has_next'  => true,
    'url'       => '/cms?page=__PAGE__',
]);
check(
    'TagRuntime::page 渲染页码并替换 __PAGE__',
    str_contains($tagPager, '第 2 / 3 页')
    && str_contains($tagPager, 'page=1')
    && str_contains($tagPager, 'page=3')
    && !str_contains($tagPager, '__PAGE__')
);
check(
    'TagRuntime::page 转义按钮文案',
    str_contains(
        \plugin\wisecms\taglib\TagRuntime::page(['last_page' => 2, 'page' => 1, 'url' => '/x?p=__PAGE__'], ['prev' => '<b>']),
        '&lt;b&gt;'
    )
);

// ---- P0-4 正文 HTML 净化（HtmlSanitizer，主表 content / 单页 content 共用） ----

$sanitizer = \plugin\wisecms\service\content\HtmlSanitizer::class;

check(
    'P0-4 HtmlSanitizer 剥离 script / iframe / form',
    !str_contains($sanitizer::clean('<p>ok</p><script>alert(1)</script>'), 'script')
    && !str_contains($sanitizer::clean('<iframe src="https://evil.test"></iframe>'), 'iframe')
    && !str_contains($sanitizer::clean('<form action="/x"></form>'), 'form')
);
check(
    'P0-4 HtmlSanitizer 剥离事件属性（含无引号写法和大小写变体）',
    !str_contains($sanitizer::clean('<img src=x onerror=alert(1)>'), 'onerror')
    && !str_contains($sanitizer::clean('<img src="x" ONERROR=alert(1)>'), 'ONERROR')
    && !str_contains($sanitizer::clean('<p onclick="alert(1)">x</p>'), 'onclick')
);
check(
    'P0-4 HtmlSanitizer 阻断 javascript: / data: 协议',
    !str_contains($sanitizer::clean('<a href="javascript:alert(1)">x</a>'), 'javascript')
    && !str_contains($sanitizer::clean('<a href="jav&#x61;script:alert(1)">x</a>'), 'script:')
    && !str_contains($sanitizer::clean('<img src="data:text/html;base64,PHN2Zz4=">'), 'data:')
);
check(
    'P0-4 HtmlSanitizer 保留正常富文本',
    $sanitizer::clean('<h2>标题</h2><p>正文<strong>加粗</strong></p><img src="https://cdn.test/a.png" alt="a" width="100">')
        === '<h2>标题</h2><p>正文<strong>加粗</strong></p><img src="https://cdn.test/a.png" alt="a" width="100">'
);
check(
    'P0-4 HtmlSanitizer::isSafeUrl 协议白名单',
    $sanitizer::isSafeUrl('/uploads/a.png')
    && $sanitizer::isSafeUrl('https://cdn.test/a.png')
    && !$sanitizer::isSafeUrl('javascript:alert(1)')
    && !$sanitizer::isSafeUrl('JaVaScRiPt:alert(1)')
    && !$sanitizer::isSafeUrl('vbscript:msgbox(1)')
);

// ---- §10.2 最高风险面：{cms:sql} 片段闸门 ----

check('TagLib::safeSqlFragment 正常片段通过', $tagQuery::safeSqlFragment('status=2') === 'status=2');
check('TagLib::safeSqlFragment 分号拒绝', $tagQuery::safeSqlFragment('id; drop table x', true) === null);
check('TagLib::safeSqlFragment union 拒绝', $tagQuery::safeSqlFragment('title union select 1', true) === null);
check('TagLib::safeSqlFragment 注释符拒绝', $tagQuery::safeSqlFragment('1=1 -- x', false) === null);
check(
    'TagLib::safeSqlFragment 不误伤 update_time / delete_time',
    $tagQuery::safeSqlFragment('update_time > 0', false) === 'update_time > 0'
    && $tagQuery::safeSqlFragment('delete_time IS NULL', false) === 'delete_time IS NULL'
);

// ---- P0-3 加固：子查询盲注载荷必须被拒 ----

check(
    'P0-3 where 子查询（select/from）拒绝',
    $tagQuery::safeSqlFragment("1=1 and (select substr(secret,1,1) from wisecms_webhook limit 1)='a'", false) === null
    && $tagQuery::safeSqlFragment('(select 1)', false) === null
    && $tagQuery::safeSqlFragment('1 from dual', false) === null
);
check(
    'P0-3 跨库/元数据访问拒绝',
    $tagQuery::safeSqlFragment('1=1 and (select 1 from information_schema.tables)', false) === null
    && $tagQuery::safeSqlFragment('mysql.user', false) === null
    && $tagQuery::safeSqlFragment('x join y', false) === null
);
check(
    'P0-3 表名白名单仅放行前台内容表',
    in_array('wisecms_content', $tagQuery::SQL_TABLE_ALLOWLIST, true)
    && !in_array('wisecms_webhook', $tagQuery::SQL_TABLE_ALLOWLIST, true)
    && !in_array('wisecms_site', $tagQuery::SQL_TABLE_ALLOWLIST, true)
    && !in_array('wisecms_search_log', $tagQuery::SQL_TABLE_ALLOWLIST, true)
);

// ---- channel / tags 属性收敛 ----

$tagN = $tagQuery::normalizeChannel(['limit' => '0']);
check('TagLib::normalizeChannel limit=0 表示不限', $tagN['limit'] === 0 && $tagN['parentid'] === 0);
$tagN = $tagQuery::normalizeTags([]);
check(
    'TagLib::normalizeTags 默认 limit=20 / count / desc',
    $tagN === ['limit' => 20, 'orderby' => 'count', 'order' => 'desc']
);

// ---- P4 缓存失效接线（spy deletePattern） ----

$tagCacheSpy = new FakeCacheService();
$tagCacheSpy->onContentSaved(9, 3, 7);
check('Cache::onContentSaved 清理该站 arclist 标签缓存', in_array('wisecms:taglib:arclist:7:*', $tagCacheSpy->patterns, true));

$tagCacheSpy = new FakeCacheService();
$tagCacheSpy->onCategoryChanged(7);
check('Cache::onCategoryChanged 清理该站全部标签缓存', in_array('wisecms:taglib:7:*', $tagCacheSpy->patterns, true));

$tagCacheSpy = new FakeCacheService();
$tagCacheSpy->onTagChanged(7);
check('Cache::onTagChanged 清理该站 tags 标签缓存', in_array('wisecms:taglib:tags:7:*', $tagCacheSpy->patterns, true));

$tagCacheSpy = new FakeCacheService();
$tagCacheSpy->onFriendLinkChanged(7);
check('Cache::onFriendLinkChanged 清理该站 links 标签缓存', in_array('wisecms:taglib:links:7:*', $tagCacheSpy->patterns, true));

$tagCacheSpy = new FakeCacheService();
$tagCacheSpy->onTaglibChanged();
check('Cache::onTaglibChanged 默认清理站点 1 全部标签', in_array('wisecms:taglib:1:*', $tagCacheSpy->patterns, true));

$tagCacheSpy = new FakeCacheService();
$tagCacheSpy->onTaglibChanged(0, 'arclist');
check('Cache::onTaglibChanged siteId=0 清理全站 arclist', in_array('wisecms:taglib:arclist:*', $tagCacheSpy->patterns, true));

// ===================== PreviewService（B-3 草稿预览令牌） =====================

// 测试替身：可注入密钥 / 覆盖单条配置，脱离 config() 真实来源做定向断言
class FakePreviewService extends \plugin\wisecms\service\content\PreviewService
{
    public ?string $fakeSecret = null;

    /** @var array<string, mixed> */
    public array $fakeConfig = [];

    protected function secret(): string
    {
        return $this->fakeSecret ?? parent::secret();
    }

    protected function getConfig(string $key, $default = null)
    {
        return array_key_exists($key, $this->fakeConfig) ? $this->fakeConfig[$key] : parent::getConfig($key, $default);
    }
}

$previewSecret = 'unit-test-preview-secret';

// 1) b64url 往返（含 + / = 字节）
$previewRaw = "\x00\xfb\xff??>>+/=";
$previewEncoded = \plugin\wisecms\service\content\PreviewService::b64urlEncode($previewRaw);
check(
    'Preview::b64urlEncode 输出 URL 安全字符（无 + / =）',
    !str_contains($previewEncoded, '+') && !str_contains($previewEncoded, '/') && !str_contains($previewEncoded, '=')
);
check(
    'Preview::b64urlEncode/Decode 往返一致',
    \plugin\wisecms\service\content\PreviewService::b64urlDecode($previewEncoded) === $previewRaw
);

// 2) encode → decode 完全还原
$previewPayload = ['id' => 123, 'site' => 1, 'uid' => 0, 'exp' => time() + 600];
$previewToken   = \plugin\wisecms\service\content\PreviewService::encode($previewPayload, $previewSecret);
check(
    'Preview::encode → decode 还原 payload',
    \plugin\wisecms\service\content\PreviewService::decode($previewToken, $previewSecret) === $previewPayload
);

// 3) 篡改签名 → null
$previewBadSig = substr($previewToken, 0, -1) . (substr($previewToken, -1) === 'a' ? 'b' : 'a');
check(
    'Preview::decode 篡改签名返回 null',
    \plugin\wisecms\service\content\PreviewService::decode($previewBadSig, $previewSecret) === null
);
check(
    'Preview::decode 错误密钥返回 null',
    \plugin\wisecms\service\content\PreviewService::decode($previewToken, 'another-secret') === null
);

// 4) 篡改 payload（签名自洽，但 verify 因 id 不匹配拒绝）
$previewForged = \plugin\wisecms\service\content\PreviewService::encode(
    ['id' => 999, 'site' => 1, 'uid' => 0, 'exp' => time() + 600],
    $previewSecret
);
$previewSvc = new FakePreviewService();
$previewSvc->fakeSecret = $previewSecret;
check(
    'Preview::decode 篡改 payload 非 null（签名自洽）',
    \plugin\wisecms\service\content\PreviewService::decode($previewForged, $previewSecret) !== null
);
check('Preview::verify 因 id 不匹配返回 null', $previewSvc->verify($previewForged, 123, 1) === null);

// 5) ttl 收敛
check('Preview::ttl(0) 收敛为 60', \plugin\wisecms\service\content\PreviewService::ttl(0) === 60);
check('Preview::ttl(999999) 收敛为 86400', \plugin\wisecms\service\content\PreviewService::ttl(999999) === 86400);
check('Preview::ttl(null) 取 preview_ttl', \plugin\wisecms\service\content\PreviewService::ttl(null) === 1800);

// 6) generate 返回结构 + 令牌可用
$previewRes = $previewSvc->generate(123, 1, 600, 0);
check(
    'Preview::generate 返回 token/url/expires_at',
    isset($previewRes['token'], $previewRes['url'], $previewRes['expires_at'])
        && is_string($previewRes['token']) && is_string($previewRes['url'])
);
check('Preview::generate url 含 cms/preview/index', str_contains($previewRes['url'], 'cms/preview/index'));
check('Preview::generate 令牌可通过 verify', $previewSvc->verify($previewRes['token'], 123, 1) !== null);

// 6b) P1-A7：令牌携带一次性标识 jti（供 preview_once 消费 / 日志追踪）
$previewPayload = \plugin\wisecms\service\content\PreviewService::decode(
    $previewRes['token'],
    'unit-test-preview-secret'
);
check(
    'P1-A7 预览令牌含 jti（16 位十六进制）',
    is_array($previewPayload)
        && isset($previewPayload['jti'])
        && preg_match('/^[0-9a-f]{16}$/', (string) $previewPayload['jti']) === 1
);

// 7) verify 过期
$previewExpired = \plugin\wisecms\service\content\PreviewService::encode(
    ['id' => 123, 'site' => 1, 'uid' => 0, 'exp' => time() - 1],
    $previewSecret
);
check('Preview::verify 过期令牌返回 null', $previewSvc->verify($previewExpired, 123, 1) === null);

// 8) verify 站点不符
$previewOtherSite = \plugin\wisecms\service\content\PreviewService::encode(
    ['id' => 123, 'site' => 2, 'uid' => 0, 'exp' => time() + 600],
    $previewSecret
);
check('Preview::verify 站点不符返回 null', $previewSvc->verify($previewOtherSite, 123, 1) === null);

// 9) enable：空密钥 fail-closed / 总开关关闭
$previewEmpty = new FakePreviewService();
$previewEmpty->fakeSecret = '';
check('Preview::enable 空密钥 fail-closed（false）', $previewEmpty->enable() === false);

$previewOff = new FakePreviewService();
$previewOff->fakeSecret = $previewSecret;
$previewOff->fakeConfig = ['preview_enable' => false];
check('Preview::enable 总开关关闭返回 false', $previewOff->enable() === false);
check('Preview::enable 密钥可用且开关开启返回 true', $previewSvc->enable() === true);

// ===================== P1-A 安全收敛 =====================

// P1-1 批量赋值：黑名单 unset
// （原用 wisecms_ad 的计数列做断言，该列为死字段已于 P1-D 移除，改用重定向表的 site_id 防护）
$redirectData = \plugin\wisecms\service\model\MassAssignmentGuard::filter([
    'source_url' => '/old', 'target_url' => '/new', 'hit_count' => 999, 'site_id' => 7, 'id' => 9, 'create_time' => 'x',
], 'wisecms_redirect');
check('P1-1 Guard 剔除 id/命中数/site_id', $redirectData === ['source_url' => '/old', 'target_url' => '/new']);

$unknownTable = \plugin\wisecms\service\model\MassAssignmentGuard::filter(
    ['id' => 1, 'create_time' => 2, 'update_time' => 3, 'delete_time' => 4, 'foo' => 5],
    'wisecms_not_registered'
);
check('P1-1 Guard 未登记表仅剔除框架托管列', $unknownTable === ['foo' => 5]);

// P1-A2 跳转目标协议白名单
check(
    'P1-A2 协议白名单放行 http/https/相对路径',
    \plugin\wisecms\service\security\UrlGuard::isSafeScheme('/a/b')
        && \plugin\wisecms\service\security\UrlGuard::isSafeScheme('#top')
        && \plugin\wisecms\service\security\UrlGuard::isSafeScheme('https://a.test/x')
);
check(
    'P1-A2 协议白名单拒绝 javascript/data（含实体编码与大小写混淆）',
    !\plugin\wisecms\service\security\UrlGuard::isSafeScheme('javascript:alert(1)')
        && !\plugin\wisecms\service\security\UrlGuard::isSafeScheme('JaVaScRiPt:alert(1)')
        && !\plugin\wisecms\service\security\UrlGuard::isSafeScheme('jav&#x61;script:alert(1)')
        && !\plugin\wisecms\service\security\UrlGuard::isSafeScheme('data:text/html;base64,PHN2Zz4=')
);

// P1-A3 SSRF：内网 / 保留 / 环回 / IPv6 / 非 http 协议
check(
    'P1-A3 SSRF 拒绝环回/链路本地元数据/IPv6 环回/非 http 协议',
    !\plugin\wisecms\service\security\UrlGuard::isSafeOutbound('http://127.0.0.1/x')
        && !\plugin\wisecms\service\security\UrlGuard::isSafeOutbound('http://169.254.169.254/latest/meta-data')
        && !\plugin\wisecms\service\security\UrlGuard::isSafeOutbound('http://[::1]/x')
        && !\plugin\wisecms\service\security\UrlGuard::isSafeOutbound('file:///etc/passwd')
);
check(
    'P1-A3 curlResolveEntry 构造 host:port:ip（IPv6 加方括号）',
    \plugin\wisecms\service\security\UrlGuard::curlResolveEntry('http://h.test:8080/x', '1.2.3.4') === 'h.test:8080:1.2.3.4'
        && \plugin\wisecms\service\security\UrlGuard::curlResolveEntry('https://h.test/x', '::1') === 'h.test:443:[::1]'
);

// P1-A6 JSON-LD 编码加固
$structuredSvc = new \plugin\wisecms\service\seo\StructuredDataService();
$ldJson = callProtected($structuredSvc, 'encode', [['headline' => '</script><img src=x>', 'amp' => 'a&b']]);
check(
    'P1-A6 JSON-LD 转义 < > & 阻断 </script>',
    !str_contains($ldJson, '</script>')
        && str_contains($ldJson, '\u003C')
        && str_contains($ldJson, '\u0026')
);

// P1-A8 标签属性变量形态白名单
$cmsTag = (new ReflectionClass(\plugin\wisecms\taglib\Cms::class))->newInstanceWithoutConstructor();
check(
    'P1-A8 buildValue 拒绝过滤器写法与危险变量',
    callProtected($cmsTag, 'buildValue', ['$x|system']) === "''"
        && callProtected($cmsTag, 'buildValue', ['$a.b()']) === "''"
        && callProtected($cmsTag, 'buildValue', ['$_GET.id']) === "''"
        && callProtected($cmsTag, 'buildValue', ['$Request.id']) === "''"
);
check(
    'P1-A8 VAR_PATTERN 放行普通变量与点号路径',
    preg_match(\plugin\wisecms\taglib\Cms::VAR_PATTERN, '$ids') === 1
        && preg_match(\plugin\wisecms\taglib\Cms::VAR_PATTERN, '$cat.ids') === 1
        && preg_match(\plugin\wisecms\taglib\Cms::VAR_PATTERN, '$cms.list.0.title') === 1
);

// ===================== P1-B 正确性与一致性 =====================

// P1-B2 缓存家族标签（写入与失效使用同一规则）
$cacheSvc = new \plugin\wisecms\service\site\CacheService();
check(
    'P1-B2 tagOf 取键前两段作为家族',
    callProtected($cacheSvc, 'tagOf', ['wisecms:taglib:arclist:1:ab']) === 'wisecms:taglib'
        && callProtected($cacheSvc, 'tagOf', ['wisecms:friendlink:1:g:20']) === 'wisecms:friendlink'
);
$cacheFamilies = \plugin\wisecms\service\site\CacheService::families();
check(
    'P1-B2 families() 由 KEY_* 常量自动推导（含 taglib/feed/content）',
    in_array('wisecms:taglib', $cacheFamilies, true)
        && in_array('wisecms:feed', $cacheFamilies, true)
        && in_array('wisecms:content', $cacheFamilies, true)
);

// P1-B1 状态机唯一规则点
$contentSvc   = new \plugin\wisecms\service\content\ContentService();
$throwsWisecms = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (\plugin\wisecms\exception\WisecmsException $e) {
        return true;
    } catch (\Throwable $e) {
        return false;
    }
};
check(
    'P1-B1 先审后发：草稿/归档 → 已发布 被拒',
    $throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [0, 2, false]))
        && $throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [3, 2, false]))
);
check(
    'P1-B1 先审后发：待审/已发布 → 已发布 放行',
    !$throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [1, 2, false]))
        && !$throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [2, 2, false]))
);
check(
    'P1-B1 工作流驱动可跳过「须先审核」校验',
    !$throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [0, 2, true]))
);
check(
    'P1-B1 非发布目标不受限制 / 非法状态值被拒',
    !$throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [2, 0, false]))
        && !$throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [1, 3, false]))
        && $throwsWisecms(fn() => callProtected($contentSvc, 'assertStatusTransition', [0, 99, false]))
);

// ===================== 时间字段统一（迁移 20261005000002） =====================

// 归一工具：cms_datetime / cms_timestamp
check(
    '时间归一 cms_datetime 支持 datetime-local / 纯日期 / 空值',
    cms_datetime('2026-10-05T12:00') === '2026-10-05 12:00:00'
        && cms_datetime('2026-10-05 12:00:00') === '2026-10-05 12:00:00'
        && cms_datetime('2026-10-05') === '2026-10-05 00:00:00'
        && cms_datetime('') === null
        && cms_datetime(null) === null
        && cms_datetime(0) === null
        && cms_datetime('0') === null
        && cms_datetime('非法时间') === null
);
check(
    '时间归一 cms_timestamp 支持 DATETIME / 无效值',
    cms_timestamp('2026-10-05 12:00:00') === strtotime('2026-10-05 12:00:00')
        && cms_timestamp(null) === 0
        && cms_timestamp('') === 0
        && cms_timestamp('非法时间') === 0
);

// TagQueryService：orderby 白名单只含 DATETIME 时间列
check(
    '时间列白名单：orderby 不含历史字段名',
    $tagQuery::ORDERBY_MAP['create_time'] === 'create_time'
        && $tagQuery::ORDERBY_MAP['update_time'] === 'update_time'
        && $tagQuery::ORDERBY_MAP['publish_time'] === 'publish_time'
        && !isset($tagQuery::ORDERBY_MAP['inputtime'], $tagQuery::ORDERBY_MAP['updatetime'])
);
check(
    '列表字段只含 DATETIME 时间列（无 UNIX_TIMESTAMP 别名）',
    str_contains($tagQuery::LIST_FIELDS, 'create_time')
        && str_contains($tagQuery::LIST_FIELDS, 'update_time')
        && !str_contains($tagQuery::LIST_FIELDS, 'UNIX_TIMESTAMP')
);

// RecommendService：DATETIME 时间衰减
$recDtCandidate = ['id' => 9, 'catid' => 5, 'tag_hits' => 1, 'create_time' => date('Y-m-d H:i:s', $recNow), 'view_count' => 0];
$recDtStale     = ['id' => 9, 'catid' => 5, 'tag_hits' => 1, 'create_time' => date('Y-m-d H:i:s', $recNow - 30 * 86400), 'view_count' => 0];
check(
    'Recommend::score 按 DATETIME create_time 做时间衰减',
    \plugin\wisecms\service\content\RecommendService::score($recDtCandidate, $recCtx, $recNow)
        > \plugin\wisecms\service\content\RecommendService::score($recDtStale, $recCtx, $recNow)
);

// OutputService：时间派生键
$outputSvc = new \plugin\wisecms\service\content\OutputService();
$outputRow = callProtected($outputSvc, 'processCommonFields', [[
    'id'          => 1,
    'url'         => '/news/hello.html',
    'description' => '摘要',
    'create_time' => '2026-10-05 12:00:00',
    'update_time' => '2026-10-06 08:30:00',
]]);
check(
    'OutputService 输出 create_time / update_time 派生键',
    ($outputRow['create_time_text'] ?? '') === '2026-10-05 12:00:00'
        && ($outputRow['create_time_date'] ?? '') === '2026-10-05'
        && ($outputRow['update_time_text'] ?? '') === '2026-10-06 08:30:00'
        && ($outputRow['update_time_date'] ?? '') === '2026-10-06'
        && !array_key_exists('inputtime_date', $outputRow)
);

// ===================== 建表迁移时间列约定 =====================
//
// 约定（插件不保留历史兼容，建表迁移即唯一事实源）：
//   · 每张表必备 create_time / update_time，且为可空 DATETIME；
//   · delete_time 仅出现在使用 SoftDelete 的表；
//   · 领域时间列保留原名，但类型同样必须是 DATETIME（禁止 int 时间戳列）。

$migrationDir     = __DIR__ . '/../database/migrations';
$migTimeColumns   = [
    'create_time', 'update_time', 'delete_time', 'inputtime', 'updatetime', 'publish_time',
    'check_time', 'audit_time', 'start_time', 'end_time', 'finish_time',
    'scheduled_time', 'actual_time', 'last_run_time',
];
$migSoftDelete    = ['wisecms_content', 'wisecms_category', 'wisecms_page', 'wisecms_friend_link'];
$migMissingColumn = [];
$migNonDatetime   = [];
$migStrayDelete   = [];
$migTableCount    = 0;

foreach (glob($migrationDir . '/*.php') ?: [] as $migrationFile) {
    $migrationSource = (string) file_get_contents($migrationFile);
    $migrationName   = basename($migrationFile);

    if (!preg_match("/\\\$this->table\('(wisecms_[a-z_]+)'/", $migrationSource, $migMatch)) {
        continue; // 非建表迁移
    }

    $migTable = $migMatch[1];
    $migTableCount++;

    foreach (['create_time', 'update_time'] as $migRequired) {
        if (!str_contains($migrationSource, "Column::datetime('{$migRequired}')")) {
            $migMissingColumn[] = "{$migrationName}({$migTable})";
        }
    }

    foreach ($migTimeColumns as $migColumn) {
        if (preg_match('/Column::(integer|bigInteger|timestamp|date)\(\'' . $migColumn . '\'\)/', $migrationSource)) {
            $migNonDatetime[] = "{$migrationName}:{$migColumn}";
        }
    }

    if (str_contains($migrationSource, "Column::datetime('delete_time')")
        && !in_array($migTable, $migSoftDelete, true)) {
        $migStrayDelete[] = $migrationName;
    }
}

check(
    '建表迁移：全部表具备 create_time / update_time（可空 DATETIME）',
    $migMissingColumn === [] && $migTableCount === 27,
    implode(', ', $migMissingColumn) . " (表数 {$migTableCount})"
);
check(
    '建表迁移：时间列一律 DATETIME（无 int 时间戳列）',
    $migNonDatetime === [],
    implode(', ', $migNonDatetime)
);
check(
    '建表迁移：delete_time 仅出现在软删除表',
    $migStrayDelete === [],
    implode(', ', $migStrayDelete)
);

// ===================== P1-C 数据层约束 =====================
//
// 防回退守卫：唯一键与热点索引直接写死在建表迁移里，改动必须同步这里的期望。

$constraintExpectations = [
    // 标签词表按站点隔离（P2）：唯一键由全局 → 站点内，热点索引由 count → (site_id, count)
    '20251102000006_create_wisecms_tag_table.php'          => ["['site_id']", "['site_id', 'name'], ['unique' => true]", "['site_id', 'alias'], ['unique' => true]", "['site_id', 'count']"],
    '20251102000007_create_wisecms_tag_relation_table.php' => ["['site_id', 'tag_id']"],
    '20251102000017_create_wisecms_comment_table.php'      => ["['ip']", "['site_id', 'status']", "['content_id', 'status', 'parent_id']", "['parent_id', 'status']"],
    '20251102000016_create_wisecms_schedule_table.php'     => ["['status', 'scheduled_time']"],
    '20251102000013_create_wisecms_internal_link_table.php' => ["['site_id', 'status']"],
    '20251102000002_create_wisecms_model_field_table.php'  => ["['model_id', 'sort_order']"],
    '20251102000003_create_wisecms_category_table.php'     => ["['site_id', 'alias'], ['unique' => true]"],
    '20251102000008_create_wisecms_page_table.php'         => ["['site_id', 'alias'], ['unique' => true]"],
    '20251102000005_create_wisecms_content_version_table.php' => ["['content_id', 'version'], ['unique' => true"],
    '20251102000025_create_wisecms_redirect_table.php'     => ["Column::integer('site_id')", "['site_id', 'source_url'], ['unique' => true]"],
];

$missingConstraints = [];
foreach ($constraintExpectations as $constraintFile => $snippets) {
    $constraintSource = (string) file_get_contents($migrationDir . '/' . $constraintFile);
    foreach ($snippets as $snippet) {
        if (!str_contains($constraintSource, $snippet)) {
            $missingConstraints[] = "{$constraintFile}:{$snippet}";
        }
    }
}

check(
    'P1-C 数据层约束：唯一键与热点索引齐备',
    $missingConstraints === [],
    implode('; ', $missingConstraints)
);
check(
    'P1-C 关联表登记齐备（级联清理前置条件）',
    count(\plugin\wisecms\service\content\ContentRelationService::CONTENT_SCOPED_TABLES) === 7
);
check(
    'P1-C 唯一键冲突识别（1062 / 23000 / duplicate entry）',
    cms_is_duplicate_key(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'x\' for key'))
        && cms_is_duplicate_key(new \Exception('SQLSTATE[42S02]', 0, new \RuntimeException('duplicate entry')))
        && !cms_is_duplicate_key(new \RuntimeException('SQLSTATE[42S02]: Base table or view not found'))
);

$relationSvc = new \plugin\wisecms\service\content\ContentRelationService();
check(
    'P1-C 关联清理入参归一（去重/取整/剔除非正数）',
    callProtected($relationSvc, 'normalizeIds', [[0, '3', 3, -1, 'abc', 5]]) === [3, 5]
        && callProtected($relationSvc, 'normalizeIds', ['7']) === [7]
);

// ===================== P1-D 站点隔离与死代码清理 =====================

// 死表 / 死字段
check(
    'P1-D 死表已清理（content_meta / webhook_log）',
    !file_exists($migrationDir . '/20251102000014_create_wisecms_content_meta_table.php')
        && !file_exists($migrationDir . '/20251102000027_create_wisecms_webhook_log_table.php')
        && !class_exists('plugin\\wisecms\\model\\ContentMeta')
        && !class_exists('plugin\\wisecms\\model\\WebhookLog')
);

$adMigration = (string) file_get_contents($migrationDir . '/20251102000019_create_wisecms_ad_table.php');
check(
    'P1-D 死字段已清理（wisecms_ad 无 view_count / click_count）',
    !str_contains($adMigration, 'view_count') && !str_contains($adMigration, 'click_count')
);

// API 站点隔离：全部前台 API 继承带站点解析的基类
check(
    'P1-D API 基类提供站点解析与内容归属校验',
    str_contains((string) file_get_contents(__DIR__ . '/../controller/api/Base.php'), 'function siteId')
        && str_contains((string) file_get_contents(__DIR__ . '/../controller/api/Base.php'), 'function findSiteContent')
);

$apiControllers = ['Content', 'Search', 'Tag', 'Comment'];
$notIsolated = [];
foreach ($apiControllers as $apiName) {
    $apiSource = (string) file_get_contents(__DIR__ . '/../controller/api/' . $apiName . '.php');
    if (!str_contains($apiSource, "class {$apiName} extends Base")) {
        $notIsolated[] = $apiName;
    }
}
check('P1-D 前台 API 全部继承站点隔离基类', $notIsolated === [], implode(', ', $notIsolated));

// ---------- P1-26 后台评论站点隔离 ----------
//
// P1-D 解决的是「前台 API 反向越权读别站内容」，P1-26 解决的是「后台评论列表/写操作跨站」——
// 此前评论表没有 site_id，后台只能跨站显示，审核/删除也只看 id 存在。

$commentMigrationSource = (string) file_get_contents($migrationDir . '/20251102000017_create_wisecms_comment_table.php');
check(
    'P1-26 评论表含 site_id 与 site_id+status 索引',
    str_contains($commentMigrationSource, "Column::integer('site_id')")
        && str_contains($commentMigrationSource, "['site_id', 'status']")
);

$commentServiceSource = (string) file_get_contents(__DIR__ . '/../service/content/CommentService.php');
check(
    'P1-26 评论 site_id 由所属内容推导（不信任调用方传值）',
    str_contains($commentServiceSource, "\$data['site_id'] = (int) \$content->site_id")
        && str_contains($commentServiceSource, 'function getAdminList(int $siteId')
);
check(
    'P1-26 评论写入校验「内容存在」且「父评论同属一篇内容」',
    str_contains($commentServiceSource, 'if (!$content) throw new WisecmsException')
        && str_contains($commentServiceSource, "(int) \$parent->content_id !== (int) \$data['content_id']")
);

$commentControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Comment.php');
check(
    'P1-26 后台评论列表按站点过滤、写操作校验归属',
    str_contains($commentControllerSource, 'resolveAdminSiteId')
        && str_contains($commentControllerSource, 'getAdminList($this->siteId()')
        && substr_count($commentControllerSource, 'assertSiteOwned(') >= 4
);

$commentSiteCommand = (string) file_get_contents(__DIR__ . '/../command/CommentSiteCommand.php');
$consoleSource      = (string) file_get_contents(__DIR__ . '/../config/console.php');
check(
    'P1-26 存量升级命令已交付并注册（wisecms:comment:site）',
    str_contains($commentSiteCommand, "setName('wisecms:comment:site')")
        && str_contains($commentSiteCommand, "site_id")           // 补列
        && str_contains($commentSiteCommand, '--dry-run')          // 可预览
        && str_contains($commentSiteCommand, 'wisecms:comment')    // 同 P2-4 一类：建表迁移管新装、命令管存量
        && str_contains($consoleSource, 'CommentSiteCommand::class')
);

// 站点隔离：无 API 再用未加站点条件的 getList / 直查
$apiContentSource = (string) file_get_contents(__DIR__ . '/../controller/api/Content.php');
check(
    'P1-D api/Content 列表传 site_id、详情走 findSiteContent',
    str_contains($apiContentSource, "'site_id' => \$this->siteId()")
        && !str_contains($apiContentSource, 'Content::where(\'id\', (int)$params[\'id\'])')
);

check(
    'P1-D 标签 API 按站点统计与过滤（siteCloud / siteContentList）',
    method_exists(\plugin\wisecms\service\content\TagService::class, 'siteCloud')
        && method_exists(\plugin\wisecms\service\content\TagService::class, 'siteContentList')
);

check(
    'P1-D 搜索日志按站点隔离（search_log.site_id + 热搜词按站点统计）',
    str_contains((string) file_get_contents($migrationDir . '/20251102000026_create_wisecms_search_log_table.php'), "Column::integer('site_id')")
        && (new \ReflectionMethod(\plugin\wisecms\service\content\SearchService::class, 'getHotKeywords'))->getNumberOfParameters() === 2
);

// 单页别名解析站点校验
check(
    'P1-D UrlService：TYPE_PAGE 分支校验 site_id',
    str_contains(
        (string) file_get_contents(__DIR__ . '/../service/url/UrlService.php'),
        "\$entity->site_id !== \$siteId"
    )
);

// ===================== P2 架构治理 =====================

// ---------- P2-1 后台站点选择器 ----------

$siteServiceSource = (string) file_get_contents(__DIR__ . '/../service/site/SiteService.php');
$adminResolverBody = (int) strpos($siteServiceSource, 'function resolveAdminSiteId');
check(
    'P2 站点解析双口径：前台域名优先、后台会话优先',
    $adminResolverBody > 0
        && // 后台解析不得依赖域名，否则「后台域名恰为某站点域名」时选择器失效
        !str_contains(substr($siteServiceSource, $adminResolverBody, 600), 'host()')
        && method_exists(\plugin\wisecms\service\site\SiteService::class, 'getSwitchOptions')
        && method_exists(\plugin\wisecms\service\site\SiteService::class, 'switchTo')
        && // 前台口径保持域名优先（未被误改）
        str_contains(substr($siteServiceSource, (int) strpos($siteServiceSource, 'function resolveCurrentSiteId'), 600), 'host()')
);

// 控制器继承框架 BaseController，独立自检进程无法加载类 → 按源码断言
$siteControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Site.php');
check(
    'P2 站点选择器控制器动作（options / switch）',
    str_contains($siteControllerSource, 'function options()')
        && str_contains($siteControllerSource, 'function switch()')
);

$pluginRouteSource = (string) file_get_contents(__DIR__ . '/../route/plugin.php');
check(
    'P2 站点选择器路由已显式登记（GET options / POST switch）',
    str_contains($pluginRouteSource, "Route::get('site/options'")
        && str_contains($pluginRouteSource, "Route::post('site/switch'")
);

$manifestData        = json_decode((string) file_get_contents(__DIR__ . '/../manifest.json'), true) ?: [];
$manifestPermissions = [];
foreach (($manifestData['permissions'] ?? []) as $permissionItem) {
    $manifestPermissions[(string) ($permissionItem['ident'] ?? '')] = (string) ($permissionItem['route'] ?? '');
}
check(
    'P2 站点选择器权限节点已登记（可与「多站点管理」分权）',
    ($manifestPermissions['plugin.wisecms.action.site.context'] ?? '') === '/plugin/wisecms/site/switch'
        && ($manifestPermissions['plugin.wisecms.action.site.option'] ?? '') === '/plugin/wisecms/site/options'
        && ($manifestPermissions['plugin.wisecms.action.site'] ?? '') === '/plugin/wisecms/site/*'
);

// ---------- UIUX-P0-15 / P0-16：单页与广告页面 ----------
//
// 反模式守卫（对应 V-02 的四项扫描中的两项）：模板内联脚本 / onclick 内联处理器。
// 这两页的列表与表单全部走 `statics/js/{page,ad}.js`，模板只剩挂载点。

$inlineScriptPages = [];
foreach ([
    'page/index.html', 'page/create.html', 'page/edit.html',
    'ad/index.html', 'ad/create.html', 'ad/edit.html',
    // P0-19 / P0-20 一并纳入同一口径
    'url_rule/index.html', 'url_rule/create.html', 'url_rule/edit.html',
    'redirect/index.html', 'redirect/create.html',
    // P0-21 / P0-33 / P0-23 / P0-40
    'friend_link/index.html', 'friend_link/form.html',
    'webhook/index.html', 'webhook/create.html',
    // P0-25 / P0-24
    'site/index.html', 'site/create.html', 'site/edit.html',
    'newsletter/index.html',
    // P0-22
    'spider/index.html',
    // P0-26 / P0-37 / P0-38
    'generation/index.html', 'generation/natural.html', 'generation/batch.html',
    // P2-08 回归补齐：看板原为内联 script 渲染趋势，已收敛到 js/dashboard.js
    'dashboard/index.html',
    // P2-06 / P2-05：系统工具页（缓存维护 + 孤儿数据清理）逻辑全在 js/system.js
    'system/index.html',
    // P2-03：内容模板管理三件套，逻辑全在 js/template.js
    'template/index.html', 'template/create.html', 'template/edit.html',
    // P2-04：搜索日志（热搜词经 data 属性下发，渲染在 js/search_log.js）
    'searchLog/index.html',
    // P0-34 / P0-35（P0-34 顺带清零 content/edit.html 内联脚本）
    'workflow/panel.html', 'schedule/calendar.html', 'content/edit.html',
    // P1-01（顺带清零 content/create.html 内联脚本）
    'content/create.html',
    // P1-07（清零 content/versions.html 内联脚本）
    'content/versions.html',
    // P1-15 / P1-20 / P1-21
    'spider/edit.html', 'seo/index.html', 'seo/push_log.html',
    'internalLink/index.html',
    // P1-16 / P1-17
    'webhook/edit.html', 'redirect/edit.html',
] as $tpl) {
    $tplSource = (string) file_get_contents(__DIR__ . '/../view/admin/' . $tpl);
    if (str_contains($tplSource, '<script') || str_contains($tplSource, 'onclick=')) {
        $inlineScriptPages[] = $tpl;
    }
}
check(
    'P0-15/16/19/20/21/22/23/24/25 列表·表单模板零内联脚本 / 零 onclick',
    $inlineScriptPages === [],
    implode(', ', $inlineScriptPages)
);

// 内核 `formatter: 'toggle'` 自 P1-27 起**内置处理器**（`enhanced-table.js::handleRowToggleChange`，
// click 委托提交、失败回滚、data-confirm 确认），`cmsBindRowToggles()` 已退化为兜底。
// 但页面仍显式调用它（作为「本页有开关」的一致性标记，且覆盖内核缺失的降级环境），
// 故保留「用了 toggle 就必须带 cmsBindRowToggles」的自动发现守卫。
$cmsAdminSource  = (string) file_get_contents(__DIR__ . '/../statics/js/cms-admin.js');
$togglePages     = [];
$toggleOffenders = [];
foreach (glob(__DIR__ . '/../statics/js/*.js') ?: [] as $toggleJsFile) {
    $toggleJsSource = (string) file_get_contents($toggleJsFile);
    if (!str_contains($toggleJsSource, "formatter: 'toggle'")) {
        continue;
    }
    $togglePages[] = basename($toggleJsFile);
    if (!str_contains($toggleJsSource, 'cmsBindRowToggles')) {
        $toggleOffenders[] = basename($toggleJsFile);
    }
}
check(
    'P1-27 用了 toggle formatter 的页面仍显式带 cmsBindRowToggles（兜底标记）',
    str_contains($cmsAdminSource, 'export function cmsBindRowToggles')
        && $togglePages !== []
        && $toggleOffenders === [],
    $toggleOffenders === []
        ? ('开关页面: ' . implode(', ', $togglePages))
        : ('未绑定: ' . implode(', ', $toggleOffenders))
);

$adJsSource   = (string) file_get_contents(__DIR__ . '/../statics/js/ad.js');
$pagePhpSource = (string) file_get_contents(__DIR__ . '/../controller/Page.php');
check(
    'P0-16 广告固定页长 15 → fixedPageSize（不是无效的 pageList）',
    str_contains($adJsSource, 'fixedPageSize: true')
        && !str_contains($adJsSource, 'pageList: false')
);
check(
    'P0-15 单页列表只取正文前缀并派生摘要（不整行拉 longtext）',
    str_contains($pagePhpSource, "fieldRaw('LEFT(content, 300) AS content')")
        && str_contains($pagePhpSource, 'function makeSummary')
);

// ---------- UIUX-P0-19 / P0-20：URL 规则与重定向 ----------

$urlRuleJsSource = (string) file_get_contents(__DIR__ . '/../statics/js/url_rule.js');
$redirectJsSource = (string) file_get_contents(__DIR__ . '/../statics/js/redirect.js');
check(
    'P0-19 URL 规则列表用 flat 形状（接口返回纯数组，无分页键）',
    str_contains($urlRuleJsSource, "shape: 'flat'")
);
check(
    'P0-20 重定向列表 fixedPageSize（后端 paginate(20) 不读 limit）',
    str_contains($redirectJsSource, 'fixedPageSize: true')
);

// 桥接点：`ajaxPost` 垫片必须用 interceptor:false 才能拿到完整信封，
// 否则存量页面的 `if (res.code === 0) { 刷新 }` 永远不成立（保存成功但页面不动）。
$siteSwitchShimSource = (string) file_get_contents(__DIR__ . '/../view/admin/common/site_switch.html');
check(
    'P0-06 垫片 ajaxPost 取完整信封（interceptor:false），否则存量页面刷新永不触发',
    str_contains($siteSwitchShimSource, '{ interceptor: false }')
);

$urlRuleControllerSource = (string) file_get_contents(__DIR__ . '/../controller/UrlRule.php');
$redirectControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Redirect.php');
check(
    'P0-19 toggleStatus 支持目标值语义（连点不会翻回去）',
    str_contains($urlRuleControllerSource, "\$this->request->post('status', null)")
        && str_contains($urlRuleControllerSource, 'in_array((int) $target, [0, 1], true)')
);
check(
    'P0-19 列表含「全站通用」规则（site_id=0 在 matchRules 里对任何站点生效）',
    str_contains($urlRuleControllerSource, '[0, (new \plugin\wisecms\service\site\SiteService())->resolveAdminSiteId()]')
        && str_contains((string) file_get_contents(__DIR__ . '/../service/url/UrlRuleService.php'), 'whereIn(\'site_id\', $siteIds)')
);
check(
    'P0-20 重定向按站点过滤 + 删除校验归属 + 死链检测传当前站点',
    substr_count($redirectControllerSource, 'resolveAdminSiteId') >= 2
        && str_contains($redirectControllerSource, 'function assertSiteOwned')
        && str_contains($redirectControllerSource, 'checkDeadLinks($this->siteId())')
);

// ---------- UIUX-P0-21 / P0-33 / P0-23 / P0-40：友链与 Webhook ----------

// 🚨 批量勾选列：bootstrap-table 只在存在 `{ checkbox: true }` 列时渲染复选框，内核也不会注入。
// 只写 `checkboxHeader: true` 的效果是「没有任何勾选框」→ 批量按钮永远提示「请先勾选」。
$checkboxOffenders = [];
$checkboxPages     = [];
foreach (glob(__DIR__ . '/../statics/js/*.js') ?: [] as $cbFile) {
    $cbSource = (string) file_get_contents($cbFile);
    if (!str_contains($cbSource, 'checkboxHeader: true')) {
        continue;
    }
    $checkboxPages[] = basename($cbFile);
    if (!str_contains($cbSource, '{ checkbox: true }')) {
        $checkboxOffenders[] = basename($cbFile);
    }
}
check(
    'P0-33 开了 checkboxHeader 的页面必须声明 { checkbox: true } 列（否则没有勾选框）',
    $checkboxPages !== [] && $checkboxOffenders === [],
    $checkboxOffenders === []
        ? ('批量页面: ' . implode(', ', $checkboxPages))
        : ('缺勾选列: ' . implode(', ', $checkboxOffenders))
);

$friendLinkJsSource = (string) file_get_contents(__DIR__ . '/../statics/js/friend_link.js');
check(
    'P0-33 友链批量删除走 cmsBatch（ids 为数组）+ 拖拽自组 orders（不用 dragsort_url）',
    str_contains($friendLinkJsSource, 'cmsBatch({')
        && str_contains($friendLinkJsSource, 'cms-drag-handle')
        && str_contains($friendLinkJsSource, 'JSON.stringify(orders)')
        // 注：注释里可以提到 dragsort_url（说明为什么不那么做），这里只断言**没有把它配置出去**
        && !str_contains($friendLinkJsSource, 'dragsort_url:')
);
check(
    'P1-19b 友链列表 limit 上限收敛（原实现只有 max(1)）',
    str_contains((string) file_get_contents(__DIR__ . '/../service/content/FriendLinkService.php'), 'min(100, max(1, (int) ($params[\'limit\']')
);

$webhookControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Webhook.php');
$webhookTemplateSource   = (string) file_get_contents(__DIR__ . '/../view/admin/webhook/create.html');
check(
    'P0-23 列表不回显密钥明文（只给 has_secret）',
    str_contains($webhookControllerSource, "\$row['has_secret'] = !empty(\$row['secret'])")
        && str_contains($webhookControllerSource, "unset(\$row['secret'])")
);
$webhookJsSource = (string) file_get_contents(__DIR__ . '/../statics/js/webhook.js');
check(
    'P0-40 表单为事件多选（勾选项 + 手填合并进 events 隐藏字段）',
    str_contains($webhookTemplateSource, 'id="eventChecks"')
        && str_contains($webhookTemplateSource, 'id="eventsValue"')
        && str_contains($webhookJsSource, "'#eventsValue'")
        && str_contains($webhookJsSource, "attr('data-event')")
);
check(
    'P0-40 queue 模式 / 依赖缺失给出显式警告',
    str_contains($webhookTemplateSource, "queue:work")
        && str_contains($webhookTemplateSource, 'wise-webhook')
        && str_contains($webhookControllerSource, 'dependencyInstalled')
);

// ---------- UIUX-P0-25 / P0-24：站点与订阅 ----------

$siteControllerSource   = (string) file_get_contents(__DIR__ . '/../controller/Site.php');
$siteServiceSource2     = (string) file_get_contents(__DIR__ . '/../service/site/SiteService.php');
$siteJsSource           = (string) file_get_contents(__DIR__ . '/../statics/js/site.js');
$newsletterJsSource     = (string) file_get_contents(__DIR__ . '/../statics/js/newsletter.js');

check(
    'P0-25 站点列表附带「站点数据量」（与删除闸门口径一致：内容/栏目/单页，含回收站）',
    str_contains($siteControllerSource, "\$row['data_counts']")
        && str_contains($siteControllerSource, "'wisecms_content'")
        && str_contains($siteControllerSource, "'wisecms_category'")
        && str_contains($siteControllerSource, "'wisecms_page'")
        // 闸门侧必须仍在数同样三张表（两端口径若漂移，列表会显示"可删除"却删不掉）
        && str_contains($siteServiceSource2, '$contentCount  = Content::withTrashed()')
        && str_contains($siteServiceSource2, '$categoryCount = Category::withTrashed()')
        && str_contains($siteServiceSource2, '$pageCount     = Page::withTrashed()')
);
check(
    'P0-25 删除站点的 L3 确认词 = 站点名；「当前站点」不可重复切换（按行显隐）',
    str_contains($siteJsSource, 'function dangerWord')
        && str_contains($siteJsSource, 'cmsOperateButtons')
        && str_contains($siteJsSource, 'show: (r) => parseInt(r.id, 10) !== currentSiteId')
);
check(
    'P0-24 邮箱脱敏显示 + 复制完整地址（cmsCopy）',
    str_contains($newsletterJsSource, 'function maskEmail')
        && str_contains($newsletterJsSource, 'cmsCopy(row.email')
        && str_contains($newsletterJsSource, 'fixedPageSize: true')
);

// ---------- UIUX-P0-22：采集任务 ----------

$spiderControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Spider.php');
$spiderJsSource         = (string) file_get_contents(__DIR__ . '/../statics/js/spider.js');

check(
    'P0-22 采集列表附加栏目名（cat_name，栏目被删时降级显示 catid）',
    str_contains($spiderControllerSource, "\$row['cat_name'] = \$catNames[\$row['target_catid']] ?? ''")
        && str_contains($spiderControllerSource, "Category::column('name', 'id')")
        && str_contains($spiderJsSource, 'function catCell')
        && str_contains($spiderJsSource, "row.cat_name")
);
check(
    'P0-22 采集执行走 L3（同步长任务，无进度接口 → 不做假进度条）',
    str_contains($spiderJsSource, 'cmsDangerConfirm')
        && str_contains($spiderJsSource, 'interceptor: false, loading: true')
        && str_contains($spiderJsSource, 'cmsRefresh(list)')
        // 明确不假装有进度条：run 是同步接口
        && str_contains($spiderJsSource, '同步执行')
);
check(
    'P0-22 删除为硬删 + 提示「不影响已采集内容」',
    str_contains($spiderJsSource, '删除后<b>没有回收站</b>')
        || str_contains($spiderJsSource, '没有回收站')
);

// ---------- UIUX-P0-26 / P0-37 / P0-38 / P0-39：AI 生成 ----------

$generationJsSource = (string) file_get_contents(__DIR__ . '/../statics/js/generation.js');

check(
    'P0-26 生成列表标准 paginate + 状态/模式/进度列（running 只读，不假装轮询）',
    str_contains($generationJsSource, "shape: 'standard'")
        && str_contains($generationJsSource, 'function statusCell')
        && str_contains($generationJsSource, 'function modeCell')
        && str_contains($generationJsSource, 'function progressCell')
);
check(
    'P0-26/37/38 生成是同步接口 → 不做假进度条（loading 而非轮询）',
    str_contains($generationJsSource, 'interceptor: false, loading: true')
        // 明确记录「无后台队列、无进度轮询」的事实
        && str_contains($generationJsSource, '没有任务队列')
);
check(
    'P0-37 自然语言结果卡片（有 content_id 才显示成功，降级提示 wise-agent）',
    str_contains($generationJsSource, 'body.data.content_id')
        && str_contains($generationJsSource, 'wise-agent')
        && str_contains($generationJsSource, '去编辑 / 发布')
);
check(
    'P0-38 批量结果表（成功/失败明细，不放假重试入口）',
    str_contains($generationJsSource, 'filter((r) => r && r.content_id)')
        && str_contains($generationJsSource, 'filter((r) => r && r.error)')
        && str_contains($generationJsSource, '无重试接口')
);
check(
    'P0-39 取消仅对 running/pending 显示（清理异常残留）',
    str_contains($generationJsSource, "show: (r) => r.status === 'running' || r.status === 'pending'")
        && str_contains($generationJsSource, '清理')
);

// ---------- UIUX-P0-34 / P0-35 / P0-36：工作流面板 + 发布日历 ----------

$workflowJsSource  = (string) file_get_contents(__DIR__ . '/../statics/js/workflow.js');
$scheduleJsSource  = (string) file_get_contents(__DIR__ . '/../statics/js/schedule.js');
$contentJsSource   = (string) file_get_contents(__DIR__ . '/../statics/js/content.js');
$manifestSource    = (string) file_get_contents(__DIR__ . '/../manifest.json');

check(
    'P0-34 工作流面板：读 status、渲染可用转换与历史、执行 transition',
    str_contains($workflowJsSource, 'workflow/status')
        && str_contains($workflowJsSource, 'workflow/transition')
        && str_contains($workflowJsSource, 'snapshot.transitions')
        && str_contains($workflowJsSource, 'snapshot.history')
        && str_contains($workflowJsSource, 'cms-wf-transition')
);
check(
    'P0-34 工作流三处接入：内容列表行操作 + 编辑页主按钮（面板内历史即第三处）',
    str_contains($contentJsSource, "action: 'workflow'")
        && str_contains($contentJsSource, 'workflow/panel')
        && str_contains($contentJsSource, 'btn-workflow')
        // ⚠️ V 验收补：入口引用的 `workflow/panel` 必须有路由 + 控制器 action，
        // 否则点开「流程/工作流」是 404（P0-34 曾漏登记 action 与路由）
        && str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), 'workflow/panel')
        && str_contains((string) file_get_contents(__DIR__ . '/../controller/Workflow.php'), 'function panel')
);
check(
    'P0-34 转换不支持备注 → 面板不放假备注输入框（后端未读 remark）',
    str_contains($workflowJsSource, '备注')
);
check(
    'P0-35 日历读 calendar/suggest、自实现月历、冲突标红',
    str_contains($scheduleJsSource, 'schedule/calendar')
        && str_contains($scheduleJsSource, 'schedule/suggest')
        && str_contains($scheduleJsSource, 'cms-cal-cell')
        && str_contains($scheduleJsSource, 'available')
);
check(
    'P0-36 拖拽改期 → schedule/batch（无 moveSchedule 路由）',
    str_contains($scheduleJsSource, 'draggable="true"')
        && str_contains($scheduleJsSource, 'schedule/batch')
        && str_contains($scheduleJsSource, "'ids[]': [contentId]")
);
check(
    'P0-35 发布日历菜单入口（manifest）',
    str_contains($manifestSource, '/plugin/wisecms/schedule/calendar')
);

// ---------- UIUX-P1-27 / P1-28：内核 toggle 处理器 + Safari 日期解析 ----------

$enhancedTableSource = (string) file_get_contents(__DIR__ . '/../../../public/statics/modules/enhanced-table.js');

check(
    'P1-27 内核 toggle 处理器读 data-confirm（确认后执行、取消回滚）',
    str_contains($enhancedTableSource, 'handleRowToggleChange')
        && str_contains($enhancedTableSource, "data('confirm')")
        && str_contains($enhancedTableSource, 'Layer.confirm(confirmMsg')
);
check(
    'P1-27 插件 cmsBindRowToggles 退化为兜底（内核已加载则不重复绑 change）',
    str_contains($cmsAdminSource, 'handleRowToggleChange')
        && str_contains($cmsAdminSource, 'typeof ET.prototype.handleRowToggleChange')
);
check(
    'P1-28 内核 moment 子集日期归一化（空格 → T，规避 Safari Invalid Date）',
    str_contains($enhancedTableSource, 'function normalizeDate')
        && str_contains($enhancedTableSource, 'new Date(normalizeDate')
        && str_contains($enhancedTableSource, "m[1] + 'T' + m[2]")
);
check(
    'P1-24 内核 timeAgo 的 duration 补 years/months/days（不再抛 TypeError）',
    str_contains($enhancedTableSource, 'years() { return Math.floor(_ms / (86400000 * 365))')
        && str_contains($enhancedTableSource, 'months() { return Math.floor(_ms / (86400000 * 30))')
        && str_contains($enhancedTableSource, 'days() { return Math.floor(_ms / 86400000)')
);

// ---------- UIUX-P1-29 / P1-30：单页回收站 + 站点设置消费方 ----------

$pageControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Page.php');
$pageJsSource         = (string) file_get_contents(__DIR__ . '/../statics/js/page.js');
$siteServiceSource    = (string) file_get_contents(__DIR__ . '/../service/site/SiteService.php');
$frontBaseSource      = (string) file_get_contents(__DIR__ . '/../controller/index/Base.php');
$routeSource          = (string) file_get_contents(__DIR__ . '/../route/plugin.php');

check(
    'P1-29 单页回收站：index 支持 trashed + 新增 restore（校验别名占用）',
    str_contains($pageControllerSource, "param('trashed'")
        && str_contains($pageControllerSource, 'function restore')
        && str_contains($pageControllerSource, 'onlyTrashed()')
        && str_contains($pageControllerSource, "\$page->restore()")
        && str_contains($routeSource, 'page/restore')
);
check(
    'P1-29 单页回收站前端：回收站视图切换 + 恢复操作 + 状态开关禁用',
    str_contains($pageJsSource, 'isTrashed')
        && str_contains($pageJsSource, 'btn-trash-page')
        && str_contains($pageJsSource, 'RESTORE_OPERATE')
        && str_contains($pageJsSource, 'disable: (value, row) => isTrashed')
);
check(
    'P1-30 站点设置读取封装（getSiteSetting / getSiteSeoSetting）',
    str_contains($siteServiceSource, 'function getSiteSetting')
        && str_contains($siteServiceSource, 'function getSiteSeoSetting')
);
check(
    'P1-30 前台接入站点名（站点级优先）+ SEO 默认值',
    str_contains($frontBaseSource, 'getSiteSetting')
        && str_contains($frontBaseSource, "siteSetting['site_name']")
        && str_contains($frontBaseSource, 'getSiteSeoSetting')
);

// ---------- P1-30 编辑器 + P1-18 订阅导出/批量 ----------

$siteEditHtml    = (string) file_get_contents(__DIR__ . '/../view/admin/site/edit.html');
$newsletterCtrl   = (string) file_get_contents(__DIR__ . '/../controller/Newsletter.php');
$newsletterJsSrc  = (string) file_get_contents(__DIR__ . '/../statics/js/newsletter.js');

check(
    'P1-30 站点编辑页有 setting/seo_setting 键值对编辑器（数组提交）',
    str_contains($siteEditHtml, 'setting[site_name]')
        && str_contains($siteEditHtml, 'seo_setting[keywords]')
        && str_contains($siteEditHtml, 'seo_setting[description]')
);
check(
    'P1-30 Site::edit 归一化 JSON 列（null → []）供编辑器渲染',
    str_contains((string) file_get_contents(__DIR__ . '/../controller/Site.php'), 'is_array($site->setting)')
);
check(
    'P1-18 Newsletter::export 导出 CSV（BOM + 公式注入防护）+ 手动操作日志',
    str_contains($newsletterCtrl, 'function export')
        && str_contains($newsletterCtrl, "\\xEF\\xBB\\xBF")
        && str_contains($newsletterCtrl, "['=', '+', '-', '@']")
        && str_contains($newsletterCtrl, 'OperationLogModel::createFromConfig')
        && str_contains($newsletterCtrl, 'text/csv')
);
check(
    'P1-18 Newsletter::batchDelete + 前端勾选列/批量删除/导出按钮',
    str_contains($newsletterCtrl, 'function batchDelete')
        && str_contains($routeSource, 'newsletter/batchDelete')
        && str_contains($routeSource, 'newsletter/export')
        && str_contains($newsletterJsSrc, 'checkboxHeader: true')
        && str_contains($newsletterJsSrc, '{ checkbox: true')
        && str_contains($newsletterJsSrc, 'cmsBatch')
        && str_contains($newsletterJsSrc, 'URL.export')
);

// ---------- UIUX-P1-01：内容编辑页 Tab 六分区 ----------

$contentEditHtml   = (string) file_get_contents(__DIR__ . '/../view/admin/content/edit.html');
$contentCreateHtml = (string) file_get_contents(__DIR__ . '/../view/admin/content/create.html');

check(
    'P1-01 内容编辑页 Tab 分区（基本/正文/字段/发布/SEO/版本，P2-02 起加「质量」→ 共七）+ 右侧锚点 + 粘性操作条',
    str_contains($contentEditHtml, 'tab-basic')
        && str_contains($contentEditHtml, 'tab-body')
        && str_contains($contentEditHtml, 'tab-fields')
        && str_contains($contentEditHtml, 'tab-publish')
        && str_contains($contentEditHtml, 'tab-seo')
        && str_contains($contentEditHtml, 'tab-quality')
        && str_contains($contentEditHtml, 'tab-versions')
        && str_contains($contentEditHtml, 'cms-anchor')
        && str_contains($contentEditHtml, 'data-submit-status')
);
check(
    'P1-01 内容发布页同样六 Tab + 零内联脚本（switchModel 外置到 content.js）',
    str_contains($contentCreateHtml, 'tab-basic')
        && str_contains($contentCreateHtml, 'data-submit-status')
        && !str_contains($contentCreateHtml, '<script')
        && !str_contains($contentCreateHtml, 'onclick=')
        && !str_contains($contentCreateHtml, 'onchange=')
);
check(
    'P1-01 content.js 公共 initContentForm（锚点联动/离开保护/跨 Tab 校验）+ create 入口',
    str_contains((string) file_get_contents(__DIR__ . '/../statics/js/content.js'), 'function initContentForm')
        && str_contains((string) file_get_contents(__DIR__ . '/../statics/js/content.js'), 'beforeunload')
        && str_contains((string) file_get_contents(__DIR__ . '/../statics/js/content.js'), "modelSelect")
);

// ---------- UIUX-P1-09 / P1-07：栏目树 chip + 版本对比抽屉 ----------

$contentJsSrc   = (string) file_get_contents(__DIR__ . '/../statics/js/content.js');
$contentIndexHtml = (string) file_get_contents(__DIR__ . '/../view/admin/content/index.html');
$versionsHtml   = (string) file_get_contents(__DIR__ . '/../view/admin/content/versions.html');

check(
    'P1-09 上游上下文 chip（当前栏目 + 清除回到全量）+ 栏目树 onSelect 带 node',
    str_contains($contentJsSrc, 'function renderChip')
        && str_contains($contentJsSrc, 'cms-context-chip')
        && str_contains($contentJsSrc, 'onSelect(catid, node)')
        && str_contains($contentIndexHtml, 'cms-context-chip')
);
check(
    'P1-07 versions.html 零内联脚本 + data-action 委托按钮',
    !str_contains($versionsHtml, '<script')
        && !str_contains($versionsHtml, 'onclick=')
        && str_contains($versionsHtml, 'data-action="diff"')
        && str_contains($versionsHtml, 'data-action="rollback"')
        && str_contains($versionsHtml, 'data-base-update-time')
);
check(
    'P1-07 content.js versions() 对比抽屉 + 回滚（乐观锁透传）',
    str_contains($contentJsSrc, 'versions()')
        && str_contains($contentJsSrc, 'versionDiff')
        && str_contains($contentJsSrc, 'renderDiffDrawer')
        && str_contains($contentJsSrc, 'base_update_time')
);

// ---------- UIUX-P1-08 / P1-10：工作流时间线操作人 + 栏目树增强 ----------

$workflowServiceSource = (string) file_get_contents(__DIR__ . '/../service/engine/WorkflowService.php');
$workflowJsSource      = (string) file_get_contents(__DIR__ . '/../statics/js/workflow.js');
$cmsAdminJsSource      = (string) file_get_contents(__DIR__ . '/../statics/js/cms-admin.js');

check(
    'P1-08 工作流历史附操作人姓名（user_id → nickname/username）',
    str_contains($workflowServiceSource, "row['user_name']")
        && str_contains($workflowServiceSource, 'app\\admin\\model\\Admin')
        && str_contains($workflowServiceSource, 'nickname')
);
check(
    'P1-08 时间线显示操作人（user_name，缺省降级「用户 #id」）',
    str_contains($workflowJsSource, 'h.user_name')
        && str_contains($workflowJsSource, 'bi-person')
);
check(
    'P1-10 栏目树增强：搜索定位 + 展开态记忆（localStorage）+ 右键菜单',
    str_contains($cmsAdminJsSource, 'cms-cat-search')
        && str_contains($cmsAdminJsSource, 'applySearch')
        && str_contains($cmsAdminJsSource, 'localStorage')
        && str_contains($cmsAdminJsSource, 'showContextMenu')
        && str_contains($cmsAdminJsSource, "contextmenu")
);
check(
    'P1-10 content.js 栏目树开启 search/persist/contextMenu + 右键三动作',
    str_contains($contentJsSrc, 'search: true')
        && str_contains($contentJsSrc, "persist: 'wisecms.cat.tree.expanded'")
        && str_contains($contentJsSrc, 'addChild(node)')
        && str_contains($contentJsSrc, 'edit(node)')
        && str_contains($contentJsSrc, 'remove(node)')
        && str_contains($contentJsSrc, 'catDel')
);

// ---------- UIUX-P1-11 / P1-12：键盘快捷键 + 行右键菜单 ----------

$friendLinkJsSrc = (string) file_get_contents(__DIR__ . '/../statics/js/friend_link.js');
$pageJsSrc       = (string) file_get_contents(__DIR__ . '/../statics/js/page.js');

check(
    'P1-11 通用快捷键：cmsBindShortcuts（输入框不劫持 + Cmd/Ctrl+S 拦截）',
    str_contains($cmsAdminJsSource, 'function cmsBindShortcuts')
        && str_contains($cmsAdminJsSource, 'inEditable')
        && str_contains($cmsAdminJsSource, "toLowerCase() === 's'")
);
check(
    'P1-11 内容列表 / 编辑页接入快捷键（/ 搜索、n 新建、r 刷新、Cmd/Ctrl+S 保存）',
    str_contains($contentJsSrc, "search: () => { $('#filter-keyword')")
        && str_contains($contentJsSrc, "create: () => { window.location.href = URL.create; }")
        && str_contains($contentJsSrc, "refresh: () => cmsRefresh(list)")
        && str_contains($contentJsSrc, 'save: () => { $form.trigger(\'submit\'); }')
);
check(
    'P1-12 通用右键菜单：cmsBindRowContextMenu（tr.data(index) + getRowByIndex + contextMenu.show）',
    str_contains($cmsAdminJsSource, 'function cmsBindRowContextMenu')
        && str_contains($cmsAdminJsSource, 'getRowByIndex')
        && str_contains($cmsAdminJsSource, "data('index')")
        && str_contains($cmsAdminJsSource, 'contextMenu.show')
);
check(
    'P1-12 三个列表接入右键菜单（内容/友链/单页，含复制 ID）',
    str_contains($contentJsSrc, "cmsBindRowContextMenu('#table', list")
        && str_contains($friendLinkJsSrc, "cmsBindRowContextMenu('#table', list")
        && str_contains($pageJsSrc, "cmsBindRowContextMenu('#table', list")
        && str_contains($contentJsSrc, "label: '复制 ID'")
);
check(
    'P1-12 友链/单页接入快捷键',
    str_contains($friendLinkJsSrc, "cmsBindShortcuts")
        && str_contains($pageJsSrc, "cmsBindShortcuts")
);

// ---------- UIUX-P1-14 / P1-02：站点切换无刷新 + 自定义字段动态重载 ----------

$contentControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Content.php');
$routeSource             = (string) file_get_contents(__DIR__ . '/../route/plugin.php');
$contextBarSource2       = (string) file_get_contents(__DIR__ . '/../view/admin/common/cms_context_bar.html');

check(
    'P1-14 站点切换不再 location.reload()，改广播 cms-site-switched 事件',
    str_contains($contextBarSource2, "cms-site-switched")
        && !str_contains($contextBarSource2, 'window.location.reload()')
        && str_contains($contextBarSource2, 'notifySwitched')
);
check(
    'P1-14 cms-admin 提供 cmsOnSiteSwitched；content.js 监听后局部刷新表格+栏目树+清 chip',
    str_contains($cmsAdminJsSource, 'function cmsOnSiteSwitched')
        && str_contains($cmsAdminJsSource, 'cms-site-switched')
        && str_contains($contentJsSrc, 'cmsOnSiteSwitched(() =>')
        && str_contains($contentJsSrc, 'tree.reload()')
);
check(
    'P1-02 后端 customFields 接口（按 model_id 返回 generateForm）+ 路由',
    str_contains($contentControllerSource, 'function customFields')
        && str_contains($contentControllerSource, 'generateForm($modelId')
        && str_contains($routeSource, 'content/customFields')
);
check(
    'P1-02 content.js 切换模型 AJAX 局部重载（renderCustomFields + 不整页跳转）',
    str_contains($contentJsSrc, 'renderCustomFields')
        && str_contains($contentJsSrc, 'URL.customFields')
        && str_contains($contentJsSrc, '$fields.html(renderCustomFields(d))')
        && !str_contains($contentJsSrc, 'url.searchParams.set(\'model_id\', id)')
);

// ---------- UIUX-P1-15 / P1-20：spider/edit + SEO 新控制器 ----------

$spiderControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Spider.php');
$seoControllerSource    = (string) file_get_contents(__DIR__ . '/../controller/Seo.php');
$seoJsSource            = (string) file_get_contents(__DIR__ . '/../statics/js/seo.js');
$manifestSource2        = (string) file_get_contents(__DIR__ . '/../manifest.json');

check(
    'P1-15 spider/edit 后端（GET 回显 list_url + POST 保留 content_rules）',
    str_contains($spiderControllerSource, 'function edit')
        && str_contains($spiderControllerSource, 'content_rules')
        && str_contains($spiderControllerSource, "list_url")
);
check(
    'P1-15 spider/edit 路由 + 前端编辑入口',
    str_contains($routeSource, 'spider/edit')
        && str_contains($spiderJsSource, "action: 'edit'")
        && str_contains($spiderJsSource, "URL.edit + '?id='")
);
check(
    'P1-20 Seo 控制器聚合健康分/审计/推送/推送日志',
    str_contains($seoControllerSource, 'function index')
        && str_contains($seoControllerSource, 'siteHealthCheck')
        && str_contains($seoControllerSource, 'function audit')
        && str_contains($seoControllerSource, 'function push')
        && str_contains($seoControllerSource, 'pushAll')
        && str_contains($seoControllerSource, 'function pushLog')
);
check(
    'P1-20 seo 路由 + manifest 菜单/权限 + 前端控制器',
    str_contains($routeSource, 'seo/index')
        && str_contains($routeSource, 'seo/pushLog')
        && str_contains($manifestSource2, '/plugin/wisecms/seo/index')
        && str_contains($manifestSource2, 'plugin.wisecms.action.seo')
        && str_contains($seoJsSource, 'pushLog()')
        && str_contains($seoJsSource, 'renderAuditDetail')
);

// ---------- UIUX-P1-05 / P1-21：SEO 评分面板 + 内链管理新控制器 ----------

$internalLinkControllerSource = (string) file_get_contents(__DIR__ . '/../controller/InternalLink.php');
$internalLinkJsSource         = (string) file_get_contents(__DIR__ . '/../statics/js/internal_link.js');
$contentEditHtmlSource        = (string) file_get_contents(__DIR__ . '/../view/admin/content/edit.html');
$contentCreateHtmlSource      = (string) file_get_contents(__DIR__ . '/../view/admin/content/create.html');

check(
    'P1-05 编辑页 SEO Tab 加检测面板 + content.js 检测逻辑（renderSeoScore）',
    str_contains($contentEditHtmlSource, 'btn-seo-audit')
        && str_contains($contentEditHtmlSource, 'seo-score-panel')
        && str_contains($contentJsSrc, 'renderSeoScore')
        && str_contains($contentJsSrc, 'URL.seoAudit')
        && str_contains($contentJsSrc, "id: contentId")
);
check(
    'P1-21 InternalLink 控制器聚合列表/新增/删除/重建（按站点隔离）',
    str_contains($internalLinkControllerSource, 'function index')
        && str_contains($internalLinkControllerSource, 'function create')
        && str_contains($internalLinkControllerSource, 'function delete')
        && str_contains($internalLinkControllerSource, 'function rebuild')
        && str_contains($internalLinkControllerSource, 'rebuildFromContent')
        && str_contains($internalLinkControllerSource, "site_id', \$this->siteId()")
);
check(
    'P1-21 internalLink 路由 + manifest 菜单/权限 + 前端（新建弹层 + L3 重建）',
    str_contains($routeSource, 'internalLink/index')
        && str_contains($routeSource, 'internalLink/rebuild')
        && str_contains($manifestSource2, '/plugin/wisecms/internalLink/index')
        && str_contains($manifestSource2, 'plugin.wisecms.action.internalLink')
        && str_contains($internalLinkJsSource, 'cmsFormDialog')
        && str_contains($internalLinkJsSource, 'cmsDangerConfirm')
        && str_contains($internalLinkJsSource, "word: 'REBUILD'")
);

// ---------- UIUX-P1-16 / P1-17：webhook + redirect 补 edit/toggle/test ----------

$webhookControllerSource   = (string) file_get_contents(__DIR__ . '/../controller/Webhook.php');
$redirectControllerSource  = (string) file_get_contents(__DIR__ . '/../controller/Redirect.php');
$redirectServiceSource     = (string) file_get_contents(__DIR__ . '/../service/content/RedirectService.php');
$webhookJsSource           = (string) file_get_contents(__DIR__ . '/../statics/js/webhook.js');
$redirectJsSource          = (string) file_get_contents(__DIR__ . '/../statics/js/redirect.js');

check(
    'P1-16 webhook 后端 edit/toggleStatus/test（密钥留空不修改 + 测试返回状态码耗时）',
    str_contains($webhookControllerSource, 'function edit')
        && str_contains($webhookControllerSource, 'function toggleStatus')
        && str_contains($webhookControllerSource, 'function test')
        && str_contains($webhookControllerSource, 'dispatchSync')
        && str_contains($webhookControllerSource, 'duration_ms')
        && str_contains($webhookControllerSource, 'unset($data[\'secret\'])')
);
check(
    'P1-16 webhook 路由 + 前端 edit/test + 状态列 toggle',
    str_contains($routeSource, 'webhook/edit')
        && str_contains($routeSource, 'webhook/test')
        && str_contains($webhookJsSource, "action: 'edit'")
        && str_contains($webhookJsSource, "action: 'test'")
        && str_contains($webhookJsSource, 'formatter: \'toggle\'')
        && str_contains($webhookJsSource, 'data-events')
);
check(
    'P1-17 redirect 后端 edit/toggleStatus + RedirectService::update（唯一键排除自己 + 站点不可改）',
    str_contains($redirectControllerSource, 'function edit')
        && str_contains($redirectControllerSource, 'function toggleStatus')
        && str_contains($redirectServiceSource, 'function update')
        && str_contains($redirectServiceSource, "where('id', '<>', \$id)")
        && str_contains($redirectServiceSource, "unset(\$data['site_id'])")
);
check(
    'P1-17 redirect 路由 + 前端 edit + 状态列 toggle',
    str_contains($routeSource, 'redirect/edit')
        && str_contains($routeSource, 'redirect/toggleStatus')
        && str_contains($redirectJsSource, "action: 'edit'")
        && str_contains($redirectJsSource, 'formatter: \'toggle\'')
        && str_contains($redirectJsSource, 'function bindForm')
);

// ---------- UIUX-P1-04 / P1-22：离开保护收尾 + content/export ----------

check(
    'P1-04 返回跳转拦截（cms-back + dirty 确认）',
    str_contains($contentJsSrc, '.cms-back')
        && str_contains($contentJsSrc, '有未保存的修改，确定离开')
        && str_contains($contentJsSrc, 'window.addEventListener(\'beforeunload\'')
);
check(
    'P1-04 返回链接加 cms-back 标记（edit/create 两页）',
    str_contains($contentEditHtmlSource, 'cms-back')
        && str_contains($contentCreateHtmlSource, 'cms-back')
);
check(
    'P1-22 content/export 后端（ids 站点过滤 + 筛选条件 + CSV BOM/转义）',
    str_contains($contentControllerSource, 'function export')
        && str_contains($contentControllerSource, 'function buildContentCsv')
        && str_contains($contentControllerSource, '\\xEF\\xBB\\xBF')
        && str_contains($contentControllerSource, 'Content-Disposition')
        && str_contains($contentControllerSource, "column('id')")
);
check(
    'P1-22 content/export 路由 + 前端导出入口（选中优先 + 筛选条件）',
    str_contains($routeSource, 'content/export')
        && str_contains($contentJsSrc, 'function exportContent')
        && str_contains($contentJsSrc, 'URL.export')
        && str_contains($contentJsSrc, "qs.set('ids', ids.join(','))")
);

// ---------- UIUX-P1-19：批量接口（category/recycle/comment/tag） ----------

$categoryControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Category.php');
$recycleControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Recycle.php');
$commentControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Comment.php');
$tagControllerSource     = (string) file_get_contents(__DIR__ . '/../controller/Tag.php');

check(
    'P1-19 category/batchToggle（目标值 + 站点过滤 + clearCache）',
    str_contains($categoryControllerSource, 'function batchToggle')
        && str_contains($categoryControllerSource, "where('site_id', \$this->siteId())")
        && str_contains($categoryControllerSource, 'clearCache')
);
check(
    'P1-19 recycle/batchRestore + batchDestroy（ownedTrashedIds 站点过滤 + 单条容错）',
    str_contains($recycleControllerSource, 'function batchRestore')
        && str_contains($recycleControllerSource, 'function batchDestroy')
        && str_contains($recycleControllerSource, 'function ownedTrashedIds')
        && str_contains($recycleControllerSource, 'onlyTrashed')
        && str_contains($recycleControllerSource, '条失败')
);
check(
    'P1-19 comment/batchApprove + batchReject（站点过滤 + 单条容错）',
    str_contains($commentControllerSource, 'function batchApprove')
        && str_contains($commentControllerSource, 'function batchReject')
        && str_contains($commentControllerSource, "where('site_id', \$this->siteId())")
);
check(
    'P1-19 tag/batchDelete（站点过滤 + 单条容错）',
    str_contains($tagControllerSource, 'function batchDelete')
        && str_contains($tagControllerSource, "where('site_id', \$this->siteId())")
);
check(
    'P1-19 六个批量路由已登记',
    str_contains($routeSource, 'category/batchToggle')
        && str_contains($routeSource, 'recycle/batchRestore')
        && str_contains($routeSource, 'recycle/batchDestroy')
        && str_contains($routeSource, 'comment/batchApprove')
        && str_contains($routeSource, 'comment/batchReject')
        && str_contains($routeSource, 'tag/batchDelete')
);

// ---------- UIUX-P1-03：自动草稿（content/autosave 轻量接口） ----------

check(
    'P1-03 后端 autosave：新建恒为草稿、编辑不传 status、走 create/update 不刷版本',
    str_contains($contentControllerSource, 'function autosave')
        && str_contains($contentControllerSource, "STATUS_DRAFT")
        && str_contains($contentControllerSource, "unset(\$data['status'])")
        && str_contains($contentControllerSource, 'contentService->update($id, $data)')
        && str_contains($contentControllerSource, 'contentService->create($data)')
);
check(
    'P1-03 前端自动草稿：60s 定时 + 失焦 + 脏标记 + 回填 id 改走 edit',
    str_contains($contentJsSrc, 'function doAutosave')
        && str_contains($contentJsSrc, 'setInterval(doAutosave, 60000)')
        && str_contains($contentJsSrc, "'blur', 'input, textarea, select', doAutosave")
        && str_contains($contentJsSrc, '$form.attr(\'action\', URL.edit)')
        && str_contains($contentJsSrc, 'URL.autosave')
);
check(
    'P1-03 模板 autosave-status 容器（edit/create 两页）',
    str_contains($contentEditHtmlSource, 'autosave-status')
        && str_contains($contentCreateHtmlSource, 'autosave-status')
);
check(
    'P1-03 autosave 路由已登记',
    str_contains($routeSource, 'content/autosave')
);

$siteSwitchPartial = __DIR__ . '/../view/admin/common/site_switch.html';
$contextBarPartial  = __DIR__ . '/../view/admin/common/cms_context_bar.html';
$siteSwitchSource   = is_file($siteSwitchPartial) ? (string) file_get_contents($siteSwitchPartial) : '';
$contextBarSource   = is_file($contextBarPartial) ? (string) file_get_contents($contextBarPartial) : '';

// UIUX-P0-05/P0-06：站点选择器 DOM 与 options/switch 端点搬到了独立的
// `cms_context_bar.html`，`site_switch.html` 退化为「兼容垫片 + include 上下文条」。
// 断言随之拆成两条，但覆盖的是**同一组不变量**：
//   ① 公共 JS 助手（ajaxPost / confirmAction）仍必须由 site_switch 下发 —— 存量 40+ 页面靠它；
//   ② 站点切换 DOM 与 site/options、site/switch 端点必须真实存在，
//      且 site_switch 必须挂载上下文条（否则老页面引了 site_switch 就看不到站点选择器）。
check(
    'P2 站点选择器区块存在且下发公共 JS 助手',
    is_file($siteSwitchPartial)
        && str_contains($siteSwitchSource, 'function ajaxPost')
        && str_contains($siteSwitchSource, 'function confirmAction')
);

check(
    'P2 站点上下文条存在且被 site_switch 挂载（含 options/switch 端点）',
    is_file($contextBarPartial)
        && str_contains($contextBarSource, 'site/options')
        && str_contains($contextBarSource, 'site/switch')
        && str_contains($contextBarSource, 'cmsSiteSelect')
        && str_contains($siteSwitchSource, 'admin/common/cms_context_bar'),
    is_file($contextBarPartial) ? '上下文条缺少端点或未被挂载' : 'cms_context_bar.html 缺失'
);

$missingSwitchInclude = [];
foreach (glob(__DIR__ . '/../view/admin/*/*.html') ?: [] as $viewFile) {
    $viewRelative = str_replace('\\', '/', substr($viewFile, (int) strpos($viewFile, '/view/')));
    if (str_ends_with($viewRelative, 'common/site_switch.html')
        // UIUX-P0-05：上下文条与 pager 同属「被 include 的片段」，不要求自己挂载自己
        || str_ends_with($viewRelative, 'common/cms_context_bar.html')
        || str_ends_with($viewRelative, 'pager.html')
        // UIUX-P2-06：系统工具页是**全站**维护操作（缓存刷新不随站点变化），
        // 刻意不挂站点条，避免让人误以为「只刷当前站点」。
        || str_starts_with($viewRelative, '/view/admin/system/')) {
        continue;
    }
    if (!str_contains((string) file_get_contents($viewFile), 'admin/common/site_switch')) {
        $missingSwitchInclude[] = basename($viewFile);
    }
}
check(
    'P2 后台视图全部挂载站点选择器区块',
    $missingSwitchInclude === [],
    implode(', ', $missingSwitchInclude)
);

// 后台读写路径统一站点上下文（否则「切了站点列表不变 / 串站」）
$adminSiteScoped = [
    'controller/Content.php'    => "\$this->siteId()",
    'controller/Category.php'   => 'resolveAdminSiteId',
    'controller/Page.php'       => 'resolveAdminSiteId',
    'controller/Recycle.php'    => 'resolveAdminSiteId',
    'controller/FriendLink.php' => 'resolveAdminSiteId',
    'controller/UrlRule.php'    => 'resolveAdminSiteId',
    'controller/Dashboard.php'  => 'resolveAdminSiteId',
];
$siteScopeMissed = [];
foreach ($adminSiteScoped as $adminFile => $needle) {
    $adminSource = (string) file_get_contents(__DIR__ . '/../' . $adminFile);
    if (!str_contains($adminSource, $needle)) {
        $siteScopeMissed[] = $adminFile;
    }
}
check('P2 后台控制器改用后台站点上下文', $siteScopeMissed === [], implode(', ', $siteScopeMissed));

// 友链写死的站点 1 已移除
check(
    'P2 友链不再写死站点 1（DEFAULT_SITE_ID 已移除）',
    !str_contains((string) file_get_contents(__DIR__ . '/../controller/FriendLink.php'), 'const DEFAULT_SITE_ID')
);

// 看板统计默认站点参数不再硬编码 1
check(
    'P2 看板统计站点参数默认值收敛为 0（不限定，而非站点 1）',
    (new \ReflectionMethod(\plugin\wisecms\service\integration\AnalyticsService::class, 'getContentStats'))
        ->getParameters()[0]->getDefaultValue() === 0
        && (new \ReflectionMethod(\plugin\wisecms\service\integration\AnalyticsService::class, 'getTopContents'))
            ->getNumberOfParameters() === 2
);

// 内容动作的站点归属校验（IDOR 面收敛）
$contentControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Content.php');
check(
    'P2 内容动作带站点归属校验（ownsContent / ownedIds）',
    str_contains($contentControllerSource, 'function ownsContent')
        && str_contains($contentControllerSource, 'function ownedIds')
        && substr_count($contentControllerSource, 'ownsContent(') >= 8
);

// ---- UIUX-P0-31：字段编辑页的 setting 形状（曾因模型 $json 属性而 500） ----
// `ModelField` 声明 `protected $json = ['setting']`，think-orm 在查询层就把它解成数组；
// 若控制器再 `json_decode($field->setting, ...)` 会抛 TypeError（json_decode 只接受 string）
// → 整个字段编辑页 500。此处锁死「走归一化函数、不再直接 json_decode」。
$modelFieldControllerSource = (string) file_get_contents(__DIR__ . '/../controller/ModelField.php');
check(
    'UIUX-P0-31 setting 形状归一化（禁止直接 json_decode($field->setting)）',
    str_contains($modelFieldControllerSource, 'function normalizeSetting')
        && !str_contains($modelFieldControllerSource, 'json_decode($field->setting')
);

// 字段类型配置接口（getAddForm）的三条路由必须登记，否则类型配置区永远加载失败
check(
    'UIUX-P0-31 getAddForm 路由已登记',
    str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "modelField/getAddForm")
);

// ---------- UIUX-P1-25：字段类型配置表单补齐（AddForm/EditForm） ----------

check(
    'P1-25 FieldBase 提供 options 归一化/反序列化工具',
    str_contains((string) file_get_contents(__DIR__ . '/../field/base/FieldBase.php'), 'function normalizeOptions')
        && str_contains((string) file_get_contents(__DIR__ . '/../field/base/FieldBase.php'), 'function optionsToText')
);

// 9 个有真实配置项的类型补齐 AddForm + EditForm；无配置项类型保持空态（不造假配置）
$p125Forms   = ['select', 'radio', 'checkbox', 'number', 'textarea', 'markdown', 'file', 'image', 'images'];
$p125Missing = [];
foreach ($p125Forms as $t) {
    if (!is_file(__DIR__ . "/../field/{$t}/AddForm.php")) {
        $p125Missing[] = "{$t}/AddForm";
    }
    if (!is_file(__DIR__ . "/../field/{$t}/EditForm.php")) {
        $p125Missing[] = "{$t}/EditForm";
    }
}
check(
    'P1-25 9 个有配置项的类型补齐 AddForm + EditForm',
    $p125Missing === [],
    '缺: ' . implode(', ', $p125Missing)
);

check(
    'P1-25 select/radio/checkbox Form 用 normalizeOptions 解析选项',
    str_contains((string) file_get_contents(__DIR__ . '/../field/select/Form.php'), 'normalizeOptions')
        && str_contains((string) file_get_contents(__DIR__ . '/../field/radio/Form.php'), 'normalizeOptions')
        && str_contains((string) file_get_contents(__DIR__ . '/../field/checkbox/Form.php'), 'normalizeOptions')
);

check(
    'P1-25 image Form 消费 preview_height / images Form 消费 thumb_size',
    str_contains((string) file_get_contents(__DIR__ . '/../field/image/Form.php'), 'preview_height')
        && str_contains((string) file_get_contents(__DIR__ . '/../field/images/Form.php'), 'thumb_size')
);

// 栏目 site_id 不可由表单伪造
check(
    'P2 栏目 site_id 已纳入批量赋值黑名单',
    str_contains(
        (string) file_get_contents(__DIR__ . '/../service/model/MassAssignmentGuard.php'),
        "'wisecms_category'"
    )
);

// ---------- P2-2 死代码清理 ----------

check(
    'P2 Demo 残留已清理（controller/Index.php + view/hello.html）',
    !file_exists(__DIR__ . '/../controller/Index.php')
        && !file_exists(__DIR__ . '/../view/hello.html')
        // 前台控制器（小写 index 目录）与前台首页视图仍在 —— 勿与 Demo 一并误删
        && file_exists(__DIR__ . '/../controller/index/Index.php')
        && file_exists(__DIR__ . '/../view/index/index.html')
);

check(
    'P2 config/view.php 在役不可删（taglib_pre_load 全局合并）',
    is_file(__DIR__ . '/../config/view.php')
        && str_contains((string) file_get_contents(__DIR__ . '/../config/view.php'), 'taglib_pre_load')
);

// ---------- P2-3 广告系统：计划功能，保留不动 ----------

check(
    'P2 广告系统保留（计划功能，不纳入死代码清理）',
    file_exists($migrationDir . '/20251102000019_create_wisecms_ad_table.php')
        && str_contains($adMigration, '计划功能')
        && str_contains((string) file_get_contents(__DIR__ . '/../model/Ad.php'), '计划功能')
        && str_contains((string) file_get_contents(__DIR__ . '/../controller/Ad.php'), '计划功能')
);

// ---------- P2-4 标签词表：按站点隔离（原「全站共享」已立项落地） ----------

$tagMigration     = (string) file_get_contents($migrationDir . '/20251102000006_create_wisecms_tag_table.php');
$tagRelMigration  = (string) file_get_contents($migrationDir . '/20251102000007_create_wisecms_tag_relation_table.php');

check(
    'P2-4 标签两表补 site_id（词表 + 关联冗余列）',
    str_contains($tagMigration, "Column::integer('site_id')")
        && str_contains($tagRelMigration, "Column::integer('site_id')")
);

check(
    'P2-4 唯一性由全局收敛为站点内（(site_id,name) / (site_id,alias)）',
    str_contains($tagMigration, "['site_id', 'name'], ['unique' => true]")
        && str_contains($tagMigration, "['site_id', 'alias'], ['unique' => true]")
        // 旧的全局单列唯一键必须已移除（否则 B 站仍无法建同名/同 slug 标签）
        && !str_contains($tagMigration, "addIndex(['name'], ['unique' => true])")
        && !str_contains($tagMigration, "addIndex(['alias'], ['unique' => true])")
);

check(
    'P2-4 TagService 站点化（创建/查重/别名/云 均带站点）',
    (new \ReflectionMethod(\plugin\wisecms\service\content\TagService::class, 'create'))->getNumberOfParameters() === 2
        && (new \ReflectionMethod(\plugin\wisecms\service\content\TagService::class, 'makeAlias'))->getNumberOfParameters() === 3
        && (new \ReflectionMethod(\plugin\wisecms\service\content\TagService::class, 'siteCloud'))->getNumberOfParameters() === 3
        && method_exists(\plugin\wisecms\service\content\TagService::class, 'findOrCreate')
        && method_exists(\plugin\wisecms\service\content\TagService::class, 'assertSiteOwned')
        // 全站口径的历史方法已移除（getCloud 无站点 / clearCloudCache 无站点）
        && !method_exists(\plugin\wisecms\service\content\TagService::class, 'getCloud')
        && !method_exists(\plugin\wisecms\service\content\TagService::class, 'clearCloudCache')
);

check(
    'P2-4 内容存标签按站点（saveTags 形参含 siteId，收敛到 findOrCreate）',
    (new \ReflectionMethod(\plugin\wisecms\service\content\ContentService::class, 'saveTags'))->getNumberOfParameters() === 4
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../service/content/ContentService.php'),
            'findOrCreate($siteId'
        )
);

check(
    'P2-4 标签读路径全部按站点收敛（{cms:tags} / 前台标签页 / sitemap / 别名解析）',
    str_contains(
        (string) file_get_contents(__DIR__ . '/../service/tag/TagQueryService.php'),
        "Tag::where('site_id', \$siteId)"
    )
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../controller/index/Tag.php'),
            "where('site_id', \$this->siteId)"
        )
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../service/seo/SitemapService.php'),
            "Tag::where('site_id', \$siteId)"
        )
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../service/url/UrlService.php'),
            "(int) (\$t['site_id'] ?? 0)"
        )
        // 别名解析三类实体（category / page / tag）都带站点校验
        && substr_count(
            (string) file_get_contents(__DIR__ . '/../service/url/UrlService.php'),
            '(int) $entity->site_id !== $siteId'
        ) >= 3
);

check(
    'P2-4 跨站复制在目标站点重建标签 + 删站级联清理标签',
    str_contains(
        (string) file_get_contents(__DIR__ . '/../service/site/SiteService.php'),
        'findOrCreate($targetSiteId'
    )
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../service/site/SiteService.php'),
            "Tag::where('site_id', \$id)->delete()"
        )
);

check(
    'P2-4 存量升级命令已交付并注册（wisecms:tag:site）',
    file_exists(__DIR__ . '/../command/TagSiteCommand.php')
        && str_contains((string) file_get_contents(__DIR__ . '/../config/console.php'), 'TagSiteCommand')
        && str_contains((string) file_get_contents(__DIR__ . '/../command/TagSiteCommand.php'), '--dry-run')
);

check(
    'P2-4 Tag 模型不再提供全站口径的历史方法（getHot / updateCount）',
    !str_contains((string) file_get_contents(__DIR__ . '/../model/Tag.php'), 'function getHot')
        && !str_contains((string) file_get_contents(__DIR__ . '/../model/Tag.php'), 'function updateCount')
);

// makeAlias 第 2 参是 siteId、第 3 参才是 excludeId：调用点漏传站点的写法必须被挡住
check(
    'P2-4 makeAlias 调用点均按站点去重（防「把 id 当站点传」的静默错值）',
    str_contains(
        (string) file_get_contents(__DIR__ . '/../command/TagAliasCommand.php'),
        'TagService::makeAlias((string) $tag->name, $siteId, $lastId)'
    )
        && !preg_match('/makeAlias\([^)]*,\s*\$lastId\s*\)/', (string) file_get_contents(__DIR__ . '/../command/TagAliasCommand.php'))
);

// ---------- UIUX-P2-06：系统工具页（刷新 CMS 缓存） ----------

$systemControllerSource = (string) file_get_contents(__DIR__ . '/../controller/System.php');
$systemViewSource       = (string) file_get_contents(__DIR__ . '/../view/admin/system/index.html');
$systemJsSource         = (string) file_get_contents(__DIR__ . '/../statics/js/system.js');

check(
    'P2-06 System 控制器：index 视图 + flushCache 走 CacheService::flush（不整体清缓存）',
    str_contains($systemControllerSource, 'function index(')
        && str_contains($systemControllerSource, 'function flushCache(')
        && str_contains($systemControllerSource, '(new CacheService())->flush()')
        // 🚨 不得调用 CacheFacade::clear()（会清空整个缓存存储、波及其它插件与框架，审计 L-14）
        && !str_contains($systemControllerSource, 'CacheFacade::clear()')
);

check(
    'P2-06 system 两条路由已登记（GET index / POST flushCache）',
    str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::get('system/index'")
        && str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::post('system/flushCache'")
);

check(
    'P2-06 系统工具页：菜单 + 权限 ident + 视图按钮 + 前端控制器（源/发布双份）',
    str_contains((string) file_get_contents(__DIR__ . '/../manifest.json'), '/plugin/wisecms/system/index')
        && ($manifestPermissions['plugin.wisecms.action.system'] ?? '') === '/plugin/wisecms/system/*'
        && str_contains($systemViewSource, 'id="btn-flush-cache"')
        // 系统页是**全站**操作，刻意不挂站点条（挂了会误导「只刷当前站点」）
        && !str_contains($systemViewSource, 'admin/common/site_switch')
        && str_contains($systemJsSource, "cmsUrl('system/flushCache')")
        && is_file(__DIR__ . '/../../../public/statics/plugins/wisecms/js/system.js')
);

// ---------- UIUX-P2-08：暗色 / 响应式回归中修的两个真实问题 ----------

// 🚨 多对多中间表模型必须继承 think\model\Pivot，否则 BelongsToMany::newPivot() 抛
//    「pivot model must extends: \think\model\Pivot」→ Content::with(['tags'])
//    （后台内容编辑 / 前台内容详情）直接 500。P2-08 回归实测发现。
check(
    'P2-08 TagRelation 必须继承 think\model\Pivot（否则 content/edit 与前台详情 500）',
    str_contains((string) file_get_contents(__DIR__ . '/../model/TagRelation.php'), 'extends Pivot')
        && str_contains((string) file_get_contents(__DIR__ . '/../model/TagRelation.php'), 'use think\\model\\Pivot;')
        // 关系声明确实引用了它（防止有人把 pivot 类换回普通模型）
        && str_contains((string) file_get_contents(__DIR__ . '/../model/Content.php'), 'TagRelation::class')
);

// 看板此前没有页面控制器 → wise-bootstrap 动态 import 403 + Console 报错；趋势由内联 script 渲染
$dashboardJsSource   = (string) file_get_contents(__DIR__ . '/../statics/js/dashboard.js');
$dashboardViewSource = (string) file_get_contents(__DIR__ . '/../view/admin/dashboard/index.html');

check(
    'P2-08 数据看板补齐页面控制器（源/发布双份）且视图零内联脚本',
    is_file(__DIR__ . '/../statics/js/dashboard.js')
        && is_file(__DIR__ . '/../../../public/statics/plugins/wisecms/js/dashboard.js')
        && str_contains($dashboardJsSource, 'data-trend')
        && !str_contains($dashboardViewSource, '<script')
);

// ---------- UIUX-P2-05：孤儿数据清理（系统工具页，L3） ----------

$systemJsSource = (string) file_get_contents(__DIR__ . '/../statics/js/system.js');

check(
    'P2-05 System 提供孤儿扫描（dry-run）与清理两个动作 + 路由已登记',
    str_contains($systemControllerSource, 'function orphans(')
        && str_contains($systemControllerSource, 'function purgeOrphans(')
        // 扫描走 service 的 dry-run 分支（只统计不写入）
        && str_contains($systemControllerSource, '(new ContentRelationService())->purgeOrphans(true)')
        && str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::get('system/orphans'")
        && str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::post('system/purgeOrphans'")
);

check(
    'P2-05 孤儿清理为 L3：扫描前置 + 逐字输入确认词 DELETE',
    str_contains($systemJsSource, 'cmsDangerConfirm({')
        && str_contains($systemJsSource, "PURGE_WORD = 'DELETE'")
        // 未扫描 / 无待清理项时不允许直接清理（先预览再执行）
        && str_contains($systemJsSource, 'if (!scanned || pending <= 0)')
        // 视图：两个按钮都在，且清理按钮默认禁用
        && str_contains($systemViewSource, 'id="btn-scan-orphans"')
        && str_contains($systemViewSource, 'id="btn-purge-orphans"')
        && preg_match('/id="btn-purge-orphans"[^>]*disabled/', $systemViewSource) === 1
);

check(
    'P2-05 孤儿表可读名映射收敛在 Service（视图与 CLI 都不硬编码表名清单）',
    str_contains((string) file_get_contents(__DIR__ . '/../service/content/ContentRelationService.php'), 'ORPHAN_LABELS')
        && str_contains((string) file_get_contents(__DIR__ . '/../service/content/ContentRelationService.php'), 'function labelOf')
        && str_contains($systemControllerSource, 'ContentRelationService::labelOf(')
);

// ---------- UIUX-P2-03：内容模板管理页（TemplateService 的入口补齐） ----------

$templateControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Template.php');
$templateRouteSource      = (string) file_get_contents(__DIR__ . '/../route/plugin.php');
$templateServiceSource    = (string) file_get_contents(__DIR__ . '/../service/content/TemplateService.php');
$templateJsSource         = (string) file_get_contents(__DIR__ . '/../statics/js/template.js');

check(
    'P2-03 模板 7 条路由已登记（index + create/edit 双向 + delete + installDefaults）',
    str_contains($templateRouteSource, "Route::get('template/index'")
        && str_contains($templateRouteSource, "Route::get('template/create'")
        && str_contains($templateRouteSource, "Route::post('template/create'")
        && str_contains($templateRouteSource, "Route::get('template/edit'")
        && str_contains($templateRouteSource, "Route::post('template/edit'")
        && str_contains($templateRouteSource, "Route::post('template/delete'")
        && str_contains($templateRouteSource, "Route::post('template/installDefaults'")
);

check(
    'P2-03 控制器 5 个动作 + 视图/前端控制器（源/发布双份）就位',
    str_contains($templateControllerSource, 'public function index(')
        && str_contains($templateControllerSource, 'public function create(')
        && str_contains($templateControllerSource, 'public function edit(')
        && str_contains($templateControllerSource, 'public function delete(')
        && str_contains($templateControllerSource, 'public function installDefaults(')
        && is_file(__DIR__ . '/../view/admin/template/index.html')
        && is_file(__DIR__ . '/../view/admin/template/create.html')
        && is_file(__DIR__ . '/../view/admin/template/edit.html')
        && is_file(__DIR__ . '/../../../public/statics/plugins/wisecms/js/template.js')
);

check(
    'P2-03 服务层补齐 update / delete / getPage / assertAliasUnique（原只有 create）',
    str_contains($templateServiceSource, 'public function update(')
        && str_contains($templateServiceSource, 'public function delete(')
        && str_contains($templateServiceSource, 'public function getPage(')
        && str_contains($templateServiceSource, 'public function assertAliasUnique(')
);

// alias 有唯一索引：不查重会直接抛 DB 异常（500 + 不可读报错）
check(
    'P2-03 唯一性/JSON 校验前置（不裸奔到 DB）',
    str_contains($templateControllerSource, 'assertAliasUnique(')
        && str_contains($templateServiceSource, 'ErrorCode::DUPLICATE')
        && str_contains($templateControllerSource, 'json_last_error()')
);

// 无消费方的列不进表单：schema / icon（grep 全插件零读取点）
check(
    'P2-03 可写字段白名单不含无消费方列（schema / icon）',
    str_contains($templateControllerSource, "const EDITABLE_FIELDS = ['name', 'alias', 'type', 'category', 'sort', 'status', 'presets', 'seo_defaults'];")
);

check(
    'P2-03 is_system 为服务端托管列（表单不可自称「内置模板」）',
    str_contains((string) file_get_contents(__DIR__ . '/../service/model/MassAssignmentGuard.php'), "['is_system']")
        && str_contains((string) file_get_contents(__DIR__ . '/../service/model/MassAssignmentGuard.php'), "'wisecms_template'")
);

check(
    'P2-03 菜单 + 权限 ident 已登记（action.template → /plugin/wisecms/template/*）',
    str_contains((string) file_get_contents(__DIR__ . '/../manifest.json'), '/plugin/wisecms/template/index')
        && ($manifestPermissions['plugin.wisecms.action.template'] ?? '') === '/plugin/wisecms/template/*'
);

// 硬删除、无软删 → 前端不得传 restoreUrl（撤销必然失败 = 假交互）
check(
    'P2-03 删除不传 restoreUrl（模板表无软删，不造假撤销）',
    str_contains($templateJsSource, 'cmsDelete({')
        && !str_contains($templateJsSource, 'restoreUrl')
        && str_contains($templateJsSource, "confirmMessage: '确定永久删除模板「'")
);

// 🚨 内核渲染表格时会对单元格值做 HTML 转义（EnhancedTable.DEFAULTS.escape = true）
//    → 直接 JSON.parse 必失败，表现为「明明配了预设却恒显示 —」（实测踩过）
check(
    'P2-03 列表 JSON 计数先 cmsDecodeEntities（否则徽章恒为 —）',
    preg_match('/function jsonKeyCount[\s\S]{0,500}cmsDecodeEntities\(/', $templateJsSource) === 1
        && str_contains($templateJsSource, 'cmsDecodeEntities')
);

// ---------- UIUX-P2-02：内容质量评分面板（QualityScorer 的入口补齐） ----------

$contentControllerSource = (string) file_get_contents(__DIR__ . '/../controller/Content.php');
$qualityScorerSource     = (string) file_get_contents(__DIR__ . '/../service/engine/QualityScorer.php');

check(
    'P2-02 content/quality 路由 + 控制器动作（QualityScorer 此前全插件无后台入口）',
    str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::get('content/quality'")
        && str_contains($contentControllerSource, 'public function quality(')
        && str_contains($contentControllerSource, '(new QualityScorer())->scoreAndSave(')
        // 越界防护：不得给「别的站点」的内容评分
        && str_contains($contentControllerSource, '$this->ownsContent($content)')
);

check(
    'P2-02 编辑/发布页「质量」Tab（导航 + 锚点 + 面板）+ 列表质量列 + JS 检测逻辑',
    str_contains($contentEditHtmlSource, 'tab-quality')
        && str_contains($contentEditHtmlSource, 'data-tab="tab-quality"')
        && str_contains($contentEditHtmlSource, 'quality-score-panel')
        && str_contains($contentCreateHtmlSource, 'tab-quality')
        && str_contains($contentJsSrc, "quality: cmsUrl('content/quality')")
        && str_contains($contentJsSrc, 'function renderQualityScore')
        && str_contains($contentJsSrc, "field: 'quality_score'")
        && str_contains($contentJsSrc, "'#btn-quality-audit'")
);

check(
    'P2-02 评分结果带维度中文名（来自各 Dimension::getName()，前端不再维护英文键映射）',
    str_contains($qualityScorerSource, '=> $dim->getName(),')
        && str_contains($contentJsSrc, 'dim.name || key')
);

// 自动发现：本版 think-orm 的 Model/Query **都没有** `updateOrCreate()`
// （`QualityScorer::scoreAndSave()` 曾用它 → 接上入口后立刻 500，见任务清单 §1.1㊹⑦）
$ormBadHits = [];
$ormScanFiles = array_merge(
    glob(__DIR__ . '/../service/*/*.php') ?: [],
    glob(__DIR__ . '/../service/*.php') ?: [],
    glob(__DIR__ . '/../controller/*.php') ?: [],
    glob(__DIR__ . '/../model/*.php') ?: [],
    glob(__DIR__ . '/../command/*.php') ?: []
);
foreach ($ormScanFiles as $ormFile) {
    if (preg_match('/\w+::updateOrCreate\s*\(/', (string) file_get_contents($ormFile)) === 1) {
        $ormBadHits[] = basename($ormFile);
    }
}
check(
    'P2-02 不得调用本版 think-orm 不存在的 updateOrCreate()（调用即 500）',
    $ormBadHits === [],
    implode(', ', $ormBadHits)
);

// 自动发现：`Query::bind()` 只接受**数组**（`ParamsBind::bind(array $value)`），
// 旧的「bind('键', 值)」两参写法会抛 TypeError（前台搜索曾因此全程不可用，P2-04 发现）
$bindBadHits = [];
foreach ($ormScanFiles as $bindFile) {
    if (preg_match('/->bind\(\s*[\'"][^\'"]+[\'"]\s*,/', (string) file_get_contents($bindFile)) === 1) {
        $bindBadHits[] = basename($bindFile);
    }
}
check(
    'P2-04 Query::bind() 必须传数组（两参写法会抛 TypeError）',
    $bindBadHits === [],
    implode(', ', $bindBadHits)
);

// ---------- UIUX-P2-04：搜索日志 / 热搜词（只读页） ----------

$searchLogControllerSource = (string) file_get_contents(__DIR__ . '/../controller/SearchLog.php');
$searchLogViewSource       = (string) file_get_contents(__DIR__ . '/../view/admin/searchLog/index.html');
$searchLogJsSource         = (string) file_get_contents(__DIR__ . '/../statics/js/search_log.js');
$searchServiceSource       = (string) file_get_contents(__DIR__ . '/../service/content/SearchService.php');

check(
    'P2-04 搜索日志两条路由 + 控制器动作（index/hot），且保持只读',
    str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::get('searchLog/index'")
        && str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::get('searchLog/hot'")
        && str_contains($searchLogControllerSource, 'public function index(')
        && str_contains($searchLogControllerSource, 'public function hot(')
        // 只读页：不得出现写动作
        && !str_contains($searchLogControllerSource, 'public function delete(')
        && !str_contains($searchLogControllerSource, 'public function update(')
);

check(
    'P2-04 日志与热词都按「当前站点」过滤（服务层 getLogPage + 控制器传 site_id）',
    str_contains($searchServiceSource, 'public function getLogPage(')
        && str_contains($searchServiceSource, "whereLike('keyword'")
        && str_contains($searchLogControllerSource, 'getHotKeywords($limit, $this->siteId())')
        && str_contains($searchLogControllerSource, "'site_id' => \$siteId,")
        && is_file(__DIR__ . '/../../../public/statics/plugins/wisecms/js/search_log.js')
);

check(
    'P2-04 「不支持置顶热词」如实说明（无假按钮）+ 站点切换局部刷新',
    str_contains($searchLogViewSource, '不支持手动置顶')
        && !str_contains($searchLogViewSource, 'pinned')
        && str_contains($searchLogJsSource, 'cmsOnSiteSwitched')
        && str_contains($searchLogJsSource, 'function renderHot')
);

// 表有 result_count 列，但原实现在搜索**之前**写死 0 → 该列毫无意义
check(
    'P2-04 搜索日志记录真实结果数（原写死 0）',
    str_contains($searchServiceSource, 'int $resultCount = 0')
        && str_contains($searchServiceSource, "'result_count' => max(0, \$resultCount)")
        && str_contains($searchServiceSource, "(int) (\$result['total'] ?? 0)")
);

// 自动发现：`siteId()` **不是** BaseController 的方法（各控制器自带一份），
// 漏定义就 `Call to undefined method ...::siteId()` → 500（P2-04 实测踩过）
$siteIdMissing = [];
foreach (glob(__DIR__ . '/../controller/*.php') ?: [] as $ctrlFile) {
    $ctrlSrc = (string) file_get_contents($ctrlFile);
    if (str_contains($ctrlSrc, '$this->siteId()') && !str_contains($ctrlSrc, 'function siteId(')) {
        $siteIdMissing[] = basename($ctrlFile);
    }
}
check(
    'P2-04 用到 $this->siteId() 的控制器必须自带 siteId()（基类无此方法，漏定义即 500）',
    $siteIdMissing === [],
    implode(', ', $siteIdMissing)
);

check(
    'P2-04 菜单 + 权限 ident 已登记（action.searchLog → /plugin/wisecms/searchLog/*）',
    str_contains((string) file_get_contents(__DIR__ . '/../manifest.json'), '/plugin/wisecms/searchLog/index')
        && ($manifestPermissions['plugin.wisecms.action.searchLog'] ?? '') === '/plugin/wisecms/searchLog/*'
);

// ---------- UIUX-P2-01：工作台增强（趋势区间 / 待办 / 卡片下钻） ----------

$dashboardCtrlSrc = (string) file_get_contents(__DIR__ . '/../controller/Dashboard.php');
$dashboardJsSrc2  = (string) file_get_contents(__DIR__ . '/../statics/js/dashboard.js');
$analyticsSrc     = (string) file_get_contents(__DIR__ . '/../service/integration/AnalyticsService.php');

check(
    'P2-01 趋势区间 7/30/90 白名单收敛（非白名单回落首项，防 ?days=100000）',
    str_contains($dashboardCtrlSrc, 'const TREND_DAYS = [7, 30, 90]')
        && str_contains($dashboardCtrlSrc, 'protected function normalizeDays(')
        && str_contains($dashboardCtrlSrc, 'in_array($days, self::TREND_DAYS, true)')
);

check(
    'P2-01 四张统计卡都是下钻链接（已发布/草稿/按浏览量排序）且排序参数真被转发',
    str_contains($dashboardCtrlSrc, "plugin_url('wisecms/content/index'")
        && str_contains($dashboardCtrlSrc, "['status' => ContentService::STATUS_PUBLISHED]")
        && str_contains($dashboardCtrlSrc, "['status' => ContentService::STATUS_DRAFT]")
        && str_contains($dashboardCtrlSrc, "['order' => 'view_count desc']")
        // 下钻排序要真生效：内容列表控制器必须转发 order（原实现未转发 → 静默无效）
        && str_contains($contentControllerSource, "'order'     => (string) \$this->request->param('order', '')")
);

check(
    'P2-01 待办清单只列有真实入口的项（待审内容 / 待审评论 / 草稿 / 回收站）',
    str_contains($dashboardCtrlSrc, 'protected function todos(')
        && str_contains($dashboardCtrlSrc, "plugin_url('wisecms/comment/index', ['status' => CommentService::STATUS_PENDING])")
        && str_contains($dashboardCtrlSrc, "plugin_url('wisecms/recycle/index')")
);

check(
    'P2-01 前端：区间切换与切站都只局部重取（禁止整页 reload）',
    is_file(__DIR__ . '/../../../public/statics/plugins/wisecms/js/dashboard.js')
        && str_contains($dashboardJsSrc2, "cmsUrl('dashboard/index')")
        && str_contains($dashboardJsSrc2, 'cmsOnSiteSwitched')
        && str_contains($dashboardJsSrc2, 'function renderTrend')
        && str_contains($dashboardJsSrc2, 'function renderTop')
        && !str_contains($dashboardJsSrc2, 'location.reload')
);

check(
    'P2-01 趋势查询由「每天一条 COUNT」收敛为单次 GROUP BY',
    str_contains($analyticsSrc, "fieldRaw('DATE(create_time) AS d, COUNT(*) AS c')")
        && str_contains($analyticsSrc, "->group('d')")
);

// 同一看板上卡片 / Top10 都排除回收站，趋势若漏了就会多算 → 数字对不上
check(
    'P2-01 趋势与卡片口径一致（同样排除回收站内容）',
    preg_match('/getTrendData[\s\S]{0,1200}whereNull\(\'delete_time\'\)/', $analyticsSrc) === 1
);

check(
    'P2-01 Top10 下发前精简列（不把正文长文本塞进 data 属性）',
    str_contains($dashboardCtrlSrc, 'protected function slimTop(')
        && str_contains($dashboardCtrlSrc, 'json_encode($this->slimTop($topContents)')
);

// ---------- UIUX-P1-06：正文接 wiseeditor（富文本编辑器接入） ----------

$c106CtrlSrc  = (string) file_get_contents(__DIR__ . '/../controller/Content.php');
$c106PageSrc  = (string) file_get_contents(__DIR__ . '/../controller/Page.php');
$c106ViewSrc  = (string) file_get_contents(__DIR__ . '/../view/admin/content/edit.html');
$c106JsSrc    = (string) file_get_contents(__DIR__ . '/../statics/js/content.js');
$c106ReqSrc   = (string) file_get_contents(__DIR__ . '/../../../app/Request.php');
$c106Attach   = (string) file_get_contents(__DIR__ . '/../service/content/AttachmentService.php');

// 🚨 这是本任务最关键的一条：框架 `app/Request::applySecurityFilter()` 对**未登记**字段
//    一律 `strip_tags()`（app/Request.php:182）→ 富文本必须在读请求体**之前**登记，
//    否则表现为「编辑器里好好的，保存后只剩纯文本」（实测踩过）。
check(
    'P1-06 富文本字段必须先 registerHtmlField（否则入库前被 strip_tags 剥成纯文本）',
    str_contains($c106ReqSrc, 'return strip_tags($value);')
        && str_contains($c106CtrlSrc, "registerHtmlField(['content'])")
        && str_contains($c106PageSrc, "registerHtmlField(['content'])")
        // 自定义富文本字段名由模型动态决定 → 按 model_id 现查现登记
        && str_contains($c106CtrlSrc, 'protected function registerModelRichFields(')
        && str_contains($c106CtrlSrc, 'registerMarkdownField($markdownFields)')
        && str_contains($c106CtrlSrc, 'registerModelRichFields($this->resolveRequestModelId())')
        && str_contains($c106CtrlSrc, 'registerModelRichFields((int) $content->model_id)')
        && str_contains($c106CtrlSrc, 'registerModelRichFields($this->resolveRequestModelId($id))')
);

check(
    'P1-06 正文由 EditorManager 渲染（未启用编辑器自动回退 textarea），模板零 <script>',
    str_contains($c106CtrlSrc, 'protected function assignBodyEditor(')
        && str_contains($c106CtrlSrc, "app('editor.manager')")
        && str_contains($c106CtrlSrc, "manager->render('content'")
        && str_contains($c106CtrlSrc, 'editor_content_input')
        // 静态资源由 PHP 拼进 HTML（模板不得出现 <script>）
        && str_contains($c106CtrlSrc, 'protected function editorAssetTags(')
        && str_contains($c106ViewSrc, '{$bodyEditorHtml|raw}')
        && !str_contains($c106ViewSrc, '<script')
);

check(
    'P1-06 内链插入：只出本站已发布内容 + 绝对 URL 走 UrlService + 光标处插入',
    str_contains($c106CtrlSrc, 'public function linkOptions()')
        && str_contains($c106CtrlSrc, 'ContentService::STATUS_PUBLISHED')
        && str_contains($c106CtrlSrc, '$urlService->absolute($urlService->contentPath($row))')
        && str_contains((string) file_get_contents(__DIR__ . '/../route/plugin.php'), "Route::get('content/linkOptions'")
        && str_contains($c106JsSrc, 'function openInternalLinkPicker')
        && str_contains($c106JsSrc, 'function insertIntoBody')
        && str_contains($c106JsSrc, 'insertContent(html)')
);

check(
    'P1-06 字数统计 + 编辑器改动回灌「计脏 / 自动草稿」（隐藏域赋值不冒泡 input）',
    str_contains($c106JsSrc, 'function initBodyWordCount')
        && str_contains($c106JsSrc, 'body-word-count')
        && str_contains($c106JsSrc, '$holder.trigger(' . "'input'" . ')')
        && str_contains($c106JsSrc, 'initBodyEditorUi();')
);

check(
    'P1-06 远程图片本地化收敛到 AttachmentService（正文与编辑器字段共用，默认关闭）',
    str_contains($c106Attach, 'public function localizeRemoteImages(')
        && str_contains((string) file_get_contents(__DIR__ . '/../field/editor/Input.php'), "app('wisecms.attach')->localizeRemoteImages(")
        && str_contains((string) file_get_contents(__DIR__ . '/../service/content/ContentService.php'), 'localizeRemoteImages($html)')
        && str_contains((string) file_get_contents(__DIR__ . '/../manifest.json'), '"save_remote_image"')
);

// 「分页标记」当前**没有任何消费方**（前台模板不解析任何分页标记）→ 刻意不做，
// 守卫此决策：一旦将来前台支持了分页标记，这条断言会失败并提醒实现插入按钮。
check(
    'P1-06 分页标记暂不实现（全插件无消费方，不做死功能）',
    !str_contains($c106CtrlSrc, 'pagebreak')
        && !str_contains($c106JsSrc, 'pagebreak')
);

// ---------- UIUX-V-03 复跑：页面请求缺参数的兜底（裸 JSON / 500） ----------

$pageFallbackSrc = (string) file_get_contents(__DIR__ . '/../controller/PageFallbackTrait.php');

check(
    'V-03 页面请求缺参数不再渲染裸 JSON（PageFallbackTrait：AJAX 保 JSON / 页面 302 回列表）',
    str_contains($pageFallbackSrc, 'trait PageFallbackTrait')
        && str_contains($pageFallbackSrc, '$this->request->isAjax()')
        && str_contains($pageFallbackSrc, 'return redirect((string) $backUrl);')
        && str_contains($pageFallbackSrc, 'return $this->error($message);')
);

// 自动发现：`edit($id)` 把 id 声明为**必填**时，无 ?id 的页面请求会抛
// ArgumentCountError → 500（UIUX-V-03 复跑实测 modelField/model 两处）
$requiredIdHits = [];
foreach (glob(__DIR__ . '/../controller/*.php') ?: [] as $ctrlFile) {
    if (basename($ctrlFile) === 'PageFallbackTrait.php') {
        continue;
    }
    if (preg_match('/public function edit\(\$id\)/', (string) file_get_contents($ctrlFile)) === 1) {
        $requiredIdHits[] = basename($ctrlFile);
    }
}
check(
    'V-03 edit() 的 $id 不得声明为必填（无 ?id 直访会 500）',
    $requiredIdHits === [],
    implode(', ', $requiredIdHits)
);

// 自动发现：`assertSiteOwned()` 是抛异常式校验，调用点必须 catch（否则页面请求 500）
check(
    'V-03 Redirect::edit 捕获 assertSiteOwned 异常（原漏 catch → 无 ?id 直访 500）',
    preg_match('/function edit\(\)[\s\S]{0,700}catch \(WisecmsException \$e\)/', (string) file_get_contents(__DIR__ . '/../controller/Redirect.php')) === 1
);

// 接入面：13 个页面控制器都要带 trait（漏一个该页就退回裸 JSON）
$traitAdopted = 0;
foreach (glob(__DIR__ . '/../controller/*.php') ?: [] as $ctrlFile) {
    if (str_contains((string) file_get_contents($ctrlFile), 'use PageFallbackTrait;')) {
        $traitAdopted++;
    }
}
check(
    'V-03 页面控制器统一接入 PageFallbackTrait（≥12 个）',
    $traitAdopted >= 12,
    '接入数=' . $traitAdopted
);

check(
    'V-03 缺参数分支已改用 pageFallback（不再是裸 this->error）',
    str_contains($contentControllerSource, "pageFallback('内容不存在'")
        && str_contains($templateControllerSource, "pageFallback('模板不存在'")
        && str_contains((string) file_get_contents(__DIR__ . '/../controller/Site.php'), "pageFallback('站点不存在'")
        && str_contains((string) file_get_contents(__DIR__ . '/../controller/ModelField.php'), "pageFallback('模型不存在'")
);

// ---------- UIUX-V-05 复跑：L3 高危操作必须带逐字确认词 ----------

// 自动发现：每个 `cmsDangerConfirm({...})` 调用块都必须给出 `word:`（逐字确认词）。
// 以「每文件 word: 数 ≥ cmsDangerConfirm 调用数」为判据（这些文件里 `word:` 只用于此处）。
$dangerCalls = 0;
$dangerWordMissing = [];
foreach (glob(__DIR__ . '/../statics/js/*.js') ?: [] as $jsFile) {
    $jsSrc = (string) file_get_contents($jsFile);
    $calls = substr_count($jsSrc, 'cmsDangerConfirm({');
    if ($calls === 0) {
        continue;
    }
    $words = substr_count($jsSrc, 'word:');
    $dangerCalls += $calls;
    if ($words < $calls) {
        $dangerWordMissing[] = basename($jsFile) . '(' . $calls . '调用/' . $words . '确认词)';
    }
}
check(
    'V-05 每个 L3 高危操作都必须带逐字确认词（word:）+ 调用点不缩水',
    $dangerWordMissing === [] && $dangerCalls >= 10,
    implode(', ', $dangerWordMissing) . ' L3调用数=' . $dangerCalls
);

check(
    'V-05 P2 新增 L3（孤儿数据清理）走 cmsDangerConfirm + DELETE 确认词',
    str_contains($systemJsSource, 'cmsDangerConfirm({')
        && str_contains($systemJsSource, "PURGE_WORD = 'DELETE'")
);

// ===================== 汇总 =====================
echo "\n--------------------------------------------------------------\n";
echo "通过 {$GLOBALS['_pass']} 项，失败 {$GLOBALS['_fail']} 项\n";
if ($GLOBALS['_failures'] !== []) {
    echo "\n失败明细：\n";
    foreach ($GLOBALS['_failures'] as $msg) {
        echo "  - {$msg}\n";
    }
}
echo "--------------------------------------------------------------\n";

if ($GLOBALS['_fail'] > 0 || $GLOBALS['_pass'] === 0) {
    echo "自检未通过。\n";
    exit(1);
}

echo "自检通过。\n";
exit(0);
