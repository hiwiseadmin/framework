/**
 * Wise.table — 增强表格（全框架唯一列表 API）
 *
 * 底层实现是全局 `EnhancedTable`（/statics/modules/enhanced-table.js），
 * 本模块在其之上补齐三件内核缺失的事：
 *   1. 列表页统一默认值（分页参数 / 响应字段 / 中文三态 / 能力开关）
 *   2. 修正 create / createSync 的实参错位
 *      —— 原实现是 `new EnhancedTable(selector, options)`，而构造函数签名是
 *      `constructor(options)` 且只读 `options.idTable`，selector 被完全忽略，
 *      导致这个门面一直不可用（这才是各页面宁可直连 EnhancedTable 的原因）。
 *   3. 列表页常用工具 operateButtons / refresh（含 EnhancedTable 实例支持）
 *
 * 使用约定（重要）：
 *   - 页面脚本取 `window.Wise.table`。内核保证：`_loadController()` 会先
 *     await 业务模块加载完成，再动态 import 页面控制器，因此控制器执行时
 *     本模块一定已就绪。
 *   - **不要** `import '/statics/modules/wise-table.js'` —— 那会得到一份
 *     独立实例，与内核状态不共享。
 *
 * 表格事件桥接到 Wise.core.eventBus（ws:table:*）。
 *
 * @module wise-table
 * @version 2.0.0
 */

import { eventBus, registry, logger } from './wise-core.js';
import { toast } from './wise-ui.js';

// ============================================================
// 列表页统一默认值
// ============================================================
//
// 这些默认值贴合**插件页后端约定**：插件 BaseController 统一返回
// `{ code, msg, data: { rows, total } }`，并读取 pageSize / pageNumber。
//
// admin 页面目前多为直接 `new EnhancedTable()`，不受影响；若将来 admin 也
// 迁到本 API，用 options 覆盖 dataDataField / queryParams 即可（那是显式的
// 一处改动，好过每个页面各写一遍）。

const LIST_DEFAULTS = {
    // 与 EnhancedTable v2 的默认容器一致（id 唯一；内核另有 .toolbar 回退）
    toolbar: '#toolbar',
    // 显式声明走 UIS v1 外观（与 EnhancedTable.DEFAULTS.uiRevision 同值，此处写明便于排查）
    uiRevision: 'v2',
    pageSize: 20,
    sortName: 'id',
    sortOrder: 'desc',

    // 以下三项**显式关闭**，会覆盖内核 v2 的「有搜索区才关」条件判断：
    // 插件端点不支持 search / commonSearch 参数，导出由页面自行决定。
    // 若某页确实需要，显式传 true 即可。
    search: false,
    commonSearch: false,
    showExport: false,

    // 响应结构适配
    dataDataField: 'data.rows',
    dataTotalField: 'data.total',
    dataPageNumber: '',

    // 列较多时的列选择器：默认开启
    showColumns: true,

    // 能力开关：批量选择 / 行内编辑需页面显式开启。
    // 未接批量接口就默认打开 checkboxHeader，只会产生"勾了没反应"的死交互。
    checkboxHeader: false,
    checkOnInit: false,
    dblClickToEdit: false,
    escape: true,

    // 中文三态：避免 bootstrap-table 英文兜底文案混入中文后台
    // 空态不在此处声明：默认实现由 EnhancedTable.renderEmpty 提供（图标 + 标题 + 提示），
    // 这里再写一份纯文本会把它盖掉。需要换文案的页面传 emptyTitle / emptyHint 即可。
    formatLoadingMessage: () => '正在加载，请稍候…',
    formatSearch: () => '搜索',
};

const _ESCAPE_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** HTML 转义（操作列按钮文案来自配置，但仍统一转义，避免将来从数据取值时漏网） */
function _escape(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, (c) => _ESCAPE_MAP[c]);
}

// ============================================================
// 等待 EnhancedTable 就绪
// ============================================================

function _getEnhancedTable() {
    const ET = window.EnhancedTable;
    // wise-adapter 在 EnhancedTable 缺失时会把它桥接成 { init, FORMATTERS } 普通对象，
    // 那不是可 new 的构造器，必须排除，否则 `new ET(...)` 直接抛错。
    return typeof ET === 'function' ? ET : null;
}

/** 轮询等待（enhanced-table.js 是 defer 脚本，正常已在控制器之前执行完；此处仅作防御） */
function _waitEnhancedTable(timeoutMs = 10000) {
    const ET = _getEnhancedTable();
    if (ET) return Promise.resolve(ET);

    return new Promise((resolve) => {
        const maxAttempts = Math.max(1, Math.round(timeoutMs / 100));
        let attempts = 0;

        const poll = setInterval(() => {
            attempts++;
            const found = _getEnhancedTable();
            if (found || attempts >= maxAttempts) {
                clearInterval(poll);
                resolve(found);
            }
        }, 100);
    });
}

// ============================================================
// 参数规范化
// ============================================================

/** 从 selector 取表格 DOM id */
function _idOf(selector) {
    if (typeof selector === 'string') return selector.replace(/^#/, '');
    if (selector && typeof selector === 'object' && selector.nodeType === 1) return selector.id || '';
    return '';
}

/** EnhancedTable 真正认识的嵌套 extend 键（见 enhanced-table.js 的 DEFAULTS.extend） */
const EXTEND_KEYS = [
    'preview_url', 'add_url', 'edit_url', 'del_url', 'restore_url',
    'import_url', 'multi_url', 'dragsort_url',
    'preview_title', 'add_title', 'edit_title', 'del_title',
];

/**
 * 拆分 extend：按键名区分「嵌套配置」与「历史顶层覆盖」
 *
 * 历史包袱：插件侧的 `opts.extend` 一直被当作 EnhancedTable **顶层**配置覆盖使用
 * （如 match3game/reward.js 的 `extend: { toolbar: '#log-toolbar' }`，因为早期
 * 工厂实现是 `Object.assign(base, opts.extend)`）。而 EnhancedTable 的 `extend`
 * 是嵌套键。这里按已知键名分流，两种写法都能正确落地。
 */
function _splitExtend(extend) {
    const nested = {};
    const top = {};
    Object.keys(extend || {}).forEach((key) => {
        if (EXTEND_KEYS.includes(key)) nested[key] = extend[key];
        else top[key] = extend[key];
    });
    return { nested, top };
}

/**
 * 规范化调用参数，兼容两种历史写法：
 *   createSync(options)           插件侧常用形态
 *   createSync('#table', options) EnhancedTable.init 时代形态
 *
 * @returns {Object} 可直接交给 `new EnhancedTable()` 的配置
 */
function _normalizeOptions(selector, options) {
    let raw;
    if (selector && typeof selector === 'object' && selector.nodeType !== 1) {
        raw = Object.assign({}, selector);
    } else {
        raw = Object.assign({}, options || {});
        const id = _idOf(selector);
        if (id) raw.idTable = id;
    }

    const { nested, top } = _splitExtend(raw.extend);
    delete raw.extend;

    const opts = Object.assign({}, LIST_DEFAULTS, raw, top);

    // EnhancedTable 默认 extend.dragsort_url 指向 admin 端点 'idler/sort'，
    // 插件页不存在该路由，继承它只会让拖拽排序静默失效。
    // 统一置空，需要时由页面显式传入（走 EXTEND_KEYS，会留在 nested 里）。
    opts.extend = Object.assign({ dragsort_url: '' }, nested);

    // 分页参数适配：bootstrap-table 发 limit/offset，插件后端读 pageSize/pageNumber
    const userQueryParams = opts.queryParams;
    const userQuery = opts.query;
    if (typeof userQueryParams !== 'function') {
        const pageSize = opts.pageSize;
        opts.queryParams = function (params) {
            const limit = params.limit > 0 ? params.limit : pageSize;
            return Object.assign(
                { pageSize: limit, pageNumber: Math.floor((params.offset || 0) / limit) + 1 },
                typeof userQuery === 'function' ? userQuery() : {}
            );
        };
    }

    // 原始响应透出（含 summary 等附加字段）
    const userOnLoadSuccess = opts.onLoadSuccess;
    const onResponse = opts.onResponse;
    opts.onLoadSuccess = function (res) {
        if (typeof userOnLoadSuccess === 'function') userOnLoadSuccess.call(this, res);
        if (typeof onResponse === 'function') onResponse(res);
    };

    // 列表加载失败不再静默
    if (typeof opts.onLoadError !== 'function') {
        opts.onLoadError = function () {
            toast.error('列表加载失败，请稍后重试');
        };
    }

    if (!opts.idTable) opts.idTable = 'table';

    // 服务端导出（P0-1）：显式 opt-in
    //   只有传了 exportUrl 才注入按钮，避免在未声明 export() 的控制器上产生死按钮。
    //   同时剔除历史遗留的字符串 'export'（v2 预设表没有它，会被内核丢弃并告警）。
    opts.toolbarBtn = _normalizeToolbarBtns(opts);

    return opts;
}

/**
 * 规范化工具栏按钮：剔除无预设的 'export' 字符串，按需注入服务端导出按钮
 *
 * @param {Object} opts 规范化后的表格配置
 * @returns {Array} toolbarBtn
 */
function _normalizeToolbarBtns(opts) {
    const list = (opts.toolbarBtn || []).filter((btn) => {
        return !(btn === 'export');
    });

    const hasExport = list.some((btn) => btn && typeof btn === 'object' && btn.name === 'export');
    if (!opts.exportUrl || hasExport) return list;

    // 声明了权限码就按权限注入；权限快照由服务端注入（window.__WS_AUTH__），后端不再逐控制器判断
    if (opts.exportPermission) {
        const api = window.Wise && window.Wise.permission;
        if (!(api && typeof api.can === 'function' && api.can(opts.exportPermission))) {
            return list;
        }
    }

    list.push({
        name: 'export',
        title: '导出',
        icon: 'bi bi-download',
        variant: 'outline-secondary',
        group: 'tools',
        order: 35,
        click() {
            _openExportDialog(opts);
        },
    });

    return list;
}

// ============================================================
// 服务端导出（P0-1）
// ============================================================

/** 取当前表格实例（点击时实时取，避免闭包指向旧实例） */
function _exportInstance(opts) {
    const key = opts.idTable || 'table';
    return table._instances.get(key) || opts.__instance || null;
}

/**
 * 打开范围选择弹层（内核唯一实现收敛在 EnhancedTable）
 *
 * wise-table 只负责「按 exportUrl 注入按钮」与转调，避免两套 blob 下载/CSRF 逻辑漂移。
 */
function _openExportDialog(opts) {
    const instance = _exportInstance(opts);
    if (instance && typeof instance.openServerExportDialog === 'function') {
        instance.openServerExportDialog();
        return;
    }

    toast.error('当前表格不支持服务端导出');
}


// ============================================================
// 事件桥接 / 实例登记
// ============================================================

function _bridgeEvents(instance) {
    if (!instance) return;
    // EnhancedTable 有自己的 eventBus，桥接到统一事件总线
    instance.on = function (event, handler) {
        eventBus.on(`ws:table:${event}`, handler);
    };
    instance.emit = function (event, data) {
        eventBus.emit(`ws:table:${event}`, data);
    };
}

function _doCreate(EnhancedTable, options) {
    try {
        const instance = new EnhancedTable(options);
        _bridgeEvents(instance);
        _bindRowActions(instance, options);

        const key = options.idTable || `table-${Date.now()}`;
        table._instances.set(key, instance);

        logger.info('table', `表格已创建: ${key}`);
        eventBus.emit('ws:table:created', { selector: key, instance });
        return instance;
    } catch (err) {
        logger.error('table', '表格创建失败', err);
        return null;
    }
}

/**
 * 绑定操作列 `[data-action]` 的点击派发
 *
 * 背景（历史缺陷）：`operateButtons()` 生成的按钮只带 `data-action` / `data-id`
 * （无文字，仅图标 + title 提示），而 EnhancedTable 自身的行内事件代理只认
 * `.btn-previewone / .btn-addone / .btn-editone / .btn-delone` 这些类名；
 * 内核此前**没有任何** `data-action` 的分发逻辑 —— 于是各页面在表格配置里声明的
 * `onClick(action, row, $el)` 永远不会被调用，表现为「编辑 / 删除」按钮点了没反应。
 *
 * 这里在文档级做一次事件代理（全页仅一个监听器），命中已登记的表格内的
 * 操作列按钮时才派发给该表格的 `options.onClick`。
 *
 * 三道收敛条件（`data-action` 并非本组件专用，必须收窄，否则会误伤其它组件）：
 *   ① 按钮必须落在**已登记的表格**内（精确 DOM id，见 `_rowActionHandlers`）——
 *      早期写法用 `(tableEl.id || 'table')` 兜底，会把「无 id 的第三方表格」也当成
 *      目标表，例如 `bootstrap-datetimepicker` 的日期表格
 *      （`<table class="table-condensed">` 无 id，日期格子是 `<a data-action="selectDay">`）；
 *   ② 按钮必须带 `data-id` —— `operateButtons()` 恒定输出 `data-action + data-id`，
 *      借此排除 wise-upload 的裁剪旋转按钮、datetimepicker 的日期格子等
 *      「有 data-action 但没有行 id」的按钮；
 *   ③ 派发时按 `tableId` **实时查表**取实例与回调（而非闭包捕获首次创建时的那一份）。
 *
 * @param {Object} instance EnhancedTable 实例
 * @param {Object} options  规范化后的表格配置
 */

/**
 * 操作列派发登记表：tableId → { instance, onClick }
 *
 * 用「登记 + 实时查表」替代早期的「document 上打布尔标志、只绑一次」：
 * 同一文档内表格被销毁重建（或同 id 重新初始化）时，早期实现里的监听器闭包
 * 仍指向**旧实例**，会出现「取行数据落空、回调指向旧闭包」的悬空引用；
 * 现在每次创建都会覆盖登记项，派发时按 DOM 上的 tableId 取最新引用。
 */
const _rowActionHandlers = new Map();
let _rowActionsDelegated = false;

function _bindRowActions(instance, options) {
    const onClick = options && options.onClick;
    if (typeof onClick !== 'function' || !instance) return;

    const tableId = options.idTable || 'table';
    // 覆盖登记：表格重建后引用随之刷新，不留旧实例闭包
    _rowActionHandlers.set(tableId, { instance, onClick });

    // 全页只需一个文档级监听器
    if (_rowActionsDelegated) return;
    _rowActionsDelegated = true;

    document.addEventListener('click', (e) => {
        const btn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
        if (!btn) return;

        // 收敛 ①：必须落在已登记的表格内（精确 DOM id 匹配）
        const tableEl = btn.closest('table');
        if (!tableEl || !tableEl.id) return;
        const entry = _rowActionHandlers.get(tableEl.id);
        if (!entry) return;

        // 收敛 ②：只认操作列按钮（operateButtons 必带 data-id）
        if (!btn.hasAttribute('data-id')) return;

        e.preventDefault();

        const action = btn.getAttribute('data-action') || '';
        const id = btn.getAttribute('data-id');

        // 收敛 ③：用实时登记的实例取行数据（表格重建后即为新实例）
        let row = null;
        try {
            const rows = typeof entry.instance.table.bootstrapTable === 'function'
                ? entry.instance.table.bootstrapTable('getData')
                : [];
            row = (rows || []).find((r) => String(r.id) === String(id)) || null;
        } catch (err) {
            // 取数失败不阻断，退化为仅有 id 的最小行对象
        }

        entry.onClick(action, row || { id }, btn);
    }, false);
}

// ============================================================
// Wise.table API
// ============================================================

const table = {
    /** @type {Map<string, Object>} 实例表 */
    _instances: new Map(),

    /**
     * 创建表格（异步等待 EnhancedTable 就绪）
     *
     * @param {Object|string|HTMLElement} optionsOrSelector - options 对象，或表格选择器
     * @param {Object} [options] - selector 形态下的 options
     * @returns {Promise<Object|null>} EnhancedTable 实例
     */
    async create(optionsOrSelector, options) {
        const ET = await _waitEnhancedTable();
        if (!ET) {
            logger.error('table', 'EnhancedTable 加载超时');
            return null;
        }
        return _doCreate(ET, _normalizeOptions(optionsOrSelector, options));
    },

    /**
     * 同步创建表格
     *
     * base.html 以 defer 加载 enhanced-table.js，且早于 ESM 入口，
     * 因此控制器执行时它必然已就绪。异步场景请用 create()。
     *
     * @param {Object|string|HTMLElement} optionsOrSelector - options 对象，或表格选择器
     * @param {Object} [options] - selector 形态下的 options
     * @returns {Object|null} EnhancedTable 实例
     */
    createSync(optionsOrSelector, options) {
        const ET = _getEnhancedTable();
        if (!ET) {
            logger.error('table', 'EnhancedTable 未就绪，请改用 await table.create()');
            return null;
        }
        return _doCreate(ET, _normalizeOptions(optionsOrSelector, options));
    },

    /**
     * 刷新表格
     *
     * @param {Object|string|HTMLElement} [target] - EnhancedTable 实例 / 选择器；
     *                                               不传则刷新页面上所有 bootstrap-table
     */
    refresh(target) {
        // EnhancedTable 实例（插件侧最常用：refresh(table)）
        if (target && target.table && typeof target.table.bootstrapTable === 'function') {
            target.table.bootstrapTable('refresh', { silent: true });
            eventBus.emit('ws:table:refresh', { instance: target });
            return;
        }

        if (target) {
            const $table = $(target);
            if ($table.length) $table.bootstrapTable('refresh');
        } else {
            $('table[data-toggle="table"]').bootstrapTable('refresh');
        }
        eventBus.emit('ws:table:refresh', { selector: target });
    },

    /**
     * 生成操作列 HTML
     *
     * 按钮统一用 data-action / data-id 标记，由 {@see _bindRowActions()} 在
     * 创建表格时注册的文档级代理统一分发到页面声明的 `options.onClick`，
     * 避免在模板里写 onclick 内联处理器（CSP 不允许）。
     *
     * ⚠️ 按钮文本默认只渲染图标（文字在 title 提示里），如需可见文字请在
     *    buttons 配置里传 `label`。
     *
     * @param {Array<{action:string,title:string,icon?:string,class?:string,label?:string}>} buttons
     * @param {Object} row 当前行数据
     * @returns {string}
     */
    operateButtons(buttons, row) {
        const html = (buttons || [])
            .map((btn) => {
                const cls = btn.class || 'btn-outline-secondary';
                const icon = btn.icon ? `<i class="${_escape(btn.icon)}"></i> ` : '';
                const label = btn.label ? _escape(btn.label) : '';
                // 唯一尺寸 btn-sm（决策 D4：不再输出无效的 xs 尺寸类）；间距交给 btn-group，不再用 me-1 破坏拼接
                return (
                    '<button type="button" class="btn btn-sm ' + _escape(cls) + '" ' +
                    'data-action="' + _escape(btn.action) + '" data-id="' + _escape((row && row.id) || 0) + '" ' +
                    'title="' + _escape(btn.title || '') + '">' + icon + label + '</button>'
                );
            })
            .join('');

        return '<div class="btn-group btn-group-sm">' + html + '</div>';
    },

    /**
     * 获取选中的主键列表
     * @param {string} [selector]
     * @returns {string[]}
     */
    getSelectedIds(selector = 'table') {
        const $table = $(selector);
        if (!$table.length) return [];
        return $table.bootstrapTable('getSelections')
            .map(row => row.id || row[$table.bootstrapTable('getOptions').pk || 'id']) || [];
    },

    /**
     * 获取选中的数据行
     * @param {string} [selector]
     * @returns {Object[]}
     */
    getSelectedData(selector = 'table') {
        const $table = $(selector);
        if (!$table.length) return [];
        return $table.bootstrapTable('getSelections') || [];
    },

    /**
     * 检查是否选中了行
     * @param {string} [selector]
     * @returns {boolean}
     */
    hasSelected(selector = 'table') {
        return this.getSelectedIds(selector).length > 0;
    },

    /**
     * 切换列可见性
     * @param {string} column - 列字段名
     * @param {string} [selector]
     */
    toggleColumn(column, selector = 'table') {
        const $table = $(selector);
        if ($table.length) $table.bootstrapTable('toggleColumn', column);
    },

    /**
     * 获取格式化器
     * @returns {Object}
     */
    get formatters() {
        const ET = _getEnhancedTable();
        return ET ? ET.FORMATTERS : {};
    },
};

// 注册模块
registry.register('table', table);

// 兼容：EnhancedTable 迟到时给出就绪事件（正常路径下此处已就绪，事件立即发出）
eventBus.on('ws:core:ready', () => {
    let attempts = 0;
    const checkInterval = setInterval(() => {
        attempts++;
        if (_getEnhancedTable()) {
            clearInterval(checkInterval);
            eventBus.emit('ws:table:ready');
            logger.info('table', 'EnhancedTable 已就绪，Wise.table 可用');
        } else if (attempts > 50) {
            clearInterval(checkInterval);
            logger.warn('table', 'EnhancedTable 未就绪，Wise.table 不可用');
        }
    }, 200);
});

export default table;
export { table, LIST_DEFAULTS };
