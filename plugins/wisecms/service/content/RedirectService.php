<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Content;
use plugin\wisecms\service\model\MassAssignmentGuard;
use plugin\wisecms\service\security\UrlGuard;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\url\UrlService;

/**
 * URL 重定向管理
 */
class RedirectService
{
    /**
     * 查找命中的重定向并累加命中数
     *
     * @param int $siteId >0 时限定站点（P1-C：重定向表已按站点隔离）
     */
    public function findRedirect(string $path, int $siteId = 0): ?array
    {
        $query = \plugin\wisecms\model\Redirect::where('source_url', $path)->where('status', 1);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $redirect = $query->find();
        if ($redirect) {
            $redirect->hit_count++;
            $redirect->save();
        }

        return $redirect ? $redirect->toArray() : null;
    }

    /**
     * 新建重定向（后台表单与 CSV 导入的唯一写入口）
     *
     * P1-C：`(site_id, source_url)` 唯一 —— 站点内同一来源路径只能有一条规则
     * （原实现可重复导入，解析时随机取到一条，301 目标不可预期）。
     *
     * @throws WisecmsException 目标 URL 协议非法 / 来源路径重复
     */
    public function create(array $data): object
    {
        // P1-1：黑名单剔除 id / create_time / hit_count 等非表单字段
        $data = MassAssignmentGuard::filter($data, 'wisecms_redirect');

        // P1-A2：目标 URL 协议白名单（http/https 或站内相对路径），
        // 阻断 javascript:/data:/vbscript: 等可被用于钓鱼或 XSS 的跳转目标
        $target = (string) ($data['target_url'] ?? '');
        if ($target !== '' && !UrlGuard::isSafeScheme($target)) {
            throw new WisecmsException(
                '跳转目标 URL 非法：仅允许 http/https 或站内相对路径',
                ErrorCode::VALIDATE_FAILED
            );
        }

        // 站点归属：后台写入一律按「后台当前站点」（会话上下文，P2 站点选择器）
        if (empty($data['site_id'])) {
            $data['site_id'] = (new SiteService())->resolveAdminSiteId();
        }

        $sourceUrl = trim((string) ($data['source_url'] ?? ''));
        if ($sourceUrl === '') {
            throw new WisecmsException('来源路径不能为空', ErrorCode::VALIDATE_FAILED);
        }
        $data['source_url'] = $sourceUrl;

        if (\plugin\wisecms\model\Redirect::where('site_id', $data['site_id'])->where('source_url', $sourceUrl)->find()) {
            throw new WisecmsException('该来源路径已存在重定向规则', ErrorCode::DUPLICATE);
        }

        try {
            // create_time / update_time 由模型 autoWriteTimestamp 维护（列类型 DATETIME）
            return \plugin\wisecms\model\Redirect::create($data);
        } catch (\Throwable $e) {
            // 并发（含 CSV 批量导入）下唯一键兜底，转成业务提示而非 500
            if (cms_is_duplicate_key($e)) {
                throw new WisecmsException('该来源路径已存在重定向规则', ErrorCode::DUPLICATE);
            }
            throw $e;
        }
    }

    /**
     * 更新重定向（UIUX-P1-17，复用 create 的协议白名单 + 唯一键校验，唯一键排除自己）
     *
     * `site_id` 不接受客户端提交（站点归属固定，由原记录决定）。
     */
    public function update(int $id, array $data): object
    {
        $redirect = \plugin\wisecms\model\Redirect::find($id);
        if (!$redirect) {
            throw new WisecmsException('重定向不存在', ErrorCode::NOT_FOUND);
        }

        $data = MassAssignmentGuard::filter($data, 'wisecms_redirect');
        unset($data['site_id']); // 站点归属不可改

        $target = (string) ($data['target_url'] ?? '');
        if ($target !== '' && !UrlGuard::isSafeScheme($target)) {
            throw new WisecmsException(
                '跳转目标 URL 非法：仅允许 http/https 或站内相对路径',
                ErrorCode::VALIDATE_FAILED
            );
        }

        $sourceUrl = trim((string) ($data['source_url'] ?? ''));
        if ($sourceUrl === '') {
            throw new WisecmsException('来源路径不能为空', ErrorCode::VALIDATE_FAILED);
        }
        $data['source_url'] = $sourceUrl;

        // 唯一键校验（排除自己）
        $dup = \plugin\wisecms\model\Redirect::where('site_id', $redirect->site_id)
            ->where('source_url', $sourceUrl)
            ->where('id', '<>', $id)
            ->find();
        if ($dup) {
            throw new WisecmsException('该来源路径已存在重定向规则', ErrorCode::DUPLICATE);
        }

        try {
            $redirect->save($data);
            return $redirect;
        } catch (\Throwable $e) {
            if (cms_is_duplicate_key($e)) {
                throw new WisecmsException('该来源路径已存在重定向规则', ErrorCode::DUPLICATE);
            }
            throw $e;
        }
    }

    /**
     * 从 CSV 导入（非法行跳过，返回成功条数）
     */
    public function importFromCsv(string $filePath): int
    {
        $count = 0;
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    try {
                        $this->create(['source_url' => $row[0], 'target_url' => $row[1], 'type' => $row[2] ?? 301]);
                        $count++;
                    } catch (WisecmsException $e) {
                        // 单行非法（如 javascript: 目标）不影响其余行导入
                        cms_log('warning', '重定向 CSV 导入跳过非法行: ' . $e->getMessage(), ['row' => $row]);
                    }
                }
            }
            fclose($handle);
        }
        return $count;
    }

    /**
     * 全站死链检测
     *
     * P1-A4 加固：
     *   1. 每个请求带 5s 超时 + 不自动跟随重定向，避免单条链接长时间阻塞整体扫描；
     *   2. 目标为**站外**地址时先过 SSRF 校验（拒绝内网/保留地址），
     *      防止用「内容自定义 URL + 死链检测」做内网探测；
     *   3. 站内自身域名放行（否则本地/内网部署下会把所有链接判为不可达而失效）。
     *
     * ⚠️ 仍为同步全表扫描，内容量大时建议改为队列任务（见审计 P1-B-8 同类问题）。
     */
    public function checkDeadLinks(int $siteId = 1): array
    {
        $contents = Content::where('site_id', $siteId)->where('status', 2)->select();
        $urlService = new UrlService();
        $selfHost = strtolower((string) parse_url($urlService->siteBase(), PHP_URL_HOST));

        $ctx = stream_context_create(['http' => [
            'timeout'       => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
            'method'        => 'HEAD',
        ]]);

        $dead = [];
        foreach ($contents as $c) {
            // 自定义 URL 可能是相对路径，先补全为绝对地址
            $url = $urlService->absolute((string) ($c->url ?: $urlService->content($c)));

            $targetHost = strtolower((string) parse_url($url, PHP_URL_HOST));
            $isSelf = $selfHost !== '' && $targetHost === $selfHost;
            if (!$isSelf && !UrlGuard::isSafeOutbound($url)) {
                // 站外非法/内网目标：直接跳过（不发起请求）
                continue;
            }

            $headers = @get_headers($url, true, $ctx);
            if ($headers && strpos((string) $headers[0], '200') === false) {
                $dead[] = ['id' => $c->id, 'title' => $c->title, 'url' => $url, 'status' => $headers[0]];
            }
        }
        return $dead;
    }
}
