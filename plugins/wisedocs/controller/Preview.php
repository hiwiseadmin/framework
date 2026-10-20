<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\FrontController;
use plugin\wisedocs\model\Document as DocumentModel;
use plugin\wisedocs\service\ComponentPreviewService;

/**
 * 组件演示沙箱页（D4）
 *
 * 只做一件事：把「组件型文档」的演示代码渲染为一个独立、隔离的 HTML 页面，
 * 供详情页的 `<iframe sandbox="allow-scripts allow-popups">` 嵌入。
 *
 * 安全要点：
 *  - 继承 `plugin\FrontController`（前台只读），不接受任何写操作；
 *    入参只有 slug / id，不参与输出拼装（不构成注入点）；
 *  - 只渲染**已发布**的组件型文档，草稿不可访问（避免未发布内容泄露）；
 *  - 响应头强制 `Content-Security-Policy: sandbox ...`（不透明源），
 *    即使被直接在新窗口打开同样不具备同源能力；
 *  - 不写入浏览统计（演示页加载不代表用户阅读文档）；
 *  - 不在框架层挂鉴权中间件（DocsAuth 只守护继承 `controller\Base` 的后台控制器），
 *    因此该端点是公开只读的。
 */
class Preview extends FrontController
{
    /**
     * 渲染演示页
     *
     * 访问方式：`/plugin/wisedocs/preview/render?slug=xxx`（或 `?id=123`）。
     */
    public function render($slug = '', $id = 0)
    {
        $document = $this->findComponent((string) $slug, (int) $id);
        if ($document === null) {
            return $this->notFound('组件示例不存在或已下线');
        }

        $service = new ComponentPreviewService();
        $demo    = $service->decode($document->demo_config);

        if ($service->isEmpty($demo)) {
            return $this->notFound('该组件文档尚未配置示例代码');
        }

        $html = $service->buildHtml((string) $document->title, $demo);

        return response($html, 200, $service->securityHeaders(), 'html');
    }

    /**
     * 定位可展示的组件型文档（仅已发布）。
     */
    protected function findComponent(string $slug, int $id): ?DocumentModel
    {
        $query = DocumentModel::where('content_type', DocumentModel::CONTENT_TYPE_COMPONENT)
            ->where('status', DocumentModel::STATUS_PUBLISHED);

        if ($id > 0) {
            $query->where('id', $id);
        } elseif ($slug !== '') {
            $query->where('slug', $slug);
        } else {
            return null;
        }

        /** @var DocumentModel|null $document */
        $document = $query->find();

        return $document;
    }

    /**
     * 纯文本 404（演示页在 iframe 内，无需站点样式）
     */
    protected function notFound(string $message)
    {
        return response($message, 404, [
            'Content-Type'   => 'text/plain; charset=utf-8',
            'Cache-Control'  => 'no-store',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
