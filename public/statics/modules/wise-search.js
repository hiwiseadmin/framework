/**
 * Wise.search — 全局搜索组件（ESM）
 *
 * 功能：
 * - 模态框搜索 UI，支持关键词输 + 防抖查询
 * - 分组渲染搜索结果（按 category），键盘上/下/回车导航
 * - 点击结果 → 调用 Navtabs.api.addTab() 打开标签页
 * - 本地最近搜索记录（localStorage，最多 8 条）
 * - 快捷键 `/` 唤醒（备选 Ctrl+K / Cmd+K），ESC 关闭
 *
 * @module wise-search
 * @version 1.0.0
 */

const $ = window.jQuery;
const Layer = window.Layer;
const Ntabs = window.navtab;
const HotKey = window.HotKey;

const Search = {
    modal: null,
    input: null,
    results: [],
    selectedIndex: -1,
    debounceTimer: null,
    recentKey: 'wise_search_recent',
    _inited: false,

    init() {
        if (this._inited) return;
        this._inited = true;

        // 仅当搜索入口 DOM 存在时初始化（导航栏与模态框只存在于主框架 index 页面，
        // iframe 子页面无此元素，跳过以免绑定失败）
        // 入口按屏幕宽度二选一：#global-search-box（宽屏伪输入框）/ #global-search-trigger（窄屏图标按钮），
        // 两者可能其一被 CSS 隐藏，但都常驻 DOM，统一绑定即可，无需感知当前断点。
        const $triggers = $('#global-search-box, #global-search-trigger');

        if (!$triggers.length || !$('#globalSearchModal').length) {
            return;
        }

        this.modal = new bootstrap.Modal('#globalSearchModal', { keyboard: true, backdrop: true });
        this.input = $('#global-search-input');

        // ---- 触发器：点击搜索入口（阻止 <a href="#"> 默认锚点跳转） ----
        $triggers.on('click', (e) => {
            e.preventDefault();
            this.open();
        });

        // ---- 快捷键 ----
        // 主快捷键 `/`（GitHub / YouTube / Jira 惯例）：单键触发，零浏览器冲突。
        // 备选 Ctrl+K（Windows）/ Cmd+K（macOS）：部分浏览器占用（Chrome 地址栏搜索），
        //   此处尽力接管；浏览器不放行时由其自行处理，两端都不会失灵，属零成本增益。
        // 裸键 / Shift 组合会输入可见字符，hotkey.js 的 _handleKeyDown 已统一在
        // 输入框 / 文本域 / 富文本中放行，故表单里打 "/" 不会被劫持。
        const hotKey = new HotKey('framework');
        const openSearch = () => this.open();
        hotKey.register('/', openSearch, '全局搜索');
        // 非 US 布局（如德语）下 "/" 需配合 Shift 才能输入，一并注册
        hotKey.register('shift+/', openSearch, '全局搜索');
        hotKey.register('ctrl+k', openSearch, '全局搜索');
        hotKey.register('meta+k', openSearch, '全局搜索');
        hotKey.subscribe('hotkey-triggered', (data) => {
            if (data.hotkey === 'shift+/' || data.hotkey === 'ctrl+k' || data.hotkey === 'meta+k') openSearch();
        });
        // ---- 输入防抖搜索 ----
        this.input.on('input', () => {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                const kw = this.input.val().trim();
                if (kw) {
                    this.doSearch(kw);
                } else {
                    this.showRecent();
                }
            }, 250);
        });

        // ---- 键盘导航 ----
        this.input.on('keydown.wiseSearchNav', (e) => this._handleKeydown(e));

        // ---- 模态框打开：聚焦 + 显示最近 ----
        $('#globalSearchModal').on('shown.bs.modal', () => {
            this.input.val('').focus();
            this.selectedIndex = -1;
            this.results = [];
            this.showRecent();
        });

        // ---- 模态框关闭：清空 ----
        $('#globalSearchModal').on('hidden.bs.modal', () => {
            this.input.val('');
            $('#global-search-results').html('<div class="text-center py-3 text-muted small">输入关键词开始搜索...</div>');
            $('#search-result-count').text('');
            this.selectedIndex = -1;
            this.results = [];
        });
    },

    // ---- 打开搜索框 ----
    open() {
        if (!this.modal) {
            this.modal = new bootstrap.Modal('#globalSearchModal');
        }
        this.modal.show();
    },

    // ---- 执行搜索 ----
    async doSearch(keyword) {
        const $container = $('#global-search-results');
        $container.html('<div class="text-center py-3 text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>搜索中...</div>');
        $('#search-result-count').text('');

        try {
            const token = $('meta[name="csrf-token"]').attr('content') || '';
            const res = await $.ajax({
                url: '/admin/search/global',
                type: 'POST',
                data: { keyword: keyword },
                dataType: 'json',
                headers: token ? { 'X-CSRF-Token': token } : {},
            });

            if (res.code === 0 && Array.isArray(res.data) && res.data.length > 0) {
                this.results = res.data;
                this.selectedIndex = 0;
                this._render(res.data);
                this._saveRecent(keyword);
            } else {
                $('#global-search-results').html(
                    '<div class="text-center py-4 text-muted">未找到 "<strong>' + this._escapeHtml(keyword) + '</strong>" 的相关结果</div>'
                );
                $('#search-result-count').text('');
            }
        } catch (err) {
            $('#global-search-results').html(
                '<div class="text-center py-3 text-danger">搜索失败，请重试</div>'
            );
            console.error('全局搜索请求失败:', err);
        }
    },

    // ---- 渲染结果 ----
    _render(results) {
        // 按 category 分组
        const grouped = {};
        results.forEach(r => {
            const cat = r.category || '其他';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(r);
        });

        let html = '<div class="list-group list-group-flush">';
        let idx = 0;
        for (const [category, items] of Object.entries(grouped)) {
            html += '<div class="px-3 py-1 bg-body-secondary small text-muted fw-medium">' + this._escapeHtml(category) + '</div>';
            items.forEach(item => {
                const icon = item.icon || 'bi bi-circle';
                const title = this._escapeHtml(item.title || '');
                const sub = this._escapeHtml(item.breadcrumb || item.subtitle || '');
                html +=
                    '<a href="javascript:;" class="list-group-item list-group-item-action d-flex align-items-center py-2 search-result-item"' +
                    ' data-index="' + idx + '" data-url="' + this._escapeAttr(item.url || '') + '"' +
                    ' data-title="' + this._escapeAttr(item.title || '') + '"' +
                    ' data-icon="' + this._escapeAttr(icon) + '">' +
                    '<i class="' + this._escapeAttr(icon) + ' me-3 fs-5 text-secondary"></i>' +
                    '<div class="flex-grow-1 min-w-0">' +
                    '<div class="fw-medium text-truncate">' + title + '</div>' +
                    (sub ? '<small class="text-muted text-truncate d-block">' + sub + '</small>' : '') +
                    '</div>' +
                    '<i class="bi bi-arrow-return-left text-muted ms-2 opacity-50"></i>' +
                    '</a>';
                idx++;
            });
        }
        html += '</div>';

        const $container = $('#global-search-results');
        $container.html(html);
        $('#search-result-count').text('共 ' + results.length + ' 条结果');

        // 点击打开
        $container.find('.search-result-item').on('click', (e) => {
            this._openTab($(e.currentTarget));
        });
    },

    // ---- 打开标签页 ----
    _openTab($item) {
        const url = $item.data('url');
        const title = $item.data('title');
        const icon = $item.data('icon');
        const tabId = url.replace(/\.html$/g, '').replace(/^\/+/g, '/');

        try {
            if (typeof Ntabs !== 'undefined' && Ntabs.api && typeof Ntabs.api.addTab === 'function') {
                Ntabs.api.addTab({ id: tabId, title: title, icon: icon, url: url, content: '' });
            } else {
                window.location.href = url;
            }
        } catch (e) {
            window.location.href = url;
        }

        this.modal.hide();
    },

    // ---- 键盘导航 ----
    _handleKeydown(e) {
        if (this.results.length === 0) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = (this.selectedIndex + 1) % this.results.length;
                this._highlight();
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = (this.selectedIndex - 1 + this.results.length) % this.results.length;
                this._highlight();
                break;
            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0) {
                    const $item = $('.search-result-item').eq(this.selectedIndex);
                    if ($item.length) this._openTab($item);
                }
                break;
            case 'Escape':
                e.preventDefault();
                this.modal.hide();
                break;
        }
    },

    _highlight() {
        $('.search-result-item').removeClass('active');
        $('.search-result-item').eq(this.selectedIndex).addClass('active');
        const el = $('.search-result-item')[this.selectedIndex];
        if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    },

    // ---- 最近搜索（localStorage） ----
    _saveRecent(keyword) {
        try {
            let recent = JSON.parse(localStorage.getItem(this.recentKey) || '[]');
            recent = [keyword, ...recent.filter(k => k !== keyword)].slice(0, 8);
            localStorage.setItem(this.recentKey, JSON.stringify(recent));
        } catch (e) {
            // 静默忽略
        }
    },

    showRecent() {
        let recent = [];
        try {
            recent = JSON.parse(localStorage.getItem(this.recentKey) || '[]');
        } catch (e) { /* 忽略 */ }

        if (recent.length === 0) {
            $('#global-search-results').html(
                '<div class="text-center py-4 text-muted">输入关键词搜索菜单和功能<br><small class="text-secondary">提示：按 <kbd class="bg-body-secondary rounded px-1">/</kbd> 快速唤醒</small></div>'
            );
            $('#search-result-count').text('');
            return;
        }

        let html = '<div class="px-3 py-1 bg-body-secondary small text-muted fw-medium">最近搜索</div>';
        html += '<div class="list-group list-group-flush">';
        recent.forEach(kw => {
            html +=
                '<a href="javascript:;" class="list-group-item list-group-item-action border-0 py-2 recent-item">' +
                '<i class="bi bi-clock-history me-2 text-muted opacity-75"></i>' + this._escapeHtml(kw) + '</a>';
        });
        html += '</div>';
        const $container = $('#global-search-results');
        $container.html(html);
        $('#search-result-count').text('');

        $container.find('.recent-item').on('click', (e) => {
            const kw = $(e.currentTarget).text().trim();
            this.input.val(kw);
            this.doSearch(kw);
        });
    },

    // ---- 工具 ----

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    },

    _escapeAttr(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },
};

// 默认导出必须存在：wise-bootstrap.js 通过 `m[mod.target] || m.default || m` 解析实例，
// 而 mod.target 为小写 'search'，与命名导出 'Search' 不匹配，缺少 default 会降级为命名空间对象。
export default Search;
export { Search };
