/**
 * 单页管理页面控制器（UIUX-P0-15 列表 + P1-29 回收站）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/page`
 * （`PageController` → snake_case `page`）。**列表页与表单页共用本模块**：
 * `index()` 管列表（含回收站视图），`create()` / `edit()` 管两个表单页的提交。
 *
 * 对应控制器动作：
 *   page/index   → 列表（标准 paginate 契约；读 `keyword`/`page`/`limit`/`trashed`）
 *                  已按站点过滤，并返回派生字段 `summary` 与关联 `category`
 *   page/create  → 表单 + 提交（POST 返回 JSON）
 *   page/edit    → 表单 + 提交（POST 返回 JSON；也用于状态开关）
 *   page/delete  → 软删（`SoftDelete`）
 *   page/restore → 恢复回收站单页（P1-29，校验别名未被占用）
 *
 * ⚠️ 四点已核实的事实（决定了本页的写法）：
 *   1. 表单页原为**裸表单 POST**，而控制器的 POST 分支返回 JSON（`$this->success()`）
 *      → 提交后浏览器直接显示 `{"code":0,...}`：数据其实已保存，但页面「看起来坏了」。
 *      故两个表单页的提交改为走 `page.js`（模板内联脚本清零）。
 *   2. 无 `toggleStatus` 接口 → 状态开关**复用 `page/edit`**，提交 `{id, status}`：
 *      `MassAssignmentGuard` 对 `wisecms_page` 的黑名单只含 `view_count`/`site_id`
 *      （`status` 可写），且 `Page::edit` 收到单字段也能正常部分更新。
 *   3. 内核 `formatter: 'toggle'` 自 P1-27 起**内置处理器**（click 委托提交），
 *      `cmsBindRowToggles()` 已退化为兜底；本页保留调用作为「本页有开关」的标记。
 *   4. P1-29：回收站视图 `trashed=1`，软删行的状态开关 `disable`、操作列换「恢复」、
 *      标题不可点编辑；删除文案改为「移至回收站（可恢复）」。
 *
 * 未提供（后端不支持，宁缺勿假）：
 *   · 关键词以外的筛选（`Page::index` 只认 `keyword`）；
 *   · 列表排序（无 `order` 参数）；
 *   · 状态筛选（无 `status` 参数）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsAction, cmsDelete,
    cmsEscape, cmsBindRowToggles,
    cmsCopy, cmsBindRowContextMenu, cmsBindShortcuts,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('page/index'),
    create: cmsUrl('page/create'),
    edit: cmsUrl('page/edit'),
    del: cmsUrl('page/delete'),
    restore: cmsUrl('page/restore'),
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

/** 回收站视图的操作（仅恢复，软删行不可编辑） */
const RESTORE_OPERATE = [
    { action: 'restore', title: '恢复', icon: 'bi bi-arrow-counterclockwise', class: 'btn-outline-success' },
];

/** 状态开关的展示文案（提交目标值语义，幂等） */
const STATUS_LABEL = { 1: '已发布', 0: '草稿' };

/**
 * 表单页提交（create / edit 共用）
 *
 * 用 `FormData` 而非手拼对象：原生处理 `seo[title]` 这类嵌套命名；
 * 内核 http 对 FormData 直接作 body、不覆盖 `Content-Type` → multipart 正确。
 *
 * @param {string} defaultMsg 默认成功提示
 */
function bindForm(defaultMsg) {
    const $form = $('#pageForm');
    if (!$form.length) return;

    function goBack() {
        // 以 iframe 弹层打开时：优先回调父列表页注册的钩子（关弹层 + 局部刷新）
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsPageFormDone === 'function') {
                window.parent.cmsPageFormDone();
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
            successMsg: defaultMsg,
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-page-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口（与 action 同名，由 wise-bootstrap 调用） */
    index() {
        let list = null;
        // P1-29：回收站视图开关（点击筛选条「回收站」切换）
        let isTrashed = false;

        /**
         * 打开单页表单（iframe 弹层）。
         *
         * 单页表单字段多（含正文 textarea 与整组 SEO），按方案 §4.3「表单四形态」
         * 属「整页表单」而非抽屉；这里用 iframe 弹层承载原整页表单，
         * 保存后由表单页回调 `cmsPageFormDone()` → 关弹层 + 只刷表格（不整页 reload）。
         */
        function openForm(url, title) {
            const index = window.Wise.ui.dialog.open(url, title, { area: ['900px', '720px'], resize: true });

            window.cmsPageFormDone = function () {
                try {
                    window.Wise.ui.dialog.close(index);
                } catch (e) {
                    // 弹层可能已被用户手动关闭，忽略
                }
                cmsRefresh(list);
            };

            return index;
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            pageSize: 15,

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'title',
                    title: '标题',
                    width: 230,
                    align: 'left',
                    // 标题即可点进编辑（P0-15 DoD）：复用内核的操作列代理
                    // （`[data-action]` + `data-id`，见 wise-table.js:289-318）
                    // P1-29：回收站视图的软删行不可编辑 → 纯文本不生成编辑链接
                    formatter: (value, row) => (isTrashed
                        ? '<span title="' + cmsEscape(row.title || '') + '">' + (value || '') + '</span>'
                        : '<a href="javascript:;" data-action="edit" data-id="'
                            + cmsEscape(row.id) + '" title="点击编辑">' + (value || '')
                            + '</a>'),
                },
                {
                    field: 'summary',
                    title: '摘要',
                    align: 'left',
                    formatter: (value) => (value
                        ? '<span class="d-inline-block text-truncate" style="max-width:360px" title="'
                            + value + '">' + value + '</span>'
                        : '<span class="text-muted">—</span>'),
                },
                {
                    field: 'alias',
                    title: '别名',
                    width: 140,
                    align: 'left',
                    formatter: (value) => (value
                        ? '<code>' + value + '</code>'
                        : '<span class="text-muted">未设置</span>'),
                },
                {
                    field: 'category.name',
                    title: '关联栏目',
                    width: 130,
                    align: 'left',
                    formatter: (value) => (value || '<span class="text-muted">不关联</span>'),
                },
                {
                    field: 'template',
                    title: '模板',
                    width: 130,
                    align: 'left',
                    formatter: (value) => (value || '<span class="text-muted">默认</span>'),
                },
                {
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    // 内核只渲染，提交由下面的 cmsBindRowToggles() 补（见文件头说明 3）
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.edit,
                    labels: { on: '发布', off: '草稿' },
                    // P1-29：回收站视图的软删行不可改状态 → 禁用开关（显示删除前的状态，只读）
                    disable: (value, row) => isTrashed,
                },
                { field: 'view_count', title: '浏览', width: 80, align: 'center', visible: false, formatter: 'normal' },
                { field: 'create_time', title: '创建时间', width: 160, align: 'center', formatter: 'datetime' },
                {
                    field: 'delete_time',
                    title: '删除时间',
                    width: 160,
                    align: 'center',
                    // 普通视图 delete_time 恒为 NULL → 显示「—」；回收站视图显示删除时间
                    formatter: (value) => (value
                        ? String(value).slice(0, 16)
                        : '<span class="text-muted">—</span>'),
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 110,
                    align: 'center',
                    clickToSelect: false,
                    // P1-29：回收站视图只给「恢复」，普通视图给「编辑 / 删除」
                    formatter: (value, row) => window.Wise.table.operateButtons(
                        isTrashed ? RESTORE_OPERATE : OPERATE, row
                    ),
                },
            ],

            query() {
                const keyword = ($('#filter-keyword').val() || '').trim();
                const q = keyword ? { keyword: keyword } : {};
                if (isTrashed) q.trashed = 1;
                return q;
            },

            toolbarBtn: [
                {
                    id: 'btn-add-page',
                    name: 'add',
                    title: '新建单页',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => openForm(URL.create, '添加单页'),
                },
                {
                    id: 'btn-refresh-page',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    return openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑单页');
                }
                if (action === 'del') {
                    return cmsDelete({
                        url: URL.del,
                        data: { id: row.id },
                        refresh: list,
                        // ⚠️ 确认框按 HTML 渲染，而 row 是**未转义原始数据** → 必须转义
                        confirmMessage: '确定删除单页「' + cmsEscape(row.title || '') + '」？<br>'
                            + '将移至<b>回收站</b>（可恢复）；'
                            + '<span class="text-muted">删除期间其别名仍被占用（唯一键含已删除单页）。</span>',
                        successMsg: '单页已移至回收站',
                    });
                }
                if (action === 'restore') {
                    // P1-29：恢复回收站单页（软删行不可编辑，恢复后回到普通列表）
                    return cmsAction({
                        url: URL.restore,
                        data: { id: row.id },
                        refresh: list,
                        successMsg: '单页已恢复',
                    });
                }
                return undefined;
            },
        });

        // 状态开关（发布 / 草稿）：提交 {id, status} 到 page/edit，失败自动回滚勾选
        cmsBindRowToggles({
            url: URL.edit,
            refresh: list,
            message: (field, target) => '已改为「' + (STATUS_LABEL[target] || target) + '」',
        });

        // ---- P1-12：行右键菜单（回收站视图只有「恢复」，普通视图编辑/删除） ----
        cmsBindRowContextMenu('#table', list, (row) => {
            const copyId = {
                id: 'copyId', label: '复制 ID', icon: 'bi bi-clipboard', action: async () => {
                    const ok = await cmsCopy(String(row.id));
                    cmsToast(ok ? 'ID 已复制' : '复制失败，请手动复制：' + row.id, ok ? 'success' : 'warning');
                },
            };
            if (isTrashed) {
                return [
                    { id: 'restore', label: '恢复', icon: 'bi bi-arrow-counterclockwise', action: () => cmsAction({
                        url: URL.restore, data: { id: row.id }, refresh: list, successMsg: '单页已恢复',
                    }) },
                    copyId,
                ];
            }
            return [
                { id: 'edit', label: '编辑', icon: 'bi bi-pencil', action: () => openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑单页') },
                copyId,
                { divider: true },
                { id: 'del', label: '删除', icon: 'bi bi-trash', action: () => cmsDelete({
                    url: URL.del,
                    data: { id: row.id },
                    refresh: list,
                    confirmMessage: '确定删除单页「' + cmsEscape(row.title || '') + '」？<br>'
                        + '将移至<b>回收站</b>（可恢复）；'
                        + '<span class="text-muted">删除期间其别名仍被占用（唯一键含已删除单页）。</span>',
                    successMsg: '单页已移至回收站',
                }) },
            ];
        });

        // ---- P1-11：键盘快捷键（`/` 聚焦搜索、`n` 新建、`r` 刷新） ----
        cmsBindShortcuts({
            search: () => { $('#filter-keyword').trigger('focus'); },
            create: () => openForm(URL.create, '添加单页'),
            refresh: () => cmsRefresh(list),
        });

        // 筛选条
        $('#btn-search-page').on('click', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                cmsRefresh(list);
            }
        });
        $('#btn-reset-page').on('click', () => {
            $('#filter-keyword').val('');
            cmsRefresh(list);
        });

        // P1-29：回收站视图切换（切换 isTrashed → query 带 trashed=1，操作列换「恢复」）
        $('#btn-trash-page').on('click', () => {
            isTrashed = !isTrashed;
            const $btn = $('#btn-trash-page');
            $btn.toggleClass('btn-outline-secondary btn-warning');
            $btn.html(isTrashed
                ? '<i class="bi bi-trash3-fill me-1"></i>返回列表'
                : '<i class="bi bi-trash3 me-1"></i>回收站');
            cmsRefresh(list);
        });

        return list;
    },

    /** 添加单页表单页 */
    create() {
        bindForm('单页创建成功');
    },

    /** 编辑单页表单页 */
    edit() {
        bindForm('单页更新成功');
    },
};
