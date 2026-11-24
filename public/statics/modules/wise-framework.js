/**
 * Wise.framework — 核心框架（纯 ESM）
 *
 * 替代 deprecated/framework.js，不再依赖 AMD/RequireJS。
 * 依赖的 hotkey / clipboard / broadcast / cookie 已 ESM 化并随 wise-bundle.js 打包
 * （源码在 statics/libs/<name>/<name>.js），模块内直接 import 或读 window 上的同名类。
 *
 * @module wise-framework
 * @version 2.0.0
 */

import { http } from './wise-util.js';
import { toast, loading } from './wise-ui.js';
import { config } from './wise-core.js';
import Cookie from '../libs/cookie/cookie.js';

const $ = window.jQuery;
const Layer = window.Layer;
const Bootstrap = window.bootstrap;
const NavTabs = window.navtab;
const Broadcast = window.Broadcast;
const HotKey = window.HotKey;

// ============================================================
// 初始化http
// ============================================================
http.setInterceptor({
    codeKey: 'code', successCode: 0,
    dataKey: 'data', msgKey: 'msg', unauthorizedCode: 401,
});
http.setErrorHandler((err) => toast.error(err.message));
// 全局请求指示器：顶部进度条（.ws-busy-bar），计数器式，不阻断页面操作。
// 历史实现是空函数 —— 导致 http 的 `loading: true` 与 ui.action.execute()
// 全都静默无效。指示器实现见 wise-ui.js 的 ui.loading。
http.setLoadingHandler({
    show: () => loading.show(),
    hide: () => loading.hide(),
});
http.setUnauthorizedHandler(() => {
    window.location.href = document.querySelector('meta[name="login-url"]')?.content || '/admin/login';
});

// ============================================================
// 页面头部占位符处理（UIS v1 · 见 docs/wiseadmin-后台页面统一方案.md §3.2）
// ============================================================
// `public/page_header.html` 里的 [icon]/[caption]/[desc]/[actions] 是 ThinkPHP
// `{include}` 的**编译期文本占位符**（不是模板变量），未传参时会原样残留。
// 这里负责：① 用 iframe tab 的标题/图标回填；② 直接访问时用浏览器标题兜底；
// ③ 清掉 desc/actions 的残留占位，避免页面上出现 "[caption]" 字面量。
// 标题占位：老页面经 index_header 传 "[caption]"；直接 include page_header 未传参则残留 "[title]"
const PAGE_TITLE_SENTINELS = ['[caption]', '[title]'];
const PAGE_ICON_SENTINEL = '[icon]';

/** 是否仍是占位标题 */
function isTitlePlaceholder(el) {
    if (!el) return false;
    return PAGE_TITLE_SENTINELS.indexOf(el.textContent.trim()) !== -1;
}

/** 写入页头标题/图标（仅当仍是占位或浏览器兜底值时才覆盖 —— 保证「传参优先」） */
function applyPageHeader(title, icon) {
    const titleEl = document.getElementById('index_header_title');
    if (titleEl && title) {
        const replaceable = isTitlePlaceholder(titleEl) || titleEl.dataset.wsFallback === '1';
        if (replaceable) {
            titleEl.textContent = title;
            delete titleEl.dataset.wsFallback;
        }
    }

    const iconEl = document.getElementById('index_header_icon');
    if (iconEl && icon) {
        // 已有 bi-* 图标（页面显式传参）则不动
        const hasIcon = /(^|\s)bi(\s|$)/.test(iconEl.className.replace(PAGE_ICON_SENTINEL, ''));
        if (!hasIcon) iconEl.className = ('ws-page-icon ' + icon).trim();
    }
}

/** 清理残留占位 + 标题兜底（直接访问页面时不能只剩一个空标题） */
function normalizePageHeader() {
    const iconEl = document.getElementById('index_header_icon');
    if (iconEl && iconEl.className.indexOf(PAGE_ICON_SENTINEL) !== -1) {
        iconEl.className = 'ws-page-icon';
    }

    document.querySelectorAll('.ws-page-desc').forEach((el) => {
        if (el.textContent.trim() === '[desc]') el.remove();
    });

    document.querySelectorAll('.ws-page-actions').forEach((el) => {
        const text = el.textContent.trim();
        if (!el.children.length && (text === '' || text === '[actions]' || text === '[buttongroup]')) {
            el.remove();
        }
    });

    // 标题：先等 iframe tab 的 tab:contentLoaded（正常几百毫秒内到达），
    // 超时仍为占位则用浏览器标题兜底（base.html 的格式：站点名 - 页面标题）。
    setTimeout(() => {
        const titleEl = document.getElementById('index_header_title');
        if (!titleEl || !isTitlePlaceholder(titleEl)) return;

        const full = (document.title || '').trim();
        const sep = full.indexOf(' - ');
        const guess = (sep >= 0 ? full.slice(sep + 3) : full).trim();

        titleEl.textContent = PAGE_TITLE_SENTINELS.indexOf(guess) !== -1 ? '' : guess;
        titleEl.dataset.wsFallback = '1';
    }, 1200);
}

// ── 兼容 `#ws-page-title` / `#ws-page-icon` 契约（UIS v1.1）────────────────
// 两套 id 约定并存：老片段用 index_header_*（上面两个函数），新片段/内联页头用
// ws-page-*（下面这些）。两套处理函数都只在元素存在时生效，互不干扰。
const PAGE_HEADER_PLACEHOLDERS = ['[title]', '[caption]', '[icon]', '[desc]', '[actions]', '[buttongroup]'];

/** 是否只是未替换的占位符（空字符串同样视为「没有」） */
function isPageHeaderPlaceholder(text) {
    const t = String(text == null ? '' : text).trim();
    return t === '' || PAGE_HEADER_PLACEHOLDERS.indexOf(t) > -1;
}

/** 图标类名清洗：去掉占位符与基础类，返回纯图标类数组 */
function pageHeaderIconClasses(el) {
    const raw = (el && el.getAttribute('class')) || '';
    return raw.split(/\s+/).filter((c) => c && c !== 'ws-page-icon' && !isPageHeaderPlaceholder(c));
}

/**
 * 初始化 ws-page-* 页头：占位符清理 + 标题兜底。
 *
 * 标题来源优先级（D5）：模板传参（已替换）→ data-title → <title> 的页面段。
 */
function initWsPageHeader() {
    const $title = $('#ws-page-title');
    if (!$title.length) return;

    const attrTitle = $title.attr('data-title');
    let title = isPageHeaderPlaceholder($title.text()) ? '' : String($title.text()).trim();

    if (!title && !isPageHeaderPlaceholder(attrTitle)) {
        title = String(attrTitle).trim();
    }
    if (!title) {
        // <title> 形如「站点名 - 页面标题」
        const parts = String(document.title || '').split(' - ');
        if (parts.length > 1) title = parts.slice(1).join(' - ').trim();
    }

    $title.text(title).attr('data-title', title);

    const iconEl = document.getElementById('ws-page-icon');
    if (iconEl) {
        const classes = pageHeaderIconClasses(iconEl);
        iconEl.setAttribute('class', classes.length ? 'ws-page-icon ' + classes.join(' ') : 'ws-page-icon');
    }

    // 描述 / 页面级操作区：只有占位符时置空，容器由 CSS :empty 隐藏（避免右侧留白挤窄标题）
    $('.ws-page-desc').each(function () {
        const $el = $(this);
        if ($el.children().length === 0 && isPageHeaderPlaceholder($el.text())) $el.empty();
    });
    $('.ws-page-actions').each(function () {
        const $el = $(this);
        if ($el.children().length === 0 && isPageHeaderPlaceholder($el.text())) $el.empty();
    });
}

/** iframe tab 元信息回填（ws-page-* 契约）：只填空值，页面显式传参时以模板为准 */
function applyWsPageHeaderMeta(title, icon) {
    const $title = $('#ws-page-title');
    if ($title.length && !isPageHeaderPlaceholder(title)
        && isPageHeaderPlaceholder($title.text())) {
        $title.text(String(title).trim());
    }

    const iconEl = document.getElementById('ws-page-icon');
    if (!iconEl || isPageHeaderPlaceholder(icon)) return;
    const classes = pageHeaderIconClasses(iconEl);
    // 页面已声明图标（含 bi 类）时不覆盖
    if (classes.indexOf('bi') > -1) return;
    iconEl.setAttribute('class', 'ws-page-icon ' + String(icon).trim());
}

// ============================================================
// Framework
// ============================================================
const Framework = {
    api: {
        // ---- 主题切换（已委托 Wise.theme，旧 'theme' key 仅做一次性迁移）----
        // 说明：主题状态统一由 wise-theme.js 管理（localStorage key: wise.theme，
        // Cookie: ws_theme，服务端 ext_fields 持久化）。此方法保留为兼容旧调用方
        // （admin/index.js、iframe 内 Framework.api.switchTheme()）的桩：
        //   1. 若本浏览器尚无 wise.theme，把旧 'theme' key 迁移过来；
        //   2. 不做任何覆盖，避免与 Wise.theme 的独立明暗/配色状态打架。
        switchTheme(theme = {}, sync = false, focus = false) {
            const W = window.Wise;
            if (W && W.theme && typeof W.theme.set === 'function') {
                try {
                    if (!localStorage.getItem(W.theme.STORAGE_KEY)) {
                        const legacy = JSON.parse(localStorage.getItem('theme') || 'null');
                        if (legacy && typeof legacy === 'object' && (legacy.theme || legacy.mode)) {
                            W.theme.set({
                                mode: legacy.theme || legacy.mode || 'dark',
                                scheme: legacy.scheme || 'blue',
                                sidebar: legacy.sidebar || 'auto',
                            }, { sync: false });
                        }
                    }
                } catch (e) { /* 迁移失败静默，不影响现有主题 */ }
                return;
            }

            // ── 降级：无 Wise.theme（如未挂载模块的独立页面）时保持旧逻辑 ──
            let storedTheme;
            try {
                storedTheme = JSON.parse(localStorage.getItem('theme') || '{"theme":"dark", "scheme":""}');
            } catch (e) {
                storedTheme = { theme: 'dark', scheme: '' };
            }

            const finalTheme = {
                theme: theme.theme || storedTheme.theme || 'dark',
                scheme: theme.scheme || storedTheme.scheme || '',
            };

            if (['light', 'dark', 'auto'].indexOf(finalTheme.theme) === -1) finalTheme.theme = 'auto';

            if (sync) {
                $.ajax({ url: '/admin/index/config', type: 'POST', data: finalTheme });
            }

            const getPreferredTheme = () => {
                if (storedTheme.theme !== null) return storedTheme;
                const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                const preferredScheme = $('body').data('color-scheme') || '';
                return { theme: preferredTheme, scheme: preferredScheme };
            };

            const setTheme = (theme) => {
                if (theme.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    $('html').attr('data-bs-theme', 'dark');
                } else {
                    $('html').attr('data-bs-theme', theme.theme);
                }
                $('html').attr('data-color-scheme', theme.scheme);
                localStorage.setItem('theme', JSON.stringify(theme));
            };

            const showActiveTheme = (theme, shouldFocus = false) => {
                const $switcher = $('#bd-theme');
                if (!$switcher.length) return;
                const $switcherText = $('#bd-theme-text');
                const $activeThemeIcon = $('.theme-icon-active i');
                const $btnToActive = $(`[data-bs-theme-value="${theme.theme}"]`);
                const svgOfActiveBtn = $btnToActive.find('i').attr('class').replace('me-2', '');
                $('[data-bs-theme-value]').removeClass('active').attr('aria-pressed', 'false');
                $btnToActive.addClass('active').attr('aria-pressed', 'true');
                $activeThemeIcon.attr('class', svgOfActiveBtn);
                const label = `${$switcherText.text()} (${$btnToActive.data('bs-theme-value')})`;
                $switcher.attr('aria-label', label);
                if (shouldFocus) $switcher.trigger('focus');
            };

            setTheme(getPreferredTheme());
            showActiveTheme(getPreferredTheme(), focus);
        },

        // ---- iframe 消息 ----
        sendMessageToIframe(conId, message) {
            const container = document.getElementById(conId);
            if (!container) return;
            const iframe = container.querySelector('iframe');
            if (iframe && iframe.contentWindow && iframe.contentWindow.Framework) {
                iframe.contentWindow.postMessage(message, window.location.origin);
            } else if (iframe) {
                let messages = [];
                try { messages = JSON.parse(iframe.dataset.messages || '[]'); } catch (_) {}
                messages.push(JSON.stringify(message));
                iframe.dataset.messages = JSON.stringify(messages);
            }
        },

        // ---- 框架内打开链接（iframe 标签页） ----
        openInFrame(url, title, icon, retry = 0) {
            if (!url) return;
            const nav = window.navtab || (window.Wise && window.Wise.navtabs);
            if (!nav || !nav.api || typeof nav.api.addTab !== 'function') {
                // 降级：tab 模块未加载时整页跳转
                window.location.href = url;
                return;
            }
            // navtabs 尚未 _init（_ready=false）时 $tab 未就绪，addTab 会抛错，轮询等待就绪
            if (nav._ready === false && retry < 40) {
                setTimeout(() => Framework.api.openInFrame(url, title, icon, retry + 1), 50);
                return;
            }
            // 统一去掉 .html 后缀，与侧边栏菜单 data-url 约定保持一致：
            // 否则 hash 会带 .html，刷新后匹配不到侧边栏菜单而走无标题 fallback。
            const normalized = String(url).replace(/\.html([?#].*)?$/, '$1');
            const meta = { title: title || '页面', icon: icon || '' };
            Framework.api.saveTabMeta(normalized, meta);
            nav.api.addTab({
                id: normalized,
                url: normalized,
                title: meta.title,
                icon: meta.icon,
                isActive: true,
            });
        },

        // ---- 非菜单链接的标题/图标持久化（供刷新后 hash fallback 恢复） ----
        saveTabMeta(url, meta) {
            try {
                const map = JSON.parse(localStorage.getItem('wise.tab.meta') || '{}');
                map[url] = meta;
                localStorage.setItem('wise.tab.meta', JSON.stringify(map));
            } catch (_) {}
        },
        getTabMeta(url) {
            try {
                const map = JSON.parse(localStorage.getItem('wise.tab.meta') || '{}');
                return map[url] || null;
            } catch (_) { return null; }
        },

        // ---- 获取 CSRF Token（底层核心能力，供全局 AJAX 注入与其它模块复用） ----
        // 优先读 Cookie：中间件每次写请求校验通过后会轮换 token，Cookie 始终是最新值；
        // meta 标签只在页面渲染时注入一次，仅作首次加载兜底。
        // Cookie 读取统一走 libs/cookie/cookie.js（原先这里自建了一套扫描逻辑）。
        getCsrfToken() {
            const fromCookie = Cookie.get(config.get('csrfCookieKey', 'csrf_token'));
            if (fromCookie) return fromCookie;

            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        },

    },

    /**
     * 取「真正持有菜单 / 标签页」的那份 Wise.nav
     *
     * · iframe 内 → `window.top` 的（同源直取）；
     * · 主框架内 → 自己那份；
     * · 跨域宿主或宿主未加载内核 → null，调用方随即放行浏览器原生跳转。
     *
     * ⚠️ 绝不在 iframe 内退回「自己那份 nav」：那会在子文档里创建不可见标签页，
     *    表现为「点了没反应」。宁可原生跳转。
     */
    hostNav() {
        const own = (window.Wise && window.Wise.nav) ? window.Wise.nav : null;
        if (window.self === window.top) return own;
        try {
            const top = window.top;
            if (top && top.Wise && top.Wise.nav) return top.Wise.nav;
        } catch (_) { /* 跨域：不可达 */ }
        return null;
    },

    // ---- 初始化入口 ----
    init() {
        Framework.api.switchTheme();
        Framework.bindEvent();
        Framework.initComponents();
        Framework.checkIframeMessages();
        Framework.bindShortcuts();
    },

    // ---- iframe 内消息恢复 ----
    checkIframeMessages() {
        if (window.self === window.top) return;
        const iframe = window.frameElement;
        if (!iframe) return;
        let messages = [];
        try {
            messages = JSON.parse(iframe.dataset.messages || '[]', (key, value) => {
                try { return JSON.parse(value); } catch (_) { return value; }
            });
        } catch (_) {}
        delete iframe.dataset.messages;
        messages.forEach(msg => window.postMessage(msg, window.location.origin));
    },

    // ---- 全局事件绑定 ----
    bindEvent() {
        // 加载完成关闭加载动画
        setTimeout(() => $('.loading-overlay').addClass('hidden'), 500);

        // 窗口失焦关下拉
        window.addEventListener('blur', () => $('.dropdown-menu').removeClass('show'));

        // Tooltip
        $('body').tooltip({ selector: '[data-bs-toggle="tooltip"]', trigger: 'hover' });

        // iframe 消息：tab 标题/图标回填页头（D5=B：传参优先，缺省回填）
        // 两套 id 契约（index_header_* / ws-page-*）各处理一次，缺元素时内部空转。
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin) return;
            if (event.data.type !== 'tab:contentLoaded') return;
            const opts = event.data?.opts || {};
            applyPageHeader(opts.title, opts.icon);
            applyWsPageHeaderMeta(opts.title || '', opts.icon || '');
        });

        // 直接访问（非 iframe）时的占位清理与标题兜底
        normalizePageHeader();
        initWsPageHeader();

        // Dropdown + Tooltip 修复
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(dropdown => {
            dropdown.addEventListener('show.bs.dropdown', function () {
                const tooltip = Bootstrap.Tooltip.getInstance(this.parentNode);
                if (tooltip) { tooltip.hide(); tooltip.disable(); }
            });
            dropdown.addEventListener('blur', function () {
                const tooltip = Bootstrap.Tooltip.getInstance(this.parentNode);
                if (tooltip) tooltip.enable();
            });
        });

        // 清理缓存
        // 返回体：{code, msg, data:{targets:[{type,label,deleted,failed}], deleted, failed, took_ms}}
        // 破坏性目标（all / config）由模板上的 data-bs-cache-confirm 提供二次确认文案；
        // 请求期间禁用整个菜单，避免连点重复递归删同一棵目录（后端另有 flock 兜底）。
        $('[data-bs-cache-value]').on('click', function () {
            const $btn = $(this);
            const type = $btn.data('bs-cache-value');
            const confirmText = $btn.data('bs-cache-confirm');

            const run = () => {
                const $items = $('[data-bs-cache-value]');

                $items.prop('disabled', true).addClass('disabled').attr('aria-busy', 'true');

                $.ajax({ url: '/admin/idler/clearCache', type: 'POST', data: { type }, dataType: 'json' })
                    .done((res) => {
                        const ok = res && Number(res.code) === 0;
                        const failed = res?.data?.failed?.length || 0;
                        const content = res?.msg || (ok ? '缓存已清理' : '缓存清理失败');

                        Layer.toast({ content, type: ok ? (failed ? 'warning' : 'success') : 'error' });
                    })
                    .fail((xhr) => {
                        // 403 / 网络异常：优先用后端文案（如「无权限」「已有清理任务在执行」）
                        Layer.toast({ content: xhr.responseJSON?.msg || '缓存清理失败', type: 'error' });
                    })
                    .always(() => {
                        $items.prop('disabled', false).removeClass('disabled').removeAttr('aria-busy');
                    });
            };

            if (confirmText) {
                Layer.confirm(confirmText, { title: '清理缓存', icon: 'warning' }, function(index){
                    Layer.close(index);
                    run();
                });
            } else {
                run();
            }
        });

        // 子页面跳转检测
        if ($('meta[name="site-subframe"]').attr('content') === 'true' && window.self === window.top) {
            const loc = window.location.href.replace(window.location.origin, '');
            Layer.confirm('是否从主页面访问', { title: '跳转提示', icon: 'warning' }, function () {
                window.location.href = window.location.origin + '/admin/index.html#' + loc;
            });
        }

        // iframe 内 ESC → 主框架
        $(document).on('keydown', function (e) {
            if (window.self === window.parent) return;
            if (e.key !== 'Escape') return;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            if (!window.parent.wsLayer) return;
            const iframeId = (window.frameElement?.id || '').replace('ws-layer-iframe-', '');
            if (!iframeId) return;
            window.parent.$(window.parent.document).triggerHandler($.Event('keydown', $.extend({}, e, { namespace: 'ws-layer-' + iframeId })));
        });

        // 非菜单链接（任意带 data-frame-url 的元素，如顶栏消息铃铛）→ 框架内 iframe 打开
        // 侧边栏菜单的打开由 admin/index.js 负责，此处不再处理，避免双重绑定。
        $(document).on('click', 'a[data-frame-url]', function (e) {
            const $a = $(this);
            const url = $a.attr('data-frame-url') || $a.attr('href');
            if (!url || url === '#' || url.indexOf('javascript:') === 0) return;
            // 保留 Ctrl/Cmd/中键 新标签页打开
            if (e.ctrlKey || e.metaKey || e.which === 2) return;
            e.preventDefault();
            const title = $a.attr('data-frame-title') || $a.attr('data-title') || $a.text().trim();
            const icon = $a.attr('data-frame-icon') || $a.find('.nav-icon').attr('class') || '';
            // 非侧边栏链接：直接打开，不触碰侧边栏选中态
            Framework.api.openInFrame(url, title, icon);
        });

        // ── 页面内超链接「打开/切换到菜单」协议（data-wise-nav）────────────────
        // 属性存在即接管（空值 / "menu" / "auto" 走菜单链；"menu" 以外的显式值见下）。
        // 语义见 modules/wise-navmenu.js 的 Wise.nav.open()：
        //   命中菜单 → 切所属一级菜单 + 展开高亮侧边栏叶子 + 新建/复用标签（保留 ?status= 筛选态）；
        //   未命中   → 退化为框架内普通标签，不会在当前 iframe 里跳走。
        // href 仍写真实地址：Ctrl/中键、复制链接、无 JS 环境照常由浏览器处理。
        $(document).on('click', 'a[data-wise-nav]', function (e) {
            // 保留浏览器「新窗口/新标签」语义
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.which === 2) return;
            if (this.target && this.target !== '_self') return;

            const url = this.getAttribute('href') || '';
            if (!url || url.charAt(0) !== '/') return;   // 只接管站内绝对路径（外链/锚点放行）

            const nav = Framework.hostNav();
            if (!nav) return;                            // 无内核（前台页/跨域宿主）→ 原生跳转

            e.preventDefault();
            const route = String(this.getAttribute('data-wise-nav') || '').trim().toLowerCase();
            nav.open(url, {
                title: this.getAttribute('data-wise-title') || this.getAttribute('data-title') || '',
                icon: this.getAttribute('data-wise-icon') || '',
                route: route === 'tab' ? 'tab' : 'auto',
            });
        });
    },

    // ---- 组件初始化 ----
    initComponents() {
        Framework.iconSelector();
    },

    // ---- 图标选择器 ----
    iconSelector() {
        $('[data-ws-icon]').on('click', function () {
            const input = $(this).data('wsIcon');
            const inputPreview = $(this).data('wsIconPreview');
            Layer.open({
                type: 2,
                title: '图标选择器',
                content: '/admin/icons?module=bootstrap',
                area: ['80%', '80%'],
                resize: true,
                btn: ['选择图标', '取消'],
                yes(index, layero) {
                    const selectedIcon = Layer.getShared ? Layer.getShared('selectedIcon') : window._selectedIcon;
                    $(input).val(`bi bi-${selectedIcon}`);
                    $(inputPreview).html(`<i class="bi bi-${selectedIcon}"></i>`);
                    Layer.close(index);
                },
            });
        });
    },

    // ---- 快捷键 ----
    bindShortcuts() {
        const hotKey = new HotKey('framework');
        const isTop = window.self === window.top;

        // 快捷键注册事件广播
        hotKey.subscribe('hotkey-registered', (data) => {
            if (data.pageId !== hotKey.pageId) return;
            const keys = data.hotkey.split('+').map(k => k.trim());
            $('.shortcuts-content').append(`<div class="row px-3 py-2"><div class="col-6 text-end">${data.description}</div>
                <div class="col-6"><span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">${keys[0]}</span>
                ${keys.length > 1 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[1] + '</span>' : ''}
                ${keys.length > 2 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[2] + '</span>' : ''}
                ${keys.length > 3 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[3] + '</span>' : ''}
                </div></div>`);
        });

        // Shift+S 搜索菜单
        hotKey.register('shift+s', () => { if (isTop) $('[data-lte-toggle="sidebar-search"] input')[0]?.focus(); }, '搜索菜单');
        hotKey.subscribe('hotkey-triggered', (data) => {
            if (isTop && data.hotkey === 'shift+s') $('[data-lte-toggle="sidebar-search"] input')[0]?.focus();
        });

        // Shift+L 锁屏
        hotKey.register('shift+l', () => { if (isTop) $('[data-lte-widget="lockscreen"]').click(); }, '临时锁屏');
        hotKey.subscribe('hotkey-triggered', (data) => {
            if (isTop && data.hotkey === 'shift+l') $('[data-lte-widget="lockscreen"]').click();
        });

        // 接收跨窗口锁屏广播
        new Broadcast({ channelName: 'framework' }).subscribe('lockscreen', () => {
            if (isTop) $('[data-lte-widget="lockscreen"]').click();
        });

        // Shift+X 退出
        hotKey.register('shift+x', () => {
            if (isTop) {
                Layer.confirm('确定要退出当前账号吗？', { title: '退出当前账号', icon: 4, btn: ['确定退出', '取消'] }, () => {
                    window.location.href = '/admin/login/logout';
                });
            }
        }, '退出当前账号');
        hotKey.subscribe('hotkey-triggered', (data) => {
            if (isTop && data.hotkey === 'shift+x') {
                Layer.confirm('确定要退出当前账号吗？', { title: '退出当前账号', icon: 4, btn: ['确定退出', '取消'] }, () => {
                    window.location.href = '/admin/login/logout';
                });
            }
        });

        // Shift+W 关闭标签
        if (NavTabs?.api?.closeTab) {
            hotKey.register('shift+w', () => { if (isTop) NavTabs.api.closeTab(); }, '关闭当前Tab页');
            hotKey.subscribe('hotkey-triggered', (data) => {
                if (isTop && data.hotkey === 'shift+w') NavTabs.api.closeTab();
            });
        }

        // Shift+R 刷新 iframe
        hotKey.register('shift+r', () => {
            if (!isTop && $(window.frameElement).parent().hasClass('active')) window.location.reload();
        }, '刷新当前Tab页');
        hotKey.subscribe('hotkey-triggered', (data) => {
            if (!isTop && $(window.frameElement).parent().hasClass('active') && data.hotkey === 'shift+r') window.location.reload();
        });

        // 渲染快捷键页面
        if (isTop) {
            $('.shortcuts-content').html('');
            hotKey.getAllHotkeys().forEach(key => {
                const keys = key.hotkey.split('+').map(k => k.trim());
                $('.shortcuts-content').append(`<div class="row px-3 py-2"><div class="col-6 text-end">${key.description}</div>
                    <div class="col-6"><span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">${keys[0]}</span>
                    ${keys.length > 1 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[1] + '</span>' : ''}
                    ${keys.length > 2 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[2] + '</span>' : ''}
                    ${keys.length > 3 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[3] + '</span>' : ''}
                    </div></div>`);
            });
        }
    },
};

// ---- CSRF Token 全局注入 ----
// 每次 AJAX 发送前动态读取 Cookie，避免 token 轮换后仍使用过期的 meta 静态值。
// 注意：jQuery 全局 AJAX 事件必须通过 $(document).ajaxSend 注册，$.ajaxSend 不存在。
$(document).ajaxSend(function (_event, xhr) {
    const token = Framework.api.getCsrfToken();
    if (token) {
        xhr.setRequestHeader('X-CSRF-TOKEN', token);
    }
});

// ---- 暴露 + 自动初始化 ----
window.Framework = Framework;
Framework.init();

export default Framework;
export { Framework };
