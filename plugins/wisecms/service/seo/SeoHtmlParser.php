<?php
namespace plugin\wisecms\service\seo;

/**
 * SEO 检查用 HTML 节点解析器
 *
 * 各 SEO 检查类原先用 `substr_count($body, '<h2')` / `substr_count($body, '<a href=')`
 * 这类子串匹配统计节点，局限性大：
 *   - 无法处理标签大小写（<H2>）、属性空白差异（<a  href = "…">）；
 *   - 属性值内含 `>` 时（如 <img alt="a > b">）正则 `[^>]+` 会截断；
 *   - 会误统计注释 / 字符串中的片段，且无法区分内链 / 外链。
 *
 * 本类统一优先用 DOMDocument + DOMXPath 解析（正确、语义化），
 * DOM 不可用或解析失败时回退到等价正则，保证在无 ext-dom 环境仍可工作。
 */
class SeoHtmlParser
{
    /** 解析缓存（按内容 hash 复用，避免同一审计内 5 个检查重复解析） */
    private static array $cache = [];

    /** 缓存上限，超出则整体清空（长驻进程防内存增长） */
    private static int $cacheLimit = 30;

    /**
     * 解析 HTML 为 DOMDocument（失败返回 null）
     */
    protected static function loadDom(string $html): ?\DOMDocument
    {
        if ($html === '') {
            return null;
        }

        $key = md5($html);
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (!class_exists(\DOMDocument::class)) {
            return self::$cache[$key] = null;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // 用 XML 编码声明包裹，强制按 UTF-8 解析，避免中文乱码
        $ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (count(self::$cache) >= self::$cacheLimit) {
            self::$cache = [];
        }
        self::$cache[$key] = $ok ? $dom : null;

        return self::$cache[$key];
    }

    /**
     * 执行 XPath 查询（DOM 不可用返回 null）
     */
    protected static function query(string $html, string $xpath): ?\DOMNodeList
    {
        $dom = self::loadDom($html);
        if ($dom === null) {
            return null;
        }
        $result = (new \DOMXPath($dom))->query($xpath);
        return $result === false ? null : $result;
    }

    /**
     * 统计 h1-h6 各层级数量
     *
     * @return array<int,int> [1 => n1, 2 => n2, ..., 6 => n6]
     */
    public static function headingCounts(string $html): array
    {
        $counts = array_fill(1, 6, 0);

        $nodes = self::query($html, '//h1|//h2|//h3|//h4|//h5|//h6');
        if ($nodes !== null) {
            foreach ($nodes as $node) {
                $level = (int) substr($node->nodeName, 1);
                if ($level >= 1 && $level <= 6) {
                    $counts[$level]++;
                }
            }
            return $counts;
        }

        // 正则降级：容忍属性与大小写
        if (preg_match_all('/<h([1-6])\b[^>]*>/i', $html, $m)) {
            foreach ($m[1] as $level) {
                $counts[(int) $level]++;
            }
        }
        return $counts;
    }

    /**
     * 统计指定标签数量
     */
    public static function tagCount(string $html, string $tag): int
    {
        $tag = strtolower($tag);
        if (!preg_match('/^[a-z][a-z0-9]*$/', $tag)) {
            return 0;
        }

        $nodes = self::query($html, '//' . $tag);
        if ($nodes !== null) {
            return $nodes->length;
        }

        return (int) preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*>/i', $html);
    }

    /**
     * 统计链接数量
     *
     * @param string $host 为空时「内链」= 全部链接（保持向后兼容）；非空时仅统计相对路径 / 同域链接
     * @return array{total:int, internal:int}
     */
    public static function linkStats(string $html, string $host = ''): array
    {
        $nodes = self::query($html, '//a[@href]');
        if ($nodes !== null) {
            $total = 0;
            $internal = 0;
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $total++;
                if (self::isInternalHref($node->getAttribute('href'), $host)) {
                    $internal++;
                }
            }
            return ['total' => $total, 'internal' => $internal];
        }

        // 正则降级：href 支持单/双引号、任意空白
        $total = 0;
        $internal = 0;
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            foreach ($m[2] as $href) {
                $total++;
                if (self::isInternalHref($href, $host)) {
                    $internal++;
                }
            }
        }
        return ['total' => $total, 'internal' => $internal];
    }

    /**
     * 统计图片总数与缺少有效 alt 的数量
     *
     * @return array{total:int, missing:int}
     */
    public static function imageAltStats(string $html): array
    {
        $nodes = self::query($html, '//img');
        if ($nodes !== null) {
            $total = 0;
            $missing = 0;
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $total++;
                if (trim($node->getAttribute('alt')) === '') {
                    $missing++;
                }
            }
            return ['total' => $total, 'missing' => $missing];
        }

        // 正则降级
        $total = (int) preg_match_all('/<img\b[^>]*>/i', $html);
        $withAlt = (int) preg_match_all('/<img\b[^>]*\salt\s*=\s*(["\'])[^"\']*\1[^>]*>/i', $html);
        return ['total' => $total, 'missing' => max(0, $total - $withAlt)];
    }

    /**
     * 提取正文中第一张图片的 src（用于 OG:Image 兜底）
     */
    public static function firstImageSrc(string $html): string
    {
        $nodes = self::query($html, '//img[@src]');
        if ($nodes !== null) {
            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement) {
                    $src = trim($node->getAttribute('src'));
                    if ($src !== '') {
                        return $src;
                    }
                }
            }
            return '';
        }

        // 正则降级
        if (preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
            return trim($m[2]);
        }
        return '';
    }

    /**
     * 提取纯文本（剔除 script/style 内容，压缩空白）
     *
     * 不依赖 DOM，避免对缓存 DOM 造成副作用。
     */
    public static function textContent(string $html): string
    {
        // 先移除 script / style 块（含其文本内容）
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        // 标签转空格，避免相邻文本粘连
        $text = (string) preg_replace('#<[^>]+>#', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * 判断是否为内链（相对路径 / 同域绝对链接）；$host 为空时把全部链接视为内链
     */
    protected static function isInternalHref(string $href, string $host): bool
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return false;
        }

        $lower = strtolower($href);
        foreach (['mailto:', 'tel:', 'javascript:', 'sms:', 'data:'] as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return false;
            }
        }

        // 相对路径（无 scheme://）视为内链
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $href)) {
            return true;
        }

        // 未提供站点域名时，绝对链接一律计入（保持原行为，避免误判为 0）
        if ($host === '') {
            return true;
        }

        $h = parse_url($href, PHP_URL_HOST);
        return is_string($h) && strcasecmp($h, $host) === 0;
    }
}
