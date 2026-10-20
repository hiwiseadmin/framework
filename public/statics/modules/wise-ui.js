/**
 * Wise.ui — UI 组件门面层
 * 封装 ws-* 组件的统一 API：Toast / Dialog / Notify / ContextMenu / Hotkey / Clipboard
 *
 * 底层仍然依赖 window 上的 ws-* 全局对象，不重写内部逻辑。
 *
 * @module wise-ui
 * @version 1.0.0
 * @license MIT
 */

import { config, logger } from './wise-core.js';
import { type, http } from './wise-util.js';

// ============================================================
// Toast 提示
// ============================================================

const TOAST_CONTAINER_ID = 'ws-toast-container';
const TOAST_ICONS = {
    success: 'bi-check-circle-fill',
    error: 'bi-x-circle-fill',
    warning: 'bi-exclamation-triangle-fill',
    info: 'bi-info-circle-fill',
};
const TOAST_TYPES = Object.keys(TOAST_ICONS);

/**
 * 创建（或复用）Toast 容器
 * @returns {HTMLElement}
 */
function _ensureContainer() {
    let container = document.getElementById(TOAST_CONTAINER_ID);
    if (!container) {
        container = document.createElement('div');
        container.id = TOAST_CONTAINER_ID;
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    return container;
}

const toast = {
    /**
     * 显示 Toast
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} [type='info']
     * @param {Object} [options]
     * @param {number} [options.duration=5000] - 显示毫秒数
     * @param {string} [options.title] - 标题
     * @param {string} [options.position='top-end'] - 位置（wsLayer 模式下生效）
     * @param {Function} [options.onEnd] - 关闭后回调（wsLayer 模式下生效）
     */
    show(message, type = 'info', options = {}) {
        if (!TOAST_TYPES.includes(type)) type = 'info';
        const { duration = config.get('toast.duration', 5000), title = '' } = options;

        // 1. 优先使用 ws-layer 的 toast
        if (typeof window.wsLayer !== 'undefined' && window.wsLayer.toast) {
            return window.wsLayer.toast({
                content: message,
                type: type,
                time: duration,
                position: options.position || 'top-end',
                end: options.onEnd || null,
            });
        }

        // 2. 兜底：Bootstrap Toast DOM 实现
        const container = _ensureContainer();
        const id = `ws-toast-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        const wrapper = document.createElement('div');
        wrapper.id = id;
        wrapper.className = `toast fade show`;
        wrapper.setAttribute('role', 'alert');
        wrapper.setAttribute('aria-live', 'assertive');

        wrapper.innerHTML = `
            <div class="toast-header bg-${type} text-white">
                <i class="bi ${TOAST_ICONS[type]} me-2"></i>
                <strong class="me-auto">${title || type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">${message}</div>
        `;

        container.appendChild(wrapper);

        // Bootstrap Toast 显示
        if (typeof window.bootstrap !== 'undefined' && window.bootstrap.Toast) {
            const bsToast = new window.bootstrap.Toast(wrapper, { delay: duration });
            bsToast.show();
            wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
        }

        // 兜底：超时后自动移除
        setTimeout(() => {
            if (wrapper.parentNode) wrapper.remove();
        }, duration + 1000);
    },

    success(msg, opts) { this.show(msg, 'success', opts); },
    error(msg, opts)   { this.show(msg, 'error', opts); },
    warning(msg, opts) { this.show(msg, 'warning', opts); },
    info(msg, opts)    { this.show(msg, 'info', opts); },

    /**
     * 显示带操作按钮（如"撤销"）的 Toast
     *
     * 返回 Promise，在 Toast 关闭时 resolve。
     * 用户点击操作按钮 → { undone: true }
     * 超时自动关闭       → { undone: false }
     *
     * @param {string} message - 提示消息
     * @param {Object} [options]
     * @param {string} [options.actionText='撤销'] - 操作按钮文本
     * @param {number} [options.duration=6000] - 撤销窗口毫秒数
     * @param {'success'|'warning'|'info'} [options.type='info'] - 视觉风格
     * @returns {Promise<{undone: boolean}>}
     */
    action(message, options = {}) {
        const {
            actionText = '撤销',
            duration = 6000,
            type = 'info',
        } = options;

        return new Promise(resolve => {
            const container = _ensureContainer();
            const id = `ws-toast-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

            const wrapper = document.createElement('div');
            wrapper.id = id;
            wrapper.className = 'toast fade show';
            wrapper.setAttribute('role', 'alert');

            const bgClass = `bg-${type} text-white`;
            const iconClass = TOAST_ICONS[type] || TOAST_ICONS.info;

            wrapper.innerHTML = `
                <div class="toast-header ${bgClass}">
                    <i class="bi ${iconClass} me-2"></i>
                    <strong class="me-auto">提示</strong>
                    <small class="opacity-75">${duration / 1000}s 内可${actionText}</small>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body d-flex justify-content-between align-items-center">
                    <span>${message}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary ws-toast-action" data-action="${actionText}">${actionText}</button>
                </div>
            `;

            container.appendChild(wrapper);

            let resolved = false;
            const finish = (undone) => {
                if (resolved) return;
                resolved = true;
                if (wrapper.parentNode) wrapper.remove();
                clearTimeout(timer);
                resolve({ undone });
            };

            // 撤销按钮点击
            const actionBtn = wrapper.querySelector('.ws-toast-action');
            if (actionBtn) {
                actionBtn.addEventListener('click', () => finish(true));
            }

            // 超时自动关闭
            const timer = setTimeout(() => finish(false), duration);

            // Bootstrap Toast hide event
            wrapper.addEventListener('hidden.bs.toast', () => finish(false));

            // 兜底：确保 toast 被移除
            setTimeout(() => {
                if (wrapper.parentNode) wrapper.remove();
            }, duration + 2000);
        });
    },
};

// ============================================================
// Dialog 对话框（封装 ws-layer）
// ============================================================

const dialog = {
    /**
     * 提示框
     * @param {string} message
     * @param {Object} [options]
     * @param {string} [options.title] - 标题
     * @returns {Promise<void>}
     */
    alert(message, options = {}) {
        return new Promise(resolve => {
            // 1. 优先使用 ws-layer
            if (typeof window.wsLayer !== 'undefined' && window.wsLayer.alert) {
                window.wsLayer.alert(message, { title: options.title || '提示' }, () => resolve());
                return;
            }

            // 2. 兜底：浏览器原生 alert
            if (window.alert) {
                window.alert(message);
                resolve();
                return;
            }
        });
    },

    /**
     * 确认框
     * @param {string} message
     * @param {Object} [options]
     * @param {string} [options.title] - 标题
     * @returns {Promise<boolean>}
     */
    confirm(message, options = {}) {
        return new Promise(resolve => {
            // 1. 优先使用 ws-layer
            if (typeof window.wsLayer !== 'undefined' && window.wsLayer.confirm) {
                window.wsLayer.confirm(
                    message,
                    { title: options.title || '确认', btn: ['确定', '取消'], icon: 'danger'},
                    () => { resolve(true); return true; },
                    () => { resolve(false); return true; }
                );
                return;
            }

            // 2. 兜底：浏览器原生 confirm
            if (window.confirm) {
                resolve(window.confirm(message));
                return;
            }
        });
    },

    /**
     * 打开弹窗（通过 ws-layer）
     * @param {string|Object} content - URL 或 HTML 内容
     * @param {string} [title]
     * @param {Object} [options] - 透传给 ws-layer.open()
     */
    open(content, title, options = {}) {
        // 尝试使用 ws-layer
        if (typeof window.wsLayer !== 'undefined' && window.wsLayer.open) {
            return window.wsLayer.open({
                type: typeof content === 'string' && content.startsWith('/') ? 2 : 1,
                title,
                content,
                area: options.area || ['800px', '500px'],
                ...options,
            });
        }

        // 兜底：Bootstrap Modal
        if (typeof window.bootstrap !== 'undefined' && window.bootstrap.Modal) {
            const modalEl = document.getElementById('ws-modal');
            if (modalEl) {
                const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                bsModal.show();
            }
            return;
        }

        logger.warn('ui', '未找到 ws-layer 或 Bootstrap Modal，弹窗不可用');
    },

    /**
     * 关闭弹窗
     * @param {number} [index]
     */
    close(index) {
        if (typeof window.wsLayer !== 'undefined' && window.wsLayer.close) {
            window.wsLayer.close(index);
        }
    },

    /** 关闭全部 */
    closeAll() {
        this.close();
    },
};

// ============================================================
// 浏览器通知
// ============================================================

const notify = {
    /**
     * 发送浏览器通知（需要权限）
     * @param {string} title
     * @param {Object} [options] - { body, icon }
     */
    send(title, options = {}) {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') {
            new Notification(title, options);
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then(p => {
                if (p === 'granted') new Notification(title, options);
            });
        }
    },

    /**
     * 页面内 Banner 通知
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} [type]
     */
    banner(message, type = 'info') {
        toast.show(message, type, { duration: 8000 });

        // 同时触发事件总线
        import('./wise-core.js').then(({ eventBus }) => {
            eventBus.emit('ws:ui:notify', { message, type });
        });
    },
};

// ============================================================
// 右键菜单
// ============================================================

let _ctxMenuEl = null;
let _ctxMenuCleanup = null;
let _ctxMenuInstance = null; // WsPopMenu 实例引用

const contextMenu = {
    /**
     * 显示右键菜单
     * @param {Array<{label:string,icon?:string,action?:Function,id?:string,divider?:boolean,disabled?:boolean}>} items
     * @param {Object} [options]
     * @param {number} [options.x] - 坐标
     * @param {number} [options.y]
     */
    show(items, options = {}) {
        this.hide();

        // 1. 优先使用 ws-popmenu
        if (typeof window.WsPopMenu !== 'undefined') {
            const mappedItems = items.map(item => {
                if (item.divider) {
                    return { type: 'divider' };
                }
                return {
                    text: item.label,
                    icon: item.icon || '',
                    id: item.id || '',
                    disabled: !!item.disabled,
                    type: 'item',
                };
            });

            const menu = new window.WsPopMenu({
                items: mappedItems,
                trigger: 'contextmenu',
                offsetX: 0,
                offsetY: 0,
                autoClose: true,
                onItemClick: (id) => {
                    const item = items.find(i => i.id === id);
                    if (item && item.action) item.action();
                },
                onHide: () => {
                    // 菜单隐藏后清理
                    if (_ctxMenuCleanup) {
                        const cleanup = _ctxMenuCleanup;
                        _ctxMenuCleanup = null;
                        _ctxMenuInstance = null;
                        cleanup();
                    }
                },
            });

            menu.updatePosition(options.x || 0, options.y || 0);
            menu.show();
            _ctxMenuInstance = menu;
            _ctxMenuCleanup = () => menu.destroy();
            return;
        }

        // 2. 兜底：Bootstrap dropdown-menu 实现
        _ctxMenuEl = document.createElement('div');
        _ctxMenuEl.className = 'dropdown-menu show position-fixed';
        _ctxMenuEl.style.cssText = `
            z-index: 9999;
            top: ${(options.y || 0)}px;
            left: ${(options.x || 0)}px;
        `;

        items.forEach(item => {
            if (item.divider) {
                _ctxMenuEl.appendChild(document.createElement('hr')).className = 'dropdown-divider';
                return;
            }
            const a = document.createElement('a');
            a.className = `dropdown-item${item.disabled ? ' disabled' : ''}`;
            a.href = '#';
            a.textContent = item.label;
            if (item.icon) {
                a.innerHTML = `<i class="bi ${item.icon} me-2"></i>${item.label}`;
            }
            if (!item.disabled && item.action) {
                a.addEventListener('click', e => {
                    e.preventDefault();
                    this.hide();
                    item.action(e);
                });
            } else {
                a.addEventListener('click', e => e.preventDefault());
            }
            _ctxMenuEl.appendChild(a);
        });

        document.body.appendChild(_ctxMenuEl);

        const handler = (e) => {
            if (!_ctxMenuEl.contains(e.target)) this.hide();
        };
        document.addEventListener('click', handler);
        _ctxMenuCleanup = () => document.removeEventListener('click', handler);
    },

    hide() {
        if (_ctxMenuEl && _ctxMenuEl.parentNode) {
            _ctxMenuEl.parentNode.removeChild(_ctxMenuEl);
        }
        if (_ctxMenuCleanup) {
            _ctxMenuCleanup();
            _ctxMenuCleanup = null;
        }
        _ctxMenuEl = null;
        _ctxMenuInstance = null;
    },
};

// ============================================================
// 快捷键管理
// ============================================================

const _hotkeys = {};
let _hotkeyInstance = null;
/** @type {Object<string, string>} keys → HotKey id 映射 */
const _hotkeyBindingMap = {};

const hotkey = {
    /**
     * 获取（或创建）HotKey 实例
     * @returns {Object|null}
     */
    _getInstance() {
        if (!_hotkeyInstance && typeof window.HotKey !== 'undefined') {
            _hotkeyInstance = new window.HotKey('wise-ui');
        }
        return _hotkeyInstance;
    },

    /**
     * 绑定快捷键
     * @param {string} keys - 'Shift+S' / 'Ctrl+Alt+D'
     * @param {Function} handler
     * @param {Object} [options]
     * @param {string} [options.description] - 快捷键说明
     */
    bind(keys, handler, options = {}) {
        // 1. 优先使用 HotKey 类
        const inst = this._getInstance();
        if (inst) {
            const normalizedKeys = keys.toLowerCase();
            const id = inst.register(normalizedKeys, handler, options.description || '');
            _hotkeyBindingMap[normalizedKeys] = id;
            return id;
        }

        // 2. 兜底：简单 keydown 匹配
        const id = keys.toLowerCase().replace(/\+/g, '-');
        _hotkeys[id] = { keys, handler, options };
    },

    /**
     * 解除绑定
     * @param {string} keys
     */
    unbind(keys) {
        const normalizedKeys = keys.toLowerCase();

        // 1. HotKey 实例
        const inst = this._getInstance();
        if (inst && _hotkeyBindingMap[normalizedKeys]) {
            inst.unregister(_hotkeyBindingMap[normalizedKeys]);
            delete _hotkeyBindingMap[normalizedKeys];
            return;
        }

        // 2. 兜底
        const id = normalizedKeys.replace(/\+/g, '-');
        delete _hotkeys[id];
    },

    /** 临时禁用所有快捷键 */
    suspend() {
        const inst = this._getInstance();
        if (inst) { inst.setEnabled(false); return; }
        this._suspended = true;
    },

    /** 恢复快捷键 */
    resume() {
        const inst = this._getInstance();
        if (inst) { inst.setEnabled(true); return; }
        this._suspended = false;
    },
};

// 全局键盘监听
document.addEventListener('keydown', function (e) {
    if (hotkey._suspended) return;
    const target = e.target;
    if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT') return;

    const parts = [];
    if (e.ctrlKey || e.metaKey) parts.push('Ctrl');
    if (e.altKey) parts.push('Alt');
    if (e.shiftKey) parts.push('Shift');
    const key = e.key.length === 1 ? e.key.toUpperCase() : e.key;
    if (!['Control', 'Alt', 'Shift'].includes(key)) parts.push(key);

    const id = parts.join('+').toLowerCase();
    const entry = _hotkeys[id];
    if (entry) {
        e.preventDefault();
        entry.handler(e);
    }
});

// ============================================================
// 剪贴板
// ============================================================

const clipboard = {
    /**
     * 复制文本到剪贴板
     * @param {string} text
     * @param {Object} [options] - 透传给底层 Clipboard 类
     * @returns {Promise<boolean>}
     */
    async copy(text, options = {}) {
        // 1. 优先使用 Clipboard 类的静态方法
        if (typeof window.Clipboard !== 'undefined' && window.Clipboard.copy) {
            return await window.Clipboard.copy(text, options);
        }

        // 2. 兜底：Navigator.clipboard API + textarea 降级
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
            // 传统 textarea 降级
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            return true;
        } catch {
            logger.warn('ui', '复制到剪贴板失败');
            return false;
        }
    },

    /**
     * 读取剪贴板文本
     * @returns {Promise<string>}
     */
    async read() {
        try {
            if (navigator.clipboard && navigator.clipboard.readText) {
                return await navigator.clipboard.readText();
            }
            return '';
        } catch {
            return '';
        }
    },

    /**
     * 检查剪贴板是否可用
     * @param {'copy'|'cut'} [action='copy']
     * @returns {boolean}
     */
    isSupported(action = 'copy') {
        if (typeof window.Clipboard !== 'undefined' && window.Clipboard.isSupported) {
            return window.Clipboard.isSupported(action);
        }
        return !!(navigator.clipboard && navigator.clipboard.writeText);
    },
};

// ============================================================
// 全局请求指示器
// ============================================================
//
// 计数器式：并发请求只显示一次，全部结束才收起。
// 形态是页面顶部进度条（.ws-busy-bar，样式见 statics/css/base.css），
// **刻意不用全屏遮罩** —— 全屏遮罩会阻断用户继续操作，对列表页这类
// 高频交互是倒退。页面首屏遮罩由 base.html 的 .loading-overlay 负责，
// 二者职责不同，不要混用。
//
// 消费方：wise-framework.js 注册为 http 的全局 loading handler，
// 因此 `http.request(url, { loading: true })` 与 ui.action.execute()
// 都会自动显示/隐藏它。

const BUSY_BAR_ID = 'ws-busy-bar';
let _busyCount = 0;

function _ensureBusyBar() {
    let el = document.getElementById(BUSY_BAR_ID);
    if (!el && document.body) {
        el = document.createElement('div');
        el.id = BUSY_BAR_ID;
        el.className = 'ws-busy-bar';
        el.setAttribute('role', 'progressbar');
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = '<div class="ws-busy-bar-inner"></div>';
        document.body.appendChild(el);
    }
    return el;
}

function _syncBusyBar() {
    const active = _busyCount > 0;
    const el = _ensureBusyBar();
    if (el) {
        el.classList.toggle('is-active', active);
        el.setAttribute('aria-hidden', active ? 'false' : 'true');
    }
    if (document.body) document.body.classList.toggle('ws-busy', active);
}

const loading = {
    /** 显示（可嵌套调用，计数归零才真正收起） */
    show() {
        _busyCount++;
        if (_busyCount === 1) _syncBusyBar();
    },

    /** 隐藏（与 show 配对） */
    hide() {
        _busyCount = Math.max(0, _busyCount - 1);
        if (_busyCount === 0) _syncBusyBar();
    },

    /** 强制复位（异常兜底，避免计数泄漏导致指示器永不消失） */
    reset() {
        _busyCount = 0;
        _syncBusyBar();
    },

    /** 当前挂起的请求数，便于排障 */
    get count() {
        return _busyCount;
    },
};

// ============================================================
// Action 操作包装器 — 统一 Confirm → Loading → Execute → Feedback 链路
// ============================================================

/**
 * 进入/退出按钮 loading 状态
 * 使用 CSS data-ws-state 属性统一管理，样式由 ws-state.css 提供
 */
function _enterLoading(target) {
    if (!target) return () => {};
    target.setAttribute('data-ws-state', 'loading');
    target.classList.add('ws-btn-loading');
    target.disabled = true;
    return () => {
        target.removeAttribute('data-ws-state');
        target.classList.remove('ws-btn-loading');
        target.disabled = false;
    };
}

const action = {
    /**
     * 删除操作（带撤销）
     *
     * 流程：
     * 1. 弹出确认框 (dialog.confirm)
     * 2. 调用删除 API
     * 3. 显示带撤销按钮的 Toast
     *    — 用户点击撤销 → 调 restore API → Toast 提示恢复结果
     *    — 超时自动消失 → 不额外操作
     *
     * @param {Object} spec
     * @param {string} spec.url - 删除 API 地址
     * @param {Object} spec.data - 请求数据，{ id: number } 或 { ids: number[] }
     * @param {string} spec.restoreUrl - 恢复 API 地址
     * @param {string} [spec.message] - 自定义成功提示
     * @param {string} [spec.restoreData] - 恢复请求的 body（默认复用 data）
     * @param {string} [spec.confirmMessage] - 确认提示文本
     * @param {string} [spec.confirmTitle] - 确认提示标题
     * @param {number} [spec.undoDuration=5000] - 撤销窗口毫秒数
     * @param {HTMLElement} [spec.target] - 触发按钮元素（自动 loading 态）
     * @returns {Promise<{success: boolean, undone: boolean}>}
     */
    async delete(spec) {
        const {
            url, data, restoreUrl,
            message = '删除成功',
            restoreData = null,
            confirmMessage = '确定要删除选中的记录吗？',
            confirmTitle = '确认删除',
            undoDuration = 5000,
            target = null,
        } = spec;

        // 1. 确认
        const confirmed = await dialog.confirm(confirmMessage, { title: confirmTitle });
        if (!confirmed) return { success: false, undone: false };

        // 2. Loading
        const exitLoading = _enterLoading(target);

        try {
            // 3. 执行删除
            await http.post(url, data);

            exitLoading();

            // 4. 撤销 Toast
            const count = data.ids ? data.ids.length : (data.id ? 1 : 0);
            const displayMsg = message || (count > 1 ? `已删除 ${count} 条记录` : '已删除');

            const { undone } = await toast.action(displayMsg, {
                actionText: '撤销',
                duration: undoDuration,
                type: 'warning',
            });

            if (undone && restoreUrl) {
                const restorePayload = restoreData || data;
                try {
                    await http.post(restoreUrl, restorePayload);
                    toast.success(count > 1 ? `已恢复 ${count} 条记录` : '已恢复');
                    return { success: true, undone: true };
                } catch (e) {
                    toast.error('恢复失败：' + (e.message || '未知错误'));
                    return { success: true, undone: false };
                }
            }

            return { success: true, undone: false };
        } catch (e) {
            exitLoading();
            toast.error(e.message || '操作失败');
            return { success: false, undone: false };
        }
    },

    /**
     * 通用操作包装器
     *
     * 流程：Loading → Execute → Toast (success/error)
     *
     * @param {Object} spec
     * @param {string} spec.method - HTTP 方法
     * @param {string} spec.url - API 地址
     * @param {Object} [spec.data] - 请求体
     * @param {string} [spec.successMsg='操作成功'] - 成功提示
     * @param {string} [spec.errorMsg] - 失败提示
     * @param {string} [spec.confirmMessage] - 确认提示（有则先弹确认框）
     * @param {string} [spec.confirmTitle] - 确认标题
     * @param {HTMLElement} [spec.target] - 触发按钮元素
     * @param {'success'|'warning'|'info'} [spec.toastType='success']
     * @returns {Promise<{success: boolean, data?: any}>}
     */
    async execute(spec) {
        const {
            method = 'POST', url, data = {},
            successMsg = '操作成功', errorMsg,
            confirmMessage = null, confirmTitle = '确认操作',
            target = null, toastType = 'success',
        } = spec;

        // 1. 确认（可选）
        if (confirmMessage) {
            const confirmed = await dialog.confirm(confirmMessage, { title: confirmTitle });
            if (!confirmed) return { success: false };
        }

        // 2. Loading
        const exitLoading = _enterLoading(target);

        try {
            // 3. 执行
            const result = await http.request(url, { method, data, interceptor: true });

            exitLoading();
            toast[toastType](successMsg);
            return { success: true, data: result };
        } catch (e) {
            exitLoading();
            const msg = errorMsg || e.message || '操作失败';
            toast.error(msg);
            return { success: false, data: null };
        }
    },
};

// ============================================================
// 导出
// ============================================================
export { toast, dialog, notify, contextMenu, hotkey, clipboard, action, loading };
