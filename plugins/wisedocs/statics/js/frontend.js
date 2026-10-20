/**
 * frontend.js — WiseDocs 前台交互
 *
 * 职责（零依赖，除 Bootstrap 弹层外不引入任何第三方库）：
 *   1. 明暗主题切换（与 theme-init.js 共用 localStorage 键，避免首帧闪烁）；
 *   2. 搜索面板（顶栏入口 / Ctrl·⌘ + K / Esc）；
 *   3. 移动端侧栏抽屉；
 *   4. 侧栏分类折叠；
 *   5. 右侧「本页目录」生成 + 滚动高亮（scrollspy）；
 *   6. 代码块语言标签与一键复制；
 *   7. 组件型示例源码 Tab 切换；
 *   8. 「有用 / 没用 / 建议反馈」提交（写请求带 CSRF 令牌）；
 *   9. 详情页之间的局部切换（fetch 替换主区与侧栏，不整页跳转）。
 *
 * 前台接口只接收纯文本，服务端会再次净化（SanitizerService）。
 */
import { render as renderMarkdown } from '/statics/libs/ws-markdown/ws-markdown.js';

(function () {
    'use strict';

    const FEEDBACK_URL = '/plugin/wisedocs/index/feedback';
    const SEARCH_URL = '/plugin/wisedocs/index/search';
    const DETAIL_URL_MARK = '/plugin/wisedocs/index/detail';
    const THEME_KEY = 'wisedocs-theme';

    /* ------------------------------------------------------------------ *
     * CSRF
     * ------------------------------------------------------------------ */

    /**
     * 读取 CSRF Token
     *
     * 顺序与 wise-util.js 保持一致：**优先 Cookie（始终是最新值）→ meta 兜底**。
     * 服务端每次写请求校验通过后会轮换 Token（防 BREACH），若只读 meta，
     * 同一页面再次提交会带上已被替换的旧 Token，从而报 419。
     */
    function csrfToken() {
        const name = 'csrf_token=';
        const parts = (document.cookie || '').split(';');
        for (let i = 0; i < parts.length; i++) {
            const c = parts[i].trim();
            if (c.indexOf(name) === 0) {
                const v = c.substring(name.length);
                if (v) return v;
            }
        }

        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    async function postFeedback(payload) {
        const body = new URLSearchParams(payload).toString();

        const res = await fetch(FEEDBACK_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body,
        });

        let json = {};
        try {
            json = await res.json();
        } catch (e) {
            // 非 JSON 响应
        }

        if (res.ok && json && json.code === 0) {
            return json;
        }

        throw new Error((json && json.msg) || '提交失败，请稍后重试');
    }

    /* ------------------------------------------------------------------ *
     * Toast
     * ------------------------------------------------------------------ */

    let toastEl = null;
    let toastTimer = null;

    function toast(message, type) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'position-fixed top-0 start-50 translate-middle-x mt-3 px-3 py-2 rounded text-white';
            toastEl.style.zIndex = '1080';
            toastEl.style.display = 'none';
            document.body.appendChild(toastEl);
        }

        toastEl.textContent = message;
        toastEl.style.background = type === 'error' ? '#dc3545' : '#198754';
        toastEl.style.display = 'block';

        if (toastTimer) window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toastEl.style.display = 'none';
        }, 2500);
    }

    /* ------------------------------------------------------------------ *
     * 复制
     * ------------------------------------------------------------------ */

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();

        try {
            document.execCommand('copy');
        } catch (e) {
            // 忽略：复制失败不阻断阅读
        }

        document.body.removeChild(ta);
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                fallbackCopy(text);
            });
        }

        fallbackCopy(text);
        return Promise.resolve();
    }

    /* ------------------------------------------------------------------ *
     * 明暗主题
     * ------------------------------------------------------------------ */

    function initThemeToggle() {
        const btn = document.getElementById('wd-theme-toggle');
        if (!btn) return;

        btn.addEventListener('click', function () {
            const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
            const root = document.documentElement;

            root.classList.toggle('dark', next === 'dark');
            root.setAttribute('data-bs-theme', next);
            root.setAttribute('data-wd-theme', next);

            try {
                window.localStorage.setItem(THEME_KEY, next);
            } catch (e) {
                // 隐私模式下写入失败，忽略（仅本次会话生效）
            }
        });
    }

    /* ------------------------------------------------------------------ *
     * 搜索面板
     * ------------------------------------------------------------------ */

    function initSearchPanel() {
        const panel = document.getElementById('wd-search');
        if (!panel) return;

        const input = document.getElementById('wd-search-input');
        const openBtn = document.getElementById('wd-search-open');

        function isOpen() {
            return panel.classList.contains('wd-search--open');
        }

        function open() {
            panel.classList.add('wd-search--open');
            document.body.style.overflow = 'hidden';
            if (input) {
                window.setTimeout(function () {
                    input.focus();
                    input.select();
                }, 0);
            }
        }

        function close() {
            panel.classList.remove('wd-search--open');
            document.body.style.overflow = '';
        }

        function submit() {
            const keyword = input ? (input.value || '').trim() : '';
            window.location.href = SEARCH_URL + (keyword ? '?keyword=' + encodeURIComponent(keyword) : '');
        }

        if (openBtn) openBtn.addEventListener('click', open);

        panel.addEventListener('click', function (event) {
            if (event.target === panel) close();
        });

        if (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submit();
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            const key = event.key;
            if ((event.ctrlKey || event.metaKey) && (key === 'k' || key === 'K')) {
                event.preventDefault();
                if (isOpen()) close(); else open();
                return;
            }
            if (key === 'Escape' && isOpen()) close();
        });
    }

    /* ------------------------------------------------------------------ *
     * 移动端抽屉
     * ------------------------------------------------------------------ */

    function initDrawer() {
        const toggle = document.getElementById('wd-drawer-toggle');
        const backdrop = document.getElementById('wd-backdrop');
        const sidebar = document.getElementById('wd-sidebar');
        if (!toggle) return;

        function setOpen(open) {
            document.body.classList.toggle('wd-drawer-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('wd-drawer-open'));
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setOpen(false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setOpen(false);
        });

        // 移动端点击导航后自动收起，避免遮挡内容
        if (sidebar) {
            sidebar.addEventListener('click', function (event) {
                if (event.target.closest('a') && window.matchMedia('(max-width: 1023px)').matches) {
                    setOpen(false);
                }
            });
        }
    }

    /* ------------------------------------------------------------------ *
     * 侧栏分类折叠
     * ------------------------------------------------------------------ */

    function initSidebarCollapse() {
        document.addEventListener('click', function (event) {
            const btn = event.target.closest('.wd-sidebar-toggle');
            if (!btn) return;

            event.preventDefault();

            const item = btn.closest('.wd-sidebar-item');
            if (!item) return;

            const willOpen = item.getAttribute('data-open') === 'false';
            item.setAttribute('data-open', willOpen ? 'true' : 'false');
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    }

    /* ------------------------------------------------------------------ *
     * 示例源码 Tab
     * ------------------------------------------------------------------ */

    function initTabs() {
        document.addEventListener('click', function (event) {
            const btn = event.target.closest('.wd-tabs__btn');
            if (!btn) return;

            const targetId = btn.getAttribute('data-tab-target');
            const tabs = btn.parentElement;
            const container = tabs ? tabs.parentElement : null;
            if (!targetId || !tabs || !container) return;

            tabs.querySelectorAll('.wd-tabs__btn').forEach(function (item) {
                item.classList.toggle('active', item === btn);
            });

            container.querySelectorAll('.wd-tab-panel').forEach(function (panel) {
                panel.classList.toggle('active', panel.id === targetId);
            });
        });
    }

    /* ------------------------------------------------------------------ *
     * 代码块：语言标签 + 复制
     * ------------------------------------------------------------------ */

    function enhanceCodeBlocks() {
        const scope = document.getElementById('docs-main');
        if (!scope) return;

        scope.querySelectorAll('pre').forEach(function (pre) {
            // 幂等：已增强过则跳过（局部切换后可能重复执行）
            if (pre.parentElement && pre.parentElement.classList.contains('wd-code-wrap')) return;

            const wrap = document.createElement('div');
            wrap.className = 'wd-code-wrap';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);

            const code = pre.querySelector('code');

            let lang = '';
            if (code && code.className) {
                const matched = String(code.className).match(/language-([\w+#-]+)/i);
                if (matched) lang = matched[1];
            }

            if (lang && lang.toLowerCase() !== 'text') {
                const label = document.createElement('span');
                label.className = 'wd-code-lang';
                label.textContent = lang;
                wrap.appendChild(label);
            }

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wd-code-copy';
            btn.title = '复制代码';
            btn.setAttribute('aria-label', '复制代码');
            btn.innerHTML = '<i class="bi bi-clipboard"></i>';

            btn.addEventListener('click', function () {
                copyText(code ? code.textContent : pre.textContent).then(function () {
                    btn.classList.add('copied');
                    btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                    window.setTimeout(function () {
                        btn.classList.remove('copied');
                        btn.innerHTML = '<i class="bi bi-clipboard"></i>';
                    }, 1600);
                });
            });

            wrap.appendChild(btn);
        });
    }

    /**
     * 语法高亮（自托管 highlight.js；仅在库已加载时执行，缺失时静默跳过）
     */
    function highlightCode() {
        if (window.hljs && typeof window.hljs.highlightAll === 'function') {
            window.hljs.highlightAll();
        }
    }

    /* ------------------------------------------------------------------ *
     * Markdown 正文渲染
     * ------------------------------------------------------------------ */

    /**
     * 把 #[id=docs-md] 里的 Markdown 原文渲染成 HTML
     *
     * 说明：
     *   - 渲染器复用**框架**的 ws-markdown（先转义再解析，无 XSS 面），
     *     不复制源码，因此框架升级该组件后这里自动受益；
     *   - 服务端已在块内输出原文作为降级内容（无 JS / 渲染失败时可读）；
     *   - 幂等：渲染成功后打标记，重复调用直接跳过。
     */
    function mountMarkdown() {
        const host = document.getElementById('docs-md');
        if (!host || host.dataset.mdRendered === '1') return;

        const source = host.textContent || '';
        if (!source.trim()) return;

        try {
            host.innerHTML = renderMarkdown(source);
            host.dataset.mdRendered = '1';
        } catch (e) {
            // 渲染失败：保留服务端输出的原文，不影响阅读
        }
    }

    /* ------------------------------------------------------------------ *
     * 本页目录（锚点导航 + 滚动高亮）
     * ------------------------------------------------------------------ */

    let spyBound = false;
    let spyIds = [];
    let spyTicking = false;

    function slugify(text, index) {
        const base = (text || '')
            .toLowerCase()
            .replace(/[^\w\u4e00-\u9fa5]+/g, '-')
            .replace(/^-+|-+$/g, '');
        return 'docs-head-' + (base ? base + '-' : '') + index;
    }

    function onSpyScroll() {
        if (spyTicking || !spyIds.length) return;
        spyTicking = true;
        requestAnimationFrame(function () {
            spyTicking = false;

            let active = spyIds[0];
            for (let i = 0; i < spyIds.length; i++) {
                const el = document.getElementById(spyIds[i]);
                if (!el) continue;
                if (el.getBoundingClientRect().top <= 120) {
                    active = spyIds[i];
                } else {
                    break;
                }
            }

            document.querySelectorAll('#docs-toc-list a').forEach(function (a) {
                a.classList.toggle('active', a.getAttribute('href') === '#' + active);
            });
        });
    }

    /**
     * 依据正文标题生成右侧目录
     */
    function buildToc() {
        const list = document.getElementById('docs-toc-list');
        const empty = document.getElementById('docs-toc-empty');
        const content = document.getElementById('docs-content');

        if (!list) return;

        list.innerHTML = '';
        spyIds = [];

        if (!content) {
            if (empty) empty.style.display = '';
            return;
        }

        const headings = content.querySelectorAll('h2, h3, h4');
        let index = 0;
        const items = [];

        headings.forEach(function (h) {
            const text = (h.textContent || '').trim();
            if (!text) return;

            if (!h.id) h.id = slugify(text, index);
            index++;

            items.push({ id: h.id, text: text, level: Number(h.tagName.substring(1)) });
        });

        if (!items.length) {
            if (empty) empty.style.display = '';
            return;
        }
        if (empty) empty.style.display = 'none';

        items.forEach(function (item) {
            const li = document.createElement('li');
            li.className = 'wd-outline__lv' + item.level;

            const a = document.createElement('a');
            a.className = 'wd-outline__link';
            a.href = '#' + item.id;
            a.textContent = item.text;
            a.addEventListener('click', function (event) {
                event.preventDefault();
                const target = document.getElementById(item.id);
                if (!target) return;
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (history.replaceState) history.replaceState(null, '', '#' + item.id);
            });

            li.appendChild(a);
            list.appendChild(li);
        });

        spyIds = items.map(function (i) { return i.id; });

        if (!spyBound) {
            spyBound = true;
            window.addEventListener('scroll', onSpyScroll, { passive: true });
            window.addEventListener('resize', onSpyScroll, { passive: true });
        }
        onSpyScroll();
    }

    /* ------------------------------------------------------------------ *
     * 详情页局部切换（不整页跳转）
     * ------------------------------------------------------------------ */

    function isPlainLeftClick(event) {
        return event.button === 0
            && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    function swapTo(url, push) {
        document.body.classList.add('docs-loading');

        fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Docs-Partial': '1',
            },
        })
            .then(function (res) {
                if (!res.ok) throw new Error('load failed');
                return res.text();
            })
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const freshMain = doc.querySelector('#docs-main');
                const currentMain = document.querySelector('#docs-main');

                if (!freshMain || !currentMain) {
                    window.location.href = url;
                    return;
                }

                currentMain.innerHTML = freshMain.innerHTML;

                // 侧栏（当前分类文档列表 / 高亮）也一并同步
                const freshSide = doc.querySelector('#wd-sidebar');
                const currentSide = document.querySelector('#wd-sidebar');
                if (freshSide && currentSide) currentSide.innerHTML = freshSide.innerHTML;

                if (doc.title) document.title = doc.title;
                if (push && history.pushState) history.pushState({ docs: url }, '', url);

                window.scrollTo({ top: 0, behavior: 'auto' });
                mountMarkdown();
                buildToc();
                enhanceCodeBlocks();
                highlightCode();
            })
            .catch(function () {
                window.location.href = url;
            })
            .finally(function () {
                document.body.classList.remove('docs-loading');
            });
    }

    /* ------------------------------------------------------------------ *
     * 事件委托
     * ------------------------------------------------------------------ */

    document.addEventListener('click', async function (event) {
        // 回到顶部
        const backtop = event.target.closest('.js-backtop');
        if (backtop) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        // 打开建议反馈弹窗
        const suggestTrigger = event.target.closest('.js-feedback-suggest');
        if (suggestTrigger) {
            const modalEl = document.getElementById('suggest-modal');
            if (modalEl && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
            return;
        }

        // 文档间局部切换（左侧导航 / 面包屑 / 上下一篇 / 相关文档）
        // 仅在详情页（存在 #docs-main 且该页有右侧大纲）内启用，其余页面保持普通跳转
        const navLink = event.target.closest('a[href*="' + DETAIL_URL_MARK + '"]');
        if (navLink && document.getElementById('docs-toc-list') && isPlainLeftClick(event)
            && navLink.target !== '_blank'
            && !event.defaultPrevented) {
            const href = navLink.getAttribute('href');
            if (href && href.indexOf(DETAIL_URL_MARK) !== -1) {
                event.preventDefault();
                swapTo(href, true);
                return;
            }
        }

        // 提交反馈（type: 1 有用 / 0 没用 / 2 建议）
        const btn = event.target.closest('.js-feedback');
        if (!btn) {
            return;
        }

        const type = Number(btn.getAttribute('data-type'));
        const documentId = btn.getAttribute('data-id');
        const sourceSelector = btn.getAttribute('data-comment-source');
        const comment = sourceSelector
            ? (document.querySelector(sourceSelector) || {}).value || ''
            : '';

        if (type === 2 && comment.trim() === '') {
            toast('请填写反馈内容', 'error');
            return;
        }

        btn.disabled = true;

        try {
            await postFeedback({ document_id: documentId, type: type, comment: comment });
            toast(type === 2 ? '感谢您的反馈' : '感谢您的评价', 'success');

            if (type === 1 || type === 0) {
                const selector = type === 1 ? '.js-helpful-count' : '.js-unhelpful-count';
                const counter = document.querySelector(selector);
                if (counter) {
                    counter.textContent = String((parseInt(counter.textContent, 10) || 0) + 1);
                }
            }

            const dismissSelector = btn.getAttribute('data-dismiss-modal');
            if (dismissSelector && window.bootstrap) {
                const modalEl = document.querySelector(dismissSelector);
                if (modalEl) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }
            }
        } catch (e) {
            toast(e.message || '提交失败', 'error');
        } finally {
            btn.disabled = false;
        }
    });

    // 浏览器前进/后退：整页刷新，保证各区域数据一致
    window.addEventListener('popstate', function () {
        window.location.reload();
    });

    /* ------------------------------------------------------------------ *
     * 初始化
     * ------------------------------------------------------------------ */

    function boot() {
        initThemeToggle();
        initSearchPanel();
        initDrawer();
        initSidebarCollapse();
        initTabs();
        mountMarkdown();
        buildToc();
        enhanceCodeBlocks();
        highlightCode();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
