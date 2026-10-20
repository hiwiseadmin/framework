<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\service\content\PreviewService;

/**
 * CMS 前台内容预览入口（B-3）
 *
 * 复用 Content 的取数与渲染能力：继承 Content 后 initPlugin()（$this->contentService）
 * 与 renderDetail() 零成本复用，Base::initPlugin() 注入的导航/$cms 也照样生效，
 * 因此预览页外观与正式详情页完全一致（含自定义字段 / OG / 结构化数据 / 推荐位）。
 *
 * 与正式页的唯一差异（见 renderDetail）：
 *   · 不限内容状态（草稿 / 待审 / 已发布 / 已归档均可预览）；
 *   · 不做 canonical 301 归一、不递增 view_count；
 *   · 响应头带 X-Robots-Tag: noindex, nofollow, noarchive。
 *
 * 安全：令牌校验失败一律 404（不返回 403 或错误详情，避免暴露「此处存在受保护内容」）。
 */
class Preview extends Content
{
    protected PreviewService $previewService;

    protected function initPlugin(): void
    {
        parent::initPlugin();
        $this->previewService = new PreviewService();
    }

    public function index()
    {
        if (!$this->previewService->enable()) {
            $this->abort404('预览功能未开启');
        }

        $id    = (int) $this->request->param('id', 0);
        $token = trim((string) $this->request->param('token', ''));

        // 校验失败一律 404（不返回 403/错误详情，避免成为伪造探测点）
        $payload = $this->previewService->verify($token, $id, $this->siteId);
        if ($payload === null) {
            $this->abort404('预览链接无效或已过期');
        }

        // 预览不限状态，但仍强制站点隔离
        $content = $this->contentService->getDetailOutput($id);
        if (!$content || (int) ($content['site_id'] ?? 1) !== $this->siteId) {
            $this->abort404('内容不存在');
        }

        // 日志只记录定位信息，不写 token 原文（避免日志泄露令牌）
        cms_log('info', '内容预览访问', [
            'content_id' => $id,
            'site_id'    => $this->siteId,
            'status'     => (int) $content['status'],
            'uid'        => (int) ($payload['uid'] ?? 0),
        ]);

        return $this->renderDetail($content, [
            'preview'    => true,
            'expires_at' => (int) ($payload['exp'] ?? 0),
        ]);
    }
}
