<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

/**
 * Markdown 渲染器（批次 7 · 后端双保存）
 *
 * 一比一移植框架前端 `ws-markdown.js`（libs/ws-markdown/ws-markdown.js）的
 * **块级 / 行内解析算法**到 PHP，输出与前端一致的语义化 HTML，供正文「双保存」
 * 与前台 SEO 直出使用。与前端 ws-markdown 的差异（刻意简化）：
 *   - 不加 `.ws-md` 包装、不加「复制」按钮（复制 / 语言标签由前台
 *     enhanceCodeBlocks 统一处理）；
 *   - 围栏代码块输出 `<pre><code class="language-{lang}">`（标准 highlight.js 结构）。
 *
 * 安全取向与前端一致（安全 > 完整度）：
 *   1. 「先转义、再解析」——源文本先做 HTML 实体转义，渲染过程只产出自有标签，
 *      任意 raw HTML（<script>、<img onerror> 等）在结构上不可能存活；
 *   2. '>' 刻意不转义（'<' 与 '&' 已转义时无法构造标签）；
 *   3. 链接 URL 走协议白名单（http/https/mailto/tel + 相对路径），
 *      javascript: / data: / vbscript: 等降级为纯文本，且先实体解码再判定；
 *   4. 图片语法降级为链接渲染（防追踪像素）。
 *
 * 支持子集：围栏代码块、行内代码、#~#### 标题、粗体/斜体/删除线、有序/无序列表
 * （含嵌套）、引用、分割线、链接、GFM 表格、段落与软换行。
 */
class MarkdownRenderer
{
    /** 递归深度上限（引用块 / 嵌套列表） */
    private const MAX_DEPTH = 6;

    /** 内联元素可直接反斜杠转义的字符 */
    private const ESCAPABLE = '\\`*_~[]()#>-+.!|';

    private const LIST_RE = '/^( *)([-*+]|\d{1,9}[.)]) +(.*)$/';
    private const FENCE_OPEN_RE = '/^ {0,3}(`{3,}|~{3,}) ?([A-Za-z0-9+#._-]{1,20})?/';
    private const HEADING_RE = '/^ {0,3}(#{1,6}) +(.*)$/';
    private const BLOCKQUOTE_RE = '/^ {0,3}> ?(.*)$/';

    /**
     * 渲染 Markdown 为 HTML 片段（所有动态内容均已转义 / 校验，可安全 |raw 输出）。
     */
    public function render(string $source): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $source);
        $text = str_replace("\t", '    ', $text);

        $text = $this->closeOpenFences($text);
        $text = $this->escapeHtml($text);

        return $this->renderBlocks(explode("\n", $text), 0);
    }

    // ── 块级解析 ──────────────────────────────────────────

    private function renderBlocks(array $lines, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '<p>' . $this->inline(implode("\n", $lines)) . '</p>';
        }

        $out   = [];
        $count = count($lines);
        $i     = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // 围栏代码块
            if (preg_match(self::FENCE_OPEN_RE, $line, $fence)) {
                $marker  = $fence[1][0];
                $closeRe = '/^ {0,3}' . $marker . '{' . strlen($fence[1]) . ',}\s*$/';
                $lang    = $fence[2] ?? '';
                $buf     = [];
                $j       = $i + 1;
                for (; $j < $count; $j++) {
                    if (preg_match($closeRe, $lines[$j])) {
                        break;
                    }
                    $buf[] = $lines[$j];
                }
                $out[] = $this->codeBlockHtml(implode("\n", $buf), $lang);
                $i     = $j < $count ? $j + 1 : $j;
                continue;
            }

            // 标题（去掉尾部 closing #）
            if (preg_match(self::HEADING_RE, $line, $head)) {
                $level = strlen($head[1]);
                $title = preg_replace('/ +#+ *$/', '', $head[2]);
                $out[] = '<h' . $level . '>' . $this->inline($title) . '</h' . $level . '>';
                $i++;
                continue;
            }

            // 分割线
            if ($this->isHr($line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // 引用块（聚合连续 '>' 行后递归解析）
            if (preg_match(self::BLOCKQUOTE_RE, $line)) {
                $buf = [];
                $j   = $i;
                for (; $j < $count; $j++) {
                    if (!preg_match(self::BLOCKQUOTE_RE, $lines[$j], $m)) {
                        break;
                    }
                    $buf[] = $m[1];
                }
                $out[] = '<blockquote>' . $this->renderBlocks($buf, $depth + 1) . '</blockquote>';
                $i     = $j;
                continue;
            }

            // 表格
            if (str_contains($line, '|') && $this->isTableDelimiter($lines[$i + 1] ?? '')) {
                $table = $this->parseTable($lines, $i);
                if ($table !== null) {
                    $out[] = $table['html'];
                    $i     = $table['next'];
                    continue;
                }
            }

            // 列表
            if (preg_match(self::LIST_RE, $line)) {
                $list  = $this->parseList($lines, $i, $depth);
                $out[] = $list['html'];
                $i     = $list['next'];
                continue;
            }

            // 段落
            $buf = [$line];
            $j   = $i + 1;
            for (; $j < $count; $j++) {
                if ($this->startsBlock($lines[$j])) {
                    break;
                }
                $buf[] = $lines[$j];
            }
            $out[] = '<p>' . $this->inline(implode("\n", $buf)) . '</p>';
            $i     = $j;
        }

        return implode('', $out);
    }

    /**
     * 解析 GFM 表格
     *
     * @param string[] $lines
     * @return array{html:string,next:int}|null
     */
    private function parseTable(array $lines, int $start): ?array
    {
        $head  = $this->splitRow($lines[$start]);
        $delim = $this->splitRow($lines[$start + 1]);
        if (empty($head) || empty($delim)) {
            return null;
        }

        $aligns = [];
        foreach ($delim as $cell) {
            $t = trim($cell);
            if (preg_match('/^:-+:$/', $t)) {
                $aligns[] = 'center';
            } elseif (preg_match('/^-+:$/', $t)) {
                $aligns[] = 'right';
            } elseif (preg_match('/^:-+$/', $t)) {
                $aligns[] = 'left';
            } else {
                $aligns[] = '';
            }
        }

        $attr = static function (int $idx) use ($aligns): string {
            return $aligns[$idx] !== '' ? ' style="text-align:' . $aligns[$idx] . '"' : '';
        };

        $html = '<table><thead><tr>';
        foreach ($head as $idx => $cell) {
            $html .= '<th' . $attr($idx) . '>' . $this->inline(trim($cell)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $count = count($lines);
        $i     = $start + 2;
        for (; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '' || !str_contains($line, '|')) {
                break;
            }
            $cells = $this->splitRow($line);
            $html .= '<tr>';
            for ($c = 0, $hc = count($head); $c < $hc; $c++) {
                $html .= '<td' . $attr($c) . '>' . $this->inline(trim($cells[$c] ?? '')) . '</td>';
            }
            $html .= '</tr>';
        }

        return ['html' => $html . '</tbody></table>', 'next' => $i];
    }

    /**
     * 解析列表（含嵌套）
     *
     * @param string[] $lines
     * @return array{html:string,next:int}
     */
    private function parseList(array $lines, int $start, int $depth): array
    {
        preg_match(self::LIST_RE, $lines[$start], $first);
        $base          = strlen($first[1]);
        $ordered       = (bool) preg_match('/^\d/', $first[2]);
        $contentIndent = $base + strlen($first[2]) + 1;
        $items         = [];
        $count         = count($lines);
        $i             = $start;

        while ($i < $count) {
            $line = $lines[$i];

            // 空行：仅当下一行仍属于本列表（缩进足够）时才继续
            if (trim($line) === '') {
                $next = $lines[$i + 1] ?? '';
                if (trim($next) !== '' && $this->indentOf($next) >= $contentIndent) {
                    if (!empty($items)) {
                        $items[count($items) - 1]['rest'][] = '';
                    }
                    $i++;
                    continue;
                }
                break;
            }

            if (preg_match(self::LIST_RE, $line, $m)
                && strlen($m[1]) === $base
                && (bool) preg_match('/^\d/', $m[2]) === $ordered) {
                $items[] = ['text' => $m[3], 'rest' => []];
                $i++;
                continue;
            }

            // 续行 / 嵌套（缩进不小于条目正文起始列）
            if ($this->indentOf($line) >= $contentIndent) {
                if (empty($items)) {
                    break;
                }
                $items[count($items) - 1]['rest'][] = substr($line, $contentIndent);
                $i++;
                continue;
            }

            break;
        }

        $tag  = $ordered ? 'ol' : 'ul';
        $html = '<' . $tag . '>';
        foreach ($items as $item) {
            $inner = $this->inline($item['text']);
            $rest  = array_values(array_filter($item['rest'], static fn ($l) => trim($l) !== ''));
            if (!empty($rest)) {
                if (preg_match(self::LIST_RE, $rest[0])) {
                    $inner .= $this->renderBlocks($rest, $depth + 1);
                } else {
                    $inner .= '<br>' . $this->inline(implode("\n", $rest));
                }
            }
            $html .= '<li>' . $inner . '</li>';
        }

        return ['html' => $html . '</' . $tag . '>', 'next' => $i];
    }

    private function startsBlock(string $line): bool
    {
        return trim($line) === ''
            || (bool) preg_match(self::FENCE_OPEN_RE, $line)
            || (bool) preg_match(self::HEADING_RE, $line)
            || (bool) preg_match(self::BLOCKQUOTE_RE, $line)
            || $this->isHr($line)
            || (bool) preg_match(self::LIST_RE, $line)
            || $this->isTableDelimiter($line);
    }

    // ── 行内解析 ──────────────────────────────────────────

    private function inline(string $text): string
    {
        $out = '';
        $len = mb_strlen($text);
        $i   = 0;

        while ($i < $len) {
            $c    = mb_substr($text, $i, 1);
            $next = $i + 1 < $len ? mb_substr($text, $i + 1, 1) : '';

            // 反斜杠转义
            if ($c === '\\' && $next !== '' && mb_strpos(self::ESCAPABLE, $next) !== false) {
                $out .= $next;
                $i   += 2;
                continue;
            }

            // 软换行 → <br>
            if ($c === "\n") {
                $out .= '<br>';
                $i++;
                continue;
            }

            // 行内代码
            if ($c === '`') {
                $run   = 1;
                while ($i + $run < $len && mb_substr($text, $i + $run, 1) === '`') {
                    $run++;
                }
                $close = mb_strpos($text, str_repeat('`', $run), $i + $run);
                if ($close !== false) {
                    $out .= '<code>' . mb_substr($text, $i + $run, $close - $i - $run) . '</code>';
                    $i    = $close + $run;
                    continue;
                }
                $out .= $c;
                $i++;
                continue;
            }

            // 图片 → 按链接渲染
            if ($c === '!' && $next === '[') {
                $link = $this->readLink($text, $i + 1);
                if ($link !== null) {
                    $out .= $this->renderLink($link);
                    $i    = $link['end'];
                    continue;
                }
                $out .= $c;
                $i++;
                continue;
            }

            // 链接
            if ($c === '[') {
                $link = $this->readLink($text, $i);
                if ($link !== null) {
                    $out .= $this->renderLink($link);
                    $i    = $link['end'];
                    continue;
                }
                $out .= $c;
                $i++;
                continue;
            }

            // 强调：***x*** / **x** / __x__ / ~~x~~ 优先于 *x* / _x_
            $prev                = $i > 0 ? mb_substr($text, $i - 1, 1) : '';
            $isMarker            = $c === '*' || $c === '_' || $c === '~';
            $intrawordUnderscore = $c === '_' && $i > 0 && (bool) preg_match('/\w/u', $prev);

            if ($isMarker && !$intrawordUnderscore && $next === $c) {
                $triple = $c !== '~' && ($i + 2 < $len ? mb_substr($text, $i + 2, 1) : '') === $c;
                $width  = $triple ? 3 : 2;
                $close  = $this->findClose($text, $i + $width, str_repeat($c, $width));
                $inner  = $close === -1 ? '' : mb_substr($text, $i + $width, $close - $i - $width);
                if ($close !== -1 && $inner !== '' && !preg_match('/^\s/u', $inner) && !preg_match('/\s$/u', $inner)) {
                    $body = $this->inline($inner);
                    $tag  = $c === '~' ? 'del' : 'strong';
                    $out .= $triple
                        ? '<strong><em>' . $body . '</em></strong>'
                        : '<' . $tag . '>' . $body . '</' . $tag . '>';
                    $i = $close + $width;
                    continue;
                }
            }

            if (($c === '*' || $c === '_') && !$intrawordUnderscore) {
                $close = $this->findClose($text, $i + 1, $c);
                $inner = $close === -1 ? '' : mb_substr($text, $i + 1, $close - $i - 1);
                if ($close !== -1 && $inner !== '' && !preg_match('/^\s/u', $inner) && !preg_match('/\s$/u', $inner)) {
                    $out .= '<em>' . $this->inline($inner) . '</em>';
                    $i    = $close + 1;
                    continue;
                }
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * 查找闭合标记，要求标记前一个字符非空白（避免 `* a * b` 误判为强调）
     */
    private function findClose(string $text, int $from, string $token): int
    {
        $idx = mb_strpos($text, $token, $from);
        while ($idx !== false) {
            if ($idx > $from && !preg_match('/\s/u', mb_substr($text, $idx - 1, 1))) {
                return $idx;
            }
            $idx = mb_strpos($text, $token, $idx + 1);
        }

        return -1;
    }

    /**
     * 从 at 处的 '[' 起读取 `[label](url)`，支持一层配对括号
     *
     * @return array{label:string,url:string,end:int}|null
     */
    private function readLink(string $text, int $at): ?array
    {
        $len   = mb_strlen($text);
        $depth = 0;
        $j     = $at;
        $label = '';

        for (; $j < $len; $j++) {
            $ch = mb_substr($text, $j, 1);
            if ($ch === '\\' && $j + 1 < $len) {
                $label .= $ch . mb_substr($text, $j + 1, 1);
                $j++;
                continue;
            }
            if ($ch === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $label .= $ch;
        }

        if ($depth !== 0
            || ($j < $len ? mb_substr($text, $j, 1) : '') !== ']'
            || ($j + 1 < $len ? mb_substr($text, $j + 1, 1) : '') !== '(') {
            return null;
        }

        $k   = $j + 2;
        $d   = 1;
        $url = '';
        for (; $k < $len; $k++) {
            $ch = mb_substr($text, $k, 1);
            if ($ch === '\\') {
                $url .= $k + 1 < $len ? mb_substr($text, $k + 1, 1) : '';
                $k++;
                continue;
            }
            if ($ch === '(') {
                $d++;
            }
            if ($ch === ')') {
                $d--;
                if ($d === 0) {
                    break;
                }
            }
            $url .= $ch;
        }
        if ($d !== 0) {
            return null;
        }

        $urlParts = preg_split('/\s+/', trim($url));

        return ['label' => $label, 'url' => $urlParts[0] ?? '', 'end' => $k + 1];
    }

    /**
     * 渲染链接；协议不通过白名单时降级为纯文本
     *
     * @param array{label:string,url:string} $link
     */
    private function renderLink(array $link): string
    {
        $src   = $link['label'] !== '' ? $link['label'] : $link['url'];
        $label = $this->inline($src);
        if ($label === '') {
            $label = $link['url'];
        }

        $url = $this->safeUrl($link['url']);
        if ($url === '') {
            return $label;
        }

        $attrs = $this->isExternalUrl($url) ? ' target="_blank" rel="noopener noreferrer nofollow"' : '';

        return '<a href="' . $url . '"' . $attrs . '>' . $label . '</a>';
    }

    // ── 工具 ──────────────────────────────────────────────

    private function codeBlockHtml(string $code, string $lang): string
    {
        $class = $lang !== '' ? ' class="language-' . $this->escapeHtml($lang) . '"' : '';
        $code  = preg_replace('/\n$/', '', $code);

        return '<pre><code' . $class . '>' . $code . '</code></pre>';
    }

    private function isHr(string $line): bool
    {
        return (bool) preg_match('/^ {0,3}(?:(?:\* *){3,}|(?:- *){3,}|(?:_ *){3,})$/', $line);
    }

    private function isTableDelimiter(string $line): bool
    {
        if ($line === '' || !str_contains($line, '-')) {
            return false;
        }

        return (bool) preg_match('/^ {0,3}\|? *:?-+:? *(?:\| *:?-+:? *)*\|? *$/', $line);
    }

    /**
     * @return string[]
     */
    private function splitRow(string $line): array
    {
        $s = trim($line);
        if (str_starts_with($s, '|')) {
            $s = substr($s, 1);
        }
        if (str_ends_with($s, '|')) {
            $s = substr($s, 0, -1);
        }

        return explode('|', $s);
    }

    private function indentOf(string $line): int
    {
        preg_match('/^ */', $line, $m);

        return strlen($m[0]);
    }

    /**
     * 补齐未闭合的围栏代码块（来源被截断时避免整段退化为字面量）
     */
    private function closeOpenFences(string $text): string
    {
        preg_match_all('/^ {0,3}(`{3,}|~{3,})/m', $text, $openers);
        if (count($openers[0]) % 2 === 0) {
            return $text;
        }

        $marker = trim((string) end($openers[0]));

        return $text . (str_ends_with($text, "\n") ? '' : "\n") . $marker . "\n";
    }

    /**
     * HTML 实体转义（不做 '>' 转义，见类头说明）
     */
    private function escapeHtml(string $str): string
    {
        return str_replace(
            ['&', '<', '"', "'"],
            ['&amp;', '&lt;', '&quot;', '&#39;'],
            $str
        );
    }

    /**
     * 还原 HTML 实体（含数字实体），仅用于「判定」场景（反复解码至稳定）
     */
    private function decodeEntities(string $str): string
    {
        $out = $str;
        for ($pass = 0; $pass < 3; $pass++) {
            $next = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $out) {
                break;
            }
            $out = $next;
        }

        return $out;
    }

    /**
     * 链接协议白名单校验（通过时返回可安全写入 href 的转义 URL，否则返回 ''）
     */
    private function safeUrl(string $raw): string
    {
        $url = trim($raw);
        if ($url === '') {
            return '';
        }

        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $this->decodeEntities($url));
        if (str_starts_with($probe, '//')) {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $probe)) {
            return preg_match('/^(https?|mailto|tel):/i', $probe) ? $url : '';
        }

        return $url;
    }

    /**
     * 是否为外部绝对链接（仅此类新窗口打开）
     */
    private function isExternalUrl(string $url): bool
    {
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $this->decodeEntities($url));

        return (bool) preg_match('/^(https?|mailto|tel):/i', $probe);
    }
}
