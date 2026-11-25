/**
 * Wise.navtabs — 标签页导航（纯 ESM 完整实现）
 *
 * 替代 deprecated/navtabs.js，不再依赖 AMD/RequireJS。
 * 事件改用 Wise.core.eventBus。存储直接用 localStorage（已自带 try-catch）。
 *
 * @module wise-navtabs
 * @version 2.0.0
 */

const $ = window.jQuery;
const Layer = window.Layer;

// ============================================================
// NavTabManager 核心
// ============================================================
const NavTabManager = {
    defaults: {
        close: true,
        monitor: 'body',
        nav: '#dynamic-tabs',
        tab: '#dynamic-tab-content',
        iframeUse: true,
        simple: false,
        iframeHeight: '100%',
        iframeForceRefresh: false,
        iframeForceRefreshTable: false,
        callback() {},
        persistence: true,
        persistenceKey: 'navTabsState',
        allowReorder: true,
        allowRename: false,
        autoRefresh: false,
        refreshInterval: 60000,
        fixedTabs: [],
        maxTabs: 20,
        closeConfirm: false,
        closeConfirmMessage: '确定要关闭此标签吗？',
        tabActiveClass: 'active',
        tabFixedClass: 'tab-fixed',
        tabPinnedIcon: '<i class="bi bi-pin-angle small text-primary"></i>',
        tabUnpinnedIcon: '<i class="bi bi-pin small"></i>',
        lazyLoadContent: true,
        loadFixedTabsOnInit: true,
    },
    options: {},
    tabs: new Map(),
    activeTabId: null,
    dragState: { dragging: false, element: null, startIndex: 0, currentIndex: 0, placeholder: null, tabWidth: 0 },
    resizeTimer: null,
    _eventBus: null,
    _ready: false,

    // ---- 初始化 ----
    _init(opts) {
        if (this._ready) return;
        this.options = $.extend({}, this.defaults, opts);
        this.$container = $(this.options.monitor);
        this.$nav = $(this.options.nav);
        this.$tab = $(this.options.tab);
        this.tabs = new Map();
        this.activeTabId = null;

        this._validateOptions();
        this._initTabsFromDOM();
        this._initPopmenu();
        this._initResize();
        this._initAutoRefresh();
        this._bindEvents();
        this._ready = true;
    },

    // ---- 事件代理 ----
    _emit(event, data) {
        if (this._eventBus) this._eventBus.emit(event, data);
    },

    // ---- 选项校验 ----
    _validateOptions() {
        if (typeof this.options.maxTabs !== 'number' || this.options.maxTabs < 1) this.options.maxTabs = Infinity;
        if (typeof this.options.refreshInterval !== 'number' || this.options.refreshInterval < 1000) this.options.refreshInterval = 60000;
    },

    // ---- DOM 初始化已有标签 ----
    _initTabsFromDOM() {
        const self = this;
        this.$nav.find('li[role=context]').each(function () {
            const $el = $(this);
            const $a = $el.find('a[role=tab]');
            const id = $a.attr('aria-controls') || $a.attr('node-id');
            if (!id) return;
            const opts = {
                id,
                title: $a.text().trim(),
                url: $a.attr('href') || $a.attr('url'),
                content: self.$tab.find(`[role=tabpanel][id="${id}"]`).html(),
            };
            self.tabs.set(id, {
                opts,
                isFixed: self.options.fixedTabs.includes(id),
                isActive: $el.hasClass(self.options.tabActiveClass),
                refreshTimer: null,
                isLoaded: true,
            });
            if (self.tabs.get(id).isActive) self.activeTabId = id;
        });
    },

    // ---- 事件绑定 ----
    _bindEvents() {
        const self = this;
        this.$nav.on('click', '.close-tab', e => this._handleClose(e));
        this.$nav.on('dblclick', 'li[role=context]', e => this._handleDblClick(e));
        this.$nav.on('click', 'li[role=context] a[role=tab]', e => this._handleNavClick(e));
        this.$nav.on('click', '.pin-tab', e => this._handlePinClick(e));
        this.$nav.on('mouseenter', 'li[role=context]', e => this._handleMouseEnter(e));
        this.$nav.on('mouseleave', 'li[role=context]', e => this._handleMouseLeave(e));
        $(document).on('contextmenu', 'li[role=context] a[role=tab]', e => this._handleContextMenu(e));
        $(document).on('click', () => $('#navContextMenu').hide());
        $(window).on('blur', () => $('#navContextMenu').hide());
        if (this.options.allowRename) {
            this.$nav.on('dblclick', 'li[role=context] a[role=tab] .tab-title', e => { e.stopPropagation(); this._handleRename(e); });
        }
        if (this.options.allowReorder) this._initDrag();
        if (window.history?.pushState) $(window).on('popstate', e => this._handlePopState(e));
        $('[data-lte-widget="refresh"]').on('click', () => this.api.refreshTabContent(null, true));
    },

    // ---- 右键菜单 ----
    _initPopmenu() {
        const self = this;
        if ($('#navContextMenu').length) return;
        $('body').append(`
            <div id="navContextMenu" class="dropdown-menu bg-body-secondary" role="menu" style="z-index:2000;display:none">
                <a class="dropdown-item" data-menu="close"><i class="bi bi-x-lg"></i> <span>关闭标签</span> Shift + W</a>
                <a class="dropdown-item" data-menu="close-other"><i class="bi bi-x-octagon"></i> <span>关闭其他标签</span></a>
                <a class="dropdown-item" data-menu="close-left"><i class="bi bi-box-arrow-left"></i> <span>关闭左侧标签</span></a>
                <a class="dropdown-item" data-menu="close-right"><i class="bi bi-box-arrow-right"></i> <span>关闭右侧标签</span></a>
                <hr class="dropdown-divider">
                <a class="dropdown-item pin-item" data-menu="pin"><i class="bi bi-pin"></i> <span>固定标签</span></a>
                <a class="dropdown-item unpin-item" data-menu="unpin"><i class="bi bi-pin-angle"></i> <span>取消固定</span></a>
                <a class="dropdown-item" data-menu="refresh"><i class="bi bi-arrow-clockwise"></i> <span>刷新标签</span> Shift + R</a>
            </div>
        `);
        $(document).on('click', '#navContextMenu a[data-menu]', function () {
            const nodeId = $('#navContextMenu').data('node-id');
            switch ($(this).data('menu')) {
                case 'close': self.api.closeTab(nodeId); break;
                case 'close-other': self.api.closeOtherTabs(nodeId); break;
                case 'close-left': self.api.closeLeftTabs(nodeId); break;
                case 'close-right': self.api.closeRightTabs(nodeId); break;
                case 'pin': self.api.pinTab(nodeId); break;
                case 'unpin': self.api.unpinTab(nodeId); break;
                case 'refresh': self.api.refreshTabContent(nodeId, true); break;
            }
        });
    },

    // ---- 浏览器 resize ----
    _initResize() {
        const self = this;
        $(window).on('resize', () => {
            clearTimeout(self.resizeTimer);
            self.resizeTimer = setTimeout(() => { self._adjustWidth(); self._refreshDropDown(); }, 100);
        });
    },

    _initAutoRefresh() {
        if (!this.options.autoRefresh) return;
        this.tabs.forEach((tab, id) => { if (tab.isActive) this.api.startTabRefresh(id); });
    },

    // ============================================================
    // 公开 API
    // ============================================================
    api: {
        addTab(opts) {
            const self = NavTabManager;
            if (typeof opts === 'string') opts = self.tabs.get(opts)?.opts || {};
            opts = $.extend({ id: '', sid: '', title: '无名标签', icon: '', url: '', isFixed: false, content: '', ajax: false, isActive: true }, opts);
            opts.sid = opts.id;
            // 规范化 tab id：仅保留 [a-zA-Z0-9_-]，剔除 "." 等 jQuery 选择器保留字符。
            // 否则形如 /admin/xxx/index.html 的 URL 生成的 id 含 "."，
            // 后续 $('#' + id) 会被解析成 id+class 组合选择器（如 #con_xxx.html）而匹配失败。
            opts.id = String(opts.id).replace(/[^a-zA-Z0-9_-]/g, '_');
            const { id } = opts;
            if (!id) { self._emit('error', { message: 'Tab ID is required' }); return; }

            const _doAdd = () => {
                let url = opts.url || '';
                if (opts.params) url += (url.includes('?') ? '&' : '?') + Object.keys(opts.params).map(k => `${k}=${opts.params[k]}`).join('&');
                if (self.options.simple) self.api.clearAllTabs();
                if (self.tabs.has(id)) { self.api.activateTab(id); self._emit('tab:reactivated', { id, opts: self.tabs.get(id).opts }); return; }
                const tabId = `tab_${id}`, conId = `con_${id}`;
                const $tabItem = self._createTabItem(opts, tabId, conId);
                const $conItem = self._createTabContent(opts, conId, url);
                self.$tab.append($conItem);
                self._appendToNav($tabItem);
                self.tabs.set(id, { opts, isFixed: opts.isFixed || self.options.fixedTabs.includes(id), isActive: false, refreshTimer: null, isLoaded: false });
                self.api.activateTab(id);
                self._refreshDropDown();
                self._saveState();
                self._emit('tab:added', { id, opts });
            };

            if (self.tabs.size >= self.options.maxTabs && !self.tabs.has(id)) {
                Layer.confirm(`已达到最大标签数量(${self.options.maxTabs})，是否关闭最右侧标签？`, {
                    closeBtn: 0, anim: 0, title: '提示', btn: ['确定', '取消']
                }, function (index) {
                    Layer.close(index);
                    self.api.closeLeftMostTab();
                    _doAdd();
                });
            } else { _doAdd(); }
        },

        activateTab(id) {
            const self = NavTabManager;
            if (!self.tabs.has(id)) return;
            if (self.activeTabId && self.activeTabId !== id) {
                const prev = self.tabs.get(self.activeTabId);
                if (prev) prev.isActive = false;
                $(`#tab_${self.activeTabId} a`, self.$nav).removeClass(self.options.tabActiveClass);
                $(`#con_${self.activeTabId}`, self.$tab).removeClass(self.options.tabActiveClass);
                self.api.stopTabRefresh(self.activeTabId);
                self._emit('tab:deactivated', { id: self.activeTabId });
            }
            self.tabs.forEach(t => { t.opts.isActive = false; });
            self.activeTabId = id;
            const tab = self.tabs.get(id);
            tab.isActive = true;
            tab.opts.isActive = true;
            $(`#tab_${id} a`, self.$nav).addClass(self.options.tabActiveClass);
            $(`#con_${id}`, self.$tab).addClass(self.options.tabActiveClass);
            // 激活项可能在下拉组内、也可能在导航栏内联：组按钮高亮必须随之同步
            self._syncDropDownActive();
            if (self.options.lazyLoadContent && !tab.isLoaded) self.api.loadTabContent(id);
            else if (!self.options.lazyLoadContent && !tab.isLoaded) self.api.loadTabContent(id);
            self._saveState();
            if (self.options.autoRefresh) self.api.startTabRefresh(id);
            self._emit('tab:activated', { id, opts: tab.opts });
        },

        loadTabContent(id) {
            const self = NavTabManager;
            const tab = self.tabs.get(id);
            if (!tab || tab.isLoaded) return;
            const opts = tab.opts, conId = `con_${id}`;
            const $conItem = $(`#${conId}`);
            let url = opts.url || '';
            if (opts.params) url += (url.includes('?') ? '&' : '?') + Object.keys(opts.params).map(k => `${k}=${opts.params[k]}`).join('&');
            if ($conItem.is(':empty') || !tab.isLoaded) {
                self._emit('tab:contentLoading', { id, opts });
                try {
                    if (opts.content) { $conItem.append(opts.content); tab.isLoaded = true; self._emit('tab:contentLoaded', { id, opts }); }
                    else if (self.options.iframeUse && !opts.ajax) {
                        $conItem.append(`<iframe id="iframe${id}" src="${url}" width="100%" height="${self.options.iframeHeight}" frameborder="no" border="0" marginwidth="0" marginheight="0" scrolling-x="no" scrolling-y="auto" allowtransparency="yes"></iframe>`);
                        tab.isLoaded = true;
                        $conItem.find('iframe').on('load', () => self._emit('tab:contentLoaded', { id, opts })).on('error', () => self._emit('tab:contentLoadError', { id, opts }));
                    } else if (opts.ajax) {
                        $.get(url, data => { $conItem.html(data); tab.isLoaded = true; self._emit('tab:contentLoaded', { id, opts }); }).fail(err => { self._emit('tab:contentLoadError', { id, opts, error: err }); });
                    } else { tab.isLoaded = true; self._emit('tab:contentLoaded', { id, opts }); }
                } catch (e) { self._emit('tab:contentLoadError', { id, opts, error: e }); }
            }
        },

        closeTab(id) {
            const self = NavTabManager;
            if (!id) { id = self.activeTabId; if (!id) return; }
            const tab = self.tabs.get(id);
            if (!tab) return;
            if (tab.isFixed) { self._emit('tab:closePrevented', { id, reason: 'tab is fixed' }); return; }
            const _close = () => {
                self.api.stopTabRefresh(id);
                let newId = null;
                if (self.activeTabId === id) newId = self._getAdjacentId(id);
                $(`#tab_${id}`, self.$nav).remove();
                $(`#con_${id}`, self.$tab).remove();
                self.tabs.delete(id);
                if (newId) self.api.activateTab(newId);
                self._refreshDropDown();
                self._saveState();
                self.options.callback();
                self._emit('tab:closed', { id, newActiveId: newId });
            };
            if (self.options.closeConfirm) {
                Layer.confirm(self.options.closeConfirmMessage, {
                    closeBtn: 0, icon: 2, anim: 0, title: '关闭标签', btn: ['确定', '取消']
                }, function (index) {
                    Layer.close(index);
                    _close();
                }, function (index) {
                    Layer.close(index);
                    self._emit('tab:closeCancelled', { id });
                });
            } else _close();
        },

        closeLeftTabs(id) {
            const self = NavTabManager;
            const $cur = $(`#tab_${id}`, self.$nav);
            if (!$cur.length) return;
            let count = 0;
            $cur.prevAll('li[role=context]').not('.tabdrop').each(function () {
                const tid = $(this).find('a').attr('aria-controls');
                const t = self.tabs.get(tid);
                if (t && !t.isFixed) { self.api.closeTab(tid); count++; }
            });
            if (count > 0) self._emit('tabs:closedLeft', { id, closedCount: count });
        },

        closeRightTabs(id) {
            const self = NavTabManager;
            const $cur = $(`#tab_${id}`, self.$nav);
            if (!$cur.length) return;
            let count = 0;
            $cur.nextAll('li[role=context]').not('.tabdrop').each(function () {
                const tid = $(this).find('a').attr('aria-controls');
                const t = self.tabs.get(tid);
                if (t && !t.isFixed) { self.api.closeTab(tid); count++; }
            });
            if (count > 0) self._emit('tabs:closedRight', { id, closedCount: count });
        },

        closeOtherTabs(id) {
            const self = NavTabManager;
            const $cur = $(`#tab_${id}`, self.$nav);
            if (!$cur.length) return;
            let count = 0;
            self.$nav.find('li[role=context]').not('.tabdrop').not(`#tab_${id}`).each(function () {
                const tid = $(this).find('a').attr('aria-controls');
                const t = self.tabs.get(tid);
                if (t && !t.isFixed) { self.api.closeTab(tid); count++; }
            });
            if (count > 0) self._emit('tabs:closedOther', { id, closedCount: count });
        },

        pinTab(id) { const self = NavTabManager; if (self.tabs.get(id) && !self.tabs.get(id).isFixed) self.api.toggleTabPin(id); },
        unpinTab(id) { const self = NavTabManager; if (self.tabs.get(id)?.isFixed) self.api.toggleTabPin(id); },

        closeLeftMostTab() {
            const self = NavTabManager;
            const $first = self.$nav.find('li[role=context]').not(`.${self.options.tabFixedClass}`).not('.tabdrop').first();
            const tid = $first.find('a').attr('aria-controls');
            if (tid) self.api.closeTab(tid);
        },

        clearAllTabs() {
            const self = NavTabManager;
            self.tabs.forEach((tab, tid) => {
                if (tab.isFixed) return;
                self.api.stopTabRefresh(tid);
                $(`#tab_${tid}`, self.$nav).remove();
                $(`#con_${tid}`, self.$tab).remove();
                self.tabs.delete(tid);
            });
            self.activeTabId = null;
            self._refreshDropDown();
            self._saveState();
            self._emit('tabs:cleared');
        },

        startTabRefresh(id) {
            const self = NavTabManager;
            const tab = self.tabs.get(id);
            if (!tab) return;
            if (tab.refreshTimer) clearInterval(tab.refreshTimer);
            tab.refreshTimer = setInterval(() => self.api.refreshTabContent(id), self.options.refreshInterval);
        },

        stopTabRefresh(id) {
            const tab = NavTabManager.tabs.get(id);
            if (tab?.refreshTimer) { clearInterval(tab.refreshTimer); tab.refreshTimer = null; }
        },

        refreshTabContent(id, force = false) {
            const self = NavTabManager;
            id = id || self.activeTabId;
            const tab = self.tabs.get(id);
            if (!tab) return;
            const { opts } = tab, conId = `con_${id}`;
            let url = opts.url || '';
            self._emit('tab:refreshStart', { id, opts, force });
            try {
                if (force) {
                    if (self.options.iframeUse && !opts.ajax) {
                        const sep = url.includes('?') ? '&' : '?';
                        $(`#${conId} iframe`).attr('src', `${url}${sep}_t=${Date.now()}`);
                    } else if (opts.ajax) { $.get(url, d => { $(`#${conId}`).html(d); self._emit('tab:refreshed', { id, opts, force }); }); }
                    else location.reload();
                } else {
                    if (self.options.iframeForceRefresh) { $(`#${conId} iframe`).attr('src', $(`#${conId} iframe`).attr('src')); }
                    else if (self.options.iframeForceRefreshTable) {
                        try { const iframe = $(`#${conId} iframe`)[0]; if (iframe?.contentWindow?.$) { const $b = iframe.contentWindow.$('.btn-refresh:not([data-force-refresh=false])'); if ($b.length) $b.click(); else $(`#${conId} iframe`).attr('src', $(`#${conId} iframe`).attr('src')); } } catch (e) { $(`#${conId} iframe`).attr('src', $(`#${conId} iframe`).attr('src')); }
                    } else if (opts.ajax) { $.get(url, d => { $(`#${conId}`).html(d); self._emit('tab:refreshed', { id, opts, force }); }); }
                    else { try { $(`#${conId} iframe`)[0].contentWindow.location.reload(); } catch (e) { location.reload(); } }
                }
                self._emit('tab:refreshed', { id, opts, force });
            } catch (e) { self._emit('tab:refreshError', { id, opts, error: e, force }); }
        },

        toggleTabPin(id) {
            const self = NavTabManager;
            const tab = self.tabs.get(id);
            if (!tab) return;
            tab.isFixed = !tab.isFixed;
            tab.opts.isFixed = tab.isFixed;
            const $item = $(`#tab_${id}`, self.$nav);
            const $pin = $item.find('.pin-tab');
            if (tab.isFixed) { $item.addClass(self.options.tabFixedClass); $item.find('.close-tab').hide(); $pin.html(self.options.tabPinnedIcon); $pin.show(); }
            else { $item.removeClass(self.options.tabFixedClass); $item.find('.close-tab').show(); $pin.html((tab.opts.icon ? `<i class="${tab.opts.icon}"></i>` : self.options.tabUnpinnedIcon)); }
            self._reorderFixed();
            self._saveState();
            self._emit('tab:pinned', { id, isFixed: tab.isFixed });
        },

        loadSavedState(defaultMenuUrl = null) {
            const self = NavTabManager;
            if (!self.options.persistence) return;
            let saved;
            try { saved = localStorage.getItem(self.options.persistenceKey); } catch (e) { return; }
            if (!saved) return;
            try {
                const state = JSON.parse(saved);
                state.tabs.forEach(({ id, opts, isFixed, isLoaded }) => {
                    // 兼容旧版本保存的含 "." 等特殊字符的 id
                    id = String(id).replace(/[^a-zA-Z0-9_-]/g, '_');
                    if (opts) opts.id = id;
                    if (self._getNavType() === 'navigate' && !isFixed) return;
                    if (self.tabs.has(id)) { const t = self.tabs.get(id); t.isFixed = isFixed; t.isLoaded = isLoaded; return; }
                    const tabId = `tab_${id}`, conId = `con_${id}`;
                    const $tabItem = self._createTabItem({ ...opts, isFixed }, tabId, conId);
                    const $contentItem = self._createTabContent({ ...opts, isFixed }, conId, opts.url || '');
                    self.$tab.append($contentItem);
                    self._appendToNav($tabItem);
                    self.tabs.set(id, { opts, isFixed, isActive: false, refreshTimer: null, isLoaded: false });
                });
                if (state.activeTab && self.tabs.has(state.activeTab)) self.api.activateTab(state.activeTab);
                else if (self.tabs.size > 0) self.api.activateTab([...self.tabs.keys()][0]);
                else if (defaultMenuUrl && $(`.sidebar-menu a[data-url="${defaultMenuUrl}"]`).length) $(`.sidebar-menu a[data-url="${defaultMenuUrl}"]`).click();
                self._reorderFixed();
                self._emit('tabs:restored', { state });
            } catch (e) { console.error('Failed to load saved tabs state:', e); }
        },

        trigger(event, data) { NavTabManager._emit(event, data); },
        on(event, listener) { NavTabManager._eventBus && NavTabManager._eventBus.on(event, listener); },
        off(event, listener) { NavTabManager._eventBus && NavTabManager._eventBus.off(event, listener); },
    },

    // ============================================================
    // 内部方法
    // ============================================================
    _createTabItem(opts, tabId, conId) {
        const isFixed = opts.isFixed || this.options.fixedTabs.includes(opts.id);
        const pinIcon = isFixed ? this.options.tabPinnedIcon : (opts.icon ? `<i class="${opts.icon}"></i>` : this.options.tabUnpinnedIcon);
        return $(`
            <li role="context" id="${tabId}" class="text-nowrap nav-item ${isFixed ? this.options.tabFixedClass : ''}">
                <a href="#${conId}" node-id="${opts.id}" class="nav-link" aria-controls="${opts.id}" role="tab" data-toggle="tab">
                    <span class="pin-tab">${pinIcon}</span>
                    <span class="tab-title">${opts.title}</span>
                    ${this.options.close ? `<i class="close-tab bi bi-x-lg"${isFixed ? ' style="display:none"' : ''}></i>` : ''}
                </a>
            </li>
        `);
    },

    _createTabContent(opts, conId, url) {
        const $con = $(`<div role="tabpanel" class="tab-pane h-100" id="${conId}"></div>`);
        if (this.options.lazyLoadContent) return $con;
        if (opts.content) $con.append(opts.content);
        else if (this.options.iframeUse && !opts.ajax) $con.append(`<iframe src="${url}" width="100%" height="${this.options.iframeHeight}" frameborder="no" border="0" marginwidth="0" marginheight="0" scrolling-x="no" scrolling-y="auto" allowtransparency="yes"></iframe>`);
        else if (opts.ajax) $.get(url, d => $con.append(d));
        return $con;
    },

    _appendToNav($tabItem) {
        if (this.options.simple) return;
        const $dropdownMenu = $('.tabdrop ul', this.$nav);
        $dropdownMenu.length ? $dropdownMenu.append($tabItem) : this.$nav.append($tabItem);
    },

    _getAdjacentId(id) {
        const $cur = $(`#tab_${id}`, this.$nav);
        const $prev = $cur.prev().not('.tabdrop');
        const $next = $cur.next().not('.tabdrop');
        if ($prev.length) return $prev.find('a').attr('aria-controls');
        if ($next.length) return $next.find('a').attr('aria-controls');
        if (this.tabs.size > 0) { const last = [...this.tabs.keys()].pop(); if (last !== id) return last; }
        return null;
    },

    _adjustWidth() {
        if (typeof this.options.nav === 'object') {
            let siblingWidth = 0;
            this.$nav.siblings().each(function () { siblingWidth += $(this).outerWidth(); });
            this.$nav.width(this.$nav.parent().width() - siblingWidth);
        } else {
            this.$nav.width($('#header').find('> .navbar').width() - $('.sidebar-toggle').outerWidth() - $('.navbar-custom-menu').outerWidth() - 20);
        }
    },

    _refreshDropDown() {
        let $dropdown = $('.tabdrop', this.$nav);
        if (!$dropdown.length) {
            $dropdown = $(`<li class="dropdown hide tabdrop"><a class="dropdown-toggle nav-link" data-bs-toggle="dropdown" href="javascript:;"><i class="bi bi-list-ul"></i><b class="caret"></b></a><ul class="border border-top-0 dropdown-menu bg-body-secondary" role="menu"></ul></li>`).appendTo(this.$nav);
        }
        if (this.$nav.parent().is('.tabs-below')) $dropdown.addClass('dropup'); else $dropdown.removeClass('dropup');
        const margin = 200, maxW = this.$nav.width() - margin;
        let totalWidth = 0;
        const $items = this.$nav.append($dropdown.find('li')).find('>li').not('.tabdrop');
        $items.each(function () { totalWidth += $(this).outerWidth(true); });
        if (this.$nav.width() < totalWidth) {
            let currentWidth = 0;
            $items.each(function () { currentWidth += $(this).outerWidth(true); if (currentWidth > maxW) $dropdown.find('ul').append($(this)); });
            $dropdown.appendTo(this.$nav).show();
            this._syncDropDownActive(true);
        } else {
            $dropdown.hide();
            this._syncDropDownActive(false);
        }
    },

    /**
     * 同步「折叠下拉组按钮」的激活态
     *
     * 语义：只有当**当前激活的标签本身被折叠进下拉菜单**时，组按钮才应高亮；
     * 激活标签在导航栏内联显示时组按钮必须取消高亮。
     *
     * 之所以单独抽出来：dropdown 的 active 一旦被加上，`activateTab()`（点下拉里的
     * 标签 / 点内联标签）并不会触发 `_refreshDropDown()`，旧实现在这种情况下会残留
     * 高亮 —— 表现为「激活项不在下拉里，下拉组按钮却也亮着」。
     *
     * @param {boolean} [visible] 组按钮是否处于显示状态（省略时按计算样式判定）
     */
    _syncDropDownActive(visible) {
        const $dropdown = $('.tabdrop', this.$nav);
        if (!$dropdown.length) return;
        const activeClass = this.options.tabActiveClass;
        if (typeof visible !== 'boolean') visible = $dropdown.css('display') !== 'none';
        const activeInDropdown = $dropdown.find(`.dropdown-menu .nav-link.${activeClass}`).length > 0;
        $dropdown.children('.dropdown-toggle').toggleClass(activeClass, visible && activeInDropdown);
    },

    _reorderFixed() {
        const $items = this.$nav.find('li[role=context]').not('.tabdrop');
        const $fixed = $items.filter(`.${this.options.tabFixedClass}`);
        const $unfixedTabs = $items.not(`.${this.options.tabFixedClass}`);
        $items.detach();
        this.$nav.append($fixed).append($unfixedTabs);
        this._refreshDropDown();
    },

    _saveState() {
        if (!this.options.persistence) return;
        const state = { activeTab: this.activeTabId, tabs: [...this.tabs.entries()].map(([id, t]) => ({ id, opts: t.opts, isFixed: t.isFixed, isLoaded: t.isLoaded })) };
        try { localStorage.setItem(this.options.persistenceKey, JSON.stringify(state)); } catch (e) {}
    },

    _getNavType() {
        const navEntry = performance.getEntriesByType('navigation');
        if (navEntry.length) return navEntry[0].type;
        if (performance.navigation) return ['navigate', 'reload', 'back_forward'][performance.navigation.type] || 'unknown';
        return 'unknown';
    },

    // ---- 事件处理 ----
    _handleClose(e) { e.preventDefault(); e.stopPropagation(); this.api.closeTab($(e.currentTarget).parent().attr('aria-controls')); },

    _handleDblClick(e) {
        const self = this;
        const $t = $(e.currentTarget), id = $t.find('a').attr('aria-controls'), tab = self.tabs.get(id);
        if (tab?.isFixed) { self.api.toggleTabPin(id); return; }
        $t.find('.close-tab').trigger('click');
    },

    _handleNavClick(e) { e.preventDefault(); this.api.activateTab($(e.currentTarget).attr('node-id')); },

    _handleContextMenu(e) {
        e.preventDefault(); e.stopPropagation();
        const self = this, nodeId = $(e.currentTarget).attr('node-id'), tab = self.tabs.get(nodeId), menu = $('#navContextMenu');
        menu.data('node-id', nodeId);
        if (tab) { menu.find('.pin-item').toggle(!tab.isFixed); menu.find('.unpin-item').toggle(tab.isFixed); }
        const contextMenuLeft = e.pageX + menu.width() > window.innerWidth ? e.pageX - menu.width() : e.pageX;
        menu.css({ display: 'block', left: contextMenuLeft, top: e.pageY });
        return false;
    },

    _handlePinClick(e) { e.preventDefault(); e.stopPropagation(); this.api.toggleTabPin($(e.currentTarget).closest('a').attr('aria-controls')); },

    _handleMouseEnter(e) {
        const $t = $(e.currentTarget), id = $t.find('a').attr('aria-controls'), tab = this.tabs.get(id);
        if (tab && !tab.isFixed) $t.find('.pin-tab').html(this.options.tabUnpinnedIcon);
    },

    _handleMouseLeave(e) {
        const $t = $(e.currentTarget), id = $t.find('a').attr('aria-controls'), tab = this.tabs.get(id);
        if (tab && !tab.isFixed) $t.find('.pin-tab').html(tab.opts.icon ? `<i class="${tab.opts.icon}"></i>` : this.options.tabUnpinnedIcon);
    },

    _handleRename(e) {
        const self = this, $title = $(e.currentTarget), $a = $title.closest('a'), id = $a.attr('aria-controls');
        const name = prompt('请输入新标签名称:', $title.text().trim());
        if (name) { $title.text(name); const tab = self.tabs.get(id); if (tab) { tab.opts.title = name; self._saveState(); self._emit('tab:renamed', { id, oldTitle: $title.text(), newTitle: name }); } }
    },

    _handlePopState(e) { if (e.originalEvent?.state?.id) this.api.addTab(e.originalEvent.state); },

    // ---- 拖拽 ----
    _initDrag() {
        const self = this;
        self.$nav.on('mousedown', '>li[role=context]', function (e) {
            if (e.button !== 0) return;
            const $t = $(this), id = $t.find('a').attr('aria-controls');
            if (self.tabs.get(id)?.isFixed) return;
            e.preventDefault();
            const $draggable = self.$nav.find('>li[role=context]').not('.tabdrop').not(`.${self.options.tabFixedClass}`);
            const tw = $t.outerWidth();
            self.dragState = { dragging: false, element: $t, startIndex: $draggable.index($t), currentIndex: $draggable.index($t), placeholder: null, tabWidth: tw, startX: e.clientX, startY: e.clientY, threshold: 5 };
            $(document).on('mousemove', _dragMove).on('mouseup', _dragEnd);
        });

        function _dragMove(e) {
            const dragState = self.dragState;
            if (!dragState.element) return;
            const dragDistance = Math.sqrt((e.clientX - dragState.startX) ** 2 + (e.clientY - dragState.startY) ** 2);
            if (!dragState.dragging && dragDistance > dragState.threshold) {
                dragState.dragging = true;
                dragState.placeholder = $(`<li class="drag-placeholder" style="width:${dragState.tabWidth}px;height:${dragState.element.outerHeight()}px;visibility:hidden"></li>`);
                dragState.element.addClass('dragging').css({ position: 'absolute', left: dragState.element.position().left, backgroundColor: 'rgba(0,0,0,0.3)', zIndex: 1000 });
                dragState.element.after(dragState.placeholder);
            }
            if (dragState.dragging) {
                e.preventDefault(); e.stopPropagation();
                dragState.element.css({ transform: `translateX(${e.clientX - dragState.startX}px)` });
                const $tabs = self.$nav.find('>li[role=context]').not('.tabdrop').not(`.${self.options.tabFixedClass}`).not(dragState.element).not(dragState.placeholder);
                let newIndex = dragState.startIndex;
                $tabs.each(function (i) { if (dragState.element.position().left + dragState.element.outerWidth(true) / 2 > $(this).position().left + $(this).outerWidth(true) / 2) newIndex = i + 1; });
                newIndex = Math.max(0, Math.min(newIndex, $tabs.length));
                if (newIndex !== dragState.currentIndex) { dragState.currentIndex = newIndex; const $targetTab = newIndex < $tabs.length ? $tabs.eq(newIndex) : $tabs.last(); newIndex === 0 ? $tabs.first().before(dragState.placeholder) : $targetTab.before(dragState.placeholder); }
            }
        }

        function _dragEnd(e) {
            $(document).off('mousemove', _dragMove).off('mouseup', _dragEnd);
            const dragState = self.dragState;
            if (dragState.dragging) {
                dragState.element.removeClass('dragging').css({ transform: '', position: '', backgroundColor: '', zIndex: '' });
                if (dragState.placeholder) { dragState.placeholder.after(dragState.element); dragState.placeholder.remove(); }
                if (dragState.currentIndex !== dragState.startIndex) { self._saveState(); self._emit('tabs:reordered'); }
                self._refreshDropDown();
                e.preventDefault(); e.stopPropagation();
            }
            self.dragState = { dragging: false, element: null, startIndex: 0, currentIndex: 0, placeholder: null, tabWidth: 0 };
        }
    },
};

// 自动初始化（DOM 就绪时）
function _autoInit() {
    if (NavTabManager._ready) return;
    NavTabManager._init({});
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', _autoInit);
else setTimeout(_autoInit, 0);

export default NavTabManager;
export { NavTabManager };
