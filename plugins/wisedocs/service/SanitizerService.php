<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

/**
 * WiseDocs 富文本净化服务（P1-5 · 设计 D7：自研白名单，零第三方依赖）
 *
 * 前提：文档正文只允许**管理员在后台**录入；前台接口只接收**纯文本**
 * （如反馈内容），不接收任何 HTML / 代码。因此净化只处理「后台可信但不安全」
 * 的富文本，范围收敛。
 *
 * 策略（DOMDocument + 白名单）：
 *   - 标签白名单：非白名单标签「脱壳保留内容」；危险标签整段丢弃；
 *   - 属性白名单：其余属性（含全部 on* 事件）一律移除；
 *   - 协议白名单：href/src 仅允许 http/https/mailto/tel 与站内相对路径；
 *   - 外链强制补 rel="noopener noreferrer"；
 *   - 输出已净化 HTML，渲染端禁止再对原始 content 使用 `|raw`。
 */
class SanitizerService
{
    /** 允许保留的标签 */
    protected const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'strong', 'em', 'b', 'i', 'u', 's', 'del', 'ins', 'sub', 'sup',
        'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'span', 'div', 'figure', 'figcaption',
    ];

    /** 整段丢弃（含内容）的危险标签 */
    protected const DROP_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'select', 'option', 'textarea', 'link', 'meta', 'base', 'svg', 'math',
        'template', 'noscript',
    ];

    /** 标签 -> 允许属性（'*' 为通用属性） */
    protected const ALLOWED_ATTRS = [
        '*'   => ['class', 'title', 'id'],
        'a'   => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /** 允许的 URL 协议 */
    protected const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * 净化富文本 HTML。
     */
    public function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        // 无 DOM 扩展时降级为 strip_tags（仍可阻断脚本标签）
        if (!class_exists(\DOMDocument::class)) {
            return (string) strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument('1.0', 'UTF-8');
            // 前置 XML 编码声明以正确识别 UTF-8；LIBXML_NONET 禁止网络访问，
            // 且不启用 NOENT（防实体展开）
            $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);

            if ($loaded === false) {
                return (string) strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
            }

            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return '';
            }

            $this->cleanNode($body);

            return $this->innerHtml($body);
        } catch (\Throwable $e) {
            return (string) strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * 净化纯文本字段（反馈内容、搜索关键词等）：剥离标签 + 去控制字符 + 截断。
     */
    public function cleanText(?string $text, int $maxLength = 0): string
    {
        $text = trim(strip_tags((string) $text));
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }

        return $text;
    }

    /**
     * URL 是否安全（协议白名单 / 站内相对路径）。
     */
    public function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        // 站内相对路径 / 锚点
        if (preg_match('#^(/|\.{1,2}/|\#)#', $url)) {
            return true;
        }

        // 显式协议
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $url, $matches)) {
            return in_array(strtolower($matches[1]), self::SAFE_SCHEMES, true);
        }

        // 无协议的相对地址
        return true;
    }

    /**
     * 净化单个 URL（不安全时返回兜底值）。
     */
    public function cleanUrl(?string $url, string $fallback = ''): string
    {
        return $this->isSafeUrl($url) ? trim((string) $url) : $fallback;
    }

    /**
     * 递归清洗节点。
     */
    protected function cleanNode(\DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue; // 文本节点保留
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // 非白名单标签：脱壳，保留其子内容
                $this->cleanNode($child);
                $this->unwrap($child);
                continue;
            }

            $this->cleanAttributes($child, $tag);
            $this->cleanNode($child);
        }
    }

    /**
     * 移除非白名单属性，并校验 URL 属性。
     */
    protected function cleanAttributes(\DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);

        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if (in_array($name, ['href', 'src'], true) && !$this->isSafeUrl($attribute->nodeValue)) {
                $remove[] = $attribute->nodeName;
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        // 外链加固
        if ($tag === 'a') {
            $href = (string) $element->getAttribute('href');
            if ($href !== '' && preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $href)) {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    /**
     * 用子内容替换元素本身（脱壳）。
     */
    protected function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * 取节点内部 HTML。
     */
    protected function innerHtml(\DOMNode $node): string
    {
        $html = '';
        $doc  = $node->ownerDocument;

        if ($doc === null) {
            return '';
        }

        foreach ($node->childNodes as $child) {
            $html .= (string) $doc->saveHTML($child);
        }

        return $html;
    }
}
