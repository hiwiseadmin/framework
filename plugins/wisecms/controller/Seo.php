<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\model\SeoAudit;
use plugin\wisecms\model\SeoPushLog;
use plugin\wisecms\service\seo\SeoAuditService;
use plugin\wisecms\service\seo\SeoPushService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\exception\WisecmsException;

/**
 * SEO 总览（UIUX-P1-20）
 *
 * 聚合 SeoAuditService / SeoPushService / SeoPushLog：
 *   seo/index   → 站点健康分 + 最近审查记录（问题下钻入口）
 *   seo/audit   → 审计单条内容（返回检查项明细）
 *   seo/push    → 推送到搜索引擎（百度/Google/Bing）
 *   seo/pushLog → 推送日志列表
 */
class Seo extends BaseController
{
    /** 后台当前站点（会话上下文，与 Content::siteId 同源） */
    protected function siteId(): int
    {
        return (new SiteService())->resolveAdminSiteId();
    }

    /** 站点健康分 + 最近审查记录 */
    public function index()
    {
        $siteId = $this->siteId();
        $health = (new SeoAuditService())->siteHealthCheck($siteId);

        // 最近审查记录（join 内容取标题；SeoAudit 无 site_id → 按内容归属过滤）
        $recent = SeoAudit::alias('a')
            ->join('wisecms_content c', 'c.id = a.content_id')
            ->where('c.site_id', $siteId)
            ->field('a.id, a.content_id, a.total_score, a.issues, a.audit_time, c.title')
            ->order('a.audit_time', 'desc')
            ->limit(20)
            ->select();

        // 转数组 + 解析问题数量（供模板直接取 `issue_count`）
        $recentArr = [];
        foreach ($recent as $r) {
            $row = $r->toArray();
            $issues = json_decode((string) ($row['issues'] ?? ''), true);
            $row['issue_count'] = is_array($issues) ? count($issues) : 0;
            $recentArr[] = $row;
        }

        $this->assign('health', $health);
        $this->assign('recent', $recentArr);
        return $this->fetch('admin/seo/index');
    }

    /** 审计单条内容（问题下钻：返回检查项明细） */
    public function audit()
    {
        $contentId = (int) $this->request->param('id');
        try {
            $result = (new SeoAuditService())->auditContent($contentId);
            return $this->success('', $result);
        } catch (WisecmsException $e) {
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            cms_log('error', 'SEO 审查失败: ' . $e->getMessage(), ['id' => $contentId]);
            return $this->error('SEO 审查失败，请查看系统日志');
        }
    }

    /** 推送到搜索引擎（POST ids 数组，本站点过滤） */
    public function push()
    {
        $ids = (array) $this->request->post('ids/a', []);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn ($id) => $id > 0);
        if (empty($ids)) {
            return $this->error('请选择要推送的内容');
        }

        // 本站点过滤（推送只针对后台当前站点的已发布内容，越界静默剔除）
        $owned = \plugin\wisecms\model\Content::whereIn('id', $ids)
            ->where('site_id', $this->siteId())
            ->column('id');
        if (empty($owned)) {
            return $this->error('所选内容均不属于当前站点');
        }

        try {
            $result = (new SeoPushService())->pushAll(array_values($owned));
            return $this->success('推送完成', $result);
        } catch (\Exception $e) {
            cms_log('error', '搜索引擎推送失败: ' . $e->getMessage());
            return $this->error('推送失败，请查看系统日志');
        }
    }

    /** 推送日志列表（固定页长 20，无筛选参数） */
    public function pushLog()
    {
        $list = SeoPushLog::order('id', 'desc')->paginate(20);
        $arr  = $list->toArray();

        if ($this->request->isAjax()) {
            return $this->success('', $arr);
        }

        $this->assign('list', $arr);
        return $this->fetch('admin/seo/push_log');
    }
}
