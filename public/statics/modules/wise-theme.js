/**
 * Wise.theme — 后台主题管理器
 *
 * 主题三维度（正交，自由组合）：
 *   mode    明暗模式   light | dark | auto（auto 跟随系统 prefers-color-scheme）
 *   scheme  颜色主题   blue | green | red | purple
 *   sidebar 侧边栏模式 auto | dark | light（auto = 跟随 mode 解析结果）
 *
 * 职责：
 *   1. 应用主题到 <html> 的 data-bs-theme / data-color-scheme / data-ws-sidebar
 *   2. 持久化到 localStorage + Cookie（Cookie 供服务端首屏直出）
 *   3. 已登录用户防抖回写服务端（admins.ext_fields）
 *   4. 监听系统偏好变化（auto 模式实时跟随）
 *   5. 跨标签页同步（BroadcastChannel + storage 事件双保险）
 *   6. 对外广播 ws:theme:change 事件，供图表/表格等第三方组件重绘
 *
 * @module wise-theme
 * @version 1.0.0
 */

import { config, logger, eventBus } from './wise-core.js';

// ============================================================
// 常量
// ============================================================

/** localStorage 键名（客户端真相源） */
const STORAGE_KEY = 'wise.theme';
/** Cookie 键名（服务端首屏直出读取） */
const COOKIE_KEY = 'ws_theme';
/** Cookie 有效期（天） */
const COOKIE_DAYS = 365;
/** 跨标签页广播通道名 */
const CHANNEL_NAME = 'wise-theme';

/** 合法取值 */
const MODES = ['light', 'dark', 'auto'];
const SCHEMES = ['blue', 'green', 'red', 'purple'];
const SIDEBARS = ['auto', 'dark', 'light'];

/** 兜底默认值（暗色优先） */
const DEFAULTS = Object.freeze({ mode: 'dark', scheme: 'blue', sidebar: 'auto' });

/** 过渡动画时长（ms），需与 CSS .ws-theme-transition 保持一致 */
const TRANSITION_MS = 220;

/** 系统深色偏好查询 */
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

/** 服务端回写防抖定时器 */
let _syncTimer = null;
/** 过渡 class 移除定时器 */
let _transitionTimer = null;

/** 当前主题（已归一化，mode 保留 auto 原值） */
let _theme = { ...DEFAULTS };

/** 变更订阅者 */
const _listeners = new Set();

/** 跨标签页广播通道（不支持时为 null） */
let _channel = null;
try {
    if (typeof BroadcastChannel !== 'undefined') {
        _channel = new BroadcastChannel(CHANNEL_NAME);
    }
} catch (e) {
    _channel = null;
}

/** 是否已初始化（避免重复绑定） */
let _inited = false;

// ============================================================
// 工具函数
// ============================================================

/**
 * 归一化单个维度取值
 * @param {*} value
 * @param {string[]} allowed
 * @param {string} fallback
 * @returns {string}
 */
function _normalize(value, allowed, fallback) {
    return allowed.includes(value) ? value : fallback;
}

/**
 * 归一化完整主题对象
 * @param {Object} partial
 * @returns {{mode:string, scheme:string, sidebar:string}}
 */
function _normalizeTheme(partial) {
    const src = partial && typeof partial === 'object' ? partial : {};
    return {
        mode: _normalize(src.mode, MODES, DEFAULTS.mode),
        scheme: _normalize(src.scheme, SCHEMES, DEFAULTS.scheme),
        sidebar: _normalize(src.sidebar, SIDEBARS, DEFAULTS.sidebar),
    };
}

/**
 * 将 auto 解析为具体明暗模式
 * @param {string} mode
 * @returns {'light'|'dark'}
 */
function _resolveMode(mode) {
    if (mode !== 'auto') return mode;
    return prefersDark.matches ? 'dark' : 'light';
}

/**
 * 解析侧边栏实际模式
 * @param {string} sidebar
 * @param {string} resolvedMode 已解析的明暗模式
 * @returns {'light'|'dark'}
 */
function _resolveSidebar(sidebar, resolvedMode) {
    return sidebar === 'auto' ? resolvedMode : sidebar;
}

/**
 * 是否要求减少动效
 * @returns {boolean}
 */
function _prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * 读取 localStorage 中的主题
 * @returns {Object|null}
 */
function _readStorage() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (e) {
        logger.warn('theme', '读取本地主题失败，回退默认值', e.message);
        return null;
    }
}

/**
 * 写入 localStorage
 * @param {Object} theme
 */
function _writeStorage(theme) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(theme));
    } catch (e) {
        logger.warn('theme', '写入本地主题失败（可能是隐私模式）', e.message);
    }
}

/**
 * 写入 Cookie，供服务端首屏直出读取
 * @param {Object} theme
 */
function _writeCookie(theme) {
    try {
        const value = encodeURIComponent(JSON.stringify(theme));
        const secure = window.location.protocol === 'https:' ? '; secure' : '';
        document.cookie = `${COOKIE_KEY}=${value}; path=/; max-age=${COOKIE_DAYS * 86400}; samesite=Lax${secure}`;
    } catch (e) {
        logger.warn('theme', '写入主题 Cookie 失败', e.message);
    }
}

/**
 * 获取 CSRF Token
 *
 * 优先读 Cookie：VerifyCsrfToken 在每次写请求校验通过后都会轮换 Token 并写回
 * Cookie（httponly=false，JS 可读），而 <meta name="csrf-token"> 只是服务端
 * 渲染时的快照，保存过一次之后即失效。故必须走 Cookie 才能连续多次保存。
 *
 * @returns {string}
 */
function _getCsrfToken() {
    const cookieKey = config.get('csrfCookieKey', 'csrf_token');
    const escaped = cookieKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
    if (match && match[1]) {
        return decodeURIComponent(match[1]);
    }

    // 兜底：Cookie 不可用（如 httponly 被改回 true）时退回 meta 快照
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
}

// ============================================================
// 应用主题
// ============================================================

/**
 * 将主题应用到 DOM
 *
 * @param {Object} theme      归一化后的主题
 * @param {Object} [options]
 * @param {boolean} [options.animate=false] 是否启用过渡动画
 * @returns {{mode:string, scheme:string, sidebar:string}} 解析后的实际值
 */
function _applyToDom(theme, options = {}) {
    const root = document.documentElement;
    if (!root) return { mode: 'dark', scheme: theme.scheme, sidebar: 'dark' };

    const mode = _resolveMode(theme.mode);
    const sidebar = _resolveSidebar(theme.sidebar, mode);

    if (options.animate && !_prefersReducedMotion()) {
        root.classList.add('ws-theme-transition');
        clearTimeout(_transitionTimer);
        _transitionTimer = setTimeout(() => {
            root.classList.remove('ws-theme-transition');
        }, TRANSITION_MS);
    }

    root.setAttribute('data-bs-theme', mode);
    root.setAttribute('data-color-scheme', theme.scheme);
    root.setAttribute('data-ws-sidebar', sidebar);
    // 同步原生 color-scheme，让滚动条/表单控件/默认背景跟随
    root.style.colorScheme = mode;

    _syncSidebarScope(sidebar, theme.scheme);

    return { mode, scheme: theme.scheme, sidebar };
}

/**
 * 同步侧边栏的独立明暗上下文
 *
 * 侧边栏可独立于主区域设为深色（后台常见「深侧栏 + 亮内容」布局）。
 * 给这些元素同步 data-bs-theme 后，其内部的 --bs-* 由 Bootstrap 局部重建，
 * --lte-sidebar-* 则由 theme.css 映射 --ws-sb-* 提供，两者缺一不可。
 *
 * 同时必须同步 data-color-scheme：品牌色由
 * [data-color-scheme][data-bs-theme] 复合选择器定义，侧边栏自身不带配色
 * 方案属性时，菜单高亮色会沿用 <html> 的品牌色（亮色主题下是深蓝，
 * 压在深底上几乎不可见）。
 *
 * @param {'light'|'dark'} sidebar
 * @param {string} [scheme] 配色方案（blue/green/red/purple）
 */
function _syncSidebarScope(sidebar, scheme) {
    const scopes = document.querySelectorAll('[data-ws-theme-scope="sidebar"]');
    scopes.forEach(el => {
        el.setAttribute('data-bs-theme', sidebar);
        if (scheme) {
            el.setAttribute('data-color-scheme', scheme);
        }
    });
}

/**
 * 广播主题变更
 * @param {Object} resolved 解析后的实际值
 * @param {Object} theme    归一化主题
 */
function _emit(resolved, theme) {
    const payload = { ...theme, resolved };

    _listeners.forEach(fn => {
        try {
            fn(payload);
        } catch (e) {
            logger.error('theme', '主题变更订阅者执行异常', e.message);
        }
    });

    eventBus.emit('ws:theme:change', payload);
}

// ============================================================
// 服务端同步
// ============================================================

/**
 * 防抖回写服务端（已登录用户）
 * @param {Object} theme
 */
function _syncToServer(theme) {
    clearTimeout(_syncTimer);
    _syncTimer = setTimeout(() => _doSync(theme), 800);
}

/**
 * 实际发起服务端保存请求
 * @param {Object} theme
 */
async function _doSync(theme) {
    const moduleurl = config.get('moduleurl', '');
    const url = `${moduleurl}/index/saveTheme`;

    try {
        const resp = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': _getCsrfToken(),
            },
            body: JSON.stringify({
                mode: theme.mode,
                scheme: theme.scheme,
                sidebar: theme.sidebar,
            }),
        });

        if (!resp.ok) {
            logger.warn('theme', `主题保存失败 HTTP ${resp.status}`);
            return;
        }

        const json = await resp.json().catch(() => null);
        // JsonResponse 约定：code=0 成功，非 0 为业务失败
        if (!json || Number(json.code) !== 0) {
            logger.warn('theme', '主题保存被拒绝', json ? json.msg : `HTTP ${resp.status}`);
            return;
        }

        logger.info('theme', '主题已保存到服务端');

        // 以服务端规范化后的值为权威，回写本地，避免跨端/跨浏览器不一致
        if (json.data && json.data.theme) {
            const serverTheme = _normalizeTheme(json.data.theme);
            if (JSON.stringify(serverTheme) !== JSON.stringify(_theme)) {
                _theme = serverTheme;
                _writeStorage(_theme);
                _writeCookie(_theme);
                const resolved = _applyToDom(_theme, { animate: true });
                _emit(resolved, _theme);
            }
        }
    } catch (e) {
        // 离线或未登录时静默降级为本地存储
        logger.warn('theme', '主题保存失败，已降级为本地存储', e.message);
    }
}

// ============================================================
// 对外 API
// ============================================================

export const theme = {
    /** 合法取值集合（供 UI 渲染使用） */
    MODES,
    SCHEMES,
    SIDEBARS,
    STORAGE_KEY,
    COOKIE_KEY,

    /**
     * 初始化：合并服务端下发值与本地存储值，应用主题并绑定监听
     *
     * 取值优先级：localStorage > 服务端注入 > 默认值
     *
     * @returns {{mode:string, scheme:string, sidebar:string}}
     */
    init() {
        if (_inited) return _theme;

        // 1. 服务端注入值（引导脚本已写入 window.__WS_THEME__）
        const serverTheme = _normalizeTheme(window.__WS_THEME__ || null);
        // 2. 本地存储值（用户在当前浏览器的最近选择）
        const localTheme = _readStorage();

        _theme = localTheme ? _normalizeTheme(localTheme) : serverTheme;

        // 首次访问无本地值时，把服务端值沉淀到本地，保证后续加载一致
        if (!localTheme) {
            _writeStorage(_theme);
            _writeCookie(_theme);
        }

        const resolved = _applyToDom(_theme);

        // 3. 绑定系统偏好变化（auto 模式实时跟随）
        const onSchemeChange = () => {
            if (_theme.mode !== 'auto' && _theme.sidebar !== 'auto') return;
            const next = _applyToDom(_theme, { animate: true });
            _emit(next, _theme);
        };
        if (typeof prefersDark.addEventListener === 'function') {
            prefersDark.addEventListener('change', onSchemeChange);
        } else if (typeof prefersDark.addListener === 'function') {
            // Safari < 14 兼容
            prefersDark.addListener(onSchemeChange);
        }

        // 4. 跨标签页同步
        window.addEventListener('storage', e => {
            if (e.key !== STORAGE_KEY || !e.newValue) return;
            try {
                const incoming = _normalizeTheme(JSON.parse(e.newValue));
                _theme = incoming;
                const next = _applyToDom(_theme, { animate: true });
                _emit(next, _theme);
            } catch (err) {
                logger.warn('theme', '跨标签页主题同步失败', err.message);
            }
        });

        if (_channel) {
            _channel.onmessage = e => {
                const data = e.data;
                if (!data || data.type !== 'theme-change') return;
                _theme = _normalizeTheme(data.theme);
                const next = _applyToDom(_theme, { animate: true });
                _emit(next, _theme);
            };
        }

        // 5. 兜底：延迟渲染的侧边栏（如插件动态挂载）补一次作用域同步
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.applyScopes());
        }

        _inited = true;
        logger.info('theme', `主题已就绪: ${_theme.mode}/${_theme.scheme}/${_theme.sidebar}`);

        return resolved;
    },

    /**
     * 获取当前主题（mode 保留 auto 原值）
     * @returns {{mode:string, scheme:string, sidebar:string}}
     */
    get() {
        return { ..._theme };
    },

    /**
     * 获取解析后的实际主题（auto 已展开为 light/dark）
     * @returns {{mode:'light'|'dark', scheme:string, sidebar:'light'|'dark'}}
     */
    resolve() {
        const mode = _resolveMode(_theme.mode);
        return {
            mode,
            scheme: _theme.scheme,
            sidebar: _resolveSidebar(_theme.sidebar, mode),
        };
    },

    /**
     * 设置明暗模式
     * @param {'light'|'dark'|'auto'} mode
     * @returns {Object} 解析后的主题
     */
    setMode(mode) {
        return this.set({ mode });
    },

    /**
     * 在 light / dark 之间快捷切换
     * @returns {Object} 解析后的主题
     */
    toggleMode() {
        const current = _resolveMode(_theme.mode);
        return this.set({ mode: current === 'dark' ? 'light' : 'dark' });
    },

    /**
     * 设置颜色主题
     * @param {string} scheme
     * @returns {Object} 解析后的主题
     */
    setScheme(scheme) {
        return this.set({ scheme });
    },

    /**
     * 设置侧边栏模式
     * @param {'auto'|'dark'|'light'} sidebar
     * @returns {Object} 解析后的主题
     */
    setSidebar(sidebar) {
        return this.set({ sidebar });
    },

    /**
     * 批量设置并持久化
     *
     * @param {Object} values 键为 mode / scheme / sidebar，未传的维度保持不变
     * @param {Object} [options]
     * @param {boolean} [options.animate=true] 是否启用过渡动画
     * @param {boolean} [options.sync=true]    是否回写服务端
     * @returns {Object} 解析后的主题
     */
    set(values, options = {}) {
        const { animate = true, sync = true } = options;

        const next = _normalizeTheme({ ..._theme, ...values });
        const changed = next.mode !== _theme.mode
            || next.scheme !== _theme.scheme
            || next.sidebar !== _theme.sidebar;

        _theme = next;

        const resolved = _applyToDom(_theme, { animate: animate && changed });

        if (changed) {
            _writeStorage(_theme);
            _writeCookie(_theme);

            if (sync) {
                _syncToServer(_theme);
            }

            // 通知其他标签页
            if (_channel) {
                try {
                    _channel.postMessage({ type: 'theme-change', theme: _theme });
                } catch (e) {
                    // 忽略广播失败
                }
            }

            _emit(resolved, _theme);
        }

        return resolved;
    },

    /**
     * 重置为网站默认配置（清除用户级覆盖）
     *
     * @returns {Promise<Object>} 解析后的主题
     */
    async reset() {
        const moduleurl = config.get('moduleurl', '');
        try {
            const resp = await fetch(`${moduleurl}/index/resetTheme`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': _getCsrfToken(),
                },
            });
            const json = await resp.json().catch(() => null);
            if (json && json.data && json.data.theme) {
                _theme = _normalizeTheme(json.data.theme);
                _writeStorage(_theme);
                _writeCookie(_theme);
                const resolved = _applyToDom(_theme, { animate: true });
                _emit(resolved, _theme);
                return resolved;
            }
        } catch (e) {
            logger.warn('theme', '重置主题失败，保留当前设置', e.message);
        }
        return this.resolve();
    },

    /**
     * 重新应用侧边栏等局部作用域
     *
     * 动态插入到 DOM 的侧边栏元素需要调用此方法补挂 data-bs-theme。
     *
     * @returns {void}
     */
    applyScopes() {
        const sidebar = _resolveSidebar(_theme.sidebar, _resolveMode(_theme.mode));
        _syncSidebarScope(sidebar, _theme.scheme);
    },

    /**
     * 订阅主题变更
     * @param {Function} handler 接收 {mode, scheme, sidebar, resolved}
     * @returns {Function} 取消订阅函数
     */
    onChange(handler) {
        if (typeof handler !== 'function') return () => {};
        _listeners.add(handler);
        return () => _listeners.delete(handler);
    },

    /**
     * 系统是否处于深色偏好（供 UI 展示「跟随系统」当前实际效果）
     * @returns {boolean}
     */
    systemPrefersDark() {
        return prefersDark.matches;
    },
};

export default theme;
