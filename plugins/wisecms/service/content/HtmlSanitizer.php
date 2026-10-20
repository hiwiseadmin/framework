<?php
namespace plugin\wisecms\service\content;

/**
 * HTML 白名单净化器（P0-4）
 *
 * 背景：净化逻辑原先只存在于 `field/editor/Input`（**自定义编辑器字段**的入库链路），
 * 而内容主表 `wisecms_content.content`、单页 `wisecms_page.content` 直接透传请求体，
 * 前端模板又以 `{$content.content|raw}` 直出 —— 形成存储型 XSS：
 * 具备「内容编辑」权限的低权账号可植入 `<script>` / `<img onerror>`，
 * 在超管或任意访客浏览器中执行。
 *
 * 本类把该逻辑收敛为**唯一事实源**，供三处共用：
 *   - `field/editor/Input`（自定义富文本字段）
 *   - `service/content/ContentService::create()/update()`（主表正文）
 *   - `controller/Page`（单页正文）
 *
 * 净化策略：
 *   1. 标签白名单：`strip_tags($html, $allowedTags)`；
 *   2. 属性白名单：正则逐属性过滤，未命中即删除（含无引号写法）；
 *   3. `href` / `src` 协议白名单：仅 http / https / 相对路径，
 *      阻断 javascript: / vbscript: / data: 及实体编码、控制字符绕过；
 *   4. 属性值统一 `htmlspecialchars` 转义；
 *   5. 并入编辑器驱动声明的白名单（wiseeditor 等），只做并集 —— 未安装编辑器时行为不变。
 *
 * 注意（已知待办，非本 P0 范围）：`style` 属性仍在白名单内，
 * 如需收紧可在 {@see ATTRIBUTES} 中移除或改做 CSS 属性白名单。
 */
class HtmlSanitizer
{
    /**
     * 允许保留的标签
     */
    const TAGS = '<div><p><span><br><b><strong><i><em><u><s><strike><del><ins><sup><sub>'
        . '<h1><h2><h3><h4><h5><h6><ul><ol><li><table><tr><td><th><thead><tbody><tfoot>'
        . '<img><a><code><pre><blockquote>';

    /**
     * 允许保留的属性
     */
    const ATTRIBUTES = 'style|class|id|src|href|target|alt|title|width|height|border'
        . '|cellpadding|cellspacing|colspan|rowspan|align|valign';

    /**
     * 净化富文本 HTML
     */
    public static function clean(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        [$allowedTags, $allowedAttributes] = self::resolveWhitelist();

        $content = strip_tags($content, $allowedTags);

        return (string) preg_replace_callback(
            '/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i',
            static function (array $matches) use ($allowedAttributes): string {
                // 第 3 组为「可选引号」：\3 空匹配时即覆盖无引号属性（src=x / onerror=alert(1)），
                // 否则 strip_tags 保留的无引号属性会绕过白名单（存储型 XSS）。
                // 断言 (?=[\s\/>]|$) 用于界定无引号属性值边界，避免吞掉后续属性。
                $pattern = '/(\s+)([a-z\-]+)\s*=\s*(["\']?)(.*?)\3(?=[\s\/>]|$)/i';

                return (string) preg_replace_callback(
                    $pattern,
                    static function (array $attrMatches) use ($allowedAttributes): string {
                        $attrName  = $attrMatches[2];
                        $quote     = $attrMatches[3];
                        $attrValue = $attrMatches[4];

                        if (!preg_match('/^(' . $allowedAttributes . ')$/i', $attrName)) {
                            return '';
                        }

                        // URL 协议白名单：href/src 仅允许 http/https 与相对路径，
                        // 阻断 javascript:/vbscript:/data: 等
                        if (in_array(strtolower($attrName), ['href', 'src'], true)
                            && !self::isSafeUrl($attrValue)) {
                            return '';
                        }

                        $attrValue = htmlspecialchars($attrValue, ENT_QUOTES, 'UTF-8');

                        return ' ' . $attrName . '=' . $quote . $attrValue . $quote;
                    },
                    $matches[0]
                );
            },
            $content
        );
    }

    /**
     * URL 协议白名单校验：仅允许 http/https 与相对路径
     *
     * 阻断 javascript:、vbscript:、data: 等危险协议，
     * 并处理实体编码（&#106;）、控制字符、空字节等常见绕过手段。
     */
    public static function isSafeUrl(string $url): bool
    {
        // 解码 HTML 实体，防止 &#106;avascript: 等编码绕过
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 剔除控制字符 / 空白 / 空字节，防止 jav\tscript: 等绕过
        $url = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $url);

        // 无协议头（相对路径 / 锚点 / 查询串）视为安全
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $url)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * 解析最终白名单（内置 + 编辑器驱动声明）
     *
     * 仅做并集，向后兼容：未安装编辑器插件 / 编辑器不可用时行为与内置白名单完全一致。
     *
     * @return array{0:string,1:string} [allowedTags, allowedAttributes]
     */
    protected static function resolveWhitelist(): array
    {
        $allowedTags       = self::TAGS;
        $allowedAttributes = self::ATTRIBUTES;

        try {
            $manager = app('editor.manager');
            if ($manager instanceof \app\common\library\EditorManager) {
                $driverTags = $manager->getAllowedTags();
                if (!empty($driverTags)) {
                    $allowedTags .= '<' . implode('><', array_map('strtolower', $driverTags)) . '>';
                }
                $driverAttrs = $manager->getAllowedAttributeNames();
                if (!empty($driverAttrs)) {
                    $allowedAttributes .= '|' . implode('|', array_map('preg_quote', $driverAttrs));
                }
            }
        } catch (\Throwable $e) {
            // 编辑器不可用时保持内置白名单
        }

        return [$allowedTags, $allowedAttributes];
    }
}
