/**
 * 回收站页面控制器（UIUX-P0-12）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/recycle`
 * （`RecycleController` → snake_case `recycle`）。
 *
 * 对应控制器动作：
 *   recycle/index   → 列表（标准 paginate 契约；参数 `keyword` / `page` / `limit`(1..100)）
 *   recycle/restore → 恢复单条（POST `id`，站点内校验）—— **可逆 → L1（仅 toast）**
 *   recycle/destroy → 彻底删除单条（POST `id`）—— **不可逆 → L3（逐字确认词）**
 *   recycle/clear   → **清空当前站点回收站**（无 `ids`，整站批量，见 §1.1④）
 *                     —— 不可逆 → L3；注意它返回 `error` 表示**部分失败**（已有数据被删），
 *                     故成功失败都要刷新列表
 *
 * ⚠️ 本页开 `checkboxHeader`：后端有批量恢复 / 批量彻底删除（P1-19）→ 勾选与批量按钮成对。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsDangerConfirm, cmsEscape, cmsBatch,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('recycle/index'),
    restore: cmsUrl('recycle/restore'),
    destroy: cmsUrl('recycle/destroy'),
    clear: cmsUrl('recycle/clear'),
    batchRestore: cmsUrl('recycle/batchRestore'),
    batchDestroy: cmsUrl('recycle/batchDestroy'),
};

/** L3 确认词：彻底删除类操作统一用 DELETE（内容标题可能为空或过长，不适合当确认词） */
const DANGER_WORD = 'DELETE';

const OPERATE = [
    { action: 'restore', title: '恢复到原栏目', icon: 'bi bi-arrow-counterclockwise', class: 'btn-outline-success' },
    { action: 'destroy', title: '彻底删除（不可恢复）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

export default {
    index() {
        let list = null;

        function selectedIds() {
            const ids = window.Wise.table.getSelectedIds('#table');
            return Array.isArray(ids) ? ids : [];
        }

        /**
         * 当前回收站总条数（供「清空」回显影响面）。
         *
         * 读取方式经核实可靠：bootstrap-table 在 `load()` 里对服务端分页会执行
         * `options.totalRows = data[options.totalField]`（bundle @138282，`totalField` 默认 `'total'`），
         * 而我们的 `responseHandler` 正是返回 `{rows, total}`。
         * 取不到时返回 0，调用方退化为「全部内容」措辞 —— **不猜数字**。
         */
        function currentTotal() {
            try {
                const opts = $('#table').bootstrapTable('getOptions');
                const n = Number(opts && opts.totalRows);
                return Number.isFinite(n) && n > 0 ? n : 0;
            } catch (e) {
                return 0;
            }
        }

        /** L1：恢复（可逆，只 toast + 刷新） */
        function restore(row) {
            return cmsAction({
                url: URL.restore,
                data: { id: row.id },
                successMsg: '已恢复「' + (row.title || '') + '」',
                refresh: list,
            });
        }

        /** L3：彻底删除单条 */
        async function destroy(row) {
            const ok = await cmsDangerConfirm({
                title: '彻底删除（不可恢复）',
                word: DANGER_WORD,
                confirmText: '确认彻底删除',
                area: ['560px', '400px'],
                html: '<div class="alert alert-danger py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将永久删除内容「<b>' + cmsEscape(row.title || '') + '</b>」'
                    + '及其版本记录、标签关联等数据，<b>不可恢复</b>。'
                    + '</div>',
            });
            if (!ok) return;

            await cmsAction({
                url: URL.destroy,
                data: { id: row.id },
                successMsg: '内容已彻底删除',
                refresh: list,
            });
        }

        /** L3：清空回收站（整站批量） */
        async function clearAll() {
            const total = currentTotal();
            const scope = total > 0
                ? '当前站点回收站中的 <b>' + total + '</b> 条内容'
                : '当前站点回收站中的<b>全部内容</b>';

            const ok = await cmsDangerConfirm({
                title: '清空回收站（不可恢复）',
                word: DANGER_WORD,
                confirmText: '确认清空',
                area: ['560px', '400px'],
                html: '<div class="alert alert-danger py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将永久删除 ' + scope
                    + '及其版本记录、标签关联等数据，<b>不可恢复</b>。'
                    + '<br><span class="small">注意：作用范围是<b>整个站点</b>，不是当前勾选项。</span>'
                    + '</div>',
            });
            if (!ok) return;

            const res = await cmsAction({
                url: URL.clear,
                data: {},
                successMsg: '回收站已清空',
            });

            // 部分失败时后端返回 error，但**已有数据被删除** → 成败都要刷新，
            // 否则列表会残留已经不存在的行（点了还会报「内容不存在」）。
            cmsRefresh(list);

            if (res && !res.success) {
                cmsToast('部分内容清理失败，详情见系统日志', 'warning');
            }
        }

        /** 批量恢复（L1，可逆：只 toast + 刷新） */
        function batchRestore() {
            const ids = selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要恢复的内容', 'warning');
                return;
            }
            return cmsBatch({
                url: URL.batchRestore,
                ids: ids,
                confirmMessage: '将恢复选中的 ' + ids.length + ' 条内容到原栏目，确定继续？',
                successMsg: '已恢复选中的内容',
                refresh: list,
            });
        }

        /** 批量彻底删除（L3，不可逆：逐字确认词） */
        async function batchDestroy() {
            const ids = selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要彻底删除的内容', 'warning');
                return;
            }
            const ok = await cmsDangerConfirm({
                title: '批量彻底删除（不可恢复）',
                word: DANGER_WORD,
                confirmText: '确认彻底删除',
                area: ['560px', '400px'],
                html: '<div class="alert alert-danger py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将永久删除选中的 <b>' + ids.length + '</b> 条内容'
                    + '及其版本记录、标签关联等数据，<b>不可恢复</b>。'
                    + '</div>',
            });
            if (!ok) return;

            await cmsBatch({
                url: URL.batchDestroy,
                ids: ids,
                successMsg: '已彻底删除选中的内容',
                refresh: list,
            });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            pageSize: 15,
            // 批量：后端有 batchRestore / batchDestroy（P1-19）→ 开勾选
            checkboxHeader: true,
            columns: [
                { checkbox: true },
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'title',
                    title: '标题',
                    align: 'left',
                    // value 已由内核转义；放进 title 属性同样安全（转义会处理引号）
                    formatter: (value) => '<span class="d-inline-block text-truncate"'
                        + ' style="max-width:460px" title="' + value + '">' + (value || '') + '</span>',
                },
                { field: 'category.name', title: '原栏目', width: 150, align: 'left', formatter: 'normal' },
                { field: 'delete_time', title: '删除时间', width: 165, align: 'center', formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 130,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            query() {
                const keyword = ($('#filter-keyword').val() || '').trim();
                return keyword ? { keyword: keyword } : {};
            },

            toolbarBtn: [
                {
                    id: 'btn-batch-restore-recycle',
                    name: 'batchRestore',
                    title: '批量恢复',
                    icon: 'bi bi-arrow-counterclockwise',
                    class: 'btn btn-sm btn-outline-success',
                    click: () => batchRestore(),
                },
                {
                    id: 'btn-batch-destroy-recycle',
                    name: 'batchDestroy',
                    title: '批量彻底删除',
                    icon: 'bi bi-trash',
                    class: 'btn btn-sm btn-outline-danger',
                    click: () => batchDestroy(),
                },
                {
                    id: 'btn-clear-recycle',
                    name: 'clear',
                    title: '清空回收站',
                    icon: 'bi bi-trash3',
                    class: 'btn btn-sm btn-danger',
                    click: () => clearAll(),
                },
                {
                    id: 'btn-refresh-recycle',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'restore') return restore(row);
                if (action === 'destroy') return destroy(row);
                return undefined;
            },
        });

        // 搜索条
        $('#btn-search-recycle').on('click', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                cmsRefresh(list);
            }
        });
        $('#btn-reset-recycle').on('click', () => {
            $('#filter-keyword').val('');
            cmsRefresh(list);
        });

        return list;
    },
};
