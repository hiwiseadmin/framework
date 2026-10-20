/**
 * 多站点管理页面控制器（UIUX-P0-25）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/site`
 * （`SiteController` → snake_case `site`）。列表与表单共用本模块。
 *
 * 对应控制器动作：
 *   site/index  → 列表（标准 paginate 契约；**固定页长 15**，无筛选参数）
 *   site/create → 表单 + 提交（POST 返回 JSON）
 *   site/edit   → 表单 + 提交（POST 返回 JSON）
 *   site/options→ 站点选择器数据源 `{current, list:[{id,name,domain}]}`（**仅启用站点**）
 *   site/switch → 切换后台当前站点（会话级，不改业务数据）
 *   site/delete → **硬删除**：服务层先做「有内容/栏目/单页则拒绝」，通过后再级联清理附属数据
 *
 * ⚠️ 四个已核实的事实：
 *   1. **删除是"两道门"**：`SiteService::deleteSite()` 在该站点仍有 `内容 + 栏目 + 单页`
 *      （**含回收站**）时**直接拒绝**；只有全为 0 才继续级联清理
 *      （别名 / URL 规则 / 友链 / 内链 / 重定向 / 标签）。因此列表必须把这三个数前置显示
 *      —— 后端 `Site::index` 已附带 `data_counts`（用原生查询 = 不过软删过滤，与闸门口径一致）。
 *   2. **没有 `toggleStatus`** → 状态列是**只读徽章**（禁用站点不会出现在站点选择器里）。
 *   3. **「当前站点」是会话上下文**，与「默认站点」（排序最前的启用站点）不是一回事：
 *      前者会随选择器变化，后者只是会话未设置时的兜底。
 *   4. 切换站点会改变**所有按站点隔离的数据**（内容/栏目/友链… 都以当前站点为准），
 *      故切换成功后需要整页重载 —— 这是 P0-41「禁止整页 reload」的**唯一例外**。
 *
 * 未提供（后端不支持，宁缺勿假）：
 *   · 状态筛选 / 搜索（`Site::index` 无任何参数）。
 *   （P1-30 已落地：`setting`/`seo_setting` 的键值对编辑器在 `site/edit.html`，
 *    以 `setting[key]` / `seo_setting[key]` 数组提交，前台 `assignFrontData` 已消费。）
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsDangerConfirm,
    cmsEscape, cmsOperateButtons,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('site/index'),
    create: cmsUrl('site/create'),
    edit: cmsUrl('site/edit'),
    del: cmsUrl('site/delete'),
    options: cmsUrl('site/options'),
    switchTo: cmsUrl('site/switch'),
};

/** L3 确认词 = 站点名（用户必须逐字输入） */
function dangerWord(row) {
    return String(row.name || '').trim();
}

/** 站点数据量：内容 / 栏目 / 单页（**含回收站**，与删除闸门口径一致） */
function dataCell(value, row) {
    const counts = row.data_counts || {};
    const content = parseInt(counts.content, 10) || 0;
    const category = parseInt(counts.category, 10) || 0;
    const page = parseInt(counts.page, 10) || 0;
    const total = content + category + page;

    const detail = '<div class="text-muted small">内容 ' + content
        + ' / 栏目 ' + category + ' / 单页 ' + page + '</div>';

    return total === 0
        ? '<span class="badge bg-success" title="无内容/栏目/单页，可删除">可删除</span>' + detail
        : '<span class="badge bg-warning text-dark" title="删除前必须清空该站点的内容、栏目与单页">有数据</span>' + detail;
}

/** 取站点选择器数据（当前站点 + 启用站点列表） */
async function loadOptions() {
    const wise = window.Wise || {};
    if (!(wise.util && wise.util.http && typeof wise.util.http.get === 'function')) {
        return null;
    }
    try {
        // 内核 http 默认 interceptor:true → resolve 的就是信封里的 data
        return await wise.util.http.get(URL.options);
    } catch (e) {
        return null;    // 无站点权限时会 403，静默降级（只是不显示"当前站点"标记）
    }
}

/** 表单页（create / edit 共用） */
function bindForm() {
    const $form = $('#siteForm');
    if (!$form.length) return;

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsSiteFormDone === 'function') {
                window.parent.cmsSiteFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        const res = await cmsAction({
            url: $form.attr('action'),
            data: new FormData(this),
            successMsg: '保存成功',
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-site-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口 */
    async index() {
        let list = null;
        let currentSiteId = 0;

        const options = await loadOptions();
        if (options && options.current) {
            currentSiteId = parseInt(options.current, 10) || 0;
        }

        /** 切换后台当前站点：会话上下文变了 → 按站点隔离的数据都要重取，故整页重载 */
        async function switchTo(row) {
            const res = await cmsAction({
                url: URL.switchTo,
                data: { site_id: row.id },
                successMsg: '已切换到「' + (row.name || '') + '」',
            });
            if (res && res.success) {
                setTimeout(() => window.location.reload(), 500);
            }
        }

        /**
         * 删除站点
         *
         * 站点下还有内容/栏目/单页（含回收站）时后端**必然拒绝**，故此时不再让人逐字输入
         * 确认词（输完也被拒），改为直接说明「先清空什么」；只有数据为 0 时才走 L3。
         */
        async function removeSite(row) {
            const counts = row.data_counts || {};
            const content = parseInt(counts.content, 10) || 0;
            const category = parseInt(counts.category, 10) || 0;
            const page = parseInt(counts.page, 10) || 0;

            if (content + category + page > 0) {
                const wise = window.Wise || {};
                const html = '<div class="alert alert-warning py-2 mb-0">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '站点「<b>' + cmsEscape(row.name || '') + '</b>」下仍有 '
                    + '<b>' + content + '</b> 篇内容 / <b>' + category + '</b> 个栏目 / <b>' + page + '</b> 个单页'
                    + '（<b>含回收站</b>），删除<b>会被拒绝</b>。'
                    + '<div class="mt-2 small text-muted">请先把该站点的内容与栏目清空'
                    + '（内容需在回收站里「彻底删除」，栏目需逐个删除），再回来删除站点。</div>'
                    + '</div>';

                if (wise.ui && wise.ui.dialog) {
                    wise.ui.dialog.open(html, '暂不能删除该站点', {
                        area: ['560px', '320px'],
                        btn: ['知道了'],
                        yes: (index) => wise.ui.dialog.close(index),
                    });
                } else {
                    cmsToast('该站点仍有内容/栏目/单页（含回收站），请先清空', 'warning');
                }
                return;
            }

            const ok = await cmsDangerConfirm({
                title: '删除站点（不可恢复）',
                word: dangerWord(row),
                confirmText: '确认删除',
                area: ['580px', '420px'],
                html: '<div class="alert alert-danger py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将删除站点「<b>' + cmsEscape(row.name || '') + '</b>」'
                    + '（域名 <code>' + cmsEscape(row.domain || '（未设置）') + '</code>）。'
                    + '</div>'
                    + '<div class="small">该站点已无内容 / 栏目 / 单页，删除时会一并清理其<b>附属数据</b>：'
                    + 'URL 别名、URL 规则、友情链接、内链、重定向、标签。<br>'
                    + '以上数据删除后<b>无法恢复</b>。</div>',
            });
            if (!ok) return;

            await cmsAction({
                url: URL.del,
                data: { id: row.id },
                refresh: list,
                successMsg: '站点已删除',
            });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(15)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', formatter: 'normal' },
                {
                    field: 'logo',
                    title: 'LOGO',
                    width: 90,
                    align: 'center',
                    formatter: (value) => (value
                        ? '<img src="' + value + '" alt="logo" style="height:28px;max-width:70px;object-fit:contain">'
                        : '<span class="text-muted">—</span>'),
                },
                {
                    field: 'name',
                    title: '站点名称',
                    width: 200,
                    align: 'left',
                    formatter: (value, row) => (value || '')
                        + (parseInt(row.id, 10) === currentSiteId
                            ? ' <span class="badge bg-primary" title="后台当前编辑上下文">当前站点</span>'
                            : ''),
                },
                {
                    field: 'domain',
                    title: '域名',
                    align: 'left',
                    formatter: (value) => {
                        if (!value) return '<span class="text-muted">未绑定（按排序兜底）</span>';
                        const href = /^https?:\/\//i.test(value) ? value : ('http://' + value);

                        return '<a href="' + cmsEscape(href) + '" target="_blank" rel="noopener noreferrer">'
                            + cmsEscape(value) + '</a>';
                    },
                },
                { field: 'sort', title: '排序', width: 70, align: 'center', formatter: 'normal' },
                { field: 'data_counts', title: '站点数据', width: 150, align: 'center', formatter: dataCell },
                {
                    // 无 toggleStatus 接口 → 只读徽章（禁用站点不会出现在站点选择器中）
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '禁用', 1: '启用' },
                    custom: { 0: 'secondary', 1: 'success' },
                },
                { field: 'create_time', title: '创建时间', width: 165, align: 'center', visible: false, formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 200,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => cmsOperateButtons([
                        { action: 'edit', title: '编辑站点', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                        {
                            action: 'switch',
                            title: '切换到此站点',
                            icon: 'bi bi-arrow-left-right',
                            class: 'btn-outline-info',
                            // 已是当前站点就不再提供（避免"点了没反应"）
                            show: (r) => parseInt(r.id, 10) !== currentSiteId,
                        },
                        { action: 'del', title: '删除站点', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-site',
                    name: 'add',
                    title: '添加站点',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => {
                        const index = window.Wise.ui.dialog.open(URL.create, '添加站点', {
                            area: ['760px', '540px'],
                            resize: true,
                        });
                        window.cmsSiteFormDone = function () {
                            try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                            cmsRefresh(list);
                        };
                    },
                },
                {
                    id: 'btn-refresh-site',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    const index = window.Wise.ui.dialog.open(
                        URL.edit + '?id=' + encodeURIComponent(row.id),
                        '编辑站点',
                        { area: ['760px', '540px'], resize: true }
                    );
                    window.cmsSiteFormDone = function () {
                        try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                        cmsRefresh(list);
                    };
                    return undefined;
                }
                if (action === 'switch') return switchTo(row);
                if (action === 'del') return removeSite(row);
                return undefined;
            },
        });

        return list;
    },

    /** 添加站点表单页 */
    create() {
        bindForm();
    },

    /** 编辑站点表单页 */
    edit() {
        bindForm();
    },
};
