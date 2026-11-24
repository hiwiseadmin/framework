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

/**
 * 弹层宿主实例 —— 本模块内所有 layer 调用的唯一出口。
 *
 * 为什么不在本窗口（iframe）里开弹窗：
 *   业务页普遍跑在主框架的 iframe 里。弹窗渲染在子文档 = 被框架边界与侧边栏挤压，
 *   「最大化」只能在子文档内铺满，遮罩也盖不住框架；且 toast 会被顶层弹窗遮罩挡住。
 *   所以这里统一取 wsLayer.host()（iframe 内自动用顶层窗口实例，跨域时回落本窗口）。
 *
 * @returns {Object|null} wsLayer 实例
 */
function layerHost() {
    const local = window.wsLayer;
    if (!local) return null;
    return typeof local.host === 'function' ? local.host() : local;
}

/**
 * 弹层索引 → 出具该弹层的实例
 *
 * 同一页面可能同时用两份 wsLayer 实例（本窗口 + 顶层窗口），index 各自递增，
 * 只凭 index 无法判断该由谁关闭（关错实例会误关另一个弹层）。开层时登记归属，
 * 关层时按归属分发。
 */
const dialogOwners = new Map();

function rememberDialogOwner(index, host) {
    if (index === undefined || index === null) return;
    dialogOwners.set(index, host);
}

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

        // 1. 优先使用 ws-layer 的 toast（走顶层：否则会被顶层弹窗的遮罩挡住）
        const hostToast = layerHost();
        if (hostToast && hostToast.toast) {
            return hostToast.toast({
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
            // 1. 优先使用 ws-layer（顶层弹层）
            const host = layerHost();
            if (host && host.alert) {
                host.alert(message, { title: options.title || '提示' }, () => resolve());
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
            // 1. 优先使用 ws-layer（顶层弹层）
            const host = layerHost();
            if (host && host.confirm) {
                host.confirm(
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
        const layerType = typeof content === 'string' && content.startsWith('/') ? 2 : 1;
        // 页面弹窗（type=2 iframe 页）统一在**顶层窗口**出具：避免被主框架 iframe 边界与
        // 侧边栏挤压，「最大化」才能真正铺满浏览器可视区（子文档内只能铺满 iframe）。
        // HTML 弹层（type=1）仍留在本窗口 —— 其内容常依赖本页 document 上的事件委托。
        const local = window.wsLayer;
        const host = (layerType === 2 ? layerHost() : local) || local;
        if (host && host.open) {
            const payload = {
                type: layerType,
                title,
                content,
                area: options.area || ['800px', '500px'],
                ...options,
            };
            // 页面弹窗在顶层出具时，把发起窗口交给弹层内页面（window.__wsLayerOpener），
            // 否则表单页的 window.parent 是主框架，父页回调钩子会找不到。
            if (layerType === 2 && host !== local) payload.openerWindow = window;
            const index = host.open(payload);
            rememberDialogOwner(index, host);
            return index;
        }

        // 兜底：Bootstrap Modal（仅当页面确有一个共用的 #ws-modal 容器）
        if (typeof window.bootstrap !== 'undefined' && window.bootstrap.Modal) {
            const modalEl = document.getElementById('ws-modal');
            if (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                return;
            }
        }

        // 签名守卫：宁可明确报错，也不静默打开一个空壳
        logger.error('ui', '弹窗不可用：ws-layer 未加载，且页面没有 #ws-modal 容器');
        if (typeof toast !== 'undefined' && toast.error) {
            toast.error('弹窗组件未加载，请刷新页面重试');
        }
    },
    /**
     * iframe 弹窗（等价于 open(url, title, options)，供旧 `Layer.iframe` 迁移使用）。
     *
     * @param {string} url
     * @param {Object} [options] - { title, area, btn, yes, end, ... }
     */
    iframe(url, options = {}) {
        return this.open(url, options.title || '', options);
    },

    /**
     * 取弹层 iframe 内的元素（供旧 `Layer.getChildFrame` 迁移使用）。
     *
     * @param {string} selector
     * @param {number|string} index - layer 索引
     * @returns {jQuery|Element|null}
     */
    getChildFrame(selector, index) {
        // 必须与 open() 用同一个宿主实例：弹层在顶层时，索引只登记在顶层实例的 instances 里
        const host = dialogOwners.get(index) || layerHost();
        if (host && typeof host.getChildFrame === 'function') {
            return host.getChildFrame(selector, index);
        }

        // 兜底路径：宿主不是本窗口实例时，弹层 DOM 在顶层文档里
        const inTop = host && window.wsLayer && host !== window.wsLayer;
        const hostDoc = (inTop && window.top && window.top.document) ? window.top.document : document;
        const iframe = hostDoc.getElementById('ws-layer-iframe-' + index);
        const doc = iframe && (iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document));
        if (!doc) return window.jQuery ? window.jQuery() : null;
        return window.jQuery ? window.jQuery(doc).find(selector) : doc.querySelector(selector);
    },

    /**
     * 轻表单弹窗 —— 唯一的内联表单弹窗入口（UIS v1 §4.2）。
     *
     * 内部统一做掉：标准骨架、校验器实例化、提交态、成功延迟关闭、dirty 提示、DOM 销毁。
     * 重表单（字段 > 8 / 富文本 / 上传 / 重复组）请改用 `dialog.open(url)` 走 iframe 表单页。
     *
     * @param {Object} spec
     * @param {string}  spec.title              标题（必填）
     * @param {'sm'|'md'|'lg'|'xl'} [spec.size='md']
     * @param {string}  [spec.html]             表单内部 HTML（与 HtmlRenderer 输出同构）
     * @param {string}  [spec.url]              或从该 URL 拉取表单片段（GET）
     * @param {string}  [spec.action]           提交地址（默认取 spec.url / 内层 form 的 action）
     * @param {string}  [spec.method='POST']
     * @param {string}  [spec.submitText='保存']
     * @param {boolean} [spec.destroyOnHide=true]
     * @param {Function}[spec.onSuccess]        (res) => void
     * @returns {Promise<{success:boolean, data?:any}>}
     */
    form(spec = {}) {
        const size = ['sm', 'md', 'lg', 'xl'].indexOf(spec.size) !== -1 ? spec.size : 'md';
        const destroyOnHide = spec.destroyOnHide !== false;

        return new Promise((resolve) => {
            const formId = 'ws-modal-form-' + Math.random().toString(36).slice(2, 8);

            const wrap = document.createElement('div');
            wrap.className = 'modal fade ws-modal-wrap';
            wrap.tabIndex = -1;
            wrap.innerHTML = `
                <div class="modal-dialog modal-${size} modal-dialog-centered">
                    <div class="modal-content ws-modal">
                        <div class="modal-header">
                            <h5 class="modal-title"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                        </div>
                        <form class="modal-body ws-form" id="${formId}" novalidate>
                            <div class="ws-modal-body-content"></div>
                            <div class="alert alert-danger d-none ws-modal-error mt-3 mb-0"></div>
                        </form>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" form="${formId}" class="btn btn-primary ws-modal-submit"></button>
                        </div>
                    </div>
                </div>`;

            wrap.querySelector('.modal-title').textContent = spec.title || '编辑';
            wrap.querySelector('.ws-modal-submit').textContent = spec.submitText || '保存';

            const form = wrap.querySelector('form');
            const bodyContent = wrap.querySelector('.ws-modal-body-content');
            const submitBtn = wrap.querySelector('.ws-modal-submit');
            const errorBox = wrap.querySelector('.ws-modal-error');

            document.body.appendChild(wrap);

            const modalEl = window.bootstrap && window.bootstrap.Modal;
            const modal = modalEl ? new modalEl(wrap, { backdrop: 'static', keyboard: true }) : null;
            let settled = false;

            const finish = (result) => {
                if (settled) return;
                settled = true;
                resolve(result);
            };

            const setContent = (html) => {
                bodyContent.innerHTML = html || '';
                // 内层若有 <form>，把字段挪进外层表单（避免表单嵌套）
                const inner = bodyContent.querySelector('form');
                if (inner) {
                    while (inner.firstChild) bodyContent.appendChild(inner.firstChild);
                    inner.remove();
                }
                // 声明式 ws-* 组件：弹窗内容是刚注入的 DOM，
                // 组件的 $(function(){}) 早已执行过，不在这里补挂就永远不会初始化。
                // 必须早于校验器创建 —— 校验规则挂在组件生成的隐藏载体上。
                const wsAutoInit = window.Wise && window.Wise.ws && window.Wise.ws.autoInitializeAll;
                if (typeof wsAutoInit === 'function') {
                    try {
                        wsAutoInit(bodyContent);
                    } catch (e) {
                        logger.warn('ui', 'ws 组件初始化失败：' + (e && e.message ? e.message : e));
                    }
                }

                // 校验器：仅当内容里带 data-validation 且 WsFormValidator 已加载
                const Validator = window.WsFormValidator;
                if (Validator && bodyContent.querySelector('[data-validation]')) {
                    try {
                        wrap.__wsValidator = new Validator(formId, { liveValidation: true, showIcons: true });
                    } catch (e) {
                        logger.warn('ui', '表单校验器初始化失败：' + (e && e.message ? e.message : e));
                    }
                }
            };

            if (spec.html) {
                setContent(spec.html);
            } else if (spec.url) {
                bodyContent.innerHTML = '<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>加载中…</div>';
                http.get(spec.url, {}, { interceptor: false })
                    .then((html) => setContent(typeof html === 'string' ? html : ''))
                    .catch((err) => {
                        bodyContent.innerHTML = '';
                        errorBox.textContent = err.message || '表单加载失败';
                        errorBox.classList.remove('d-none');
                    });
            }

            if (modal) modal.show();

            // 自己接管 [data-bs-dismiss]：不依赖 Bootstrap 的 document 级 data-api
            //（ws-layer 等库也会在 document 上处理点击，顺序不可控；这里保证「取消/×」一定关得掉）
            wrap.querySelectorAll('[data-bs-dismiss="modal"]').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.preventDefault();
                    if (modal) modal.hide(); else wrap.remove();
                });
            });

            wrap.addEventListener('hidden.bs.modal', () => {
                const validator = wrap.__wsValidator;
                if (validator && typeof validator.destroy === 'function') {
                    try { validator.destroy(); } catch (e) { /* 忽略 */ }
                }
                if (destroyOnHide) wrap.remove();
                finish({ success: false });
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                errorBox.classList.add('d-none');

                const validator = wrap.__wsValidator;
                if (validator && typeof validator.validate === 'function' && validator.validate() === false) {
                    return;
                }

                const action = spec.action || form.getAttribute('action') || spec.url || '';
                if (!action) {
                    logger.warn('ui', 'dialog.form 缺少提交地址（spec.action）');
                    return;
                }

                const data = {};
                new FormData(form).forEach((value, key) => {
                    if (typeof value !== 'string') return;
                    data[key] = data[key] === undefined ? value : [].concat(data[key], value);
                });

                submitBtn.disabled = true;
                submitBtn.classList.add('disabled');

                try {
                    const res = await http.post(action, data, spec.method && spec.method !== 'POST' ? { method: spec.method } : {});
                    toast.success(spec.successText || '保存成功', { duration: 1500 });
                    settled = true;
                    // 延后关闭：让用户看到提示（§4.2 第 5 条）
                    setTimeout(() => {
                        if (modal) modal.hide(); else wrap.remove();
                        if (typeof spec.onSuccess === 'function') spec.onSuccess(res);
                        resolve({ success: true, data: res });
                    }, 600);
                } catch (err) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('disabled');
                    // http 层已 toast；这里补一条弹窗内联提示，避免用户漏看
                    errorBox.textContent = err.message || '提交失败';
                    errorBox.classList.remove('d-none');
                }
            });
        });
    },

    /**
     * 选择器弹窗 —— 唯一的选择类弹窗入口（列表 / 图标 / 单值）。
     *
     * @param {Object} spec
     * @param {Array}  [spec.items]   静态选项 [{value,label,icon?,desc?}]
     * @param {string} [spec.url]     或从该 URL 拉取（返回 {rows:[...]} 或数组）
     * @param {'single'|'multiple'} [spec.mode='single']
     * @param {string} [spec.title='请选择']
     * @param {Function} [spec.renderItem] (item) => html
     * @returns {Promise<Array|Object|string|null>} 取消返回 null
     */
    picker(spec = {}) {
        const mode = spec.mode === 'multiple' ? 'multiple' : 'single';

        return new Promise((resolve) => {
            const wrap = document.createElement('div');
            wrap.className = 'modal fade ws-picker-wrap';
            wrap.tabIndex = -1;
            wrap.innerHTML = `
                <div class="modal-dialog modal-md modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content ws-modal">
                        <div class="modal-header">
                            <h5 class="modal-title"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                        </div>
                        <div class="modal-body p-0">
                            <div class="p-2 border-bottom">
                                <input type="search" class="form-control form-control-sm ws-picker-search" placeholder="搜索">
                            </div>
                            <div class="list-group list-group-flush ws-picker-list">
                                <div class="text-center text-secondary py-4">加载中…</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="button" class="btn btn-primary ws-picker-ok">确定</button>
                        </div>
                    </div>
                </div>`;

            wrap.querySelector('.modal-title').textContent = spec.title || '请选择';

            const listEl = wrap.querySelector('.ws-picker-list');
            const searchEl = wrap.querySelector('.ws-picker-search');
            const okBtn = wrap.querySelector('.ws-picker-ok');
            document.body.appendChild(wrap);

            const modalEl = window.bootstrap && window.bootstrap.Modal;
            const modal = modalEl ? new modalEl(wrap, { backdrop: 'static', keyboard: true }) : null;

            let items = Array.isArray(spec.items) ? spec.items.slice() : [];
            const selected = new Set();
            let settled = false;

            const render = (keyword = '') => {
                const kw = keyword.trim().toLowerCase();
                const shown = kw === '' ? items : items.filter((it) => String(it.label || it.value || '').toLowerCase().indexOf(kw) !== -1);

                if (shown.length === 0) {
                    listEl.innerHTML = '<div class="text-center text-secondary py-4">无匹配项</div>';
                    return;
                }

                listEl.innerHTML = shown.map((item) => {
                    const value = String(item.value);
                    const icon = item.icon ? `<i class="${item.icon} me-2"></i>` : '';
                    const desc = item.desc ? `<div class="small text-secondary">${item.desc}</div>` : '';
                    const active = selected.has(value) ? ' active' : '';
                    return `<button type="button" class="list-group-item list-group-item-action ws-picker-item${active}" data-value="${value}">
                        ${icon}${item.label || value}${desc}</button>`;
                }).join('');
            };

            listEl.addEventListener('click', (event) => {
                const btn = event.target.closest('.ws-picker-item');
                if (!btn) return;
                const value = btn.dataset.value;

                if (mode === 'single') {
                    selected.clear();
                    selected.add(value);
                    listEl.querySelectorAll('.ws-picker-item').forEach((el) => el.classList.remove('active'));
                    btn.classList.add('active');
                } else {
                    if (selected.has(value)) { selected.delete(value); btn.classList.remove('active'); }
                    else { selected.add(value); btn.classList.add('active'); }
                }
            });

            searchEl.addEventListener('input', () => render(searchEl.value));

            okBtn.addEventListener('click', () => {
                if (selected.size === 0) {
                    toast.warning(spec.emptyText || '请先选择一项', { duration: 1500 });
                    return;
                }
                const values = Array.from(selected);
                const picked = mode === 'single'
                    ? items.find((it) => String(it.value) === values[0]) || values[0]
                    : items.filter((it) => selected.has(String(it.value)));

                settled = true;
                if (modal) modal.hide(); else wrap.remove();
                resolve(picked);
            });

            wrap.addEventListener('hidden.bs.modal', () => {
                wrap.remove();
                if (!settled) resolve(null);
            });

            if (modal) modal.show();

            // 同 form()：自行接管关闭，不依赖 document 级 data-api
            wrap.querySelectorAll('[data-bs-dismiss="modal"]').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.preventDefault();
                    if (modal) modal.hide(); else wrap.remove();
                });
            });

            if (spec.url) {
                http.get(spec.url, {}, { interceptor: false })
                    .then((res) => {
                        const rows = Array.isArray(res) ? res : (res && (res.rows || res.data)) || [];
                        items = rows.map((row) => (typeof row === 'object'
                            ? { value: row.value !== undefined ? row.value : row.id, label: row.label || row.name || row.title || row.id, icon: row.icon, desc: row.desc }
                            : { value: row, label: String(row) }));
                        render();
                    })
                    .catch((err) => {
                        listEl.innerHTML = `<div class="text-center text-danger py-4">${err.message || '加载失败'}</div>`;
                    });
            } else {
                render();
            }
        });
    },

    /**
     * 关闭弹窗
     * @param {number} [index]
     */
    close(index) {
        // 按开层时的归属实例关闭（页面弹窗在顶层、HTML 弹层在本窗口）
        const owner = dialogOwners.get(index) || window.wsLayer;
        if (owner && owner.close) {
            owner.close(index);
        }
        dialogOwners.delete(index);
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
