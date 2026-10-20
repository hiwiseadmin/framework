/**
 * 邮件订阅页面控制器（UIUX-P0-24）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/newsletter`
 * （`NewsletterController` → snake_case `newsletter`）。
 *
 * 对应控制器动作：
 *   newsletter/index      → 列表（标准 paginate 契约；**固定页长 20**，无筛选/搜索参数）
 *   newsletter/delete     → **硬删除**（表无软删、无回收站）
 *   newsletter/batchDelete→ 批量删除（POST `ids[]`，P1-18）
 *   newsletter/export     → 导出 CSV（GET 下载，含 BOM + 公式注入防护，P1-18）
 *
 * 后端没有的能力（宁缺勿假）：
 *   · **无筛选**（`Newsletter::index` 不读任何参数）→ 不放搜索框；
 *   · **无 `edit` / `toggleStatus`** → 状态列是**只读徽章**。
 *
 * 关于「邮箱脱敏」（P0-24 的 DoD）：
 *   列表默认显示掩码（`ab***@example.com`），完整地址仅在**点击复制**时使用 —— 既避免整屏泄露
 *   订阅者邮箱，又保留把地址贴到邮件工具里的能力。`cmsCopy()` 优先走内核 `Wise.ui.clipboard`。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsConfirm, cmsAction, cmsBatch, cmsEscape, cmsCopy,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('newsletter/index'),
    del: cmsUrl('newsletter/delete'),
    batchDel: cmsUrl('newsletter/batchDelete'),
    export: cmsUrl('newsletter/export'),
};

const STATUS_MAP = { 0: '待确认', 1: '已确认', 2: '已退订' };

/**
 * 邮箱脱敏：本地部分保留前 2 字符，域名保留
 *
 * 例：`zhangsan@example.com` → `zh***@example.com`
 */
function maskEmail(raw) {
    const email = String(raw || '');
    const at = email.lastIndexOf('@');
    if (at <= 0) {
        return email ? '******' : '';
    }

    const local = email.slice(0, at);
    return local.slice(0, Math.min(2, local.length)) + '***' + email.slice(at);
}

/**
 * 标签徽章
 *
 * 与 Webhook 的 `events` 同理：内核 `tags` formatter 按 `,` 切分但**不 trim**
 * （`enhanced-table.js:2454`），`"a, b"` 会渲染出带前导空格的 badge，故自己拼。
 */
function tagsCell(value) {
    const items = String(value || '').split(',').map((s) => s.trim()).filter(Boolean);
    if (!items.length) {
        return '<span class="text-muted">—</span>';
    }

    return items.map((t) => '<span class="badge bg-light text-dark me-1">' + cmsEscape(t) + '</span>').join('');
}

export default {
    /** 列表页入口 */
    index() {
        let list = null;

        /** 复制完整邮箱（脱敏只影响显示） */
        function copyEmail(row) {
            return cmsCopy(row.email || '').then((ok) => {
                cmsToast(ok ? '邮箱已复制' : '复制失败，请手动选择复制', ok ? 'success' : 'warning');
            });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(20)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,
            // P1-18：批量删除 → 开勾选列（⚠️ 必须有 {checkbox:true} 列，否则复选框不渲染，见 §1.1㉒）
            checkboxHeader: true,

            columns: [
                { checkbox: true },
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'email',
                    title: '邮箱（脱敏）',
                    align: 'left',
                    formatter: (value, row) => '<span class="text-nowrap">' + cmsEscape(maskEmail(row.email)) + '</span>'
                        + ' <a href="javascript:;" class="text-muted ms-1" data-action="copy-email" data-id="'
                        + cmsEscape(row.id) + '" title="复制完整邮箱"><i class="bi bi-clipboard"></i></a>',
                },
                {
                    field: 'name',
                    title: '订阅人',
                    width: 130,
                    align: 'left',
                    formatter: (value) => (value || '<span class="text-muted">匿名</span>'),
                },
                { field: 'tags', title: '标签', width: 200, align: 'left', formatter: tagsCell },
                {
                    // 无 toggleStatus 接口 → 只读徽章（订阅状态由确认/退订链路写入）
                    field: 'status',
                    title: '状态',
                    width: 100,
                    align: 'center',
                    formatter: 'status',
                    searchList: STATUS_MAP,
                    custom: { 0: 'warning', 1: 'success', 2: 'secondary' },
                },
                { field: 'create_time', title: '订阅时间', width: 170, align: 'center', formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 90,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'del', title: '删除订阅记录', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-export-newsletter',
                    name: 'export',
                    title: '导出 CSV',
                    icon: 'bi bi-download',
                    class: 'btn btn-sm btn-outline-primary',
                    // 导出走 GET 下载（后端返回 text/csv），直接跳转触发浏览器下载
                    click: () => { window.location.href = URL.export; },
                },
                {
                    id: 'btn-batch-del-newsletter',
                    name: 'batchDelete',
                    title: '批量删除',
                    icon: 'bi bi-trash',
                    class: 'btn btn-sm btn-outline-danger',
                    click: () => {
                        const ids = window.Wise.table.getSelectedIds('#table');
                        return cmsBatch({
                            url: URL.batchDel,
                            ids: Array.isArray(ids) ? ids : [],
                            refresh: list,
                            confirmMessage: '确定删除选中的订阅记录？<br>'
                                + '<span class="text-danger">订阅是个人信息，删除后不可恢复。</span>',
                            successMsg: '已删除选中的订阅记录',
                        });
                    },
                },
                {
                    id: 'btn-refresh-newsletter',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'copy-email') return copyEmail(row);

                if (action === 'del') {
                    return cmsConfirm(
                        '确定删除订阅记录 <code>' + cmsEscape(maskEmail(row.email)) + '</code>？<br>'
                        + '删除后该邮箱<b>不再出现在订阅列表</b>，且没有回收站。',
                        '删除订阅记录'
                    ).then((ok) => (ok
                        ? cmsAction({
                            url: URL.del,
                            data: { id: row.id },
                            refresh: list,
                            successMsg: '订阅记录已删除',
                        })
                        : undefined));
                }
                return undefined;
            },
        });

        return list;
    },
};
