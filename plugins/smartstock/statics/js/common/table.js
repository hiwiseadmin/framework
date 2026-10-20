/**
 * SmartStock 列表页表格工厂（兼容别名）
 *
 * 实现只有一套：内核的 `Wise.table`（底层 EnhancedTable）。
 * 本文件仅为存量调用点（createTable / operateButtons / refresh）保留别名，
 * **不含任何实现** —— 新代码请直接用 `window.Wise.table`。
 *
 * 时序：内核 `_loadController()` 先 await 业务模块加载完成，再动态 import
 * 页面控制器，因此本文件执行时 `window.Wise.table` 必然已就绪。
 *
 * @deprecated 请直接使用 `window.Wise.table`
 */

/** @returns {Object|null} 内核 Wise.table */
function kernel() {
    const t = window.Wise && window.Wise.table;
    if (!t) {
        console.error('[smartstock] Wise.table 未就绪，无法初始化列表');
        return null;
    }
    return t;
}

/** 创建列表表格（同步） */
export function createTable(options = {}) {
    const t = kernel();
    return t ? t.createSync(options) : null;
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
