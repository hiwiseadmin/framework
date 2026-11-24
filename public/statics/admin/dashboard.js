/**
 * admin/dashboard.js — 数据面板编排器（ESM）
 *
 * 依赖：jQuery / GridStack（window 全局）、Bootstrap bundle（Modal 插件）、父框架 Layer。
 *
 * 职责：
 *  - 从 window.$dashboardData（服务端直出）或 API 获取 layout + catalog + entries；
 *  - 以 GridStack 编排面板（位置/尺寸/主题色由服务端持久化，见 dashboard_layouts 表）；
 *  - 数据面板通用渲染：stat / entries（图标快捷入口）/ list / welcome(html)；
 *  - 编辑交互：添加/恢复面板、主题色切换、props 配置、移除、恢复默认布局；
 *  - 防抖保存布局到 POST /admin/dashboard/saveLayout。
 */
const $ = window.jQuery;
const GridStack = window.GridStack;

/**
 * 统一弹窗开关（UIS v1 §4.1）：走 Bootstrap 5 的 Modal 实例，不用 jQuery 的 `$.fn.modal`
 *（那是 BS3/BS4 API，本项目靠一层来路不明的桥接才没报错）。
 *
 * @param {string|Element|Object} target 选择器 / DOM / jQuery 对象
 * @param {'show'|'hide'} [action='show']
 */
function wsModal(target, action = 'show') {
    const el = typeof target === 'string'
        ? document.querySelector(target)
        : (target && target.jquery ? target[0] : target);

    if (!el || !window.bootstrap || !window.bootstrap.Modal) return null;

    const inst = window.bootstrap.Modal.getOrCreateInstance(el);
    if (action === 'hide') inst.hide(); else inst.show();
    return inst;
}
const Layer = (window.parent && window.parent.Layer) || window.Layer;

const API = '/admin/dashboard/';
const ACCENT_CYCLE = ['', 'brand', 'success', 'warning', 'danger', 'info'];
const ACCENT_LABELS = { '': '跟随主题', brand: '品牌', success: '成功', warning: '警告', danger: '危险', info: '信息' };
const SOURCE_LABELS = { system: '系统面板' };

// 最大可见面板数（单 key 单实例）
const MAX_PANELS = 60;

const state = {
    layout: null,          // { settings, panels }
    catalog: [],           // 可见面板目录
    metaByKey: new Map(),  // widgetKey -> catalog meta
    uidMap: new Map(),     // panel.uid -> panel（引用布局中的记录）
    grid: null,
    suppressSave: false,
    timers: new Map(),     // uid -> setInterval
    saveTimer: null,
};

const Controller = {
    index() {
        // ---------- 0. 运行环境检查 ----------
        if (!window.jQuery) {
            renderFatal('jQuery 未加载，数据面板无法初始化');
            return;
        }
        if (!window.GridStack) {
            renderFatal('GridStack 组件未加载，数据面板无法初始化');
            return;
        }

        // ---------- 1. 初始化数据 ----------
        const init = window.$dashboardData || {};
        state.layout = normalizeLayout(init.layout);
        state.catalog = Array.isArray(init.catalog) ? init.catalog : [];
        state.catalog.forEach(m => state.metaByKey.set(m.key, m));

        // 同步各面板可见性/尺寸到目录（服务端缺省元数据补全）
        syncCatalogToPanels();

        // ---------- 2. GridStack ----------
        const s = state.layout.settings;
        // 官方间距机制：margin 作用于 .grid-stack-item-content 四边 inset，
        // item 间的实际间隔 = 相邻两侧 margin 之和。
        // 修复点：margin 必须作为 init 选项传给 GridStack 才会生效（v9 曾算出却未传入）。
        // 支持独立调节：settings.marginX / marginY（px，缺省回退到 margin），
        // 以 CSS 短横写法 '纵向 横向' 传入 GridStack。
        const base = Math.max(0, Number(s.margin) || 12);
        const marginY = Number.isFinite(Number(s.marginY)) ? Math.max(0, Number(s.marginY)) : base;
        const marginX = Number.isFinite(Number(s.marginX)) ? Math.max(0, Number(s.marginX)) : base;

        // 动态样式表策略：继承页面 script nonce，确保 GridStack 注入的间距规则不受 CSP 限制。
        let nonce = '';
        try {
            const nonceEl = document.querySelector('script[nonce]');
            if (nonceEl) nonce = nonceEl.getAttribute('nonce') || '';
        } catch (_) { /* 忽略 */ }

        state.grid = GridStack.init({
            column: s.columns,
            cellHeight: s.cellHeight,
            margin: `${marginY}px ${marginX}px`,
            float: true,
            resizable: { handles: 'e, se, s' },
            handle: '.panel-header',
            nonce,
            // GridStack v12+ 起已删除以下两项：
            //  - disableOneColumnMode / oneColumnSize：不再有自动降为单列的旧模式；
            //  - styleInHead：样式改为内联 CSS 变量（--gs-*），不再注入动态 <style>。
        }, '#panel-grid');

        if (!state.grid) {
            renderFatal('网格容器初始化失败，请刷新后重试');
            return;
        }

        // ---------- 3. 挂载面板（首次挂载不触发保存） ----------
        state.suppressSave = true;
        mountAllPanels();
        state.suppressSave = false;
        updateEmptyState();

        // ---------- 4. 事件 ----------
        bindGridEvents();
        bindToolbarEvents();
        bindConfigModalEvents();

        window.addEventListener('pagehide', cleanupAll);
        window.addEventListener('resize', debounce(() => scheduleSave(false), 500));
    },
};

// ============================================================
// 通用工具
// ============================================================

function uid() {
    return 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
}

function escapeHtml(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toast(msg, type = 'info') {
    try {
        if (Layer && typeof Layer.toast === 'function') {
            Layer.toast({ content: msg, type: type === 'error' ? 'error' : 'success', offset: 't' });
            return;
        }
        if (Layer && typeof Layer.msg === 'function') {
            Layer.msg(msg, { icon: type === 'error' ? 2 : 1, time: 1800 });
            return;
        }
    } catch (_) { /* 忽略跨框架异常 */ }
    alert(msg);
}

function confirmBox(message, onOk) {
    try {
        if (Layer && typeof Layer.confirm === 'function') {
            Layer.confirm(message, { icon: 3 }, (idx) => { Layer.close(idx); onOk(); });
            return;
        }
    } catch (_) { /* fallthrough */ }
    if (window.confirm(message)) onOk();
}

// 简单请求封装：返回 Promise<data>，非 0 抛出 message
function request(method, url, payload) {
    return new Promise((resolve, reject) => {
        $.ajax({
            url,
            method,
            dataType: 'json',
            contentType: payload ? 'application/json' : undefined,
            data: payload ? JSON.stringify(payload) : undefined,
            success(res) {
                if (res && res.code === 0) resolve(res.data || {});
                else reject(new Error((res && res.msg) || '请求失败'));
            },
            error(xhr) {
                reject(new Error('网络错误 (' + (xhr && xhr.status) + ')'));
            },
        });
    });
}

function api(path, payload) {
    return request('POST', API + path, payload);
}

function apiGet(path, query) {
    const qs = query ? '?' + new URLSearchParams(query).toString() : '';
    return request('GET', API + path + qs, null);
}

function debounce(fn, wait) {
    let t;
    return function (...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(null, args), wait);
    };
}

// ============================================================
// 布局数据
// ============================================================

function normalizeLayout(layout) {
    const defaults = {
        settings: { columns: 12, cellHeight: 70, margin: 12 },
        panels: [],
    };
    if (!layout || typeof layout !== 'object') return defaults;
    const settings = Object.assign({}, defaults.settings, layout.settings || {});
    const panels = Array.isArray(layout.panels) ? layout.panels : [];
    return { settings, panels };
}

function findPanel(uidKey) {
    return state.layout.panels.find(p => p.uid === uidKey);
}

// 让已在布局中的面板从 catalog 获得默认元数据（图标/标题等渲染依赖）
function syncCatalogToPanels() {
    state.layout.panels.forEach((p) => {
        const meta = state.metaByKey.get(p.widgetKey);
        if (!meta) return;
        p.title = meta.title;
        p.icon = meta.icon;
        p.category = meta.category;
    });
}

function maxBottomRow() {
    let row = 0;
    state.layout.panels.forEach((p) => {
        if (!p.hidden) row = Math.max(row, (p.y || 0) + (p.h || 0));
    });
    const nodes = state.grid ? state.grid.engine.nodes : [];
    nodes.forEach((n) => { row = Math.max(row, n.y + n.h); });
    return row;
}

// 汇总当前布局（含隐藏面板）
function collectLayout() {
    if (!state.grid) return state.layout;
    const nodes = state.grid.engine.nodes || [];
    state.layout.panels.forEach((p) => {
        if (p.hidden) return;
        const el = findElByUid(p.uid);
        if (!el) return;
        const n = nodes.find(x => String(x.id) === p.uid || x.el === el[0]);
        if (n) {
            p.x = n.x;
            p.y = n.y;
            p.w = n.w;
            p.h = n.h;
        }
    });
    return state.layout;
}

function scheduleSave(immediate = false) {
    if (state.suppressSave) return;
    clearTimeout(state.saveTimer);
    const doSave = () => {
        state.saveTimer = null;
        api('saveLayout', { layout: collectLayout() })
            .then(() => { /* 静默成功，避免频繁 toast 干扰 */ })
            .catch((e) => toast('布局保存失败：' + e.message, 'error'));
    };
    if (immediate) { doSave(); return; }
    state.saveTimer = setTimeout(doSave, 800);
}

// ============================================================
// 面板挂载 / 移除 / 数据装载
// ============================================================

function mountAllPanels() {
    state.layout.panels
        .filter(p => !p.hidden)
        .forEach(p => mountPanel(p));
}

function mountPanel(panel) {
    const meta = state.metaByKey.get(panel.widgetKey);
    if (!meta) return; // 已卸载插件的面板不渲染

    const title = panel.title || meta.title;
    const icon = panel.icon || meta.icon || { type: 'bi', value: 'bi bi-window', color: '' };

    const el = $(
        `<div class="grid-stack-item" data-uid="${escapeHtml(panel.uid)}" data-key="${escapeHtml(panel.widgetKey)}">
            <div class="grid-stack-item-content panel-shell" data-accent="${escapeHtml(panel.accent || '')}">
                <div class="panel-header">
                    <span class="panel-title">${iconTag(icon)}<span class="panel-title-text">${escapeHtml(title)}</span></span>
                    <span class="panel-actions">
                        <button type="button" data-act="refresh" title="刷新"><i class="bi bi-arrow-clockwise"></i></button>
                        <button type="button" data-act="palette" title="主题色"><i class="bi bi-palette"></i></button>
                        <button type="button" data-act="config" title="配置"><i class="bi bi-gear"></i></button>
                        <button type="button" data-act="remove" title="移除"><i class="bi bi-x-lg"></i></button>
                    </span>
                </div>
                <div class="panel-body">${panelLoadingHtml()}</div>
            </div>
        </div>`
    );

    // GridStack v13：addWidget() 只接受纯对象 GridStackWidget；已有 DOM 元素
    // 需先放入 grid 容器，再调用 makeWidget(el, opts) 由库接管（等价 v9 addWidget(el, opts)）。
    const node = el[0];
    if (node.parentElement !== state.grid.el) state.grid.el.appendChild(node);
    state.grid.makeWidget(node, {
        x: panel.x, y: panel.y,
        w: panel.w || meta.size.w || 4,
        h: panel.h || meta.size.h || 3,
        id: panel.uid,
        minW: meta.min ? meta.min.w : 1,
        minH: meta.min ? meta.min.h : 1,
    });

    state.uidMap.set(panel.uid, panel);
    wirePanelActions(el, panel, meta);
    loadPanelData(panel, meta, el.find('.panel-body'), false);
}

function findElByUid(uidKey) {
    const safe = (window.CSS && typeof CSS.escape === 'function')
        ? CSS.escape(String(uidKey))
        : String(uidKey).replace(/["\\]/g, '');
    return $(`#panel-grid .grid-stack-item[data-uid="${safe}"]`);
}

function removePanel(panel, triggerSave = true) {
    const el = findElByUid(panel.uid);
    const timer = state.timers.get(panel.uid);
    if (timer) { clearInterval(timer); state.timers.delete(panel.uid); }
    // v13 removeWidget(el, removeDOM, triggerEvent) 签名不变：传入原生 DOM 节点
    if (el && el.length && state.grid) state.grid.removeWidget(el[0], true, false);
    state.uidMap.delete(panel.uid);
    panel.hidden = true;
    if (triggerSave) scheduleSave();
}

function deletePanelPermanently(panel) {
    if (!panel.hidden) removePanel(panel, false);
    const idx = state.layout.panels.indexOf(panel);
    if (idx !== -1) state.layout.panels.splice(idx, 1);
    scheduleSave();
    updateEmptyState();
}

function wirePanelActions(el, panel, meta) {
    el.find('[data-act="refresh"]').on('click', (e) => {
        e.stopPropagation();
        loadPanelData(panel, meta, el.find('.panel-body'), true);
    });
    el.find('[data-act="palette"]').on('click', (e) => {
        e.stopPropagation();
        cycleAccent(el, panel);
    });
    el.find('[data-act="config"]').on('click', (e) => {
        e.stopPropagation();
        openConfigModal(panel, meta);
    });
    el.find('[data-act="remove"]').on('click', (e) => {
        e.stopPropagation();
        removePanel(panel, true);
        updateEmptyState();
        toast('面板已移除，可在“添加面板”中恢复', 'success');
    });
}

function cycleAccent(el, panel) {
    const idx = ACCENT_CYCLE.indexOf(panel.accent || '');
    panel.accent = ACCENT_CYCLE[(idx + 1) % ACCENT_CYCLE.length];
    el.find('.panel-shell').attr('data-accent', panel.accent || '');
    scheduleSave();
}

function panelLoadingHtml() {
    return '<div class="panel-loading"><div class="spinner-border spinner-border-sm" role="status"></div></div>';
}

function setPanelBody(body, html) {
    body.html(html);
}

// ============================================================
// 数据装载 + 分类渲染
// ============================================================

async function loadPanelData(panel, meta, body, showLoading = true) {
    if (showLoading) setPanelBody(body, panelLoadingHtml());

    // 面板 props
    const props = Object.assign({}, meta.defaultConfig || {}, panel.props || {});
    const query = { key: panel.widgetKey };
    const propsJson = JSON.stringify(props);
    if (propsJson !== '{}') query.props = propsJson;

    try {
        const data = await apiGet('widgetData', query);
        const out = renderByCategory(panel, meta, data);
        setPanelBody(body, out);
        body[0].dataset.state = 'ok';
    } catch (err) {
        setPanelBody(body, `<div class="panel-error"><i class="bi bi-exclamation-triangle me-1"></i>${escapeHtml(err.message)}</div>`);
        body[0].dataset.state = 'error';
    }

    // 轮询：首次装载后按面板 refresh 周期重载
    const existing = state.timers.get(panel.uid);
    if (existing) { clearInterval(existing); state.timers.delete(panel.uid); }
    const interval = Number(panel.refresh) || 0;
    if (interval >= 30) {
        state.timers.set(panel.uid, setInterval(() => loadPanelData(panel, meta, body, false), interval * 1000));
    }
}

function renderByCategory(panel, meta, data) {
    const category = meta.category || 'html';
    switch (category) {
        case 'stat':    return renderStat(panel, data);
        case 'entries': return renderEntries(data);
        case 'list':    return renderList(panel, data);
        case 'html':    return renderHtml(panel, data);
        default:        return renderHtml(panel, data);
    }
}

// stat：{ cpu?, memory?, disk?, ... } 数值指标
function renderStat(panel, data) {
    if (!data || typeof data !== 'object') return panelEmptyHtml('暂无数据');
    const labelMap = { cpu: 'CPU 负载', memory: '内存', disk: '磁盘', network: '网络' };
    const unitMap = { cpu: '', memory: '%', disk: '%', network: '' };
    const names = Object.keys(data).filter(k => k !== 'demo');
    if (!names.length) return panelEmptyHtml('暂无指标');

    const rows = names.slice(0, 6).map((k) => {
        const raw = data[k];
        const show = raw == null || raw === '' || isNaN(Number(raw)) ? '--' : String(raw);
        const label = labelMap[k] || k;
        const unit = unitMap[k] || '';
        return `<div class="dash-stat">
            <div class="dash-stat-value">${escapeHtml(show)}${unit ? `<span class="unit">${escapeHtml(unit)}</span>` : ''}</div>
            <div class="dash-stat-label">${escapeHtml(label)}</div>
        </div>`;
    }).join('');
    return `<div class="dash-stats">${rows}</div>`;
}

// entries：{ groups: [{ label, items: [{ title,url,icon }] }] }
function renderEntries(data) {
    const groups = data && Array.isArray(data.groups) ? data.groups : [];
    if (!groups.length) return panelEmptyHtml('暂无可用的快捷入口');

    const html = groups.map((g) => {
        const items = (g.items || []).map((it) => {
            const url = it.url || '';
            // data-ident：插件条目标识，供主框架按“启动台打开插件”等价路径处理（含临时插件 Tab）
            return `<a class="entry-item" href="#" data-url="${escapeHtml(url)}"
                data-ident="${escapeHtml(it.ident || '')}" title="${escapeHtml(it.title || '')}">
                ${entryIconTag(it.icon)}
                <span class="entry-name">${escapeHtml(it.title || '')}</span>
            </a>`;
        }).join('');
        return `<div class="entry-group">
            <div class="entry-group-label">${escapeHtml(g.label || g.group || '')}</div>
            <div class="entry-items">${items}</div>
        </div>`;
    }).join('');

    return `<div class="entry-groups">${html}</div>`;
}

// list：{ items: [{...}] }，通用字段渲染
function renderList(panel, data) {
    const items = data && Array.isArray(data.items) ? data.items : [];
    const head = [];
    if (data && typeof data.unread === 'number') {
        head.push(`<span>未读 <span class="badge text-bg-danger">${data.unread}</span></span>`);
    }
    if (!items.length) return (head.length ? `<div class="dash-list"><div class="dash-list-head">${head.join('')}</div></div>` : '') + panelEmptyHtml('暂无记录');

    const rows = items.slice(0, 12).map((it) => {
        const title = it.title || [it.location, it.browser, it.os].filter(Boolean).join(' · ') || '记录';
        const dot = it.read === false || it.unread ? '<span class="dot-unread"></span>' : '';
        const metaParts = [it.createAt || it.time, it.ip && `IP ${it.ip}`, it.location].filter(Boolean);
        const meta = metaParts.join(' · ');
        return `<div class="dash-list-item">
            <div class="dash-list-item-title">${dot}${escapeHtml(title)}</div>
            ${meta ? `<div class="dash-list-item-meta">${escapeHtml(meta)}</div>` : ''}
        </div>`;
    }).join('');

    const headHtml = head.length ? `<div class="dash-list-head">${head.join('')}</div>` : '';
    return `<div class="dash-list">${headHtml}${rows}</div>`;
}

// html / welcome：服务端结构化文本
function renderHtml(panel, data) {
    if (!data || typeof data !== 'object') return panelEmptyHtml('暂无数据');
    if (panel.widgetKey === 'system.welcome') {
        return `<div class="dash-welcome">
            <div class="dash-welcome-name">你好，${escapeHtml(data.nickname || '管理员')}</div>
            <div class="dash-welcome-time">${escapeHtml(data.time || '')}</div>
            <div class="dash-welcome-meta">${escapeHtml(data.date || '')} · ${escapeHtml(data.week || '')}</div>
        </div>`;
    }
    // 通用富文本面板
    const body = data.content || data.html;
    if (body != null) return `<div>${body}</div>`;
    const kv = Object.entries(data).map(([k, v]) =>
        `<div><strong>${escapeHtml(k)}</strong>：${escapeHtml(String(v == null ? '' : v))}</div>`).join('');
    return kv || panelEmptyHtml('暂无数据');
}

function panelEmptyHtml(msg) {
    return `<div class="panel-empty">${escapeHtml(msg)}</div>`;
}

function renderFatal(msg) {
    const grid = document.getElementById('panel-grid');
    const html = `<div class="panel-error"><i class="bi bi-exclamation-triangle me-1"></i>${escapeHtml(msg)}</div>`;
    if (grid) grid.innerHTML = html;
}

// ============================================================
// 图标渲染（bi / image / emoji）
// ============================================================

function iconTag(icon) {
    icon = icon || {};
    const type = icon.type || 'bi';
    const value = icon.value || 'bi bi-window';
    const color = icon.color || '';
    const style = color ? ` style="color:${escapeHtml(color)}"` : '';
    if (type === 'image') {
        return `<i class="bi bi-image panel-img-icon" style="background-image:url('${escapeHtml(urlSafe(value))}');${color ? `background-color:${escapeHtml(color)};` : ''}"></i>`;
    }
    if (type === 'emoji') return `<span class="panel-emoji"${style}>${escapeHtml(value)}</span>`;
    return `<i class="${escapeHtml(/^bi\s/i.test(value) ? value : 'bi bi-window')}"${style}></i>`;
}

function entryIconTag(icon) {
    icon = icon || {};
    const type = icon.type || 'bi';
    const value = icon.value || 'bi bi-box-seam';
    const color = icon.color || '';
    const style = color ? ` style="background-color:${escapeHtml(color)}"` : '';
    if (type === 'image') {
        return `<span class="entry-icon" style="background-image:url('${escapeHtml(urlSafe(value))}');${color ? `background-color:${escapeHtml(color)};` : ''}"></span>`;
    }
    if (type === 'emoji') {
        return `<span class="entry-icon"${style}><span class="entry-emoji">${escapeHtml(value)}</span></span>`;
    }
    return `<span class="entry-icon"${style}><i class="${escapeHtml(/^bi\s/i.test(value) ? value : 'bi bi-box-seam')}"></i></span>`;
}

function urlSafe(url) {
    return String(url || '').replace(/["\\]/g, (m) => (m === '"' ? '%22' : '\\\\'));
}

// ============================================================
// GridStack 事件
// ============================================================

function bindGridEvents() {
    // 分别注册 GridStack 事件（兼容不同版本的事件名解析）
    ['change', 'added', 'removed', 'resizestop', 'dragstop'].forEach((ev) => {
        try {
            state.grid.on(ev, () => scheduleSave());
        } catch (_) { /* 事件名不支持的旧版本忽略 */ }
    });
}

// ============================================================
// 工具条
// ============================================================

function bindToolbarEvents() {
    $('#dashboard-add-btn').on('click', openAddModal);
    $('#dashboard-reset-btn').on('click', () => {
        confirmBox('确定要恢复为默认布局吗？当前自定义面板将被移除。', () => {
            api('resetLayout').then(() => { window.location.reload(); })
                .catch(e => toast('重置失败：' + e.message, 'error'));
        });
    });
}

function updateEmptyState() {
    const activeCount = state.layout.panels.filter(p => !p.hidden).length;
    $('#dashboard-empty').toggleClass('d-none', activeCount !== 0);
}

// ============================================================
// 添加 / 恢复面板
// ============================================================

function groupCatalog() {
    const groups = new Map();
    state.catalog.forEach((meta) => {
        const key = meta.source;
        const label = SOURCE_LABELS[key] || (key === 'system' ? '系统面板' : `插件 · ${key}`);
        if (!groups.has(key)) groups.set(key, { label, metas: [] });
        groups.get(key).metas.push(meta);
    });
    return [...groups.values()];
}

function visibleKeys() {
    const set = new Set();
    state.layout.panels.forEach((p) => { if (!p.hidden) set.add(p.widgetKey); });
    return set;
}

function openAddModal() {
    const $box = $('#dashboard-add-modal');
    const $catalog = $box.find('.dashboard-catalog');
    $catalog.empty();

    // 已移除（可恢复）区
    const hiddenPanels = state.layout.panels.filter(p => p.hidden);
    if (hiddenPanels.length) {
        const rows = hiddenPanels.map((p) => {
            const meta = state.metaByKey.get(p.widgetKey);
            const title = (meta && meta.title) || p.widgetKey;
            return `<div class="d-flex justify-content-between align-items-center border-bottom py-1">
                <span class="small">${escapeHtml(title)}</span>
                <span>
                    <button class="btn btn-sm btn-outline-primary" data-restore="${escapeHtml(p.uid)}">恢复</button>
                    <button class="btn btn-sm btn-outline-secondary text-danger" data-delete="${escapeHtml(p.uid)}">删除记录</button>
                </span>
            </div>`;
        }).join('');
        $catalog.append(`<div class="catalog-group"><div class="catalog-group-title">已移除（可恢复）</div>${rows}</div>`);
    }

    // 可用面板目录（去重：同 key 已在可见面板中则不再出现）
    const visible = visibleKeys();
    const groups = groupCatalog();
    if (!groups.length && !hiddenPanels.length) {
        $catalog.append('<div class="catalog-empty">暂无可添加的面板</div>');
    }

    groups.forEach((g) => {
        const metas = g.metas.filter(m => !visible.has(m.key));
        if (!metas.length) return;
        const tiles = metas.map((m) => {
            const size = m.size || {};
            return `<div class="catalog-item" data-key="${escapeHtml(m.key)}">
                <span class="catalog-item-icon">${iconTag(m.icon)}</span>
                <span class="catalog-item-body">
                    <span class="catalog-item-title">${escapeHtml(m.title)}</span>
                    <span class="catalog-item-desc">${escapeHtml(m.description || '')}</span>
                    <span class="catalog-item-size">默认尺寸 ${size.w || '-'} × ${size.h || '-'}</span>
                </span>
            </div>`;
        }).join('');
        $catalog.append(`<div class="catalog-group"><div class="catalog-group-title">${escapeHtml(g.label)}</div><div class="catalog-grid">${tiles}</div></div>`);
    });

    // 事件：恢复 / 删除 / 新增
    $catalog.find('[data-restore]').on('click', function () {
        const p = findPanel($(this).data('restore'));
        const meta = p ? state.metaByKey.get(p.widgetKey) : null;
        if (p && meta) {
            p.hidden = false;
            state.suppressSave = true;
            mountPanel(p);
            state.suppressSave = false;
            updateEmptyState();
            scheduleSave();
        } else if (p) {
            toast('该面板依赖已卸载，无法恢复', 'error');
        }
        $(this).closest('.d-flex').remove();
        if (!$catalog.find('.catalog-group').length) $catalog.append('<div class="catalog-empty">暂无可添加的面板</div>');
    });
    $catalog.find('[data-delete]').on('click', function () {
        const p = findPanel($(this).data('delete'));
        if (p) deletePanelPermanently(p);
        $(this).closest('.d-flex').remove();
    });
    $catalog.find('.catalog-item').on('click', function () {
        const key = $(this).data('key');
        addPanelFromCatalog(key);
    });

    wsModal($box, 'show');
}

function addPanelFromCatalog(key) {
    const meta = state.metaByKey.get(key);
    if (!meta) { toast('面板不存在或已无权限', 'error'); return; }
    if (visibleKeys().has(key)) { toast('该面板已在面板区中', 'error'); return; }
    if (state.layout.panels.filter(p => !p.hidden).length >= MAX_PANELS) {
        toast('面板数量已达上限', 'error');
        return;
    }

    // 同 key 存在已移除记录 → 直接恢复，避免同 key 多实例
    const hiddenOne = state.layout.panels.find(p => p.hidden && p.widgetKey === key);
    if (hiddenOne) {
        hiddenOne.hidden = false;
        state.suppressSave = true;
        mountPanel(hiddenOne);
        state.suppressSave = false;
        scheduleSave();
        wsModal('#dashboard-add-modal', 'hide');
        toast('面板已恢复', 'success');
        return;
    }

    const size = meta.size || { w: 4, h: 3 };
    const panel = {
        uid: uid(),
        widgetKey: key,
        x: 0,
        y: maxBottomRow(),
        w: size.w,
        h: size.h,
        accent: meta.accent || '',
        props: Object.assign({}, meta.defaultConfig || {}),
        refresh: 0,
        hidden: false,
        title: meta.title,
        icon: meta.icon,
        category: meta.category,
    };
    state.layout.panels.push(panel);
    state.uidMap.set(panel.uid, panel);

    // 先让 GridStack 给出放置位置
    state.suppressSave = true;
    mountPanel(panel);
    state.suppressSave = false;
    scheduleSave();

    // 关闭抽屉并刷新可见项
    wsModal('#dashboard-add-modal', 'hide');
    toast('面板已添加', 'success');
}

// ============================================================
// 面板配置（props 表单）
// ============================================================

let configContext = null; // { panel, meta }

function openConfigModal(panel, meta) {
    const schema = meta.propsSchema || {};
    const keys = Object.keys(schema);
    if (!keys.length) { toast('该面板无可配置项', 'info'); return; }
    configContext = { panel, meta };

    const values = Object.assign({}, meta.defaultConfig || {}, panel.props || {});
    const form = keys.map((k) => fieldHtml(k, schema[k], values[k])).join('');
    $('#dashboard-config-form').html(form);
    wsModal('#dashboard-config-modal', 'show');
}

function fieldHtml(name, field, value) {
    field = field || {};
    const type = field.type || 'string';
    const title = field.title || name;
    const tips = field.tips || field.description || '';
    const label = `<label class="form-label small mb-1">${escapeHtml(title)}</label>`;
    const tipsHtml = tips ? `<div class="form-text small">${escapeHtml(tips)}</div>` : '';
    const required = field.required ? ' required' : '';

    let input = '';
    switch (type) {
        case 'number': {
            input = `<input type="number" class="form-control form-control-sm${required}" name="${escapeHtml(name)}" value="${escapeHtml(value == null ? '' : value)}">`;
            break;
        }
        case 'switch':
        case 'boolean': {
            input = `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="${escapeHtml(name)}" ${value ? 'checked' : ''}></div>`;
            break;
        }
        case 'select': {
            const options = Object.entries(field.options || {})
                .map(([k, v]) => `<option value="${escapeHtml(k)}" ${String(k) === String(value) ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('');
            input = `<select class="form-select form-select-sm${required}" name="${escapeHtml(name)}">${options}</select>`;
            break;
        }
        case 'textarea':
        case 'text': {
            input = `<textarea class="form-control form-control-sm${required}" name="${escapeHtml(name)}" rows="3">${escapeHtml(value || '')}</textarea>`;
            break;
        }
        default: {
            input = `<input type="text" class="form-control form-control-sm${required}" name="${escapeHtml(name)}" value="${escapeHtml(value || '')}">`;
        }
    }
    return `<div class="mb-3">${label}${input}${tipsHtml}</div>`;
}

function bindConfigModalEvents() {
    $('#dashboard-config-save').on('click', function () {
        if (!configContext) return;
        const { panel, meta } = configContext;
        const schema = meta.propsSchema || {};
        const fd = $('#dashboard-config-form');
        const data = {};
        Object.keys(schema).forEach((name) => {
            const type = schema[name].type || 'string';
            const $el = fd.find(`[name="${name}"]`);
            if (type === 'number') data[name] = $el.length ? Number($el.val()) : 0;
            else if (type === 'switch' || type === 'boolean') data[name] = $el.is(':checked');
            else data[name] = $el.length ? $el.val() : '';
        });
        panel.props = Object.assign({}, meta.defaultConfig || {}, data);
        wsModal('#dashboard-config-modal', 'hide');

        const body = findElByUid(panel.uid).find('.panel-body');
        loadPanelData(panel, meta, body, true);
        scheduleSave();
        toast('配置已保存', 'success');
    });
}

// ============================================================
// 打开入口（iframe 内 → 主框架 Tab，尽量等价于点击侧边栏菜单）
// ============================================================

/**
 * 打开快捷入口页面
 *
 * 统一委托给主框架的 `Wise.nav.open()`（方案 A 的单一实现，见 modules/wise-navmenu.js）：
 *  · 命中侧边栏菜单 → 切所属一级菜单 + 展开高亮叶子 + 新建/复用顶部 tab，
 *    并**保留 url 上的查询参数**（本面板入口没有 query，但这是全站统一的公共口径）；
 *  · 未命中（非菜单地址）→ 退化为普通 tab，标题/图标用条目自带的，避免变“无名标签”。
 *
 * 历史实现是自己去 `window.top.document` 里翻 `.sidebar-menu a[data-url]` 再 `.click()`：
 * 既耦合主框架 DOM 结构，又会因叶子 `data-url` 不含 query 而丢掉筛选态（下钻链接失真）。
 *
 * @param {string} url
 * @param {string} [title]
 * @param {string} [icon] CSS 图标类名（如 'bi bi-grid'）
 */
function openEntryUrl(url, title, icon) {
    if (!url) return;

    let top;
    try { top = window.top; } catch (_) { top = window; }

    // 1) 主框架导航 API：菜单与标签页的唯一持有者（同源直取）
    const nav = (top !== window && top.Wise && top.Wise.nav)
        ? top.Wise.nav
        : ((window.Wise && window.Wise.nav) || null);
    if (nav) {
        // open() 命中菜单走菜单链；未命中时它已自行退化为普通标签 —— 两种情况都无需再兜底
        nav.open(url, { title: title || '', icon: icon || '' });
        return;
    }

    // 2) 兜底：主框架未加载导航模块（旧 bundle / 跨域）→ 直接开/复用顶部 tab
    const framework = (top && top.Framework && top.Framework.api && typeof top.Framework.api.openInFrame === 'function')
        ? top.Framework
        : null;
    if (framework) {
        framework.api.openInFrame(url, title || '', icon || '');
    } else if (url.startsWith('/') || /^https?:\/\//.test(url)) {
        window.location.href = url;
    }
}

// 事件委托：entries 内条目点击
$(document).on('click', '#panel-grid .entry-item', function (e) {
    e.preventDefault();
    const $item = $(this);
    const url = $item.data('url');
    if (!url) return;
    const title = $item.attr('title') || $item.find('.entry-name').text().trim() || '';
    const icon = ($item.find('.entry-icon i.bi').attr('class') || '').trim();
    openEntryUrl(url, title, icon);
});

// ============================================================
// 清理
// ============================================================

function cleanupAll() {
    state.timers.forEach((t) => clearInterval(t));
    state.timers.clear();
    if (state.saveTimer) {
        clearTimeout(state.saveTimer);
        state.saveTimer = null;
        try { api('saveLayout', { layout: collectLayout() }); } catch (_) { /* 页面卸载阶段尽力保存 */ }
    }
}

export default Controller;
