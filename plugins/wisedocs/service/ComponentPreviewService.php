<?php

declare(strict_types=1);

namespace plugin\wisedocs\service;

/**
 * 组件型文档演示服务（D4 · 在线运行沙箱）
 *
 * 职责：为「组件型文档」（content_type = 3）组装可在沙箱中运行的演示页，
 * 并提供该演示页的安全响应头。
 *
 * 安全模型（与 6.1 富文本净化是**两条互不替代**的通道）：
 *   演示代码（HTML / CSS / JS）**不能**经过 SanitizerService 净化——一旦移除
 *   `script` 与事件属性，示例就无法运行。因此这里不做内容净化，改由
 *   「执行环境隔离」保证安全：
 *     1. 演示页由独立端点 `preview/render` 返回，不并入站点页面 DOM；
 *     2. 响应头携带 `Content-Security-Policy: sandbox allow-scripts; ...`，
 *        使演示页成为**独立不透明源**（opaque origin）：读不到站点 Cookie /
 *        localStorage / 父页面 DOM，跨源请求也带不上凭据；
 *     3. 详情页用 `<iframe sandbox="allow-scripts allow-popups">` 嵌入，
 *        同样**不授予** allow-same-origin；
 *     4. `connect-src 'none'` 阻断 fetch / XHR / WebSocket；
 *        `form-action 'none'` 阻断表单外发；`frame-src 'none'` 阻断嵌套框架；
 *     5. 仅超级管理员可创建 / 修改组件型文档（controller\Document::save）。
 *
 * 残余风险（已记录于设计文档 6.2）：演示脚本仍可通过 img / script 等**子资源
 * 请求**外带数据，也能操作 iframe 内部的 DOM。因此组件型文档应整体视为
 * 「受信任内容」，仅由可信任的管理员录入，不得开放给普通编辑或前台提交。
 */
class ComponentPreviewService
{
    /** 单个字段字符上限 */
    public const MAX_FIELD_LENGTH = 20000;

    /** 三个字段合计字符上限 */
    public const MAX_TOTAL_LENGTH = 50000;

    /** 演示区域高度（px）取值范围 */
    public const MIN_HEIGHT = 120;

    public const MAX_HEIGHT = 1200;

    public const DEFAULT_HEIGHT = 320;

    /**
     * `demo_config` 序列化后的字节上限。
     *
     * 字段列为 TEXT（65535 字节），预留约 5KB 余量避免写入被静默截断。
     */
    public const MAX_STORAGE_BYTES = 60000;

    /**
     * 演示页 CSP。
     *
     * - `sandbox allow-scripts allow-popups`：不透明源 + 允许脚本，弹窗继承沙箱；
     * - `default-src 'none'`：默认全部拒绝，再逐项放开资源类型；
     * - 资源按协议放开（`http:` / `https:`），因此示例可继续引用站点
     *   `/statics/...` 与 CDN 上的样式、脚本、字体、图片；
     * - `connect-src 'none'` / `form-action 'none'`：切断数据外发的主要通道。
     */
    protected const CSP = "sandbox allow-scripts allow-popups; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' data: blob: http: https:; style-src 'unsafe-inline' http: https:; img-src data: blob: http: https:; font-src data: http: https:; media-src data: blob: http: https:; connect-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";

    /** 演示页基础样式（只做中文字体与盒模型基线，不干扰示例自身样式） */
    protected const BASE_CSS = '*{box-sizing:border-box}html,body{margin:0;padding:0}body{padding:16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;font-size:14px;line-height:1.6;color:#212529;background:#fff}';

    /**
     * 解析 `demo_config`（JSON 或数组）为规范结构。
     *
     * @param mixed $raw
     * @return array{html:string,css:string,js:string,height:int}
     */
    public function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            $data = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
            $data    = is_array($decoded) ? $decoded : [];
        }

        return [
            'html'   => (string) ($data['html'] ?? ''),
            'css'    => (string) ($data['css'] ?? ''),
            'js'     => (string) ($data['js'] ?? ''),
            'height' => $this->normalizeHeight($data['height'] ?? self::DEFAULT_HEIGHT),
        ];
    }

    /**
     * 序列化演示结构（入库用）。
     *
     * @param array{html?:string,css?:string,js?:string,height?:int} $demo
     */
    public function encode(array $demo): string
    {
        return (string) json_encode([
            'html'   => (string) ($demo['html'] ?? ''),
            'css'    => (string) ($demo['css'] ?? ''),
            'js'     => (string) ($demo['js'] ?? ''),
            'height' => $this->normalizeHeight($demo['height'] ?? self::DEFAULT_HEIGHT),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 演示代码是否为空（组件型文档必须提供 HTML 结构）。
     *
     * @param array{html?:string,css?:string,js?:string} $demo
     */
    public function isEmpty(array $demo): bool
    {
        return trim((string) ($demo['html'] ?? '')) === '';
    }

    /**
     * 高度归一化并夹取到合法区间。
     */
    public function normalizeHeight(mixed $value): int
    {
        $height = (int) $value;
        if ($height <= 0) {
            return self::DEFAULT_HEIGHT;
        }

        return max(self::MIN_HEIGHT, min(self::MAX_HEIGHT, $height));
    }

    /**
     * 长度校验；通过返回 null，否则返回面向管理员的错误文案。
     *
     * @param array{html?:string,css?:string,js?:string,height?:int} $demo
     */
    public function sizeError(array $demo): ?string
    {
        $labels = ['html' => 'HTML 结构', 'css' => 'CSS 样式', 'js' => 'JavaScript'];

        foreach ($labels as $key => $label) {
            if (mb_strlen((string) ($demo[$key] ?? '')) > self::MAX_FIELD_LENGTH) {
                return "{$label}过长，请控制在 " . self::MAX_FIELD_LENGTH . ' 字符以内';
            }
        }

        $total = mb_strlen((string) ($demo['html'] ?? ''))
            + mb_strlen((string) ($demo['css'] ?? ''))
            + mb_strlen((string) ($demo['js'] ?? ''));

        if ($total > self::MAX_TOTAL_LENGTH) {
            return '示例代码合计过长，请控制在 ' . self::MAX_TOTAL_LENGTH . ' 字符以内';
        }

        if (strlen($this->encode($demo)) > self::MAX_STORAGE_BYTES) {
            return '示例代码超出存储上限（约 60KB），请精简后再保存';
        }

        return null;
    }

    /**
     * 组装演示页 HTML。
     *
     * 注意：`html/css/js` 均**不做净化**（见类注释）；仅对可能提前闭合
     * `<style>` / `<script>` 的序列做转义，避免示例自身被截断。
     *
     * @param array{html?:string,css?:string,js?:string,height?:int} $demo
     */
    public function buildHtml(string $title, array $demo): string
    {
        $safeTitle = htmlspecialchars($title !== '' ? $title : '组件预览', ENT_QUOTES, 'UTF-8');
        $css       = $this->escapeClosingTag((string) ($demo['css'] ?? ''), 'style');
        $js        = $this->escapeClosingTag((string) ($demo['js'] ?? ''), 'script');
        $body      = (string) ($demo['html'] ?? '');

        $style = self::BASE_CSS . ($css !== '' ? "\n" . $css : '');

        $html = '<!DOCTYPE html>' . "\n"
            . '<html lang="zh-CN">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex, nofollow">' . "\n"
            . '<title>' . $safeTitle . ' · 组件预览</title>' . "\n"
            . '<!-- 由 WiseDocs 沙箱隔离渲染（CSP: sandbox）；此页不携带站点凭据 -->' . "\n"
            . '<style>' . $style . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . $body . "\n";

        if ($js !== '') {
            $html .= '<script>' . $js . '</script>' . "\n";
        }

        return $html . '</body>' . "\n</html>";
    }

    /**
     * 演示页响应头（沙箱隔离 + 禁止被外站框架嵌套 + 不缓存）。
     *
     * @return array<string,string>
     */
    public function securityHeaders(): array
    {
        return [
            'Content-Type'                => 'text/html; charset=utf-8',
            'Content-Security-Policy'     => self::CSP,
            'X-Content-Type-Options'      => 'nosniff',
            // 只允许同站页面用 iframe 嵌入（演示页由详情页嵌入）
            'X-Frame-Options'             => 'SAMEORIGIN',
            'Referrer-Policy'             => 'no-referrer',
            // 新窗口打开时切断 opener，避免演示页反向操作上游页面
            'Cross-Origin-Opener-Policy'  => 'same-origin',
            'Cache-Control'               => 'no-store, no-cache, must-revalidate',
        ];
    }

    /**
     * 转义 `</script` / `</style` 序列，防止演示代码提前闭合内联标签。
     *
     * `<\/script` 在 JS 字符串中语义等价、在 JS 正则中亦合法；CSS 中同样安全。
     */
    protected function escapeClosingTag(string $code, string $tag): string
    {
        if ($code === '') {
            return '';
        }

        return (string) preg_replace('~</\s*' . preg_quote($tag, '~') . '~i', '<\\/' . $tag, $code);
    }
}
