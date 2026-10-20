/**
 * WiseDocs 列表页表格工厂（兼容别名）
 *
 * 实现只有一套：内核的 `Wise.table`（底层 EnhancedTable）。
 * 本文件为存量调用点（createTable / operateButtons / refresh）保留别名，
 * 并统一注入本插件的列表默认表现（空态文案）。
 *
 * 时序：内核 `_loadController()` 先 await 业务模块加载完成，再动态 import
 * 页面控制器，因此本文件执行时 `window.Wise.table` 必然已就绪。
 *
 * @deprecated 请直接使用 `window.Wise.table`（新页面建议直接用）
 */

import { $ } from './util.js';

/**
 * 列表空态（三态之一）
 *
 * 与「无数据」区分：这里统一表达「当前筛选条件下没有结果」，
 * 并给出下一步动作提示，避免用户以为是页面出错。
 */
const EMPTY_HTML =
    '<div class="docs-empty">' +
    '<i class="bi bi-inbox"></i>' +
    '<p class="docs-empty__title">没有匹配的数据</p>' +
    '<p class="docs-empty__hint">试试调整或清空筛选条件后重新查询</p>' +
    '</div>';

/** @returns {Object|null} 内核 Wise.table */
function kernel() {
    const t = window.Wise && window.Wise.table;
    if (!t) {
        console.error('[wisedocs] Wise.table 未就绪，无法初始化列表');
        return null;
    }
    return t;
}

/* --------------------------------------------------------------------------
 * 操作列点击派发（插件侧补位）
 *
 * bootstrap-table 渲染出来的表体是**无 id 的克隆表**（原 #table 被隐藏），
 * 内核按「按钮所在 table 的 id」查派发表，克隆表没有 id 就直接 return，
 * 表现为行内「编辑 / 删除 / 发布…」点了没反应。
 *
 * 这里按「同一 .bootstrap-table 容器内带 id 的原表」定位到页面登记的 onClick；
 * 若内核已经派发过（defaultPrevented），说明后续内核修复生效，直接跳过，不会重复执行。
 * -------------------------------------------------------------------------- */
const rowActionHandlers = new Map();
let rowActionBound = false;

function bindRowActions(tableId, onClick) {
    rowActionHandlers.set(tableId, onClick);
    if (rowActionBound) return;
    rowActionBound = true;

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented) return;

        const btn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
        if (!btn || !btn.hasAttribute('data-id')) return;

        const btnTable = btn.closest('table');
        if (!btnTable) return;

        const container = btnTable.closest('.bootstrap-table');
        const origin = (container && container.querySelector('table[id]')) || btnTable;
        if (!origin.id) return;

        const handler = rowActionHandlers.get(origin.id);
        if (!handler) return;

        e.preventDefault();

        const action = btn.getAttribute('data-action') || '';
        const id = btn.getAttribute('data-id');

        let row = null;
        try {
            const $origin = $('#' + origin.id);
            const rows = $origin.length && typeof $origin.bootstrapTable === 'function'
                ? $origin.bootstrapTable('getData')
                : [];
            row = (rows || []).find(function (r) { return String(r.id) === String(id); }) || null;
        } catch (err) {
            // 取数失败不阻断，退化为仅有 id 的最小行对象
        }

        handler(action, row || { id: id }, btn);
    }, false);
}

/** 创建列表表格（同步），统一注入空态 */
export function createTable(options = {}) {
    const t = kernel();
    if (!t) return null;

    // bootstrap-table 会把 formatNoMatches 当函数调用（this.options.formatNoMatches()），
    // 传字符串会抛 "TypeError: ... is not a function"；必须返回一个返回 HTML 的函数。
    const merged = { formatNoMatches: () => EMPTY_HTML };
    Object.keys(options).forEach(function (key) {
        merged[key] = options[key];
    });

    const instance = t.createSync(merged);
    if (instance && typeof merged.onClick === 'function') {
        bindRowActions(merged.idTable || 'table', merged.onClick);
    }

    return instance;
}

/** 与 createTable 同义，对齐内核命名 */
export const createList = createTable;

/** 生成操作列 HTML */
export function operateButtons(buttons, row) {
    const t = kernel();
    return t ? t.operateButtons(buttons, row) : '';
}

/** 刷新表格（接受 EnhancedTable 实例） */
export function refresh(target) {
    const t = kernel();
    if (t) t.refresh(target);
}

/** 取当前勾选的主键（批量操作入口） */
export function selectedIds(selector = '#table') {
    const t = kernel();
    return t ? (t.getSelectedIds(selector) || []) : [];
}

/* --------------------------------------------------------------------------
 * 列表筛选状态保持（sessionStorage）
 *
 * 把筛选表单里各控件的值存入 sessionStorage，下次进入同一页面时自动回填，
 * 避免「编辑文档返回后筛选条件丢失」。按表单内控件的 id 作为键，通用无侵入。
 * -------------------------------------------------------------------------- */
const LS_PREFIX = 'wisedocs.listState.';

/**
 * 绑定列表筛选状态：进入页面时回填，输入变化时保存。
 *
 * @param {string} scope   唯一作用域（建议用控制器名，如 'document'）
 * @param {Object} $form   筛选表单的 jQuery 对象（含带 id 的 input/select/textarea）
 */
export function bindListState(scope, $form) {
    if (!scope || !$form || !$form.length) return;

    const key = LS_PREFIX + scope;

    // 恢复：按控件 id 回填（仅表单控件，避免误伤表单内的其它带 id 元素）
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(key) || 'null');
    } catch (e) {
        saved = null;
    }
    if (saved && typeof saved === 'object') {
        Object.keys(saved).forEach(function (id) {
            const $el = $form.find('#' + id);
            if ($el.length && !$el.prop('disabled')) {
                $el.val(saved[id]);
            }
        });
    }

    // 保存：任一输入变化即保存当前所有表单控件的值
    $form.on('change input', function () {
        const data = {};
        $form.find('input, select, textarea').each(function () {
            if (this.id) data[this.id] = $(this).val();
        });
        try {
            sessionStorage.setItem(key, JSON.stringify(data));
        } catch (e) {
            // 写入失败（隐私模式 / 配额）静默忽略，不影响主流程
        }
    });
}
