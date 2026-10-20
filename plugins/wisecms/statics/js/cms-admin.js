/**
 * wisecms 后台公共前端助手（UIUX-P0-01 / P0-02 / P0-03）
 *
 * 定位：本插件的**唯一**前端公共层。页面控制器（`statics/js/{controller}.js`，由
 * `app/plugin/base/BaseController.php:51` 自动注入 `../plugins/wisecms/js/{snake}`）
 * 从这里取表格/弹层/删除/批量/轮询能力，不在各页各写一遍。
 *
 * 三条硬约束（均由阶段 0 实测确定，见 docs/wisecms-后台UIUX-任务清单.md §1.1）：
 *
 *  1) **分页参数**：wisecms 后端只读 `page` + `limit`（`Content.php:73-74` 等 7 处），
 *     而内核 `Wise.table` 发的是 `pageSize` + `pageNumber`（`wise-table.js:181`）。
 *     不映射 → 列表永远停在第 1 页。故此处统一注入 `queryParams`。
 *
 *  2) **响应契约**：wisecms 有 4 种形状，必须按形状适配，不能一刀切：
 *     · standard  —— 12 个列表：`data = {total, per_page, current_page, last_page, data:[]}`
 *     · model     —— `Model::index`：`data = {code, data:[], count, msg}`
 *     · modelField—— `ModelField::index`：`data = {code, data:[], msg}`（无 count、全量）
 *     · flat      —— `UrlRule::index` 等：`data = [...]`（纯数组、无分页键）
 *
 *  3) **请求体**：内核 `Wise.util.http` 发 `application/json`（`wise-util.js:281`）；
 *     ThinkPHP `Request::post` 对 JSON body 会 `json_decode` 后落入 `$this->post`
 *     （`vendor/topthink/framework/src/think/Request.php:271`），故 `$request->post('id')`
 *     可直接取到，无需改成表单编码。批量接口的 `ids` 一律传**数组**。
 *
 * 统一入口：只用 `window.Wise.*`，禁止 `import '/statics/modules/*'`（会拿到 bundle
 * 之外的第二份实例，内核配置不生效）。
 *
 * @module wisecms/cms-admin
 */

/* ============================================================================
 * 一、标识与 URL
 * ========================================================================== */

/** 插件标识，从本模块自身 URL 反推：/statics/plugins/{ident}/js/cms-admin.js */
const PLUGIN_IDENT = (String(import.meta.url).match(/\/statics\/plugins\/([^/]+)\/js\//) || [])[1] || 'wisecms';

/** 插件后台路由前缀，如 /plugin/wisecms */
export const BASE = '/plugin/' + PLUGIN_IDENT;

/** 插件静态资源根，如 /statics/plugins/wisecms */
export const ASSET = '/statics/plugins/' + PLUGIN_IDENT;

/**
 * 拼插件后台 URL。
 *
 * @param {string} path   `'tag/index'` 或 `'content/edit'`
 * @param {Object} [params] 查询参数；空串 / null / undefined 会被丢弃
 * @returns {string}
 */
export function cmsUrl(path, params) {
    let url = BASE + '/' + String(path == null ? '' : path).replace(/^\/+/, '');
    if (params && typeof params === 'object') {
        const qs = new URLSearchParams();
        Object.keys(params).forEach((key) => {
            const value = params[key];
            if (value === '' || value === null || value === undefined) return;
            if (Array.isArray(value)) {
                value.forEach((v) => qs.append(key + '[]', v));
            } else {
                qs.append(key, value);
            }
        });
        const s = qs.toString();
        if (s) url += (url.indexOf('?') === -1 ? '?' : '&') + s;
    }
    return url;
}

/* ============================================================================
 * 二、基建小工具
 * ========================================================================== */

/** 取内核 Wise；未就绪时返回 null（不抛错，避免整页白屏） */
function W() {
    return window.Wise || null;
}

/** HTML 转义（用于把后端数据拼进 innerHTML） */
export function cmsEscape(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

/**
 * 实体解码一次（`&amp;lt;` → `&lt;`）。
 *
 * 为什么需要：本插件有字段在**入库时**就由 PHP `htmlspecialchars()` 转义过
 * （典型：评论正文，`CommentService::create()` 第 66 行），而内核输出前又会转义一次
 * （`EnhancedTable.DEFAULTS.escape = true`）→ 页面上直接渲染会显示成
 * `&amp;lt;script&amp;gt;` 这种**双重转义乱码**（老模板用 `{$item.content}` 不转义，
 * 所以此前是正常的；换到 table 渲染后必须自己解一层）。
 *
 * ⚠️ 返回值**必须**再经 `cmsEscape()` 才能插入 HTML —— 否则遇到「历史未转义」的脏数据
 * （库里存着裸 `<script>`）就会重新引入 XSS。
 *
 * @param {*} value
 * @returns {string}
 */
export function cmsDecodeEntities(value) {
    const div = document.createElement('div');
    div.innerHTML = String(value === null || value === undefined ? '' : value);
    return div.textContent || '';
}

/**
 * 生成操作列 HTML（在 `Wise.table.operateButtons` 之上补一层「按行显隐」）。
 *
 * 内核的 `operateButtons(buttons, row)` 是**无差别渲染**（`wise-table.js:402-417`），
 * 既没有 `visible` 也没有 `disable` —— 所以「已通过的评论不再显示『通过』」这类需求
 * 必须在调用方先过滤。此处提供 `show(row)` 谓词，避免每个页面各写一遍 filter。
 *
 * @param {Array<{action:string,title:string,icon?:string,class?:string,show?:Function}>} buttons
 * @param {Object} row 当前行（注意：**未转义原始数据**）
 * @returns {string}
 */
export function cmsOperateButtons(buttons, row) {
    const visible = (buttons || []).filter((btn) => (
        typeof btn.show === 'function' ? !!btn.show(row) : btn.show !== false
    ));
    return W().table.operateButtons(visible, row);
}

/**
 * 取**裸文本 / HTML** 响应。
 *
 * 为什么不能走 `Wise.util.http`：它按 JSON 信封解析（拦截器读 `code`/`data` 键），
 * 而本插件有些接口返回的是**裸 HTML**（如 `modelField/getAddForm` 返回字段类型配置片段），
 * 走 http 会解析失败/被当异常。故此处直接用 `fetch().text()`。
 *
 * @param {string} url
 * @param {Object} [params] 查询参数（空值丢弃）
 * @returns {Promise<string>}
 */
export async function cmsFetchText(url, params) {
    let full = url;
    if (params && typeof params === 'object') {
        const qs = new URLSearchParams();
        Object.keys(params).forEach((key) => {
            const value = params[key];
            if (value === '' || value === null || value === undefined) return;
            qs.append(key, value);
        });
        const s = qs.toString();
        if (s) full += (full.indexOf('?') === -1 ? '?' : '&') + s;
    }

    const res = await fetch(full, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!res.ok) {
        throw new Error('HTTP ' + res.status);
    }
    return res.text();
}

/** 统一轻提示（替代 cmsToast / alert） */
export function cmsToast(message, type) {
    const wise = W();
    if (wise && wise.ui && wise.ui.toast) {
        (wise.ui.toast[type] || wise.ui.toast.info).call(wise.ui.toast, message);
        return;
    }
    if (window.console) console.warn('[cms] toast 不可用：', message);
}

/** 统一确认框，返回 Promise<boolean> */
export async function cmsConfirm(message, title) {
    const wise = W();
    if (wise && wise.ui && wise.ui.dialog) {
        return wise.ui.dialog.confirm(message, { title: title || '操作确认' });
    }
    return window.confirm(message);
}

/**
 * 解析数据库 DATETIME 文本为时间戳（无值/非法返回 null）。
 *
 * ⚠️ 不要用 `new Date('2026-10-10 12:00:00')` 直接解析：空格分隔写法在 Safari/WebKit 上
 * 得到 Invalid Date（内核内置 moment 子集正是这么干的，见 `enhanced-table.js:34`，
 * 影响所有 `datetime` 列显示，登记为 P1-28）。这里补 `T` 归一。
 */
export function cmsParseDbTime(value) {
    if (!value) return null;
    const ts = Date.parse(String(value).replace(' ', 'T'));
    return Number.isNaN(ts) ? null : ts;
}

/**
 * 「投放期」状态：长期 / 未开始 / 投放中 / 已过期。
 *
 * 判定口径与 `FriendLinkService::queryFront()`、广告投放一致：**NULL = 不限**。
 * 供广告（P0-16）与友链（P0-21）共用，避免两页各写一套判断而口径漂移。
 *
 * @param {Object} row 含 `start_time` / `end_time` 的行数据
 * @returns {{key:string, text:string, cls:string}}
 */
export function cmsPeriodInfo(row) {
    const now = Date.now();
    const start = cmsParseDbTime(row && row.start_time);
    const end = cmsParseDbTime(row && row.end_time);

    if (end !== null && end < now) return { key: 'expired', text: '已过期', cls: 'bg-danger' };
    if (start !== null && start > now) return { key: 'pending', text: '未开始', cls: 'bg-warning text-dark' };
    if (start === null && end === null) return { key: 'long', text: '长期有效', cls: 'bg-secondary' };

    return { key: 'active', text: '投放中', cls: 'bg-success' };
}

/**
 * 复制文本到剪贴板（优先内核 `Wise.ui.clipboard`，再退原生 API）。
 *
 * @param {string} text
 * @returns {Promise<boolean>} 成功与否（失败由调用方给出「请手动复制」提示）
 */
export async function cmsCopy(text) {
    const value = String(text === null || text === undefined ? '' : text);
    const wise = W();

    if (wise && wise.ui && wise.ui.clipboard && typeof wise.ui.clipboard.copy === 'function') {
        try {
            await wise.ui.clipboard.copy(value);
            return true;
        } catch (e) {
            // 继续走原生兜底
        }
    }

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }
    } catch (e) {
        // 非安全上下文（http）下不可用
    }

    return false;
}

/**
 * 绑定「行右键菜单」（UIUX-P1-12）。
 *
 * 内核 EnhancedTable 的行 `<tr>` 带 `data('index')`，实例有 `getRowByIndex(index)`
 * （`enhanced-table.js:1270`）→ 据此拿到行数据，再用 `Wise.ui.contextMenu.show`
 * 弹出菜单（`wise-ui.js:342`，items: `{label,icon,action,id,divider,disabled}`）。
 *
 * @param {string|HTMLElement} container 表格容器（`#table`）
 * @param {Object} list        cmsTable 返回的实例（需含 `getRowByIndex`）
 * @param {Function} itemsBuilder `(row) => Array<{label,icon,action}>`；返回空则不弹
 */
export function cmsBindRowContextMenu(container, list, itemsBuilder) {
    const $container = $(container);
    if (!$container.length || typeof itemsBuilder !== 'function') return;

    $container.on('contextmenu', 'tr', function (e) {
        const index = parseInt($(this).data('index'), 10);
        if (isNaN(index)) return;
        const row = list && typeof list.getRowByIndex === 'function' ? list.getRowByIndex(index) : null;
        if (!row) return;
        const items = itemsBuilder(row);
        if (!items || !items.length) return;

        e.preventDefault();
        const wise = W();
        if (wise && wise.ui && wise.ui.contextMenu && typeof wise.ui.contextMenu.show === 'function') {
            wise.ui.contextMenu.show(items, { x: e.clientX, y: e.clientY });
        }
    });
}

/**
 * 绑定页面级键盘快捷键（UIUX-P1-11）。
 *
 * 约束：① 焦点在 `input/textarea/select/contenteditable` 内**不劫持**；
 * ② 仅 `Cmd/Ctrl+S`（保存）用修饰键，其余键需无修饰键、不与浏览器冲突。
 *
 * @param {Object} spec
 * @param {Function} [spec.search]  `/` 聚焦搜索
 * @param {Function} [spec.create]  `n` 新建
 * @param {Function} [spec.refresh] `r` 刷新
 * @param {Function} [spec.escape]  `Esc` 关层
 * @param {Function} [spec.save]    `Cmd/Ctrl+S` 保存
 */
export function cmsBindShortcuts(spec) {
    const cfg = spec || {};

    function inEditable(target) {
        if (!target) return false;
        const tag = String(target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
        return !!target.isContentEditable;
    }

    $(document).on('keydown', function (e) {
        if (inEditable(e.target)) return;

        // Cmd/Ctrl+S：拦截浏览器「保存网页」默认行为
        if ((e.metaKey || e.ctrlKey) && String(e.key || '').toLowerCase() === 's') {
            if (typeof cfg.save === 'function') {
                e.preventDefault();
                cfg.save(e);
            }
            return;
        }

        // 其余快捷键：不允许任何修饰键（避免与系统/浏览器快捷键冲突）
        if (e.metaKey || e.ctrlKey || e.altKey) return;

        const key = e.key === 'Escape' ? 'Escape' : String(e.key || '').toLowerCase();
        const map = { '/': cfg.search, n: cfg.create, r: cfg.refresh, Escape: cfg.escape };
        const handler = map[key];
        if (typeof handler === 'function') {
            e.preventDefault();
            handler(e);
        }
    });
}

/**
 * 监听「站点切换」事件（UIUX-P1-14）。
 *
 * `cms_context_bar.html` 切换站点成功后不再 `location.reload()`，而是派发
 * `cms-site-switched`（detail: `{siteId, siteName}`）。各页面用本函数注册回调，
 * 局部刷新自己的表格 / 栏目树，而不是整页重载。
 *
 * @param {Function} handler `(detail) => void`
 */
export function cmsOnSiteSwitched(handler) {
    if (typeof handler !== 'function') return;
    document.addEventListener('cms-site-switched', function (e) {
        handler(e.detail || {});
    });
}

/**
 * 绑定「行内开关」提交 —— **已退化为兜底**（UIUX-P1-27）。
 *
 * ⚠️ 历史更正：此前这里注释称「内核 `toggle` formatter 只渲染不提交、全库无处理器」，
 * 并据此用**文档级 `change` 委托**自行提交。经 P1-27 核实，该结论**不成立** ——
 * 内核 `enhanced-table.js` **一直内置** `handleRowToggleChange`（`bindCellCommonEvents`
 * 在表格初始化时用 `click` 委托绑定 `.form-switch>input[role="switch"]`，读 `data-url`/
 * `data-params` 提交、失败回滚），而各页面用 `formatter:'toggle'` 时**都已传 `url`**。
 * 于是本函数（`change`）与内核（`click`）会**双重提交同一开关**。
 *
 * P1-27 落地：内核处理器已补上 `data-confirm` 确认（此前渲染了却不读），插件侧此处
 * 仅在「内核 EnhancedTable 未加载」时退化为兜底绑定，正常环境不再重复提交。
 *
 * @param {Object} [opts]
 * @param {string} [opts.url]          兜底提交地址（开关自带 `data-url` 时以它为准）
 * @param {string} [opts.pk='id']      主键字段名
 * @param {*}      [opts.refresh]      成功后刷新的表格（EnhancedTable 实例 / 选择器）
 * @param {string} [opts.successMsg]   成功提示，默认「已更新」
 * @param {Function} [opts.message]    `(field, target) => string` 自定义成功提示
 */
export function cmsBindRowToggles(opts) {
    // P1-27：内核 EnhancedTable 已内置处理器（含 data-confirm）→ 插件侧不再绑 change，
    // 避免「内核 click + 插件 change」双重提交。仅内核缺失时走下方兜底绑定。
    const ET = window.EnhancedTable;
    if (typeof ET === 'function' && typeof ET.prototype.handleRowToggleChange === 'function') {
        return;
    }

    const cfg = opts || {};
    const pk = cfg.pk || 'id';

    const attr = (el, name) => {
        const v = el.getAttribute(name);
        return v === null ? undefined : v;
    };

    $(document).off('change.cmsRowToggle').on('change.cmsRowToggle', '.toggle-switch', async function () {
        const el = this;
        const $el = $(el);
        const id = attr(el, 'data-id');
        const field = attr(el, 'data-field');
        const url = attr(el, 'data-url') || cfg.url;
        const target = $el.prop('checked') ? attr(el, 'data-on-value') : attr(el, 'data-off-value');
        const confirmMsg = attr(el, 'data-confirm');

        if (!url || !field || id === undefined) {
            cmsToast('开关未配置提交地址', 'error');
            $el.prop('checked', !$el.prop('checked'));
            return;
        }

        if (confirmMsg) {
            const ok = await cmsConfirm(String(confirmMsg));
            if (!ok) {
                $el.prop('checked', !$el.prop('checked'));
                return;
            }
        }

        const payload = {};
        payload[pk] = id;
        payload[field] = target;

        const msg = typeof cfg.message === 'function'
            ? cfg.message(field, target)
            : (cfg.successMsg || '已更新');

        const res = await cmsAction({ url: url, data: payload, successMsg: msg });

        if (res && res.success) {
            if (cfg.refresh) cmsRefresh(cfg.refresh);
            return;
        }

        // 失败回滚：否则界面显示"已切换"而后端并未变更（假成功）
        $el.prop('checked', !$el.prop('checked'));
    });
}

/**
 * 刷新表格。
 *
 * @param {*} [target] EnhancedTable 实例 / 表格 id 字符串 / 自定义函数；
 *                     缺省时刷新页面内全部表格（与 `Wise.table.refresh()` 语义一致）
 */
export function cmsRefresh(target) {
    if (typeof target === 'function') {
        target();
        return;
    }
    const wise = W();
    if (wise && wise.table && typeof wise.table.refresh === 'function') {
        if (target) wise.table.refresh(target);
        else wise.table.refresh();
    }
}

/* ============================================================================
 * 三、响应契约（UIUX-P0-02）
 * ========================================================================== */

/**
 * 四种响应形状预设。页面上用 `cmsTable({ shape: 'model', ... })` 选择；
 * 也可逐个覆盖预设键（如显式传 `responseHandler` 接管）。
 */
export const SHAPES = {
    /** 12 个标准列表：paginate()->toArray() 原样放进 data */
    standard: {
        dataDataField: 'data.data',
        dataTotalField: 'data.total',
        dataPageNumber: 'data.current_page',
    },

    /** Model::index：data = {code:0, data:[], count:N, msg:''} */
    model: {
        sidePagination: 'server',
        responseHandler(res) {
            const d = (res && res.data) || {};
            return {
                rows: Array.isArray(d.data) ? d.data : [],
                total: Number(d.count) || 0,
            };
        },
    },

    /** ModelField::index：data = {code:0, data:[], msg:''}，无 count 且全量 → 前端分页 */
    modelField: {
        sidePagination: 'client',
        responseHandler(res) {
            const d = (res && res.data) || {};
            const rows = Array.isArray(d.data) ? d.data : [];
            return { rows, total: rows.length };
        },
    },

    /** UrlRule::index 等纯数组返回 → 前端分页 */
    flat: {
        sidePagination: 'client',
        responseHandler(res) {
            const d = res && res.data;
            const rows = Array.isArray(d) ? d : (d && Array.isArray(d.data) ? d.data : []);
            return { rows, total: rows.length };
        },
    },

    /**
     * 后台树形列表（`category/tree`，UIUX-P0-47）：`data = { rows: 扁平行[], tree: 嵌套树[] }`。
     *
     * 树表要的是**扁平行**（`parentIdField` 由 bootstrap-table 的 treegrid 扩展自行建树），
     * 所以只取 `rows`；`tree` 留给左侧栏目树类的组件。
     * **关闭分页**：栏目树按页切分没有意义（树会被切断），且 `pagination:false` 与
     * 内核 `admin/menu.js` 的可用配置一致。
     */
    adminTree: {
        pagination: false,
        responseHandler(res) {
            const d = (res && res.data) || {};
            const rows = Array.isArray(d.rows) ? d.rows : [];
            return { rows, total: rows.length };
        },
    },
};

/* ============================================================================
 * 四、分页参数映射（UIUX-P0-03）
 * ========================================================================== */

/**
 * 构造 bootstrap-table 的 queryParams：把内核发出的 `limit/offset`
 * 翻译成 wisecms 后端读取的 `page/limit`，并合并页面的筛选条件。
 *
 * @param {Object} options  已合并的 cmsTable 选项（读 pageSize 作兜底）
 * @param {Function|Object} [userQuery] 页面提供的 `query()`
 * @returns {Function}
 */
function buildQueryParams(options, userQuery) {
    const fallbackLimit = Number(options.pageSize) > 0 ? Number(options.pageSize) : 15;

    return function (params) {
        const p = params || {};
        const limit = Number(p.limit) > 0 ? Number(p.limit) : fallbackLimit;
        const offset = Number(p.offset) > 0 ? Number(p.offset) : 0;

        const query = {};

        // 1) 先合并业务筛选（空值丢弃，避免发出 ?keyword=&status=）
        //    把 bootstrap-table 的 params 透传进去：页面可以据此读取 `sort` / `order`
        //    等表格状态（如 wisecms 的 `?order=view_count desc` 白名单排序），
        //    旧实现不传参，页面拿不到排序信息（历史上只能放弃服务端排序）。
        let extra = null;
        if (typeof userQuery === 'function') extra = userQuery(p);
        else if (userQuery && typeof userQuery === 'object') extra = userQuery;

        if (extra && typeof extra === 'object') {
            Object.keys(extra).forEach((key) => {
                const value = extra[key];
                if (value === '' || value === null || value === undefined) return;
                if (Array.isArray(value) && value.length === 0) return;
                query[key] = value;
            });
        }

        // 2) 分页键**最后**写入：任何筛选都不能覆盖它
        query.page = Math.floor(offset / limit) + 1;
        query.limit = limit;

        return query;
    };
}

/* ============================================================================
 * 五、表格（UIUX-P0-01）
 * ========================================================================== */

/**
 * 创建 wisecms 后台列表表格。
 *
 * 在 `Wise.table.createSync` 之上补齐三件事：
 *   1. 按 `shape` 适配响应契约；
 *   2. 注入 `page`/`limit` 分页映射（含 `query()` 合并）；
 *   3. 固定页长模块可用 `fixedPageSize: true` 隐藏每页条数选择器
 *      （bootstrap-table 由 `paginationParts` 控制，去掉 `'pageSize'` 即隐藏）。
 *
 * @param {Object} options
 * @param {string} options.url                        列表接口
 * @param {Array}  options.columns                    列定义
 * @param {'standard'|'model'|'modelField'|'flat'} [options.shape='standard']
 * @param {Function|Object} [options.query]           筛选条件（由本函数消费）
 * @param {boolean} [options.fixedPageSize=false]     后端不读 limit 时置 true
 * @param {boolean} [options.checkboxHeader]          批量勾选（必须与批量接口成对）
 * @param {Object} [options.extend]                   透传（dragsort_url 等）
 * @param {Array}  [options.toolbarBtn]               工具条按钮
 * @param {Function} [options.onClick]                `operateButtons` 事件代理
 * @returns {Object} EnhancedTable 实例
 */
export function cmsTable(options) {
    const wise = W();
    const opt = Object.assign({}, options || {});

    // 1) 响应契约
    const shape = opt.shape || 'standard';
    delete opt.shape;
    const preset = SHAPES[shape] || SHAPES.standard;
    Object.keys(preset).forEach((key) => {
        if (opt[key] === undefined) opt[key] = preset[key];
    });

    // 2) 分页参数映射（页面自己传了 queryParams 就完全接管，尊重之）
    const userQuery = opt.query;
    delete opt.query;

    // 2b) 筛选态 ↔ URL 双向同步（UIUX-P1-03）
    //     此前 25 个控制器里 0 处写 URL：刷新即丢筛选、从编辑页返回丢上下文、复制地址给别人无效。
    //     这里统一在 cmsTable 层做（一处改动、全模块生效）：
    //       · 建表前把 URL 上的筛选值回填到 `#filter-<key>` / `[name=<key>]`；
    //       · 每次加载成功后把当前查询条件写回 URL（page=1 时省略，保持地址干净）。
    const syncUrl = opt.syncUrl !== false;
    delete opt.syncUrl;

    const currentQuery = {};
    let baselineOrder = null;
    if (typeof opt.queryParams !== 'function') {
        const built = buildQueryParams(opt, userQuery);
        opt.queryParams = function (params) {
            const q = built(params);
            Object.keys(currentQuery).forEach((k) => { delete currentQuery[k]; });
            Object.keys(q).forEach((k) => { currentQuery[k] = q[k]; });
            // 记住首屏默认排序：后面只把"用户改过的排序"写进 URL，避免地址栏里
            // 常驻一段 `?order=id+desc` 这种默认值噪声。
            if (baselineOrder === null) baselineOrder = String(q.order || '');
            return q;
        };
    }

    if (syncUrl && typeof window !== 'undefined' && window.history && window.history.replaceState) {
        hydrateFiltersFromUrl();

        const userLoadSuccess = opt.onLoadSuccess;
        opt.onLoadSuccess = function (data) {
            persistFiltersToUrl(currentQuery, baselineOrder);
            if (typeof userLoadSuccess === 'function') {
                return userLoadSuccess.apply(this, arguments);
            }
            return undefined;
        };
    }

    // 3) 固定页长：隐藏每页条数选择器（后端固定 paginate(N)，改了也没用）
    if (opt.fixedPageSize === true) {
        opt.paginationParts = ['pageInfo', 'pageList'];
    }
    delete opt.fixedPageSize;

    // 4) 加载失败统一提示（内核已注入，此处兜底防"白屏无提示"）
    if (typeof opt.onLoadError !== 'function') {
        opt.onLoadError = (err) => {
            cmsToast((err && (err.message || err.statusText)) || '列表加载失败', 'error');
        };
    }

    if (!wise || !wise.table || typeof wise.table.createSync !== 'function') {
        cmsToast('表格组件未就绪，请刷新页面重试', 'error');
        return null;
    }

    return wise.table.createSync(opt);
}

/**
 * 把 URL 上的筛选值回填到页面控件（`#filter-<key>` 优先，其次 `[name=<key>]`）。
 *
 * 只处理「可写值」的控件，且跳过分页/排序键——这些由表格自己管，
 * 回填它们会造成「URL 说第 3 页、表格从第 1 页开始」的错位。
 *
 * @returns {boolean} 是否发生了回填
 */
function hydrateFiltersFromUrl() {
    const skip = ['page', 'limit', 'offset', 'sort', 'order'];
    let changed = false;

    new URLSearchParams(window.location.search).forEach((value, key) => {
        if (skip.indexOf(key) !== -1) return;

        const el = document.querySelector('#filter-' + key)
            || document.querySelector('[name="' + key + '"]');
        if (!el) return;
        const tag = String(el.tagName || '').toUpperCase();
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA') return;
        if (el.type === 'checkbox' || el.type === 'radio') return;

        el.value = value;
        // 让页面的 change/input 监听（如级联下拉）也能感知
        el.dispatchEvent(new Event('change', { bubbles: true }));
        changed = true;
    });

    return changed;
}

/**
 * 把当前查询条件写回地址栏（`replaceState`，不新增历史记录）。
 *
 * @param {Object} query        `queryParams()` 产出的对象
 * @param {string} baselineOrder 首屏默认排序（与之一致则不写 URL）
 */
function persistFiltersToUrl(query, baselineOrder) {
    const skip = ['limit', 'offset', 'sort'];
    const qs = new URLSearchParams(window.location.search);

    Object.keys(query || {}).forEach((key) => {
        if (skip.indexOf(key) !== -1) return;
        const value = query[key];
        if (key === 'order' && String(value || '') === String(baselineOrder || '')) {
            qs.delete(key);
            return;
        }
        if (value === '' || value === null || value === undefined) {
            qs.delete(key);
            return;
        }
        qs.set(key, String(value));
    });

    // page=1 不写，避免出现 `?page=1` 这种无意义尾巴
    if (String(query && query.page) === '1') qs.delete('page');

    const search = qs.toString();
    const next = window.location.pathname + (search ? '?' + search : '') + window.location.hash;
    if (next !== window.location.pathname + window.location.search + window.location.hash) {
        window.history.replaceState(null, '', next);
    }
}

/* ============================================================================
 * 六、写操作：删除 / 批量 / 立即执行
 * ========================================================================== */

/**
 * 单个删除。
 *
 * 传了 `restoreUrl` 走内核「带撤销的删除」（软删除首选）；否则退化为
 * 「确认 → 执行 → toast」。
 *
 * @param {Object} spec
 * @param {string} spec.url                删除接口
 * @param {Object} spec.data               `{ id }`
 * @param {string} [spec.restoreUrl]       恢复接口（软删除实体应传）
 * @param {string} [spec.confirmMessage]
 * @param {string} [spec.successMsg]
 * @param {HTMLElement} [spec.target]
 * @param {*} [spec.refresh]               成功后刷新的表格（默认刷新本页全部表格）
 * @returns {Promise<{success:boolean, undone?:boolean}>}
 */
export async function cmsDelete(spec) {
    const wise = W();
    if (!wise || !wise.ui || !wise.ui.action) {
        cmsToast('操作组件未就绪', 'error');
        return { success: false };
    }

    const {
        url, data,
        restoreUrl,
        confirmMessage = '确定要删除该记录吗？',
        confirmTitle = '确认删除',
        successMsg = '删除成功',
        target = null,
        refresh,
    } = spec || {};

    let result;
    if (restoreUrl) {
        result = await wise.ui.action.delete({
            url, data, restoreUrl,
            confirmMessage, confirmTitle,
            message: successMsg,
            target,
        });
    } else {
        result = await wise.ui.action.execute({
            method: 'POST', url, data,
            confirmMessage, confirmTitle,
            successMsg,
            target,
        });
    }

    if (result && result.success) cmsRefresh(refresh);
    return result || { success: false };
}

/**
 * 批量操作。
 *
 * `ids` 必须是**数组**（后端 `post('ids/a')` / `(array) post('ids')`；
 * 逗号串在 `FriendLink::batchDelete` 会退化成单元素数组 → 只删 1 条）。
 *
 * @param {Object} spec
 * @param {string} spec.url
 * @param {Array}  spec.ids
 * @param {Object} [spec.data]             额外参数（如 `{ catid }`）
 * @param {string} [spec.confirmMessage]   自动带上条数
 * @param {string} [spec.successMsg]
 * @param {HTMLElement} [spec.target]
 * @param {*} [spec.refresh]
 * @returns {Promise<{success:boolean}>}
 */
export async function cmsBatch(spec) {
    const wise = W();
    if (!wise || !wise.ui || !wise.ui.action) {
        cmsToast('操作组件未就绪', 'error');
        return { success: false };
    }

    const {
        url, ids, data,
        confirmMessage,
        successMsg = '操作成功',
        target = null,
        refresh,
    } = spec || {};

    const list = Array.isArray(ids) ? ids : [];
    if (list.length === 0) {
        cmsToast('请先勾选要操作的记录', 'warning');
        return { success: false };
    }

    const result = await wise.ui.action.execute({
        method: 'POST',
        url,
        data: Object.assign({ ids: list }, data || {}),
        confirmMessage: confirmMessage || ('确定对选中的 ' + list.length + ' 条记录执行该操作？'),
        confirmTitle: '批量操作确认',
        successMsg,
        target,
    });

    if (result && result.success) cmsRefresh(refresh);
    return result || { success: false };
}

/**
 * 单次写操作（确认 → Loading → 执行 → toast → 刷新）。
 * 表单提交、状态切换等统一走这里，避免各页各写一遍。
 *
 * @param {Object} spec 透传 `Wise.ui.action.execute`，额外支持 `refresh`
 * @returns {Promise<{success:boolean}>}
 */
export async function cmsAction(spec) {
    const wise = W();
    if (!wise || !wise.ui || !wise.ui.action) {
        cmsToast('操作组件未就绪', 'error');
        return { success: false };
    }
    const { refresh, ...rest } = spec || {};
    const result = await wise.ui.action.execute(rest);
    if (result && result.success) cmsRefresh(refresh);
    return result || { success: false };
}

/**
 * 整页表单（create / edit）统一提交（UIUX-P0-52）。
 *
 * 解决的问题：category/model 的 create|edit 仍是「整页表单 + 内联脚本」旧模式，
 * 内联脚本既无法被内核按 action 分发（会报「控制器缺少方法 create」并把 loading
 * 遮罩永久留在弹层里），也违反了仓库「零内联脚本」的约定。本函数把这段逻辑收进
 * 公共层，页面控制器只需 `create() { return cmsFormPage({...}); }`。
 *
 * 三种落地路径（按优先级）：
 *   1. 在弹层 iframe 内 → 调父页面注册的钩子（局部刷新表格，不 reload）；
 *   2. 有 `redirect` → 跳过去（整页访问时的正常路径）；
 *   3. 都没有 → 回退 `history.back()`。
 *
 * @param {Object} spec
 * @param {string} [spec.form='#cmsForm']   表单选择器
 * @param {string} [spec.url]               提交地址（缺省取 form 的 action）
 * @param {string} [spec.successMsg='保存成功']
 * @param {string} [spec.parentHook='cmsFormDone'] 父页面钩子名（window[hook](res)）
 * @param {string} [spec.redirect]          成功后跳转地址
 * @param {Function} [spec.beforeSubmit]    返回 false 可拦截提交
 * @param {Function} [spec.after]           自定义成功后动作（覆盖钩子/跳转）
 * @returns {jQuery|null}
 */
export function cmsFormPage(spec) {
    const cfg = spec || {};
    const $form = $(cfg.form || '#cmsForm');
    if (!$form.length) {
        return null;
    }

    // 在弹层 iframe 内：隐藏本页自带页头/站点条（样式见 statics/css/wisecms-admin.css §3），
    // 否则弹层标题与页面标题会叠成"双层页头"。
    try {
        if (window.parent && window.parent !== window) {
            document.documentElement.classList.add('cms-in-layer');
        }
    } catch (err) {
        // 跨域时无法判断，保持原样
    }

    $form.on('submit', async function (e) {
        e.preventDefault();

        if (typeof cfg.beforeSubmit === 'function' && cfg.beforeSubmit($form) === false) {
            return;
        }

        const res = await cmsAction({
            url: cfg.url || $form.attr('action'),
            data: new FormData(this),
            successMsg: cfg.successMsg || '保存成功',
            confirmMessage: cfg.confirmMessage || '',
        });

        if (!res || !res.success) {
            return;
        }

        if (typeof cfg.after === 'function') {
            cfg.after(res);
            return;
        }

        // ① 弹层 iframe：交给父页面关层 + 局部刷新
        const hook = cfg.parentHook || 'cmsFormDone';
        try {
            if (window.parent && window.parent !== window && typeof window.parent[hook] === 'function') {
                window.parent[hook](res);
                return;
            }
        } catch (err) {
            // 跨域/父页面已销毁 → 走下方兜底
        }

        // ② 整页访问：跳回列表
        if (cfg.redirect) {
            window.location.href = cfg.redirect;
            return;
        }

        // ③ 兜底
        window.history.back();
    });

    return $form;
}

/**
 * L3 高危确认：必须**逐字输入确认词**才放行。
 *
 * 适用（方案 §4.6）：不可逆 / 影响全站 / 触发 DDL 的操作 ——
 * 彻底删除、清空回收站、删除站点、删除模型、删除字段、重建全站别名、
 * 重建内链、死链检测、采集执行。
 *
 * ⚠️ 关层语义（已核实 `ws-layer.js:1996-1999`）：`yes` 回调**只有严格返回 `true`
 * 才会关闭弹层**，返回 falsy 会保持打开 —— 确认词错误时正好借此让用户重试。
 *
 * @param {Object} spec
 * @param {string} spec.title              弹层标题
 * @param {string} spec.html               说明 HTML（调用方负责转义其中的动态数据）
 * @param {string} spec.word               需逐字输入的确认词（模型标识 / 站点名 / DELETE …）
 * @param {string} [spec.confirmText]      确定按钮文案
 * @param {Array}  [spec.area]
 * @returns {Promise<boolean>} 输入正确且点确定 → true；取消/关闭/超时 → false
 */
export function cmsDangerConfirm(spec) {
    const cfg = spec || {};
    const wise = W();

    if (!wise || !wise.ui || !wise.ui.dialog) {
        cmsToast('弹层组件未就绪', 'error');
        return Promise.resolve(false);
    }

    const word = String(cfg.word == null ? '' : cfg.word);
    if (!word) {
        cmsToast('未提供确认词，已阻止该高危操作', 'error');
        return Promise.resolve(false);
    }

    const inputId = 'cms-danger-word-' + Date.now().toString(36);
    const body = (cfg.html || '')
        + '<p class="mb-1">请输入 <code>' + cmsEscape(word) + '</code> 以确认：</p>'
        + '<input type="text" class="form-control" id="' + inputId + '" autocomplete="off">';

    return new Promise((resolve) => {
        let settled = false;
        const finish = (ok) => {
            if (settled) return;
            settled = true;
            resolve(ok);
        };

        wise.ui.dialog.open(body, cfg.title || '高危操作确认', {
            area: cfg.area || ['520px', '360px'],
            btn: [cfg.confirmText || '确定执行', '取消'],
            shadeClose: false,

            success(layero) {
                layero.find('#' + inputId).trigger('focus');
            },

            yes(index, layero) {
                const typed = String(layero.find('#' + inputId).val() || '').trim();
                if (typed !== word) {
                    cmsToast('确认词不正确，请逐字输入「' + word + '」', 'warning');
                    return false; // 保持弹层打开
                }
                finish(true);
                wise.ui.dialog.close(index);
                return false; // 已主动 close，无需框架再关一次
            },

            end() {
                finish(false);
            },
        });
    });
}

/* ============================================================================
 * 七、表单弹层（UIUX-P0-01 的 cmsDialog）
 * ========================================================================== */

/** 支持的字段类型 → 渲染分支 */
const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'radio', 'toggle', 'color', 'date', 'datetime', 'password', 'hidden'];

/**
 * 渲染单个字段为 Bootstrap 5 结构。
 * 校验属性走 `data-validation`（前端词汇），与 skill `wiseadmin-admin-ui` §3.1 一致。
 */
function renderField(field) {
    const name = String(field.name || '');
    const id = 'cmsf-' + name.replace(/[^a-zA-Z0-9_-]/g, '-');
    const type = FIELD_TYPES.indexOf(field.type) === -1 ? 'text' : field.type;
    const value = field.value === undefined || field.value === null ? '' : field.value;

    const label = field.label === undefined ? name : field.label;
    const requiredMark = field.required ? ' <span class="text-danger">*</span>' : '';
    const help = field.help ? '<div class="form-text">' + cmsEscape(field.help) + '</div>' : '';

    // 只有一个 data-validation 属性：自定义规则优先，其次 required
    const rules = field.validation || (field.required ? 'required' : '');
    const messages = field.messages || {};
    let validationAttrs = '';
    if (rules) {
        validationAttrs += ' data-validation="' + cmsEscape(rules) + '"';
        rules.split('|').forEach((rule) => {
            const ruleName = rule.split(':')[0];
            if (messages[ruleName]) {
                validationAttrs += ' data-validation-message-' + ruleName + '="' + cmsEscape(messages[ruleName]) + '"';
            } else if (ruleName === 'required') {
                validationAttrs += ' data-validation-message-required="' + cmsEscape('请填写' + (label || name)) + '"';
            }
        });
    }

    const feedback = '<div class="invalid-feedback"><span class="validation-text"></span></div>';
    const baseAttrs = 'name="' + cmsEscape(name) + '" id="' + id + '"'
        + (field.readonly ? ' readonly' : '')
        + (field.disabled ? ' disabled' : '')
        + validationAttrs;

    let control = '';

    if (type === 'hidden') {
        return '<input type="hidden" name="' + cmsEscape(name) + '" id="' + id + '" value="' + cmsEscape(value) + '">';
    }

    if (type === 'textarea') {
        control = '<textarea class="form-control" rows="' + (field.rows || 3) + '" ' + baseAttrs
            + (field.placeholder ? ' placeholder="' + cmsEscape(field.placeholder) + '"' : '')
            + '>' + cmsEscape(value) + '</textarea>';
    } else if (type === 'select') {
        const options = field.options || {};
        let opts = field.placeholder === false ? '' : '<option value="">' + cmsEscape(field.placeholder || '请选择') + '</option>';
        Object.keys(options).forEach((key) => {
            const selected = String(key) === String(value) ? ' selected' : '';
            opts += '<option value="' + cmsEscape(key) + '"' + selected + '>' + cmsEscape(options[key]) + '</option>';
        });
        control = '<select class="form-select" ' + baseAttrs + '>' + opts + '</select>';
    } else if (type === 'radio') {
        const options = field.options || {};
        control = '<div class="d-flex flex-wrap gap-3 pt-1">';
        Object.keys(options).forEach((key, index) => {
            const rid = id + '-' + index;
            const checked = String(key) === String(value) ? ' checked' : '';
            control += '<div class="form-check">'
                + '<input class="form-check-input" type="radio" name="' + cmsEscape(name) + '" id="' + rid + '"'
                + ' value="' + cmsEscape(key) + '"' + checked + '>'
                + '<label class="form-check-label" for="' + rid + '">' + cmsEscape(options[key]) + '</label>'
                + '</div>';
        });
        control += '</div>';
        return '<div class="mb-3"><label class="form-label">' + cmsEscape(label) + requiredMark + '</label>'
            + control + help + '</div>';
    } else if (type === 'toggle') {
        const checked = String(value) === '1';
        control = '<div class="form-check form-switch pt-1">'
            + '<input class="form-check-input" type="checkbox" role="switch" name="' + cmsEscape(name) + '" id="' + id + '"'
            + ' value="1"' + (checked ? ' checked' : '') + '>'
            + '</div>';
    } else {
        const inputType = type === 'datetime' ? 'datetime-local' : type;
        control = '<input type="' + inputType + '" class="form-control" ' + baseAttrs
            + ' value="' + cmsEscape(value) + '"'
            + (field.placeholder ? ' placeholder="' + cmsEscape(field.placeholder) + '"' : '')
            + (field.min !== undefined ? ' min="' + cmsEscape(field.min) + '"' : '')
            + (field.max !== undefined ? ' max="' + cmsEscape(field.max) + '"' : '')
            + (field.step !== undefined ? ' step="' + cmsEscape(field.step) + '"' : '')
            + '>';
    }

    return '<div class="mb-3">'
        + (label ? '<label class="form-label" for="' + id + '">' + cmsEscape(label) + requiredMark + '</label>' : '')
        + control + feedback + help + '</div>';
}

/** 从表单 DOM 取值（与后端 `post()` 语义对齐：toggle → 1/0） */
function readFormData(formEl) {
    const data = {};
    formEl.querySelectorAll('[name]').forEach((el) => {
        const name = el.getAttribute('name');
        if (!name || el.disabled) return;

        if (el.type === 'checkbox') {
            data[name] = el.checked ? (el.value || 1) : 0;
        } else if (el.type === 'radio') {
            if (el.checked) data[name] = el.value;
        } else if (el.multiple) {
            data[name] = Array.prototype.map.call(el.selectedOptions, (o) => o.value);
        } else {
            data[name] = el.value;
        }
    });
    return data;
}

/**
 * 打开表单弹层并提交。
 *
 * @param {Object} spec
 * @param {string} spec.title                        弹层标题
 * @param {Array}  spec.fields                       字段定义（见 renderField）
 * @param {string} spec.url                          提交地址
 * @param {string} [spec.method='POST']
 * @param {string} [spec.submitText='保存']
 * @param {string} [spec.successMsg='保存成功']
 * @param {string} [spec.confirmMessage]             提交前二次确认
 * @param {Array}  [spec.area=['620px','520px']]     ws-layer 尺寸
 * @param {string} [spec.intro]                      顶部说明 HTML
 * @param {Function} [spec.onSubmit]                 `(payload, formEl) => payload|false`
 * @param {Function} [spec.onSuccess]                `(result, close) => void`
 * @param {*} [spec.refresh]                         成功后刷新的表格
 * @returns {Promise<{success:boolean}>}
 */
export function cmsFormDialog(spec) {
    const cfg = spec || {};
    const wise = W();

    if (!wise || !wise.ui || !wise.ui.dialog) {
        cmsToast('弹层组件未就绪', 'error');
        return Promise.resolve({ success: false });
    }

    const fields = cfg.fields || [];
    const formId = 'cms-form-' + Date.now().toString(36);

    const body = '<form id="' + formId + '" novalidate>'
        + (cfg.intro || '')
        + fields.map(renderField).join('')
        + '</form>';

    return new Promise((resolve) => {
        let settled = false;

        wise.ui.dialog.open(body, cfg.title || '', {
            area: cfg.area || ['620px', '520px'],
            btn: [cfg.submitText || '保存', '取消'],
            shadeClose: false,

            success: (layero) => {
                // 初始化校验器（字段级即时校验；提交仍由本函数负责）
                const Validator = (wise.ws && wise.ws.WsFormValidator) || window.WsFormValidator;
                if (typeof Validator === 'function') {
                    try {
                        layero.data('cmsValidator', new Validator(formId, { liveValidation: true }));
                    } catch (e) {
                        if (window.console) console.warn('[cms] 校验器初始化失败', e);
                    }
                }
            },

            yes: async (index, layero) => {
                const formEl = layero.find('#' + formId)[0] || layero.find('form')[0];
                if (!formEl) return false;

                // 1) 校验（失败不关弹层）
                const validator = layero.data('cmsValidator');
                if (validator && typeof validator.validateForm === 'function') {
                    if (validator.validateForm() !== true) return false;
                }

                // 2) 组装 payload
                let payload = readFormData(formEl);
                if (typeof cfg.onSubmit === 'function') {
                    const custom = cfg.onSubmit(payload, formEl);
                    if (custom === false) return false;
                    if (custom && typeof custom === 'object') payload = custom;
                }

                // 3) 执行
                const result = await cmsAction({
                    method: cfg.method || 'POST',
                    url: cfg.url,
                    data: payload,
                    successMsg: cfg.successMsg || '保存成功',
                    confirmMessage: cfg.confirmMessage || null,
                    target: layero.find('.ws-layer-btn-yes, .layui-layer-btn0')[0] || null,
                    refresh: null,
                });

                if (result && result.success) {
                    settled = true;
                    wise.ui.dialog.close(index);
                    if (typeof cfg.onSuccess === 'function') cfg.onSuccess(result, index);
                    else cmsRefresh(cfg.refresh);
                    resolve({ success: true });
                }
                return false; // 失败时保持弹层打开，便于改后重试
            },

            end: () => {
                if (!settled) resolve({ success: false });
            },
        });
    });
}

/* ============================================================================
 * 八、长任务轮询（UIUX-P0-01 的 cmsPollTask）
 * ========================================================================== */

/**
 * 轮询任务状态（AI 生成 / 采集 / URL 重建 / 死链检测统一用这个）。
 *
 * @param {Object} spec
 * @param {string} spec.url                       状态查询地址
 * @param {Object} [spec.data]                    查询参数（如 `{ id }`）
 * @param {number} [spec.interval=2000]           轮询间隔 ms
 * @param {number} [spec.timeout=600000]          总超时 ms
 * @param {Function} [spec.getData]               `(res) => payload`，默认取 `res.data`
 * @param {Function} [spec.isDone]                `(payload) => boolean`，默认 status ∈ 终态
 * @param {Function} [spec.onTick]                `(payload)` 每次返回后调用
 * @param {Function} [spec.onDone]                `(payload)` 终态调用
 * @returns {{stop:Function, promise:Promise}}
 */
export function cmsPollTask(spec) {
    const cfg = spec || {};
    const wise = W();
    const http = wise && wise.util && wise.util.http;

    const getData = cfg.getData || ((res) => (res && res.data) || {});
    const isDone = cfg.isDone || ((p) => ['completed', 'failed', 'cancelled', 'canceled'].indexOf(p && p.status) !== -1);
    const interval = Number(cfg.interval) > 0 ? Number(cfg.interval) : 2000;
    const timeout = Number(cfg.timeout) > 0 ? Number(cfg.timeout) : 600000;

    let timer = null;
    let stopped = false;
    const startedAt = Date.now();

    const promise = new Promise((resolve) => {
        const finish = (payload, ok) => {
            if (stopped && ok === undefined) return;
            stopped = true;
            if (timer) clearTimeout(timer);
            if (ok !== false && typeof cfg.onDone === 'function') cfg.onDone(payload);
            resolve({ ok: ok !== false, payload });
        };

        const tick = async () => {
            if (stopped) return;

            if (Date.now() - startedAt > timeout) {
                cmsToast('任务轮询超时，请稍后到列表中查看结果', 'warning');
                return;
            }

            if (!http || typeof http.get !== 'function') {
                cmsToast('请求组件未就绪，无法轮询任务', 'error');
                return;
            }

            try {
                const res = await http.get(cfg.url, cfg.data || {});
                const payload = getData(res);
                if (typeof cfg.onTick === 'function') cfg.onTick(payload);
                if (isDone(payload)) {
                    finish(payload, true);
                    return;
                }
            } catch (e) {
                // 单次失败不终止：网络抖动时继续轮询，超时兜底
                if (window.console) console.warn('[cms] 任务轮询失败', e);
            }

            if (!stopped) timer = setTimeout(tick, interval);
        };

        tick();
    });

    return {
        stop() { stopped = true; if (timer) clearTimeout(timer); },
        promise,
    };
}

/* ============================================================================
 * 九、栏目树（左侧筛选树 / 栏目选择器数据源）
 * ========================================================================== */

/** 组件自带样式（按 id 幂等注入，避免要求各页面重复引 CSS 文件） */
function ensureTreeStyle() {
    if (document.getElementById('cms-cat-tree-style')) return;
    const style = document.createElement('style');
    style.id = 'cms-cat-tree-style';
    style.textContent = [
        '.cms-cat-tree,.cms-cat-tree ul{list-style:none;margin:0;padding:0;}',
        '.cms-cat-tree li{position:relative;}',
        '.cms-cat-tree .cms-cat-row{display:flex;align-items:center;gap:.25rem;border-radius:.25rem;}',
        '.cms-cat-tree .cms-cat-row:hover{background:var(--bs-tertiary-bg,#f2f4f7);}',
        '.cms-cat-tree .cms-cat-row.active{background:var(--bs-primary-bg-subtle,#cfe2ff);font-weight:600;}',
        '.cms-cat-tree .cms-cat-toggle{width:1.1rem;flex:0 0 1.1rem;text-align:center;cursor:pointer;color:var(--bs-secondary-color,#6c757d);}',
        '.cms-cat-tree .cms-cat-toggle.is-leaf{visibility:hidden;}',
        '.cms-cat-tree .cms-cat-link{flex:1 1 auto;min-width:0;padding:.2rem .25rem;color:inherit;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
        '.cms-cat-tree .cms-cat-link .badge{font-weight:400;}',
        '.cms-cat-tree .cms-cat-count{color:var(--bs-secondary-color,#6c757d);font-size:.75rem;}',
    ].join('');
    document.head.appendChild(style);
}

/**
 * 渲染栏目树。
 *
 * 数据源固定为 `category/tree`（`{ rows, tree }`，UIUX-P0-47）：`tree` 用于本组件，
 * `rows` 供「批量移动」等下拉场景使用 —— 二者共用一次请求。
 *
 * @param {HTMLElement|string} container 容器元素或选择器
 * @param {Object} [spec]
 * @param {string} [spec.url]                默认为 category/tree
 * @param {Function} [spec.onSelect]         `(catid, node) => void`；`catid=0` 表示「全部栏目」
 * @param {number|string} [spec.current]     初始选中项
 * @param {string} [spec.allLabel='全部栏目']
 * @returns {Promise<{rows:Array, tree:Array, select:Function, reload:Function}>}
 */
export async function cmsCategoryTree(container, spec) {
    const cfg = spec || {};
    const el = typeof container === 'string' ? document.querySelector(container) : container;

    const empty = { rows: [], tree: [], select: () => {}, reload: () => {} };
    if (!el) return empty;

    const wise = W();
    const http = wise && wise.util && wise.util.http;
    if (!http) {
        cmsToast('请求组件未就绪，栏目树加载失败', 'error');
        return empty;
    }

    ensureTreeStyle();

    const url = cfg.url || cmsUrl('category/tree');
    const allLabel = cfg.allLabel || '全部栏目';
    // P1-10 增强开关（可选，缺省不启用，不影响既有调用方）
    const enableSearch = !!cfg.search;
    const persistKey   = cfg.persist || '';
    const ctxMenu      = cfg.contextMenu || null;

    let rows = [];
    let tree = [];
    let current = cfg.current === undefined || cfg.current === null ? 0 : cfg.current;

    // P1-10：展开态记忆（persistKey 提供时读写 localStorage）
    const expanded = new Set();
    function loadExpanded() {
        if (!persistKey) return;
        try {
            const raw = localStorage.getItem(persistKey);
            if (raw) JSON.parse(raw).forEach((id) => expanded.add(String(id)));
        } catch (e) { /* 解析失败按无记忆处理 */ }
    }
    function saveExpanded() {
        if (!persistKey) return;
        try { localStorage.setItem(persistKey, JSON.stringify([...expanded])); } catch (e) { /* 忽略 */ }
    }
    loadExpanded();

    /** 递归渲染节点（返回 `<li>` 序列，不含外层 `<ul>`；子级自带一层 `<ul>`） */
    function renderNodes(nodes) {
        return nodes.map((node) => {
            const children = Array.isArray(node.children) ? node.children : [];
            const hasChild = children.length > 0;
            const disabled = String(node.status) === '0';
            const badge = disabled ? ' <span class="badge bg-secondary">禁用</span>' : '';

            return '<li>'
                + '<div class="cms-cat-row" data-catid="' + cmsEscape(node.id) + '">'
                + '<span class="cms-cat-toggle' + (hasChild ? '' : ' is-leaf') + '">'
                + '<i class="bi bi-caret-down-fill"></i></span>'
                + '<a href="javascript:;" class="cms-cat-link">'
                + cmsEscape(node.name) + badge + '</a>'
                + '</div>'
                + (hasChild ? '<ul>' + renderNodes(children) + '</ul>' : '')
                + '</li>';
        }).join('');
    }

    function paint() {
        let html = '';
        if (enableSearch) {
            html += '<div class="p-2 border-bottom"><input type="text" class="form-control form-control-sm cms-cat-search"'
                + ' placeholder="搜索栏目" autocomplete="off"></div>';
        }
        html += '<ul class="cms-cat-tree">'
            + '<li><div class="cms-cat-row" data-catid="0">'
            + '<span class="cms-cat-toggle is-leaf"></span>'
            + '<a href="javascript:;" class="cms-cat-link"><i class="bi bi-collection"></i> '
            + cmsEscape(allLabel) + '</a></div></li>'
            + renderNodes(tree)
            + '</ul>';

        el.innerHTML = html;
        markActive(current);
        restoreExpanded();

        if (enableSearch) {
            const $search = el.querySelector('.cms-cat-search');
            if ($search) $search.addEventListener('input', () => applySearch($search.value));
        }
    }

    /** P1-10：恢复展开态（读取 expanded Set） */
    function restoreExpanded() {
        el.querySelectorAll('.cms-cat-toggle:not(.is-leaf)').forEach((t) => {
            const li = t.closest('li');
            const sub = li ? li.querySelector(':scope > ul') : null;
            if (!sub) return;
            const row = li.querySelector('.cms-cat-row');
            const catid = row ? row.dataset.catid : '';
            const open = expanded.has(String(catid));
            sub.style.display = open ? '' : 'none';
            const icon = t.querySelector('i');
            if (icon) icon.className = open ? 'bi bi-caret-down-fill' : 'bi bi-caret-right-fill';
        });
    }

    /** P1-10：搜索定位（匹配节点显示 + 祖先展开 + 高亮；空关键词还原） */
    function applySearch(keyword) {
        const kw = String(keyword || '').trim().toLowerCase();
        el.querySelectorAll('.cms-cat-row').forEach((row) => {
            const link = row.querySelector('.cms-cat-link');
            const name = link ? link.textContent : '';
            const li = row.closest('li');
            const hit = kw === '' || name.toLowerCase().includes(kw);
            // 命中显示；未命中隐藏（但保留「全部栏目」根行）
            if (String(row.dataset.catid) === '0') {
                row.style.display = '';
                return;
            }
            row.style.display = hit ? '' : 'none';
            // 命中的节点展开祖先路径
            if (hit && kw !== '' && li) {
                let p = li.parentElement;
                while (p && p !== el) {
                    if (p.tagName === 'UL') {
                        p.style.display = '';
                        const parentLi = p.parentElement;
                        if (parentLi) {
                            const icon = parentLi.querySelector(':scope > .cms-cat-row .cms-cat-toggle i');
                            if (icon) icon.className = 'bi bi-caret-down-fill';
                        }
                    }
                    p = p.parentElement;
                }
            }
            // 高亮匹配文字
            if (link) {
                if (hit && kw !== '') {
                    const idx = name.toLowerCase().indexOf(kw);
                    link.innerHTML = cmsEscape(name.slice(0, idx))
                        + '<mark>' + cmsEscape(name.slice(idx, idx + kw.length)) + '</mark>'
                        + cmsEscape(name.slice(idx + kw.length));
                } else {
                    link.textContent = name;
                }
            }
        });
    }

    function markActive(catid) {
        el.querySelectorAll('.cms-cat-row').forEach((row) => {
            row.classList.toggle('active', String(row.dataset.catid) === String(catid));
        });
    }

    el.addEventListener('click', (e) => {
        const toggle = e.target.closest('.cms-cat-toggle');
        if (toggle && !toggle.classList.contains('is-leaf')) {
            const li = toggle.closest('li');
            const sub = li ? li.querySelector(':scope > ul') : null;
            if (sub) {
                const hidden = sub.style.display === 'none';
                sub.style.display = hidden ? '' : 'none';
                const icon = toggle.querySelector('i');
                if (icon) icon.className = hidden ? 'bi bi-caret-down-fill' : 'bi bi-caret-right-fill';
                // P1-10：记录展开态
                const row = li.querySelector('.cms-cat-row');
                const catid = row ? row.dataset.catid : '';
                if (catid) {
                    if (hidden) expanded.add(String(catid));
                    else expanded.delete(String(catid));
                    saveExpanded();
                }
            }
            return;
        }

        const row = e.target.closest('.cms-cat-row');
        if (!row) return;

        current = parseInt(row.dataset.catid, 10) || 0;
        markActive(current);
        if (typeof cfg.onSelect === 'function') cfg.onSelect(current, findNode(current));
    });

    // P1-10：右键菜单（新增子栏目 / 编辑 / 删除）
    if (ctxMenu) {
        el.addEventListener('contextmenu', (e) => {
            const row = e.target.closest('.cms-cat-row');
            if (!row || String(row.dataset.catid) === '0') return; // 「全部栏目」无右键
            e.preventDefault();
            const catid = parseInt(row.dataset.catid, 10) || 0;
            const node = findNode(catid);
            if (!node) return;
            showContextMenu(e.clientX, e.clientY, node);
        });
    }

    function showContextMenu(x, y, node) {
        const old = document.querySelector('.cms-cat-menu');
        if (old) old.remove();

        const items = [
            { label: '新增子栏目', icon: 'bi bi-plus-lg', run: () => ctxMenu.addChild && ctxMenu.addChild(node) },
            { label: '编辑栏目', icon: 'bi bi-pencil', run: () => ctxMenu.edit && ctxMenu.edit(node) },
            { label: '删除栏目', icon: 'bi bi-trash', run: () => ctxMenu.remove && ctxMenu.remove(node), danger: true },
        ].filter((it) => it.run);

        const $menu = document.createElement('div');
        $menu.className = 'cms-cat-menu';
        $menu.style.cssText = 'position:fixed;z-index:1050;min-width:150px;background:#fff;'
            + 'border:1px solid #dee2e6;border-radius:.25rem;box-shadow:0 4px 12px rgba(0,0,0,.12);'
            + 'left:' + x + 'px;top:' + y + 'px;';
        items.forEach((it) => {
            const a = document.createElement('a');
            a.href = 'javascript:;';
            a.className = 'dropdown-item' + (it.danger ? ' text-danger' : '');
            a.innerHTML = '<i class="bi ' + it.icon + ' me-2"></i>' + cmsEscape(it.label);
            a.addEventListener('click', () => {
                document.querySelector('.cms-cat-menu')?.remove();
                it.run();
            });
            $menu.appendChild(a);
        });
        document.body.appendChild($menu);

        const close = (ev) => {
            if (!$menu.contains(ev.target)) {
                $menu.remove();
                document.removeEventListener('mousedown', close);
            }
        };
        document.addEventListener('mousedown', close);
    }

    function findNode(catid) {
        const stack = tree.slice();
        while (stack.length) {
            const node = stack.shift();
            if (String(node.id) === String(catid)) return node;
            if (Array.isArray(node.children)) stack.push(...node.children);
        }
        return null;
    }

    async function reload() {
        try {
            // 内核 http.get 默认 interceptor:true → resolve 的就是信封里的 data（即 {rows, tree}），
            // 此处不能再套一层 `res.data`（那会拿到 undefined → 树恒为空）。
            const d = (await http.get(url)) || {};
            rows = Array.isArray(d.rows) ? d.rows : [];
            tree = Array.isArray(d.tree) ? d.tree : [];
            paint();
        } catch (e) {
            // 拦截器已提示；此处保持容器原样，不抛断页面
            if (window.console) console.warn('[cms] 栏目树加载失败', e);
        }
        return { rows, tree };
    }

    await reload();

    return {
        get rows() { return rows; },
        get tree() { return tree; },
        select(catid) {
            current = parseInt(catid, 10) || 0;
            markActive(current);
        },
        reload,
    };
}

/* ============================================================================
 * 十、默认导出（便于 `import cms from './cms-admin.js'`）
 * ========================================================================== */

export default {
    PLUGIN_IDENT,
    BASE,
    ASSET,
    cmsUrl,
    cmsEscape,
    cmsFetchText,
    cmsToast,
    cmsConfirm,
    cmsRefresh,
    SHAPES,
    cmsTable,
    cmsDelete,
    cmsBatch,
    cmsAction,
    cmsFormDialog,
    cmsDangerConfirm,
    cmsPollTask,
    cmsCategoryTree,
    cmsOperateButtons,
    cmsDecodeEntities,
    cmsBindRowToggles,
    cmsParseDbTime,
    cmsPeriodInfo,
    cmsCopy,
};
