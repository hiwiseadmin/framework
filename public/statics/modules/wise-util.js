/**
 * Wise.util — 工具函数集合
 * 提供 URL 处理、HTTP 客户端、国际化、编解码、类型判断、数据处理、存储
 *
 * @module wise-util
 * @version 1.0.0
 * @license MIT
 */

import { config } from './wise-core.js';

// ============================================================
// URL 工具
// ============================================================
const url = {
    /**
     * 修复 URL（判断是否完整URL + 拼接 baseUrl）
     * @param {string} rawUrl
     * @param {boolean} [isAbsolute]
     * @returns {string}
     */
    fix(rawUrl, isAbsolute) {
        if (!rawUrl) return '';
        if (/^https?:\/\//i.test(rawUrl)) return rawUrl;
        const base = config.get('apiBaseUrl', window.location.origin);
        if (isAbsolute || rawUrl.startsWith('/')) {
            return `${base}${rawUrl.startsWith('/') ? '' : '/'}${rawUrl}`;
        }
        return `${base}/${rawUrl}`;
    },

    /**
     * 解析 URL 查询参数为对象
     * @param {string} [urlStr] - 不传则用当前页面 URL
     * @returns {Object}
     */
    parseParams(urlStr) {
        const str = (urlStr || window.location.href).split('?')[1]?.split('#')[0] || '';
        const params = {};
        if (!str) return params;
        str.split('&').forEach(pair => {
            const [paramKey, paramValue] = pair.split('=');
            const key = paramKey ? decodeURIComponent(paramKey) : '';
            const value = paramValue !== undefined ? decodeURIComponent(paramValue) : '';
            if (key) params[key] = value;
        });
        return params;
    },

    /**
     * 构建查询参数字符串
     * @param {Object} params
     * @returns {string} 不含问号
     */
    buildParams(params) {
        if (!params) return '';
        return Object.entries(params)
            .filter(([, v]) => v !== null && v !== undefined && v !== '')
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
            .join('&');
    },

    /**
     * 拼接 URL 和参数
     * @param {string} urlStr
     * @param {Object} params
     * @returns {string}
     */
    merge(urlStr, params) {
        const qs = this.buildParams(params);
        if (!qs) return urlStr;
        return urlStr + (urlStr.includes('?') ? '&' : '?') + qs;
    },

    /**
     * 获取单个 URL 参数
     * @param {string} name
     * @param {string} [urlStr]
     * @returns {string|null}
     */
    getParam(name, urlStr) {
        const params = this.parseParams(urlStr);
        return params[name] ?? null;
    },
};

// ============================================================
// HTTP 客户端（基于 fetch + XMLHttpRequest，不依赖 jQuery）
// ============================================================
/**
 * 解析后端 JSON 响应
 * @param {Response} response
 * @returns {Promise<Object>}
 */
async function _parseResponse(response) {
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        return response.json();
    }
    return response.text();
}

// --- CSRF Token 读取 ---
// 注意：服务端每次写请求校验通过后会「轮换」token（防 BREACH），
// 因此这里绝不能做永久缓存——否则第一次成功后，后续请求仍发旧 token，
// 与服务端 Cookie 中的新 token 不匹配，导致「CSRF令牌验证失败」。
// 正确顺序：优先读 Cookie（始终是最新值）→ 退回 meta 标签 → 退回 window.csrfToken。
function _getCSRFToken() {
    const name = 'csrf_token=';
    const parts = document.cookie.split(';');
    for (let i = 0; i < parts.length; i++) {
        const c = parts[i].trim();
        if (c.indexOf(name) === 0) {
            const v = c.substring(name.length);
            if (v) return v;
        }
    }
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.getAttribute('content')) {
        return meta.getAttribute('content');
    }
    if (typeof window.csrfToken !== 'undefined' && window.csrfToken) {
        return window.csrfToken;
    }
    return null;
}

// --- 业务码判断（支持数组形式 successCode）---
function _isSuccess(code, expected) {
    if (Array.isArray(expected)) return expected.includes(code);
    return code == expected;
}

const http = {
    // --- 配置 ---
    _interceptor: null,
    _errorHandler: null,
    _loadingHandler: null,
    _unauthorizedHandler: null,

    // --- Loading 计数器 ---
    _loadingCount: 0,
    _loadingTimer: null,

    /**
     * 设置业务响应拦截器
     * @param {Object} [options]
     * @param {string} [options.codeKey='code']
     * @param {number|number[]} [options.successCode=0]  支持数组：[0, 1, 200]
     * @param {string} [options.dataKey='data']
     * @param {string} [options.msgKey='msg']
     * @param {number} [options.unauthorizedCode=401]
     */
    setInterceptor(options = {}) {
        this._interceptor = {
            codeKey: options.codeKey ?? 'code',
            successCode: options.successCode ?? [0, 200],
            dataKey: options.dataKey ?? 'data',
            msgKey: options.msgKey ?? 'msg',
            unauthorizedCode: options.unauthorizedCode ?? 401,
        };
    },

    /**
     * 设置全局错误处理回调（通常注入 toast.error）
     * @param {Function} handler - (err: Error) => void
     */
    setErrorHandler(handler) {
        if (typeof handler === 'function') this._errorHandler = handler;
    },

    /**
     * 设置全局 Loading 处理回调
     * @param {Object} handler - { show: Function, hide: Function }
     */
    setLoadingHandler(handler) {
        if (handler && typeof handler.show === 'function' && typeof handler.hide === 'function') {
            this._loadingHandler = handler;
        }
    },

    /**
     * 设置全局 401 未授权处理回调
     * @param {Function} handler - (res: Object) => void
     */
    setUnauthorizedHandler(handler) {
        if (typeof handler === 'function') this._unauthorizedHandler = handler;
    },

    // ======================== 内部辅助 ========================

    _showLoading() {
        this._loadingCount++;
        if (this._loadingCount === 1 && this._loadingHandler) {
            this._loadingTimer = setTimeout(() => {
                if (this._loadingHandler) this._loadingHandler.show();
            }, 300);
        }
    },

    _hideLoading() {
        this._loadingCount = Math.max(0, this._loadingCount - 1);
        if (this._loadingCount === 0) {
            if (this._loadingTimer) {
                clearTimeout(this._loadingTimer);
                this._loadingTimer = null;
            }
            if (this._loadingHandler) this._loadingHandler.hide();
        }
    },

    _showError(err, opts) {
        if (opts.autoError && this._errorHandler) this._errorHandler(err);
        if (typeof opts.onError === 'function') opts.onError(err);
    },

    _handleUnauthorized(res) {
        if (this._unauthorizedHandler) {
            this._unauthorizedHandler(res);
        } else {
            const meta = document.querySelector('meta[name="login-url"]');
            const loginUrl = meta ? meta.getAttribute('content') : '/admin/login';
            window.location.href = loginUrl;
        }
    },

    // ======================== 核心请求 ========================

    /**
     * 通用请求（非 async，返回 Promise）
     *
     * interceptor=true  → 业务拦截路径：解析 code/msg/data，401 处理，业务错误 reject
     * interceptor=false → 直接路径：跳过业务逻辑，resolve 原始 body
     *
     * @param {string} urlStr
     * @param {Object} [options]
     * @param {string} [options.method='GET']
     * @param {Object} [options.data] - 请求体数据
     * @param {Object} [options.headers]
     * @param {number} [options.timeout=10000] - 超时毫秒
     * @param {boolean} [options.csrf=true] - 自动注入 CSRF Token
     * @param {boolean} [options.interceptor=true] - 启用业务拦截器
     * @param {number} [options.retry=0] - 网络错误重试次数
     * @param {boolean} [options.loading=false] - 显示全局 Loading
     * @param {boolean} [options.autoError=true] - 自动错误提示
     * @param {Function} [options.beforeRequest] - 请求前拦截，返回 false 取消
     * @param {Function} [options.onError] - 每请求级错误回调
     * @returns {Promise}
     */
    request(urlStr, options = {}) {
        const opts = {
            method: 'GET', data: null, headers: {}, timeout: 10000,
            csrf: true, interceptor: true, retry: 0,
            loading: false, autoError: true,
            beforeRequest: null, onError: null,
            ...options,
        };
        const self = this;

        return new Promise((resolve, reject) => {
            let retryCount = 0;

            function doRequest() {
                // 构建 fetch 配置
                const fetchOpts = {
                    method: opts.method,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', ...opts.headers },
                };

                // CSRF 自动注入（非 GET/HEAD）
                if (opts.csrf && !['GET', 'HEAD'].includes(opts.method.toUpperCase())) {
                    const token = _getCSRFToken();
                    if (token) fetchOpts.headers['X-CSRF-TOKEN'] = token;
                }

                // 请求体
                if (opts.data) {
                    if (opts.data instanceof FormData) {
                        fetchOpts.body = opts.data;
                    } else {
                        fetchOpts.headers['Content-Type'] = 'application/json';
                        fetchOpts.body = JSON.stringify(opts.data);
                    }
                }

                // beforeRequest 拦截
                if (typeof opts.beforeRequest === 'function') {
                    const result = opts.beforeRequest(fetchOpts);
                    if (result === false) {
                        self._hideLoading();
                        resolve(null);
                        return;
                    }
                }

                // Loading
                if (opts.loading) self._showLoading();

                // 超时控制
                const controller = new AbortController();
                fetchOpts.signal = controller.signal;
                const timer = setTimeout(() => controller.abort(), opts.timeout);

                fetch(urlStr, fetchOpts)
                    .then(response => {
                        clearTimeout(timer);
                        if (!response.ok) {
                            return _parseResponse(response).then(body => {
                                const err = new Error(
                                    (body && (body.message || body.msg)) || `HTTP ${response.status}`
                                );
                                err.status = response.status;
                                err.data = body;
                                throw err;
                            });
                        }
                        return _parseResponse(response);
                    })
                    .then(body => {
                        self._hideLoading();

                        // -------- interceptor=true：业务拦截路径 --------
                        if (opts.interceptor && self._interceptor &&
                            typeof body === 'object' && body !== null) {
                            const { codeKey, successCode, dataKey, msgKey, unauthorizedCode } = self._interceptor;
                            if (codeKey in body) {
                                const code = body[codeKey];

                                // 成功
                                if (_isSuccess(code, successCode)) {
                                    resolve(body[dataKey] !== undefined ? body[dataKey] : body);
                                    return;
                                }

                                // 401 未授权
                                if (unauthorizedCode != null && code == unauthorizedCode) {
                                    const err = new Error(body[msgKey] || '登录已过期');
                                    err.code = code;
                                    err.data = body[dataKey];
                                    err.unauthorized = true;
                                    self._handleUnauthorized(body);
                                    self._showError(err, opts);
                                    reject(err);
                                    return;
                                }

                                // 业务错误
                                const err = new Error(body[msgKey] || '业务错误');
                                err.code = code;
                                err.data = body[dataKey];
                                self._showError(err, opts);
                                reject(err);
                                return;
                            }
                        }

                        // -------- interceptor=false：直接返回 body --------
                        resolve(body);
                    })
                    .catch(err => {
                        clearTimeout(timer);
                        self._hideLoading();

                        // 超时
                        if (err.name === 'AbortError') {
                            const timeoutErr = new Error('请求超时');
                            timeoutErr.status = 0;
                            self._showError(timeoutErr, opts);
                            reject(timeoutErr);
                            return;
                        }

                        // 网络错误重试（status === undefined 表示网络层错误，非 HTTP 状态码错误）
                        if (retryCount < opts.retry && err.status === undefined) {
                            retryCount++;
                            doRequest();
                            return;
                        }

                        self._showError(err, opts);
                        reject(err);
                    });
            }

            doRequest();
        });
    },

    // ======================== 快捷方法 ========================

    /**
     * GET 请求
     * @param {string} urlStr
     * @param {Object} [params] - 查询参数
     * @param {Object} [options]
     * @returns {Promise}
     */
    get(urlStr, params, options = {}) {
        const fullUrl = params ? url.merge(urlStr, params) : urlStr;
        return this.request(fullUrl, { ...options, method: 'GET' });
    },

    /**
     * POST 请求
     * @param {string} urlStr
     * @param {Object} [data]
     * @param {Object} [options]
     * @returns {Promise}
     */
    post(urlStr, data, options = {}) {
        return this.request(urlStr, { ...options, method: 'POST', data });
    },

    /**
     * PUT 请求
     * @param {string} urlStr
     * @param {Object} [data]
     * @param {Object} [options]
     * @returns {Promise}
     */
    put(urlStr, data, options = {}) {
        return this.request(urlStr, { ...options, method: 'PUT', data });
    },

    /**
     * DELETE 请求
     * @param {string} urlStr
     * @param {Object} [options]
     * @returns {Promise}
     */
    delete(urlStr, options = {}) {
        return this.request(urlStr, { ...options, method: 'DELETE' });
    },

    /**
     * 文件上传（基于 XMLHttpRequest，支持上传进度）
     * @param {string} urlStr
     * @param {File|FormData} file
     * @param {Object} [options]
     * @param {Function} [options.onProgress] - (percent:number, loaded:number, total:number) => void
     * @param {number} [options.timeout=30000]
     * @param {boolean} [options.interceptor=true]
     * @param {boolean} [options.csrf=true]
     * @param {boolean} [options.autoError=true]
     * @param {boolean} [options.loading=false]
     * @param {Function} [options.onError]
     * @returns {Promise}
     */
    upload(urlStr, file, options = {}) {
        const opts = {
            csrf: true, interceptor: true, autoError: true,
            timeout: 30000, loading: false,
            onProgress: null, onError: null,
            ...options,
        };

        let formData;
        if (file instanceof FormData) {
            formData = file;
        } else {
            formData = new FormData();
            formData.append('file', file);
        }

        const self = this;

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();

            // 上传进度
            if (opts.onProgress && xhr.upload) {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        opts.onProgress(
                            Math.round((e.loaded / e.total) * 100),
                            e.loaded,
                            e.total
                        );
                    }
                }, false);
            }

            // Loading
            if (opts.loading) self._showLoading();

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                self._hideLoading();

                // 网络错误 / 取消
                if (xhr.status === 0) {
                    const err = new Error('网络请求失败');
                    self._showError(err, opts);
                    reject(err);
                    return;
                }

                // HTTP 错误
                if (xhr.status < 200 || xhr.status >= 300) {
                    let errBody;
                    try { errBody = JSON.parse(xhr.responseText); } catch { errBody = xhr.responseText; }
                    const err = new Error(
                        (errBody && (errBody.message || errBody.msg)) || `HTTP ${xhr.status}`
                    );
                    err.status = xhr.status;
                    err.data = errBody;
                    self._showError(err, opts);
                    reject(err);
                    return;
                }

                // 解析响应体
                let body;
                const contentType = xhr.getResponseHeader('content-type') || '';
                if (contentType.includes('application/json')) {
                    try { body = JSON.parse(xhr.responseText); } catch { body = xhr.responseText; }
                } else {
                    body = xhr.responseText;
                }

                // 业务拦截
                if (opts.interceptor && self._interceptor &&
                    typeof body === 'object' && body !== null) {
                    const { codeKey, successCode, dataKey, msgKey, unauthorizedCode } = self._interceptor;
                    if (codeKey in body) {
                        const code = body[codeKey];

                        if (_isSuccess(code, successCode)) {
                            resolve(body[dataKey] !== undefined ? body[dataKey] : body);
                            return;
                        }

                        if (unauthorizedCode != null && code == unauthorizedCode) {
                            const err = new Error(body[msgKey] || '登录已过期');
                            err.code = code;
                            err.data = body[dataKey];
                            err.unauthorized = true;
                            self._handleUnauthorized(body);
                            self._showError(err, opts);
                            reject(err);
                            return;
                        }

                        const err = new Error(body[msgKey] || '业务错误');
                        err.code = code;
                        err.data = body[dataKey];
                        self._showError(err, opts);
                        reject(err);
                        return;
                    }
                }

                resolve(body);
            };

            xhr.onerror = function () {
                self._hideLoading();
                const err = new Error('网络请求失败');
                self._showError(err, opts);
                reject(err);
            };

            xhr.ontimeout = function () {
                self._hideLoading();
                const err = new Error('请求超时');
                err.status = 0;
                self._showError(err, opts);
                reject(err);
            };

            xhr.timeout = opts.timeout;
            xhr.open('POST', urlStr, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            // CSRF（setRequestHeader 必须在 open 之后）
            if (opts.csrf) {
                const token = _getCSRFToken();
                if (token) xhr.setRequestHeader('X-CSRF-TOKEN', token);
            }

            xhr.send(formData);
        });
    },

    /**
     * 表单提交（原生 FormData 序列化）
     * @param {string|HTMLFormElement} formEl - 选择器或 DOM 元素
     * @param {Object} [options] - 同 request options
     * @returns {Promise}
     */
    submit(formEl, options = {}) {
        const form = typeof formEl === 'string' ? document.querySelector(formEl) : formEl;
        if (!form) {
            return Promise.reject(new Error('表单元素不存在'));
        }
        const urlStr = form.getAttribute('action') || window.location.href;
        const method = (form.getAttribute('method') || 'POST').toUpperCase();
        const formData = new FormData(form);
        return this.request(urlStr, { ...options, method, data: formData });
    },

    /**
     * 批量请求（并发控制）
     * @param {Array<Function>} reqFns - 返回 Promise 的函数数组
     * @param {number} [concurrency=3]
     * @returns {Promise<Array>} 按原始顺序返回结果
     */
    async batch(reqFns, concurrency = 3) {
        const results = new Array(reqFns.length);
        let idx = 0;
        async function worker() {
            while (idx < reqFns.length) {
                const i = idx++;
                try {
                    results[i] = { ok: true, data: await reqFns[i]() };
                } catch (err) {
                    results[i] = { ok: false, error: err };
                }
            }
        }
        const workers = Array(Math.min(concurrency, reqFns.length))
            .fill(null).map(() => worker());
        await Promise.all(workers);
        return results;
    },
};

// ============================================================
// 国际化（兼容旧 window.L 函数）
// ============================================================
let _locale = 'zh-cn';
let _messages = {};

const i18n = {
    /**
     * 翻译函数
     * @param {string} key - 语言键
     * @param {...*} args - 替换参数，支持 %s / %d / %{name}
     * @returns {string}
     */
    t(key, ...args) {
        let msg = _messages[key] ?? key;
        if (args.length > 0) {
            args.forEach((arg, i) => {
                msg = msg.replace(new RegExp(`%s|%d|\\$\\{${i}\\}`, 'g'), arg);
            });
        }
        return msg;
    },

    /**
     * 设置语言包
     * @param {string} locale
     * @param {Object} msg
     */
    setLocale(locale, msg) {
        _locale = locale;
        _messages = msg || {};
    },

    /** @returns {string} */
    getLocale() { return _locale; },
};

// ============================================================
// 编码/解码
// ============================================================
const codec = {
    base64Encode(str) {
        try { return btoa(unescape(encodeURIComponent(str))); }
        catch { return btoa(str); }
    },
    base64Decode(str) {
        try { return decodeURIComponent(escape(atob(str))); }
        catch { return atob(str); }
    },
    htmlEscape(str) {
        const charMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(str).replace(/[&<>"']/g, matchedChar => charMap[matchedChar]);
    },
    htmlUnescape(str) {
        const entityMap = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'" };
        return String(str).replace(/&(amp|lt|gt|quot|#39);/g, matchedEntity => entityMap[matchedEntity]);
    },
    htmlPurify(str) {
        return this.htmlEscape(str);
    },
};

// ============================================================
// 类型判断
// ============================================================
const type = {
    isString(v) { return typeof v === 'string'; },
    isNumber(v) { return typeof v === 'number' && !isNaN(v); },
    isBoolean(v) { return typeof v === 'boolean'; },
    isArray(v) { return Array.isArray(v); },
    isObject(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); },
    isFunction(v) { return typeof v === 'function'; },
    isNull(v) { return v === null; },
    isUndefined(v) { return v === undefined; },
    isEmpty(v) {
        if (v == null) return true;
        if (typeof v === 'string') return v.trim().length === 0;
        if (Array.isArray(v)) return v.length === 0;
        if (typeof v === 'object') return Object.keys(v).length === 0;
        return false;
    },
    isPromise(v) { return v && typeof v.then === 'function'; },
    isElement(v) { return v instanceof HTMLElement; },
    isMobile() { return /Mobi|Android/i.test(navigator.userAgent); },
};

// ============================================================
// 数据处理
// ============================================================
const data = {
    clone(obj) {
        if (obj == null || typeof obj !== 'object') return obj;
        if (obj instanceof Date) return new Date(obj);
        if (Array.isArray(obj)) return obj.map(item => this.clone(item));
        const cloned = {};
        for (const [k, v] of Object.entries(obj)) cloned[k] = this.clone(v);
        return cloned;
    },

    merge(target, ...sources) {
        for (const src of sources) {
            if (!src) continue;
            for (const [k, v] of Object.entries(src)) {
                if (typeof v === 'object' && !Array.isArray(v) && v !== null) {
                    target[k] = this.merge(target[k] || {}, v);
                } else {
                    target[k] = v;
                }
            }
        }
        return target;
    },

    get(obj, path, defaultValue) {
        const keys = String(path).split('.');
        let cur = obj;
        for (const k of keys) {
            if (cur == null || typeof cur !== 'object') return defaultValue;
            cur = cur[k];
        }
        return cur !== undefined ? cur : defaultValue;
    },

    set(obj, path, value) {
        const keys = String(path).split('.');
        const last = keys.pop();
        let cur = obj;
        for (const k of keys) {
            if (!(k in cur) || typeof cur[k] !== 'object') cur[k] = {};
            cur = cur[k];
        }
        cur[last] = value;
        return obj;
    },

    toFormData(obj) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(obj)) {
            if (v instanceof File) fd.append(k, v);
            else if (v != null) fd.append(k, String(v));
        }
        return fd;
    },

    debounce(fn, delay = 300) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    },

    throttle(fn, delay = 300) {
        let last = 0;
        return function (...args) {
            const now = Date.now();
            if (now - last >= delay) { last = now; fn.apply(this, args); }
        };
    },

    uid(prefix = 'ws') {
        return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
    },
};

// ============================================================
// 存储工具
// ============================================================
const storage = {
    get(key, defaultValue) {
        try {
            const val = localStorage.getItem(key);
            if (val === null) return defaultValue;
            try { return JSON.parse(val); } catch { return val; }
        } catch { return defaultValue; }
    },
    set(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* quota exceeded */ }
    },
    remove(key) { try { localStorage.removeItem(key); } catch { /* */ } },

    session: {
        get(key, def) {
            try {
                const val = sessionStorage.getItem(key);
                if (val === null) return def;
                try { return JSON.parse(val); } catch { return val; }
            } catch { return def; }
        },
        set(key, value) {
            try { sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* */ }
        },
        remove(key) { try { sessionStorage.removeItem(key); } catch { /* */ } },
    },

    cookie: {
        get(name) {
            const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
            return match ? decodeURIComponent(match[1]) : null;
        },
        set(name, value, days = 30, path = '/') {
            const d = new Date();
            d.setTime(d.getTime() + days * 86400000);
            document.cookie = `${name}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=${path}`;
        },
        remove(name, path = '/') {
            document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=${path}`;
        },
    },
};

// ============================================================
// 导出
// ============================================================
export { url, http, i18n, codec, type, data, storage };
