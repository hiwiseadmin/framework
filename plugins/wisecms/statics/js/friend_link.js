/**
 * 友情链接页面控制器（UIUX-P0-21 列表 + P0-33 批量删除与拖拽排序）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/friend_link`
 * （`FriendLinkController` → snake_case `friend_link`）。列表与表单共用本模块
 * （控制器对 create/edit 复用同一个 `view/admin/friend_link/form.html`）。
 *
 * 对应控制器动作：
 *   friendLink/index        → 列表（标准 paginate 契约；读 `keyword`/`status`/`group_name`/`page`/`limit`）
 *   friendLink/create|edit  → 共用表单 + POST 保存
 *   friendLink/delete       → 软删（**后台无友链回收站**：没有任何 restore 接口）
 *   friendLink/batchDelete  → 批量软删，入参 `ids` 必须是**数组**
 *   friendLink/toggleStatus → 上下架（**目标值语义**，后端已正确）
 *   friendLink/sort         → 批量排序，入参 `orders` = `[{id, sort}]`（数组或 JSON 串）
 *
 * ⚠️ 四处已核实的事实：
 *   1. **必须有 `{ checkbox: true }` 列**：bootstrap-table 只在存在该列时渲染复选框
 *      （内核不会自动注入）。只写 `checkboxHeader: true` 的效果是"没有任何勾选框"——
 *      `getSelectedIds()` 读的是 `getSelections()`，于是批量按钮永远提示"请先勾选"。
 *      P0-10 的内容页也踩了这个坑，已一并修（见 §1.1㉒）。
 *   2. **不能直接用 `extend.dragsort_url`**：内建拖拽发 `{ids:"1,2,3", changeid, ...}`，
 *      而 `FriendLink::sort` 读 `orders[][id]`/`orders[][sort]` → 接了会静默假成功。
 *      故用 SortableJS 自组 payload（同 §1.1⑫ 的结论）。
 *   3. **`ids` 必须传数组**：后端是 `(array) $this->request->post('ids')`，逗号串会退化成
 *      单元素数组 → 只删 1 条（§1.1⑩）。`cmsBatch()` 正好发数组，故批量删除走它。
 *   4. **排序方向是 `sort desc, id desc`**：拖拽后把当前页重新赋一串**递减**的 sort 值，
 *      基准取「本页最大 sort + 页长」，既保证本页新顺序生效、又不会与其他页的值打架。
 *
 * 未提供（后端不支持，宁缺勿假）：
 *   · 友链回收站 / 恢复入口（软删但无 restore 接口）；删除按 L2 处理并在确认框说明；
 *   · 列表排序（`getList()` 固定 `sort desc, id desc`，无 `order` 参数）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsConfirm, cmsAction, cmsBatch,
    cmsEscape, cmsBindRowToggles, cmsPeriodInfo,
    cmsCopy, cmsBindRowContextMenu, cmsBindShortcuts,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('friendLink/index'),
    create: cmsUrl('friendLink/create'),
    edit: cmsUrl('friendLink/edit'),
    del: cmsUrl('friendLink/delete'),
    batchDel: cmsUrl('friendLink/batchDelete'),
    toggle: cmsUrl('friendLink/toggleStatus'),
    sort: cmsUrl('friendLink/sort'),
};

/** 提交 `orders`（后端 `FriendLink::sort` 接受数组或 JSON 串，这里用 JSON 串走已验证路径） */
async function postOrders(orders) {
    const http = window.Wise && window.Wise.util ? window.Wise.util.http : null;
    if (!http) return false;

    try {
        await http.post(URL.sort, { orders: JSON.stringify(orders) });
        return true;
    } catch (e) {
        return false;   // 失败原因已由内核拦截器 toast
    }
}

/** 投放期单元格：时间窗 + 状态徽章（与广告页共用 `cmsPeriodInfo()` 口径） */
function periodCell(value, row) {
    const text = (v) => (v ? String(v).substring(0, 16) : '不限');
    const state = cmsPeriodInfo(row);

    return '<div class="small">' + cmsEscape(text(row.start_time)) + ' ~ '
        + cmsEscape(text(row.end_time)) + '</div>'
        + '<span class="badge ' + state.cls + '">' + state.text + '</span>';
}

/** 表单页（create/edit 共用同一模板） */
function bindForm() {
    const $form = $('#friendLinkForm');
    if (!$form.length) return;

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsFriendLinkFormDone === 'function') {
                window.parent.cmsFriendLinkFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        const res = await cmsAction({
            url: $form.attr('action'),
            // 用 FormData：表单里的时间字段是 `Y-m-d H:i` 文本，由后端 cms_datetime() 归一
            data: new FormData(this),
            successMsg: '保存成功',
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-friend-link-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口 */
    index() {
        let list = null;

        function selectedIds() {
            const ids = window.Wise.table.getSelectedIds('#table');
            return Array.isArray(ids) ? ids : [];
        }

        /**
         * 拖拽排序（P0-33）：SortableJS 自组 `orders`，每次表格重绘后重绑
         * （bootstrap-table 可能替换 tbody 元素，见 P0-29 的同类修复）。
         */
        function enableDragSort() {
            let boundBody = null;
            let instance = null;

            return function bind() {
                const tbody = document.querySelector('#table tbody');
                if (!tbody || tbody === boundBody) return;

                if (instance) {
                    try { instance.destroy(); } catch (e) { /* 忽略 */ }
                    instance = null;
                }

                if (typeof window.Sortable !== 'function') {
                    cmsToast('拖拽组件未加载，请改用「排序」列的数值输入', 'warning');
                    boundBody = tbody;
                    return;
                }

                boundBody = tbody;
                instance = window.Sortable.create(tbody, {
                    handle: '.cms-drag-handle',
                    animation: 150,
                    onEnd() {
                        const handles = Array.prototype.slice.call(tbody.querySelectorAll('.cms-drag-handle'));
                        const ids = handles.map((el) => parseInt(el.getAttribute('data-id'), 10)).filter((v) => v > 0);
                        if (!ids.length) return;

                        // 基准：本页现有最大 sort + 页长，赋一串递减值 → 本页新顺序即最终顺序
                        const rows = (list && list.table
                            ? list.table.bootstrapTable('getData')
                            : []) || [];
                        const maxSort = rows.reduce((m, r) => Math.max(m, parseInt(r.sort, 10) || 0), 0);
                        const step = Math.max(rows.length, 1);

                        const orders = ids.map((id, i) => ({ id: id, sort: maxSort + step - i }));

                        postOrders(orders).then((ok) => {
                            if (ok) cmsToast('排序已更新', 'success');
                            // 失败或成功都重新拉取：回到服务端真实顺序（避免界面与库不一致）
                            cmsRefresh(list);
                        });
                    },
                });
            };
        }

        const bindDrag = enableDragSort();

        /** 批量删除（软删，但**无回收站** → L2 明确说明不可恢复） */
        function batchDelete() {
            const ids = selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要删除的友链', 'warning');
                return;
            }

            return cmsBatch({
                url: URL.batchDel,
                ids: ids,
                confirmMessage: '将删除选中的 ' + ids.length + ' 条友情链接。'
                    + '友链<b>没有回收站</b>，后台也无法恢复，确定继续？',
                successMsg: '已删除选中的友链',
                refresh: list,
            });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            pageSize: 20,
            // 后端有 batchDelete（P0-33）→ 开批量；⚠️ 必须同时声明 { checkbox: true } 列
            checkboxHeader: true,

            columns: [
                { checkbox: true },
                { field: 'id', title: 'ID', width: 60, align: 'center', formatter: 'normal' },
                {
                    field: 'name',
                    title: '名称 / 地址',
                    align: 'left',
                    formatter: (value, row) => '<div class="fw-bold">' + (value || '') + '</div>'
                        + (row.url
                            ? '<a class="small" href="' + cmsEscape(row.url) + '" target="_blank"'
                                + ' rel="noopener noreferrer nofollow">' + cmsEscape(row.url) + '</a>'
                            : '')
                        + (row.description
                            ? '<div class="text-muted small">' + cmsEscape(row.description) + '</div>'
                            : ''),
                },
                {
                    field: 'group_name',
                    title: '分组',
                    width: 110,
                    align: 'left',
                    formatter: (value) => (value || '<span class="text-muted">默认组</span>'),
                },
                {
                    field: 'is_image',
                    title: '类型',
                    width: 80,
                    align: 'center',
                    formatter: (value, row) => (Number(value) === 1
                        ? '<span class="badge bg-info">图片</span>'
                            + (row.logo
                                ? '<div class="mt-1"><img src="' + cmsEscape(row.logo) + '" alt="logo"'
                                    + ' style="height:26px;max-width:70px;object-fit:contain"></div>'
                                : '<div class="text-danger small">缺 Logo</div>')
                        : '<span class="badge bg-secondary">文字</span>'),
                },
                { field: 'start_time', title: '投放期', width: 190, align: 'left', formatter: periodCell },
                { field: 'nofollow', title: 'nofollow', width: 90, align: 'center', visible: false, formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.toggle,
                    labels: { on: '显示', off: '隐藏' },
                },
                {
                    field: 'sort',
                    title: '排序',
                    width: 130,
                    align: 'center',
                    // 拖拽手柄（SortableJS 的 handle）+ 数值输入兜底（无拖拽库时仍可改排序）
                    formatter: (value, row) => '<div class="d-flex align-items-center justify-content-center">'
                        + '<a href="javascript:;" class="cms-drag-handle text-muted me-1" data-id="'
                        + cmsEscape(row.id) + '" title="拖动排序"><i class="bi bi-grip-vertical"></i></a>'
                        + '<input type="number" class="form-control form-control-sm cms-sort-input"'
                        + ' style="width:70px" data-id="' + cmsEscape(row.id) + '" value="'
                        + cmsEscape(value) + '">'
                        + '</div>',
                },
                { field: 'click_count', title: '点击', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 110,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                        { action: 'del', title: '删除（无回收站）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            query() {
                const keyword = ($('#filter-keyword').val() || '').trim();
                const group = $('#filter-group').val();
                const status = $('#filter-status').val();

                const q = {};
                if (keyword) q.keyword = keyword;
                if (group) q.group_name = group;
                if (status !== '' && status !== null && status !== undefined) q.status = status;
                return q;
            },

            toolbarBtn: [
                {
                    id: 'btn-add-friend-link',
                    name: 'add',
                    title: '添加友链',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => {
                        const index = window.Wise.ui.dialog.open(URL.create, '添加友情链接', {
                            area: ['860px', '640px'],
                            resize: true,
                        });
                        window.cmsFriendLinkFormDone = function () {
                            try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                            cmsRefresh(list);
                        };
                    },
                },
                {
                    id: 'btn-batch-del-friend-link',
                    name: 'batchDelete',
                    title: '批量删除',
                    icon: 'bi bi-trash3',
                    class: 'btn btn-sm btn-danger',
                    click: () => batchDelete(),
                },
                {
                    id: 'btn-refresh-friend-link',
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
                        '编辑友情链接',
                        { area: ['860px', '640px'], resize: true }
                    );
                    window.cmsFriendLinkFormDone = function () {
                        try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                        cmsRefresh(list);
                    };
                    return undefined;
                }

                if (action === 'del') {
                    return cmsConfirm(
                        '确定删除友链「' + cmsEscape(row.name || '') + '」？<br>'
                        + '友链<b>没有回收站</b>，删除后无法从后台恢复。',
                        '删除友链'
                    ).then((ok) => (ok
                        ? cmsAction({
                            url: URL.del,
                            data: { id: row.id },
                            refresh: list,
                            successMsg: '友情链接已删除',
                        })
                        : undefined));
                }
                return undefined;
            },
        });

        // 上下架开关：提交 {id, status} 目标值（失败自动回滚勾选）
        cmsBindRowToggles({
            url: URL.toggle,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '已显示' : '已隐藏'),
        });

        // ---- P1-12：行右键菜单（编辑 / 复制 ID / 删除，与操作列一致） ----
        cmsBindRowContextMenu('#table', list, (row) => [
            { id: 'edit', label: '编辑', icon: 'bi bi-pencil', action: () => {
                const index = window.Wise.ui.dialog.open(
                    URL.edit + '?id=' + encodeURIComponent(row.id),
                    '编辑友情链接',
                    { area: ['860px', '640px'], resize: true }
                );
                window.cmsFriendLinkFormDone = function () {
                    try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                    cmsRefresh(list);
                };
            } },
            { id: 'copyId', label: '复制 ID', icon: 'bi bi-clipboard', action: async () => {
                const ok = await cmsCopy(String(row.id));
                cmsToast(ok ? 'ID 已复制' : '复制失败，请手动复制：' + row.id, ok ? 'success' : 'warning');
            } },
            { divider: true },
            { id: 'del', label: '删除（无回收站）', icon: 'bi bi-trash', action: () => cmsConfirm(
                '确定删除友链「' + cmsEscape(row.name || '') + '」？<br>'
                + '友链<b>没有回收站</b>，删除后无法从后台恢复。',
                '删除友链'
            ).then((ok) => (ok
                ? cmsAction({ url: URL.del, data: { id: row.id }, refresh: list, successMsg: '友情链接已删除' })
                : undefined)) },
        ]);

        // ---- P1-11：键盘快捷键（`/` 聚焦搜索、`n` 新建、`r` 刷新） ----
        cmsBindShortcuts({
            search: () => { $('#filter-keyword').trigger('focus'); },
            create: () => {
                const index = window.Wise.ui.dialog.open(URL.create, '添加友情链接', {
                    area: ['860px', '640px'], resize: true,
                });
                window.cmsFriendLinkFormDone = function () {
                    try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                    cmsRefresh(list);
                };
            },
            refresh: () => cmsRefresh(list),
        });

        // 每次重绘后重新绑定拖拽（bootstrap-table 会替换 tbody）
        $('#table').on('post-body.bs.table', () => bindDrag());
        bindDrag();

        // 排序数值输入：失焦提交单条（无拖拽库时的兜底路径）
        $('#table').on('change', '.cms-sort-input', function () {
            const id = parseInt(this.getAttribute('data-id'), 10);
            const sort = parseInt($(this).val(), 10) || 0;
            if (!(id > 0)) return;

            postOrders([{ id: id, sort: sort }]).then(() => cmsRefresh(list));
        });

        // 筛选条
        $('#btn-search-friend-link').on('click', () => cmsRefresh(list));
        $('#filter-status').on('change', () => cmsRefresh(list));
        $('#filter-group').on('change', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                cmsRefresh(list);
            }
        });
        $('#btn-reset-friend-link').on('click', () => {
            $('#filter-keyword').val('');
            $('#filter-group').val('');
            $('#filter-status').val('');
            cmsRefresh(list);
        });

        return list;
    },

    /** 添加友链表单页 */
    create() {
        bindForm();
    },

    /** 编辑友链表单页（与 create 共用模板） */
    edit() {
        bindForm();
    },
};
