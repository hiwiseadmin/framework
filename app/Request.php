<?php
namespace app;

class Request extends \think\Request
{
    protected $filter = [];

    /**
     * 允许携带 HTML 的字段名（富文本字段）
     * @var array
     */
    protected $htmlAllowedFields = [];

    /**
     * 允许携带 Markdown 的字段名
     * @var array
     */
    protected $markdownFields = [];

    /**
     * HTMLPurifier 单例
     * @var \HTMLPurifier|null
     */
    protected static $purifier = null;

    /**
     * 合并后的白名单缓存（基线 ∪ 编辑器驱动），实例级以避免跨请求污染
     * @var array<string, string[]>|null
     */
    protected $allowedCache = null;

    /**
     * 基础 HTML 白名单：无编辑器插件时等价于原硬编码配置。
     * 标签内属性用 | 分隔，遵循 HTMLPurifier 的 HTML.Allowed 语法。
     */
    protected const HTML_ALLOWED_BASELINE =
        'div[class|style|data-type],p[style|align],span[style],'
        . 'mark[data-color|style],'
        . 'b,strong,i,em,u,s,del,'
        . 'a[href|title|target|rel],'
        . 'ul,ol,li,br,hr,'
        . 'img[src|alt|width|height|style|loading],'
        . 'h1,h2,h3,h4,h5,h6,'
        . 'table,thead,tbody,tr,th,td[colspan|rowspan],'
        . 'blockquote,code,pre,'
        . 'figure,figcaption,'
        . 'video[src|controls|width|height|poster|autoplay|loop|muted],'
        . 'audio[src|controls|autoplay|loop|muted],'
        . 'source[src|type],'
        . 'iframe[src|width|height|frameborder|allowfullscreen|allow],'
        . 'details,summary';

    /**
     * 注册富文本字段
     * @param string|array $fields
     * @return $this
     */
    public function registerHtmlField($fields)
    {
        $fields = (array) $fields;
        $this->htmlAllowedFields = array_unique(array_merge($this->htmlAllowedFields, $fields));
        return $this;
    }

    /**
     * 注册 Markdown 字段
     *
     * Markdown 是纯文本，不能走 HTMLPurifier（会被转义、破坏语法），
     * 因此单独处理：只清理危险标签与危险属性/协议。
     *
     * @param string|array $fields
     * @return $this
     */
    public function registerMarkdownField($fields)
    {
        $fields = (array) $fields;
        $this->markdownFields = array_unique(array_merge($this->markdownFields, $fields));
        return $this;
    }

    /**
     * 重写 param，完全兼容父类签名（$name 可为 string|array）
     * @param string|array $name
     * @param mixed $default 默认值（可选）
     * @param string $filter 附加过滤（可选，会与默认过滤组合）
     * @return mixed
     */
    public function param($name = '', $default = null, $filter = '')
    {
        $value = parent::param($name, $default, $filter);
        return $this->autoFilter($name, $value, $filter);
    }

    /**
     * 重写 get，签名同父类
     * @param string|array $name
     * @param mixed $default 默认值（可选）
     * @param string $filter 附加过滤（可选，会与默认过滤组合）
     * @return mixed
     */
    public function get($name = '', $default = null, $filter = '')
    {
        $value = parent::get($name, $default, $filter);
        return $this->autoFilter($name, $value, $filter);
    }

    /**
     * 重写 post，签名同父类
     * @param string|array $name
     * @param mixed $default 默认值（可选）
     * @param string $filter 附加过滤（可选，会与默认过滤组合）
     * @return mixed
     */
    public function post($name = '', $default = null, $filter = '')
    {
        $value = parent::post($name, $default, $filter);
        return $this->autoFilter($name, $value, $filter);
    }

    /**
     * 获取未经过安全过滤的原始值（保留父类数组参数能力）
     * @param string|array $name
     * @param mixed $default 默认值（可选）
     * @return mixed
     */
    public function raw($name, $default = null)
    {
        return parent::param($name, $default, '');
    }

    /**
     * 自动应用安全过滤
     * @param string|array $name
     * @param mixed $value 父类返回的原始值（已应用用户传入的 $filter）
     * @param string $filter 附加过滤（可选，会与默认过滤组合）
     * @return mixed
     */
    protected function autoFilter($name, $value, $filter = '')
    {
        if (is_array($value)) {
            return $this->deepFilter($value, $filter);
        }

        if (is_string($name) && $name !== '') {
            return $this->applySecurityFilter($name, $value, $filter);
        }

        return $value;
    }

    /**
     * 单字段安全过滤（区分普通文本/富文本）
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    protected function applySecurityFilter(string $name, $value, $filter = '')
    {
        if (!is_scalar($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($this->isMarkdownField($name)) {
            return $this->sanitizeMarkdown($value);
        }

        if ($this->isHtmlAllowedField($name)) {
            return $this->htmlPurify($value);
        }

        $filters = explode(',', $filter);
        if (!in_array('strip_tags', $filters)) {
            return strip_tags($value);
        }

        return $value;
    }

    /**
     * 递归过滤整个参数数组
     * @param array $data
     * @param string $filter 附加过滤（可选，会与默认过滤组合）
     * @return array
     */
    protected function deepFilter(array $data, $filter = ''): array
    {
        foreach ($data as $key => &$val) {
            if (is_array($val)) {
                $val = $this->deepFilter($val, $filter);
            } elseif (is_scalar($val)) {
                $val = $this->applySecurityFilter((string) $key, $val, $filter);
            }
        }
        unset($val);
        return $data;
    }

    /**
     * 判断字段名是否允许富文本（支持点语法末级匹配）
     * @param string $name
     * @return bool
     */
    protected function isHtmlAllowedField(string $name): bool
    {
        if (in_array($name, $this->htmlAllowedFields, true)) {
            return true;
        }
        if (strpos($name, '.') !== false) {
            $leaf = substr(strrchr($name, '.'), 1);
            return in_array($leaf, $this->htmlAllowedFields, true);
        }
        return false;
    }

    /**
     * HTMLPurifier 净化HTML字符串
     * @param string $html
     * @return string
     */
    protected function htmlPurify(string $html): string
    {
        if (!class_exists('HTMLPurifier')) {
            return $this->sanitizeHtmlFallback($html);
        }

        if (is_null(self::$purifier)) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', $this->buildHtmlAllowed());
            $config->set('CSS.AllowedProperties',
                'font,font-family,font-size,font-weight,font-style,color,background-color,text-align,'
                . 'text-decoration,width,height,margin,margin-left,margin-right,padding,'
                . 'border,border-color,border-style,border-radius,display,vertical-align,'
                . 'line-height,list-style-type,white-space,word-break,overflow,float'
            );
            $config->set('URI.DisableExternalResources', true);
            $config->set('HTML.TargetBlank', true);
            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier->purify($html);
    }

    /**
     * 合并后的白名单映射：基线 ∪ 编辑器驱动
     *
     * @return array<string, string[]> tag => [attr, ...]
     */
    protected function allowedMap(): array
    {
        if ($this->allowedCache !== null) {
            return $this->allowedCache;
        }

        $map = $this->parseAllowed(self::HTML_ALLOWED_BASELINE);

        try {
            $manager = app('editor.manager');
            if ($manager instanceof \app\common\library\EditorManager) {
                $whitelist = $manager->getCombinedHtmlWhitelist();
                foreach (($whitelist['allowed_tags'] ?? []) as $fragment) {
                    [$tag, $attrs] = $this->splitAllowed((string) $fragment);
                    if ($tag === '') {
                        continue;
                    }
                    $map[$tag] = array_values(array_unique(array_merge($map[$tag] ?? [], $attrs)));
                }
            }
        } catch (\Throwable $e) {
        }

        return $this->allowedCache = $map;
    }

    /**
     * 构建 HTMLPurifier 的 HTML.Allowed 配置串
     */
    protected function buildHtmlAllowed(): string
    {
        $fragments = [];
        foreach ($this->allowedMap() as $tag => $attrs) {
            $fragments[] = $attrs ? $tag . '[' . implode('|', $attrs) . ']' : $tag;
        }
        return implode(',', $fragments);
    }

    /**
     * 合并后的纯标签名列表
     * @return string[]
     */
    protected function allowedTagNames(): array
    {
        return array_keys($this->allowedMap());
    }

    /**
     * 合并后的属性名列表（全局并集）
     * @return string[]
     */
    protected function allowedAttributeNames(): array
    {
        $names = [];
        foreach ($this->allowedMap() as $attrs) {
            $names = array_merge($names, $attrs);
        }
        return array_values(array_unique($names));
    }

    /**
     * 解析 HTML.Allowed 串为映射
     * @return array<string, string[]>
     */
    protected function parseAllowed(string $allowed): array
    {
        $map = [];
        foreach (explode(',', $allowed) as $fragment) {
            [$tag, $attrs] = $this->splitAllowed($fragment);
            if ($tag === '') {
                continue;
            }
            $map[$tag] = array_values(array_unique(array_merge($map[$tag] ?? [], $attrs)));
        }
        return $map;
    }

    /**
     * 解析单条白名单片段 'a[href|title]' => ['a', ['href','title']]
     * @return array{0: string, 1: string[]}
     */
    protected function splitAllowed(string $fragment): array
    {
        $fragment = trim($fragment);
        if ($fragment === '') {
            return ['', []];
        }

        if (preg_match('/^([a-zA-Z0-9_*]+)\s*(?:\[([^\]]*)\])?$/', $fragment, $m)) {
            $attrs = [];
            if (isset($m[2]) && $m[2] !== '') {
                $attrs = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $m[2]))));
            }
            return [strtolower($m[1]), $attrs];
        }

        return ['', []];
    }

    /**
     * 无 HTMLPurifier 时的兜底净化
     *
     * 使用合并后的白名单：strip_tags 保留白名单标签，再按属性白名单
     * 逐个清理（含无引号属性），并对 href/src 做协议白名单校验。
     * 比原实现（htmlspecialchars + strip_tags）更贴合富文本场景。
     */
    protected function sanitizeHtmlFallback(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);

        $tags = $this->allowedTagNames();
        if ($tags === []) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $content = strip_tags($html, '<' . implode('><', $tags) . '>');

        $allowedAttrs = $this->allowedAttributeNames();
        if ($allowedAttrs === []) {
            return $content;
        }
        $attrPattern = implode('|', array_map('preg_quote', $allowedAttrs));

        return (string) preg_replace_callback('/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i', function ($matches) use ($attrPattern) {
            return (string) preg_replace_callback(
                '/(\s+)([a-z\-]+)\s*=\s*(["\']?)(.*?)\3(?=[\s\/>]|$)/i',
                function ($attr) use ($attrPattern) {
                    $name = strtolower($attr[2]);
                    if (!preg_match('/^(' . $attrPattern . ')$/i', $name)) {
                        return '';
                    }
                    if (in_array($name, ['href', 'src'], true) && !$this->isSafeUrl($attr[4])) {
                        return '';
                    }
                    return ' ' . $attr[2] . '=' . $attr[3] . htmlspecialchars($attr[4], ENT_QUOTES, 'UTF-8') . $attr[3];
                },
                $matches[0]
            );
        }, $content);
    }

    /**
     * URL 协议白名单：仅允许 http/https 与相对路径
     */
    protected function isSafeUrl(string $url): bool
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $url);

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $url)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * 判断字段名是否为 Markdown 字段（支持点语法末级匹配）
     */
    protected function isMarkdownField(string $name): bool
    {
        if (in_array($name, $this->markdownFields, true)) {
            return true;
        }
        if (strpos($name, '.') !== false) {
            $leaf = substr(strrchr($name, '.'), 1);
            return in_array($leaf, $this->markdownFields, true);
        }
        return false;
    }

    /**
     * Markdown 净化：保留语法，仅移除危险标签与危险属性/协议
     */
    protected function sanitizeMarkdown(string $markdown): string
    {
        $markdown = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $markdown);

        $content = strip_tags(
            $markdown,
            '<p><br><strong><b><em><i><u><s><del><a><code><pre><ul><ol><li>'
            . '<blockquote><h1><h2><h3><h4><h5><h6><img><table><thead><tbody><tr><th><td><hr>'
        );
        $content = (string) preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content);
        $content = (string) preg_replace('/\s(?:href|src)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content);

        return $content;
    }

    /**
     * 请求来源校验
     */
    public function checkReferer(): bool
    {
        $referer = $this->server('HTTP_REFERER');
        if (empty($referer)) {
            return true;
        }
        $allowedHosts = config('app.allowed_referer_hosts', []);
        if (empty($allowedHosts)) {
            return false;
        }
        $parts = parse_url($referer);
        return !empty($parts['host']) && in_array($parts['host'], $allowedHosts, true);
    }
}