/**
 * Wise.nav — 主框架菜单/侧边栏/标签页导航 API（纯 ESM）
 *
 * 这个模块收敛三件事，供**主框架本身**与**iframe 子页面**共用：
 *   1. 顶部一级菜单切换 + 侧边栏显隐（switchTop）
 *   2. 侧边栏叶子定位/高亮/展开（matchLeaf / highlight）
 *   3. 打开标签页（openTab / openLeaf / openInSidebar / open / openFrame）
 *
 * 为什么要有这个模块
 * ------------------
 * 上述逻辑原先是 `statics/admin/index.js` 里 `Controller.index()` 的**闭包**函数，
 * 只有主框架首页自己能调用；iframe 子页面（业务页）想「打开某个菜单」只能自己
 * 去 `window.top.document` 里翻 `.sidebar-menu a[data-url]` 再 `.click()`（见
 * `statics/admin/dashboard.js` 的历史实现），既耦合主框架 DOM 又各自为政。
 * 现在实现下沉到这里，页面侧只声明意图（`data-wise-nav`，见下）。
 *
 * ── 页面内超链接「打开/切换到菜单」协议 ──────────────────────────────
 * 在任意页面（含 iframe 业务页）写：
 * ```html
 * <a href="/plugin/wisecms/content/index?status=2" data-wise-nav>已发布</a>
 * <a href="/admin/system_message/index" data-wise-nav="tab">消息中心</a>   <!-- 只开标签，不切菜单 -->
 * ```
 * 由 `wise-framework.js` 的文档级委托调用本模块 {@link open}：
 *   · 命中菜单叶子 → 切到所属一级菜单 + 展开高亮叶子 + 新建/复用标签（**保留 query**）；
 *   · 未命中       → 退化为框架内普通标签（标题/图标优先取调用方，其次 tab meta）；
 *   · 无 `Wise.nav`（前台页 / 跨域宿主）→ 不拦截，浏览器原生跳转。
 * `href` 仍写真实地址，所以 Ctrl/中键、复制链接、无 JS 环境全部照常。
 *
 * ⚠️ 与历史 iframe hack 的关键差异：命中叶子后**不再 `$leaf.click()`**。
 * 点击处理器取的是叶子的 `data-url`（不含 query），会把 `?status=2` 这类筛选态丢掉。
 * 这里改为「叶子规范地址 + 来源 query」直接开标签，并把高亮/展开/持久化一次做全。
 *
 * @module wise-navmenu
 * @version 1.0.0
 * @see /statics/admin/index.js          主框架首页（薄封装调用本模块）
 * @see /statics/modules/wise-framework.js  `a[data-wise-nav]` 委托
 */

const $ = window.jQuery;

/** 临时插件 Tab 的唯一 parentId（未固定的多顶级插件，启动台左侧，始终只保留一个） */
const TEMP_PLUGIN_PARENT = 'temp-plugin';

/** 侧边栏/一级菜单选中态持久化 key（与历史实现同 key，刷新语义不变） */
const ACTIVE_KEY = 'wise.nav.active';

// ============================================================
// URL 归一化（与侧边栏 data-url 约定对齐）
// ============================================================

/**
 * 归一化 URL：去 hash 片段、去 `.html` 后缀（保留 query）
 *
 * 侧边栏 `data-url` 一律是「无后缀、无 query」的规范地址；页面里的链接则可能带
 * `?status=2` 或历史遗留的 `.html`，两者必须能匹配上。
 */
function normalizeUrl(url) {
    let u = String(url == null ? '' : url).trim();
    if (!u) return '';
    const hash = u.indexOf('#');
    if (hash >= 0) u = u.slice(0, hash);
    return u.replace(/\.html(?=$|\?)/, '');
}

/** 取归一化后的路径部分（去尾斜杠） */
function pathOf(url) {
    return normalizeUrl(url).split('?')[0].replace(/\/+$/, '');
}

/** 取归一化后的 query 部分（带前导 `?`，无则空串） */
function queryOf(url) {
    const q = normalizeUrl(url).split('?')[1];
    return q ? '?' + q : '';
}

/** 侧边栏叶子的图标类名（模板里带 `nav-icon` 前缀，取出来供标签页使用） */
function leafIcon($leaf) {
    const cls = ($leaf.find('i').attr('class') || '').trim();
    return cls.replace(/\bnav-icon\b\s*/, '');
}

// ============================================================
// 选中态持久化（localStorage）
// ============================================================

/** 读上次的一级菜单 id + 叶子 url；无记录返回 null */
function getActive() {
    try {
        return JSON.parse(localStorage.getItem(ACTIVE_KEY) || 'null');
    } catch (_) {
        return null;
    }
}

/** 写选中态（url 传 null 表示只记一级菜单） */
function setActive(parentId, url) {
    try {
        localStorage.setItem(ACTIVE_KEY, JSON.stringify({ parentId: parentId, url: url == null ? null : url }));
    } catch (_) { /* 隐私模式等写入失败：忽略 */ }
}

// ============================================================
// 标签页 / 主框架宿主
// ============================================================

/** 是否已无任何打开的标签页（用于「自动打开第一个页面」与「刷新回到上次位置」） */
function hasNoOpenTab() {
    const nt = window.navtab;
    if (nt && nt.tabs && typeof nt.tabs.size === 'number') return nt.tabs.size === 0;
    return $('#dynamic-tabs li[role=context]').length === 0;
}

/**
 * 取「真正持有标签页/菜单」的那份 Framework
 *
 * · iframe 内 → `window.top` 的（标签与菜单都在主框架文档里，同源直取）；
 * · 主框架内 → 自己那份；
 * · 跨域宿主 → null（调用方降级为原生跳转）。
 */
function hostFramework() {
    try {
        if (window.self !== window.top && window.top.Framework) return window.top.Framework;
    } catch (_) { /* 跨域：不可达，走下面的自身分支 */ }
    return window.Framework || null;
}

/** 读某 url 的标签元信息（标题/图标持久化，供刷新后恢复无名标签） */
function getTabMeta(url) {
    const fw = hostFramework();
    if (fw && fw.api && typeof fw.api.getTabMeta === 'function') return fw.api.getTabMeta(normalizeUrl(url));
    return null;
}

/**
 * 无刷新改写主框架 hash
 *
 * hash 只属于主框架文档：iframe 内改自己的地址没意义，还会污染子页面 hash，
 * 故此处直接跳过。
 */
function setHash(id) {
    if (window.self !== window.top) return;
    const hash = '#' + String(id || '').replace(/^#/, '');
    if (history.replaceState) {
        history.replaceState(null, null, hash);
    } else {
        window.location.hash = hash;
    }
}

/**
 * 打开/复用标签页（不做菜单联动）
 *
 * @param {string} id    标签 id（= 页面 url，含 query）
 * @param {string} [title]
 * @param {string} [icon] CSS 图标类名
 * @return {string|null} 标签 id；标签模块不可用时整页跳转并返回 null
 */
function openTab(id, title, icon) {
    // 归一化：调用方可能直接给带 .html / hash 的原始地址
    id = normalizeUrl(id);
    if (!id) return null;

    const navtabs = window.navtab;
    if (!navtabs || !navtabs.api || typeof navtabs.api.addTab !== 'function') {
        // 降级：标签模块未就绪时整页跳转（不该发生，但比静默无反应好）
        window.location.href = id;
        return null;
    }

    navtabs.api.addTab({ id: id, title: title || '页面', icon: icon || '', url: id, content: '' });
    setHash(id);
    return id;
}

/**
 * 在框架内以普通标签打开 url（不切菜单、不高亮侧边栏）
 *
 * @return {boolean} 是否走了标签路径
 */
function openFrame(url, title, icon) {
    const fw = hostFramework();
    if (fw && fw.api && typeof fw.api.openInFrame === 'function') {
        fw.api.openInFrame(url, title || '', icon || '');
        setHash(url);
        return true;
    }
    window.location.href = url;
    return false;
}

// ============================================================
// 菜单定位 / 一级菜单 / 高亮
// ============================================================

/**
 * 在侧边栏中定位 url 对应的菜单叶子
 *
 * 匹配口径（**与 hash 恢复、tab 激活反查共用，只此一处**）：
 *   · 路径比对（去 query / 去 `.html` / 去尾斜杠）；
 *   · 候选：原路径 → 补 `/index`（历史书签与旧 hash 可能被截断成 `/admin/a/b`）。
 *
 * @param  {string} url
 * @return {jQuery} 命中返回叶子元素，未命中返回空集合
 */
function matchLeaf(url) {
    const path = pathOf(url);
    if (!path) return $();

    const candidates = [path];
    if (!/\/index$/.test(path)) candidates.push(path + '/index');

    const $leaves = $('.sidebar-menu a[data-url]');
    for (let i = 0; i < candidates.length; i++) {
        let $hit = $();
        $leaves.each(function () {
            if (pathOf(this.getAttribute('data-url')) === candidates[i]) {
                $hit = $(this);
                return false;
            }
        });
        if ($hit.length) return $hit;
    }
    return $();
}

/**
 * 切换顶部一级菜单：切顶部 active + 显隐对应侧边栏 + 记录 parentId（保留叶子 url）
 *
 * 临时插件 Tab 不持久化 —— 刷新后应回到真实菜单，而不是上次的临时状态。
 *
 * @param {string|number} parentId 顶部菜单项的 data-id（= 菜单根 id，或 'temp-plugin'）
 * @param {{autoOpenFirst?:boolean}} [opts] 无任何标签页时是否自动打开该菜单下第一个页面
 * @return {boolean} 是否命中顶部菜单项
 */
function switchTop(parentId, opts = {}) {
    const $target = $('.top-menu-item[data-id="' + parentId + '"]');
    if (!$target.length) return false;

    $('.top-menu-item').removeClass('active');
    $target.addClass('active');
    // 仅隐藏随顶部 Tab 切换的模块侧边栏；常驻分组（sidebar-persist）始终可见
    $('.sidebar-menu:not(.sidebar-persist)').addClass('d-none');
    $('.sidebar-menu:not(.sidebar-persist)').removeClass('active');
    const $sidebar = $('.sidebar-menu[data-parent="' + parentId + '"]').removeClass('d-none').addClass('active');

    const saved = getActive();
    if (parentId !== TEMP_PLUGIN_PARENT) {
        setActive(parentId, saved ? saved.url : null);
    }

    if (opts.autoOpenFirst && hasNoOpenTab()) {
        const $firstLeaf = $sidebar.find('a[data-url]').first();
        if ($firstLeaf.length) $firstLeaf.trigger('click');
    }
    return true;
}

/**
 * 高亮侧边栏叶子并展开所属分组
 *
 * @param {string|jQuery} input url 或叶子元素
 * @param {{markRoot?:boolean}} [opts] markRoot：同时标记一级菜单标题 active（点菜单时用）
 * @return {jQuery|null} 命中的叶子
 */
function highlight(input, opts = {}) {
    const $leaf = (input && input.jquery) ? input : matchLeaf(input);
    if (!$leaf || !$leaf.length) return null;

    $('.sidebar-menu a[data-url]').removeClass('active');
    $leaf.addClass('active');

    // 展开所属分组：除内联显示外，必须同步标记 li.nav-item.menu-open。
    // AdminLTE Treeview 的 toggle() 以 menu-open 判定开合（CSS：.sidebar-menu .menu-open > .nav-treeview{display:block}，
    // 箭头旋转也依赖该类）；只 $.show() 不标记会导致「视觉已展开、状态未展开」——
    // 再次点击走 open() 而非 close()，表现为点一次展开、第二次不折叠、第三次才折叠。
    const $tree = $leaf.closest('.nav-treeview');
    if ($tree.length) {
        $tree.show();
        $tree.closest('li.nav-item').addClass('menu-is-opening menu-open');
        $tree.siblings('a.nav-link').attr('aria-expanded', 'true');

        if (opts.markRoot) {
            $('.sidebar-menu>.nav-item>.nav-link').removeClass('active');
            $tree.siblings('a.nav-link').addClass('active');
            $tree.siblings('a.nav-link').closest('li.nav-item').addClass('menu-is-opening menu-open');
        }
    }
    return $leaf;
}

// ============================================================
// 打开菜单（核心）
// ============================================================

/**
 * 打开一个菜单叶子 —— 等价于「侧边栏点一次菜单」，且补全历史实现缺的能力
 *
 * 依次完成：切所属一级菜单 → 高亮展开叶子 → 持久化选中态 → 开/复用标签 → 更新 hash。
 *
 * @param {jQuery} $leaf
 * @param {{url?:string, source?:string, title?:string, icon?:string, markRoot?:boolean, persist?:boolean}} [opts]
 *        url    显式指定标签地址（默认 = 叶子规范地址 + source 的 query）
 *        source 触发来源地址（用于保留 `?status=2` 这类筛选态）
 * @return {string|null} 标签 id
 */
function openLeaf($leaf, opts = {}) {
    if (!$leaf || !$leaf.length) return null;

    const base = normalizeUrl($leaf.attr('data-url'));
    if (!base) return null;

    // 标签地址 = 叶子规范地址 + 来源 query：筛选态随标签保留（历史实现用 leaf.click() 会丢）
    const tabUrl = opts.url || (base + queryOf(opts.source || ''));
    const title  = opts.title || $leaf.attr('data-title') || $leaf.find('p').text().trim() || '页面';
    const icon   = opts.icon || leafIcon($leaf);
    const parentId = $leaf.closest('.sidebar-menu').data('parent');

    if (parentId != null) switchTop(parentId, { autoOpenFirst: false });

    highlight($leaf, { markRoot: opts.markRoot !== false });

    // 侧边栏选中态持久化（临时插件 Tab 不持久化）
    if (parentId != null && parentId !== TEMP_PLUGIN_PARENT && opts.persist !== false) {
        setActive(parentId, base);
    }

    return openTab(tabUrl, title, icon);
}

/**
 * 在指定一级菜单的侧边栏内打开 url（启动台入口点击用）
 *
 * 命中叶子 → 与点菜单完全一致；未命中 → 直接开标签（启动台首页等场景）。
 *
 * @param {string|number} parentId
 * @param {string} url
 * @param {{tabId?:string, title?:string, icon?:string}} [opts] 未命中时的标签兜底信息
 * @return {string|null} 标签 id
 */
function openInSidebar(parentId, url, opts = {}) {
    const $scoped = $('.sidebar-menu[data-parent="' + parentId + '"]');
    const path = pathOf(url);

    let $leaf = $();
    $scoped.find('a[data-url]').each(function () {
        if (pathOf(this.getAttribute('data-url')) === path) {
            $leaf = $(this);
            return false;
        }
        return undefined;
    });

    if ($leaf.length) {
        return openLeaf($leaf, { source: url, title: opts.title, icon: opts.icon });
    }
    return openTab(normalizeUrl(opts.tabId || url), opts.title, opts.icon);
}

/**
 * 方案 A 的核心入口：把「页面内的一个 URL」打开成对应的菜单/标签页
 *
 * @param {string} url 站内绝对路径（可带 query；非 `/` 开头一律不处理）
 * @param {{title?:string, icon?:string, route?:'auto'|'tab', fallback?:boolean}} [opts]
 *        route    'auto'（默认）命中菜单走菜单链；'tab' 强制只开标签
 *        fallback 未命中菜单时是否退化为普通标签（默认 true）
 * @return {boolean} 是否命中菜单（走了菜单链）
 */
function open(url, opts = {}) {
    const normalized = normalizeUrl(url);
    // 只接管站内绝对路径：外链、锚点、javascript: 一律交回浏览器
    if (!/^\//.test(normalized)) return false;

    if ((opts.route || 'auto') !== 'tab') {
        const $leaf = matchLeaf(normalized);
        if ($leaf.length) {
            openLeaf($leaf, {
                url: normalizeUrl($leaf.attr('data-url')) + queryOf(normalized),
                source: normalized,
                title: opts.title,
                icon: opts.icon,
                markRoot: true,
            });
            return true;
        }
    }

    if (opts.fallback === false) return false;

    // 未命中菜单（如详情页/编辑页）：退化为框架内普通标签，标题/图标优先取调用方，其次 tab meta
    const meta = getTabMeta(normalized) || {};
    openFrame(normalized, opts.title || meta.title || '', opts.icon || meta.icon || '');
    return false;
}

/**
 * 恢复上次的一级菜单 + 侧边栏选中态；标签页为空（全新会话）时重新打开上次页面
 *
 * ⚠️ 已有（loadSavedState 恢复出来的）标签时**什么都不做**：标签激活时的反查
 * （tab:activated → switchTop + highlight）已把一级菜单、侧边栏、临时插件入口切到
 * 当前页面所属；这里再切一次会把它覆盖回「上一次点过的菜单」，出现标签与侧边栏不匹配
 * —— 典型症状：未固定插件的页面被恢复后，临时 Tab 刚由反查显示出来又被切走。
 *
 * @return {object|null} 读到的选中态
 */
function restore() {
    const saved = getActive();
    if (!saved) return null;

    if (!hasNoOpenTab()) return saved;   // 有标签：以激活标签的反查结果为准

    if (saved.parentId != null) switchTop(saved.parentId, { autoOpenFirst: false });
    if (!saved.url) return saved;

    const $leaf = matchLeaf(saved.url);
    if (!$leaf.length) return saved;

    // 一个标签都没有（首开 / 非固定标签未被恢复）→ 真正打开上次页面
    // （click 会走 openLeaf：高亮 + 展开 + 持久化 + 开标签）
    $leaf.trigger('click');
    return saved;
}

const nav = {
    TEMP_PLUGIN_PARENT,
    KEY: ACTIVE_KEY,

    // URL 工具（供主框架/测试复用，避免各写一份归一化）
    normalize: normalizeUrl,
    pathOf,
    queryOf,

    // 状态
    getActive,
    setActive,
    hasNoOpenTab,

    // 菜单/侧边栏
    matchLeaf,
    switchTop,
    highlight,

    // 打开
    open,
    openLeaf,
    openInSidebar,
    openTab,
    openFrame,
    restore,
};

// 自挂全局：与 wise-framework.js 同一惯例（模块被独立引入时也可用；bootstrap 会覆盖为同一引用）
window.Wise = window.Wise || {};
window.Wise.nav = nav;

export { nav };
export default nav;
