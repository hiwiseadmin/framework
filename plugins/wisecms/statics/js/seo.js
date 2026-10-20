/**
 * SEO 总览页面控制器（UIUX-P1-20）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/seo`
 * （`SeoController` → snake_case `seo`）。
 *
 * 对应控制器动作：
 *   seo/index   → 健康概览 + 最近审查（本模块 `index()` 处理「明细」「推送」交互）
 *   seo/audit   → 审计单条内容（问题下钻：返回检查项明细）
 *   seo/push    → 推送到搜索引擎（百度/Google/Bing）
 *   seo/pushLog → 推送日志列表（本模块 `pushLog()` 渲染）
 *
 * ⚠️ 两点已核实的事实：
 *   1. 推送是**同步外呼**（`SeoPushService` 用 curl 调百度/Google/Bing，单条 10s 超时）
 *      → 与采集/死链同款：不进假进度条，提交后 loading，完成后回显各引擎 `success` 数。
 *   2. `SeoAudit`/`SeoPushLog` 均无 `site_id` → 控制器里按内容归属过滤（`push` 过滤本站点，
 *      `index` 最近审查 join content 过滤）；日志列表页**跨站**显示（日志表无站点归属）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsEscape,
} from './cms-admin.js';

const URL = {
    audit: cmsUrl('seo/audit'),
    push: cmsUrl('seo/push'),
    pushLog: cmsUrl('seo/pushLog'),
};

/** 审计结果明细弹层（问题下钻） */
function renderAuditDetail(d) {
    const wise = window.Wise || {};
    const score = Number(d.total_score) || 0;
    const level = d.level || '—';
    const issues = Array.isArray(d.issues) ? d.issues : [];

    let issueHtml = '';
    if (!issues.length) {
        issueHtml = '<div class="text-success small"><i class="bi bi-check-circle me-1"></i>未发现问题</div>';
    } else {
        issueHtml = '<ul class="list-group list-group-flush small">' + issues.map((it) => (
            '<li class="list-group-item px-0 d-flex">'
            + '<span class="badge bg-warning text-dark me-2 align-self-start">' + cmsEscape(it.name || it.check || '') + '</span>'
            + '<span>' + cmsEscape(it.message || '') + '</span>'
            + '</li>'
        )).join('') + '</ul>';
    }

    const html = '<div class="d-flex align-items-center justify-content-between mb-3">'
        + '<div class="fw-bold">SEO 检测明细</div>'
        + '<div><span class="badge bg-primary fs-6">' + cmsEscape(level) + '</span> '
        + '<span class="fs-6 fw-bold">' + score + '</span><span class="text-muted small"> / 100</span></div>'
        + '</div>'
        + '<div class="text-muted small mb-2">问题项（' + issues.length + '）</div>'
        + issueHtml;

    if (wise.ui && wise.ui.dialog) {
        wise.ui.dialog.open(html, 'SEO 检测明细', { area: ['560px', '60%'], shadeClose: true });
    } else {
        cmsToast('弹层组件未就绪', 'warning');
    }
}

export default {
    /** SEO 总览页（健康概览 + 最近审查的交互） */
    index() {
        // 问题下钻：查看单条审计明细
        $('.cms-seo-detail').on('click', function () {
            const id = parseInt($(this).data('id'), 10);
            if (!(id > 0)) return;
            const wise = window.Wise || {};
            if (!(wise.util && wise.util.http && typeof wise.util.http.get === 'function')) {
                cmsToast('请求组件未就绪', 'error');
                return;
            }
            // 内核 http 默认 interceptor:true → resolve 的就是 data
            wise.util.http.get(URL.audit, { id: id })
                .then((d) => renderAuditDetail(d || {}))
                .catch(() => cmsToast('明细加载失败，请稍后重试', 'error'));
        });

        // 推送：读内容 ID（逗号分隔）→ seo/push（同步外呼，loading 遮罩）
        $('#btn-seo-push').on('click', function () {
            const raw = String($('#seoPushIds').val() || '').trim();
            const ids = raw.split(',').map((s) => parseInt(s.trim(), 10)).filter((v) => v > 0);
            if (!ids.length) {
                cmsToast('请输入要推送的内容 ID（逗号分隔）', 'warning');
                return;
            }
            return cmsAction({
                url: URL.push,
                data: { ids: ids },
                successMsg: '推送完成',
            });
        });
    },

    /** 推送日志列表 */
    pushLog() {
        const list = cmsTable({
            url: URL.pushLog,
            shape: 'standard',
            // 后端 `->paginate(20)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                { field: 'content_id', title: '内容 ID', width: 90, align: 'center', formatter: 'normal' },
                {
                    field: 'engine',
                    title: '引擎',
                    width: 100,
                    align: 'center',
                    formatter: (value) => {
                        const map = { baidu: '百度', google: 'Google', bing: 'Bing' };
                        return '<span class="badge bg-secondary">' + cmsEscape(map[value] || value || '—') + '</span>';
                    },
                },
                {
                    field: 'url',
                    title: '推送 URL',
                    align: 'left',
                    formatter: (value) => (value
                        ? '<span class="d-inline-block text-truncate" style="max-width:420px" title="'
                            + cmsEscape(value) + '">' + cmsEscape(value) + '</span>'
                        : '<span class="text-muted">—</span>'),
                },
                { field: 'response_code', title: '响应码', width: 90, align: 'center', formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '失败', 1: '成功' },
                    custom: { 0: 'danger', 1: 'success' },
                },
                { field: 'create_time', title: '推送时间', width: 165, align: 'center', formatter: 'datetime' },
            ],

            toolbarBtn: [
                {
                    id: 'btn-refresh-seo-log',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],
        });

        return list;
    },
};
