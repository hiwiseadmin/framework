var __defProp = Object.defineProperty;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __typeError = (msg) => {
  throw TypeError(msg);
};
var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
var __esm = (fn, res) => function __init() {
  return fn && (res = (0, fn[__getOwnPropNames(fn)[0]])(fn = 0)), res;
};
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
var __accessCheck = (obj, member, msg) => member.has(obj) || __typeError("Cannot " + msg);
var __privateGet = (obj, member, getter) => (__accessCheck(obj, member, "read from private field"), getter ? getter.call(obj) : member.get(obj));
var __privateAdd = (obj, member, value) => member.has(obj) ? __typeError("Cannot add the same private member more than once") : member instanceof WeakSet ? member.add(obj) : member.set(obj, value);
var __privateMethod = (obj, member, method) => (__accessCheck(obj, member, "access private method"), method);

// public/statics/modules/wise-core.js
var wise_core_exports = {};
__export(wise_core_exports, {
  config: () => config,
  eventBus: () => eventBus,
  logger: () => logger,
  registry: () => registry
});
function _getByPath(obj, path) {
  const keys = path.split(".");
  let current = obj;
  for (const key of keys) {
    if (current == null || typeof current !== "object") return void 0;
    current = current[key];
  }
  return current;
}
function _setByPath(obj, path, value) {
  const keys = path.split(".");
  const lastKey = keys.pop();
  let current = obj;
  for (const key of keys) {
    if (!(key in current) || typeof current[key] !== "object" || current[key] === null) {
      current[key] = {};
    }
    current = current[key];
  }
  current[lastKey] = value;
}
var _config, config, LOG_LEVELS, _logLevel, _logEnabled, logger, _registry, registry, _listeners, eventBus;
var init_wise_core = __esm({
  "public/statics/modules/wise-core.js"() {
    _config = {};
    config = {
      /**
       * 获取配置值（支持点号路径）
       * @param {string} path - 配置路径，如 'upload.maxSize'
       * @param {*} [defaultValue] - 默认值
       * @returns {*}
       */
      get(path, defaultValue) {
        const val = _getByPath(_config, path);
        return val !== void 0 ? val : defaultValue;
      },
      /**
       * 设置配置值
       * @param {string} path
       * @param {*} value
       */
      set(path, value) {
        _setByPath(_config, path, value);
      },
      /**
       * 批量合并配置
       * @param {Object} options - 合并到现有配置中
       */
      merge(options2) {
        if (!options2 || typeof options2 !== "object") return;
        for (const [key, value] of Object.entries(options2)) {
          if (typeof value === "object" && !Array.isArray(value) && value !== null) {
            _config[key] = { ..._config[key] || {}, ...value };
          } else {
            _config[key] = value;
          }
        }
      },
      /** 获取全部配置（只读） */
      all() {
        return Object.freeze({ ..._config });
      }
    };
    LOG_LEVELS = { debug: 0, info: 1, warn: 2, error: 3, none: 99 };
    _logLevel = LOG_LEVELS.warn;
    _logEnabled = true;
    logger = {
      /**
       * 输出日志
       * @param {string} module - 模块名
       * @param {'debug'|'info'|'warn'|'error'} level
       * @param {string} message
       * @param {*} [data]
       */
      log(module, level, message, data3) {
        if (!_logEnabled || LOG_LEVELS[level] < _logLevel) return;
        const prefix = `[Wise:${module}]`;
        const fn = level === "error" ? console.error : level === "warn" ? console.warn : level === "info" ? console.info : console.debug;
        fn(prefix, message, data3 !== void 0 ? data3 : "");
      },
      setLevel(level) {
        if (level in LOG_LEVELS) _logLevel = LOG_LEVELS[level];
      },
      enable() {
        _logEnabled = true;
      },
      disable() {
        _logEnabled = false;
      }
    };
    ["debug", "info", "warn", "error"].forEach((lvl) => {
      logger[lvl] = function(module, message, data3) {
        this.log(module, lvl, message, data3);
      };
    });
    _registry = {};
    registry = {
      /**
       * 注册模块
       * @param {string} name - 模块名
       * @param {Object} instance - 模块实例
       * @param {string[]} [dependencies] - 依赖的模块名列表
       */
      register(name, instance, dependencies = []) {
        if (_registry[name]) {
          logger.warn("registry", `模块 ${name} 已注册，将被覆盖`);
        }
        _registry[name] = { instance, dependencies };
        logger.info("registry", `模块 ${name} 已注册`);
      },
      /**
       * 获取已注册的模块
       * @param {string} name
       * @returns {Object|null}
       */
      get(name) {
        const entry = _registry[name];
        return entry ? entry.instance : null;
      },
      /**
       * 检查模块是否已注册
       * @param {string} name
       * @returns {boolean}
       */
      has(name) {
        return name in _registry;
      },
      /**
       * 获取注册表信息（调试用）
       * @returns {Object}
       */
      info() {
        const result = {};
        for (const [name, entry] of Object.entries(_registry)) {
          result[name] = { dependencies: entry.dependencies, loaded: !!entry.instance };
        }
        return result;
      }
    };
    _listeners = {};
    eventBus = {
      /**
       * 订阅事件
       * @param {string} event - 事件名，如 'ws:table:refresh'
       * @param {Function} handler - 处理函数
       * @param {Object} [opts] - { once?: boolean, priority?: number }
       * @returns {Function} 取消订阅的函数
       */
      on(event, handler, opts = {}) {
        const { once = false, priority = 0 } = opts;
        if (!_listeners[event]) _listeners[event] = [];
        const entry = { handler, once, priority };
        _listeners[event].push(entry);
        _listeners[event].sort((a, b) => b.priority - a.priority);
        return () => this.off(event, handler);
      },
      /**
       * 单次订阅
       * @param {string} event
       * @param {Function} handler
       * @returns {Function} 取消订阅的函数
       */
      once(event, handler) {
        return this.on(event, handler, { once: true });
      },
      /**
       * 取消订阅
       * @param {string} event
       * @param {Function} [handler] - 不传则取消该事件的所有订阅
       */
      off(event, handler) {
        if (!handler) {
          delete _listeners[event];
          return;
        }
        if (_listeners[event]) {
          _listeners[event] = _listeners[event].filter((e) => e.handler !== handler);
          if (_listeners[event].length === 0) delete _listeners[event];
        }
      },
      /**
       * 触发事件（异步，不阻塞主线程）
       * @param {string} event
       * @param {*} [data] - 事件数据
       * @returns {Promise<Array>} 所有处理函数的返回值
       */
      emit(event, data3) {
        if (!_listeners[event]) return Promise.resolve([]);
        const handlers = [..._listeners[event]];
        _listeners[event] = _listeners[event].filter((e) => !e.once);
        if (_listeners[event].length === 0) delete _listeners[event];
        const results = [];
        const promises = handlers.map((entry) => {
          try {
            const result = entry.handler(data3);
            results.push(result);
            return result instanceof Promise ? result : Promise.resolve(result);
          } catch (err) {
            logger.error("eventBus", `事件 ${event} 处理异常`, err);
            return Promise.reject(err);
          }
        });
        return Promise.allSettled(promises).then(() => results);
      },
      /**
       * 列出所有已注册的事件
       * @returns {string[]}
       */
      listEvents() {
        return Object.keys(_listeners);
      }
    };
  }
});

// public/statics/modules/wise-util.js
var wise_util_exports = {};
__export(wise_util_exports, {
  codec: () => codec,
  data: () => data,
  http: () => http,
  i18n: () => i18n,
  storage: () => storage,
  type: () => type,
  url: () => url
});
async function _parseResponse(response) {
  const contentType = response.headers.get("content-type") || "";
  if (contentType.includes("application/json")) {
    return response.json();
  }
  return response.text();
}
function _getCSRFToken() {
  const name = "csrf_token=";
  const parts = document.cookie.split(";");
  for (let i = 0; i < parts.length; i++) {
    const c = parts[i].trim();
    if (c.indexOf(name) === 0) {
      const v = c.substring(name.length);
      if (v) return v;
    }
  }
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta && meta.getAttribute("content")) {
    return meta.getAttribute("content");
  }
  if (typeof window.csrfToken !== "undefined" && window.csrfToken) {
    return window.csrfToken;
  }
  return null;
}
function _isSuccess(code, expected) {
  if (Array.isArray(expected)) return expected.includes(code);
  return code == expected;
}
var url, http, _locale, _messages, i18n, codec, type, data, storage;
var init_wise_util = __esm({
  "public/statics/modules/wise-util.js"() {
    init_wise_core();
    url = {
      /**
       * 修复 URL（判断是否完整URL + 拼接 baseUrl）
       * @param {string} rawUrl
       * @param {boolean} [isAbsolute]
       * @returns {string}
       */
      fix(rawUrl, isAbsolute) {
        if (!rawUrl) return "";
        if (/^https?:\/\//i.test(rawUrl)) return rawUrl;
        const base = config.get("apiBaseUrl", window.location.origin);
        if (isAbsolute || rawUrl.startsWith("/")) {
          return `${base}${rawUrl.startsWith("/") ? "" : "/"}${rawUrl}`;
        }
        return `${base}/${rawUrl}`;
      },
      /**
       * 解析 URL 查询参数为对象
       * @param {string} [urlStr] - 不传则用当前页面 URL
       * @returns {Object}
       */
      parseParams(urlStr) {
        const str = (urlStr || window.location.href).split("?")[1]?.split("#")[0] || "";
        const params = {};
        if (!str) return params;
        str.split("&").forEach((pair) => {
          const [paramKey, paramValue] = pair.split("=");
          const key = paramKey ? decodeURIComponent(paramKey) : "";
          const value = paramValue !== void 0 ? decodeURIComponent(paramValue) : "";
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
        if (!params) return "";
        return Object.entries(params).filter(([, v]) => v !== null && v !== void 0 && v !== "").map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join("&");
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
        return urlStr + (urlStr.includes("?") ? "&" : "?") + qs;
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
      }
    };
    http = {
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
      setInterceptor(options2 = {}) {
        this._interceptor = {
          codeKey: options2.codeKey ?? "code",
          successCode: options2.successCode ?? [0, 200],
          dataKey: options2.dataKey ?? "data",
          msgKey: options2.msgKey ?? "msg",
          unauthorizedCode: options2.unauthorizedCode ?? 401
        };
      },
      /**
       * 设置全局错误处理回调（通常注入 toast.error）
       * @param {Function} handler - (err: Error) => void
       */
      setErrorHandler(handler) {
        if (typeof handler === "function") this._errorHandler = handler;
      },
      /**
       * 设置全局 Loading 处理回调
       * @param {Object} handler - { show: Function, hide: Function }
       */
      setLoadingHandler(handler) {
        if (handler && typeof handler.show === "function" && typeof handler.hide === "function") {
          this._loadingHandler = handler;
        }
      },
      /**
       * 设置全局 401 未授权处理回调
       * @param {Function} handler - (res: Object) => void
       */
      setUnauthorizedHandler(handler) {
        if (typeof handler === "function") this._unauthorizedHandler = handler;
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
        if (typeof opts.onError === "function") opts.onError(err);
      },
      _handleUnauthorized(res) {
        if (this._unauthorizedHandler) {
          this._unauthorizedHandler(res);
        } else {
          const meta = document.querySelector('meta[name="login-url"]');
          const loginUrl = meta ? meta.getAttribute("content") : "/admin/login";
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
      request(urlStr, options2 = {}) {
        const opts = {
          method: "GET",
          data: null,
          headers: {},
          timeout: 1e4,
          csrf: true,
          interceptor: true,
          retry: 0,
          loading: false,
          autoError: true,
          beforeRequest: null,
          onError: null,
          ...options2
        };
        const self = this;
        return new Promise((resolve, reject) => {
          let retryCount = 0;
          function doRequest() {
            const fetchOpts = {
              method: opts.method,
              headers: { "X-Requested-With": "XMLHttpRequest", ...opts.headers }
            };
            if (opts.csrf && !["GET", "HEAD"].includes(opts.method.toUpperCase())) {
              const token = _getCSRFToken();
              if (token) fetchOpts.headers["X-CSRF-TOKEN"] = token;
            }
            if (opts.data) {
              if (opts.data instanceof FormData) {
                fetchOpts.body = opts.data;
              } else {
                fetchOpts.headers["Content-Type"] = "application/json";
                fetchOpts.body = JSON.stringify(opts.data);
              }
            }
            if (typeof opts.beforeRequest === "function") {
              const result = opts.beforeRequest(fetchOpts);
              if (result === false) {
                self._hideLoading();
                resolve(null);
                return;
              }
            }
            if (opts.loading) self._showLoading();
            const controller = new AbortController();
            fetchOpts.signal = controller.signal;
            const timer = setTimeout(() => controller.abort(), opts.timeout);
            fetch(urlStr, fetchOpts).then((response) => {
              clearTimeout(timer);
              if (!response.ok) {
                return _parseResponse(response).then((body) => {
                  const err = new Error(
                    body && (body.message || body.msg) || `HTTP ${response.status}`
                  );
                  err.status = response.status;
                  err.data = body;
                  throw err;
                });
              }
              return _parseResponse(response);
            }).then((body) => {
              self._hideLoading();
              if (opts.interceptor && self._interceptor && typeof body === "object" && body !== null) {
                const { codeKey, successCode, dataKey, msgKey, unauthorizedCode } = self._interceptor;
                if (codeKey in body) {
                  const code = body[codeKey];
                  if (_isSuccess(code, successCode)) {
                    resolve(body[dataKey] !== void 0 ? body[dataKey] : body);
                    return;
                  }
                  if (unauthorizedCode != null && code == unauthorizedCode) {
                    const err2 = new Error(body[msgKey] || "登录已过期");
                    err2.code = code;
                    err2.data = body[dataKey];
                    err2.unauthorized = true;
                    self._handleUnauthorized(body);
                    self._showError(err2, opts);
                    reject(err2);
                    return;
                  }
                  const err = new Error(body[msgKey] || "业务错误");
                  err.code = code;
                  err.data = body[dataKey];
                  self._showError(err, opts);
                  reject(err);
                  return;
                }
              }
              resolve(body);
            }).catch((err) => {
              clearTimeout(timer);
              self._hideLoading();
              if (err.name === "AbortError") {
                const timeoutErr = new Error("请求超时");
                timeoutErr.status = 0;
                self._showError(timeoutErr, opts);
                reject(timeoutErr);
                return;
              }
              if (retryCount < opts.retry && err.status === void 0) {
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
      get(urlStr, params, options2 = {}) {
        const fullUrl = params ? url.merge(urlStr, params) : urlStr;
        return this.request(fullUrl, { ...options2, method: "GET" });
      },
      /**
       * POST 请求
       * @param {string} urlStr
       * @param {Object} [data]
       * @param {Object} [options]
       * @returns {Promise}
       */
      post(urlStr, data3, options2 = {}) {
        return this.request(urlStr, { ...options2, method: "POST", data: data3 });
      },
      /**
       * PUT 请求
       * @param {string} urlStr
       * @param {Object} [data]
       * @param {Object} [options]
       * @returns {Promise}
       */
      put(urlStr, data3, options2 = {}) {
        return this.request(urlStr, { ...options2, method: "PUT", data: data3 });
      },
      /**
       * DELETE 请求
       * @param {string} urlStr
       * @param {Object} [options]
       * @returns {Promise}
       */
      delete(urlStr, options2 = {}) {
        return this.request(urlStr, { ...options2, method: "DELETE" });
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
      upload(urlStr, file, options2 = {}) {
        const opts = {
          csrf: true,
          interceptor: true,
          autoError: true,
          timeout: 3e4,
          loading: false,
          onProgress: null,
          onError: null,
          ...options2
        };
        let formData;
        if (file instanceof FormData) {
          formData = file;
        } else {
          formData = new FormData();
          formData.append("file", file);
        }
        const self = this;
        return new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest();
          if (opts.onProgress && xhr.upload) {
            xhr.upload.addEventListener("progress", (e) => {
              if (e.lengthComputable) {
                opts.onProgress(
                  Math.round(e.loaded / e.total * 100),
                  e.loaded,
                  e.total
                );
              }
            }, false);
          }
          if (opts.loading) self._showLoading();
          xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            self._hideLoading();
            if (xhr.status === 0) {
              const err = new Error("网络请求失败");
              self._showError(err, opts);
              reject(err);
              return;
            }
            if (xhr.status < 200 || xhr.status >= 300) {
              let errBody;
              try {
                errBody = JSON.parse(xhr.responseText);
              } catch {
                errBody = xhr.responseText;
              }
              const err = new Error(
                errBody && (errBody.message || errBody.msg) || `HTTP ${xhr.status}`
              );
              err.status = xhr.status;
              err.data = errBody;
              self._showError(err, opts);
              reject(err);
              return;
            }
            let body;
            const contentType = xhr.getResponseHeader("content-type") || "";
            if (contentType.includes("application/json")) {
              try {
                body = JSON.parse(xhr.responseText);
              } catch {
                body = xhr.responseText;
              }
            } else {
              body = xhr.responseText;
            }
            if (opts.interceptor && self._interceptor && typeof body === "object" && body !== null) {
              const { codeKey, successCode, dataKey, msgKey, unauthorizedCode } = self._interceptor;
              if (codeKey in body) {
                const code = body[codeKey];
                if (_isSuccess(code, successCode)) {
                  resolve(body[dataKey] !== void 0 ? body[dataKey] : body);
                  return;
                }
                if (unauthorizedCode != null && code == unauthorizedCode) {
                  const err2 = new Error(body[msgKey] || "登录已过期");
                  err2.code = code;
                  err2.data = body[dataKey];
                  err2.unauthorized = true;
                  self._handleUnauthorized(body);
                  self._showError(err2, opts);
                  reject(err2);
                  return;
                }
                const err = new Error(body[msgKey] || "业务错误");
                err.code = code;
                err.data = body[dataKey];
                self._showError(err, opts);
                reject(err);
                return;
              }
            }
            resolve(body);
          };
          xhr.onerror = function() {
            self._hideLoading();
            const err = new Error("网络请求失败");
            self._showError(err, opts);
            reject(err);
          };
          xhr.ontimeout = function() {
            self._hideLoading();
            const err = new Error("请求超时");
            err.status = 0;
            self._showError(err, opts);
            reject(err);
          };
          xhr.timeout = opts.timeout;
          xhr.open("POST", urlStr, true);
          xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
          if (opts.csrf) {
            const token = _getCSRFToken();
            if (token) xhr.setRequestHeader("X-CSRF-TOKEN", token);
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
      submit(formEl, options2 = {}) {
        const form = typeof formEl === "string" ? document.querySelector(formEl) : formEl;
        if (!form) {
          return Promise.reject(new Error("表单元素不存在"));
        }
        const urlStr = form.getAttribute("action") || window.location.href;
        const method = (form.getAttribute("method") || "POST").toUpperCase();
        const formData = new FormData(form);
        return this.request(urlStr, { ...options2, method, data: formData });
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
        const workers = Array(Math.min(concurrency, reqFns.length)).fill(null).map(() => worker());
        await Promise.all(workers);
        return results;
      }
    };
    _locale = "zh-cn";
    _messages = {};
    i18n = {
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
            msg = msg.replace(new RegExp(`%s|%d|\\$\\{${i}\\}`, "g"), arg);
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
      getLocale() {
        return _locale;
      }
    };
    codec = {
      base64Encode(str) {
        try {
          return btoa(unescape(encodeURIComponent(str)));
        } catch {
          return btoa(str);
        }
      },
      base64Decode(str) {
        try {
          return decodeURIComponent(escape(atob(str)));
        } catch {
          return atob(str);
        }
      },
      htmlEscape(str) {
        const charMap = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };
        return String(str).replace(/[&<>"']/g, (matchedChar) => charMap[matchedChar]);
      },
      htmlUnescape(str) {
        const entityMap = { "&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": '"', "&#39;": "'" };
        return String(str).replace(/&(amp|lt|gt|quot|#39);/g, (matchedEntity) => entityMap[matchedEntity]);
      },
      htmlPurify(str) {
        return this.htmlEscape(str);
      }
    };
    type = {
      isString(v) {
        return typeof v === "string";
      },
      isNumber(v) {
        return typeof v === "number" && !isNaN(v);
      },
      isBoolean(v) {
        return typeof v === "boolean";
      },
      isArray(v) {
        return Array.isArray(v);
      },
      isObject(v) {
        return v !== null && typeof v === "object" && !Array.isArray(v);
      },
      isFunction(v) {
        return typeof v === "function";
      },
      isNull(v) {
        return v === null;
      },
      isUndefined(v) {
        return v === void 0;
      },
      isEmpty(v) {
        if (v == null) return true;
        if (typeof v === "string") return v.trim().length === 0;
        if (Array.isArray(v)) return v.length === 0;
        if (typeof v === "object") return Object.keys(v).length === 0;
        return false;
      },
      isPromise(v) {
        return v && typeof v.then === "function";
      },
      isElement(v) {
        return v instanceof HTMLElement;
      },
      isMobile() {
        return /Mobi|Android/i.test(navigator.userAgent);
      }
    };
    data = {
      clone(obj) {
        if (obj == null || typeof obj !== "object") return obj;
        if (obj instanceof Date) return new Date(obj);
        if (Array.isArray(obj)) return obj.map((item) => this.clone(item));
        const cloned = {};
        for (const [k, v] of Object.entries(obj)) cloned[k] = this.clone(v);
        return cloned;
      },
      merge(target, ...sources) {
        for (const src of sources) {
          if (!src) continue;
          for (const [k, v] of Object.entries(src)) {
            if (typeof v === "object" && !Array.isArray(v) && v !== null) {
              target[k] = this.merge(target[k] || {}, v);
            } else {
              target[k] = v;
            }
          }
        }
        return target;
      },
      get(obj, path, defaultValue) {
        const keys = String(path).split(".");
        let cur = obj;
        for (const k of keys) {
          if (cur == null || typeof cur !== "object") return defaultValue;
          cur = cur[k];
        }
        return cur !== void 0 ? cur : defaultValue;
      },
      set(obj, path, value) {
        const keys = String(path).split(".");
        const last = keys.pop();
        let cur = obj;
        for (const k of keys) {
          if (!(k in cur) || typeof cur[k] !== "object") cur[k] = {};
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
        return function(...args) {
          clearTimeout(timer);
          timer = setTimeout(() => fn.apply(this, args), delay);
        };
      },
      throttle(fn, delay = 300) {
        let last = 0;
        return function(...args) {
          const now = Date.now();
          if (now - last >= delay) {
            last = now;
            fn.apply(this, args);
          }
        };
      },
      uid(prefix = "ws") {
        return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
      }
    };
    storage = {
      get(key, defaultValue) {
        try {
          const val = localStorage.getItem(key);
          if (val === null) return defaultValue;
          try {
            return JSON.parse(val);
          } catch {
            return val;
          }
        } catch {
          return defaultValue;
        }
      },
      set(key, value) {
        try {
          localStorage.setItem(key, JSON.stringify(value));
        } catch {
        }
      },
      remove(key) {
        try {
          localStorage.removeItem(key);
        } catch {
        }
      },
      session: {
        get(key, def) {
          try {
            const val = sessionStorage.getItem(key);
            if (val === null) return def;
            try {
              return JSON.parse(val);
            } catch {
              return val;
            }
          } catch {
            return def;
          }
        },
        set(key, value) {
          try {
            sessionStorage.setItem(key, JSON.stringify(value));
          } catch {
          }
        },
        remove(key) {
          try {
            sessionStorage.removeItem(key);
          } catch {
          }
        }
      },
      cookie: {
        get(name) {
          const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
          return match ? decodeURIComponent(match[1]) : null;
        },
        set(name, value, days = 30, path = "/") {
          const d = /* @__PURE__ */ new Date();
          d.setTime(d.getTime() + days * 864e5);
          document.cookie = `${name}=${encodeURIComponent(value)};expires=${d.toUTCString()};path=${path}`;
        },
        remove(name, path = "/") {
          document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=${path}`;
        }
      }
    };
  }
});

// public/statics/modules/wise-ui.js
var wise_ui_exports = {};
__export(wise_ui_exports, {
  action: () => action,
  clipboard: () => clipboard,
  contextMenu: () => contextMenu,
  dialog: () => dialog,
  hotkey: () => hotkey,
  loading: () => loading,
  notify: () => notify,
  toast: () => toast
});
function _ensureContainer() {
  let container = document.getElementById(TOAST_CONTAINER_ID);
  if (!container) {
    container = document.createElement("div");
    container.id = TOAST_CONTAINER_ID;
    container.className = "toast-container position-fixed top-0 end-0 p-3";
    container.style.zIndex = "9999";
    document.body.appendChild(container);
  }
  return container;
}
function _ensureBusyBar() {
  let el = document.getElementById(BUSY_BAR_ID);
  if (!el && document.body) {
    el = document.createElement("div");
    el.id = BUSY_BAR_ID;
    el.className = "ws-busy-bar";
    el.setAttribute("role", "progressbar");
    el.setAttribute("aria-hidden", "true");
    el.innerHTML = '<div class="ws-busy-bar-inner"></div>';
    document.body.appendChild(el);
  }
  return el;
}
function _syncBusyBar() {
  const active = _busyCount > 0;
  const el = _ensureBusyBar();
  if (el) {
    el.classList.toggle("is-active", active);
    el.setAttribute("aria-hidden", active ? "false" : "true");
  }
  if (document.body) document.body.classList.toggle("ws-busy", active);
}
function _enterLoading(target) {
  if (!target) return () => {
  };
  target.setAttribute("data-ws-state", "loading");
  target.classList.add("ws-btn-loading");
  target.disabled = true;
  return () => {
    target.removeAttribute("data-ws-state");
    target.classList.remove("ws-btn-loading");
    target.disabled = false;
  };
}
var TOAST_CONTAINER_ID, TOAST_ICONS, TOAST_TYPES, toast, dialog, notify, _ctxMenuEl, _ctxMenuCleanup, _ctxMenuInstance, contextMenu, _hotkeys, _hotkeyInstance, _hotkeyBindingMap, hotkey, clipboard, BUSY_BAR_ID, _busyCount, loading, action;
var init_wise_ui = __esm({
  "public/statics/modules/wise-ui.js"() {
    init_wise_core();
    init_wise_util();
    TOAST_CONTAINER_ID = "ws-toast-container";
    TOAST_ICONS = {
      success: "bi-check-circle-fill",
      error: "bi-x-circle-fill",
      warning: "bi-exclamation-triangle-fill",
      info: "bi-info-circle-fill"
    };
    TOAST_TYPES = Object.keys(TOAST_ICONS);
    toast = {
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
      show(message, type2 = "info", options2 = {}) {
        if (!TOAST_TYPES.includes(type2)) type2 = "info";
        const { duration = config.get("toast.duration", 5e3), title = "" } = options2;
        if (typeof window.wsLayer !== "undefined" && window.wsLayer.toast) {
          return window.wsLayer.toast({
            content: message,
            type: type2,
            time: duration,
            position: options2.position || "top-end",
            end: options2.onEnd || null
          });
        }
        const container = _ensureContainer();
        const id = `ws-toast-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        const wrapper = document.createElement("div");
        wrapper.id = id;
        wrapper.className = `toast fade show`;
        wrapper.setAttribute("role", "alert");
        wrapper.setAttribute("aria-live", "assertive");
        wrapper.innerHTML = `
            <div class="toast-header bg-${type2} text-white">
                <i class="bi ${TOAST_ICONS[type2]} me-2"></i>
                <strong class="me-auto">${title || type2.charAt(0).toUpperCase() + type2.slice(1)}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">${message}</div>
        `;
        container.appendChild(wrapper);
        if (typeof window.bootstrap !== "undefined" && window.bootstrap.Toast) {
          const bsToast = new window.bootstrap.Toast(wrapper, { delay: duration });
          bsToast.show();
          wrapper.addEventListener("hidden.bs.toast", () => wrapper.remove());
        }
        setTimeout(() => {
          if (wrapper.parentNode) wrapper.remove();
        }, duration + 1e3);
      },
      success(msg, opts) {
        this.show(msg, "success", opts);
      },
      error(msg, opts) {
        this.show(msg, "error", opts);
      },
      warning(msg, opts) {
        this.show(msg, "warning", opts);
      },
      info(msg, opts) {
        this.show(msg, "info", opts);
      },
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
      action(message, options2 = {}) {
        const {
          actionText = "撤销",
          duration = 6e3,
          type: type2 = "info"
        } = options2;
        return new Promise((resolve) => {
          const container = _ensureContainer();
          const id = `ws-toast-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
          const wrapper = document.createElement("div");
          wrapper.id = id;
          wrapper.className = "toast fade show";
          wrapper.setAttribute("role", "alert");
          const bgClass = `bg-${type2} text-white`;
          const iconClass = TOAST_ICONS[type2] || TOAST_ICONS.info;
          wrapper.innerHTML = `
                <div class="toast-header ${bgClass}">
                    <i class="bi ${iconClass} me-2"></i>
                    <strong class="me-auto">提示</strong>
                    <small class="opacity-75">${duration / 1e3}s 内可${actionText}</small>
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
          const actionBtn = wrapper.querySelector(".ws-toast-action");
          if (actionBtn) {
            actionBtn.addEventListener("click", () => finish(true));
          }
          const timer = setTimeout(() => finish(false), duration);
          wrapper.addEventListener("hidden.bs.toast", () => finish(false));
          setTimeout(() => {
            if (wrapper.parentNode) wrapper.remove();
          }, duration + 2e3);
        });
      }
    };
    dialog = {
      /**
       * 提示框
       * @param {string} message
       * @param {Object} [options]
       * @param {string} [options.title] - 标题
       * @returns {Promise<void>}
       */
      alert(message, options2 = {}) {
        return new Promise((resolve) => {
          if (typeof window.wsLayer !== "undefined" && window.wsLayer.alert) {
            window.wsLayer.alert(message, { title: options2.title || "提示" }, () => resolve());
            return;
          }
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
      confirm(message, options2 = {}) {
        return new Promise((resolve) => {
          if (typeof window.wsLayer !== "undefined" && window.wsLayer.confirm) {
            window.wsLayer.confirm(
              message,
              { title: options2.title || "确认", btn: ["确定", "取消"], icon: "danger" },
              () => {
                resolve(true);
                return true;
              },
              () => {
                resolve(false);
                return true;
              }
            );
            return;
          }
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
      open(content, title, options2 = {}) {
        if (typeof window.wsLayer !== "undefined" && window.wsLayer.open) {
          return window.wsLayer.open({
            type: typeof content === "string" && content.startsWith("/") ? 2 : 1,
            title,
            content,
            area: options2.area || ["800px", "500px"],
            ...options2
          });
        }
        if (typeof window.bootstrap !== "undefined" && window.bootstrap.Modal) {
          const modalEl = document.getElementById("ws-modal");
          if (modalEl) {
            const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
          }
          return;
        }
        logger.warn("ui", "未找到 ws-layer 或 Bootstrap Modal，弹窗不可用");
      },
      /**
       * 关闭弹窗
       * @param {number} [index]
       */
      close(index) {
        if (typeof window.wsLayer !== "undefined" && window.wsLayer.close) {
          window.wsLayer.close(index);
        }
      },
      /** 关闭全部 */
      closeAll() {
        this.close();
      }
    };
    notify = {
      /**
       * 发送浏览器通知（需要权限）
       * @param {string} title
       * @param {Object} [options] - { body, icon }
       */
      send(title, options2 = {}) {
        if (!("Notification" in window)) return;
        if (Notification.permission === "granted") {
          new Notification(title, options2);
        } else if (Notification.permission !== "denied") {
          Notification.requestPermission().then((p) => {
            if (p === "granted") new Notification(title, options2);
          });
        }
      },
      /**
       * 页面内 Banner 通知
       * @param {string} message
       * @param {'success'|'error'|'warning'|'info'} [type]
       */
      banner(message, type2 = "info") {
        toast.show(message, type2, { duration: 8e3 });
        Promise.resolve().then(() => (init_wise_core(), wise_core_exports)).then(({ eventBus: eventBus2 }) => {
          eventBus2.emit("ws:ui:notify", { message, type: type2 });
        });
      }
    };
    _ctxMenuEl = null;
    _ctxMenuCleanup = null;
    _ctxMenuInstance = null;
    contextMenu = {
      /**
       * 显示右键菜单
       * @param {Array<{label:string,icon?:string,action?:Function,id?:string,divider?:boolean,disabled?:boolean}>} items
       * @param {Object} [options]
       * @param {number} [options.x] - 坐标
       * @param {number} [options.y]
       */
      show(items, options2 = {}) {
        this.hide();
        if (typeof window.WsPopMenu !== "undefined") {
          const mappedItems = items.map((item) => {
            if (item.divider) {
              return { type: "divider" };
            }
            return {
              text: item.label,
              icon: item.icon || "",
              id: item.id || "",
              disabled: !!item.disabled,
              type: "item"
            };
          });
          const menu = new window.WsPopMenu({
            items: mappedItems,
            trigger: "contextmenu",
            offsetX: 0,
            offsetY: 0,
            autoClose: true,
            onItemClick: (id) => {
              const item = items.find((i) => i.id === id);
              if (item && item.action) item.action();
            },
            onHide: () => {
              if (_ctxMenuCleanup) {
                const cleanup = _ctxMenuCleanup;
                _ctxMenuCleanup = null;
                _ctxMenuInstance = null;
                cleanup();
              }
            }
          });
          menu.updatePosition(options2.x || 0, options2.y || 0);
          menu.show();
          _ctxMenuInstance = menu;
          _ctxMenuCleanup = () => menu.destroy();
          return;
        }
        _ctxMenuEl = document.createElement("div");
        _ctxMenuEl.className = "dropdown-menu show position-fixed";
        _ctxMenuEl.style.cssText = `
            z-index: 9999;
            top: ${options2.y || 0}px;
            left: ${options2.x || 0}px;
        `;
        items.forEach((item) => {
          if (item.divider) {
            _ctxMenuEl.appendChild(document.createElement("hr")).className = "dropdown-divider";
            return;
          }
          const a = document.createElement("a");
          a.className = `dropdown-item${item.disabled ? " disabled" : ""}`;
          a.href = "#";
          a.textContent = item.label;
          if (item.icon) {
            a.innerHTML = `<i class="bi ${item.icon} me-2"></i>${item.label}`;
          }
          if (!item.disabled && item.action) {
            a.addEventListener("click", (e) => {
              e.preventDefault();
              this.hide();
              item.action(e);
            });
          } else {
            a.addEventListener("click", (e) => e.preventDefault());
          }
          _ctxMenuEl.appendChild(a);
        });
        document.body.appendChild(_ctxMenuEl);
        const handler = (e) => {
          if (!_ctxMenuEl.contains(e.target)) this.hide();
        };
        document.addEventListener("click", handler);
        _ctxMenuCleanup = () => document.removeEventListener("click", handler);
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
      }
    };
    _hotkeys = {};
    _hotkeyInstance = null;
    _hotkeyBindingMap = {};
    hotkey = {
      /**
       * 获取（或创建）HotKey 实例
       * @returns {Object|null}
       */
      _getInstance() {
        if (!_hotkeyInstance && typeof window.HotKey !== "undefined") {
          _hotkeyInstance = new window.HotKey("wise-ui");
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
      bind(keys, handler, options2 = {}) {
        const inst = this._getInstance();
        if (inst) {
          const normalizedKeys = keys.toLowerCase();
          const id2 = inst.register(normalizedKeys, handler, options2.description || "");
          _hotkeyBindingMap[normalizedKeys] = id2;
          return id2;
        }
        const id = keys.toLowerCase().replace(/\+/g, "-");
        _hotkeys[id] = { keys, handler, options: options2 };
      },
      /**
       * 解除绑定
       * @param {string} keys
       */
      unbind(keys) {
        const normalizedKeys = keys.toLowerCase();
        const inst = this._getInstance();
        if (inst && _hotkeyBindingMap[normalizedKeys]) {
          inst.unregister(_hotkeyBindingMap[normalizedKeys]);
          delete _hotkeyBindingMap[normalizedKeys];
          return;
        }
        const id = normalizedKeys.replace(/\+/g, "-");
        delete _hotkeys[id];
      },
      /** 临时禁用所有快捷键 */
      suspend() {
        const inst = this._getInstance();
        if (inst) {
          inst.setEnabled(false);
          return;
        }
        this._suspended = true;
      },
      /** 恢复快捷键 */
      resume() {
        const inst = this._getInstance();
        if (inst) {
          inst.setEnabled(true);
          return;
        }
        this._suspended = false;
      }
    };
    document.addEventListener("keydown", function(e) {
      if (hotkey._suspended) return;
      const target = e.target;
      if (target.tagName === "INPUT" || target.tagName === "TEXTAREA" || target.tagName === "SELECT") return;
      const parts = [];
      if (e.ctrlKey || e.metaKey) parts.push("Ctrl");
      if (e.altKey) parts.push("Alt");
      if (e.shiftKey) parts.push("Shift");
      const key = e.key.length === 1 ? e.key.toUpperCase() : e.key;
      if (!["Control", "Alt", "Shift"].includes(key)) parts.push(key);
      const id = parts.join("+").toLowerCase();
      const entry = _hotkeys[id];
      if (entry) {
        e.preventDefault();
        entry.handler(e);
      }
    });
    clipboard = {
      /**
       * 复制文本到剪贴板
       * @param {string} text
       * @param {Object} [options] - 透传给底层 Clipboard 类
       * @returns {Promise<boolean>}
       */
      async copy(text, options2 = {}) {
        if (typeof window.Clipboard !== "undefined" && window.Clipboard.copy) {
          return await window.Clipboard.copy(text, options2);
        }
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
          }
          const textarea = document.createElement("textarea");
          textarea.value = text;
          textarea.style.position = "fixed";
          textarea.style.opacity = "0";
          document.body.appendChild(textarea);
          textarea.select();
          document.execCommand("copy");
          document.body.removeChild(textarea);
          return true;
        } catch {
          logger.warn("ui", "复制到剪贴板失败");
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
          return "";
        } catch {
          return "";
        }
      },
      /**
       * 检查剪贴板是否可用
       * @param {'copy'|'cut'} [action='copy']
       * @returns {boolean}
       */
      isSupported(action3 = "copy") {
        if (typeof window.Clipboard !== "undefined" && window.Clipboard.isSupported) {
          return window.Clipboard.isSupported(action3);
        }
        return !!(navigator.clipboard && navigator.clipboard.writeText);
      }
    };
    BUSY_BAR_ID = "ws-busy-bar";
    _busyCount = 0;
    loading = {
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
      }
    };
    action = {
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
          url: url2,
          data: data3,
          restoreUrl,
          message = "删除成功",
          restoreData = null,
          confirmMessage = "确定要删除选中的记录吗？",
          confirmTitle = "确认删除",
          undoDuration = 5e3,
          target = null
        } = spec;
        const confirmed = await dialog.confirm(confirmMessage, { title: confirmTitle });
        if (!confirmed) return { success: false, undone: false };
        const exitLoading = _enterLoading(target);
        try {
          await http.post(url2, data3);
          exitLoading();
          const count = data3.ids ? data3.ids.length : data3.id ? 1 : 0;
          const displayMsg = message || (count > 1 ? `已删除 ${count} 条记录` : "已删除");
          const { undone } = await toast.action(displayMsg, {
            actionText: "撤销",
            duration: undoDuration,
            type: "warning"
          });
          if (undone && restoreUrl) {
            const restorePayload = restoreData || data3;
            try {
              await http.post(restoreUrl, restorePayload);
              toast.success(count > 1 ? `已恢复 ${count} 条记录` : "已恢复");
              return { success: true, undone: true };
            } catch (e) {
              toast.error("恢复失败：" + (e.message || "未知错误"));
              return { success: true, undone: false };
            }
          }
          return { success: true, undone: false };
        } catch (e) {
          exitLoading();
          toast.error(e.message || "操作失败");
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
          method = "POST",
          url: url2,
          data: data3 = {},
          successMsg = "操作成功",
          errorMsg,
          confirmMessage = null,
          confirmTitle = "确认操作",
          target = null,
          toastType = "success"
        } = spec;
        if (confirmMessage) {
          const confirmed = await dialog.confirm(confirmMessage, { title: confirmTitle });
          if (!confirmed) return { success: false };
        }
        const exitLoading = _enterLoading(target);
        try {
          const result = await http.request(url2, { method, data: data3, interceptor: true });
          exitLoading();
          toast[toastType](successMsg);
          return { success: true, data: result };
        } catch (e) {
          exitLoading();
          const msg = errorMsg || e.message || "操作失败";
          toast.error(msg);
          return { success: false, data: null };
        }
      }
    };
  }
});

// public/statics/modules/wise-adapter.js
var wise_adapter_exports = {};
var init_wise_adapter = __esm({
  "public/statics/modules/wise-adapter.js"() {
    (function() {
      "use strict";
      if (!window.Wise) {
        console.warn("[WiseAdapter] Wise 核心未加载，跳过适配");
        return;
      }
      const W2 = window.Wise;
      const warned = {};
      const deprecate = (oldName, newName) => {
        if (!warned[oldName]) {
          console.warn(`[WiseAdapter] \`${oldName}\` 已废弃，请使用 \`${newName}\``);
          warned[oldName] = true;
        }
      };
      if (typeof window.Toolkit === "undefined") {
        const _Toolkit = {
          config: {
            get apiBaseUrl() {
              deprecate("Toolkit.config.apiBaseUrl", "Wise.config");
              return W2.config.get("apiBaseUrl");
            },
            set apiBaseUrl(value) {
              W2.config.set("apiBaseUrl", value);
            }
          },
          events: {
            onSuccess(data3, callback, message) {
              deprecate("Toolkit.events.onSuccess", "Wise.ui.toast.success");
              W2.ui.toast.success(message || "操作成功");
              if (typeof callback === "function") callback(data3);
            },
            onError(error, callback, message) {
              deprecate("Toolkit.events.onError", "Wise.ui.toast.error");
              const resp = error?.jqXHR?.responseJSON;
              const errMsg = message || (resp?.msg || resp?.message) || error?.statusText || "操作失败";
              W2.ui.toast.error(errMsg);
              if (typeof callback === "function") callback(error);
            }
          },
          api: {
            showToast(msg, type2) {
              deprecate("Toolkit.api.showToast", "Wise.ui.toast.show");
              W2.ui.toast.show(msg, type2);
            },
            fixUrl(url2, isAbsolute) {
              deprecate("Toolkit.api.fixUrl", "Wise.util.url.fix");
              return W2.util.url.fix(url2, isAbsolute);
            },
            getUrlParam() {
              deprecate("Toolkit.api.getUrlParam", "Wise.util.url.parseParams / getParam");
              if (arguments.length === 0) return W2.util.url.parseParams();
              return W2.util.url.getParam(arguments[0]);
            },
            buildQueryParams(params) {
              deprecate("Toolkit.api.buildQueryParams", "Wise.util.url.buildParams");
              return W2.util.url.buildParams(params);
            },
            request(url2, data3, type2) {
              deprecate("Toolkit.api.request", "Wise.util.http.request");
              const method = type2 || "GET";
              if (method === "GET") return W2.util.http.get(url2, data3);
              return W2.util.http.post(url2, data3);
            }
          }
        };
        window.Toolkit = _Toolkit;
      }
      if (typeof window.L === "undefined") {
        window.L = function() {
          deprecate("window.L()", "Wise.util.i18n.t()");
          return W2.util.i18n.t.apply(W2.util.i18n, arguments);
        };
      }
      if (typeof window.Layer === "undefined") {
        window.Layer = W2.ui.dialog;
      }
      if (typeof window.Framework === "undefined") {
        window.Framework = {
          switchTheme(theme2) {
            deprecate("Framework.switchTheme", 'Wise.config.set("theme")');
            W2.config.set("theme", theme2);
          },
          getTheme() {
            return W2.config.get("theme", "light");
          }
        };
      }
      if (typeof window.navtab === "undefined") {
        window.navtab = {};
        ["add", "close", "switchTo", "refresh"].forEach((method) => {
          window.navtab[method] = function() {
            deprecate(`navtab.${method}`, `Wise.navtabs.${method}`);
            if (W2.navtabs && W2.navtabs[method]) {
              return W2.navtabs[method].apply(W2.navtabs, arguments);
            }
          };
        });
      }
      if (typeof window.EnhancedTable === "undefined") {
        window.EnhancedTable = {
          init(selector, opts) {
            deprecate("EnhancedTable.init", "Wise.table.create");
            if (W2.table) return W2.table.create(selector, opts);
          },
          FORMATTERS: new Proxy({}, {
            get(target, prop) {
              if (W2.table && W2.table.formatters) {
                return W2.table.formatters[prop];
              }
            }
          })
        };
      }
      if (typeof window.permissionManager === "undefined") {
        window.permissionManager = {
          init() {
            deprecate("permissionManager.init", "Wise.permission.init");
            if (W2.permission) return W2.permission.init();
          },
          can(perm) {
            deprecate("permissionManager.can", "Wise.permission.can");
            if (W2.permission) return W2.permission.can(perm);
            return true;
          },
          autoControl() {
            deprecate("permissionManager.autoControl", "Wise.permission.autoControl");
            if (W2.permission) W2.permission.autoControl();
          }
        };
      }
      console.log("[WiseAdapter] 向后兼容适配层已就绪");
    })();
  }
});

// public/statics/modules/wise-permission.js
var wise_permission_exports = {};
__export(wise_permission_exports, {
  default: () => wise_permission_default,
  permission: () => permission
});
var permission, wise_permission_default;
var init_wise_permission = __esm({
  "public/statics/modules/wise-permission.js"() {
    init_wise_core();
    init_wise_util();
    permission = {
      _permissions: [],
      _menus: [],
      _initialized: false,
      /**
       * 初始化权限（从后端加载）
       * @param {Object} [options]
       * @param {string} [options.permissionsUrl='/admin/api/user/permissions']
       * @param {string} [options.menusUrl='/admin/api/user/menus']
       * @returns {Promise<boolean>}
       */
      async init(options2 = {}) {
        const permissionsUrl = options2.permissionsUrl || "/admin/api/index/permissions";
        const menusUrl = options2.menusUrl || "/admin/api/index/menus";
        try {
          const [permRes, menuRes] = await Promise.all([
            http.get(permissionsUrl),
            http.get(menusUrl)
          ]);
          this._permissions = permRes?.data || permRes?.list || [];
          this._menus = menuRes?.data || menuRes?.list || [];
          this._initialized = true;
          logger.info("permission", `已加载 ${this._permissions.length} 个权限`);
          eventBus.emit("ws:permission:loaded", {
            permissions: this._permissions,
            menus: this._menus
          });
          return true;
        } catch (err) {
          logger.error("permission", "权限加载失败", err);
          this._permissions = [];
          this._menus = [];
          this._initialized = true;
          return false;
        }
      },
      /**
       * 检查是否有某个权限
       * @param {string} perm - 权限码
       * @returns {boolean}
       */
      can(perm) {
        if (!this._initialized) return true;
        return this._permissions.includes(perm);
      },
      /**
       * 检查是否有任意一个权限
       * @param {string[]} perms
       * @returns {boolean}
       */
      canAny(perms) {
        if (!this._initialized) return true;
        return perms.some((p) => this._permissions.includes(p));
      },
      /**
       * 检查是否有全部权限
       * @param {string[]} perms
       * @returns {boolean}
       */
      canAll(perms) {
        if (!this._initialized) return true;
        return perms.every((p) => this._permissions.includes(p));
      },
      /**
       * 获取当前用户的权限列表
       * @returns {string[]}
       */
      all() {
        return [...this._permissions];
      },
      /**
       * 自动控制 DOM 元素的显隐
       * 扫描 [data-ws-permission] 元素，无权限则隐藏
       */
      autoControl() {
        document.querySelectorAll("[data-ws-permission]").forEach((el) => {
          const perm = el.dataset.wsPermission;
          if (perm && !this.can(perm)) {
            el.style.display = "none";
          }
        });
        document.querySelectorAll("[data-permission]").forEach((el) => {
          const perm = el.dataset.permission;
          if (perm && !this.can(perm)) {
            el.style.display = "none";
          }
        });
      },
      /**
       * 渲染侧边栏菜单
       * @param {string} [containerId='sidebarMenu']
       */
      renderMenu(containerId = "sidebarMenu") {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = "";
        this._menus.forEach((menu) => {
          if (this._checkMenuPermission(menu)) {
            const item = this._renderMenuItem(menu);
            container.appendChild(item);
          }
        });
      },
      /**
       * 过滤有权限的菜单项
       * @param {Array} menus
       * @returns {Array}
       */
      filterMenus(menus) {
        if (!Array.isArray(menus)) return [];
        return menus.filter((menu) => this._checkMenuPermission(menu)).map((menu) => ({
          ...menu,
          children: menu.children ? this.filterMenus(menu.children) : []
        }));
      },
      // ---- 私有 ----
      _checkMenuPermission(menu) {
        const perm = menu.permission || menu.perm || "";
        if (!perm) return true;
        return this.can(perm);
      },
      _renderMenuItem(menu) {
        const li = document.createElement("li");
        li.className = "nav-item";
        if (menu.active) li.classList.add("menu-open");
        if (menu.children && menu.children.length > 0) {
          const hasVisible = menu.children.some((c) => this._checkMenuPermission(c));
          if (!hasVisible) {
            li.style.display = "none";
            return li;
          }
          li.innerHTML = `
                <a href="#" class="nav-link">
                    <i class="nav-icon ${menu.icon || "bi-circle"}"></i>
                    <p>${menu.title || menu.name}<i class="nav-arrow bi bi-chevron-right"></i></p>
                </a>
            `;
          const ul = document.createElement("ul");
          ul.className = "nav nav-treeview";
          menu.children.forEach((child) => {
            if (this._checkMenuPermission(child)) {
              ul.appendChild(this._renderMenuChild(child));
            }
          });
          li.appendChild(ul);
        } else {
          li.innerHTML = `
                <a href="${menu.url || "#"}" class="nav-link ${menu.active ? "active" : ""}">
                    <i class="nav-icon ${menu.icon || "bi-circle"}"></i>
                    <p>${menu.title || menu.name}</p>
                </a>
            `;
        }
        return li;
      },
      _renderMenuChild(child) {
        const li = document.createElement("li");
        li.className = "nav-item";
        li.innerHTML = `
            <a href="${child.url || "#"}" class="nav-link ${child.active ? "active" : ""}">
                <i class="nav-icon ${child.icon || "bi-circle"}"></i>
                <p>${child.title || child.name}</p>
            </a>
        `;
        return li;
      }
    };
    registry.register("permission", permission);
    wise_permission_default = permission;
  }
});

// public/statics/modules/wise-upload.js
var wise_upload_exports = {};
__export(wise_upload_exports, {
  default: () => wise_upload_default,
  upload: () => upload
});
function $9(selector, parent2) {
  if (selector instanceof HTMLElement || selector instanceof Document) return selector;
  return (parent2 || document).querySelector(selector);
}
function $$(selector, parent2) {
  return Array.from((parent2 || document).querySelectorAll(selector));
}
function createEl(tag, attrs = {}, children = []) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === "className") el.className = v;
    else if (k === "innerHTML") el.innerHTML = v;
    else if (k === "style" && typeof v === "object") Object.assign(el.style, v);
    else if (k.startsWith("data")) el.setAttribute(k.replace(/([A-Z])/g, "-$1").toLowerCase(), v);
    else if (k.startsWith("on") && typeof v === "function") el.addEventListener(k.slice(2).toLowerCase(), v);
    else el.setAttribute(k, v);
  }
  children.forEach((c) => {
    if (c) el.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
  });
  return el;
}
function showToast(msg, type2 = "success") {
  try {
    if (window.Layer && typeof window.Layer.top === "function") {
      window.Layer.top().toast({ content: msg, type: type2 });
      return;
    }
    if (window.Layer && typeof window.Layer.toast === "function") {
      window.Layer.toast({ content: msg, type: type2 });
      return;
    }
  } catch (_) {
  }
  eventBus.emit("ws:toast", { message: msg, type: type2 });
}
function getCropper() {
  if (window.Cropper) return window.Cropper;
  if (window.requirejs && window.requirejs.defined && window.requirejs.defined("cropper")) {
    return window.requirejs("cropper");
  }
  return null;
}
var upload, wise_upload_default;
var init_wise_upload = __esm({
  "public/statics/modules/wise-upload.js"() {
    init_wise_core();
    init_wise_util();
    upload = {
      /**
       * 头像上传（含裁切预览）
       * @param {Object} options
       * @param {string|HTMLElement} options.el - 触发元素
       * @param {string} options.url - 上传地址
       * @param {number} [options.maxSize=2] - MB
       * @param {string[]} [options.accept=['image/*']] - 接受的文件类型
       * @param {Function} [options.onSuccess] - (url) => void
       * @param {Function} [options.onError] - (err) => void
       * @param {Function} [options.onProgress] - (percent) => void
       */
      avatar(options2) {
        const {
          el,
          url: url2,
          maxSize = 2,
          accept = ["image/*"],
          onSuccess,
          onError,
          onProgress
        } = options2;
        const trigger = typeof el === "string" ? document.querySelector(el) : el;
        if (!trigger) return;
        const input = document.createElement("input");
        input.type = "file";
        input.accept = accept.join(",");
        input.style.display = "none";
        document.body.appendChild(input);
        trigger.addEventListener("click", () => input.click());
        input.addEventListener("change", async () => {
          const file = input.files[0];
          if (!file) return;
          if (file.size > maxSize * 1024 * 1024) {
            eventBus.emit("ws:upload:error", { file, error: "文件过大" });
            if (onError) onError(new Error(`文件不能超过 ${maxSize}MB`));
            return;
          }
          try {
            const formData = new FormData();
            formData.append("file", file);
            const result = await http.upload(url2, formData, {
              onProgress: (pct) => {
                if (onProgress) onProgress(pct);
                eventBus.emit("ws:upload:progress", { file, percent: pct });
              }
            });
            const avatarUrl = result.url || result;
            if (onSuccess) onSuccess(avatarUrl);
            eventBus.emit("ws:upload:success", { file, result });
          } catch (err) {
            logger.error("upload", "上传失败", err);
            if (onError) onError(err);
            eventBus.emit("ws:upload:error", { file, error: err });
          }
          input.value = "";
        });
      },
      /**
       * 通用文件上传
       * @param {Object} options
       * @param {string|HTMLElement} options.el - 触发元素或容器
       * @param {string} options.url - 上传地址
       * @param {number} [options.maxSize=10] - MB
       * @param {string[]} [options.accept] - 文件类型
       * @param {boolean} [options.multiple=false] - 多文件
       * @param {boolean} [options.dropzone=false] - 拖拽上传
       * @param {Function} [options.onSuccess] - (file, result) => void
       * @param {Function} [options.onError] - (file, err) => void
       * @param {Function} [options.onProgress] - (file, percent) => void
       * @returns {{ destroy: Function }} 清理函数
       */
      file(options2) {
        const {
          el,
          url: url2,
          maxSize = 10,
          accept = [],
          multiple = false,
          dropzone = false,
          onSuccess,
          onError,
          onProgress
        } = options2;
        const container = typeof el === "string" ? document.querySelector(el) : el;
        if (!container) return { destroy() {
        } };
        const uploadFile = async (file) => {
          if (file.size > maxSize * 1024 * 1024) {
            if (onError) onError(file, new Error(`文件 ${file.name} 超过 ${maxSize}MB`));
            return;
          }
          try {
            const formData = new FormData();
            formData.append("file", file);
            const result = await http.upload(url2, formData);
            if (onSuccess) onSuccess(file, result);
            eventBus.emit("ws:upload:success", { file, result });
          } catch (err) {
            if (onError) onError(file, err);
            eventBus.emit("ws:upload:error", { file, error: err });
          }
        };
        const input = document.createElement("input");
        input.type = "file";
        if (accept.length) input.accept = accept.join(",");
        input.multiple = multiple;
        input.style.display = "none";
        document.body.appendChild(input);
        container.addEventListener("click", (e) => {
          if (e.target === container || container.contains(e.target)) {
            input.click();
          }
        });
        input.addEventListener("change", () => {
          [...input.files].forEach(uploadFile);
          input.value = "";
        });
        if (dropzone) {
          const prevent = (e) => {
            e.preventDefault();
            e.stopPropagation();
          };
          container.addEventListener("dragenter", prevent);
          container.addEventListener("dragover", prevent);
          container.addEventListener("dragleave", prevent);
          container.addEventListener("drop", (e) => {
            prevent(e);
            [...e.dataTransfer?.files || []].forEach(uploadFile);
          });
        }
        return {
          destroy() {
            input.remove();
          }
        };
      },
      // ============================================================
      // 完整头像上传器（模态框 + 预设头像 + Cropper 裁切）
      // ============================================================
      /**
       * 头像上传器（完整功能：预设头像 + 上传裁切 + 旋转/缩放 + 保存）
       *
       * @param {Object} options
       * @param {string|HTMLElement} options.modal        - 模态框选择器或元素（#avatarSelectModal）
       * @param {string}  [options.presetUrl]              - 预设头像 API 地址
       * @param {string}  [options.presetContainer]        - 预设头像容器选择器（#presetAvatarsContainer）
       * @param {string}  [options.uploadCroppedAvatarUrl] - 上传裁切后头像的 API 地址
       * @param {string}  [options.updateAvatarUrl]        - 更新用户头像的 API 地址
       * @param {string}  [options.uploadArea]             - 上传区域选择器（#uploadArea）
       * @param {string}  [options.imageInput]             - 文件输入选择器（#imageUpload）
       * @param {string}  [options.selectImageBtn]         - 选择图片按钮（#selectImageBtn）
       * @param {string}  [options.cropperContainer]       - 裁切容器（#cropperContainer）
       * @param {string}  [options.cropperImage]           - 待裁切图片（#imageToCrop）
       * @param {string}  [options.previewContainer]       - 预览容器（#previewContainer）
       * @param {string}  [options.avatarPreview]          - 预览图片（#avatarPreview）
       * @param {string}  [options.saveBtn]                - 保存按钮（#saveAvatarBtn）
       * @param {string}  [options.uploadTips]             - 上传提示（#uploadTips）
       * @param {string}  [options.resetUploadBtn]         - 重新选择按钮（#resetUploadBtn）
       * @param {string}  [options.rotateBtns]             - 旋转按钮（.rotateBtn）
       * @param {string}  [options.rotateAngleInput]       - 旋转角度输入（#rotateAngle）
       * @param {string}  [options.zoomSlider]             - 缩放滑块（#zoomSlider）
       * @param {string}  [options.cropShapeRadios]        - 裁切形状单选（input[name="cropShape"]）
       * @param {string}  [options.tabContainer]           - 选项卡容器（#avatarTab）
       * @param {number}  [options.maxSize=2]              - 文件大小限制 MB
       * @param {string}  [options.cropShape='circle']     - 默认裁切形状
       * @param {number}  [options.cropWidth=300]          - 裁切输出宽度
       * @param {number}  [options.cropHeight=300]         - 裁切输出高度
       * @param {boolean} [options.showUploadType=true]    - 是否显示「上传头像」选项卡
       * @param {Function} [options.onSuccess]             - (avatarUrl) => void
       * @param {Function} [options.onError]               - (err) => void
       * @param {Function} [options.onModalShow]           - 模态框显示后回调
       * @param {Function} [options.onModalHide]           - 模态框关闭后回调
       * @returns {{ show: Function, hide: Function, destroy: Function }}
       */
      avatarUploader(options2) {
        const opts = {
          // 选择器
          modal: "#avatarSelectModal",
          presetUrl: null,
          presetContainer: "#presetAvatarsContainer",
          uploadCroppedAvatarUrl: null,
          updateAvatarUrl: null,
          uploadArea: "#uploadArea",
          imageInput: "#imageUpload",
          selectImageBtn: "#selectImageBtn",
          cropperContainer: "#cropperContainer",
          cropperImage: "#imageToCrop",
          previewContainer: "#previewContainer",
          avatarPreview: "#avatarPreview",
          saveBtn: "#saveAvatarBtn",
          uploadTips: "#uploadTips",
          resetUploadBtn: "#resetUploadBtn",
          rotateBtns: ".rotateBtn",
          rotateAngleInput: "#rotateAngle",
          zoomSlider: "#zoomSlider",
          cropShapeRadios: 'input[name="cropShape"]',
          tabContainer: "#avatarTab",
          // 配置
          maxSize: 2,
          cropShape: "circle",
          cropWidth: 300,
          cropHeight: 300,
          showUploadType: true,
          // 回调
          onSuccess: null,
          onError: null,
          onModalShow: null,
          onModalHide: null,
          ...options2
        };
        let cropper = null;
        let currentAvatarType = "preset";
        let selectedAvatarUrl = "";
        let _inited2 = false;
        const modalEl = $9(opts.modal);
        if (!modalEl) {
          logger.error("upload", `头像模态框未找到: ${opts.modal}`);
          return { show() {
          }, hide() {
          }, destroy() {
          } };
        }
        function setBtnLoading(btn, loading2, originalText) {
          if (loading2) {
            btn.disabled = true;
            btn._originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>保存中...';
          } else {
            btn.disabled = false;
            btn.innerHTML = originalText || btn._originalHTML || '<i class="bi bi-check-circle me-1"></i>保存头像';
          }
        }
        function destroyCropper() {
          if (cropper) {
            try {
              cropper.destroy();
            } catch (_) {
            }
            cropper = null;
          }
        }
        function resetUploadArea() {
          const crp = $9(opts.cropperContainer);
          const prev = $9(opts.previewContainer);
          const area = $9(opts.uploadArea);
          const tips = $9(opts.uploadTips);
          if (crp) crp.classList.add("d-none");
          if (prev) prev.classList.add("d-none");
          if (area) area.classList.remove("d-none");
          if (tips) tips.classList.remove("d-none");
          const imgInput = $9(opts.imageInput);
          if (imgInput) imgInput.value = "";
          destroyCropper();
          currentAvatarType = "preset";
          selectedAvatarUrl = "";
          const saveBtn = $9(opts.saveBtn);
          if (saveBtn) saveBtn.disabled = true;
        }
        function updateAvatarPreview(url2) {
          const preview = $9(opts.avatarPreview);
          if (preview) preview.src = url2;
        }
        function updateAvatarPreviewFromCropper() {
          if (!cropper) return;
          const canvas = cropper.getCroppedCanvas({
            width: 200,
            height: 200,
            fillColor: "#fff",
            imageSmoothingEnabled: true,
            imageSmoothingQuality: "high"
          });
          if (canvas) {
            const preview = $9(opts.avatarPreview);
            if (preview) preview.src = canvas.toDataURL("image/png");
            currentAvatarType = "upload";
          }
        }
        function loadPresetAvatars() {
          if (!opts.presetUrl || !opts.presetContainer) return;
          const container = $9(opts.presetContainer);
          if (!container) return;
          http.get(opts.presetUrl, null, { interceptor: false, autoError: false }).then((resp) => {
            let avatars = Array.isArray(resp) ? resp : resp?.data || resp?.avatars || [];
            container.innerHTML = "";
            avatars.forEach((avatar) => {
              const avatarUrl = avatar.url || avatar;
              const avatarId = avatar.id || avatarUrl;
              const avatarName = avatar.name || "";
              const col = createEl("div", { className: "col-4 col-md-3" });
              const item = createEl("div", {
                className: "avatar-item",
                dataAvatarId: avatarId,
                dataAvatarUrl: avatarUrl
              });
              const ratio = createEl("div", { className: "ratio ratio-1x1" });
              const img = createEl("img", {
                src: avatarUrl,
                alt: avatarName,
                className: "avatar-img"
              });
              ratio.appendChild(img);
              item.appendChild(ratio);
              col.appendChild(item);
              container.appendChild(col);
              item.addEventListener("click", () => {
                $$(".avatar-item", container).forEach((it) => it.classList.remove("selected"));
                item.classList.add("selected");
                selectedAvatarUrl = avatarUrl;
                currentAvatarType = "preset";
                updateAvatarPreview(avatarUrl);
                const saveBtn = $9(opts.saveBtn);
                if (saveBtn) saveBtn.disabled = false;
              });
            });
          }).catch((err) => {
            if (container) {
              container.innerHTML = `
                            <div class="col-12 text-center py-5">
                                <i class="bi bi-wifi-off display-4 text-muted mb-3"></i>
                                <p class="text-muted">加载预设头像失败</p>
                            </div>`;
            }
            logger.error("upload", "加载预设头像失败", err);
          });
        }
        function initCropper(imageSrc) {
          const CropperClass = getCropper();
          if (!CropperClass) {
            showToast("Cropper 组件未加载，请刷新页面后重试", "error");
            return;
          }
          const imgEl = $9(opts.cropperImage);
          if (!imgEl) return;
          destroyCropper();
          imgEl.src = imageSrc;
          cropper = new CropperClass(imgEl, {
            aspectRatio: 1,
            viewMode: 1,
            autoCropArea: 0.8,
            responsive: true,
            restore: false,
            guides: true,
            center: true,
            highlight: false,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false,
            ready() {
              updateAvatarPreviewFromCropper();
            },
            crop() {
              updateAvatarPreviewFromCropper();
            }
          });
          _applyCropShape(opts.cropShape);
        }
        function _applyCropShape(shape) {
          if (!cropper) return;
          const container = $9(opts.cropperContainer);
          if (container) {
            if (shape === "circle") {
              container.classList.remove("cropper-square");
              container.classList.add("cropper-circle");
            } else {
              container.classList.remove("cropper-circle");
              container.classList.add("cropper-square");
            }
          }
          cropper.setAspectRatio(1);
          updateAvatarPreviewFromCropper();
        }
        function handleImageFile(file) {
          if (!file.type.match(/image\//)) {
            showToast("请选择图片文件（JPG、PNG格式）", "error");
            return;
          }
          if (file.size > opts.maxSize * 1024 * 1024) {
            showToast(`图片大小不能超过${opts.maxSize}MB`, "error");
            return;
          }
          const reader = new FileReader();
          reader.onload = (e) => {
            const area = $9(opts.uploadArea);
            const tips = $9(opts.uploadTips);
            if (area) area.classList.add("d-none");
            if (tips) tips.classList.add("d-none");
            const crp = $9(opts.cropperContainer);
            const prev = $9(opts.previewContainer);
            if (crp) crp.classList.remove("d-none");
            if (prev) prev.classList.remove("d-none");
            initCropper(e.target.result);
            const saveBtn = $9(opts.saveBtn);
            if (saveBtn) saveBtn.disabled = false;
          };
          reader.readAsDataURL(file);
        }
        function saveCroppedAvatar(saveBtn) {
          if (!cropper) {
            showToast("请先上传图片", "error");
            return;
          }
          if (!opts.uploadCroppedAvatarUrl) {
            showToast("请先配置上传裁切后的头像URL", "error");
            setBtnLoading(saveBtn, false);
            return;
          }
          const canvas = cropper.getCroppedCanvas({
            width: opts.cropWidth,
            height: opts.cropHeight,
            fillColor: "#fff",
            imageSmoothingEnabled: true,
            imageSmoothingQuality: "high"
          });
          if (!canvas) {
            showToast("无法获取裁切后的图片", "error");
            setBtnLoading(saveBtn, false);
            return;
          }
          const avatarData = canvas.toDataURL("image/png");
          http.post(opts.uploadCroppedAvatarUrl, { avatar_data: avatarData }, { interceptor: false }).then((resp) => {
            const result = resp?.data || resp || {};
            const avatarUrl = result.avatar_url || result.url;
            if (!avatarUrl) {
              throw new Error("服务器未返回头像URL");
            }
            updateUserAvatar(avatarUrl, saveBtn);
          }).catch((err) => {
            showToast(err.message || "网络错误，请稍后重试", "error");
            setBtnLoading(saveBtn, false);
            if (opts.onError) opts.onError(err);
          });
        }
        function updateUserAvatar(avatarUrl, saveBtn) {
          const doFinish = () => {
            setBtnLoading(saveBtn, false);
            hideModal();
            if (opts.onSuccess) {
              opts.onSuccess(avatarUrl);
            }
            eventBus.emit("ws:upload:avatar:updated", { avatarUrl });
          };
          if (!opts.updateAvatarUrl) {
            doFinish();
            return;
          }
          http.post(opts.updateAvatarUrl, { avatar: avatarUrl }, { interceptor: false }).then(() => {
            showToast("头像更新成功", "success");
            doFinish();
          }).catch((err) => {
            showToast(err.message || "头像更新失败", "error");
            setBtnLoading(saveBtn, false);
            if (opts.onSuccess) opts.onSuccess(avatarUrl);
            if (opts.onError) opts.onError(err);
          });
        }
        function handleSave() {
          const saveBtn = $9(opts.saveBtn);
          if (!saveBtn) return;
          setBtnLoading(saveBtn, true);
          if (currentAvatarType === "preset") {
            if (!selectedAvatarUrl) {
              showToast("请选择头像", "error");
              setBtnLoading(saveBtn, false);
              return;
            }
            updateUserAvatar(selectedAvatarUrl, saveBtn);
          } else if (currentAvatarType === "upload" && cropper) {
            saveCroppedAvatar(saveBtn);
          } else {
            showToast("请选择或上传头像", "error");
            setBtnLoading(saveBtn, false);
          }
        }
        function showModal() {
          if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
            const bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            bsModal.show();
          } else {
            if (window.$) {
              window.$(modalEl).modal("show");
            } else {
              modalEl.style.display = "block";
              modalEl.classList.add("show");
            }
          }
        }
        function hideModal() {
          if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            if (bsModal) bsModal.hide();
          } else if (window.$) {
            window.$(modalEl).modal("hide");
          } else {
            modalEl.style.display = "none";
            modalEl.classList.remove("show");
          }
        }
        function bindEvents() {
          if (_inited2) return;
          _inited2 = true;
          const selectBtn = $9(opts.selectImageBtn);
          const uploadAreaEl = $9(opts.uploadArea);
          const imgInput = $9(opts.imageInput);
          if (selectBtn && imgInput) {
            selectBtn.addEventListener("click", (e) => {
              e.stopPropagation();
              e.preventDefault();
              imgInput.click();
            });
          }
          if (uploadAreaEl && imgInput) {
            uploadAreaEl.addEventListener("click", (e) => {
              e.stopPropagation();
              e.preventDefault();
              imgInput.click();
            });
          }
          if (uploadAreaEl) {
            uploadAreaEl.addEventListener("dragover", (e) => {
              e.preventDefault();
              e.stopPropagation();
              uploadAreaEl.classList.add("dragover");
            });
            uploadAreaEl.addEventListener("dragleave", (e) => {
              e.preventDefault();
              e.stopPropagation();
              uploadAreaEl.classList.remove("dragover");
            });
            uploadAreaEl.addEventListener("drop", (e) => {
              e.preventDefault();
              e.stopPropagation();
              uploadAreaEl.classList.remove("dragover");
              const files = e.dataTransfer?.files;
              if (files && files.length > 0) {
                handleImageFile(files[0]);
              }
            });
          }
          if (imgInput) {
            imgInput.addEventListener("change", function() {
              if (this.files && this.files[0]) {
                handleImageFile(this.files[0]);
              }
            });
          }
          $$(opts.rotateBtns).forEach((btn) => {
            btn.addEventListener("click", () => {
              if (!cropper) return;
              const action3 = btn.getAttribute("data-action");
              if (action3 === "left") cropper.rotate(-90);
              else if (action3 === "right") cropper.rotate(90);
              const data3 = cropper.getData();
              const angleInput2 = $9(opts.rotateAngleInput);
              if (angleInput2) angleInput2.value = data3.rotate;
            });
          });
          const angleInput = $9(opts.rotateAngleInput);
          if (angleInput) {
            angleInput.addEventListener("change", () => {
              if (!cropper) return;
              const angle = parseInt(angleInput.value) || 0;
              cropper.rotateTo(angle);
            });
          }
          const zoomSlider = $9(opts.zoomSlider);
          if (zoomSlider) {
            zoomSlider.addEventListener("input", () => {
              if (!cropper) return;
              const zoom = parseFloat(zoomSlider.value);
              cropper.zoomTo(zoom);
            });
          }
          $$(opts.cropShapeRadios).forEach((radio) => {
            radio.addEventListener("change", function() {
              _applyCropShape(this.value);
            });
          });
          const resetBtn = $9(opts.resetUploadBtn);
          if (resetBtn) {
            resetBtn.addEventListener("click", () => {
              resetUploadArea();
              const uploadTabBtn = $9("#upload-tab");
              if (uploadTabBtn && typeof bootstrap !== "undefined" && bootstrap.Tab) {
                const tab = new bootstrap.Tab(uploadTabBtn);
                tab.show();
              }
            });
          }
          const saveBtn = $9(opts.saveBtn);
          if (saveBtn) {
            saveBtn.addEventListener("click", handleSave);
          }
          const tabContainer = $9(opts.tabContainer);
          if (tabContainer) {
            tabContainer.addEventListener("shown.bs.tab", (e) => {
              const targetId = e.target.getAttribute("data-bs-target");
              if (targetId === "#upload") {
                resetUploadArea();
              } else if (targetId === "#preset") {
                const presetContainer = $9(opts.presetContainer);
                if (presetContainer && presetContainer.children.length === 0) {
                  loadPresetAvatars();
                }
              }
            });
          }
          if (modalEl) {
            modalEl.addEventListener("hidden.bs.modal", () => {
              if (opts.onModalHide) opts.onModalHide();
            });
            modalEl.addEventListener("shown.bs.modal", () => {
              if (opts.onModalShow) opts.onModalShow();
            });
          }
          if (!opts.showUploadType) {
            const uploadTabLi = $9("#upload-tab")?.parentElement;
            if (uploadTabLi) uploadTabLi.classList.add("d-none");
          }
        }
        function init() {
          bindEvents();
          if (opts.presetUrl) {
            loadPresetAvatars();
          }
        }
        init();
        return {
          show() {
            showModal();
          },
          hide() {
            hideModal();
          },
          destroy() {
            destroyCropper();
            if (_inited2) {
              const saveBtn = $9(opts.saveBtn);
              if (saveBtn) {
                const newBtn = saveBtn.cloneNode(true);
                saveBtn.parentNode?.replaceChild(newBtn, saveBtn);
              }
            }
            _inited2 = false;
            logger.info("upload", "avatarUploader 已销毁");
          },
          /** 重新初始化 Cropper（当图片通过其他方式加载时） */
          recrop(imageSrc) {
            initCropper(imageSrc);
          }
        };
      },
      /**
       * 订阅上传事件
       * @param {'success'|'error'|'progress'} event
       * @param {Function} handler
       * @returns {Function}
       */
      on(event, handler) {
        return eventBus.on(`ws:upload:${event}`, handler);
      }
    };
    registry.register("upload", upload);
    wise_upload_default = upload;
  }
});

// public/statics/modules/wise-navtabs.js
var wise_navtabs_exports = {};
__export(wise_navtabs_exports, {
  NavTabManager: () => NavTabManager,
  default: () => wise_navtabs_default
});
function _autoInit() {
  if (NavTabManager._ready) return;
  NavTabManager._init({});
}
var $10, Layer, NavTabManager, wise_navtabs_default;
var init_wise_navtabs = __esm({
  "public/statics/modules/wise-navtabs.js"() {
    $10 = window.jQuery;
    Layer = window.Layer;
    NavTabManager = {
      defaults: {
        close: true,
        monitor: "body",
        nav: "#dynamic-tabs",
        tab: "#dynamic-tab-content",
        iframeUse: true,
        simple: false,
        iframeHeight: "100%",
        iframeForceRefresh: false,
        iframeForceRefreshTable: false,
        callback() {
        },
        persistence: true,
        persistenceKey: "navTabsState",
        allowReorder: true,
        allowRename: false,
        autoRefresh: false,
        refreshInterval: 6e4,
        fixedTabs: [],
        maxTabs: 20,
        closeConfirm: false,
        closeConfirmMessage: "确定要关闭此标签吗？",
        tabActiveClass: "active",
        tabFixedClass: "tab-fixed",
        tabPinnedIcon: '<i class="bi bi-pin-angle small text-primary"></i>',
        tabUnpinnedIcon: '<i class="bi bi-pin small"></i>',
        lazyLoadContent: true,
        loadFixedTabsOnInit: true
      },
      options: {},
      tabs: /* @__PURE__ */ new Map(),
      activeTabId: null,
      dragState: { dragging: false, element: null, startIndex: 0, currentIndex: 0, placeholder: null, tabWidth: 0 },
      resizeTimer: null,
      _eventBus: null,
      _ready: false,
      // ---- 初始化 ----
      _init(opts) {
        if (this._ready) return;
        this.options = $10.extend({}, this.defaults, opts);
        this.$container = $10(this.options.monitor);
        this.$nav = $10(this.options.nav);
        this.$tab = $10(this.options.tab);
        this.tabs = /* @__PURE__ */ new Map();
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
      _emit(event, data3) {
        if (this._eventBus) this._eventBus.emit(event, data3);
      },
      // ---- 选项校验 ----
      _validateOptions() {
        if (typeof this.options.maxTabs !== "number" || this.options.maxTabs < 1) this.options.maxTabs = Infinity;
        if (typeof this.options.refreshInterval !== "number" || this.options.refreshInterval < 1e3) this.options.refreshInterval = 6e4;
      },
      // ---- DOM 初始化已有标签 ----
      _initTabsFromDOM() {
        const self = this;
        this.$nav.find("li[role=context]").each(function() {
          const $el = $10(this);
          const $a = $el.find("a[role=tab]");
          const id = $a.attr("aria-controls") || $a.attr("node-id");
          if (!id) return;
          const opts = {
            id,
            title: $a.text().trim(),
            url: $a.attr("href") || $a.attr("url"),
            content: self.$tab.find(`[role=tabpanel][id="${id}"]`).html()
          };
          self.tabs.set(id, {
            opts,
            isFixed: self.options.fixedTabs.includes(id),
            isActive: $el.hasClass(self.options.tabActiveClass),
            refreshTimer: null,
            isLoaded: true
          });
          if (self.tabs.get(id).isActive) self.activeTabId = id;
        });
      },
      // ---- 事件绑定 ----
      _bindEvents() {
        const self = this;
        this.$nav.on("click", ".close-tab", (e) => this._handleClose(e));
        this.$nav.on("dblclick", "li[role=context]", (e) => this._handleDblClick(e));
        this.$nav.on("click", "li[role=context] a[role=tab]", (e) => this._handleNavClick(e));
        this.$nav.on("click", ".pin-tab", (e) => this._handlePinClick(e));
        this.$nav.on("mouseenter", "li[role=context]", (e) => this._handleMouseEnter(e));
        this.$nav.on("mouseleave", "li[role=context]", (e) => this._handleMouseLeave(e));
        $10(document).on("contextmenu", "li[role=context] a[role=tab]", (e) => this._handleContextMenu(e));
        $10(document).on("click", () => $10("#navContextMenu").hide());
        $10(window).on("blur", () => $10("#navContextMenu").hide());
        if (this.options.allowRename) {
          this.$nav.on("dblclick", "li[role=context] a[role=tab] .tab-title", (e) => {
            e.stopPropagation();
            this._handleRename(e);
          });
        }
        if (this.options.allowReorder) this._initDrag();
        if (window.history?.pushState) $10(window).on("popstate", (e) => this._handlePopState(e));
        $10('[data-lte-widget="refresh"]').on("click", () => this.api.refreshTabContent(null, true));
      },
      // ---- 右键菜单 ----
      _initPopmenu() {
        const self = this;
        if ($10("#navContextMenu").length) return;
        $10("body").append(`
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
        $10(document).on("click", "#navContextMenu a[data-menu]", function() {
          const nodeId = $10("#navContextMenu").data("node-id");
          switch ($10(this).data("menu")) {
            case "close":
              self.api.closeTab(nodeId);
              break;
            case "close-other":
              self.api.closeOtherTabs(nodeId);
              break;
            case "close-left":
              self.api.closeLeftTabs(nodeId);
              break;
            case "close-right":
              self.api.closeRightTabs(nodeId);
              break;
            case "pin":
              self.api.pinTab(nodeId);
              break;
            case "unpin":
              self.api.unpinTab(nodeId);
              break;
            case "refresh":
              self.api.refreshTabContent(nodeId, true);
              break;
          }
        });
      },
      // ---- 浏览器 resize ----
      _initResize() {
        const self = this;
        $10(window).on("resize", () => {
          clearTimeout(self.resizeTimer);
          self.resizeTimer = setTimeout(() => {
            self._adjustWidth();
            self._refreshDropDown();
          }, 100);
        });
      },
      _initAutoRefresh() {
        if (!this.options.autoRefresh) return;
        this.tabs.forEach((tab, id) => {
          if (tab.isActive) this.api.startTabRefresh(id);
        });
      },
      // ============================================================
      // 公开 API
      // ============================================================
      api: {
        addTab(opts) {
          const self = NavTabManager;
          if (typeof opts === "string") opts = self.tabs.get(opts)?.opts || {};
          opts = $10.extend({ id: "", sid: "", title: "无名标签", icon: "", url: "", isFixed: false, content: "", ajax: false, isActive: true }, opts);
          opts.sid = opts.id;
          opts.id = String(opts.id).replace(/[^a-zA-Z0-9_-]/g, "_");
          const { id } = opts;
          if (!id) {
            self._emit("error", { message: "Tab ID is required" });
            return;
          }
          const _doAdd = () => {
            let url2 = opts.url || "";
            if (opts.params) url2 += (url2.includes("?") ? "&" : "?") + Object.keys(opts.params).map((k) => `${k}=${opts.params[k]}`).join("&");
            if (self.options.simple) self.api.clearAllTabs();
            if (self.tabs.has(id)) {
              self.api.activateTab(id);
              self._emit("tab:reactivated", { id, opts: self.tabs.get(id).opts });
              return;
            }
            const tabId = `tab_${id}`, conId = `con_${id}`;
            const $tabItem = self._createTabItem(opts, tabId, conId);
            const $conItem = self._createTabContent(opts, conId, url2);
            self.$tab.append($conItem);
            self._appendToNav($tabItem);
            self.tabs.set(id, { opts, isFixed: opts.isFixed || self.options.fixedTabs.includes(id), isActive: false, refreshTimer: null, isLoaded: false });
            self.api.activateTab(id);
            self._refreshDropDown();
            self._saveState();
            self._emit("tab:added", { id, opts });
          };
          if (self.tabs.size >= self.options.maxTabs && !self.tabs.has(id)) {
            Layer.confirm(`已达到最大标签数量(${self.options.maxTabs})，是否关闭最右侧标签？`, {
              closeBtn: 0,
              anim: 0,
              title: "提示",
              btn: ["确定", "取消"]
            }, function(index) {
              Layer.close(index);
              self.api.closeLeftMostTab();
              _doAdd();
            });
          } else {
            _doAdd();
          }
        },
        activateTab(id) {
          const self = NavTabManager;
          if (!self.tabs.has(id)) return;
          if (self.activeTabId && self.activeTabId !== id) {
            const prev = self.tabs.get(self.activeTabId);
            if (prev) prev.isActive = false;
            $10(`#tab_${self.activeTabId} a`, self.$nav).removeClass(self.options.tabActiveClass);
            $10(`#con_${self.activeTabId}`, self.$tab).removeClass(self.options.tabActiveClass);
            self.api.stopTabRefresh(self.activeTabId);
            self._emit("tab:deactivated", { id: self.activeTabId });
          }
          self.tabs.forEach((t) => {
            t.opts.isActive = false;
          });
          self.activeTabId = id;
          const tab = self.tabs.get(id);
          tab.isActive = true;
          tab.opts.isActive = true;
          $10(`#tab_${id} a`, self.$nav).addClass(self.options.tabActiveClass);
          $10(`#con_${id}`, self.$tab).addClass(self.options.tabActiveClass);
          if (self.options.lazyLoadContent && !tab.isLoaded) self.api.loadTabContent(id);
          else if (!self.options.lazyLoadContent && !tab.isLoaded) self.api.loadTabContent(id);
          self._saveState();
          if (self.options.autoRefresh) self.api.startTabRefresh(id);
          self._emit("tab:activated", { id, opts: tab.opts });
        },
        loadTabContent(id) {
          const self = NavTabManager;
          const tab = self.tabs.get(id);
          if (!tab || tab.isLoaded) return;
          const opts = tab.opts, conId = `con_${id}`;
          const $conItem = $10(`#${conId}`);
          let url2 = opts.url || "";
          if (opts.params) url2 += (url2.includes("?") ? "&" : "?") + Object.keys(opts.params).map((k) => `${k}=${opts.params[k]}`).join("&");
          if ($conItem.is(":empty") || !tab.isLoaded) {
            self._emit("tab:contentLoading", { id, opts });
            try {
              if (opts.content) {
                $conItem.append(opts.content);
                tab.isLoaded = true;
                self._emit("tab:contentLoaded", { id, opts });
              } else if (self.options.iframeUse && !opts.ajax) {
                $conItem.append(`<iframe id="iframe${id}" src="${url2}" width="100%" height="${self.options.iframeHeight}" frameborder="no" border="0" marginwidth="0" marginheight="0" scrolling-x="no" scrolling-y="auto" allowtransparency="yes"></iframe>`);
                tab.isLoaded = true;
                $conItem.find("iframe").on("load", () => self._emit("tab:contentLoaded", { id, opts })).on("error", () => self._emit("tab:contentLoadError", { id, opts }));
              } else if (opts.ajax) {
                $10.get(url2, (data3) => {
                  $conItem.html(data3);
                  tab.isLoaded = true;
                  self._emit("tab:contentLoaded", { id, opts });
                }).fail((err) => {
                  self._emit("tab:contentLoadError", { id, opts, error: err });
                });
              } else {
                tab.isLoaded = true;
                self._emit("tab:contentLoaded", { id, opts });
              }
            } catch (e) {
              self._emit("tab:contentLoadError", { id, opts, error: e });
            }
          }
        },
        closeTab(id) {
          const self = NavTabManager;
          if (!id) {
            id = self.activeTabId;
            if (!id) return;
          }
          const tab = self.tabs.get(id);
          if (!tab) return;
          if (tab.isFixed) {
            self._emit("tab:closePrevented", { id, reason: "tab is fixed" });
            return;
          }
          const _close = () => {
            self.api.stopTabRefresh(id);
            let newId = null;
            if (self.activeTabId === id) newId = self._getAdjacentId(id);
            $10(`#tab_${id}`, self.$nav).remove();
            $10(`#con_${id}`, self.$tab).remove();
            self.tabs.delete(id);
            if (newId) self.api.activateTab(newId);
            self._refreshDropDown();
            self._saveState();
            self.options.callback();
            self._emit("tab:closed", { id, newActiveId: newId });
          };
          if (self.options.closeConfirm) {
            Layer.confirm(self.options.closeConfirmMessage, {
              closeBtn: 0,
              icon: 2,
              anim: 0,
              title: "关闭标签",
              btn: ["确定", "取消"]
            }, function(index) {
              Layer.close(index);
              _close();
            }, function(index) {
              Layer.close(index);
              self._emit("tab:closeCancelled", { id });
            });
          } else _close();
        },
        closeLeftTabs(id) {
          const self = NavTabManager;
          const $cur = $10(`#tab_${id}`, self.$nav);
          if (!$cur.length) return;
          let count = 0;
          $cur.prevAll("li[role=context]").not(".tabdrop").each(function() {
            const tid = $10(this).find("a").attr("aria-controls");
            const t = self.tabs.get(tid);
            if (t && !t.isFixed) {
              self.api.closeTab(tid);
              count++;
            }
          });
          if (count > 0) self._emit("tabs:closedLeft", { id, closedCount: count });
        },
        closeRightTabs(id) {
          const self = NavTabManager;
          const $cur = $10(`#tab_${id}`, self.$nav);
          if (!$cur.length) return;
          let count = 0;
          $cur.nextAll("li[role=context]").not(".tabdrop").each(function() {
            const tid = $10(this).find("a").attr("aria-controls");
            const t = self.tabs.get(tid);
            if (t && !t.isFixed) {
              self.api.closeTab(tid);
              count++;
            }
          });
          if (count > 0) self._emit("tabs:closedRight", { id, closedCount: count });
        },
        closeOtherTabs(id) {
          const self = NavTabManager;
          const $cur = $10(`#tab_${id}`, self.$nav);
          if (!$cur.length) return;
          let count = 0;
          self.$nav.find("li[role=context]").not(".tabdrop").not(`#tab_${id}`).each(function() {
            const tid = $10(this).find("a").attr("aria-controls");
            const t = self.tabs.get(tid);
            if (t && !t.isFixed) {
              self.api.closeTab(tid);
              count++;
            }
          });
          if (count > 0) self._emit("tabs:closedOther", { id, closedCount: count });
        },
        pinTab(id) {
          const self = NavTabManager;
          if (self.tabs.get(id) && !self.tabs.get(id).isFixed) self.api.toggleTabPin(id);
        },
        unpinTab(id) {
          const self = NavTabManager;
          if (self.tabs.get(id)?.isFixed) self.api.toggleTabPin(id);
        },
        closeLeftMostTab() {
          const self = NavTabManager;
          const $first = self.$nav.find("li[role=context]").not(`.${self.options.tabFixedClass}`).not(".tabdrop").first();
          const tid = $first.find("a").attr("aria-controls");
          if (tid) self.api.closeTab(tid);
        },
        clearAllTabs() {
          const self = NavTabManager;
          self.tabs.forEach((tab, tid) => {
            if (tab.isFixed) return;
            self.api.stopTabRefresh(tid);
            $10(`#tab_${tid}`, self.$nav).remove();
            $10(`#con_${tid}`, self.$tab).remove();
            self.tabs.delete(tid);
          });
          self.activeTabId = null;
          self._refreshDropDown();
          self._saveState();
          self._emit("tabs:cleared");
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
          if (tab?.refreshTimer) {
            clearInterval(tab.refreshTimer);
            tab.refreshTimer = null;
          }
        },
        refreshTabContent(id, force = false) {
          const self = NavTabManager;
          id = id || self.activeTabId;
          const tab = self.tabs.get(id);
          if (!tab) return;
          const { opts } = tab, conId = `con_${id}`;
          let url2 = opts.url || "";
          self._emit("tab:refreshStart", { id, opts, force });
          try {
            if (force) {
              if (self.options.iframeUse && !opts.ajax) {
                const sep = url2.includes("?") ? "&" : "?";
                $10(`#${conId} iframe`).attr("src", `${url2}${sep}_t=${Date.now()}`);
              } else if (opts.ajax) {
                $10.get(url2, (d) => {
                  $10(`#${conId}`).html(d);
                  self._emit("tab:refreshed", { id, opts, force });
                });
              } else location.reload();
            } else {
              if (self.options.iframeForceRefresh) {
                $10(`#${conId} iframe`).attr("src", $10(`#${conId} iframe`).attr("src"));
              } else if (self.options.iframeForceRefreshTable) {
                try {
                  const iframe = $10(`#${conId} iframe`)[0];
                  if (iframe?.contentWindow?.$) {
                    const $b = iframe.contentWindow.$(".btn-refresh:not([data-force-refresh=false])");
                    if ($b.length) $b.click();
                    else $10(`#${conId} iframe`).attr("src", $10(`#${conId} iframe`).attr("src"));
                  }
                } catch (e) {
                  $10(`#${conId} iframe`).attr("src", $10(`#${conId} iframe`).attr("src"));
                }
              } else if (opts.ajax) {
                $10.get(url2, (d) => {
                  $10(`#${conId}`).html(d);
                  self._emit("tab:refreshed", { id, opts, force });
                });
              } else {
                try {
                  $10(`#${conId} iframe`)[0].contentWindow.location.reload();
                } catch (e) {
                  location.reload();
                }
              }
            }
            self._emit("tab:refreshed", { id, opts, force });
          } catch (e) {
            self._emit("tab:refreshError", { id, opts, error: e, force });
          }
        },
        toggleTabPin(id) {
          const self = NavTabManager;
          const tab = self.tabs.get(id);
          if (!tab) return;
          tab.isFixed = !tab.isFixed;
          tab.opts.isFixed = tab.isFixed;
          const $item = $10(`#tab_${id}`, self.$nav);
          const $pin = $item.find(".pin-tab");
          if (tab.isFixed) {
            $item.addClass(self.options.tabFixedClass);
            $item.find(".close-tab").hide();
            $pin.html(self.options.tabPinnedIcon);
            $pin.show();
          } else {
            $item.removeClass(self.options.tabFixedClass);
            $item.find(".close-tab").show();
            $pin.html(tab.opts.icon ? `<i class="${tab.opts.icon}"></i>` : self.options.tabUnpinnedIcon);
          }
          self._reorderFixed();
          self._saveState();
          self._emit("tab:pinned", { id, isFixed: tab.isFixed });
        },
        loadSavedState(defaultMenuUrl = null) {
          const self = NavTabManager;
          if (!self.options.persistence) return;
          let saved;
          try {
            saved = localStorage.getItem(self.options.persistenceKey);
          } catch (e) {
            return;
          }
          if (!saved) return;
          try {
            const state = JSON.parse(saved);
            state.tabs.forEach(({ id, opts, isFixed, isLoaded }) => {
              id = String(id).replace(/[^a-zA-Z0-9_-]/g, "_");
              if (opts) opts.id = id;
              if (self._getNavType() === "navigate" && !isFixed) return;
              if (self.tabs.has(id)) {
                const t = self.tabs.get(id);
                t.isFixed = isFixed;
                t.isLoaded = isLoaded;
                return;
              }
              const tabId = `tab_${id}`, conId = `con_${id}`;
              const $tabItem = self._createTabItem({ ...opts, isFixed }, tabId, conId);
              const $contentItem = self._createTabContent({ ...opts, isFixed }, conId, opts.url || "");
              self.$tab.append($contentItem);
              self._appendToNav($tabItem);
              self.tabs.set(id, { opts, isFixed, isActive: false, refreshTimer: null, isLoaded: false });
            });
            if (state.activeTab && self.tabs.has(state.activeTab)) self.api.activateTab(state.activeTab);
            else if (self.tabs.size > 0) self.api.activateTab([...self.tabs.keys()][0]);
            else if (defaultMenuUrl && $10(`.sidebar-menu a[data-url="${defaultMenuUrl}"]`).length) $10(`.sidebar-menu a[data-url="${defaultMenuUrl}"]`).click();
            self._reorderFixed();
            self._emit("tabs:restored", { state });
          } catch (e) {
            console.error("Failed to load saved tabs state:", e);
          }
        },
        trigger(event, data3) {
          NavTabManager._emit(event, data3);
        },
        on(event, listener) {
          NavTabManager._eventBus && NavTabManager._eventBus.on(event, listener);
        },
        off(event, listener) {
          NavTabManager._eventBus && NavTabManager._eventBus.off(event, listener);
        }
      },
      // ============================================================
      // 内部方法
      // ============================================================
      _createTabItem(opts, tabId, conId) {
        const isFixed = opts.isFixed || this.options.fixedTabs.includes(opts.id);
        const pinIcon = isFixed ? this.options.tabPinnedIcon : opts.icon ? `<i class="${opts.icon}"></i>` : this.options.tabUnpinnedIcon;
        return $10(`
            <li role="context" id="${tabId}" class="text-nowrap nav-item ${isFixed ? this.options.tabFixedClass : ""}">
                <a href="#${conId}" node-id="${opts.id}" class="nav-link" aria-controls="${opts.id}" role="tab" data-toggle="tab">
                    <span class="pin-tab">${pinIcon}</span>
                    <span class="tab-title">${opts.title}</span>
                    ${this.options.close ? `<i class="close-tab bi bi-x-lg"${isFixed ? ' style="display:none"' : ""}></i>` : ""}
                </a>
            </li>
        `);
      },
      _createTabContent(opts, conId, url2) {
        const $con = $10(`<div role="tabpanel" class="tab-pane h-100" id="${conId}"></div>`);
        if (this.options.lazyLoadContent) return $con;
        if (opts.content) $con.append(opts.content);
        else if (this.options.iframeUse && !opts.ajax) $con.append(`<iframe src="${url2}" width="100%" height="${this.options.iframeHeight}" frameborder="no" border="0" marginwidth="0" marginheight="0" scrolling-x="no" scrolling-y="auto" allowtransparency="yes"></iframe>`);
        else if (opts.ajax) $10.get(url2, (d) => $con.append(d));
        return $con;
      },
      _appendToNav($tabItem) {
        if (this.options.simple) return;
        const $dropdownMenu = $10(".tabdrop ul", this.$nav);
        $dropdownMenu.length ? $dropdownMenu.append($tabItem) : this.$nav.append($tabItem);
      },
      _getAdjacentId(id) {
        const $cur = $10(`#tab_${id}`, this.$nav);
        const $prev = $cur.prev().not(".tabdrop");
        const $next = $cur.next().not(".tabdrop");
        if ($prev.length) return $prev.find("a").attr("aria-controls");
        if ($next.length) return $next.find("a").attr("aria-controls");
        if (this.tabs.size > 0) {
          const last = [...this.tabs.keys()].pop();
          if (last !== id) return last;
        }
        return null;
      },
      _adjustWidth() {
        if (typeof this.options.nav === "object") {
          let siblingWidth = 0;
          this.$nav.siblings().each(function() {
            siblingWidth += $10(this).outerWidth();
          });
          this.$nav.width(this.$nav.parent().width() - siblingWidth);
        } else {
          this.$nav.width($10("#header").find("> .navbar").width() - $10(".sidebar-toggle").outerWidth() - $10(".navbar-custom-menu").outerWidth() - 20);
        }
      },
      _refreshDropDown() {
        let $dropdown = $10(".tabdrop", this.$nav);
        if (!$dropdown.length) {
          $dropdown = $10(`<li class="dropdown hide tabdrop"><a class="dropdown-toggle nav-link" data-bs-toggle="dropdown" href="javascript:;"><i class="bi bi-list-ul"></i><b class="caret"></b></a><ul class="border border-top-0 dropdown-menu bg-body-secondary" role="menu"></ul></li>`).appendTo(this.$nav);
        }
        if (this.$nav.parent().is(".tabs-below")) $dropdown.addClass("dropup");
        else $dropdown.removeClass("dropup");
        const margin = 200, maxW = this.$nav.width() - margin;
        let totalWidth = 0;
        const $items = this.$nav.append($dropdown.find("li")).find(">li").not(".tabdrop");
        $items.each(function() {
          totalWidth += $10(this).outerWidth(true);
        });
        if (this.$nav.width() < totalWidth) {
          let currentWidth = 0;
          $items.each(function() {
            currentWidth += $10(this).outerWidth(true);
            if (currentWidth > maxW) $dropdown.find("ul").append($10(this));
          });
          $dropdown.appendTo(this.$nav).show();
          $dropdown.find(".dropdown-toggle").toggleClass(this.options.tabActiveClass, $dropdown.find(`.dropdown-menu .${this.options.tabActiveClass}`).length === 1);
        } else $dropdown.hide();
      },
      _reorderFixed() {
        const $items = this.$nav.find("li[role=context]").not(".tabdrop");
        const $fixed = $items.filter(`.${this.options.tabFixedClass}`);
        const $unfixedTabs = $items.not(`.${this.options.tabFixedClass}`);
        $items.detach();
        this.$nav.append($fixed).append($unfixedTabs);
        this._refreshDropDown();
      },
      _saveState() {
        if (!this.options.persistence) return;
        const state = { activeTab: this.activeTabId, tabs: [...this.tabs.entries()].map(([id, t]) => ({ id, opts: t.opts, isFixed: t.isFixed, isLoaded: t.isLoaded })) };
        try {
          localStorage.setItem(this.options.persistenceKey, JSON.stringify(state));
        } catch (e) {
        }
      },
      _getNavType() {
        const navEntry = performance.getEntriesByType("navigation");
        if (navEntry.length) return navEntry[0].type;
        if (performance.navigation) return ["navigate", "reload", "back_forward"][performance.navigation.type] || "unknown";
        return "unknown";
      },
      // ---- 事件处理 ----
      _handleClose(e) {
        e.preventDefault();
        e.stopPropagation();
        this.api.closeTab($10(e.currentTarget).parent().attr("aria-controls"));
      },
      _handleDblClick(e) {
        const self = this;
        const $t = $10(e.currentTarget), id = $t.find("a").attr("aria-controls"), tab = self.tabs.get(id);
        if (tab?.isFixed) {
          self.api.toggleTabPin(id);
          return;
        }
        $t.find(".close-tab").trigger("click");
      },
      _handleNavClick(e) {
        e.preventDefault();
        this.api.activateTab($10(e.currentTarget).attr("node-id"));
      },
      _handleContextMenu(e) {
        e.preventDefault();
        e.stopPropagation();
        const self = this, nodeId = $10(e.currentTarget).attr("node-id"), tab = self.tabs.get(nodeId), menu = $10("#navContextMenu");
        menu.data("node-id", nodeId);
        if (tab) {
          menu.find(".pin-item").toggle(!tab.isFixed);
          menu.find(".unpin-item").toggle(tab.isFixed);
        }
        const contextMenuLeft = e.pageX + menu.width() > window.innerWidth ? e.pageX - menu.width() : e.pageX;
        menu.css({ display: "block", left: contextMenuLeft, top: e.pageY });
        return false;
      },
      _handlePinClick(e) {
        e.preventDefault();
        e.stopPropagation();
        this.api.toggleTabPin($10(e.currentTarget).closest("a").attr("aria-controls"));
      },
      _handleMouseEnter(e) {
        const $t = $10(e.currentTarget), id = $t.find("a").attr("aria-controls"), tab = this.tabs.get(id);
        if (tab && !tab.isFixed) $t.find(".pin-tab").html(this.options.tabUnpinnedIcon);
      },
      _handleMouseLeave(e) {
        const $t = $10(e.currentTarget), id = $t.find("a").attr("aria-controls"), tab = this.tabs.get(id);
        if (tab && !tab.isFixed) $t.find(".pin-tab").html(tab.opts.icon ? `<i class="${tab.opts.icon}"></i>` : this.options.tabUnpinnedIcon);
      },
      _handleRename(e) {
        const self = this, $title = $10(e.currentTarget), $a = $title.closest("a"), id = $a.attr("aria-controls");
        const name = prompt("请输入新标签名称:", $title.text().trim());
        if (name) {
          $title.text(name);
          const tab = self.tabs.get(id);
          if (tab) {
            tab.opts.title = name;
            self._saveState();
            self._emit("tab:renamed", { id, oldTitle: $title.text(), newTitle: name });
          }
        }
      },
      _handlePopState(e) {
        if (e.originalEvent?.state?.id) this.api.addTab(e.originalEvent.state);
      },
      // ---- 拖拽 ----
      _initDrag() {
        const self = this;
        self.$nav.on("mousedown", ">li[role=context]", function(e) {
          if (e.button !== 0) return;
          const $t = $10(this), id = $t.find("a").attr("aria-controls");
          if (self.tabs.get(id)?.isFixed) return;
          e.preventDefault();
          const $draggable = self.$nav.find(">li[role=context]").not(".tabdrop").not(`.${self.options.tabFixedClass}`);
          const tw = $t.outerWidth();
          self.dragState = { dragging: false, element: $t, startIndex: $draggable.index($t), currentIndex: $draggable.index($t), placeholder: null, tabWidth: tw, startX: e.clientX, startY: e.clientY, threshold: 5 };
          $10(document).on("mousemove", _dragMove).on("mouseup", _dragEnd);
        });
        function _dragMove(e) {
          const dragState = self.dragState;
          if (!dragState.element) return;
          const dragDistance = Math.sqrt((e.clientX - dragState.startX) ** 2 + (e.clientY - dragState.startY) ** 2);
          if (!dragState.dragging && dragDistance > dragState.threshold) {
            dragState.dragging = true;
            dragState.placeholder = $10(`<li class="drag-placeholder" style="width:${dragState.tabWidth}px;height:${dragState.element.outerHeight()}px;visibility:hidden"></li>`);
            dragState.element.addClass("dragging").css({ position: "absolute", left: dragState.element.position().left, backgroundColor: "rgba(0,0,0,0.3)", zIndex: 1e3 });
            dragState.element.after(dragState.placeholder);
          }
          if (dragState.dragging) {
            e.preventDefault();
            e.stopPropagation();
            dragState.element.css({ transform: `translateX(${e.clientX - dragState.startX}px)` });
            const $tabs = self.$nav.find(">li[role=context]").not(".tabdrop").not(`.${self.options.tabFixedClass}`).not(dragState.element).not(dragState.placeholder);
            let newIndex = dragState.startIndex;
            $tabs.each(function(i) {
              if (dragState.element.position().left + dragState.element.outerWidth(true) / 2 > $10(this).position().left + $10(this).outerWidth(true) / 2) newIndex = i + 1;
            });
            newIndex = Math.max(0, Math.min(newIndex, $tabs.length));
            if (newIndex !== dragState.currentIndex) {
              dragState.currentIndex = newIndex;
              const $targetTab = newIndex < $tabs.length ? $tabs.eq(newIndex) : $tabs.last();
              newIndex === 0 ? $tabs.first().before(dragState.placeholder) : $targetTab.before(dragState.placeholder);
            }
          }
        }
        function _dragEnd(e) {
          $10(document).off("mousemove", _dragMove).off("mouseup", _dragEnd);
          const dragState = self.dragState;
          if (dragState.dragging) {
            dragState.element.removeClass("dragging").css({ transform: "", position: "", backgroundColor: "", zIndex: "" });
            if (dragState.placeholder) {
              dragState.placeholder.after(dragState.element);
              dragState.placeholder.remove();
            }
            if (dragState.currentIndex !== dragState.startIndex) {
              self._saveState();
              self._emit("tabs:reordered");
            }
            self._refreshDropDown();
            e.preventDefault();
            e.stopPropagation();
          }
          self.dragState = { dragging: false, element: null, startIndex: 0, currentIndex: 0, placeholder: null, tabWidth: 0 };
        }
      }
    };
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", _autoInit);
    else setTimeout(_autoInit, 0);
    wise_navtabs_default = NavTabManager;
  }
});

// public/statics/modules/wise-navmenu.js
var wise_navmenu_exports = {};
__export(wise_navmenu_exports, {
  default: () => wise_navmenu_default,
  nav: () => nav
});
function normalizeUrl(url2) {
  let u = String(url2 == null ? "" : url2).trim();
  if (!u) return "";
  const hash = u.indexOf("#");
  if (hash >= 0) u = u.slice(0, hash);
  return u.replace(/\.html(?=$|\?)/, "");
}
function pathOf(url2) {
  return normalizeUrl(url2).split("?")[0].replace(/\/+$/, "");
}
function queryOf(url2) {
  const q = normalizeUrl(url2).split("?")[1];
  return q ? "?" + q : "";
}
function leafIcon($leaf) {
  const cls = ($leaf.find("i").attr("class") || "").trim();
  return cls.replace(/\bnav-icon\b\s*/, "");
}
function getActive() {
  try {
    return JSON.parse(localStorage.getItem(ACTIVE_KEY) || "null");
  } catch (_) {
    return null;
  }
}
function setActive(parentId, url2) {
  try {
    localStorage.setItem(ACTIVE_KEY, JSON.stringify({ parentId, url: url2 == null ? null : url2 }));
  } catch (_) {
  }
}
function hasNoOpenTab() {
  const nt = window.navtab;
  if (nt && nt.tabs && typeof nt.tabs.size === "number") return nt.tabs.size === 0;
  return $11("#dynamic-tabs li[role=context]").length === 0;
}
function hostFramework() {
  try {
    if (window.self !== window.top && window.top.Framework) return window.top.Framework;
  } catch (_) {
  }
  return window.Framework || null;
}
function getTabMeta(url2) {
  const fw = hostFramework();
  if (fw && fw.api && typeof fw.api.getTabMeta === "function") return fw.api.getTabMeta(normalizeUrl(url2));
  return null;
}
function setHash(id) {
  if (window.self !== window.top) return;
  const hash = "#" + String(id || "").replace(/^#/, "");
  if (history.replaceState) {
    history.replaceState(null, null, hash);
  } else {
    window.location.hash = hash;
  }
}
function openTab(id, title, icon) {
  id = normalizeUrl(id);
  if (!id) return null;
  const navtabs = window.navtab;
  if (!navtabs || !navtabs.api || typeof navtabs.api.addTab !== "function") {
    window.location.href = id;
    return null;
  }
  navtabs.api.addTab({ id, title: title || "页面", icon: icon || "", url: id, content: "" });
  setHash(id);
  return id;
}
function openFrame(url2, title, icon) {
  const fw = hostFramework();
  if (fw && fw.api && typeof fw.api.openInFrame === "function") {
    fw.api.openInFrame(url2, title || "", icon || "");
    setHash(url2);
    return true;
  }
  window.location.href = url2;
  return false;
}
function matchLeaf(url2) {
  const path = pathOf(url2);
  if (!path) return $11();
  const candidates = [path];
  if (!/\/index$/.test(path)) candidates.push(path + "/index");
  const $leaves = $11(".sidebar-menu a[data-url]");
  for (let i = 0; i < candidates.length; i++) {
    let $hit = $11();
    $leaves.each(function() {
      if (pathOf(this.getAttribute("data-url")) === candidates[i]) {
        $hit = $11(this);
        return false;
      }
    });
    if ($hit.length) return $hit;
  }
  return $11();
}
function switchTop(parentId, opts = {}) {
  const $target = $11('.top-menu-item[data-id="' + parentId + '"]');
  if (!$target.length) return false;
  $11(".top-menu-item").removeClass("active");
  $target.addClass("active");
  $11(".sidebar-menu:not(.sidebar-persist)").addClass("d-none");
  $11(".sidebar-menu:not(.sidebar-persist)").removeClass("active");
  const $sidebar = $11('.sidebar-menu[data-parent="' + parentId + '"]').removeClass("d-none").addClass("active");
  const saved = getActive();
  if (parentId !== TEMP_PLUGIN_PARENT) {
    setActive(parentId, saved ? saved.url : null);
  }
  if (opts.autoOpenFirst && hasNoOpenTab()) {
    const $firstLeaf = $sidebar.find("a[data-url]").first();
    if ($firstLeaf.length) $firstLeaf.trigger("click");
  }
  return true;
}
function highlight(input, opts = {}) {
  const $leaf = input && input.jquery ? input : matchLeaf(input);
  if (!$leaf || !$leaf.length) return null;
  $11(".sidebar-menu a[data-url]").removeClass("active");
  $leaf.addClass("active");
  const $tree = $leaf.closest(".nav-treeview");
  if ($tree.length) {
    $tree.show();
    $tree.closest("li.nav-item").addClass("menu-is-opening menu-open");
    $tree.siblings("a.nav-link").attr("aria-expanded", "true");
    if (opts.markRoot) {
      $11(".sidebar-menu>.nav-item>.nav-link").removeClass("active");
      $tree.siblings("a.nav-link").addClass("active");
      $tree.siblings("a.nav-link").closest("li.nav-item").addClass("menu-is-opening menu-open");
    }
  }
  return $leaf;
}
function openLeaf($leaf, opts = {}) {
  if (!$leaf || !$leaf.length) return null;
  const base = normalizeUrl($leaf.attr("data-url"));
  if (!base) return null;
  const tabUrl = opts.url || base + queryOf(opts.source || "");
  const title = opts.title || $leaf.attr("data-title") || $leaf.find("p").text().trim() || "页面";
  const icon = opts.icon || leafIcon($leaf);
  const parentId = $leaf.closest(".sidebar-menu").data("parent");
  if (parentId != null) switchTop(parentId, { autoOpenFirst: false });
  highlight($leaf, { markRoot: opts.markRoot !== false });
  if (parentId != null && parentId !== TEMP_PLUGIN_PARENT && opts.persist !== false) {
    setActive(parentId, base);
  }
  return openTab(tabUrl, title, icon);
}
function openInSidebar(parentId, url2, opts = {}) {
  const $scoped = $11('.sidebar-menu[data-parent="' + parentId + '"]');
  const path = pathOf(url2);
  let $leaf = $11();
  $scoped.find("a[data-url]").each(function() {
    if (pathOf(this.getAttribute("data-url")) === path) {
      $leaf = $11(this);
      return false;
    }
    return void 0;
  });
  if ($leaf.length) {
    return openLeaf($leaf, { source: url2, title: opts.title, icon: opts.icon });
  }
  return openTab(normalizeUrl(opts.tabId || url2), opts.title, opts.icon);
}
function open(url2, opts = {}) {
  const normalized = normalizeUrl(url2);
  if (!/^\//.test(normalized)) return false;
  if ((opts.route || "auto") !== "tab") {
    const $leaf = matchLeaf(normalized);
    if ($leaf.length) {
      openLeaf($leaf, {
        url: normalizeUrl($leaf.attr("data-url")) + queryOf(normalized),
        source: normalized,
        title: opts.title,
        icon: opts.icon,
        markRoot: true
      });
      return true;
    }
  }
  if (opts.fallback === false) return false;
  const meta = getTabMeta(normalized) || {};
  openFrame(normalized, opts.title || meta.title || "", opts.icon || meta.icon || "");
  return false;
}
function restore() {
  const saved = getActive();
  if (!saved) return null;
  if (!hasNoOpenTab()) return saved;
  if (saved.parentId != null) switchTop(saved.parentId, { autoOpenFirst: false });
  if (!saved.url) return saved;
  const $leaf = matchLeaf(saved.url);
  if (!$leaf.length) return saved;
  $leaf.trigger("click");
  return saved;
}
var $11, TEMP_PLUGIN_PARENT, ACTIVE_KEY, nav, wise_navmenu_default;
var init_wise_navmenu = __esm({
  "public/statics/modules/wise-navmenu.js"() {
    $11 = window.jQuery;
    TEMP_PLUGIN_PARENT = "temp-plugin";
    ACTIVE_KEY = "wise.nav.active";
    nav = {
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
      restore
    };
    window.Wise = window.Wise || {};
    window.Wise.nav = nav;
    wise_navmenu_default = nav;
  }
});

// public/statics/modules/wise-framework.js
var wise_framework_exports = {};
__export(wise_framework_exports, {
  Framework: () => Framework,
  default: () => wise_framework_default
});
var $12, Layer2, Bootstrap, NavTabs, Broadcast, HotKey, Framework, wise_framework_default;
var init_wise_framework = __esm({
  "public/statics/modules/wise-framework.js"() {
    init_wise_util();
    init_wise_ui();
    $12 = window.jQuery;
    Layer2 = window.Layer;
    Bootstrap = window.bootstrap;
    NavTabs = window.navtab;
    Broadcast = window.Broadcast;
    HotKey = window.HotKey;
    http.setInterceptor({
      codeKey: "code",
      successCode: 0,
      dataKey: "data",
      msgKey: "msg",
      unauthorizedCode: 401
    });
    http.setErrorHandler((err) => toast.error(err.message));
    http.setLoadingHandler({
      show: () => loading.show(),
      hide: () => loading.hide()
    });
    http.setUnauthorizedHandler(() => {
      window.location.href = document.querySelector('meta[name="login-url"]')?.content || "/admin/login";
    });
    Framework = {
      api: {
        // ---- 主题切换（已委托 Wise.theme，旧 'theme' key 仅做一次性迁移）----
        // 说明：主题状态统一由 wise-theme.js 管理（localStorage key: wise.theme，
        // Cookie: ws_theme，服务端 ext_fields 持久化）。此方法保留为兼容旧调用方
        // （admin/index.js、iframe 内 Framework.api.switchTheme()）的桩：
        //   1. 若本浏览器尚无 wise.theme，把旧 'theme' key 迁移过来；
        //   2. 不做任何覆盖，避免与 Wise.theme 的独立明暗/配色状态打架。
        switchTheme(theme2 = {}, sync = false, focus = false) {
          const W2 = window.Wise;
          if (W2 && W2.theme && typeof W2.theme.set === "function") {
            try {
              if (!localStorage.getItem(W2.theme.STORAGE_KEY)) {
                const legacy = JSON.parse(localStorage.getItem("theme") || "null");
                if (legacy && typeof legacy === "object" && (legacy.theme || legacy.mode)) {
                  W2.theme.set({
                    mode: legacy.theme || legacy.mode || "dark",
                    scheme: legacy.scheme || "blue",
                    sidebar: legacy.sidebar || "auto"
                  }, { sync: false });
                }
              }
            } catch (e) {
            }
            return;
          }
          let storedTheme;
          try {
            storedTheme = JSON.parse(localStorage.getItem("theme") || '{"theme":"dark", "scheme":""}');
          } catch (e) {
            storedTheme = { theme: "dark", scheme: "" };
          }
          const finalTheme = {
            theme: theme2.theme || storedTheme.theme || "dark",
            scheme: theme2.scheme || storedTheme.scheme || ""
          };
          if (["light", "dark", "auto"].indexOf(finalTheme.theme) === -1) finalTheme.theme = "auto";
          if (sync) {
            $12.ajax({ url: "/admin/index/config", type: "POST", data: finalTheme });
          }
          const getPreferredTheme = () => {
            if (storedTheme.theme !== null) return storedTheme;
            const preferredTheme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
            const preferredScheme = $12("body").data("color-scheme") || "";
            return { theme: preferredTheme, scheme: preferredScheme };
          };
          const setTheme = (theme3) => {
            if (theme3.theme === "auto" && window.matchMedia("(prefers-color-scheme: dark)").matches) {
              $12("html").attr("data-bs-theme", "dark");
            } else {
              $12("html").attr("data-bs-theme", theme3.theme);
            }
            $12("html").attr("data-color-scheme", theme3.scheme);
            localStorage.setItem("theme", JSON.stringify(theme3));
          };
          const showActiveTheme = (theme3, shouldFocus = false) => {
            const $switcher = $12("#bd-theme");
            if (!$switcher.length) return;
            const $switcherText = $12("#bd-theme-text");
            const $activeThemeIcon = $12(".theme-icon-active i");
            const $btnToActive = $12(`[data-bs-theme-value="${theme3.theme}"]`);
            const svgOfActiveBtn = $btnToActive.find("i").attr("class").replace("me-2", "");
            $12("[data-bs-theme-value]").removeClass("active").attr("aria-pressed", "false");
            $btnToActive.addClass("active").attr("aria-pressed", "true");
            $activeThemeIcon.attr("class", svgOfActiveBtn);
            const label = `${$switcherText.text()} (${$btnToActive.data("bs-theme-value")})`;
            $switcher.attr("aria-label", label);
            if (shouldFocus) $switcher.trigger("focus");
          };
          setTheme(getPreferredTheme());
          showActiveTheme(getPreferredTheme(), focus);
        },
        // ---- iframe 消息 ----
        sendMessageToIframe(conId, message) {
          const container = document.getElementById(conId);
          if (!container) return;
          const iframe = container.querySelector("iframe");
          if (iframe && iframe.contentWindow && iframe.contentWindow.Framework) {
            iframe.contentWindow.postMessage(message, window.location.origin);
          } else if (iframe) {
            let messages = [];
            try {
              messages = JSON.parse(iframe.dataset.messages || "[]");
            } catch (_) {
            }
            messages.push(JSON.stringify(message));
            iframe.dataset.messages = JSON.stringify(messages);
          }
        },
        // ---- 框架内打开链接（iframe 标签页） ----
        openInFrame(url2, title, icon, retry = 0) {
          if (!url2) return;
          const nav2 = window.navtab || window.Wise && window.Wise.navtabs;
          if (!nav2 || !nav2.api || typeof nav2.api.addTab !== "function") {
            window.location.href = url2;
            return;
          }
          if (nav2._ready === false && retry < 40) {
            setTimeout(() => Framework.api.openInFrame(url2, title, icon, retry + 1), 50);
            return;
          }
          const normalized = String(url2).replace(/\.html([?#].*)?$/, "$1");
          const meta = { title: title || "页面", icon: icon || "" };
          Framework.api.saveTabMeta(normalized, meta);
          nav2.api.addTab({
            id: normalized,
            url: normalized,
            title: meta.title,
            icon: meta.icon,
            isActive: true
          });
        },
        // ---- 非菜单链接的标题/图标持久化（供刷新后 hash fallback 恢复） ----
        saveTabMeta(url2, meta) {
          try {
            const map = JSON.parse(localStorage.getItem("wise.tab.meta") || "{}");
            map[url2] = meta;
            localStorage.setItem("wise.tab.meta", JSON.stringify(map));
          } catch (_) {
          }
        },
        getTabMeta(url2) {
          try {
            const map = JSON.parse(localStorage.getItem("wise.tab.meta") || "{}");
            return map[url2] || null;
          } catch (_) {
            return null;
          }
        },
        // ---- 获取 CSRF Token（底层核心能力，供全局 AJAX 注入与其它模块复用） ----
        // 优先读 Cookie：中间件每次写请求校验通过后会轮换 token，Cookie 始终是最新值；
        // meta 标签只在页面渲染时注入一次，仅作首次加载兜底。
        getCsrfToken() {
          const name = "csrf_token=";
          const parts = document.cookie.split(";");
          for (let i = 0; i < parts.length; i++) {
            const c = parts[i].trim();
            if (c.indexOf(name) === 0) return c.substring(name.length);
          }
          const meta = document.querySelector('meta[name="csrf-token"]');
          return meta ? meta.getAttribute("content") : "";
        }
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
        const own = window.Wise && window.Wise.nav ? window.Wise.nav : null;
        if (window.self === window.top) return own;
        try {
          const top = window.top;
          if (top && top.Wise && top.Wise.nav) return top.Wise.nav;
        } catch (_) {
        }
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
          messages = JSON.parse(iframe.dataset.messages || "[]", (key, value) => {
            try {
              return JSON.parse(value);
            } catch (_) {
              return value;
            }
          });
        } catch (_) {
        }
        delete iframe.dataset.messages;
        messages.forEach((msg) => window.postMessage(msg, window.location.origin));
      },
      // ---- 全局事件绑定 ----
      bindEvent() {
        setTimeout(() => $12(".loading-overlay").addClass("hidden"), 500);
        window.addEventListener("blur", () => $12(".dropdown-menu").removeClass("show"));
        $12("body").tooltip({ selector: '[data-bs-toggle="tooltip"]', trigger: "hover" });
        window.addEventListener("message", (event) => {
          if (event.origin !== window.location.origin) return;
          if (event.data.type === "tab:contentLoaded") {
            if ($12("#index_header_title").length && $12("#index_header_title").text() === "[caption]") {
              $12("#index_header_title").text(event.data?.opts?.title || "");
            }
            if ($12("#index_header_icon").length && $12("#index_header_icon").attr("class") === "[icon]") {
              $12("#index_header_icon").attr("class", event.data.opts.icon);
            }
          }
        });
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((dropdown) => {
          dropdown.addEventListener("show.bs.dropdown", function() {
            const tooltip = Bootstrap.Tooltip.getInstance(this.parentNode);
            if (tooltip) {
              tooltip.hide();
              tooltip.disable();
            }
          });
          dropdown.addEventListener("blur", function() {
            const tooltip = Bootstrap.Tooltip.getInstance(this.parentNode);
            if (tooltip) tooltip.enable();
          });
        });
        $12("[data-bs-cache-value]").on("click", function() {
          const value = $12(this).data("bs-cache-value");
          $12.ajax({
            url: "/admin/idler/clearCache",
            type: "POST",
            data: { type: value },
            success(res) {
              Layer2.toast({ content: res.code == 0 ? "缓存已清理" : "缓存清理失败", type: res.code == 0 ? "success" : "error" });
            },
            error() {
              Layer2.toast({ content: "缓存清理失败", type: "error" });
            }
          });
        });
        if ($12('meta[name="site-subframe"]').attr("content") === "true" && window.self === window.top) {
          const loc = window.location.href.replace(window.location.origin, "");
          Layer2.confirm("是否从主页面访问", { title: "跳转提示", icon: "warning" }, function() {
            window.location.href = window.location.origin + "/admin/index.html#" + loc;
          });
        }
        $12(document).on("keydown", function(e) {
          if (window.self === window.parent) return;
          if (e.key !== "Escape") return;
          if (["INPUT", "TEXTAREA", "SELECT"].includes(e.target.tagName)) return;
          if (!window.parent.wsLayer) return;
          const iframeId = (window.frameElement?.id || "").replace("ws-layer-iframe-", "");
          if (!iframeId) return;
          window.parent.$(window.parent.document).triggerHandler($12.Event("keydown", $12.extend({}, e, { namespace: "ws-layer-" + iframeId })));
        });
        $12(document).on("click", "a[data-frame-url]", function(e) {
          const $a = $12(this);
          const url2 = $a.attr("data-frame-url") || $a.attr("href");
          if (!url2 || url2 === "#" || url2.indexOf("javascript:") === 0) return;
          if (e.ctrlKey || e.metaKey || e.which === 2) return;
          e.preventDefault();
          const title = $a.attr("data-frame-title") || $a.attr("data-title") || $a.text().trim();
          const icon = $a.attr("data-frame-icon") || $a.find(".nav-icon").attr("class") || "";
          Framework.api.openInFrame(url2, title, icon);
        });
        $12(document).on("click", "a[data-wise-nav]", function(e) {
          if (e.ctrlKey || e.metaKey || e.shiftKey || e.which === 2) return;
          if (this.target && this.target !== "_self") return;
          const url2 = this.getAttribute("href") || "";
          if (!url2 || url2.charAt(0) !== "/") return;
          const nav2 = Framework.hostNav();
          if (!nav2) return;
          e.preventDefault();
          const route = String(this.getAttribute("data-wise-nav") || "").trim().toLowerCase();
          nav2.open(url2, {
            title: this.getAttribute("data-wise-title") || this.getAttribute("data-title") || "",
            icon: this.getAttribute("data-wise-icon") || "",
            route: route === "tab" ? "tab" : "auto"
          });
        });
      },
      // ---- 组件初始化 ----
      initComponents() {
        Framework.iconSelector();
      },
      // ---- 图标选择器 ----
      iconSelector() {
        $12("[data-ws-icon]").on("click", function() {
          const input = $12(this).data("wsIcon");
          const inputPreview = $12(this).data("wsIconPreview");
          Layer2.open({
            type: 2,
            title: "图标选择器",
            content: "/admin/icons?module=bootstrap",
            area: ["80%", "80%"],
            resize: true,
            btn: ["选择图标", "取消"],
            yes(index, layero) {
              const selectedIcon = Layer2.getShared ? Layer2.getShared("selectedIcon") : window._selectedIcon;
              $12(input).val(`bi bi-${selectedIcon}`);
              $12(inputPreview).html(`<i class="bi bi-${selectedIcon}"></i>`);
              Layer2.close(index);
            }
          });
        });
      },
      // ---- 快捷键 ----
      bindShortcuts() {
        const hotKey = new HotKey("framework");
        const isTop = window.self === window.top;
        hotKey.subscribe("hotkey-registered", (data3) => {
          if (data3.pageId !== hotKey.pageId) return;
          const keys = data3.hotkey.split("+").map((k) => k.trim());
          $12(".shortcuts-content").append(`<div class="row px-3 py-2"><div class="col-6 text-end">${data3.description}</div>
                <div class="col-6"><span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">${keys[0]}</span>
                ${keys.length > 1 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[1] + "</span>" : ""}
                ${keys.length > 2 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[2] + "</span>" : ""}
                ${keys.length > 3 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[3] + "</span>" : ""}
                </div></div>`);
        });
        hotKey.register("shift+s", () => {
          if (isTop) $12('[data-lte-toggle="sidebar-search"] input')[0]?.focus();
        }, "搜索菜单");
        hotKey.subscribe("hotkey-triggered", (data3) => {
          if (isTop && data3.hotkey === "shift+s") $12('[data-lte-toggle="sidebar-search"] input')[0]?.focus();
        });
        hotKey.register("shift+l", () => {
          if (isTop) $12('[data-lte-widget="lockscreen"]').click();
        }, "临时锁屏");
        hotKey.subscribe("hotkey-triggered", (data3) => {
          if (isTop && data3.hotkey === "shift+l") $12('[data-lte-widget="lockscreen"]').click();
        });
        new Broadcast({ channelName: "framework" }).subscribe("lockscreen", () => {
          if (isTop) $12('[data-lte-widget="lockscreen"]').click();
        });
        hotKey.register("shift+x", () => {
          if (isTop) {
            Layer2.confirm("确定要退出当前账号吗？", { title: "退出当前账号", icon: 4, btn: ["确定退出", "取消"] }, () => {
              window.location.href = "/admin/login/logout";
            });
          }
        }, "退出当前账号");
        hotKey.subscribe("hotkey-triggered", (data3) => {
          if (isTop && data3.hotkey === "shift+x") {
            Layer2.confirm("确定要退出当前账号吗？", { title: "退出当前账号", icon: 4, btn: ["确定退出", "取消"] }, () => {
              window.location.href = "/admin/login/logout";
            });
          }
        });
        if (NavTabs?.api?.closeTab) {
          hotKey.register("shift+w", () => {
            if (isTop) NavTabs.api.closeTab();
          }, "关闭当前Tab页");
          hotKey.subscribe("hotkey-triggered", (data3) => {
            if (isTop && data3.hotkey === "shift+w") NavTabs.api.closeTab();
          });
        }
        hotKey.register("shift+r", () => {
          if (!isTop && $12(window.frameElement).parent().hasClass("active")) window.location.reload();
        }, "刷新当前Tab页");
        hotKey.subscribe("hotkey-triggered", (data3) => {
          if (!isTop && $12(window.frameElement).parent().hasClass("active") && data3.hotkey === "shift+r") window.location.reload();
        });
        if (isTop) {
          $12(".shortcuts-content").html("");
          hotKey.getAllHotkeys().forEach((key) => {
            const keys = key.hotkey.split("+").map((k) => k.trim());
            $12(".shortcuts-content").append(`<div class="row px-3 py-2"><div class="col-6 text-end">${key.description}</div>
                    <div class="col-6"><span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">${keys[0]}</span>
                    ${keys.length > 1 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[1] + "</span>" : ""}
                    ${keys.length > 2 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[2] + "</span>" : ""}
                    ${keys.length > 3 ? '+ <span class="badge text-bg-secondary text-capitalize" style="min-width: 2rem;">' + keys[3] + "</span>" : ""}
                    </div></div>`);
          });
        }
      }
    };
    $12(document).ajaxSend(function(_event, xhr) {
      const token = Framework.api.getCsrfToken();
      if (token) {
        xhr.setRequestHeader("X-CSRF-TOKEN", token);
      }
    });
    window.Framework = Framework;
    Framework.init();
    wise_framework_default = Framework;
  }
});

// public/statics/modules/wise-table.js
var wise_table_exports = {};
__export(wise_table_exports, {
  LIST_DEFAULTS: () => LIST_DEFAULTS,
  default: () => wise_table_default,
  table: () => table
});
function _escape(str) {
  return String(str == null ? "" : str).replace(/[&<>"']/g, (c) => _ESCAPE_MAP[c]);
}
function _getEnhancedTable() {
  const ET = window.EnhancedTable;
  return typeof ET === "function" ? ET : null;
}
function _waitEnhancedTable(timeoutMs = 1e4) {
  const ET = _getEnhancedTable();
  if (ET) return Promise.resolve(ET);
  return new Promise((resolve) => {
    const maxAttempts = Math.max(1, Math.round(timeoutMs / 100));
    let attempts = 0;
    const poll = setInterval(() => {
      attempts++;
      const found = _getEnhancedTable();
      if (found || attempts >= maxAttempts) {
        clearInterval(poll);
        resolve(found);
      }
    }, 100);
  });
}
function _idOf(selector) {
  if (typeof selector === "string") return selector.replace(/^#/, "");
  if (selector && typeof selector === "object" && selector.nodeType === 1) return selector.id || "";
  return "";
}
function _splitExtend(extend) {
  const nested = {};
  const top = {};
  Object.keys(extend || {}).forEach((key) => {
    if (EXTEND_KEYS.includes(key)) nested[key] = extend[key];
    else top[key] = extend[key];
  });
  return { nested, top };
}
function _normalizeOptions(selector, options2) {
  let raw;
  if (selector && typeof selector === "object" && selector.nodeType !== 1) {
    raw = Object.assign({}, selector);
  } else {
    raw = Object.assign({}, options2 || {});
    const id = _idOf(selector);
    if (id) raw.idTable = id;
  }
  const { nested, top } = _splitExtend(raw.extend);
  delete raw.extend;
  const opts = Object.assign({}, LIST_DEFAULTS, raw, top);
  opts.extend = Object.assign({ dragsort_url: "" }, nested);
  const userQueryParams = opts.queryParams;
  const userQuery = opts.query;
  if (typeof userQueryParams !== "function") {
    const pageSize = opts.pageSize;
    opts.queryParams = function(params) {
      const limit = params.limit > 0 ? params.limit : pageSize;
      return Object.assign(
        { pageSize: limit, pageNumber: Math.floor((params.offset || 0) / limit) + 1 },
        typeof userQuery === "function" ? userQuery() : {}
      );
    };
  }
  const userOnLoadSuccess = opts.onLoadSuccess;
  const onResponse = opts.onResponse;
  opts.onLoadSuccess = function(res) {
    if (typeof userOnLoadSuccess === "function") userOnLoadSuccess.call(this, res);
    if (typeof onResponse === "function") onResponse(res);
  };
  if (typeof opts.onLoadError !== "function") {
    opts.onLoadError = function() {
      toast.error("列表加载失败，请稍后重试");
    };
  }
  if (!opts.idTable) opts.idTable = "table";
  return opts;
}
function _bridgeEvents(instance) {
  if (!instance) return;
  instance.on = function(event, handler) {
    eventBus.on(`ws:table:${event}`, handler);
  };
  instance.emit = function(event, data3) {
    eventBus.emit(`ws:table:${event}`, data3);
  };
}
function _doCreate(EnhancedTable, options2) {
  try {
    const instance = new EnhancedTable(options2);
    _bridgeEvents(instance);
    _bindRowActions(instance, options2);
    const key = options2.idTable || `table-${Date.now()}`;
    table._instances.set(key, instance);
    logger.info("table", `表格已创建: ${key}`);
    eventBus.emit("ws:table:created", { selector: key, instance });
    return instance;
  } catch (err) {
    logger.error("table", "表格创建失败", err);
    return null;
  }
}
function _bindRowActions(instance, options2) {
  const onClick = options2 && options2.onClick;
  if (typeof onClick !== "function" || !instance) return;
  const tableId = options2.idTable || "table";
  _rowActionHandlers.set(tableId, { instance, onClick });
  if (_rowActionsDelegated) return;
  _rowActionsDelegated = true;
  document.addEventListener("click", (e) => {
    const btn = e.target && e.target.closest ? e.target.closest("[data-action]") : null;
    if (!btn) return;
    const tableEl = btn.closest("table");
    if (!tableEl || !tableEl.id) return;
    const entry = _rowActionHandlers.get(tableEl.id);
    if (!entry) return;
    if (!btn.hasAttribute("data-id")) return;
    e.preventDefault();
    const action3 = btn.getAttribute("data-action") || "";
    const id = btn.getAttribute("data-id");
    let row = null;
    try {
      const rows = typeof entry.instance.table.bootstrapTable === "function" ? entry.instance.table.bootstrapTable("getData") : [];
      row = (rows || []).find((r) => String(r.id) === String(id)) || null;
    } catch (err) {
    }
    entry.onClick(action3, row || { id }, btn);
  }, false);
}
var LIST_DEFAULTS, _ESCAPE_MAP, EXTEND_KEYS, _rowActionHandlers, _rowActionsDelegated, table, wise_table_default;
var init_wise_table = __esm({
  "public/statics/modules/wise-table.js"() {
    init_wise_core();
    init_wise_ui();
    LIST_DEFAULTS = {
      toolbar: "#toolbar",
      pageSize: 20,
      sortName: "id",
      sortOrder: "desc",
      // 依赖 admin 端点的通用搜索 / 导出：列表页统一关闭，页面按需开启
      search: false,
      commonSearch: false,
      showExport: false,
      // 响应结构适配
      dataDataField: "data.rows",
      dataTotalField: "data.total",
      dataPageNumber: "",
      // 列较多时的列选择器：默认开启
      showColumns: true,
      // 能力开关：批量选择 / 行内编辑需页面显式开启。
      // 未接批量接口就默认打开 checkboxHeader，只会产生"勾了没反应"的死交互。
      checkboxHeader: false,
      checkOnInit: false,
      dblClickToEdit: false,
      escape: true,
      // 中文三态：避免 bootstrap-table 英文兜底文案混入中文后台
      formatLoadingMessage: () => "正在加载，请稍候…",
      formatNoMatches: () => "暂无数据",
      formatSearch: () => "搜索"
    };
    _ESCAPE_MAP = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };
    EXTEND_KEYS = [
      "preview_url",
      "add_url",
      "edit_url",
      "del_url",
      "restore_url",
      "import_url",
      "multi_url",
      "dragsort_url",
      "preview_title",
      "add_title",
      "edit_title",
      "del_title"
    ];
    _rowActionHandlers = /* @__PURE__ */ new Map();
    _rowActionsDelegated = false;
    table = {
      /** @type {Map<string, Object>} 实例表 */
      _instances: /* @__PURE__ */ new Map(),
      /**
       * 创建表格（异步等待 EnhancedTable 就绪）
       *
       * @param {Object|string|HTMLElement} optionsOrSelector - options 对象，或表格选择器
       * @param {Object} [options] - selector 形态下的 options
       * @returns {Promise<Object|null>} EnhancedTable 实例
       */
      async create(optionsOrSelector, options2) {
        const ET = await _waitEnhancedTable();
        if (!ET) {
          logger.error("table", "EnhancedTable 加载超时");
          return null;
        }
        return _doCreate(ET, _normalizeOptions(optionsOrSelector, options2));
      },
      /**
       * 同步创建表格
       *
       * base.html 以 defer 加载 enhanced-table.js，且早于 ESM 入口，
       * 因此控制器执行时它必然已就绪。异步场景请用 create()。
       *
       * @param {Object|string|HTMLElement} optionsOrSelector - options 对象，或表格选择器
       * @param {Object} [options] - selector 形态下的 options
       * @returns {Object|null} EnhancedTable 实例
       */
      createSync(optionsOrSelector, options2) {
        const ET = _getEnhancedTable();
        if (!ET) {
          logger.error("table", "EnhancedTable 未就绪，请改用 await table.create()");
          return null;
        }
        return _doCreate(ET, _normalizeOptions(optionsOrSelector, options2));
      },
      /**
       * 刷新表格
       *
       * @param {Object|string|HTMLElement} [target] - EnhancedTable 实例 / 选择器；
       *                                               不传则刷新页面上所有 bootstrap-table
       */
      refresh(target) {
        if (target && target.table && typeof target.table.bootstrapTable === "function") {
          target.table.bootstrapTable("refresh", { silent: true });
          eventBus.emit("ws:table:refresh", { instance: target });
          return;
        }
        if (target) {
          const $table = $(target);
          if ($table.length) $table.bootstrapTable("refresh");
        } else {
          $('table[data-toggle="table"]').bootstrapTable("refresh");
        }
        eventBus.emit("ws:table:refresh", { selector: target });
      },
      /**
       * 生成操作列 HTML
       *
       * 按钮统一用 data-action / data-id 标记，由 {@see _bindRowActions()} 在
       * 创建表格时注册的文档级代理统一分发到页面声明的 `options.onClick`，
       * 避免在模板里写 onclick 内联处理器（CSP 不允许）。
       *
       * ⚠️ 按钮文本默认只渲染图标（文字在 title 提示里），如需可见文字请在
       *    buttons 配置里传 `label`。
       *
       * @param {Array<{action:string,title:string,icon?:string,class?:string,label?:string}>} buttons
       * @param {Object} row 当前行数据
       * @returns {string}
       */
      operateButtons(buttons, row) {
        const html = (buttons || []).map((btn) => {
          const cls = btn.class || "btn-outline-secondary";
          const icon = btn.icon ? `<i class="${_escape(btn.icon)}"></i> ` : "";
          const label = btn.label ? _escape(btn.label) : "";
          return '<button type="button" class="btn btn-xs ' + _escape(cls) + ' me-1" data-action="' + _escape(btn.action) + '" data-id="' + _escape(row && row.id || 0) + '" title="' + _escape(btn.title || "") + '">' + icon + label + "</button>";
        }).join("");
        return '<div class="btn-group btn-group-sm">' + html + "</div>";
      },
      /**
       * 获取选中的主键列表
       * @param {string} [selector]
       * @returns {string[]}
       */
      getSelectedIds(selector = "table") {
        const $table = $(selector);
        if (!$table.length) return [];
        return $table.bootstrapTable("getSelections").map((row) => row.id || row[$table.bootstrapTable("getOptions").pk || "id"]) || [];
      },
      /**
       * 获取选中的数据行
       * @param {string} [selector]
       * @returns {Object[]}
       */
      getSelectedData(selector = "table") {
        const $table = $(selector);
        if (!$table.length) return [];
        return $table.bootstrapTable("getSelections") || [];
      },
      /**
       * 检查是否选中了行
       * @param {string} [selector]
       * @returns {boolean}
       */
      hasSelected(selector = "table") {
        return this.getSelectedIds(selector).length > 0;
      },
      /**
       * 切换列可见性
       * @param {string} column - 列字段名
       * @param {string} [selector]
       */
      toggleColumn(column, selector = "table") {
        const $table = $(selector);
        if ($table.length) $table.bootstrapTable("toggleColumn", column);
      },
      /**
       * 获取格式化器
       * @returns {Object}
       */
      get formatters() {
        const ET = _getEnhancedTable();
        return ET ? ET.FORMATTERS : {};
      }
    };
    registry.register("table", table);
    eventBus.on("ws:core:ready", () => {
      let attempts = 0;
      const checkInterval2 = setInterval(() => {
        attempts++;
        if (_getEnhancedTable()) {
          clearInterval(checkInterval2);
          eventBus.emit("ws:table:ready");
          logger.info("table", "EnhancedTable 已就绪，Wise.table 可用");
        } else if (attempts > 50) {
          clearInterval(checkInterval2);
          logger.warn("table", "EnhancedTable 未就绪，Wise.table 不可用");
        }
      }, 200);
    });
    wise_table_default = table;
  }
});

// public/statics/modules/wise-grid.js
var wise_grid_exports = {};
__export(wise_grid_exports, {
  default: () => wise_grid_default,
  grid: () => grid
});
function _getGridManager() {
  return window.GridManager || null;
}
var grid, checkInterval, wise_grid_default;
var init_wise_grid = __esm({
  "public/statics/modules/wise-grid.js"() {
    init_wise_core();
    grid = {
      /**
       * 创建网格布局
       * @param {string|HTMLElement} selector - 容器选择器
       * @param {Object} options - { gridItems, baseHeight, columns, onLayoutChange }
       * @returns {Promise<Object|null>}
       */
      async create(selector, options2 = {}) {
        return new Promise((resolve, reject) => {
          const GM = _getGridManager();
          if (GM) {
            try {
              const instance = new GM(selector, options2);
              instance.init();
              eventBus.emit("ws:grid:created", { selector, instance });
              resolve(instance);
            } catch (err) {
              logger.error("grid", "创建网格失败", err);
              reject(err);
            }
            return;
          }
          let attempts = 0;
          const timer = setInterval(() => {
            attempts++;
            const GM2 = _getGridManager();
            if (GM2) {
              clearInterval(timer);
              try {
                const instance = new GM2(selector, options2);
                instance.init();
                eventBus.emit("ws:grid:created", { selector, instance });
                resolve(instance);
              } catch (err) {
                reject(err);
              }
            } else if (attempts > 100) {
              clearInterval(timer);
              reject(new Error("GridManager 加载超时"));
            }
          }, 100);
        });
      },
      /**
       * 订阅网格事件
       * @param {'created'|'change'} event
       * @param {Function} handler
       */
      on(event, handler) {
        return eventBus.on(`ws:grid:${event}`, handler);
      }
    };
    checkInterval = setInterval(() => {
      if (_getGridManager()) {
        clearInterval(checkInterval);
        eventBus.emit("ws:grid:ready");
        logger.info("grid", "GridManager 已就绪");
      }
    }, 200);
    setTimeout(() => clearInterval(checkInterval), 1e4);
    registry.register("grid", grid);
    wise_grid_default = grid;
  }
});

// public/statics/modules/wise-search.js
var wise_search_exports = {};
__export(wise_search_exports, {
  Search: () => Search,
  default: () => wise_search_default
});
var $13, Layer3, Ntabs, HotKey2, Search, wise_search_default;
var init_wise_search = __esm({
  "public/statics/modules/wise-search.js"() {
    $13 = window.jQuery;
    Layer3 = window.Layer;
    Ntabs = window.navtab;
    HotKey2 = window.HotKey;
    Search = {
      modal: null,
      input: null,
      results: [],
      selectedIndex: -1,
      debounceTimer: null,
      recentKey: "wise_search_recent",
      _inited: false,
      init() {
        if (this._inited) return;
        this._inited = true;
        const $trigger = $13("#global-search-trigger");
        if (!$trigger.length || !$13("#globalSearchModal").length) {
          return;
        }
        this.modal = new bootstrap.Modal("#globalSearchModal", { keyboard: true, backdrop: true });
        this.input = $13("#global-search-input");
        $13("#global-search-trigger").on("click", (e) => {
          e.preventDefault();
          this.open();
        });
        const hotKey = new HotKey2("framework");
        const openSearch = () => this.open();
        hotKey.register("/", openSearch, "全局搜索");
        hotKey.register("shift+/", openSearch, "全局搜索");
        hotKey.register("ctrl+k", openSearch, "全局搜索");
        hotKey.register("meta+k", openSearch, "全局搜索");
        hotKey.subscribe("hotkey-triggered", (data3) => {
          if (data3.hotkey === "shift+/" || data3.hotkey === "ctrl+k" || data3.hotkey === "meta+k") openSearch();
        });
        this.input.on("input", () => {
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
        this.input.on("keydown.wiseSearchNav", (e) => this._handleKeydown(e));
        $13("#globalSearchModal").on("shown.bs.modal", () => {
          this.input.val("").focus();
          this.selectedIndex = -1;
          this.results = [];
          this.showRecent();
        });
        $13("#globalSearchModal").on("hidden.bs.modal", () => {
          this.input.val("");
          $13("#global-search-results").html('<div class="text-center py-3 text-muted small">输入关键词开始搜索...</div>');
          $13("#search-result-count").text("");
          this.selectedIndex = -1;
          this.results = [];
        });
      },
      // ---- 打开搜索框 ----
      open() {
        if (!this.modal) {
          this.modal = new bootstrap.Modal("#globalSearchModal");
        }
        this.modal.show();
      },
      // ---- 执行搜索 ----
      async doSearch(keyword) {
        const $container = $13("#global-search-results");
        $container.html('<div class="text-center py-3 text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>搜索中...</div>');
        $13("#search-result-count").text("");
        try {
          const token = $13('meta[name="csrf-token"]').attr("content") || "";
          const res = await $13.ajax({
            url: "/admin/search/global",
            type: "POST",
            data: { keyword },
            dataType: "json",
            headers: token ? { "X-CSRF-Token": token } : {}
          });
          if (res.code === 0 && Array.isArray(res.data) && res.data.length > 0) {
            this.results = res.data;
            this.selectedIndex = 0;
            this._render(res.data);
            this._saveRecent(keyword);
          } else {
            $13("#global-search-results").html(
              '<div class="text-center py-4 text-muted">未找到 "<strong>' + this._escapeHtml(keyword) + '</strong>" 的相关结果</div>'
            );
            $13("#search-result-count").text("");
          }
        } catch (err) {
          $13("#global-search-results").html(
            '<div class="text-center py-3 text-danger">搜索失败，请重试</div>'
          );
          console.error("全局搜索请求失败:", err);
        }
      },
      // ---- 渲染结果 ----
      _render(results) {
        const grouped = {};
        results.forEach((r) => {
          const cat = r.category || "其他";
          if (!grouped[cat]) grouped[cat] = [];
          grouped[cat].push(r);
        });
        let html = '<div class="list-group list-group-flush">';
        let idx = 0;
        for (const [category, items] of Object.entries(grouped)) {
          html += '<div class="px-3 py-1 bg-body-secondary small text-muted fw-medium">' + this._escapeHtml(category) + "</div>";
          items.forEach((item) => {
            const icon = item.icon || "bi bi-circle";
            const title = this._escapeHtml(item.title || "");
            const sub = this._escapeHtml(item.breadcrumb || item.subtitle || "");
            html += '<a href="javascript:;" class="list-group-item list-group-item-action d-flex align-items-center py-2 search-result-item" data-index="' + idx + '" data-url="' + this._escapeAttr(item.url || "") + '" data-title="' + this._escapeAttr(item.title || "") + '" data-icon="' + this._escapeAttr(icon) + '"><i class="' + this._escapeAttr(icon) + ' me-3 fs-5 text-secondary"></i><div class="flex-grow-1 min-w-0"><div class="fw-medium text-truncate">' + title + "</div>" + (sub ? '<small class="text-muted text-truncate d-block">' + sub + "</small>" : "") + '</div><i class="bi bi-arrow-return-left text-muted ms-2 opacity-50"></i></a>';
            idx++;
          });
        }
        html += "</div>";
        const $container = $13("#global-search-results");
        $container.html(html);
        $13("#search-result-count").text("共 " + results.length + " 条结果");
        $container.find(".search-result-item").on("click", (e) => {
          this._openTab($13(e.currentTarget));
        });
      },
      // ---- 打开标签页 ----
      _openTab($item) {
        const url2 = $item.data("url");
        const title = $item.data("title");
        const icon = $item.data("icon");
        const tabId = url2.replace(/\.html$/g, "").replace(/^\/+/g, "/");
        try {
          if (typeof Ntabs !== "undefined" && Ntabs.api && typeof Ntabs.api.addTab === "function") {
            Ntabs.api.addTab({ id: tabId, title, icon, url: url2, content: "" });
          } else {
            window.location.href = url2;
          }
        } catch (e) {
          window.location.href = url2;
        }
        this.modal.hide();
      },
      // ---- 键盘导航 ----
      _handleKeydown(e) {
        if (this.results.length === 0) return;
        switch (e.key) {
          case "ArrowDown":
            e.preventDefault();
            this.selectedIndex = (this.selectedIndex + 1) % this.results.length;
            this._highlight();
            break;
          case "ArrowUp":
            e.preventDefault();
            this.selectedIndex = (this.selectedIndex - 1 + this.results.length) % this.results.length;
            this._highlight();
            break;
          case "Enter":
            e.preventDefault();
            if (this.selectedIndex >= 0) {
              const $item = $13(".search-result-item").eq(this.selectedIndex);
              if ($item.length) this._openTab($item);
            }
            break;
          case "Escape":
            e.preventDefault();
            this.modal.hide();
            break;
        }
      },
      _highlight() {
        $13(".search-result-item").removeClass("active");
        $13(".search-result-item").eq(this.selectedIndex).addClass("active");
        const el = $13(".search-result-item")[this.selectedIndex];
        if (el) el.scrollIntoView({ block: "nearest", behavior: "smooth" });
      },
      // ---- 最近搜索（localStorage） ----
      _saveRecent(keyword) {
        try {
          let recent = JSON.parse(localStorage.getItem(this.recentKey) || "[]");
          recent = [keyword, ...recent.filter((k) => k !== keyword)].slice(0, 8);
          localStorage.setItem(this.recentKey, JSON.stringify(recent));
        } catch (e) {
        }
      },
      showRecent() {
        let recent = [];
        try {
          recent = JSON.parse(localStorage.getItem(this.recentKey) || "[]");
        } catch (e) {
        }
        if (recent.length === 0) {
          $13("#global-search-results").html(
            '<div class="text-center py-4 text-muted">输入关键词搜索菜单和功能<br><small class="text-secondary">提示：按 <kbd class="bg-body-secondary rounded px-1">/</kbd> 快速唤醒</small></div>'
          );
          $13("#search-result-count").text("");
          return;
        }
        let html = '<div class="px-3 py-1 bg-body-secondary small text-muted fw-medium">最近搜索</div>';
        html += '<div class="list-group list-group-flush">';
        recent.forEach((kw) => {
          html += '<a href="javascript:;" class="list-group-item list-group-item-action border-0 py-2 recent-item"><i class="bi bi-clock-history me-2 text-muted opacity-75"></i>' + this._escapeHtml(kw) + "</a>";
        });
        html += "</div>";
        const $container = $13("#global-search-results");
        $container.html(html);
        $13("#search-result-count").text("");
        $container.find(".recent-item").on("click", (e) => {
          const kw = $13(e.currentTarget).text().trim();
          this.input.val(kw);
          this.doSearch(kw);
        });
      },
      // ---- 工具 ----
      _escapeHtml(str) {
        const div = document.createElement("div");
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
      },
      _escapeAttr(str) {
        return String(str).replace(/"/g, "&quot;").replace(/'/g, "&#39;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
      }
    };
    wise_search_default = Search;
  }
});

// public/statics/modules/wise-slot.js
var wise_slot_exports = {};
__export(wise_slot_exports, {
  SlotRenderer: () => SlotRenderer,
  default: () => wise_slot_default
});
var DEFAULT_LOADING_HTML, DEFAULT_ERROR_HTML, _definitions, _rendered, _SlotRenderer_instances, renderComponent_fn, resolveComponentPath_fn, createItemContainer_fn, applyRendererClass_fn, log_fn, warn_fn, SlotRenderer, slotRenderer, wise_slot_default;
var init_wise_slot = __esm({
  "public/statics/modules/wise-slot.js"() {
    DEFAULT_LOADING_HTML = '<div class="d-flex justify-content-center p-3"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div></div>';
    DEFAULT_ERROR_HTML = '<div class="text-center text-muted p-2"><small>组件加载失败</small></div>';
    SlotRenderer = class {
      constructor() {
        __privateAdd(this, _SlotRenderer_instances);
        /**
         * Slot 元数据注册表
         * @type {Map<string, Object>}
         */
        __privateAdd(this, _definitions, /* @__PURE__ */ new Map());
        /**
         * 已渲染的组件实例缓存
         * @type {Map<string, Array<{name: string, instance: Object, container: HTMLElement}>>}
         */
        __privateAdd(this, _rendered, /* @__PURE__ */ new Map());
      }
      /**
       * 初始化：从 __WS_CONFIG__ 或 API 加载插槽元数据
       *
       * @param {string} page 页面标识，如 'dashboard'
       * @returns {Promise<void>}
       */
      async init(page = "dashboard") {
        const config2 = window.__WS_CONFIG__;
        if (config2 && config2.slots && Array.isArray(config2.slots)) {
          for (const slot of config2.slots) {
            __privateGet(this, _definitions).set(slot.name, slot);
          }
          __privateMethod(this, _SlotRenderer_instances, log_fn).call(this, `已从配置加载 ${config2.slots.length} 个插槽`);
          return;
        }
        try {
          const url2 = Wise.config.get("slots.api", "/admin/slot/list") + "?page=" + page;
          const resp = await fetch(url2, {
            headers: { "X-Requested-With": "XMLHttpRequest" }
          });
          const json = await resp.json();
          if (json.code === 0 && json.data && json.data.slots) {
            for (const slot of json.data.slots) {
              __privateGet(this, _definitions).set(slot.name, slot);
            }
            __privateMethod(this, _SlotRenderer_instances, log_fn).call(this, `已从 API 加载 ${json.data.slots.length} 个插槽`);
          }
        } catch (err) {
          __privateMethod(this, _SlotRenderer_instances, warn_fn).call(this, "加载插槽配置失败", err);
        }
      }
      /**
       * 渲染页面上的所有插槽
       * 查找所有带 data-slot 属性的元素并渲染。
       *
       * @returns {Promise<void>}
       */
      async renderAll() {
        const containers = document.querySelectorAll("[data-slot]");
        if (containers.length === 0) return;
        __privateMethod(this, _SlotRenderer_instances, log_fn).call(this, `发现 ${containers.length} 个插槽占位元素`);
        const tasks = [];
        for (const container of containers) {
          const slotName = container.dataset.slot;
          if (slotName) {
            tasks.push(this.render(slotName, container));
          }
        }
        await Promise.allSettled(tasks);
      }
      /**
       * 渲染指定插槽
       *
       * @param {string} slotName 插槽名称
       * @param {HTMLElement|null} container 目标容器（可选，默认查找 data-slot 元素）
       * @returns {Promise<void>}
       */
      async render(slotName, container = null) {
        if (!container) {
          container = document.querySelector(`[data-slot="${slotName}"]`);
        }
        if (!container) {
          __privateMethod(this, _SlotRenderer_instances, warn_fn).call(this, `插槽 [${slotName}] 的占位元素不存在`);
          return;
        }
        const definition = __privateGet(this, _definitions).get(slotName);
        if (!definition) {
          __privateMethod(this, _SlotRenderer_instances, warn_fn).call(this, `插槽 [${slotName}] 未在配置中定义`);
          return;
        }
        const contents = definition.contents || [];
        if (contents.length === 0) {
          if (container.dataset.slotHideEmpty !== "false") {
            container.style.display = "none";
          }
          return;
        }
        const renderer = definition.renderer || "stack";
        __privateMethod(this, _SlotRenderer_instances, applyRendererClass_fn).call(this, container, renderer);
        const instances = [];
        for (const item of contents) {
          const itemContainer = __privateMethod(this, _SlotRenderer_instances, createItemContainer_fn).call(this, container, renderer, item);
          const instance = await __privateMethod(this, _SlotRenderer_instances, renderComponent_fn).call(this, item, itemContainer);
          if (instance) {
            instances.push({ name: item.component, instance, container: itemContainer });
          }
        }
        __privateGet(this, _rendered).set(slotName, instances);
        __privateMethod(this, _SlotRenderer_instances, log_fn).call(this, `插槽 [${slotName}] 渲染完成 (${instances.length}/${contents.length})`);
      }
      /**
       * 销毁指定插槽的所有组件
       *
       * @param {string} slotName 插槽名称
       */
      async destroy(slotName) {
        const instances = __privateGet(this, _rendered).get(slotName);
        if (!instances) return;
        for (const { instance, container } of instances) {
          try {
            if (instance && typeof instance.destroy === "function") {
              await instance.destroy(container);
            }
          } catch (err) {
            __privateMethod(this, _SlotRenderer_instances, warn_fn).call(this, `销毁组件失败 [${slotName}]`, err);
          }
          if (container && container.parentNode) {
            container.remove();
          }
        }
        __privateGet(this, _rendered).delete(slotName);
      }
      /**
       * 刷新指定插槽（销毁后重新渲染）
       *
       * @param {string} slotName
       */
      async refresh(slotName) {
        await this.destroy(slotName);
        await this.render(slotName);
      }
      /**
       * 手动注册一个插槽（运行时动态添加）
       *
       * @param {string} slotName 插槽名称
       * @param {Object} definition 插槽定义
       */
      register(slotName, definition) {
        __privateGet(this, _definitions).set(slotName, definition);
      }
      /**
       * 获取插槽定义
       *
       * @param {string} slotName
       * @returns {Object|undefined}
       */
      getDefinition(slotName) {
        return __privateGet(this, _definitions).get(slotName);
      }
    };
    _definitions = new WeakMap();
    _rendered = new WeakMap();
    _SlotRenderer_instances = new WeakSet();
    renderComponent_fn = async function(item, container) {
      const componentPath = item.component;
      if (!componentPath) return null;
      container.innerHTML = DEFAULT_LOADING_HTML;
      try {
        const modulePath = __privateMethod(this, _SlotRenderer_instances, resolveComponentPath_fn).call(this, componentPath);
        const module = await import(modulePath);
        const Component = module.default || module;
        if (!Component || typeof Component.render !== "function") {
          throw new Error(`组件 [${componentPath}] 必须导出 render(container, props) 方法`);
        }
        const props = item.props || {};
        await Component.render(container, props);
        __privateMethod(this, _SlotRenderer_instances, log_fn).call(this, `组件 [${componentPath}] 渲染成功`);
        return Component;
      } catch (err) {
        __privateMethod(this, _SlotRenderer_instances, warn_fn).call(this, `加载组件 [${componentPath}] 失败`, err);
        container.innerHTML = DEFAULT_ERROR_HTML;
        return null;
      }
    };
    /**
     * 解析组件路径为可 import 的模块路径
     *
     * 约定规则：
     *  - plugin/shop/SalesWidget → /statics/plugins/shop/js/SalesWidget.js
     *  - @system/statCard → /statics/modules/widgets/statCard.js
     */
    resolveComponentPath_fn = function(componentPath) {
      if (componentPath.startsWith("plugin/")) {
        const parts = componentPath.split("/");
        const pluginIdent = parts[1];
        const widgetName = parts.slice(2).join("/");
        return `/statics/plugins/${pluginIdent}/js/${widgetName}.js`;
      }
      if (componentPath.startsWith("@system/")) {
        const name = componentPath.replace("@system/", "");
        return `/statics/modules/widgets/${name}.js`;
      }
      return componentPath;
    };
    /**
     * 创建子容器的包装元素
     */
    createItemContainer_fn = function(parent2, renderer, item) {
      const wrapper = document.createElement("div");
      wrapper.className = "slot-item";
      if (item.title) {
        const header = document.createElement("div");
        header.className = "slot-item-header";
        if (item.icon) {
          const iconEl = document.createElement("i");
          iconEl.className = `${item.icon} me-1`;
          header.appendChild(iconEl);
          header.appendChild(document.createTextNode(item.title));
        } else {
          header.textContent = item.title;
        }
        wrapper.appendChild(header);
      }
      const body = document.createElement("div");
      body.className = "slot-item-body";
      wrapper.appendChild(body);
      parent2.appendChild(wrapper);
      return body;
    };
    /**
     * 根据渲染模式设置容器的 CSS 类
     */
    applyRendererClass_fn = function(container, renderer) {
      const classMap = {
        "grid": "slot-grid",
        "stack": "slot-stack",
        "list": "slot-list",
        "tabs": "slot-tabs"
      };
      const cls = classMap[renderer] || "slot-stack";
      container.classList.add("slot-container", cls);
      container.style.display = "";
    };
    log_fn = function(msg, ...args) {
      if (window.Wise && window.Wise.logger) {
        window.Wise.logger.debug(`[Slot] ${msg}`, ...args);
      }
    };
    warn_fn = function(msg, ...args) {
      if (window.Wise && window.Wise.logger) {
        window.Wise.logger.warn(`[Slot] ${msg}`, ...args);
      }
    };
    slotRenderer = new SlotRenderer();
    wise_slot_default = slotRenderer;
  }
});

// public/statics/modules/ws/index.js
var ws_exports = {};
__export(ws_exports, {
  WsColorPicker: () => ws_color_picker_default,
  WsDatePicker: () => ws_date_picker_default,
  WsFormValidator: () => ws_form_validator_default,
  WsHintInput: () => ws_hint_input_default,
  WsLayer: () => ws_layer_default,
  WsLinkageSelect: () => ws_linkage_select_default,
  WsMarkdown: () => ws_markdown_default,
  WsPopMenu: () => ws_popmenu_default,
  WsSelect: () => ws_select_default,
  WsUpload: () => ws_upload_default,
  default: () => ws_default
});

// public/statics/libs/ws-layer/ws-layer.js
var $2 = window.jQuery;
if (typeof $2 === "undefined") {
  throw new Error("WsLayer requires jQuery");
}
var WsLayer = {
  // 版本号
  version: "1.0.0",
  // 默认配置
  DEFAULT_CONFIG: {
    frameName: "",
    // 框架名称
    type: 0,
    // 弹层类型: 0-对话框, 1-页面层, 2-iframe层, 3-loading加载层, 4-tips提示层
    title: "信息",
    // 标题
    content: "",
    // 内容，支持HTML 和 url ,iframe类型支持 [url, 'auto|no']
    skin: "",
    // 皮肤样式
    area: "auto",
    // 宽高 [width, height]
    offset: "auto",
    // 位置
    icon: -1,
    // 图标类型 -1: 无, 0: 信息, 1: 成功, 2: 警告, 3: 错误, 4: 问号, 5: 锁, 6: 哭脸, 7: 笑脸
    btn: ["确定"],
    // 按钮
    closeBtn: true,
    // 关闭按钮
    minBtn: false,
    // 最小化按钮
    maxBtn: false,
    // 最大化按钮
    shade: true,
    // 遮罩
    shadeClose: false,
    // 点击遮罩关闭
    time: 0,
    // 自动关闭时间(毫秒)
    id: null,
    // 弹层ID
    anim: true,
    // 动画 
    isOutAnim: true,
    // 退出动画
    fixed: true,
    // 固定定位
    resize: false,
    // 允许调整大小
    scrollbar: true,
    // 允许滚动条
    maxWidth: 860,
    // 最大宽度
    maxHeight: 0,
    // 最大高度
    zIndex: 1060,
    // 层级
    onlyOne: true,
    // 只能显示一个弹层
    minStack: true,
    // 最小化堆叠
    move: true,
    // 允许拖拽
    moveType: 1,
    // 拖拽类型
    moveOut: false,
    // 允许拖出窗口
    tips: 1,
    // 提示方向 1:上, 2:右, 3:下, 4:左
    // 事件回调
    success: null,
    // 创建成功回调
    yes: null,
    // 确定按钮回调
    cancel: null,
    // 取消按钮回调
    end: null,
    // 关闭后回调
    full: null,
    // 最大化回调
    restore: null,
    // 还原回调
    minEnd: null,
    // 最小化回调
    moveEnd: null,
    // 拖拽结束回调
    resizing: null,
    // 调整大小回调
    // 按钮配置
    btnAsync: false,
    // 异步按钮
    tipsMore: false,
    // 允许多个提示
    closeOnEsc: true,
    // ESC 键关闭
    submitOnEnter: true,
    // Enter 键提交
    submitOnEnterInInput: true,
    // 在输入框内也允许 Enter 提交
    loadingAnimation: "ws-layer-spin",
    // 默认加载动画
    // iframe 配置
    autoUpdateTitle: false,
    // 自动更新 iframe 标题
    iframeTitle: null,
    // iframe 标题回调函数
    iframeLoad: null,
    // iframe 加载完成回调
    iframeLoadError: null,
    // iframe 加载错误回调
    // loading 层配置
    loadingIconSize: "fs-4",
    // 加载图标大小
    loadingTextColor: "text-white"
    // 加载文字颜色
  },
  // 当前打开的弹层索引
  index: 0,
  // 共享数据存储
  sharedData: {},
  // 弹层实例存储
  instances: [],
  // 最小化堆叠数组
  minStackArr: [],
  // 国际化配置
  i18n: {
    "zh-CN": {
      confirm: "确定",
      cancel: "取消",
      defaultTitle: "信息",
      prompt: {
        InputLengthPrompt: "最多输入 {length} 个字符"
      },
      photos: {
        noData: "没有图片",
        tools: {
          rotate: "旋转",
          scaleX: "水平变换",
          zoomIn: "放大",
          zoomOut: "缩小",
          reset: "还原",
          close: "关闭"
        },
        viewPicture: "查看原图",
        urlError: {
          prompt: "当前图片地址异常，\n是否继续查看下一张？",
          confirm: "下一张",
          cancel: "不看了"
        }
      }
    }
  },
  // 当前语言
  lang: "zh-CN",
  /**
   * 初始化组件
   */
  init: function() {
    this.createContainer();
    this.injectStyles();
  },
  /**
   * 创建容器
   */
  createContainer: function() {
    if (!$2("#ws-layer-shade-container").length) {
      $2("body").append('<div id="ws-layer-shade-container"></div>');
    }
    if (!$2("#ws-layer-container").length) {
      $2("body").append('<div id="ws-layer-container"></div>');
    }
    if (!$2("#ws-toast-container").length) {
      $2("body").append('<div id="ws-toast-container" class="ws-toast-container"></div>');
    }
  },
  /**
   * 注入样式
   * 已改由 statics/modules/ws/ws-components.css 统一引入（见文件顶部说明）。
   * 保留空实现：内部存在 injectStyles() 调用点，直接删除会抛 ReferenceError。
   */
  injectStyles: function() {
  },
  /**
   * 顶层窗口链式调用函数
   * @param {number|string} [level] - 指定层级：
   *   不传：按原逻辑，在 iframe 中且顶层可访问则使用顶层，否则当前窗口；
   *   0 或 'self'：强制当前窗口；
   *   1 或 'parent'：强制父窗口；
   *   2 及以上：逐级向上访问。
   * @returns {Object} 链式调用对象
   */
  top: function(level) {
    const self = this;
    let targetWin = window;
    if (level === void 0) {
      if (self.isInIframe() && self.hasLayerInTopWindow()) {
        targetWin = window.top;
      }
    } else {
      let lvl = 0;
      if (typeof level === "string") {
        if (level.toLowerCase() === "self") {
          lvl = 0;
        } else if (level.toLowerCase() === "parent") {
          lvl = 1;
        } else {
          lvl = parseInt(level, 10) || 0;
        }
      } else {
        lvl = parseInt(level, 10) || 0;
      }
      while (lvl > 0 && targetWin !== targetWin.top) {
        try {
          targetWin = targetWin.parent;
          if (targetWin) {
            lvl--;
          } else {
            break;
          }
        } catch (e) {
          console.warn("访问父窗口失败（可能跨域）:", e);
          break;
        }
      }
    }
    const chain = {
      /**
       * 执行顶层窗口的方法
       * @param {String} method 方法名
       * @param {Array} args 参数数组
       * @returns {*} 方法返回值
       */
      execute: function(method, args) {
        try {
          if (targetWin.wsLayer && typeof targetWin.wsLayer[method] === "function") {
            return targetWin.wsLayer[method].apply(targetWin.wsLayer, args);
          }
        } catch (e) {
          console.error(`在目标窗口执行 ${method} 失败:`, e);
        }
        return self[method].apply(self, args);
      }
    };
    const publicMethods = [
      // 核心方法
      "open",
      "close",
      "closeAll",
      "closeLast",
      "min",
      "max",
      "restore",
      "title",
      // 快捷方法
      "iframe",
      "alert",
      "confirm",
      "msg",
      "load",
      "tips",
      "prompt",
      "tab",
      "photos",
      "toast",
      // 工具方法
      "getChildFrame",
      "getInstances",
      "setup",
      "setLang",
      "addMessages",
      // 共享数据操作方法
      "setShared",
      "getShared",
      "hasShared",
      "removeShared",
      "clearShared"
    ];
    publicMethods.forEach((method) => {
      if (typeof this[method] === "function") {
        chain[method] = function() {
          return chain.execute(method, Array.prototype.slice.call(arguments));
        };
      }
    });
    return chain;
  },
  /**
   * 判断当前页面是否在 iframe 中
   * @returns {Boolean}
   */
  isInIframe: function() {
    try {
      return window.self !== window.top;
    } catch (e) {
      return true;
    }
  },
  /**
   * 检查顶层页面是否有 layer 组件
   * @returns {Boolean}
   */
  hasLayerInTopWindow: function() {
    try {
      return !!(window.top && window.top.wsLayer);
    } catch (e) {
      return false;
    }
  },
  /**
   * 打开弹层
   * @param {LayerOptions} options 配置选项
   * @returns {Number} 弹层索引
   */
  open: function(options2) {
    const config2 = $2.extend(true, {}, this.DEFAULT_CONFIG, options2);
    const index = ++this.index;
    if (config2.onlyOne) {
      this.closeAll("dialog");
      this.closeAll("loading");
      this.closeAll("tips");
    }
    if (config2.type === 2) {
      window.name = config2.frameName || "ws-layer-window-" + index;
    }
    const instance = new LayerInstance(index, config2);
    this.instances[index] = instance;
    instance.create();
    return index;
  },
  /**
   * 关闭弹层
   * @param {Number} index 弹层索引
   * @param {String} exitType 退出类型 close|cancel
   */
  close: function(index, exitType = "close") {
    if (this.instances[index]) {
      this.instances[index].close(exitType);
      this.instances[index] = null;
    }
  },
  /**
   * 关闭所有弹层
   * @param {String} type 弹层类型
   */
  closeAll: function(type2) {
    let typeMap = {
      "dialog": 0,
      "page": 1,
      "iframe": 2,
      "loading": 3,
      "tips": 4
    };
    type2 = typeMap[type2] || type2;
    $2.each(this.instances, function(index, instance) {
      if (instance && (!type2 || instance.config.type == type2)) {
        instance.close();
      }
    });
    if (!type2) {
      this.instances = [];
      this.index = 0;
    }
  },
  /**
   * 关闭最近打开的弹层
   * @param {String} type 弹层类型
   */
  closeLast: function(type2) {
    let maxIndex = 0;
    let targetIndex = 0;
    $2.each(this.instances, function(index, instance) {
      if ((!type2 || instance && instance.config.type == type2) && index > maxIndex) {
        maxIndex = index;
        targetIndex = index;
      }
    });
    if (targetIndex > 0) {
      this.close(targetIndex);
    }
  },
  /**
   * 警告框
   * @param {String} content 内容
   * @param {LayerOptions|Function} options 配置选项或回调函数
   * @param {Function} [yes] 确定回调
   * @returns {Number} 弹层索引
   */
  alert: function(content, options2, yes) {
    const type2 = typeof options2 === "function";
    if (type2) {
      yes = options2;
      options2 = {};
    }
    return this.open($2.extend({
      content,
      btn: ["确定"],
      yes
    }, type2 ? {} : options2));
  },
  /**
   * 确认框
   * @param {String} content 内容
   * @param {LayerOptions|Function} options 配置选项或回调函数
   * @param {Function} [yes] 确定回调
   * @param {Function} [cancel] 取消回调
   * @returns {Number} 弹层索引
   */
  confirm: function(content, options2, yes, cancel) {
    const type2 = typeof options2 === "function";
    if (type2) {
      cancel = yes;
      yes = options2;
      options2 = {};
    }
    return this.open($2.extend({
      content,
      btn: ["确定", "取消"],
      yes,
      cancel
    }, type2 ? {} : options2));
  },
  /**
   * iframe弹层
   * @param {String} url iframe地址
   * @param {LayerOptions|Function} options 配置选项或成功回调函数
   * @param {Function} [yes] 确定回调
   * @param {Function} [cancel] 取消回调
   * @returns {Number} 弹层索引
   */
  iframe: function(url2, options2, yes, cancel) {
    const type2 = typeof options2 === "function";
    if (type2) {
      cancel = yes;
      yes = options2;
      options2 = {};
    }
    return this.open($2.extend({
      type: 2,
      content: [url2, "auto"],
      title: "弹层",
      area: ["800px", "600px"],
      yes,
      cancel
    }, type2 ? {} : options2));
  },
  /**
   * loading弹层
   * @param {LayerOptions} [options] 配置选项
   * @returns {Number} 弹层索引
   */
  loading: function(options2) {
    return this.open($2.extend({
      type: 3,
      content: "加载中...",
      icon: -1
    }, options2));
  },
  /**
   * 消息提示
   * @param {String} content 内容
   * @param {LayerOptions|Function} options 配置选项或回调函数
   * @param {Function} [end] 关闭回调
   * @returns {Number} 弹层索引
   */
  msg: function(content, options2, end) {
    const type2 = typeof options2 === "function";
    if (type2) {
      end = options2;
      options2 = {};
    }
    return this.open($2.extend({
      type: 4,
      content,
      time: 3e3,
      shade: false,
      title: false,
      closeBtn: false,
      btn: false,
      anim: false,
      end
    }, type2 ? {} : options2));
  },
  /**
   * 加载层
   * @param {Number|LayerOptions} [icon] 动画类型 0:默认旋转加载 1:沙漏加载 2:双环加载 3:脉冲加载 4:缩放旋转加载 5:弹跳加载；或直接传配置对象
   * @param {LayerOptions} [options] 配置选项
   * @returns {Number} 弹层索引
   */
  load: function(icon, options2) {
    let config2 = {};
    if (typeof icon === "object") {
      config2 = icon;
    } else {
      config2 = $2.extend({
        type: 3,
        icon: icon || 0,
        shade: 0.01,
        title: false,
        closeBtn: false
      }, options2);
    }
    return this.open(config2);
  },
  /**
   * 提示层
   * @param {String} content 内容
   * @param {String|Object} follow 跟随元素（选择器或 DOM 元素）
   * @param {LayerOptions} [options] 配置选项
   * @returns {Number} 弹层索引
   */
  tips: function(content, follow, options2) {
    return this.open($2.extend({
      type: 4,
      content: [content, follow],
      tips: 1,
      // 提示方向 1:上, 2:右, 3:下, 4:左
      time: 3e3,
      shade: false,
      title: false,
      closeBtn: false,
      fixed: false,
      anim: false
    }, options2));
  },
  /**
   * 输入框
   * @param {LayerOptions|Function} options 配置选项或确定回调
   * @param {Function} [yes] 确定回调
   * @returns {Number} 弹层索引
   */
  prompt: function(options2, yes) {
    if (typeof options2 === "function") {
      yes = options2;
      options2 = {};
    }
    var self = this;
    const escapeHtml = WsLayer.escapeHtml;
    const content = options2.formType === "textarea" ? `<textarea class="form-control" rows="4" placeholder="${escapeHtml(options2.placeholder || "")}">${escapeHtml(options2.value || "")}</textarea>` : `<input type="${escapeHtml(options2.formType || "text")}" class="form-control" placeholder="${escapeHtml(options2.placeholder || "")}" value="${escapeHtml(options2.value || "")}">`;
    return this.open($2.extend({
      type: 1,
      title: options2.title || "输入",
      content,
      btn: ["确定", "取消"],
      area: options2.area || ["400px", "auto"],
      success: function(layero) {
        const input = layero.find(".form-control");
        input.focus();
        self.handlePromptEvents(layero);
        if (options2.success) options2.success(layero);
      },
      yes: function(index, layero) {
        const value = layero.find(".form-control").val();
        if (yes) yes(value, index, layero);
      }
    }, options2));
  },
  /**
   * 输入框弹层的特殊处理
   */
  handlePromptEvents($layer) {
    const self = this;
    const $input = $layer.find(".form-control");
    $input.on("keydown", function(e) {
      if (e.keyCode === 13 && self.DEFAULT_CONFIG.submitOnEnter && self.DEFAULT_CONFIG.submitOnEnterInInput) {
        e.preventDefault();
        const $confirmBtn = $layer.find('[data-layer-btn="0"]');
        if ($confirmBtn.length && !$confirmBtn.prop("disabled")) {
          $confirmBtn.trigger("click");
        }
      }
    });
    setTimeout(() => {
      $input.focus();
      if ($input.is('input[type="text"], input[type="password"]')) {
        $input.select();
      }
    }, 100);
  },
  /**
   * 标签页弹层
   * @param {LayerOptions} [options] 配置选项
   * @returns {Number} 弹层索引
   */
  tab: function(options2) {
    options2 = options2 || {};
    const tab = options2.tab || [];
    let tabHeaders = "";
    let tabContents = "";
    const escapeHtml = WsLayer.escapeHtml;
    tab.forEach((item, index) => {
      const activeClass = index === 0 ? "active" : "";
      tabHeaders += `
                    <li class="nav-item">
                        <button class="nav-link ${activeClass}" data-bs-toggle="tab" 
                                data-bs-target="#tab-${index}" type="button">
                            ${escapeHtml(item.title)}
                        </button>
                    </li>
                `;
      tabContents += `
                    <div class="tab-pane fade ${activeClass ? "show active" : ""}" id="tab-${index}">
                        ${escapeHtml(item.content || "")}
                    </div>
                `;
    });
    const content = `
                <ul class="nav nav-tabs mb-3">${tabHeaders}</ul>
                <div class="tab-content">${tabContents}</div>
            `;
    return this.open($2.extend({
      type: 1,
      title: options2.title || "标签页",
      content,
      area: options2.area || ["600px", "400px"],
      success: function(layero) {
        const triggerTabList = layero.find('button[data-bs-toggle="tab"]');
        triggerTabList.on("click", function(event) {
          event.preventDefault();
          const target = $2(this).data("bs-target");
          layero.find(".tab-pane").removeClass("show active");
          layero.find(target).addClass("show active");
          triggerTabList.removeClass("active");
          $2(this).addClass("active");
        });
        if (options2.success) options2.success(layero);
      }
    }, options2));
  },
  /**
   * 图片浏览器
   * @param {LayerOptions} [options] 配置选项
   * @returns {Number} 弹层索引
   */
  photos: function(options2) {
    options2 = options2 || {};
    const photos = options2.photos || {};
    const data3 = photos.data || [];
    const start = photos.start || 0;
    if (data3.length === 0) {
      return this.msg(this.$t("photos.noData"));
    }
    const currentIndex = start;
    const currentPhoto = data3[currentIndex];
    const escapeHtml = WsLayer.escapeHtml;
    const content = `
                <div class="ws-layer-photos-main">
                    <img src="${escapeHtml(currentPhoto.src)}" alt="${escapeHtml(currentPhoto.alt || "")}" 
                         style="max-width: 100%; max-height: 100%;">
                </div>
                <div class="ws-layer-photos-toolbar ws-layer-photos-header">
                    <button class="btn" data-photos-action="rotate" title="${this.$t("photos.tools.rotate")}">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button class="btn" data-photos-action="prev" ${data3.length <= 1 ? "disabled" : ""}>
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="mx-2">${currentIndex + 1} / ${data3.length}</span>
                    <button class="btn" data-photos-action="next" ${data3.length <= 1 ? "disabled" : ""}>
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <button class="btn" data-photos-action="close" title="${this.$t("photos.tools.close")}">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="ws-layer-photos-toolbar ws-layer-photos-footer">
                    <div>${escapeHtml(currentPhoto.alt || "")}</div>
                    <a href="${escapeHtml(currentPhoto.src)}" target="_blank" class="btn btn-sm btn-link text-white">
                        ${this.$t("photos.viewPicture")}
                    </a>
                </div>
            `;
    const instanceIndex = this.open($2.extend({
      type: 1,
      skin: "ws-layer-photos",
      content,
      title: false,
      closeBtn: false,
      shade: true,
      shadeClose: true,
      success: function(layero, index, layerInstance) {
        const instance = layerInstance || WsLayer.instances[index];
        if (!instance) return;
        instance.photosData = {
          data: data3,
          currentIndex,
          rotate: 0
        };
        const $photosImg = layero.find("img");
        $photosImg.on("load", function() {
          instance._schedulePhotosFit();
        });
        instance._schedulePhotosFit();
        if ($photosImg.length && $photosImg[0].complete) {
          instance._schedulePhotosFit();
        }
        const onViewportChange = function() {
          instance._schedulePhotosFit();
        };
        $2(window).on("resize.layerPhotos", onViewportChange);
        layero.find("[data-photos-action]").on("click", function() {
          const action3 = $2(this).data("photos-action");
          instance.handlePhotosAction(action3);
        });
        $2(document).on("keyup.layerPhotos", function(e) {
          if (e.keyCode === 27) {
            WsLayer.close(index);
          } else if (e.keyCode === 37) {
            instance.handlePhotosAction("prev");
          } else if (e.keyCode === 39) {
            instance.handlePhotosAction("next");
          }
        });
        if (options2.success) options2.success(layero);
      },
      end: function() {
        $2(document).off("keyup.layerPhotos");
        $2(window).off("resize.layerPhotos");
        const inst = WsLayer.instances[instanceIndex];
        if (inst && inst._stopPhotosAutoFit) {
          inst._stopPhotosAutoFit();
          inst._stopPhotosAutoFit = null;
        }
      }
    }, options2));
    return instanceIndex;
  },
  /**
   * Toast 通知
   * @param {Object|string} options 配置选项或提示内容字符串
   * @returns {String} Toast 实例 ID
   */
  toast: function(options2) {
    if (typeof options2 === "string") {
      options2 = { content: options2 };
    }
    const config2 = $2.extend({
      content: "",
      type: "info",
      // info, success, warning, error
      time: 3e3,
      position: "top-end"
    }, options2);
    const toastId = "toast-" + Date.now();
    const iconClass = this.getToastIconClass(config2.type);
    const bgClass = this.getToastBgClass(config2.type);
    const escapeHtml = WsLayer.escapeHtml;
    const toastHtml = `
                <div id="${toastId}" class="ws-toast toast show" role="alert">
                    <div class="toast-header ${bgClass} text-white">
                        <i class="bi ${iconClass} me-2"></i>
                        <strong class="me-auto">${escapeHtml(this.getToastTitle(config2.type))}</strong>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">
                        ${escapeHtml(config2.content)}
                    </div>
                </div>
            `;
    $2("#ws-toast-container").append(toastHtml);
    let ended = false;
    const tryEnd = function() {
      if (!ended && typeof config2.end === "function") {
        ended = true;
        config2.end();
      }
    };
    if (config2.time > 0) {
      setTimeout(function() {
        $2(`#${toastId}`).remove();
        tryEnd();
      }, config2.time);
    }
    $2(`#${toastId} .btn-close`).on("click", function() {
      $2(`#${toastId}`).remove();
      tryEnd();
    });
    return toastId;
  },
  /**
   * 最小化弹层
   * @param {Number} index 弹层索引
   */
  min: function(index) {
    if (this.instances[index]) {
      this.instances[index].minimize();
    }
  },
  /**
   * 最大化弹层
   * @param {Number} index 弹层索引
   */
  max: function(index) {
    if (this.instances[index]) {
      this.instances[index].maximize();
    }
  },
  /**
   * 还原弹层
   * @param {Number} index 弹层索引
   */
  restore: function(index) {
    if (this.instances[index]) {
      this.instances[index].restore();
    }
  },
  /**
   * 动态设置标题
   * @param {String} title 标题
   * @param {Number} index 弹层索引
   */
  title: function(title, index) {
    if (this.instances[index]) {
      this.instances[index].setTitle(title);
    }
  },
  /**
   * 获取子iframe的DOM
   * @param {String} selector 选择器
   * @param {Number} index 弹层索引
   */
  getChildFrame: function(selector, index) {
    if (this.instances[index] && this.instances[index].config.type === 2) {
      return $2(`#ws-layer-iframe-${index}`).contents().find(selector);
    }
    return $2();
  },
  /**
   * 获取 Toast 图标类名
   * @param {String} type 图标类型
   * @returns {String} 图标类名
   */
  getToastIconClass: function(type2) {
    const iconMap = {
      info: "bi-info-circle",
      success: "bi-check-circle",
      warning: "bi-exclamation-triangle",
      error: "bi-x-circle",
      danger: "bi-x-circle"
    };
    return iconMap[type2] || "bi-info-circle";
  },
  /**
   * 获取 Toast 背景类名
   * @param {String} type 类型
   * @returns {String} 背景类名
   */
  getToastBgClass: function(type2) {
    const bgMap = {
      info: "bg-info",
      success: "bg-success",
      warning: "bg-warning",
      error: "bg-danger",
      danger: "bg-danger"
    };
    return bgMap[type2] || "bg-info";
  },
  /**
   * 获取 Toast 标题
   * @param {String} type 类型
   * @returns {String} 标题
   */
  getToastTitle: function(type2) {
    const titleMap = {
      info: "信息",
      success: "成功",
      warning: "警告",
      error: "错误",
      danger: "错误"
    };
    return titleMap[type2] || "信息";
  },
  /**
           * 获取iframe层的索引
           * @param {String} windowName 窗口名称，通常是 window.name
           * @returns {Number} 弹层索引，未找到返回 -1
           */
  getFrameIndex: function(windowName) {
    if (!windowName) {
      console.warn("getFrameIndex: windowName 参数不能为空");
      return -1;
    }
    for (const index in this.instances) {
      const instance = this.instances[index];
      if (instance && instance.config.type === 2) {
        try {
          const iframe = document.getElementById(`ws-layer-iframe-${index}`);
          if (iframe && iframe.contentWindow) {
            if (iframe.contentWindow.name === windowName) {
              return parseInt(index);
            }
          }
        } catch (error) {
          console.warn("跨域iframe，无法访问内容:", error.message);
          continue;
        }
      }
    }
    console.warn(`getFrameIndex: 未找到匹配的iframe弹层，windowName: ${windowName}`);
    return -1;
  },
  /**
   * 获取当前iframe的父层索引（在iframe内部调用）
   * @returns {Number} 父层索引，未找到返回 -1
   */
  getParentFrameIndex: function() {
    if (window === window.top) {
      console.warn("getParentFrameIndex: 当前窗口不是iframe");
      return -1;
    }
    try {
      if (!parent.document) {
        console.warn("getParentFrameIndex: 无法访问父窗口文档（可能跨域）");
        return -1;
      }
      const iframes = parent.document.getElementsByTagName("iframe");
      let currentIframe = null;
      for (let i = 0; i < iframes.length; i++) {
        try {
          if (iframes[i].contentWindow === window) {
            currentIframe = iframes[i];
            break;
          }
        } catch (error) {
          console.warn("跨域iframe，无法访问内容:", error.message);
          continue;
        }
      }
      if (currentIframe) {
        const id = currentIframe.id;
        const match = id.match(/ws-layer-iframe-(\d+)/);
        if (match && match[1]) {
          return parseInt(match[1]);
        }
      }
    } catch (error) {
      console.error("getParentFrameIndex: 获取父层索引失败", error);
    }
    return -1;
  },
  /**
   * 关闭当前iframe所在的弹层（在iframe内部调用）
   * @param {Object} data 关闭时传递的数据（可选）
   */
  closeFrame: function(data3) {
    const frameIndex = this.getParentFrameIndex();
    if (frameIndex !== -1) {
      if (data3 !== void 0 && this.instances[frameIndex]) {
        this.instances[frameIndex].closeData = data3;
      }
      this.close(frameIndex);
    } else {
      console.warn("closeFrame: 无法找到当前iframe对应的弹层");
    }
  },
  /**
   * 从iframe内部调用父窗口的方法
   * @param {String} method 方法名
   * @param {...any} args 参数
   */
  callParent: function(method, ...args) {
    try {
      if (window.parent && window.parent.wsLayer) {
        return window.parent.wsLayer[method](...args);
      } else {
        console.error("callParent: 父窗口未找到wsLayer对象");
      }
    } catch (error) {
      console.error("callParent: 调用父窗口方法失败", error);
    }
  },
  /**
   * 国际化翻译
   * @param {String} key 翻译键
   * @param {Object} options 替换参数
   * @returns {String} 翻译后的文本
   */
  $t: function(key, options2) {
    const keys = key.split(".");
    let result = this.i18n[this.lang];
    for (let i = 0; i < keys.length; i++) {
      if (result === void 0 || result === null) {
        return key;
      }
      if (typeof result !== "object") {
        return result || key;
      }
      result = result[keys[i]];
    }
    if (result === void 0 || result === null) {
      return key;
    }
    if (typeof result === "string" && options2) {
      return result.replace(/\{(\w+)\}/g, function(match, k) {
        return options2[k] !== void 0 ? options2[k] : match;
      });
    }
    return result || key;
  },
  /**
   * 设置语言
   * @param {String} lang 语言代码
   */
  setLang: function(lang) {
    if (this.i18n[lang]) {
      this.lang = lang;
    }
  },
  /**
   * 添加语言包
   * @param {String} lang 语言代码
   * @param {Object} messages 语言消息
   */
  addMessages: function(lang, messages) {
    this.i18n[lang] = $2.extend(true, this.i18n[lang] || {}, messages);
  },
  /**
   * 设置全局配置
   * @param {Object} options 配置选项
   */
  config: function(options2) {
    $2.extend(true, this.DEFAULT_CONFIG, options2);
  },
  /**
   * 获取当前所有弹层
   * @returns {Object} 弹层实例对象
   */
  getInstances: function() {
    return this.instances;
  },
  /**
   * 设置共享数据
   * @param {String|Object} key 键名或键值对象
   * @param {*} value 值（当key为字符串时）
   * @returns {Object} 返回当前实例，支持链式调用
   */
  setShared: function(key, value) {
    if (typeof key === "object" && key !== null) {
      $2.extend(this.sharedData, key);
    } else if (typeof key === "string") {
      this.sharedData[key] = value;
    }
    return this;
  },
  /**
   * 获取共享数据
   * @param {String} key 键名，不传则返回所有数据的浅拷贝
   * @returns {*} 对应的值，或所有数据的浅拷贝
   */
  getShared: function(key) {
    if (key === void 0) {
      return $2.extend({}, this.sharedData);
    }
    return this.sharedData[key];
  },
  /**
   * 检查共享数据是否存在
   * @param {String} key 键名
   * @returns {Boolean}
   */
  hasShared: function(key) {
    return this.sharedData.hasOwnProperty(key);
  },
  /**
   * 删除共享数据
   * @param {String} key 键名
   * @returns {Object} 返回当前实例
   */
  removeShared: function(key) {
    delete this.sharedData[key];
    return this;
  },
  /**
   * 清空所有共享数据
   * @returns {Object} 返回当前实例
   */
  clearShared: function() {
    for (let key in this.sharedData) {
      if (this.sharedData.hasOwnProperty(key)) {
        delete this.sharedData[key];
      }
    }
    return this;
  },
  /**
   * HTML 转义，防止 XSS 注入
   * @param {String} str 待转义字符串
   * @returns {String} 转义后的安全字符串
   */
  escapeHtml: function(str) {
    if (typeof str !== "string") return "";
    const div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
};
var LayerInstance = class {
  constructor(index, config2) {
    this.index = index;
    this.config = config2;
    this.isMinimized = false;
    this.isMaximized = false;
    this._normalStyle = null;
    this._stateBeforeMin = null;
  }
  /**
   * 创建弹层
   */
  create() {
    this.createShade();
    this.createLayer();
    this.bindEvents();
    this.autoClose();
    if (this.config.success) {
      this.config.success(this.$layer, this.index, this);
    }
  }
  /**
   * 创建遮罩
   */
  createShade() {
    if (this.config.shade) {
      const shadeHtml = `
                    <div class="ws-layer-shade" id="ws-layer-shade-${this.index}"></div>
                `;
      $2("#ws-layer-shade-container").append(shadeHtml);
      this.$shade = $2(`#ws-layer-shade-${this.index}`);
      this.$shade.show();
    }
  }
  /**
   * 创建弹层主体
   */
  createLayer() {
    const config2 = this.config;
    const layerHtml = this.getLayerHtml();
    $2("#ws-layer-container").append(layerHtml);
    this.$layer = $2(`#ws-layer-${this.index}`);
    this.$layer.css({
      "visibility": "hidden",
      "display": "flex"
    });
    this.setSize();
    this.setPosition();
    this.bindKeyboardEvents();
    if (config2.type === 4) {
      this.showTips();
    }
    if (config2.type === 2) {
      this.bindIframeEvents();
    }
    this.$layer.css("visibility", "visible").addClass("ws-layer-fade-in");
    this.autoFocusDefaultButton();
  }
  /*
  * 自动聚焦到默认按钮
  */
  autoFocusDefaultButton() {
    const config2 = this.config;
    if (config2.type === 3 || config2.type === 4) return;
    const $firstBtn = this.$layer.find('[data-layer-btn="0"]');
    if ($firstBtn.length) {
      setTimeout(() => {
        $firstBtn.focus();
      }, 100);
    }
  }
  /**
   * 获取弹层 HTML
   * @returns {String} HTML 字符串
   */
  getLayerHtml() {
    const config2 = this.config;
    let html = "";
    switch (config2.type) {
      case 0:
      // 对话框
      case 1:
        html = this.getDialogHtml();
        break;
      case 2:
        html = this.getIframeHtml();
        break;
      case 3:
        html = this.getLoadingHtml();
        break;
      case 4:
        html = this.getTipsHtml();
        break;
    }
    return html;
  }
  /**
   * 获取对话框 HTML
   * @returns {String} HTML 字符串
   */
  getDialogHtml() {
    const config2 = this.config;
    const iconHtml = config2.icon !== -1 ? this.getDialogIconHtml() : "";
    const closeBtnHtml = config2.closeBtn ? '<button type="button" class="btn btn-close" data-layer-close></button>' : "";
    const btnHtml = config2.btn ? this.getBtnHtml() : "";
    const resizeHtml = config2.resize ? '<div class="ws-layer-resize"></div>' : "";
    return `
                <div class="ws-layer ws-layer-dialog ${config2.skin}" id="ws-layer-${this.index}" tabindex="-1">
                    <div class="ws-layer-header">
                        <h5 class="mb-0">${iconHtml}${config2.title}</h5>
                        <div>
                            ${config2.minBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-min><i class="bi bi-dash"></i></button>' : ""}
                            ${config2.maxBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-max><i class="bi bi-square"></i></button>' : ""}
                            ${closeBtnHtml}
                        </div>
                    </div>
                    <div class="ws-layer-body">
                        ${config2.content}
                    </div>
                    ${btnHtml}
                    ${resizeHtml}
                </div>
            `;
  }
  /**
   * 获取iframe层 HTML
   * @returns {String} HTML 字符串
   */
  getIframeHtml() {
    const config2 = this.config;
    const content = typeof config2.content === "string" ? [config2.content, "auto"] : config2.content;
    const closeBtnHtml = config2.closeBtn ? '<button type="button" class="btn-close" data-layer-close></button>' : "";
    const btnHtml = config2.btn ? this.getBtnHtml() : "";
    const resizeHtml = config2.resize ? '<div class="ws-layer-resize"></div>' : "";
    return `
                <div class="ws-layer ws-layer-iframe border shadow ${config2.skin}" id="ws-layer-${this.index}">
                    <div class="ws-layer-header">
                        <h5 class="mb-0">${config2.title}</h5>
                        <div>
                            ${config2.minBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-min><i class="bi bi-dash"></i></button>' : ""}
                            ${config2.maxBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-max><i class="bi bi-square"></i></button>' : ""}
                            ${closeBtnHtml}
                        </div>
                    </div>
                    <div class="ws-layer-body p-0">
                        <iframe id="ws-layer-iframe-${this.index}" 
                                name="ws-layer-iframe-${this.index}"
                                src="${content[0]}" 
                                frameborder="0" 
                                style="width:100%;height:100%;"
                                scrolling="${content[1] || "auto"}">
                        </iframe>
                    </div>
                    ${btnHtml}
                    ${resizeHtml}
                </div>
            `;
  }
  /**
   * 获取加载层 HTML
   * @returns {String} HTML 字符串
   */
  getLoadingHtml() {
    const config2 = this.config;
    let iconHtml = "";
    let animationClass = "";
    switch (config2.icon) {
      case 0:
        iconHtml = `<i class="bi bi-arrow-clockwise ws-layer-loading-icon ${config2.loadingAnimation || "ws-layer-spin"}"></i>`;
        animationClass = "ws-layer-spin";
        break;
      case 1:
        iconHtml = `<i class="bi bi-hourglass-split ws-layer-loading-icon"></i>`;
        animationClass = "ws-layer-breathe";
        break;
      case 2:
        return `
                        <div class="ws-layer ws-layer-loading" id="ws-layer-${this.index}">
                            <div class="ws-layer-body">
                                <div class="ws-layer-loading-2"></div>
                                <div class="ws-layer-loading-text">${config2.content || "加载中..."}</div>
                            </div>
                        </div>
                    `;
      case 3:
        iconHtml = `<i class="bi bi-circle ws-layer-loading-icon"></i>`;
        animationClass = "ws-layer-pulse";
        break;
      case 4:
        iconHtml = `<i class="bi bi-arrow-repeat ws-layer-loading-icon"></i>`;
        animationClass = "ws-layer-scale-rotate";
        break;
      case 5:
        iconHtml = `<i class="bi bi-dot ws-layer-loading-icon"></i>`;
        animationClass = "ws-layer-bounce";
        break;
      default:
        iconHtml = `<i class="bi bi-arrow-clockwise ws-layer-loading-icon"></i>`;
        animationClass = "ws-layer-spin";
    }
    if (animationClass) {
      iconHtml = iconHtml.replace(
        "ws-layer-loading-icon",
        `ws-layer-loading-icon ${animationClass}`
      );
    }
    if (config2.loadingIconSize) {
      iconHtml = iconHtml.replace(
        "ws-layer-loading-icon",
        `ws-layer-loading-icon  ${config2.loadingTextColor} ${config2.loadingIconSize}`
      );
    }
    return `
                <div class="ws-layer ws-layer-loading" id="ws-layer-${this.index}">
                    <div class="ws-layer-body">
                        ${iconHtml}
                        <div class="ws-layer-loading-text ${config2.loadingTextColor}">${config2.content || "加载中..."}</div>
                    </div>
                </div>
            `;
  }
  /**
   * 获取提示层 HTML
   * @returns {String} HTML 字符串
   */
  getTipsHtml() {
    const config2 = this.config;
    const content = Array.isArray(config2.content) ? config2.content[0] : config2.content;
    return `
                <div class="ws-layer ws-layer-tips" id="ws-layer-${this.index}">
                    <div class="ws-layer-body">
                        ${content}
                    </div>
                    <div class="ws-layer-tips-arrow"></div>
                </div>
            `;
  }
  /**
   * 提示层位置计算和显示
   */
  showTips() {
    const config2 = this.config;
    const follow = config2.follow || (Array.isArray(config2.content) ? config2.content[1] : null);
    if (!follow) return;
    const $follow = $2(follow);
    if (!$follow.length) {
      if (window.console && console.warn) {
        console.warn("[WsLayer] tips: 跟随元素不存在，已按默认位置显示");
      }
      return;
    }
    const followRect = $follow[0].getBoundingClientRect();
    const layerWidth = this.$layer.outerWidth();
    const layerHeight = this.$layer.outerHeight();
    const arrowSize = 10;
    const windowWidth = $2(window).width();
    const windowHeight = $2(window).height();
    const scrollTop = $2(window).scrollTop();
    const scrollLeft = $2(window).scrollLeft();
    const space = {
      top: followRect.top - layerHeight - arrowSize,
      right: windowWidth - followRect.right - layerWidth - arrowSize,
      bottom: windowHeight - followRect.bottom - layerHeight - arrowSize,
      left: followRect.left - layerWidth - arrowSize
    };
    let direction = config2.tips || 1;
    if (typeof direction === "number") {
      const directionMap = { 1: "top", 2: "right", 3: "bottom", 4: "left" };
      const dirKey = directionMap[direction];
      if (space[dirKey] < 20) {
        const bestDirection = Object.keys(space).reduce(
          (a, b) => space[a] > space[b] ? a : b
        );
        direction = { top: 1, right: 2, bottom: 3, left: 4 }[bestDirection];
      }
    }
    let top, left, arrowPosition, arrowClass;
    switch (direction) {
      case 1:
        top = followRect.top + scrollTop - layerHeight - arrowSize;
        left = followRect.left + scrollLeft + (followRect.width - layerWidth) / 2;
        arrowClass = "bottom";
        arrowPosition = {
          top: "100%",
          left: "50%",
          marginLeft: -arrowSize + "px"
        };
        break;
      case 2:
        top = followRect.top + scrollTop + (followRect.height - layerHeight) / 2;
        left = followRect.right + scrollLeft + arrowSize;
        arrowClass = "left";
        arrowPosition = {
          top: "50%",
          left: -arrowSize * 2 + "px",
          marginTop: -arrowSize + "px"
        };
        break;
      case 3:
        top = followRect.bottom + scrollTop + arrowSize;
        left = followRect.left + scrollLeft + (followRect.width - layerWidth) / 2;
        arrowClass = "top";
        arrowPosition = {
          top: -arrowSize + "px",
          left: "50%",
          marginLeft: -arrowSize + "px"
        };
        break;
      case 4:
        top = followRect.top + scrollTop + (followRect.height - layerHeight) / 2;
        left = followRect.left + scrollLeft - layerWidth - arrowSize;
        arrowClass = "right";
        arrowPosition = {
          top: "50%",
          left: "100%",
          marginTop: -arrowSize + "px"
        };
        break;
    }
    if (!config2.fixed) {
      top = Math.max(10, Math.min(top, scrollTop + windowHeight - layerHeight - 10));
      left = Math.max(10, Math.min(left, scrollLeft + windowWidth - layerWidth - 10));
    } else {
      top = Math.max(10, Math.min(top, windowHeight - layerHeight - 10));
      left = Math.max(10, Math.min(left, windowWidth - layerWidth - 10));
    }
    this.$layer.css({
      top: top + "px",
      left: left + "px"
    });
    const $arrow = this.$layer.find(".ws-layer-tips-arrow");
    $arrow.attr("class", `ws-layer-tips-arrow ${arrowClass}`);
    $arrow.css(Object.assign({
      position: "absolute",
      width: 0,
      height: 0,
      borderStyle: "solid"
    }, arrowPosition, this.getArrowBorder(direction)));
  }
  /**
   * 获取小三角边框样式
   * @param {Number} direction 方向
   * @returns {Object} 边框样式
   */
  getArrowBorder(direction) {
    const borderColor = "rgba(0, 0, 0, 0.8)";
    const transparent = "transparent";
    const arrowSize = 10;
    const styles = {
      1: {
        // 上方
        borderWidth: `${arrowSize}px ${arrowSize}px 0 ${arrowSize}px`,
        borderColor: `${borderColor} ${transparent} ${transparent} ${transparent}`
      },
      2: {
        // 右侧
        borderWidth: `${arrowSize}px 0 ${arrowSize}px ${arrowSize}px`,
        borderColor: `${transparent} ${transparent} ${transparent} ${borderColor}`
      },
      3: {
        // 下方
        borderWidth: `0 ${arrowSize}px ${arrowSize}px ${arrowSize}px`,
        borderColor: `${transparent} ${transparent} ${borderColor} ${transparent}`
      },
      4: {
        // 左侧
        borderWidth: `${arrowSize}px ${arrowSize}px ${arrowSize}px 0`,
        borderColor: `${transparent} ${borderColor} ${transparent} ${transparent}`
      }
    };
    return styles[direction] || styles[1];
  }
  /**
   * 获取图标 HTML
   * @returns {String} HTML 字符串
   */
  getDialogIconHtml() {
    const config2 = this.config;
    const iconMap = {
      "info": "bi-info-circle text-info",
      "success": "bi-check-circle text-success",
      "warning": "bi-exclamation-triangle text-warning",
      "danger": "bi-x-circle text-danger",
      "primary": "bi-question-circle text-primary",
      "lock": "bi-lock text-secondary",
      "frown": "bi-emoji-frown text-warning",
      "smile": "bi-emoji-smile text-success"
    };
    const iconClass = typeof config2.icon === "string" && config2.icon in iconMap ? iconMap[config2.icon] : typeof config2.icon === "number" ? Object.values(iconMap)[config2.icon] : "bi-info-circle text-info";
    return `<i class="${iconClass} ws-layer-icon"></i>`;
  }
  /**
   * 获取按钮 HTML
   * @returns {String} HTML 字符串
   */
  getBtnHtml() {
    const config2 = this.config;
    let btnHtml = '<div class="ws-layer-footer">';
    if (typeof config2.btn === "string") {
      config2.btn = [config2.btn];
    }
    config2.btn.forEach((text, index) => {
      const btnClass = index === 0 ? "btn-primary" : "btn-outline-secondary";
      btnHtml += `<button type="button" class="btn ${btnClass} me-2" data-layer-btn="${index}">${text}</button>`;
    });
    btnHtml += "</div>";
    return btnHtml;
  }
  /**
   * 设置弹层位置
   */
  setPosition() {
    const config2 = this.config;
    const windowWidth = $2(window).width();
    const windowHeight = $2(window).height();
    const prevVisibility = this.$layer.css("visibility");
    this.$layer.css({
      "visibility": "hidden",
      "display": "flex"
    });
    const layerWidth = this.$layer.outerWidth();
    const layerHeight = this.$layer.outerHeight();
    let top, left;
    if (config2.offset === "auto") {
      top = (windowHeight - layerHeight) / 2;
      left = (windowWidth - layerWidth) / 2;
    } else if (typeof config2.offset === "object") {
      top = config2.offset[0];
      left = config2.offset[1] || (windowWidth - layerWidth) / 2;
      if (typeof top === "string" && top.includes("%")) {
        top = windowHeight * parseInt(top) / 100;
      }
      if (typeof left === "string" && left.includes("%")) {
        left = windowWidth * parseInt(left) / 100;
      }
    } else {
      const offsetMap = {
        "t": 0,
        // 上
        "r": [0, windowWidth - layerWidth],
        // 右
        "b": [windowHeight - layerHeight, 0],
        // 下
        "l": [0, 0],
        // 左
        "lt": [0, 0],
        // 左上
        "lb": [windowHeight - layerHeight, 0],
        // 左下
        "rt": [0, windowWidth - layerWidth],
        // 右上
        "rb": [windowHeight - layerHeight, windowWidth - layerWidth],
        // 右下
        "c": [(windowHeight - layerHeight) / 2, (windowWidth - layerWidth) / 2]
        // 居中
      };
      const position = offsetMap[config2.offset] || [(windowHeight - layerHeight) / 2, (windowWidth - layerWidth) / 2];
      top = Array.isArray(position) ? position[0] : position;
      left = Array.isArray(position) ? position[1] : (windowWidth - layerWidth) / 2;
    }
    top = Math.max(0, Math.min(top, windowHeight - layerHeight - 10));
    left = Math.max(0, Math.min(left, windowWidth - layerWidth - 10));
    if (!config2.fixed) {
      top += $2(window).scrollTop();
      left += $2(window).scrollLeft();
    }
    this.$layer.css({
      top: top + "px",
      left: left + "px"
    });
    this.$layer.css("visibility", prevVisibility);
  }
  /**
   * 设置弹层尺寸
   */
  setSize() {
    const config2 = this.config;
    const windowWidth = $2(window).width();
    const windowHeight = $2(window).height();
    if (config2.area && config2.area !== "auto") {
      let width, height;
      if (typeof config2.area === "string") {
        width = config2.area;
        height = "auto";
      } else if (Array.isArray(config2.area)) {
        width = config2.area[0];
        height = config2.area[1];
      }
      if (width === "auto" || typeof width === "string" && width.includes("%")) {
        const maxWidth = config2.maxWidth || windowWidth - 40;
        if (typeof width === "string" && width.includes("%")) {
          width = Math.min(windowWidth * parseInt(width) / 100, maxWidth);
        } else {
          width = Math.min(this.$layer.width(), maxWidth);
        }
        width = width + "px";
      }
      if (height === "auto" || typeof height === "string" && height.includes("%")) {
        const maxHeight = config2.maxHeight || windowHeight - 40;
        if (typeof height === "string" && height.includes("%")) {
          height = Math.min(windowHeight * parseInt(height) / 100, maxHeight);
        } else {
          height = Math.min(this.$layer.height(), maxHeight);
        }
        height = height + "px";
      }
      this.$layer.css({
        width,
        height
      });
    }
    if (config2.maxWidth) {
      const currentWidth = this.$layer.outerWidth();
      if (currentWidth > config2.maxWidth) {
        this.$layer.css("width", config2.maxWidth + "px");
      }
    }
    if (config2.maxHeight) {
      const currentHeight = this.$layer.outerHeight();
      if (currentHeight > config2.maxHeight) {
        this.$layer.css("height", config2.maxHeight + "px");
      }
    }
  }
  /**
   * 绑定事件
   */
  bindEvents() {
    this.bindCloseEvent();
    this.bindBtnEvents();
    this.bindShadeClose();
    this.bindDragEvents();
    this.bindResizeEvents();
    this.bindMinMaxEvents();
  }
  /**
   * 绑定iframe事件
   */
  bindIframeEvents() {
    const self = this;
    const $iframe = this.$layer.find("iframe");
    const iframeId = `ws-layer-iframe-${this.index}`;
    $iframe.on("load", function() {
      let loadSuccess = true;
      try {
        const iframeDoc = this.contentDocument || this.contentWindow.document;
        const iframeTitle = iframeDoc.title;
        if (iframeTitle && self.config.iframeTitle) {
          const newTitle = self.config.iframeTitle(iframeTitle, self.index, self.$layer, self);
          if (typeof newTitle === "string") {
            self.setTitle(newTitle);
          }
        } else if (iframeTitle && self.config.autoUpdateTitle !== false) {
          self.setTitle(iframeTitle);
        }
      } catch (e) {
        console.warn("无法访问 iframe 内容，可能是跨域限制:", e.message);
        loadSuccess = false;
        if (self.config.iframeLoadError) {
          self.config.iframeLoadError(e, self.index, self.$layer, self);
        }
      }
      if (loadSuccess && self.config.iframeLoad) {
        self.config.iframeLoad(self.index, self.$layer, self);
      }
    });
    $iframe.on("error", function(e) {
      console.error("iframe 加载错误:", e);
      if (self.config.iframeLoadError) {
        self.config.iframeLoadError(e, self.index, self.$layer, self);
      }
    });
  }
  /**
   * 绑定关闭事件
   */
  bindCloseEvent() {
    const self = this;
    this.$layer.find("[data-layer-close]").on("click", function() {
      if (self.config.cancel) {
        self.config.cancel(self.index, self.$layer, self);
      }
      self.close("cancel");
    });
  }
  /**
   * 绑定按钮事件
   */
  bindBtnEvents() {
    const self = this;
    this.$layer.find("[data-layer-btn]").on("click", function() {
      const btnIndex = $2(this).data("layer-btn");
      if (self.config.btnAsync) {
        const btnCallback = btnIndex === 0 ? self.config.yes : self.config["btn" + (btnIndex + 1)];
        if (btnCallback) {
          const result = btnCallback(self.index, self.$layer, self);
          if (result && typeof result.then === "function") {
            const $btn = $2(this);
            $btn.prop("disabled", true).prepend('<span class="spinner-border spinner-border-sm me-2"></span>');
            result.then(function(res) {
              if (res !== false) {
                self.close();
              }
            }).finally(function() {
              $btn.prop("disabled", false).find(".spinner-border").remove();
            });
          } else if (result !== false) {
            self.close();
          }
        } else {
          self.close();
        }
      } else {
        if (btnIndex === 0) {
          if (self.config.yes) {
            const close = self.config.yes(self.index, self.$layer, self);
            if (close === true) {
              self.close();
            }
          } else {
            self.close();
          }
        } else {
          if (self.config["btn" + (btnIndex + 1)]) {
            const close = self.config["btn" + (btnIndex + 1)](self.index, self.$layer, self);
            if (close === true) {
              self.close();
            }
          } else {
            self.close();
          }
        }
      }
    });
  }
  /**
   * 绑定遮罩关闭事件
   */
  bindShadeClose() {
    const self = this;
    if (this.config.shadeClose && this.$shade) {
      this.$shade.on("click", function() {
        self.close();
      });
    }
  }
  /**
   * 绑定拖拽事件
   */
  bindDragEvents() {
    if (!this.config.move) return;
    const self = this;
    const $header = this.$layer.find(".ws-layer-header");
    let isDragging = false;
    let startX, startY, initialX, initialY;
    $header.addClass("ws-layer-draggable");
    $header.on("mousedown", function(e) {
      if (self.isMaximized) return;
      isDragging = true;
      self._minDragMoved = false;
      startX = e.clientX;
      startY = e.clientY;
      initialX = parseInt(self.$layer.css("left"));
      initialY = parseInt(self.$layer.css("top"));
      $2("body").css("cursor", "move");
      e.preventDefault();
    });
    $2(document).off("mousemove.layerDrag mouseup.layerDrag");
    $2(document).on("mousemove.layerDrag", function(e) {
      if (!isDragging) return;
      const deltaX = e.clientX - startX;
      const deltaY = e.clientY - startY;
      if (Math.abs(deltaX) > 4 || Math.abs(deltaY) > 4) {
        self._minDragMoved = true;
      }
      let newX = initialX + deltaX;
      let newY = initialY + deltaY;
      if (!self.config.moveOut) {
        const windowWidth = $2(window).width();
        const windowHeight = $2(window).height();
        const layerWidth = self.$layer.outerWidth();
        const layerHeight = self.$layer.outerHeight();
        newX = Math.max(0, Math.min(newX, windowWidth - layerWidth));
        newY = Math.max(0, Math.min(newY, windowHeight - layerHeight));
      }
      self.$layer.css({
        left: newX + "px",
        top: newY + "px",
        transform: "none"
      });
    });
    $2(document).on("mouseup.layerDrag", function() {
      if (isDragging) {
        isDragging = false;
        $2("body").css("cursor", "");
        if (self.config.moveEnd) {
          self.config.moveEnd(self.$layer, self);
        }
        if (self.isMinimized) {
          setTimeout(function() {
            self._minDragMoved = false;
          }, 0);
        }
      }
    });
  }
  /**
   * 绑定调整大小事件
   */
  bindResizeEvents() {
    if (!this.config.resize) return;
    const self = this;
    const $resize = this.$layer.find(".ws-layer-resize");
    $resize.off("mousedown.layerResize");
    $resize.on("mousedown.layerResize", function(e) {
      if (self.isMaximized || self.isMinimized) return;
      e.stopPropagation();
      e.preventDefault();
      const startX = e.clientX;
      const startY = e.clientY;
      const initialWidth = self.$layer.outerWidth();
      const initialHeight = self.$layer.outerHeight();
      $2("body").css("cursor", "se-resize");
      self.$layer.addClass("ws-layer-resizing");
      const handleMouseMove = function(e2) {
        const deltaX = e2.clientX - startX;
        const deltaY = e2.clientY - startY;
        const newWidth = Math.max(200, initialWidth + deltaX);
        const newHeight = Math.max(150, initialHeight + deltaY);
        const maxWidth = $2(window).width() - 20;
        const maxHeight = $2(window).height() - 20;
        const finalWidth = Math.min(newWidth, maxWidth);
        const finalHeight = Math.min(newHeight, maxHeight);
        self.$layer.css({
          width: finalWidth + "px",
          height: finalHeight + "px"
        });
        self.updateContentSize();
        if (self.config.resizing) {
          self.config.resizing(self.$layer, self);
        }
      };
      const handleMouseUp = function() {
        $2("body").css("cursor", "");
        self.$layer.removeClass("ws-layer-resizing");
        self.adjustPositionAfterResize();
        $2(document).off("mousemove.layerResize mouseup.layerResize");
      };
      $2(document).off("mousemove.layerResize mouseup.layerResize").on("mousemove.layerResize", handleMouseMove).on("mouseup.layerResize", handleMouseUp);
    });
  }
  /**
   * 调整大小后重新定位
   */
  adjustPositionAfterResize() {
    const windowWidth = $2(window).width();
    const windowHeight = $2(window).height();
    const layerWidth = this.$layer.outerWidth();
    const layerHeight = this.$layer.outerHeight();
    let top = parseInt(this.$layer.css("top"));
    let left = parseInt(this.$layer.css("left"));
    if (left + layerWidth > windowWidth) {
      left = windowWidth - layerWidth - 10;
    }
    if (top + layerHeight > windowHeight) {
      top = windowHeight - layerHeight - 10;
    }
    left = Math.max(10, left);
    top = Math.max(10, top);
    this.$layer.css({
      top: top + "px",
      left: left + "px"
    });
  }
  /**
   * 更新内容区域尺寸
   */
  updateContentSize() {
    const $header = this.$layer.find(".ws-layer-header");
    const $body = this.$layer.find(".ws-layer-body");
    const $footer = this.$layer.find(".ws-layer-footer");
    const headerHeight = $header.outerHeight() || 0;
    const footerHeight = $footer.outerHeight() || 0;
    const layerHeight = this.$layer.outerHeight();
    const contentHeight = layerHeight - headerHeight - footerHeight;
    if (contentHeight > 0) {
      $body.css("height", contentHeight + "px");
    }
    if (this.config.type === 2) {
      const $iframe = this.$layer.find("iframe");
      if ($iframe.length) {
        const iframeHeight = contentHeight - (parseInt($body.css("padding-top")) || 0) - (parseInt($body.css("padding-bottom")) || 0);
        if (iframeHeight > 0) {
          $iframe.css("height", iframeHeight + "px");
        }
      }
    }
  }
  /**
   * 绑定最小化最大化事件
   */
  bindMinMaxEvents() {
    const self = this;
    this.$layer.find("[data-layer-min]").on("click", function() {
      if (self.config.minEnd) {
        self.config.minEnd(self.index, self.$layer, self);
      }
      self.minimize();
    });
    this.$layer.find("[data-layer-max]").on("click", function() {
      if (self.isMaximized || self.isMinimized) {
        if (self.config.restore) {
          self.config.restore(self.index, self.$layer, self);
        }
        self.restore();
      } else {
        if (self.config.full) {
          self.config.full(self.index, self.$layer, self);
        }
        self.maximize();
      }
    });
  }
  /**
   * 绑定键盘事件
   */
  bindKeyboardEvents() {
    const self = this;
    $2(document).on(`keydown.ws-layer-${this.index}`, function(e) {
      const maxIndex = WsLayer.index;
      if (e.keyCode === 27 && self.config.closeOnEsc !== false && self.index === maxIndex) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        if (self.config.cancel) {
          self.config.cancel(self.index, self.$layer, self);
        }
        self.close("cancel");
        return false;
      }
      if (e.keyCode === 13 && self.config.submitOnEnter !== false) {
        const $focused = $2(document.activeElement);
        const isInInput = $focused.is("input, textarea, select");
        const isInLayer = $focused.closest(".ws-layer").length > 0;
        if (isInLayer && (!isInInput || self.config.submitOnEnterInInput)) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();
          const $confirmBtn = self.$layer.find('[data-layer-btn="0"]');
          if ($confirmBtn.length && !$confirmBtn.prop("disabled")) {
            $confirmBtn.trigger("click");
          }
          return false;
        }
      }
    });
  }
  /**
   * 移除键盘事件
   */
  unbindKeyboardEvents() {
    $2(document).off(`keydown.ws-layer-${this.index}`);
  }
  /**
   * 自动关闭
   */
  autoClose() {
    const self = this;
    if (this.config.time > 0) {
      setTimeout(function() {
        self.close();
      }, this.config.time);
    }
  }
  /**
   * 最小化弹层
   * 弹层为 fixed 定位，top 必须相对「视口高度」计算，
   * 若按整页文档高度（$(document).height()）定位，页面一长，
   * 最小化条就会被推到视口之外而“消失”。
   */
  minimize() {
    if (this.isMinimized) return;
    if (this.isMaximized) {
      this._stateBeforeMin = "maximized";
    } else {
      this._stateBeforeMin = "normal";
      if (!this._normalStyle) {
        this._normalStyle = this._captureGeometry();
      }
    }
    this.$layer.addClass("ws-layer-minimized");
    this.$layer.find("[data-layer-max]").html('<i class="bi bi-fullscreen-exit"></i>');
    const barH = this.$layer.outerHeight();
    const vw = $2(window).width();
    const vh = $2(window).height();
    const gap = 10;
    const slotW = 260;
    if (WsLayer.minStackArr.indexOf(this.index) === -1) {
      WsLayer.minStackArr.push(this.index);
    }
    const order = WsLayer.minStackArr.indexOf(this.index);
    const rowCap = Math.max(1, Math.floor((vw - gap * 2) / slotW));
    const row = Math.floor(order / rowCap);
    const col = order % rowCap;
    this.$layer.css({
      width: "240px",
      height: "auto",
      top: Math.max(0, vh - (row + 1) * (barH + gap)) + "px",
      left: gap + col * slotW + "px",
      right: "auto",
      bottom: "auto",
      transform: "none"
    });
    if (this.$shade) {
      this.$shade.hide();
    }
    if (!this._minRestoreBound) {
      const self = this;
      this._minRestoreBound = true;
      this.$layer.find(".ws-layer-header").on("click.layerMinRestore", function(e) {
        if (!self.isMinimized || self._minDragMoved) return;
        if ($2(e.target).closest("button").length) return;
        self.restore();
      });
    }
    this.isMinimized = true;
    this.isMaximized = false;
  }
  /**
   * 捕获当前常规几何（用于还原普通状态）
   */
  _captureGeometry() {
    return {
      width: this.$layer.css("width"),
      height: this.$layer.css("height"),
      top: this.$layer.css("top"),
      left: this.$layer.css("left"),
      right: this.$layer.css("right"),
      bottom: this.$layer.css("bottom"),
      transform: this.$layer.css("transform")
    };
  }
  /**
   * 应用最大化几何
   */
  _applyMaximizeCss() {
    this.$layer.css({
      width: "100%",
      height: "100%",
      top: 0,
      left: 0,
      right: "auto",
      bottom: "auto",
      transform: "none"
    });
  }
  /**
   * 最大化弹层
   */
  maximize() {
    if (this.isMaximized || this.isMinimized) return;
    if (!this._normalStyle) {
      this._normalStyle = this._captureGeometry();
    }
    this._applyMaximizeCss();
    this.$layer.find("[data-layer-max]").html('<i class="bi bi-fullscreen-exit"></i>');
    this.isMaximized = true;
  }
  /**
   * 还原弹层
   * 状态机：normal <-> maximized <-> minimized。
   * 最小化还原会回到“最小化前的状态”（最大化 或 普通），
   * 最大化还原回到普通；不会因反复切换而丢失普通几何或状态位。
   */
  restore() {
    if (!this.isMinimized && !this.isMaximized) return;
    const fromMinimized = this.isMinimized;
    const backToMaximized = fromMinimized && this._stateBeforeMin === "maximized";
    this.$layer.removeClass("ws-layer-minimized");
    if (backToMaximized) {
      this._applyMaximizeCss();
      this.$layer.find("[data-layer-max]").html('<i class="bi bi-fullscreen-exit"></i>');
      this.isMaximized = true;
      this.isMinimized = false;
    } else {
      this.$layer.css(this._normalStyle || {});
      this.$layer.find("[data-layer-max]").html('<i class="bi bi-square"></i>');
      this.isMaximized = false;
      this.isMinimized = false;
    }
    this._stateBeforeMin = null;
    const pos = WsLayer.minStackArr.indexOf(this.index);
    if (pos > -1) {
      WsLayer.minStackArr.splice(pos, 1);
    }
    if (this.$shade) {
      this.$shade.show();
    }
  }
  /**
   * 设置标题
   * @param {String} title 新标题
   */
  setTitle(title) {
    this.$layer.find(".ws-layer-header h5").html(title);
  }
  /**
   * 处理图片浏览器操作
   * @param {String} action 操作类型
   */
  handlePhotosAction(action3) {
    if (!this.photosData) return;
    switch (action3) {
      case "prev":
        if (this.photosData.currentIndex > 0) {
          this.photosData.currentIndex--;
          this.updatePhotosView();
        }
        break;
      case "next":
        if (this.photosData.currentIndex < this.photosData.data.length - 1) {
          this.photosData.currentIndex++;
          this.updatePhotosView();
        }
        break;
      case "rotate":
        this.photosData.rotate = (this.photosData.rotate + 90) % 360;
        this.$layer.find("img").css("transform", `rotate(${this.photosData.rotate}deg)`);
        break;
      case "close":
        WsLayer.close(this.index);
        break;
    }
  }
  /**
   * 更新图片浏览器视图
   */
  updatePhotosView() {
    const photo = this.photosData.data[this.photosData.currentIndex];
    const $img = this.$layer.find("img");
    const $counter = this.$layer.find(".ws-layer-photos-header span");
    const $footer = this.$layer.find(".ws-layer-photos-footer div");
    const $link = this.$layer.find(".ws-layer-photos-footer a");
    $img.attr("src", photo.src).attr("alt", photo.alt || "");
    $counter.text(`${this.photosData.currentIndex + 1} / ${this.photosData.data.length}`);
    $footer.text(photo.alt || "");
    $link.attr("href", photo.src);
    this.$layer.find('[data-photos-action="prev"]').prop("disabled", this.photosData.currentIndex === 0);
    this.$layer.find('[data-photos-action="next"]').prop("disabled", this.photosData.currentIndex === this.photosData.data.length - 1);
    this._schedulePhotosFit();
  }
  /**
   * 图片层自适应：约束图片尺寸并按弹层高度轮询校正位置，直到尺寸稳定。
   *
   * 背景：弹层在图片加载/解码完成之前就会被定位，而图片异步就位后
   * 弹层高度会突变。load 事件、complete 检查、单次 rAF 都无法覆盖
   * "命中缓存"与"解码耗时"两类场景，因此采用低频轮询——每 80ms
   * 重新定位一次，直到连续 3 次弹层高度一致（视为尺寸稳定），
   * 或超过 4s 上限后自动停止，保证弹层始终完整落在视口内。
   */
  _schedulePhotosFit() {
    const self = this;
    if (this._stopPhotosAutoFit) this._stopPhotosAutoFit();
    const $img = this.$layer.find("img");
    $img.css({
      "max-width": "100%",
      "max-height": Math.max(160, $2(window).height() - 120) + "px"
    });
    let lastH = null;
    let stable = 0;
    let count = 0;
    const timer = setInterval(function() {
      count++;
      self.setPosition();
      const h = self.$layer.outerHeight();
      if (h === lastH) {
        stable++;
      } else {
        stable = 0;
        lastH = h;
      }
      if (stable >= 3 || count > 50) {
        clearInterval(timer);
      }
    }, 80);
    this._stopPhotosAutoFit = function() {
      clearInterval(timer);
    };
  }
  /**
   * 关闭弹层
   * @param {String} exitType 退出类型，可选值为 'close' 或 'cancel'
   */
  close(exitType = "close") {
    const self = this;
    const stackPos = WsLayer.minStackArr.indexOf(this.index);
    if (stackPos > -1) {
      WsLayer.minStackArr.splice(stackPos, 1);
    }
    this.unbindKeyboardEvents();
    if (this.config.isOutAnim) {
      this.$layer.removeClass("ws-layer-fade-in").addClass("ws-layer-fade-out");
      setTimeout(function() {
        self.remove();
      }, 300);
    } else {
      this.remove();
    }
    if (this.config.end) {
      this.config.end(exitType);
    }
    WsLayer.instances[self.index] = null;
  }
  /**
   * 移除弹层
   */
  remove() {
    if (this.$shade) {
      this.$shade.remove();
    }
    $2(document).off("mousemove.layerDrag mouseup.layerDrag");
    this.$layer.remove();
  }
};
WsLayer.init();
window.WsLayer = WsLayer;
window.wsLayer = WsLayer;
window.Layer = WsLayer;
var ws_layer_default = WsLayer;

// public/statics/libs/ws-select/ws-select.js
var $3 = window.jQuery;
if (typeof $3 === "undefined") {
  throw new Error("WsSelect requires jQuery");
}
var DEFAULTS = {
  options: [],
  value: null,
  defaultValue: null,
  multiple: false,
  multipleMaxSelect: 0,
  disabled: false,
  searchable: false,
  autoClearSearchValue: false,
  clearable: true,
  placeholder: "请选择",
  size: "medium",
  onChange: null,
  onOptionChange: null,
  onRender: null,
  onSearch: null,
  onFocus: null,
  onBlur: null,
  onSelectOptionRender: null,
  onSelectValueRender: null,
  loading: false,
  filterOption: true,
  allowClear: true,
  showArrow: true,
  dropdownMatchSelectWidth: true,
  remoteUrl: null,
  url: null,
  remoteMethod: null,
  onError: null,
  secondaryColumn: false,
  emptyText: "无匹配选项",
  loadingText: "加载中...",
  fieldMapping: {
    // 字段映射配置
    value: "value",
    label: "label",
    secondary: "secondary",
    icon: "icon",
    group: "group",
    disabled: "disabled"
  },
  displayMode: "default",
  // 'default' or 'group'
  remoteDelay: 300,
  minSearchLength: 1,
  maxOptionHeight: "300px",
  ajaxOptions: {},
  pagination: false,
  pageSize: 10,
  pageSizeOptions: [10, 20, 50, 100],
  paginationFieldMapping: {
    // 分页字段映射配置
    page: "page",
    pageSize: "pageSize",
    total: "total",
    data: "data"
  },
  showTotal: true,
  showSizeChanger: true,
  // 树形模式相关配置
  treeMode: false,
  treeFieldMapping: {
    // 树形字段映射配置 
    value: "value",
    label: "label",
    children: "children",
    disabled: "disabled",
    icon: "icon",
    secondary: "secondary",
    isLeaf: "isLeaf"
  },
  treeExpandAll: false,
  lastLevelLayout: "vertical",
  // 'vertical' or 'horizontal'
  treeCheckable: false,
  treeCheckStrictly: false,
  // 表单集成相关配置
  name: null,
  // 隐藏input的name属性，用于表单提交
  inputName: null,
  // 隐藏input的name属性别名
  id: null,
  // 隐藏input的id属性
  inputId: null,
  // 隐藏input的id属性别名
  required: false,
  // 是否必填
  valueSeparator: ",",
  // 多选时值的分隔符，默认为逗号
  useJsonValue: false,
  // 是否使用JSON格式存储多选值
  autoCreateInput: true
  // 是否自动创建隐藏input，默认为true
};
function WsSelect(element, options2) {
  this.$element = $3(element);
  this.settings = $3.extend(true, {}, DEFAULTS, options2);
  this.settings.remoteUrl = this.settings.remoteUrl || this.settings.url;
  this.init();
}
WsSelect.prototype = {
  /**
   * 初始化组件
   */
  init: function() {
    this.selectedValues = this.settings.multiple ? Array.isArray(this.settings.value) ? this.settings.value : this.settings.value ? [this.settings.value] : [] : this.settings.value || null;
    this.isOpen = false;
    this.searchValue = "";
    this.focusedIndex = -1;
    this.remoteSearchTimer = null;
    this.isLoading = false;
    this.remoteData = [];
    this.localOptions = this.transformRemoteData(this.settings.options || []);
    this.currentPage = 1;
    this.total = this.localOptions.length;
    this.pageSize = this.settings.pageSize;
    this.treeData = [];
    this.expandedKeys = /* @__PURE__ */ new Set();
    this.hiddenInput = null;
    if (this.settings.autoCreateInput) {
      this.createHiddenInput();
    }
    this.render();
    if (this.settings.treeMode) {
      this.processTreeData();
      if (this.settings.treeExpandAll) {
        this.expandAllNodes();
      }
      this.updateOptionsList();
    } else {
    }
    this.bindEvents();
    if ((this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") && this.localOptions.length === 0) {
      this.loadRemoteData();
    }
  },
  /**
   * 动态重新配置组件
   * @param {Object} newOptions - 新的配置选项，将与现有配置合并
   * @param {boolean} [resetSelectedValues=false] - 是否重置选中的值为新配置中的value或默认值
   */
  reconfigure: function(newOptions, resetSelectedValues) {
    if (!newOptions || typeof newOptions !== "object") return;
    var oldSettings = $3.extend(true, {}, this.settings);
    $3.extend(true, this.settings, newOptions);
    this.settings.remoteUrl = this.settings.remoteUrl || this.settings.url;
    var changes = {
      options: !this.deepEqual(oldSettings.options, this.settings.options),
      multiple: oldSettings.multiple !== this.settings.multiple,
      disabled: oldSettings.disabled !== this.settings.disabled,
      searchable: oldSettings.searchable !== this.settings.searchable,
      clearable: oldSettings.clearable !== this.settings.clearable,
      placeholder: oldSettings.placeholder !== this.settings.placeholder,
      size: oldSettings.size !== this.settings.size,
      displayMode: oldSettings.displayMode !== this.settings.displayMode,
      treeMode: oldSettings.treeMode !== this.settings.treeMode,
      pagination: oldSettings.pagination !== this.settings.pagination,
      remoteUrl: oldSettings.remoteUrl !== this.settings.remoteUrl,
      fieldMapping: !this.deepEqual(oldSettings.fieldMapping, this.settings.fieldMapping),
      name: oldSettings.name !== this.settings.name || oldSettings.inputName !== this.settings.inputName,
      valueSeparator: oldSettings.valueSeparator !== this.settings.valueSeparator,
      useJsonValue: oldSettings.useJsonValue !== this.settings.useJsonValue,
      remoteMethod: oldSettings.remoteMethod !== this.settings.remoteMethod
    };
    if (resetSelectedValues) {
      this.selectedValues = this.settings.multiple ? Array.isArray(this.settings.value) ? this.settings.value : this.settings.value ? [this.settings.value] : [] : this.settings.value || null;
    } else if (changes.multiple) {
      if (this.settings.multiple) {
        if (!Array.isArray(this.selectedValues) && this.selectedValues !== null) {
          this.selectedValues = [this.selectedValues];
        } else if (this.selectedValues === null) {
          this.selectedValues = [];
        }
      } else {
        if (Array.isArray(this.selectedValues) && this.selectedValues.length > 0) {
          this.selectedValues = this.selectedValues[0];
        } else {
          this.selectedValues = null;
        }
      }
    }
    this.$element.removeClass("size-sm size-medium size-lg display-mode-default display-mode-group ws-select-tree-mode last-level-horizontal");
    if (this.settings.size !== "medium") {
      this.$element.addClass("size-" + this.settings.size);
    }
    this.$element.addClass("display-mode-" + this.settings.displayMode);
    if (this.settings.treeMode) {
      this.$element.addClass("ws-select-tree-mode");
      if (this.settings.lastLevelLayout === "horizontal") {
        this.$element.addClass("last-level-horizontal");
      }
    }
    if (changes.disabled) {
      if (this.settings.disabled) {
        this.$trigger.addClass("disabled");
      } else {
        this.$trigger.removeClass("disabled");
      }
    }
    if (changes.clearable || changes.disabled) {
      if (this.$clearBtn) {
        this.$clearBtn.remove();
        this.$clearBtn = null;
      }
      if (this.settings.allowClear && !this.settings.disabled) {
        this.$clearBtn = $3('<span class="custom-select-clear"><i class="bi bi-x-circle"></i></span>');
        this.$trigger.append(this.$clearBtn);
        this.$clearBtn.on("click", function(e) {
          e.stopPropagation();
          this.clear();
        }.bind(this));
      }
    }
    if (changes.searchable || changes.remoteUrl) {
      var searchExists = this.$search && this.$search.length > 0;
      var needSearch = this.settings.searchable || this.settings.remoteUrl || this.settings.treeMode;
      if (needSearch && !searchExists) {
        this.$search = $3(
          '<div class="custom-select-search"><input type="text" class="form-control form-control-sm" placeholder="搜索..."></div>'
        );
        this.$dropdown.prepend(this.$search);
        this.$search.find("input").on("input", function() {
          var searchValue = $3(this).val();
          this.handleSearch(searchValue);
        }.bind(this));
      } else if (!needSearch && searchExists) {
        this.$search.remove();
        this.$search = null;
      }
    }
    if (changes.pagination) {
      if (this.settings.pagination && !this.$pagination) {
        this.$pagination = $3('<div class="custom-select-pagination"></div>');
        this.$dropdown.append(this.$pagination);
      } else if (!this.settings.pagination && this.$pagination) {
        this.$pagination.remove();
        this.$pagination = null;
      }
    }
    if (this.$pagination && (changes.pagination || changes.pageSize || changes.pageSizeOptions)) {
      this.renderPagination();
    }
    if (changes.treeMode) {
      if (this.settings.treeMode) {
        this.processTreeData();
        if (this.settings.treeExpandAll) {
          this.expandAllNodes();
        }
      } else {
        this.treeData = [];
        this.expandedKeys.clear();
      }
    }
    if (changes.options || changes.treeMode || changes.fieldMapping) {
      if (this.settings.treeMode) {
        this.processTreeData();
      } else {
        this.localOptions = this.settings.options || [];
      }
    }
    if (changes.name && this.settings.autoCreateInput) {
      this.createHiddenInput();
    }
    this.updateOptionsList();
    this.updateValueDisplay();
    if (changes.remoteUrl && this.settings.remoteUrl && this.localOptions.length === 0 || changes.remoteMethod && typeof this.settings.remoteMethod === "function") {
      this.loadRemoteData();
    }
    this.$element.trigger("reconfigured.multiSelect", [this.settings]);
  },
  /**
   * 简单的深度比较函数（仅适用于JSON可序列化的对象）
   * @param {*} a - 比较值a
   * @param {*} b - 比较值b
   * @returns {boolean} 是否相等
   */
  deepEqual: function(a, b) {
    if (a === b) return true;
    if (a == null || b == null) return false;
    if (typeof a !== typeof b) return false;
    if (typeof a !== "object") return a === b;
    if (Array.isArray(a) && Array.isArray(b)) {
      if (a.length !== b.length) return false;
      for (var i = 0; i < a.length; i++) {
        if (!this.deepEqual(a[i], b[i])) return false;
      }
      return true;
    }
    var keysA = Object.keys(a);
    var keysB = Object.keys(b);
    if (keysA.length !== keysB.length) return false;
    for (var key in a) {
      if (!b.hasOwnProperty(key)) return false;
      if (!this.deepEqual(a[key], b[key])) return false;
    }
    return true;
  },
  /**
   * 创建隐藏input元素
   */
  createHiddenInput: function() {
    if (this.hiddenInput) {
      this.hiddenInput.remove();
    }
    var inputName = this.settings.inputName || this.settings.name;
    if (!inputName) {
      var originalInput = this.$element.find('input[type="hidden"], select').first();
      if (originalInput.length && originalInput.attr("name")) {
        inputName = originalInput.attr("name");
      } else {
        return;
      }
    }
    var inputId = this.settings.inputId || this.settings.id;
    if (!inputId) {
      var originalElementId = this.$element.attr("id");
      if (originalElementId) {
        inputId = originalElementId + "_hidden";
      }
    }
    this.hiddenInput = $3('<input type="hidden">');
    this.hiddenInput.attr("name", inputName);
    if (inputId) {
      this.hiddenInput.attr("id", inputId);
    }
    this.updateHiddenInputValue();
    this.$element.append(this.hiddenInput);
    var originalSelect = this.$element.find("select").first();
    if (originalSelect.length) {
      originalSelect.hide();
      var attrsToCopy = ["required", "data-required", "class", "data-validate", "data-parsley-required"];
      attrsToCopy.forEach(function(attr2) {
        var val = originalSelect.attr(attr2);
        if (val !== void 0) {
          this.hiddenInput.attr(attr2, val);
        }
      }.bind(this));
    }
  },
  /**
   * 更新隐藏input的值
   */
  updateHiddenInputValue: function() {
    if (!this.hiddenInput) return;
    var value = "";
    if (this.selectedValues) {
      if (this.settings.multiple) {
        if (Array.isArray(this.selectedValues) && this.selectedValues.length > 0) {
          if (this.settings.useJsonValue) {
            value = JSON.stringify(this.selectedValues);
          } else {
            value = this.selectedValues.join(this.settings.valueSeparator);
          }
        }
      } else {
        value = this.selectedValues || "";
      }
    }
    this.hiddenInput.val(value);
    this.hiddenInput.trigger("change");
    var originalSelect = this.$element.find("select").first();
    if (originalSelect.length) {
      if (this.settings.multiple) {
        originalSelect.val(this.selectedValues || []);
      } else {
        originalSelect.val(this.selectedValues || "");
      }
      originalSelect.trigger("change");
    }
  },
  /**
   * 渲染组件结构
   */
  render: function() {
    this.$element.empty();
    this.$element.addClass("ws-select-container display-mode-" + this.settings.displayMode);
    if (this.settings.size !== "medium") {
      this.$element.addClass("size-" + this.settings.size);
    }
    if (this.settings.treeMode) {
      this.$element.addClass("ws-select-tree-mode");
      if (this.settings.lastLevelLayout === "horizontal") {
        this.$element.addClass("last-level-horizontal");
      }
    }
    this.$trigger = $3('<div class="ws-select-trigger" tabindex="0"></div>');
    if (this.settings.disabled) {
      this.$trigger.addClass("disabled");
    }
    this.$valueDisplay = $3('<div class="ws-select-value"></div>');
    this.updateValueDisplay();
    if (this.settings.allowClear && !this.settings.disabled) {
      this.$clearBtn = $3('<span class="ws-select-clear"><i class="bi bi-x-circle"></i></span>');
      this.$trigger.append(this.$clearBtn);
    }
    if (this.settings.showArrow) {
      this.$arrow = $3('<span class="ws-select-arrow"><i class="bi bi-chevron-down"></i></span>');
      this.$trigger.append(this.$arrow);
    }
    this.$trigger.prepend(this.$valueDisplay);
    this.$element.append(this.$trigger);
    this.$dropdown = $3('<div class="ws-select-dropdown"></div>');
    if (this.settings.searchable || this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") {
      this.$search = $3(
        '<div class="ws-select-search"><input type="text" class="form-control form-control-sm" placeholder="搜索..." value="' + this.searchValue + '"></div>'
      );
      this.$dropdown.append(this.$search);
    }
    const maxHeight = (typeof this.settings.maxOptionHeight === "number" ? "max-height:" + this.settings.maxOptionHeight + "px" : typeof this.settings.maxOptionHeight === "string" && /^\d+(\.\d+)?$/.test(this.settings.maxOptionHeight) ? "max-height:" + this.settings.maxOptionHeight + "px" : "max-height:" + this.settings.maxOptionHeight) || "";
    this.$optionsList = $3(`<ul class="ws-select-options" style="${maxHeight}"></ul>`);
    this.$dropdown.append(this.$optionsList);
    if (this.settings.pagination) {
      this.$pagination = $3('<div class="ws-select-pagination"></div>');
      this.$dropdown.append(this.$pagination);
      this.renderPagination();
    }
    this.$element.append(this.$dropdown);
    if (this.hiddenInput && this.hiddenInput.length && !this.hiddenInput.parent().length) {
      this.$element.append(this.hiddenInput);
    }
    this.updateOptionsList();
  },
  /**
   * 更新选中值的显示
   */
  updateValueDisplay: function() {
    this.$valueDisplay.empty();
    if (this.selectedValues && (this.settings.multiple ? this.selectedValues.length > 0 : this.selectedValues)) {
      if (this.settings.multiple && Array.isArray(this.selectedValues)) {
        var $tags = $3('<div class="ws-select-tags"></div>');
        this.selectedValues.forEach(function(value) {
          var option2 = this.findOptionByValue(value);
          if (option2) {
            if (typeof this.settings.onSelectValueRender === "function") {
              var $tag = $3(
                '<div class="ws-select-tag">' + this.settings.onSelectValueRender(option2) + (!this.settings.disabled ? '<span class="ws-select-tag-remove"><i class="bi bi-x"></i></span>' : "") + "</div>"
              );
            } else {
              $tag = $3(
                '<div class="ws-select-tag"><span>' + option2.label + "</span>" + (!this.settings.disabled ? '<span class="ws-select-tag-remove"><i class="bi bi-x"></i></span>' : "") + "</div>"
              );
            }
            $tag.data("value", value);
            $tags.append($tag);
          }
        }.bind(this));
        this.$valueDisplay.append($tags);
      } else {
        var option = this.findOptionByValue(this.selectedValues);
        if (option) {
          if (typeof this.settings.onSelectValueRender === "function") {
            this.$valueDisplay.html(this.settings.onSelectValueRender(option));
          } else {
            this.$valueDisplay.text(option.label);
          }
        }
      }
    } else {
      this.$valueDisplay.html('<span class="ws-select-placeholder">' + this.settings.placeholder + "</span>");
    }
    this.updateHiddenInputValue();
  },
  /**
   * 更新选项列表
   */
  updateOptionsList: function() {
    if (this.isLoading) {
      this.$optionsList.append(
        '<div class="ws-select-loading"><div class="spinner-border spinner-border-sm" role="status"></div>' + this.settings.loadingText + "</div>"
      );
      return;
    }
    if (this.settings.treeMode) {
      var treeDataToRender = this.treeData;
      if (this.searchValue && (this.settings.searchable || this.settings.remoteUrl)) {
        treeDataToRender = this.filterTree(this.treeData, this.searchValue);
        if (treeDataToRender.length > 0) {
          this.expandedKeys = this.collectExpandKeysFromFilteredTree(treeDataToRender);
        } else {
          this.expandedKeys.clear();
        }
      } else {
        if (this.settings.treeExpandAll) {
          this.expandAllNodes();
        } else {
        }
      }
      this.renderTree(treeDataToRender);
    } else {
      var optionsToShow = this.getCurrentOptions();
      if ((this.settings.searchable || this.settings.remoteUrl) && this.searchValue) {
        if (this.settings.remoteUrl) {
        } else if (typeof this.settings.filterOption === "function") {
          optionsToShow = optionsToShow.filter(function(option) {
            return this.settings.filterOption(this.searchValue, option);
          }.bind(this));
        } else if (typeof this.settings.filterOption === "boolean" && this.settings.filterOption) {
          var searchLower = this.searchValue.toLowerCase();
          optionsToShow = optionsToShow.filter(function(option) {
            return option.label.toLowerCase().includes(searchLower) || option.secondary && option.secondary.toLowerCase().includes(searchLower);
          });
        }
      }
      if (this.settings.pagination) {
        if (!this.settings.remoteUrl && typeof this.settings.remoteMethod !== "function") {
          this.total = optionsToShow.length;
        }
        if (this.total === 0) {
          this.$optionsList.html('<div class="ws-select-no-options">' + this.settings.emptyText + "</div>");
          if (this.$pagination) {
            this.renderPagination();
          }
          return;
        }
        if (this.total == optionsToShow.length) {
          var start = (this.currentPage - 1) * this.pageSize;
          var end = start + this.pageSize;
          optionsToShow = optionsToShow.slice(start, end);
        }
      }
      var groupedOptions = this.groupOptions(optionsToShow);
      if (groupedOptions.length === 0) {
        this.$optionsList.html('<div class="ws-select-no-options">' + this.settings.emptyText + "</div>");
      } else {
        this.$optionsList.empty();
        groupedOptions.forEach(function(group) {
          if (group.group && this.settings.displayMode === "group") {
            this.$optionsList.append('<div class="ws-select-group"><i class="bi bi-view-list me-2"></i>' + group.group + "</div>");
          }
          group.options.forEach(function(option) {
            this.renderOption(option);
          }.bind(this));
        }.bind(this));
      }
    }
  },
  /**
   * 过滤树数据（保留匹配节点及其祖先）
   * @param {Array} nodes - 树节点数组
   * @param {string} searchValue - 搜索关键词
   * @returns {Array} 过滤后的树数据
   */
  filterTree: function(nodes, searchValue) {
    var self = this;
    var searchLower = searchValue.toLowerCase();
    var result = [];
    nodes.forEach(function(node) {
      var nodeCopy = $3.extend(true, {}, node);
      var matches = node.label.toLowerCase().includes(searchLower) || node.secondary && node.secondary.toLowerCase().includes(searchLower);
      var filteredChildren = [];
      if (node.children && node.children.length > 0) {
        filteredChildren = self.filterTree(node.children, searchValue);
      }
      if (matches || filteredChildren.length > 0) {
        if (filteredChildren.length > 0) {
          nodeCopy.children = filteredChildren;
        } else {
          delete nodeCopy.children;
        }
        result.push(nodeCopy);
      }
    });
    return result;
  },
  /**
   * 从过滤后的树中收集所有需要展开的节点 key
   * @param {Array} filteredTree - 过滤后的树数据
   * @returns {Set} 包含所有节点 value 的 Set
   */
  collectExpandKeysFromFilteredTree: function(filteredTree) {
    var keys = /* @__PURE__ */ new Set();
    var collect = function(nodes) {
      nodes.forEach(function(node) {
        keys.add(node.value);
        if (node.children && node.children.length > 0) {
          collect(node.children);
        }
      });
    };
    collect(filteredTree);
    return keys;
  },
  /**
   * 渲染树形结构
   * @param {Array} [treeData] - 要渲染的树数据，默认为 this.treeData
   */
  renderTree: function(treeData) {
    treeData = treeData || this.treeData;
    if (treeData && treeData.length === 0) {
      this.$optionsList.html(`<div class="ws-select-no-options">${this.settings.emptyText}</div>`);
      return;
    }
    var renderTreeNode = function(node, level) {
      var $node = $3('<li class="ws-select-tree-node"></li>');
      var $content = $3('<div class="ws-select-tree-node-content"></div>');
      var isLastLevelHorizontal = level >= 0 && this.settings.lastLevelLayout === "horizontal";
      if (!isLastLevelHorizontal) {
        for (var i = 0; i < level; i++) {
          $content.append('<span class="ws-select-tree-node-indent"></span>');
        }
      }
      var hasChildren = node.children && node.children.length > 0;
      var isExpanded = this.expandedKeys.has(node.value);
      if (hasChildren) {
        var $toggle = $3('<span class="ws-select-tree-node-toggle"><i class="bi bi-caret-right-fill"></i></span>');
        if (isExpanded) {
          $toggle.addClass("expanded");
        }
        $content.append($toggle);
        $toggle.data("node", node);
      } else {
      }
      var isSelected = this.selectedValues && this.selectedValues.includes(node.value);
      var isDisabled = node.disabled || this.settings.disabled;
      if (this.settings.treeCheckable && this.settings.multiple) {
        var $checkbox = $3('<span class="ws-select-tree-checkbox"><i class="bi"></i></span>');
        var isIndeterminate = false;
        if (!this.settings.treeCheckStrictly && hasChildren) {
          var childSelectionState = this.getChildSelectionState(node);
          if (childSelectionState.someSelected && !childSelectionState.allSelected) {
            isIndeterminate = true;
          }
        }
        if (isIndeterminate) {
          $checkbox.find("i").addClass("bi-dash-square");
        } else if (isSelected) {
          $checkbox.find("i").addClass("bi-check-square-fill");
        } else {
          $checkbox.find("i").addClass("bi-square");
        }
        $content.append($checkbox);
      }
      if (isSelected) {
        $content.addClass("selected");
      }
      if (isDisabled) {
        $content.addClass("disabled");
      }
      if (isIndeterminate) {
        $content.addClass("indeterminate");
      }
      var $optionContent = $3('<div class="ws-select-option-content"></div>');
      if (node.icon) {
        $optionContent.append('<span class="ws-select-option-icon"><i class="' + node.icon + '"></i></span>');
      }
      $optionContent.append('<span class="ws-select-option-main">' + node.label + "</span>");
      if (node.secondary && !isLastLevelHorizontal) {
        $optionContent.append('<span class="ws-select-option-secondary">' + node.secondary + "</span>");
      }
      $content.append($optionContent);
      $content.data("node", node);
      $node.append($content);
      if (hasChildren) {
        var $children = $3('<ul class="ws-select-tree-node-children"></ul>');
        if (isExpanded) {
          $children.addClass("expanded");
        }
        if (isLastLevelHorizontal && node.children.every(function(child) {
          return !child.children || child.children.length === 0;
        })) {
          $children.addClass("horizontal");
        }
        node.children.forEach(function(child) {
          $children.append(renderTreeNode.call(this, child, level + 1));
        }.bind(this));
        $node.append($children);
      }
      return $node;
    }.bind(this);
    this.$optionsList.empty();
    treeData.forEach(function(node) {
      this.$optionsList.append(renderTreeNode.call(this, node, 0));
    }.bind(this));
  },
  /**
   * 获取子节点的选择状态
   * @param {Object} node - 父节点
   * @returns {Object} 包含 someSelected 和 allSelected 的对象
   */
  getChildSelectionState: function(node) {
    var result = {
      someSelected: false,
      allSelected: true,
      total: 0,
      selected: 0
    };
    var countSelected = function(childNode) {
      result.total++;
      var isSelected = this.selectedValues.indexOf(childNode.value) !== -1;
      if (isSelected) {
        result.selected++;
        result.someSelected = true;
      } else {
        result.allSelected = false;
      }
      if (childNode.children && childNode.children.length > 0) {
        childNode.children.forEach(function(grandChild) {
          countSelected.call(this, grandChild);
        }.bind(this));
      }
    }.bind(this);
    if (node.children && node.children.length > 0) {
      node.children.forEach(function(child) {
        countSelected.call(this, child);
      }.bind(this));
    }
    return result;
  },
  /**
   * 获取当前选中的所有节点值（包括子节点）
   * @returns {Array} 选中的节点值数组
   */
  getCheckedValues: function() {
    if (this.settings.treeCheckStrictly) {
      return this.selectedValues;
    } else {
      var allChecked = [];
      var collectChecked = function(nodes) {
        nodes.forEach(function(node) {
          if (this.selectedValues.indexOf(node.value) !== -1) {
            allChecked.push(node.value);
            if (node.children && node.children.length > 0) {
              this.collectAllChildrenValues(node, allChecked);
            }
          } else if (node.children && node.children.length > 0) {
            collectChecked.call(this, node.children);
          }
        }.bind(this));
      }.bind(this);
      collectChecked.call(this, this.treeData);
      return [...new Set(allChecked)];
    }
  },
  /**
   * 收集节点的所有子节点值
   * @param {Object} node - 父节点
   * @param {Array} result - 结果数组
   */
  collectAllChildrenValues: function(node, result) {
    if (!node.children || node.children.length === 0) return;
    node.children.forEach(function(child) {
      result.push(child.value);
      this.collectAllChildrenValues(child, result);
    }.bind(this));
  },
  /**
   * 处理树形数据
   */
  processTreeData: function() {
    var mapData = function(data3) {
      return data3.map(function(item) {
        var node = {
          value: item[this.settings.treeFieldMapping.value],
          label: item[this.settings.treeFieldMapping.label],
          disabled: item[this.settings.treeFieldMapping.disabled] || false,
          icon: item[this.settings.treeFieldMapping.icon],
          secondary: item[this.settings.treeFieldMapping.secondary],
          isLeaf: item[this.settings.treeFieldMapping.isLeaf] || false
        };
        if (item[this.settings.treeFieldMapping.children] && item[this.settings.treeFieldMapping.children].length > 0) {
          node.children = mapData.call(this, item[this.settings.treeFieldMapping.children]);
        }
        return node;
      }.bind(this));
    }.bind(this);
    this.treeData = mapData.call(this, this.settings.options);
    return this.treeData;
  },
  /**
   * 获取所有树节点的扁平化列表
   * @returns {Array} 所有树节点的数组
   */
  getAllTreeNodes: function() {
    var allNodes = [];
    var traverse = function(nodes) {
      nodes.forEach(function(node) {
        allNodes.push(node);
        if (node.children && node.children.length > 0) {
          traverse(node.children);
        }
      });
    };
    traverse(this.treeData);
    return allNodes;
  },
  /**
   * 展开所有节点
   */
  expandAllNodes: function() {
    var expandNode = function(node) {
      this.expandedKeys.add(node.value);
      if (node.children && node.children.length > 0) {
        node.children.forEach(function(child) {
          expandNode.call(this, child);
        }.bind(this));
      }
    }.bind(this);
    this.treeData.forEach(function(node) {
      expandNode.call(this, node);
    }.bind(this));
  },
  /**
   * 获取当前显示的选项（本地或远程）
   * @returns {Array} 选项数组
   */
  getCurrentOptions: function() {
    if (this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") {
      if (this.searchValue && this.searchValue.length >= (typeof this.settings.minSearchLength === "number" ? this.settings.minSearchLength : 1)) {
        return this.remoteData;
      } else if (!this.searchValue && this.remoteData.length > 0) {
        return this.remoteData;
      }
    }
    return this.localOptions;
  },
  /**
   * 对选项进行分组处理
   * @param {Array} options - 选项数组
   * @returns {Array} 分组后的选项数组
   */
  groupOptions: function(options2) {
    var groups = {};
    var ungrouped = [];
    options2.forEach(function(option) {
      if (option.group && this.settings.displayMode === "group") {
        if (!groups[option.group]) {
          groups[option.group] = [];
        }
        groups[option.group].push(option);
      } else {
        ungrouped.push(option);
      }
    }.bind(this));
    var result = [];
    if (ungrouped.length > 0) {
      result.push({
        group: null,
        options: ungrouped
      });
    }
    Object.keys(groups).forEach(function(groupName) {
      result.push({
        group: groupName,
        options: groups[groupName]
      });
    });
    return result;
  },
  /**
   * 渲染单个选项
   * @param {Object} option - 选项对象
   */
  renderOption: function(option) {
    var isSelected = this.settings.multiple ? this.selectedValues.includes(option.value) : this.selectedValues === option.value;
    var isDisabled = option.disabled || this.settings.disabled;
    var $option = $3(
      '<li class="ws-select-option ' + (isSelected ? "selected" : "") + " " + (isDisabled ? "disabled" : "") + '"><div class="ws-select-option-content ' + (this.settings.secondaryColumn ? " secondary-column" : "") + '">' + (option.icon ? '<span class="ws-select-option-icon"><i class="' + option.icon + '"></i></span>' : "") + '<span class="ws-select-option-main">' + option.label + "</span>" + (option.secondary ? '<span class="ws-select-option-secondary">' + option.secondary + "</span>" : "") + "</div></li>"
    );
    $option.data("option", option);
    this.$optionsList.append($option);
  },
  /**
   * 根据值查找选项
   * @param {*} value - 选项值
   * @returns {Object|null} 找到的选项对象，未找到返回null
   */
  findOptionByValue: function(value) {
    if (this.settings.treeMode) {
      return this.findTreeNodeByValue(value);
    }
    var allOptions = this.localOptions.concat(this.remoteData);
    return allOptions.find(function(option) {
      return option.value === value;
    });
  },
  /**
   * 根据值查找树节点
   * @param {*} value - 节点值
   * @param {Array} [nodes] - 要搜索的节点数组（默认为根节点）
   * @returns {Object|null} 找到的节点对象，未找到返回null
   */
  findTreeNodeByValue: function(value, nodes) {
    if (!value) return null;
    nodes = nodes || this.treeData;
    var result = null;
    var searchTree = function(nodes2) {
      for (var i = 0; i < nodes2.length; i++) {
        var node = nodes2[i];
        if (node.value === value) {
          result = node;
          return true;
        }
        if (node.children && node.children.length > 0) {
          if (searchTree(node.children)) {
            return true;
          }
        }
      }
      return false;
    };
    searchTree(nodes);
    return result;
  },
  /**
   * 渲染分页组件
   */
  renderPagination: function() {
    if (!this.$pagination) return;
    this.$pagination.empty();
    var totalItems = this.total || 0;
    var totalPages = totalItems > 0 ? Math.ceil(totalItems / this.pageSize) : 0;
    if (totalItems === 0 && !this.settings.showTotal) {
      this.$pagination.hide();
      return;
    } else {
      this.$pagination.show();
    }
    if (this.settings.showTotal) {
      var $info = $3('<span class="ws-select-pagination-info"></span>');
      var infoText = "共 " + totalItems + " 条";
      $info.text(infoText);
      this.$pagination.append($info);
    }
    if (totalPages <= 0) {
      return;
    }
    var $controls = $3('<div class="ws-select-pagination-controls"></div>');
    var $prevBtn = $3('<button class="ws-select-pagination-btn" type="button" title="上一页" role="prev"><i class="bi bi-chevron-left"></i></button>');
    if (this.currentPage <= 1) {
      $prevBtn.prop("disabled", true);
    }
    $controls.append($prevBtn);
    $controls.append(`<span class="ws-select-pagination-info">${this.currentPage} / ${totalPages}</span>`);
    var $nextBtn = $3('<button class="ws-select-pagination-btn" type="button" title="下一页" role="next"><i class="bi bi-chevron-right"></i></button>');
    if (this.currentPage >= totalPages) {
      $nextBtn.prop("disabled", true);
    }
    $controls.append($nextBtn);
    if (this.settings.showSizeChanger) {
      var $sizeSelect = $3('<select class="ws-select-pagination-size"></select>');
      this.settings.pageSizeOptions.forEach(function(size) {
        var $option = $3('<option value="' + size + '">' + size + " 条/页</option>");
        if (size === this.pageSize) {
          $option.prop("selected", true);
        }
        $sizeSelect.append($option);
      }.bind(this));
      $controls.append($sizeSelect);
    }
    this.$pagination.append($controls);
  },
  /**
   * 绑定事件处理函数
   */
  bindEvents: function() {
    var self = this;
    this.$trigger.on("click", function(e) {
      if (!self.settings.disabled) {
        self.toggleDropdown();
      }
    });
    if (this.$clearBtn) {
      this.$clearBtn.on("click", function(e) {
        e.stopPropagation();
        self.clear();
      });
    }
    if (this.$search) {
      this.$search.find("input").on("input", function() {
        var searchValue = $3(this).val();
        self.handleSearch(searchValue);
      });
    }
    this.$optionsList.on("click", ".ws-select-option:not(.disabled)", function(e) {
      var option = $3(this).data("option");
      self.selectOption(option);
      if (!self.settings.multiple) {
        self.closeDropdown();
      } else {
        e.stopPropagation();
      }
    });
    this.$optionsList.on("click", ".ws-select-tree-node-content:not(.disabled)", function(e) {
      e.stopPropagation();
      var node = $3(this).data("node");
      self.selectTreeNode(node);
      if (!self.settings.multiple) {
        self.closeDropdown();
      }
    });
    this.$optionsList.on("click", ".ws-select-tree-node-toggle", function(e) {
      e.stopPropagation();
      var node = $3(this).data("node");
      self.toggleTreeNode(node);
    });
    this.$valueDisplay.on("click", ".ws-select-tag-remove", function(e) {
      e.stopPropagation();
      var $tag = $3(this).closest(".ws-select-tag");
      var value = $tag.data("value");
      self.deselectOption(value);
    });
    $3(document).on("click.wsSelect", function(e) {
      if (!$3(e.target).closest(".ws-select-container").is(self.$element)) {
        self.closeDropdown();
      }
    });
    if (this.$pagination) {
      this.$pagination.on("click", ".ws-select-pagination-btn", function(e) {
        e.stopPropagation();
        var $btn = $3(this);
        if ($btn.prop("disabled")) return;
        var isPrev = $btn.attr("role") === "prev";
        var isNext = $btn.attr("role") === "next";
        if (isPrev && self.currentPage > 1) {
          self.goToPage(self.currentPage - 1);
        } else if (isNext && self.currentPage < Math.ceil(self.total / self.pageSize)) {
          self.goToPage(self.currentPage + 1);
        }
      });
      this.$pagination.on("click", ".ws-select-pagination-page:not(.disabled):not(.active)", function(e) {
        e.stopPropagation();
        var page = $3(this).data("page");
        if (page) {
          self.goToPage(parseInt(page));
        }
      });
      this.$pagination.on("change", ".ws-select-pagination-size", function(e) {
        e.stopPropagation();
        var newSize = parseInt($3(this).val());
        if (newSize !== self.pageSize && !isNaN(newSize)) {
          self.pageSize = newSize;
          self.currentPage = 1;
          if (self.settings.remoteUrl || typeof self.settings.remoteMethod === "function") {
            self.loadRemoteData(self.searchValue);
          } else {
            self.updateOptionsList();
            self.renderPagination();
          }
        }
      });
    }
    this.$element.on("keydown", function(e) {
      self.handleKeyDown(e);
    });
  },
  /**
   * 处理搜索输入
   * @param {string} searchValue - 搜索关键词
   */
  handleSearch: function(searchValue) {
    this.searchValue = searchValue;
    this.currentPage = 1;
    if (this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") {
      if (this.remoteSearchTimer) {
        clearTimeout(this.remoteSearchTimer);
      }
      this.remoteSearchTimer = setTimeout(function() {
        if (searchValue.length >= this.settings.minSearchLength || searchValue.length === 0) {
          this.loadRemoteData(searchValue);
        }
      }.bind(this), typeof this.settings.remoteDelay === "number" ? this.settings.remoteDelay : 300);
    } else {
      if (this.settings.treeMode && searchValue.length === 0) {
        if (this.settings.treeExpandAll) {
          this.expandAllNodes();
        } else {
          this.expandedKeys.clear();
        }
      }
      this.updateOptionsList();
      if (this.settings.pagination) {
        this.renderPagination();
      }
    }
    if (typeof this.settings.onSearch === "function") {
      this.settings.onSearch(searchValue);
    }
  },
  /**
   * 加载远程数据
   * @param {string} searchValue - 搜索关键词
   */
  loadRemoteData: function(searchValue) {
    var self = this;
    this.isLoading = true;
    var requestData = $3.extend(true, {}, this.settings.ajaxOptions.data || {});
    if (searchValue) {
      requestData.search = searchValue;
      requestData.q = searchValue;
    }
    if (this.settings.pagination) {
      var mapping = this.settings.paginationFieldMapping;
      requestData[mapping.page] = this.currentPage;
      requestData[mapping.pageSize] = this.pageSize;
    }
    var ajaxOptions = $3.extend(true, {
      url: this.settings.remoteUrl,
      method: "GET",
      data: requestData,
      dataType: "json"
    }, this.settings.ajaxOptions);
    var request;
    if (typeof this.settings.remoteMethod === "function") {
      request = this.settings.remoteMethod(searchValue, this.currentPage, this.pageSize);
      if (request && typeof request.then === "function") {
      } else {
        request = Promise.resolve(request);
      }
    } else if (this.settings.remoteUrl) {
      var ajaxOptions = $3.extend(true, {
        url: this.settings.remoteUrl,
        method: "GET",
        data: requestData,
        dataType: "json"
      }, this.settings.ajaxOptions);
      request = $3.ajax(ajaxOptions);
    } else {
      this.isLoading = false;
      this.updateOptionsList();
      return;
    }
    var handleSuccess = function(response) {
      var mapping2 = self.settings.paginationFieldMapping;
      var data3 = self.getNestedValue(response, mapping2.data) || response.results || response.list || response.items || response;
      if (self.settings.pagination) {
        var total = self.getNestedValue(response, mapping2.total) || response.total || response.totalCount || response.totalRecords || response[mapping2.total + "Count"] || response[mapping2.total + "Records"] || (Array.isArray(data3) ? data3.length : 0);
        total = parseInt(total) || 0;
        self.total = total;
      }
      if (self.settings.treeMode) {
        self.settings.options = data3;
        self.treeData = self.processTreeData();
      } else {
        self.remoteData = self.transformRemoteData(data3);
      }
      self.isLoading = false;
      self.updateOptionsList();
      if (self.settings.pagination) {
        self.renderPagination();
      }
    };
    var handleError = function(xhr, status, error) {
      console.error("远程数据加载失败:", error);
      self.remoteData = [];
      self.isLoading = false;
      self.updateOptionsList();
      if (self.settings.pagination) {
        self.renderPagination();
      }
      if (typeof self.settings.onError === "function") {
        self.settings.onError(xhr, status, error);
      }
    };
    if (request && typeof request.then === "function") {
      request.then(handleSuccess).catch(function(error) {
        handleError(null, "error", error);
      });
    } else if (request && typeof request.done === "function") {
      request.done(handleSuccess).fail(handleError);
    } else {
      handleSuccess(request);
    }
  },
  /**
   * 获取嵌套对象的属性值
   * @param {*} obj - 源对象
   * @param {string} path - 属性路径（例如：'a.b.c'）
   * @returns {*} 属性值或undefined
   */
  getNestedValue: function(obj, path) {
    return path.split(".").reduce(function(current, key) {
      return current && current[key] !== void 0 ? current[key] : void 0;
    }, obj);
  },
  /**
   * 转换远程数据格式
   * @param {*} response - 远程响应数据
   * @returns {Array} 转换后的选项数组
   */
  transformRemoteData: function(response) {
    var data3 = response;
    if (response && response.data !== void 0) {
      data3 = response.data;
    }
    if (response && response.results !== void 0) {
      data3 = response.results;
    }
    if (!Array.isArray(data3)) {
      data3 = [data3];
    }
    return data3.map(function(item) {
      var mappedItem = {};
      var mapping = typeof this.settings.fieldMapping === "object" ? this.settings.fieldMapping : {};
      for (var key in mapping) {
        if (mapping.hasOwnProperty(key)) {
          var sourceField = mapping[key];
          if (item.hasOwnProperty(sourceField)) {
            mappedItem[key] = item[sourceField];
          } else {
            mappedItem[key] = null;
          }
        }
      }
      if (!mappedItem.value && item) {
        mappedItem.value = item.id || item.value || Math.random().toString(36).substring(2, 9);
      }
      if (!mappedItem.label && item) {
        mappedItem.label = item.name || item.label || "未知选项";
      }
      return mappedItem;
    }.bind(this));
  },
  /**
   * 跳转到指定页码
   * @param {number} page - 页码
   */
  goToPage: function(page) {
    if (page === this.currentPage || page < 1) return;
    var totalPages = Math.ceil(this.total / this.pageSize) || 1;
    if (page > totalPages) {
      page = totalPages;
    }
    this.currentPage = page;
    if (this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") {
      this.loadRemoteData(this.searchValue);
    } else {
      this.updateOptionsList();
      this.renderPagination();
    }
  },
  /**
   * 选择树形节点
   * @param {Object} node - 树节点
   */
  selectTreeNode: function(node) {
    if (node.disabled || this.settings.disabled) return;
    if (this.settings.treeCheckable && this.settings.multiple) {
      var index = this.selectedValues.indexOf(node.value);
      var wasSelected = index !== -1;
      if (this.settings.treeCheckStrictly) {
        if (wasSelected) {
          this.selectedValues.splice(index, 1);
        } else {
          this.selectedValues.push(node.value);
        }
      } else {
        if (wasSelected) {
          this.selectedValues.splice(index, 1);
          this.deselectAllChildren(node);
        } else {
          this.selectedValues.push(node.value);
          this.selectAllChildren(node);
        }
        this.updateParentNodes(node);
      }
    } else {
      this.selectedValues = node.value;
    }
    this.updateValueDisplay();
    this.updateOptionsList();
    if (this.settings.onChange) {
      this.settings.onChange(this.selectedValues, node);
    }
  },
  /**
   * 递归选中所有子节点
   * @param {Object} node - 父节点
   */
  selectAllChildren: function(node) {
    if (!node.children || node.children.length === 0) return;
    node.children.forEach(function(child) {
      if (!child.disabled && this.selectedValues.indexOf(child.value) === -1) {
        this.selectedValues.push(child.value);
      }
      this.selectAllChildren(child);
    }.bind(this));
  },
  /**
   * 递归取消选中所有子节点
   * @param {Object} node - 父节点
   */
  deselectAllChildren: function(node) {
    if (!node.children || node.children.length === 0) return;
    node.children.forEach(function(child) {
      var index = this.selectedValues.indexOf(child.value);
      if (index !== -1) {
        this.selectedValues.splice(index, 1);
      }
      this.deselectAllChildren(child);
    }.bind(this));
  },
  /**
   * 更新父节点选择状态（非严格模式）
   * @param {Object} node - 当前节点
   */
  updateParentNodes: function(node) {
    if (this.settings.treeCheckStrictly) return;
    var updateParent = function(childNode) {
      var parent2 = this.findParentNode(childNode);
      if (parent2) {
        this.updateNodeSelectionState(parent2);
        updateParent.call(this, parent2);
      }
    }.bind(this);
    updateParent(node);
  },
  /**
   * 查找节点的父节点
   * @param {Object} childNode - 子节点
   * @returns {Object|null} 父节点
   */
  findParentNode: function(childNode) {
    var result = null;
    var searchParent = function(nodes, parent2) {
      for (var i = 0; i < nodes.length; i++) {
        var node = nodes[i];
        if (node.value === childNode.value) {
          return parent2;
        }
        if (node.children && node.children.length > 0) {
          var found = searchParent(node.children, node);
          if (found) return found;
        }
      }
      return null;
    };
    return searchParent(this.treeData, null);
  },
  /**
   * 更新节点的选择状态（考虑半选状态）
   * @param {Object} node - 节点
   */
  updateNodeSelectionState: function(node) {
    if (!node.children || node.children.length === 0) return;
    var allChildrenSelected = true;
    var someChildrenSelected = false;
    var checkChildren = function(children) {
      children.forEach(function(child) {
        var isSelected = this.selectedValues.indexOf(child.value) !== -1;
        if (isSelected) someChildrenSelected = true;
        if (!isSelected) allChildrenSelected = false;
        if (child.children && child.children.length > 0) {
          checkChildren.call(this, child.children);
        }
      }.bind(this));
    }.bind(this);
    checkChildren(node.children);
    var nodeIndex = this.selectedValues.indexOf(node.value);
    if (allChildrenSelected && node.children.length > 0) {
      if (nodeIndex === -1) {
        this.selectedValues.push(node.value);
      }
    } else if (someChildrenSelected) {
    } else {
      if (nodeIndex !== -1) {
        this.selectedValues.splice(nodeIndex, 1);
      }
    }
  },
  /**
   * 切换树形节点展开/折叠
   * @param {Object} node - 树节点
   */
  toggleTreeNode: function(node) {
    if (this.expandedKeys.has(node.value)) {
      this.expandedKeys.delete(node.value);
    } else {
      this.expandedKeys.add(node.value);
    }
    this.updateOptionsList();
  },
  /**
   * 切换下拉菜单显示状态
   */
  toggleDropdown: function() {
    if (this.isOpen) {
      this.closeDropdown();
    } else {
      this.openDropdown();
    }
  },
  /**
   * 打开下拉菜单
   */
  openDropdown: function() {
    this.isOpen = true;
    this.$dropdown.addClass("show");
    if (this.$arrow) {
      this.$arrow.addClass("rotated");
    }
    if (this.settings.searchable || this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") {
      this.$search.find("input").focus();
    }
    if (typeof this.settings.onFocus === "function") {
      this.settings.onFocus();
    }
  },
  /**
   * 关闭下拉菜单
   */
  closeDropdown: function() {
    if (!this.$dropdown.hasClass("show")) {
      return;
    }
    this.isOpen = false;
    this.$dropdown.removeClass("show");
    if (this.$arrow) {
      this.$arrow.removeClass("rotated");
    }
    if (this.settings.autoClearSearchValue && (this.settings.searchable || this.settings.remoteUrl || typeof this.settings.remoteMethod === "function")) {
      this.searchValue = "";
      this.$search.find("input").val("");
      if (!this.settings.remoteUrl && typeof this.settings.remoteMethod !== "function") {
        this.updateOptionsList();
      } else {
        this.handleSearch("");
      }
    }
    this.focusedIndex = -1;
    if (typeof this.settings.onBlur === "function") {
      this.settings.onBlur();
    }
  },
  /**
   * 选择选项
   * @param {Object} option - 要选择的选项对象
   */
  selectOption: function(option) {
    if (this.settings.multiple) {
      if (!this.selectedValues.includes(option.value)) {
        this.selectedValues.push(option.value);
      } else {
        this.selectedValues = this.selectedValues.filter(function(v) {
          return v !== option.value;
        });
      }
    } else {
      this.selectedValues = option.value;
    }
    this.updateValueDisplay();
    this.updateOptionsList();
    if (typeof this.settings.onChange === "function") {
      this.settings.onChange(this.selectedValues, option);
    }
  },
  /**
   * 取消选择选项
   * @param {*} value - 要取消选择的选项值
   */
  deselectOption: function(value) {
    if (this.settings.multiple) {
      this.selectedValues = this.selectedValues.filter(function(v) {
        return v !== value;
      });
      this.updateValueDisplay();
      this.updateOptionsList();
      if (typeof this.settings.onChange === "function") {
        this.settings.onChange(this.selectedValues, null);
      }
    }
  },
  /**
   * 清除所有选中项
   */
  clear: function() {
    this.selectedValues = this.settings.multiple ? [] : null;
    this.updateValueDisplay();
    this.updateOptionsList();
    if (typeof this.settings.onChange === "function") {
      this.settings.onChange(this.selectedValues, null);
    }
  },
  /**
   * 处理键盘事件
   * @param {Event} e - 键盘事件对象
   */
  handleKeyDown: function(e) {
    if (!this.isOpen) {
      if (e.key === "Enter" || e.key === " " || e.key === "ArrowDown") {
        e.preventDefault();
        this.openDropdown();
      }
      return;
    }
    var options2 = this.$optionsList.find(".ws-select-option:not(.disabled)");
    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        this.focusedIndex = this.focusedIndex < options2.length - 1 ? this.focusedIndex + 1 : 0;
        this.updateFocus();
        break;
      case "ArrowUp":
        e.preventDefault();
        this.focusedIndex = this.focusedIndex > 0 ? this.focusedIndex - 1 : options2.length - 1;
        this.updateFocus();
        break;
      case "Enter":
        e.preventDefault();
        if (this.focusedIndex >= 0) {
          var option = $3(options2[this.focusedIndex]).data("option");
          this.selectOption(option);
          if (!this.settings.multiple) {
            this.closeDropdown();
          }
        }
        break;
      case "Escape":
        e.preventDefault();
        this.closeDropdown();
        break;
      case "Tab":
        this.closeDropdown();
        break;
    }
  },
  /**
   * 更新键盘导航焦点
   */
  updateFocus: function() {
    this.$optionsList.find(".ws-select-option").removeClass("focused");
    if (this.focusedIndex >= 0) {
      var $option = this.$optionsList.find(".ws-select-option:not(.disabled)").eq(this.focusedIndex);
      $option.addClass("focused");
      $option[0].scrollIntoView({ block: "nearest" });
    }
  },
  /**
   * 获取当前选中的值
   * @returns {*|Array} 当前选中的值
   */
  getValue: function() {
    return this.selectedValues;
  },
  /**
   * 设置选中的值
   * @param {*|Array} value - 要设置的值
   */
  setValue: function(value) {
    this.selectedValues = this.settings.multiple ? Array.isArray(value) ? value : [value] : value;
    this.updateValueDisplay();
    this.updateOptionsList();
  },
  /**
   * 获取隐藏input的DOM元素
   * @returns {HTMLElement|null} 隐藏input元素
   */
  getHiddenInput: function() {
    return this.hiddenInput ? this.hiddenInput[0] : null;
  },
  /**
   * 获取隐藏input的值
   * @returns {string} 隐藏input的值
   */
  getHiddenInputValue: function() {
    return this.hiddenInput ? this.hiddenInput.val() : "";
  },
  /**
   * 同步到原始select元素
   */
  syncToOriginalSelect: function() {
    var originalSelect = this.$element.find("select").first();
    if (originalSelect.length) {
      if (this.settings.multiple) {
        originalSelect.val(this.selectedValues || []);
      } else {
        originalSelect.val(this.selectedValues || "");
      }
    }
  },
  /**
   * 从原始select元素同步
   */
  syncFromOriginalSelect: function() {
    var originalSelect = this.$element.find("select").first();
    if (originalSelect.length) {
      if (this.settings.multiple) {
        this.selectedValues = originalSelect.val() || [];
      } else {
        this.selectedValues = originalSelect.val() || null;
      }
      this.updateValueDisplay();
      this.updateOptionsList();
    }
  },
  /**
   * 重新创建隐藏input
   * @param {Object} options - 新的配置选项
   */
  recreateHiddenInput: function(options2) {
    if (options2) {
      $3.extend(true, this.settings, options2);
    }
    this.createHiddenInput();
  },
  /**
   * 启用组件
   */
  enable: function() {
    this.settings.disabled = false;
    this.$trigger.removeClass("disabled");
    this.updateOptionsList();
  },
  /**
   * 禁用组件
   */
  disable: function() {
    this.settings.disabled = true;
    this.$trigger.addClass("disabled");
    this.closeDropdown();
    this.updateOptionsList();
  },
  /**
   * 更新选项数据
   * @param {Array} newOptions - 新的选项数据
   */
  updateOptions: function(newOptions) {
    if (newOptions) {
      this.localOptions = this.transformRemoteData(newOptions || []);
      if (this.settings.treeMode) {
        this.settings.options = this.localOptions;
        this.treeData = this.processTreeData();
      }
    }
    this.updateOptionsList();
    if (this.$pagination) {
      this.renderPagination();
    }
  },
  /**
   * 设置显示模式
   * @param {string} mode - 显示模式，'default' 或 'group'
   */
  setDisplayMode: function(mode) {
    this.settings.displayMode = mode;
    this.$element.removeClass("ws-display-mode-default ws-display-mode-group").addClass("ws-display-mode-" + mode);
    this.updateOptionsList();
  },
  /**
   * 设置选择模式
   * @param {boolean} isMultiple - 是否多选
   */
  setMultiple: function(isMultiple) {
    this.settings.multiple = isMultiple;
    if (!isMultiple && Array.isArray(this.selectedValues) && this.selectedValues.length > 1) {
      this.selectedValues = this.selectedValues[0];
    } else if (isMultiple && !Array.isArray(this.selectedValues)) {
      this.selectedValues = this.selectedValues ? [this.selectedValues] : [];
    }
    this.updateValueDisplay();
    this.updateOptionsList();
  },
  /**
   * 重新加载远程数据
   * @param {string} searchValue - 搜索关键词
   */
  reloadRemoteData: function(searchValue) {
    if (this.settings.remoteUrl || typeof this.settings.remoteMethod === "function") {
      this.loadRemoteData(searchValue || this.searchValue);
    }
  },
  /**
   * 设置树形模式
   * @param {boolean} enable - 是否启用树形模式
   */
  setTreeMode: function(enable) {
    this.settings.treeMode = enable;
    if (enable) {
      this.$element.addClass("ws-select-tree-mode");
      this.processTreeData();
      if (this.settings.treeExpandAll) {
        this.expandAllNodes();
      }
    } else {
      this.$element.removeClass("ws-select-tree-mode");
    }
    this.updateOptionsList();
  },
  /**
   * 设置最后一级布局方式
   * @param {string} layout - 'vertical' 或 'horizontal'
   */
  setLastLevelLayout: function(layout) {
    this.settings.lastLevelLayout = layout;
    this.$element.removeClass("last-level-horizontal").addClass(layout === "horizontal" ? "last-level-horizontal" : "");
    this.updateOptionsList();
  },
  /**
   * 展开所有树节点
   */
  expandAll: function() {
    if (this.settings.treeMode) {
      this.expandedKeys.clear();
      this.expandAllNodes();
      this.updateOptionsList();
    }
  },
  /**
   * 折叠所有树节点
   */
  collapseAll: function() {
    if (this.settings.treeMode) {
      this.expandedKeys.clear();
      this.updateOptionsList();
    }
  },
  /**
   * 销毁组件实例，清理事件绑定和DOM元素
   */
  destroy: function() {
    $3(document).off(".wsSelect");
    this.$element.off();
    this.$element.empty();
    this.$element.removeData("wsSelect");
    return null;
  }
};
function parseConfigFromElement(element) {
  var $el = $3(element);
  var config2 = {};
  var dataAttrs = [
    "options",
    "value",
    "defaultValue",
    "multiple",
    "disabled",
    "searchable",
    "clearable",
    "placeholder",
    "size",
    "loading",
    "allowClear",
    "showArrow",
    "dropdownMatchSelectWidth",
    "maxOptionHeight",
    "remoteUrl",
    "url",
    "fieldMapping",
    "displayMode",
    "remoteDelay",
    "minSearchLength",
    "ajaxOptions",
    "filterOption",
    "pagination",
    "pageSize",
    "pageSizeOptions",
    "paginationFieldMapping",
    "showTotal",
    "showSizeChanger",
    "treeMode",
    "treeFieldMapping",
    "treeExpandAll",
    "lastLevelLayout",
    "treeCheckable",
    "treeCheckStrictly",
    "name",
    "inputName",
    "id",
    "inputId",
    "required",
    "valueSeparator",
    "useJsonValue",
    "autoCreateInput"
  ];
  dataAttrs.forEach(function(attr2) {
    var value = $el.data(attr2);
    if (value !== void 0) {
      config2[attr2] = value;
    }
  });
  [
    "options",
    "fieldMapping",
    "ajaxOptions",
    "paginationFieldMapping",
    "pageSizeOptions",
    "treeFieldMapping"
  ].forEach(function(key) {
    var value = $el.data(key);
    if (typeof value === "string") {
      try {
        config2[key] = JSON.parse(value);
      } catch (e) {
        console.warn("Failed to parse data-" + key + " as JSON:", e);
      }
    }
  });
  [
    "multiple",
    "disabled",
    "searchable",
    "clearable",
    "loading",
    "allowClear",
    "showArrow",
    "dropdownMatchSelectWidth",
    "pagination",
    "showTotal",
    "showSizeChanger",
    "treeMode",
    "treeExpandAll",
    "treeCheckable",
    "treeCheckStrictly",
    "required",
    "useJsonValue",
    "autoCreateInput"
  ].forEach(function(key) {
    var value = $el.data(key);
    if (typeof value === "string") {
      config2[key] = value === "true" || value === "1";
    }
  });
  ["remoteDelay", "minSearchLength", "pageSize"].forEach(function(key) {
    var value = $el.data(key);
    if (typeof value === "string") {
      var numValue = parseFloat(value);
      if (!isNaN(numValue)) {
        config2[key] = numValue;
      }
    }
  });
  [
    "onChange",
    "onSearch",
    "onFocus",
    "onBlur",
    "onSelectOptionRender",
    "onSelectValueRender",
    "remoteMethod",
    "filterOption"
  ].forEach(function(key) {
    var value = $el.data(key);
    if (typeof value === "string" && window[value]) {
      config2[key] = window[value];
    }
  });
  config2["remoteUrl"] = config2["remoteUrl"] || config2["url"];
  return config2;
}
WsSelect.autoInitialize = function(selector) {
  selector = selector || '[data-role="wsselect"]';
  $3(selector).each(function() {
    if (!$3(this).data("wsSelect")) {
      var config2 = parseConfigFromElement(this);
      $3(this).wsSelect(config2);
    }
  });
};
$3.fn.wsSelect = function(options2) {
  if (typeof options2 === "string") {
    var args = Array.prototype.slice.call(arguments, 1);
    var methodName = options2;
    var hasReturnValue = false;
    var returnValue;
    var firstInstance = this.first().data("wsSelect");
    if (firstInstance && typeof firstInstance[methodName] === "function") {
      returnValue = firstInstance[methodName].apply(firstInstance, args);
      hasReturnValue = returnValue !== void 0 && returnValue !== firstInstance;
    }
    if (hasReturnValue) {
      return returnValue;
    } else {
      this.each(function(index, element) {
        if (index > 0) {
          var instance = $3(element).data("wsSelect");
          if (instance && typeof instance[methodName] === "function") {
            instance[methodName].apply(instance, args);
          }
        }
      });
      return this;
    }
  }
  return this.each(function() {
    if (!$3(this).data("wsSelect")) {
      $3(this).data("wsSelect", new WsSelect(this, options2));
    }
  });
};
$3.fn.autoInitializeWsSelect = function() {
  return this.each(function() {
    if ($3(this).data("role") === "wsselect" && !$3(this).data("wsSelect")) {
      var config2 = parseConfigFromElement(this);
      $3(this).wsSelect(config2);
    }
  });
};
$3(function() {
  WsSelect.autoInitialize();
  $3("select[data-convert-to-wsselect]").each(function() {
    var $select = $3(this);
    var $container = $3("<div></div>");
    var attrs = $select[0].attributes;
    for (var i = 0; i < attrs.length; i++) {
      var attr2 = attrs[i];
      if (!["data-convert-to-wsselect", "class"].includes(attr2.name)) {
        $container.attr(attr2.name, attr2.value);
      }
    }
    $container.data("role", "wsselect");
    var options2 = [];
    var groups = [];
    $select.children().each(function() {
      if (this.tagName === "OPTGROUP") {
        var $group = $3(this);
        var groupLabel = $group.attr("label");
        groups.push(groupLabel);
        $group.children("option").each(function() {
          var $option2 = $3(this);
          options2.push({
            value: $option2.val(),
            label: $option2.text(),
            group: groupLabel,
            disabled: $option2.prop("disabled")
          });
        });
      } else if (this.tagName === "OPTION") {
        var $option = $3(this);
        options2.push({
          value: $option.val(),
          label: $option.text(),
          disabled: $option.prop("disabled")
        });
      }
    });
    $container.attr("data-options", JSON.stringify(options2));
    if ($select.prop("multiple")) {
      $container.attr("data-multiple", "true");
    }
    if ($select.prop("disabled")) {
      $container.attr("data-disabled", "true");
    }
    var selectedValues = $select.val();
    if (selectedValues !== null && selectedValues !== "") {
      if ($select.prop("multiple") && Array.isArray(selectedValues)) {
        $container.attr("data-value", JSON.stringify(selectedValues));
        $container.attr("data-use-json-value", "true");
      } else {
        $container.attr("data-value", selectedValues);
      }
    }
    if (!$select.attr("placeholder")) {
      var firstOption = $select.find("option").first();
      if (firstOption.length && firstOption.val() === "") {
        $container.attr("data-placeholder", firstOption.text());
      }
    }
    $select.wrap($container);
    var $wrapper = $select.parent();
    $wrapper.autoInitializeWsSelect();
  });
});
window.WsSelect = WsSelect;
var ws_select_default = WsSelect;

// public/statics/libs/ws-upload/ws-upload.js
var $4 = window.jQuery;
if (typeof $4 === "undefined") {
  throw new Error("WsUpload requires jQuery");
}
if (typeof window.bootstrap === "undefined") {
  console.warn("WsUpload: Bootstrap 5 is recommended for full functionality");
}
var DEFAULTS2 = {
  // 基础配置
  url: "",
  // 上传接口URL
  method: "post",
  // 请求方法 可选：post, put
  field: "file",
  // 上传文件的字段名
  multiple: false,
  // 是否支持多文件上传
  accept: "file",
  // 接受的文件类型
  acceptMime: "",
  // 接受的MIME类型
  exts: "",
  // 允许的文件扩展名
  maxSize: 0,
  // 最大文件大小（字节）
  maxFiles: 0,
  // 最大上传文件数
  autoUpload: true,
  // 是否自动上传
  chunkedUpload: false,
  // 是否启用分块上传
  chunkSize: 1024 * 1024,
  // 分块上传大小（字节）
  retryAttempts: 3,
  // 上传失败重试次数
  parallelUploads: 3,
  // 并行上传数
  // 外观配置
  theme: "default",
  // 主题样式 可选：default, drag, minimal
  showPreview: true,
  // 是否显示预览
  showFileList: true,
  // 是否显示文件列表
  previewType: "list",
  // 预览类型（list 或 grid）
  listLayout: "",
  // 列表版式：''(默认展开) | 'compact'(紧凑表格行) | 'mini-queue'(上传中收进迷你队列)
  dragDrop: true,
  // 是否启用拖拽上传
  showStatistics: true,
  // 是否显示上传统计
  showActions: true,
  // 是否显示操作按钮
  // 操作按钮配置 未实现
  showAttach: true,
  // 是否显示选择附件按钮
  showCancel: true,
  // 是否显示取消按钮
  showDelete: true,
  // 是否显示删除按钮
  showRetry: true,
  // 是否显示重试按钮
  // 文本配置
  browseText: '<i class="bi bi-cloud-arrow-up"></i> 选择文件',
  // 选择文件按钮文本
  attachText: "选择附件",
  // 选择附件按钮文本
  dragText: "拖拽文件到此处或<u>点击选择文件</u>",
  // 拖拽上传提示文本
  dropText: "释放文件上传",
  // 释放文件上传提示文本
  uploadText: "开始上传",
  // 开始上传按钮文本
  cancelText: "取消上传",
  // 取消上传按钮文本
  deleteText: "删除",
  // 删除按钮文本
  clearText: "清空列表",
  // 清空列表按钮文本
  retryText: "重试",
  // 重试按钮文本
  // 验证消息
  messages: {
    maxFiles: "最多只能上传 {maxFiles} 个文件",
    maxSize: "文件大小不能超过 {maxSize}",
    invalidExt: "不支持的文件格式: {file}",
    invalidType: "不支持的文件类型: {file}",
    uploadFailed: "上传失败: {file}",
    uploadSuccess: "上传成功: {file}",
    networkError: "网络错误，请检查连接",
    serverError: "服务器错误: {status}"
  },
  // 回调函数
  onInit: null,
  // 初始化时调用
  onSelect: null,
  // 选择文件时调用
  onRemove: null,
  // 删除文件时调用
  beforeUpload: null,
  // 上传前调用
  onProgress: null,
  // 上传进度时调用
  onSuccess: null,
  // 上传成功时调用
  onError: null,
  // 上传失败时调用
  onComplete: null,
  // 上传完成时调用
  onChunkUpload: null,
  // 分块上传时调用
  onChunkSuccess: null,
  // 分块上传成功时调用
  onChunkError: null,
  // 分块上传失败时调用
  // 额外数据
  data: {},
  headers: {},
  // 服务器响应配置
  responseType: "json",
  // 服务器响应类型
  successField: "success",
  // 成功标识字段名
  messageField: "message",
  // 消息字段名
  dataField: "data"
  // 数据字段名
};
var FILE_TYPES = {
  images: {
    mime: "image/*",
    exts: "jpg,jpeg,png,gif,bmp,webp,svg",
    icon: "bi bi-file-image",
    color: "#198754"
  },
  video: {
    mime: "video/*",
    exts: "mp4,avi,mov,wmv,flv,mkv,webm",
    icon: "bi bi-file-play",
    color: "#dc3545"
  },
  audio: {
    mime: "audio/*",
    exts: "mp3,wav,ogg,flac,aac,m4a",
    icon: "bi bi-file-music",
    color: "#6f42c1"
  },
  document: {
    mime: "application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain",
    exts: "pdf,doc,docx,txt,rtf",
    icon: "bi bi-file-text",
    color: "#fd7e14"
  },
  archive: {
    mime: "application/zip,application/x-rar-compressed,application/x-7z-compressed",
    exts: "zip,rar,7z,tar,gz",
    icon: "bi bi-file-zip",
    color: "#6c757d"
  },
  file: {
    mime: "*/*",
    exts: "",
    icon: "bi bi-file-earmark",
    color: "#0d6efd"
  }
};
var FILE_ICONS = {
  pdf: "bi bi-file-pdf text-danger",
  doc: "bi bi-file-word text-primary",
  docx: "bi bi-file-word text-primary",
  txt: "bi bi-file-text text-secondary",
  rtf: "bi bi-file-text text-info",
  zip: "bi bi-file-zip text-warning",
  rar: "bi bi-file-zip text-warning",
  "7z": "bi bi-file-zip text-warning",
  tar: "bi bi-file-zip text-warning",
  gz: "bi bi-file-zip text-warning",
  js: "bi bi-file-code text-warning",
  html: "bi bi-file-code text-danger",
  css: "bi bi-file-code text-primary",
  json: "bi bi-file-code text-warning",
  xml: "bi bi-file-code text-success",
  mp4: "bi bi-file-play text-danger",
  avi: "bi bi-file-play text-primary",
  mov: "bi bi-file-play text-success",
  mp3: "bi bi-file-music text-primary",
  wav: "bi bi-file-music text-info"
};
var WsUpload = function(element, options2) {
  this.element = $4(element);
  this.options = $4.extend(true, {}, DEFAULTS2, options2);
  this.files = [];
  this.uploadQueue = [];
  this.uploading = false;
  this.activeUploads = 0;
  this.completedUploads = 0;
  this.failedUploads = 0;
  this.xhrPool = [];
  this.$miniQueue = null;
  this._miniOpen = false;
  this.init();
};
WsUpload.prototype = {
  constructor: WsUpload,
  /**
   * 初始化组件
   */
  init: function() {
    this.render();
    this.bindEvents();
    this.updateStatistics();
    if (typeof this.options.onInit === "function") {
      this.options.onInit.call(this);
    }
  },
  /**
   * 渲染组件UI
   */
  render: function() {
    var options2 = this.options;
    var html = "";
    if (options2.theme === "default") {
      html = `
                    <div class="ws-upload-container">
                        <div class="d-flex gap-2 align-items-center mb-3">
                            <button type="button" class="btn btn-primary browse-btn">${options2.browseText}</button>
                            ${options2.showAttach ? `<button type="button" class="btn btn-info attach-btn">${options2.attachText}</button>` : ""}
                            ${options2.autoUpload ? "" : `<button type="button" class="btn btn-success upload-action-btn">${options2.uploadText}</button>`}
                            ${options2.showActions ? `<button type="button" class="btn btn-outline-danger clear-btn">${options2.clearText}</button>` : ""}
                        </div>
                        <input type="file" class="d-none" ${options2.multiple ? "multiple" : ""}>
                        ${options2.showFileList ? '<div class="ws-upload-preview mt-3"></div>' : ""}
                        ${this.renderStatistics()}
                    </div>
                `;
    } else if (options2.theme === "drag") {
      html = `
                    <div class="ws-upload-container">
                        <div class="ws-upload-dropzone">
                            <div class="ws-upload-dropzone-content pe-none">
                                <i class="bi bi-cloud-arrow-up display-4 text-muted mb-3"></i>
                                <h5>${options2.dragText}</h5>
                                <p class="text-muted mb-3">支持单个或多个文件上传</p>
                                <button type="button" class="btn btn-primary browse-btn pe-auto">${options2.browseText}</button>
                            </div>
                        </div>
                        <input type="file" class="d-none" ${options2.multiple ? "multiple" : ""}>
                        ${options2.showFileList ? '<div class="ws-upload-preview mt-3"></div>' : ""}
                        ${this.renderActions()}
                        ${this.renderStatistics()}
                    </div>
                `;
    } else if (options2.theme === "minimal") {
      html = `
                    <div class="ws-upload-container">
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary browse-btn">${options2.browseText}</button>
                            ${options2.autoUpload ? "" : '<button type="button" class="btn btn-sm btn-success upload-action-btn">' + options2.uploadText + "</button>"}
                        </div>
                        <input type="file" class="d-none" ${options2.multiple ? "multiple" : ""}>
                        ${options2.showFileList ? '<div class="ws-upload-preview mt-2"></div>' : ""}
                        ${this.renderStatistics()}
                    </div>
                `;
    }
    this.element.html(html);
    this.fileInput = this.element.find('input[type="file"]');
    this.previewContainer = this.element.find(".ws-upload-preview");
    this.dropzone = this.element.find(".ws-upload-dropzone");
    this.uploadBtn = this.element.find(".upload-action-btn");
    this.clearBtn = this.element.find(".clear-btn");
    this.browseBtn = this.element.find(".browse-btn");
    this.statisticsContainer = this.element.find(".ws-upload-statistics");
    if (this.options.previewType === "list") {
      if (this.options.listLayout === "compact") {
        this.previewContainer.addClass("ws-upload-compact");
      } else if (this.options.listLayout === "mini-queue") {
        this.previewContainer.addClass("ws-upload-mini");
      }
    }
    this.setAcceptAttribute();
  },
  /**
   * 渲染操作按钮区域
   * @returns {string} HTML 字符串
   */
  renderActions: function() {
    if (!this.options.showActions) return "";
    return `
                <div class="ws-upload-actions">
                    <div class="d-flex gap-2 align-items-center">
                        ${this.options.autoUpload ? "" : `<button type="button" class="btn btn-success upload-action-btn">${this.options.uploadText}</button>`}
                        ${this.options.showAttach ? `<button type="button" class="btn btn-info attach-btn">${this.options.attachText}</button>` : ""}
                        <button type="button" class="btn btn-outline-danger clear-btn">${this.options.clearText}</button>
                        ${this.options.chunkedUpload ? `<button type="button" class="btn btn-outline-warning cancel-btn">${this.options.cancelText}</button>` : ""}
                    </div>
                </div>
            `;
  },
  /**
   * 渲染统计信息区域
   * @returns {string} HTML 字符串
   */
  renderStatistics: function() {
    if (!this.options.showStatistics) return "";
    return `
                <div class="ws-upload-statistics">
                    <small>
                        <span class="total-files">文件总数: 0</span> | 
                        <span class="uploaded-files">已上传: 0</span> | 
                        <span class="failed-files">失败: 0</span> |
                        <span class="total-size">总大小: 0</span>
                    </small>
                </div>
            `;
  },
  /**
   * 设置文件输入框的 accept 属性
   */
  setAcceptAttribute: function() {
    var accept = this.options.accept;
    var exts = this.options.exts;
    var acceptMime = this.options.acceptMime;
    if (acceptMime) {
      this.fileInput.attr("accept", acceptMime);
      return;
    }
    if (accept !== "custom" && FILE_TYPES[accept]) {
      var fileType = FILE_TYPES[accept];
      var acceptValue = exts ? "." + exts.split(",").join(",.") : fileType.mime;
      this.fileInput.attr("accept", acceptValue);
    } else if (exts) {
      this.fileInput.attr("accept", "." + exts.split(",").join(",."));
    }
  },
  /**
   * 绑定事件监听器
   */
  bindEvents: function() {
    var self = this;
    this.browseBtn.on("click", function(e) {
      e.preventDefault();
      e.stopPropagation();
      self.fileInput.click();
    });
    this.fileInput.on("change", function(e) {
      self.handleFileSelect(e.target.files);
      $4(this).val("");
    });
    if (this.uploadBtn.length) {
      this.uploadBtn.on("click", function() {
        self.upload();
      });
    }
    if (this.clearBtn.length) {
      this.clearBtn.on("click", function() {
        self.clearFiles();
      });
    }
    if (this.options.dragDrop && this.dropzone.length) {
      this.dropzone.on("click", function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.fileInput.click();
      });
      this.dropzone.on("dragover", function(e) {
        e.preventDefault();
        e.stopPropagation();
        $4(this).addClass("is-dragover");
      });
      this.dropzone.on("dragleave", function(e) {
        e.preventDefault();
        e.stopPropagation();
        $4(this).removeClass("is-dragover");
      });
      this.dropzone.on("drop", function(e) {
        e.preventDefault();
        e.stopPropagation();
        $4(this).removeClass("is-dragover");
        self.handleFileSelect(e.originalEvent.dataTransfer.files);
      });
    }
    this.element.on("click", ".cancel-btn", function() {
      self.cancelUpload();
    });
    this.element.on("click", ".retry-btn", function() {
      var fileId = $4(this).closest(".ws-upload-file-item").data("file-id");
      self.retryUpload(fileId);
    });
  },
  /**
   * 处理文件选择
   * @param {FileList} fileList - 文件列表
   */
  handleFileSelect: function(fileList) {
    var files = Array.from(fileList);
    var validFiles = [];
    for (var i = 0; i < files.length; i++) {
      var file = files[i];
      if (this.validateFile(file)) {
        validFiles.push(file);
      }
    }
    if (validFiles.length === 0) return;
    this.addFiles(validFiles);
    if (typeof this.options.onSelect === "function") {
      this.options.onSelect.call(this, validFiles);
    }
    if (this.options.autoUpload) {
      this.upload();
    }
  },
  /**
   * 验证文件
   * @param {File} file - 文件对象
   * @returns {boolean} 是否通过验证
   */
  validateFile: function(file) {
    var options2 = this.options;
    var maxFiles = options2.maxFiles;
    var maxSize = options2.maxSize;
    var accept = options2.accept;
    var exts = options2.exts;
    var messages = options2.messages;
    if (maxFiles > 0 && this.files.length >= maxFiles) {
      this.showMessage(messages.maxFiles.replace("{maxFiles}", maxFiles), "warning");
      return false;
    }
    if (maxSize > 0 && file.size > maxSize) {
      var maxSizeText = this.formatFileSize(maxSize);
      this.showMessage(
        messages.maxSize.replace("{maxSize}", maxSizeText) + " - " + file.name,
        "error"
      );
      return false;
    }
    if (accept !== "file" && accept !== "custom") {
      var fileType = FILE_TYPES[accept];
      var fileExt = file.name.split(".").pop().toLowerCase();
      var allowedExts = exts ? exts.split(",") : fileType.exts.split(",");
      if (!allowedExts.includes(fileExt)) {
        this.showMessage(
          messages.invalidExt.replace("{file}", file.name),
          "error"
        );
        return false;
      }
    } else if (exts) {
      var fileExt = file.name.split(".").pop().toLowerCase();
      var allowedExts = exts.split(",");
      if (!allowedExts.includes(fileExt)) {
        this.showMessage(
          messages.invalidExt.replace("{file}", file.name),
          "error"
        );
        return false;
      }
    }
    return true;
  },
  /**
   * 添加文件到列表
   * @param {Array} files - 文件数组
   */
  addFiles: function(files) {
    for (var i = 0; i < files.length; i++) {
      var file = files[i];
      file.id = this.generateFileId();
      file.retryCount = 0;
      file.status = "pending";
      file.uploadedSize = 0;
      this.files.push(file);
      if (this.options.showFileList) {
        this.renderFileItem(file);
      }
    }
    this.updateStatistics();
  },
  /**
   * 渲染文件项
   * @param {File} file - 文件对象
   */
  renderFileItem: function(file) {
    var fileExt = file.name.split(".").pop().toLowerCase();
    var fileIcon = FILE_ICONS[fileExt] || FILE_TYPES[this.getFileType(file)].icon;
    var fileSize = this.formatFileSize(file.size);
    var options2 = this.options;
    var fileItem = $4(`
                <div class="ws-upload-file-item" data-file-id="${file.id}">
                    <div class="ws-upload-file-icon">
                        <i class="${fileIcon}"></i>
                    </div>
                    <div class="ws-upload-file-info">
                        <div class="ws-upload-file-name">${file.name}</div>
                        <div class="ws-upload-file-size">${fileSize}</div>
                        <div class="progress ws-upload-file-progress d-none">
                            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div class="ws-upload-status"></div>
                    </div>
                    <div class="ws-upload-file-actions">
                        ${options2.showRetry ? `<button type="button" class="btn btn-sm btn-outline-warning retry-btn d-none" title="重试上传"><i class="bi bi-arrow-clockwise"></i> ${options2.retryText}</button>` : ""}
                        <button type="button" class="btn btn-sm btn-outline-danger file-remove-btn" title="删除文件">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `);
    this.previewContainer.append(fileItem);
    fileItem.find(".file-remove-btn").on("click", function(e) {
      this.removeFile(file.id);
    }.bind(this));
    if (this.options.showPreview && this.isImageFile(file)) {
      this.renderImagePreview(file, fileItem);
    }
  },
  /**
   * 渲染图片预览
   * @param {File} file - 文件对象
   * @param {jQuery} fileItem - 文件项元素
   */
  renderImagePreview: function(file, fileItem) {
    var reader = new FileReader();
    var self = this;
    reader.onload = function(e) {
      if (self.options.previewType === "grid") {
        self.previewContainer.addClass("d-flex");
        var gridItem = $4(`
                        <div class="ws-upload-grid-item" data-file-id="${file.id}">
                            <img src="${e.target.result}" alt="${file.name}">
                            <div class="ws-upload-grid-item-overlay">
                                <button type="button" class="btn btn-light btn-sm preview-btn" title="预览">
                                    <i class="bi bi-eye"></i>
                                </button>
                                ${options.showRetry ? '<button type="button" class="btn btn-light btn-sm ms-1 retry-btn d-none" title="重试上传"><i class="bi bi-arrow-clockwise"></i></button>' : ""}
                                <button type="button" class="btn btn-light btn-sm ms-1 remove-btn" title="删除">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `);
        gridItem.find(".preview-btn").on("click", function() {
          self.previewImage(e.target.result, file.name);
        });
        gridItem.find(".remove-btn").on("click", function() {
          self.removeFile(file.id);
        });
        self.previewContainer.append(gridItem);
        fileItem.hide();
      } else {
        var previewBtn = $4(`
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2 preview-btn">
                            <i class="bi bi-eye"></i> 预览
                        </button>
                    `);
        previewBtn.on("click", function() {
          self.previewImage(e.target.result, file.name);
        });
        fileItem.find(".ws-upload-file-info").append(previewBtn);
      }
    };
    reader.readAsDataURL(file);
  },
  /**
   * 预览图片
   * @param {string} src - 图片数据URL
   * @param {string} filename - 文件名
   */
  previewImage: function(src, filename) {
    if (typeof bootstrap !== "undefined" && bootstrap.Modal) {
      var modalHtml = `
                    <div class="modal fade ws-upload-image-preview-modal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">${filename}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <img src="${src}" alt="${filename}" class="img-fluid">
                                </div>
                            </div>
                        </div>
                    </div>
                `;
      var modal = $4(modalHtml).appendTo("body");
      var bsModal = new bootstrap.Modal(modal[0]);
      bsModal.show();
      modal.on("hidden.bs.modal", function() {
        modal.remove();
      });
    } else {
      var newWindow = window.open();
      newWindow.document.write('<img src="' + src + '" alt="' + filename + '" style="max-width: 100%;">');
    }
  },
  // ==================== 迷你队列（mini-queue 版式） ====================
  /**
   * 确保迷你队列 DOM 存在。当配置 listLayout='mini-queue' 且当前有上传任务时，
   * 上传中文件会收进一个 fixed 在视口右下角的迷你队列，主列表不铺开占位。
   * @returns {jQuery} 迷你队列
   */
  _ensureMiniQueue: function() {
    var self = this;
    if (this.$miniQueue && this.$miniQueue.length) return this.$miniQueue;
    var queue = $4(`
                <div class="ws-upload-mini-queue" role="status" aria-live="polite">
                    <button type="button" class="ws-upload-mini-head" data-mini-toggle>
                        <i class="bi bi-cloud-arrow-up"></i>
                        <span class="ws-upload-mini-head-text">上传中…</span>
                        <span class="ws-upload-mini-head-count"></span>
                        <i class="bi bi-chevron-down ws-upload-mini-caret"></i>
                    </button>
                    <div class="ws-upload-mini-bar">
                        <div class="ws-upload-mini-progress"></div>
                    </div>
                    <div class="ws-upload-mini-list"></div>
                </div>
            `).appendTo("body");
    queue.on("click", "[data-mini-toggle]", function(e) {
      e.stopPropagation();
      self._miniOpen = !self._miniOpen;
      queue.toggleClass("open", self._miniOpen);
    });
    this._miniNs = "wsUploadMini" + this.generateFileId();
    $4(document).on("click." + this._miniNs, function(e) {
      if (self.$miniQueue && self.$miniQueue.length && !self.$miniQueue.is(e.target) && self.$miniQueue.has(e.target).length === 0) {
        self._miniOpen = false;
        self.$miniQueue.removeClass("open");
      }
    });
    this.$miniQueue = queue;
    return queue;
  },
  /**
   * 刷新迷你队列头部：上传中计数 + 聚合进度
   */
  _miniRefresh: function() {
    if (!this.$miniQueue) return;
    var self = this;
    var uploading = this.files.filter(function(f) {
      return f.status === "uploading";
    });
    var done = this.files.filter(function(f) {
      return f.status === "success";
    });
    var total = uploading.length + done.length;
    var pct = 0;
    if (total > 0) {
      var sum = 0;
      uploading.forEach(function(f) {
        sum += f.uploadedPercent || 0;
      });
      done.forEach(function() {
        sum += 100;
      });
      pct = Math.round(sum / total);
    }
    this.$miniQueue.find(".ws-upload-mini-head-count").text(uploading.length);
    this.$miniQueue.find(".ws-upload-mini-head-text").text(
      uploading.length > 0 ? "上传中 " + uploading.length + " · 完成 " + done.length : "已完成 " + done.length
    );
    this.$miniQueue.find(".ws-upload-mini-progress").css("width", pct + "%");
    if (uploading.length === 0 && this._miniOpen) {
      this._miniOpen = false;
      this.$miniQueue.removeClass("open");
    }
  },
  /**
   * 把一个上传中的文件加入迷你队列（隐藏主列表行，队列内新增一行）
   */
  _miniShowFile: function(file) {
    var queue = this._ensureMiniQueue();
    queue.find(".ws-upload-mini-list").append(
      '<div class="ws-upload-mini-file" data-file-id="' + file.id + '"><span class="ws-upload-mini-file-icon"><i class="' + this._iconFor(file) + '"></i></span><span class="ws-upload-mini-file-name" title="' + this.escapeHtml(file.name) + '">' + this.escapeHtml(file.name) + '</span><span class="ws-upload-mini-file-pct">0%</span></div>'
    );
    queue.addClass("show");
    this.element.find('[data-file-id="' + file.id + '"]').addClass("ws-upload-mini-hide");
    this._miniRefresh();
  },
  /**
   * 更新迷你队列中某文件行的进度
   */
  _miniUpdateFile: function(fileId, percent) {
    if (!this.$miniQueue) return;
    var row = this.$miniQueue.find('.ws-upload-mini-file[data-file-id="' + fileId + '"]');
    row.find(".ws-upload-mini-file-pct").text(percent + "%");
    this._miniRefresh();
  },
  /**
   * 某文件离开上传中态（成功/失败/取消）：从迷你队列移除，并恢复主列表行显示
   */
  _miniRemoveFile: function(fileId) {
    if (this.$miniQueue) {
      this.$miniQueue.find('.ws-upload-mini-file[data-file-id="' + fileId + '"]').remove();
    }
    this.element.find('[data-file-id="' + fileId + '"]').removeClass("ws-upload-mini-hide");
    this._miniRefresh();
    var stillUploading = this.files.some(function(f) {
      return f.status === "uploading";
    });
    if (this.$miniQueue && !stillUploading) {
      this.$miniQueue.removeClass("show");
    }
  },
  /**
   * 取文件图标类
   */
  _iconFor: function(file) {
    var ext = file.name.split(".").pop().toLowerCase();
    return FILE_ICONS[ext] || FILE_TYPES[this.getFileType(file)].icon;
  },
  /**
   * 清理迷你队列 DOM 与全局事件
   */
  _miniDestroy: function() {
    if (this.$miniQueue) {
      this.$miniQueue.remove();
      this.$miniQueue = null;
    }
    if (this._miniNs) {
      $4(document).off("click." + this._miniNs);
      this._miniNs = null;
    }
    this._miniOpen = false;
  },
  /**
   * 移除文件
   * @param {string} fileId - 文件ID
   */
  removeFile: function(fileId) {
    var index = this.files.findIndex(function(f) {
      return f.id === fileId;
    });
    if (index !== -1) {
      var file = this.files[index];
      if (file.status === "uploading") {
        this.cancelFileUpload(fileId);
      }
      this.files.splice(index, 1);
    }
    this.element.find('[data-file-id="' + fileId + '"]').remove();
    this._miniRemoveFile(fileId);
    this.updateStatistics();
    if (typeof this.options.onRemove === "function") {
      this.options.onRemove.call(this, fileId);
    }
  },
  /**
   * 开始上传
   */
  upload: function() {
    if (this.files.length === 0) {
      this.showMessage("请先选择文件", "warning");
      return;
    }
    if (this.uploading) {
      this.showMessage("文件正在上传中", "warning");
      return;
    }
    this.uploading = true;
    this.completedUploads = 0;
    this.failedUploads = 0;
    if (typeof this.options.beforeUpload === "function") {
      var result = this.options.beforeUpload.call(this, this.files);
      if (result === false) {
        this.uploading = false;
        return;
      }
    }
    this.uploadQueue = this.files.filter(function(file) {
      return file.status !== "success";
    });
    if (this.uploadQueue.length === 0) {
      this.uploading = false;
      this.showMessage("没有需要上传的文件", "info");
      return;
    }
    this.uploadQueue.forEach(function(file) {
      file.status = "uploading";
      this.updateFileStatus(file.id, "uploading", "上传中...");
    }.bind(this));
    if (this.options.chunkedUpload) {
      this.uploadChunked();
    } else if (this.options.parallelUploads > 1) {
      this.uploadParallel();
    } else {
      this.uploadSequential();
    }
  },
  /**
   * 顺序上传
   */
  uploadSequential: function() {
    var self = this;
    var uploadNext = function(index) {
      if (index >= self.uploadQueue.length) {
        self.uploadComplete();
        return;
      }
      var file = self.uploadQueue[index];
      self.uploadFile(file).always(function() {
        uploadNext(index + 1);
      });
    };
    uploadNext(0);
  },
  /**
   * 并行上传
   */
  uploadParallel: function() {
    var self = this;
    var parallelCount = Math.min(this.options.parallelUploads, this.uploadQueue.length);
    for (var i = 0; i < parallelCount; i++) {
      this.uploadNextInQueue();
    }
  },
  /**
   * 上传队列中的下一个文件
   */
  uploadNextInQueue: function() {
    var self = this;
    var file = this.uploadQueue.find(function(f) {
      return f.status === "uploading" && !f.xhr;
    });
    if (!file) return;
    this.uploadFile(file).always(function() {
      if (self.activeUploads < self.options.parallelUploads) {
        self.uploadNextInQueue();
      }
    });
  },
  /**
   * 上传单个文件
   * @param {File} file - 文件对象
   * @returns {jqXHR} jQuery XHR 对象
   */
  uploadFile: function(file) {
    var self = this;
    var formData = new FormData();
    formData.append(this.options.field, file);
    Object.keys(this.options.data).forEach(function(key) {
      var value = typeof self.options.data[key] === "function" ? self.options.data[key](file) : self.options.data[key];
      formData.append(key, value);
    });
    var fileItem = this.element.find('[data-file-id="' + file.id + '"]');
    fileItem.addClass("is-uploading");
    fileItem.find(".ws-upload-file-progress").removeClass("d-none");
    fileItem.find(".ws-upload-status").html('<span class="ws-upload-loading"></span>上传中...');
    if (this.options.listLayout === "mini-queue") {
      this._miniShowFile(file);
    }
    this.activeUploads++;
    var xhr = $4.ajax({
      url: this.options.url,
      type: this.options.method,
      data: formData,
      processData: false,
      contentType: false,
      headers: this.options.headers,
      xhr: function() {
        var xhr2 = new window.XMLHttpRequest();
        xhr2.upload.addEventListener("progress", function(e) {
          if (e.lengthComputable) {
            var percent = Math.round(e.loaded / e.total * 100);
            file.uploadedPercent = percent;
            fileItem.find(".progress-bar").css("width", percent + "%");
            if (self.options.listLayout === "mini-queue") {
              self._miniUpdateFile(file.id, percent);
            }
            if (typeof self.options.onProgress === "function") {
              self.options.onProgress.call(self, file, percent, e.loaded, e.total);
            }
          }
        }, false);
        return xhr2;
      },
      success: function(response) {
        self.handleUploadSuccess(file, response);
      },
      error: function(xhr2, status, error) {
        self.handleUploadError(file, error, xhr2);
      }
    });
    file.xhr = xhr;
    this.xhrPool.push(xhr);
    return xhr;
  },
  /**
   * 分片上传（需要后端支持）
   */
  uploadChunked: function() {
    this.uploadParallel();
  },
  /**
   * 处理上传成功
   * @param {File} file - 文件对象
   * @param {*} response - 服务器响应
   */
  handleUploadSuccess: function(file, response) {
    file.status = "success";
    file.xhr = null;
    var fileItem = this.element.find('[data-file-id="' + file.id + '"]');
    fileItem.removeClass("is-uploading").addClass("is-success");
    fileItem.find(".ws-upload-status").html('<i class="bi bi-check-circle text-success"></i> ' + this.escapeHtml(this.options.messages.uploadSuccess.replace("{file}", file.name)));
    fileItem.find(".progress-bar").addClass("bg-success");
    this._miniRemoveFile(file.id);
    this.completedUploads++;
    this.activeUploads--;
    this.updateStatistics();
    var success = true;
    var message = "";
    var data3 = response;
    if (this.options.responseType === "json") {
      if (response && typeof response === "object") {
        success = response[this.options.successField] !== false;
        message = response[this.options.messageField] || "";
        data3 = response[this.options.dataField] || response;
      }
    }
    if (success) {
      if (typeof this.options.onSuccess === "function") {
        this.options.onSuccess.call(this, file, data3, response);
      }
    } else {
      this.handleUploadError(file, message || "服务器返回失败状态");
    }
    this.checkUploadComplete();
  },
  /**
   * HTML 转义（防 XSS）
   * @param {string} str
   * @returns {string}
   */
  escapeHtml: function(str) {
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  },
  /**
   * 处理上传错误
   * @param {File} file - 文件对象
   * @param {string} error - 错误信息
   * @param {XMLHttpRequest} xhr - XHR 对象
   */
  handleUploadError: function(file, error, xhr) {
    file.status = "error";
    file.xhr = null;
    var fileItem = this.element.find('[data-file-id="' + file.id + '"]');
    fileItem.removeClass("is-uploading").addClass("is-error");
    var errorMessage = error || this.options.messages.uploadFailed.replace("{file}", file.name);
    if (xhr && xhr.status) {
      errorMessage = this.options.messages.serverError.replace("{status}", xhr.status) + ": " + errorMessage;
    }
    fileItem.find(".ws-upload-status").html('<i class="bi bi-x-circle text-danger"></i> ' + this.escapeHtml(errorMessage));
    fileItem.find(".progress-bar").addClass("bg-danger");
    fileItem.find(".retry-btn").removeClass("d-none");
    this._miniRemoveFile(file.id);
    this.failedUploads++;
    this.activeUploads--;
    this.updateStatistics();
    if (typeof this.options.onError === "function") {
      this.options.onError.call(this, file, error, xhr);
    }
    this.checkUploadComplete();
  },
  /**
   * 检查上传是否完成
   */
  checkUploadComplete: function() {
    if (this.completedUploads + this.failedUploads === this.uploadQueue.length) {
      this.uploadComplete();
    }
  },
  /**
   * 上传完成处理
   */
  uploadComplete: function() {
    this.uploading = false;
    this.uploadQueue = [];
    this.activeUploads = 0;
    this.xhrPool = [];
    if (typeof this.options.onComplete === "function") {
      this.options.onComplete.call(this, this.files);
    }
    var message = "上传完成! 成功: " + this.completedUploads + ", 失败: " + this.failedUploads;
    this.showMessage(message, this.failedUploads === 0 ? "success" : "warning");
  },
  /**
   * 取消上传
   */
  cancelUpload: function() {
    this.xhrPool.forEach(function(xhr) {
      xhr.abort();
    });
    this.uploadQueue.forEach(function(file) {
      if (file.status === "uploading") {
        file.status = "cancelled";
        this.updateFileStatus(file.id, "cancelled", "上传已取消");
        this._miniRemoveFile(file.id);
      }
    }.bind(this));
    this.uploading = false;
    this.uploadQueue = [];
    this.activeUploads = 0;
    this.xhrPool = [];
    this.showMessage("上传已取消", "info");
  },
  /**
   * 取消单个文件上传
   * @param {string} fileId - 文件ID
   */
  cancelFileUpload: function(fileId) {
    var file = this.files.find(function(f) {
      return f.id === fileId;
    });
    if (file && file.xhr) {
      file.xhr.abort();
    }
  },
  /**
   * 重试上传
   * @param {string} fileId - 文件ID
   */
  retryUpload: function(fileId) {
    var file = this.files.find(function(f) {
      return f.id === fileId;
    });
    if (file && file.status === "error") {
      if (file.retryCount < this.options.retryAttempts) {
        file.retryCount++;
        file.status = "uploading";
        this.updateFileStatus(fileId, "uploading", "重试上传中... (" + file.retryCount + "/" + this.options.retryAttempts + ")");
        if (this.uploading) {
          this.uploadFile(file);
        } else {
          this.uploadQueue = [file];
          this.uploadFile(file);
        }
      } else {
        this.showMessage("重试次数已达上限: " + file.name, "error");
      }
    }
  },
  /**
   * 更新文件状态显示
   * @param {string} fileId - 文件ID
   * @param {string} status - 状态类型
   * @param {string} message - 状态消息
   */
  updateFileStatus: function(fileId, status, message) {
    var fileItem = this.element.find('[data-file-id="' + fileId + '"]');
    var statusElement = fileItem.find(".ws-upload-status");
    fileItem.removeClass("uploading success error");
    var escapedMsg = this.escapeHtml(message);
    switch (status) {
      case "uploading":
        fileItem.addClass("is-uploading");
        statusElement.html('<span class="ws-upload-loading"></span>' + escapedMsg);
        fileItem.find(".retry-btn").addClass("d-none");
        break;
      case "success":
        fileItem.addClass("is-success");
        statusElement.html('<i class="bi bi-check-circle text-success"></i> ' + escapedMsg);
        fileItem.find(".retry-btn").addClass("d-none");
        break;
      case "error":
        fileItem.addClass("is-error");
        statusElement.html('<i class="bi bi-x-circle text-danger"></i> ' + escapedMsg);
        fileItem.find(".retry-btn").removeClass("d-none");
        break;
      case "cancelled":
        statusElement.html('<i class="bi bi-slash-circle text-warning"></i> ' + escapedMsg);
        fileItem.find(".retry-btn").removeClass("d-none");
        break;
    }
  },
  /**
   * 清空文件列表
   */
  clearFiles: function() {
    if (this.uploading) {
      if (!confirm("文件正在上传中，确定要清空列表吗？")) {
        return;
      }
      this.cancelUpload();
    }
    this.files.forEach(function(file) {
      if (file.xhr) {
        file.xhr.abort();
      }
    });
    this.files = [];
    this.previewContainer.empty();
    this._miniDestroy();
    this.updateStatistics();
    this.showMessage("文件列表已清空", "info");
  },
  /**
   * 更新统计信息
   */
  updateStatistics: function() {
    if (!this.options.showStatistics) return;
    var totalSize = this.files.reduce(function(sum, file) {
      return sum + file.size;
    }, 0);
    var successCount = this.files.filter(function(file) {
      return file.status === "success";
    }).length;
    var errorCount = this.files.filter(function(file) {
      return file.status === "error";
    }).length;
    this.statisticsContainer.html(`
                <small>
                    <span class="total-files">文件总数: ${this.files.length}</span> | 
                    <span class="uploaded-files">已上传: ${successCount}</span> | 
                    <span class="failed-files">失败: ${errorCount}</span> |
                    <span class="total-size">总大小: ${this.formatFileSize(totalSize)}</span>
                </small>
            `);
  },
  // ==================== 工具方法 ====================
  /**
   * 生成文件ID
   * @returns {string} 唯一文件ID
   */
  generateFileId: function() {
    return Date.now() + "-" + Math.random().toString(36).substr(2, 9);
  },
  /**
   * 格式化文件大小
   * @param {number} bytes - 字节数
   * @returns {string} 格式化后的文件大小
   */
  formatFileSize: function(bytes) {
    if (bytes === 0) return "0 Bytes";
    var k = 1024;
    var sizes = ["Bytes", "KB", "MB", "GB", "TB"];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
  },
  /**
   * 获取文件类型
   * @param {File} file - 文件对象
   * @returns {string} 文件类型
   */
  getFileType: function(file) {
    if (file.type.startsWith("image/")) return "images";
    if (file.type.startsWith("video/")) return "video";
    if (file.type.startsWith("audio/")) return "audio";
    if (file.type.startsWith("application/pdf") || file.type.includes("word") || file.type.includes("text")) return "document";
    if (file.type.includes("zip") || file.type.includes("rar") || file.type.includes("archive")) return "archive";
    return "file";
  },
  /**
   * 检查是否为图片文件
   * @param {File} file - 文件对象
   * @returns {boolean} 是否为图片
   */
  isImageFile: function(file) {
    return file.type.startsWith("image/");
  },
  /**
   * 显示消息
   * @param {string} message - 消息内容
   * @param {string} type - 消息类型 (success, error, warning, info)
   */
  showMessage: function(message, type2) {
    var alertClass = {
      "success": "alert-success",
      "error": "alert-danger",
      "warning": "alert-warning",
      "info": "alert-info"
    }[type2] || "alert-info";
    var alertHtml = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
    var alertElement = $4(alertHtml).insertBefore(this.element.find(".ws-upload-container"));
    setTimeout(function() {
      if (typeof bootstrap !== "undefined" && bootstrap.Alert) {
        alertElement.alert("close");
      } else {
        alertElement.fadeOut(400, function() {
          $4(this).remove();
        });
      }
    }, 5e3);
  },
  // ==================== 公共API方法 ====================
  /**
   * 获取所有文件
   * @returns {Array} 文件列表
   */
  getFiles: function() {
    return this.files;
  },
  /**
   * 获取已上传成功的文件
   * @returns {Array} 已上传文件列表
   */
  getUploadedFiles: function() {
    return this.files.filter(function(file) {
      return file.status === "success";
    });
  },
  /**
   * 获取上传失败的文件
   * @returns {Array} 失败文件列表
   */
  getFailedFiles: function() {
    return this.files.filter(function(file) {
      return file.status === "error";
    });
  },
  /**
   * 检查是否正在上传
   * @returns {boolean} 上传状态
   */
  isUploading: function() {
    return this.uploading;
  },
  /**
   * 获取上传进度百分比
   * @returns {number} 进度百分比
   */
  getProgress: function() {
    var total = this.files.length;
    var completed = this.files.filter(function(file) {
      return file.status === "success";
    }).length;
    return total > 0 ? Math.round(completed / total * 100) : 0;
  },
  /**
   * 销毁组件
   */
  destroy: function() {
    this.cancelUpload();
    this._miniDestroy();
    this.fileInput.off();
    this.element.find("button").off();
    this.element.off();
    this.element.empty();
    this.element.removeData("wsUpload");
  }
};
$4.fn.wsUpload = function(options2) {
  return this.each(function() {
    if (!$4(this).data("wsUpload")) {
      $4(this).data("wsUpload", new WsUpload(this, options2));
    }
  });
};
$4.fn.wsUpload.defaults = DEFAULTS2;
$4.fn.wsUpload.fileTypes = FILE_TYPES;
window.WsUpload = WsUpload;
var ws_upload_default = WsUpload;

// public/statics/libs/ws-form-validator/ws-form-validator.js
var $5 = window.jQuery;
var bootstrap2 = window.bootstrap;
if (typeof $5 === "undefined") {
  throw new Error("WsFormValidator requires jQuery");
}
var _WsFormValidator = class _WsFormValidator {
  constructor(formId, options2 = {}) {
    if (!formId) {
      throw new Error("WsFormValidator requires a form ID");
    }
    this.form = document.getElementById(formId);
    if (!this.form) {
      throw new Error('Form with ID "' + formId + '" not found');
    }
    this.form.setAttribute("novalidate", "");
    this.form.classList.add("needs-validation");
    this.options = Object.assign({}, _WsFormValidator.DEFAULT_OPTIONS, options2);
    this._dynamicRules = /* @__PURE__ */ new Map();
    this._currentDynamic = null;
    this.init();
  }
  init() {
    if (this._initialized) return;
    this._initialized = true;
    this.form.addEventListener("submit", (el) => {
      el.preventDefault();
      this.validateForm(el);
    });
    if (this.options.liveValidation) {
      this.setupLiveValidation();
    }
    this.form.addEventListener("reset", (el) => {
      this.resetForm();
      if (typeof this.options.onReset === "function") {
        this.options.onReset(el, this.form);
      }
    });
    this.setupCustomEvents();
    this.setupPlaceholders();
  }
  // 设置实时校验（保留原方法名；实际逻辑收敛到 refresh()）
  setupLiveValidation() {
    this.refresh();
  }
  /**
   * 重新扫描并绑定实时校验
   *
   * 覆盖两类目标：
   *   1. 带 data-validation 的元素（含同 name 的 radio/checkbox 组）
   *   2. setRules() 动态注册的元素（可能是无 name 的容器 div）
   *
   * 幂等：同一元素重复调用不会重复绑定。
   * @returns {WsFormValidator}
   */
  refresh() {
    const targets = /* @__PURE__ */ new Set();
    this.form.querySelectorAll("[data-validation]").forEach((input) => {
      const groupName = input.getAttribute("name");
      if (!groupName) return;
      this.form.querySelectorAll('[name="' + CSS.escape(groupName) + '"]').forEach((el) => targets.add(el));
    });
    this._dynamicRules.forEach((_cfg, el) => {
      if (el && el.isConnected) targets.add(el);
    });
    targets.forEach((el) => this._bindLive(el));
    return this;
  }
  /** 为单个元素绑定实时校验（幂等） */
  _bindLive(el) {
    if (!el || el._wsFvBound) return;
    el._wsFvBound = true;
    const handler = () => this.validateField(el);
    el.addEventListener("input", handler);
    el.addEventListener("blur", handler);
    el.addEventListener("change", handler);
  }
  /**
   * 解析元素的 data-validation 属性为规则映射表
   * @param {HTMLElement} input
   * @returns {Object}  { ruleName: ruleParam, ... }
   */
  _parseRules(input) {
    var validationRules = input.getAttribute("data-validation");
    if (!validationRules) return {};
    var rules = validationRules.split("|");
    var ruleMap = {};
    for (var j = 0; j < rules.length; j++) {
      var rule = rules[j];
      var ruleParts = rule.split(":");
      var ruleName = ruleParts[0];
      var ruleParam = ruleParts[1] || "";
      ruleMap[ruleName] = ruleParam;
    }
    return ruleMap;
  }
  // 设置自定义事件
  setupCustomEvents() {
    var inputs = this.form.querySelectorAll("[data-validation]");
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      var ruleMap = this._parseRules(input);
      if (Object.keys(ruleMap).length === 0) continue;
      if (ruleMap["passwordStrength"] !== void 0) {
        var strengthIndicator = this._resolveStrengthIndicator(input);
        this.bindPasswordStrengthEvent(input, strengthIndicator, input.id + "_strength", ruleMap["passwordStrength"]);
      }
      if (ruleMap["charCount"] !== void 0 && ruleMap["maxLength"] !== void 0) {
        var counterId = input.id + "CharCount";
        var maxLength = ruleMap["maxLength"];
        this.bindCharCountEvent(input, counterId, maxLength);
      }
      if (ruleMap["fileType"] !== void 0 || ruleMap["fileSize"] !== void 0) {
        var areaId = input.id + "UploadArea";
        var infoId = input.id + "FileInfo";
        this.bindFileUploadEvent(input, areaId, infoId);
      }
    }
  }
  // 初始化占位符
  setupPlaceholders() {
    if (this.options.feedbackPlaceholder) {
      const elements = this.form.querySelectorAll(".invalid-feedback");
      elements.forEach((element) => {
        element.style.visibility = "hidden";
        element.style.display = "block";
      });
    }
  }
  // 校验整个表单
  validateForm(el) {
    let self = this;
    let isValid = true;
    let errors = [];
    const inputs = Array.from(this.form.querySelectorAll("input, select, textarea"));
    this._dynamicRules.forEach((_cfg, el2) => {
      if (el2 && el2.isConnected && !el2.matches("input, select, textarea")) inputs.push(el2);
    });
    inputs.forEach((input) => {
      let result = this.validateField(input);
      if (result !== true) {
        isValid = false;
        errors.push(result);
      }
    });
    if (isValid) {
      if (this.options.showMessage) {
        this.showSuccessMessage("表单校验通过，可以提交！");
      }
      if (typeof this.options.onSuccess === "function") {
        this.options.onSuccess(this.form, el);
      }
      if (this.options.autoSubmit) {
        this._autoSubmit(el);
      } else if (typeof this.options.onSubmit === "function") {
        this.options.onSubmit(this.form, el, "");
      }
    } else {
      if (this.options.showMessage) {
        this.showErrorMessage("表单中存在错误，请检查后重新提交！");
      }
      this.scrollToFirstError();
      if (typeof this.options.onError === "function") {
        this.options.onError(this.form, el, errors);
      }
    }
    return isValid;
  }
  /**
   * 内置自动提交（autoSubmit: true 时调用）
   *
   * 默认行为与旧版**完全一致**：POST + form.action + serialize()（表单编码）。
   * 以下配置可覆盖（都不传即保持旧行为）：
   *   - submitMethod       'post' | 'get' | 'put' | 'patch' | 'delete'
   *   - submitContentType  'form'（默认，表单编码）| 'json'
   *   - submitHeaders      额外请求头
   *   - submitData         (form) => object | FormData，覆盖默认的 serialize()
   *
   * ⚠️ 成功判定（res.code == 0）与 iframe 内自动关闭弹层是本组件的硬编码约定，
   *    扩展时不要改动，否则会影响现有使用 autoSubmit 的后台页面。
   *
   * @param {Event} el 触发表单提交的事件
   */
  _autoSubmit(el) {
    const self = this;
    const form = this.form;
    const options2 = this.options;
    const method = String(options2.submitMethod || "post").toLowerCase();
    const useJson = String(options2.submitContentType || "form").toLowerCase() === "json";
    let payload = typeof options2.submitData === "function" ? options2.submitData(form) : $5(form).serialize();
    const ajaxOptions = {
      url: form.action,
      type: method,
      dataType: "json",
      beforeSend: function() {
        ws_layer_default.loading({ content: "正在提交..." });
      },
      success: function(res) {
        if (res.code == 0) {
          ws_layer_default.toast({ content: res?.msg || "提交成功", type: "success", time: 2e3 });
          if (typeof self.options.onSubmit === "function") {
            self.options.onSubmit(self.form, el, res || "提交成功");
          }
          if (window.parent !== window && window.parent.Layer) {
            window.parent.Layer.close(window.parent.Layer.getFrameIndex(window.name));
          }
        } else {
          ws_layer_default.toast({ content: res?.msg || "提交失败", type: "warning", time: 2e4 });
          if (typeof self.options.onSubmit === "function") {
            self.options.onSubmit(self.form, el, res || "提交失败");
          }
        }
        ws_layer_default.closeAll("loading");
      },
      error: function() {
        ws_layer_default.toast({ content: "网络错误，请重试", type: "warning" });
        if (typeof self.options.onSubmit === "function") {
          self.options.onSubmit(self.form, el, "网络错误，请重试");
        }
        ws_layer_default.closeAll("loading");
      }
    };
    if (useJson) {
      ajaxOptions.contentType = "application/json; charset=UTF-8";
      if (!(payload instanceof FormData) && typeof payload !== "string") {
        payload = JSON.stringify(payload);
      }
    }
    if (options2.submitHeaders && typeof options2.submitHeaders === "object") {
      ajaxOptions.headers = options2.submitHeaders;
    }
    ajaxOptions.data = payload;
    $5.ajax(ajaxOptions);
  }
  // 校验单个字段
  validateField(field) {
    if (!field) return true;
    const dynamic = this._dynamicRules.get(field) || null;
    this._currentDynamic = dynamic;
    if (dynamic && typeof dynamic.options.when === "function" && !dynamic.options.when(field)) {
      this.updateFieldUI(field, true, "", dynamic.options);
      this._currentDynamic = null;
      return true;
    }
    const groupName = field.name;
    const ruleSource = dynamic ? dynamic : groupName ? document.querySelector(`[name="${groupName}"][data-validation]`) : null;
    if (!ruleSource) {
      this._currentDynamic = null;
      return true;
    }
    const rules = dynamic ? this._serializeRules(dynamic.rules) : ruleSource.getAttribute("data-validation");
    if (!rules) {
      this._currentDynamic = null;
      return true;
    }
    const rawValue = dynamic && typeof dynamic.options.getValue === "function" ? dynamic.options.getValue(field) : field.value;
    const value = rawValue === null || rawValue === void 0 ? "" : String(rawValue);
    let isValid = true;
    let errorMessage = "";
    const ruleList = rules.split("|");
    for (const rule of ruleList) {
      let [ruleName, ruleParam] = rule.split(":");
      if ((field.type === "radio" || field.type === "checkbox") && ruleName === "required") {
        const checked = this.form.querySelector(`[name="${groupName}"]:checked`);
        if (!checked) {
          isValid = false;
          errorMessage = this.getErrorMessage(field, ruleName);
          break;
        }
      } else {
        switch (ruleName) {
          case "required":
            if (!value.trim()) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "minLength":
            if (value.length < parseInt(ruleParam)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "maxLength":
            if (value.length > parseInt(ruleParam)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "email":
            if (value && !this.isValidEmail(value)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "phone":
            if (value && !this.isValidPhone(value)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "number":
            if (value !== "" && isNaN(Number(value))) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "min":
            if (value && parseFloat(value) < parseFloat(ruleParam)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "max":
            if (value && parseFloat(value) > parseFloat(ruleParam)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "match":
            ruleParam = ruleParam.replace(/^\/|\/$/g, "");
            if (value && !new RegExp(ruleParam).test(value)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "confirmPassword":
            const fieldToMatch = document.getElementById(ruleParam);
            if (fieldToMatch && value !== fieldToMatch.value) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "date":
            if (value && !this.isValidDate(value)) {
              isValid = false;
              errorMessage = this.getErrorMessage(field, ruleName);
            }
            break;
          case "fileType":
            if (field.files.length > 0) {
              const file = field.files[0];
              const acceptedTypes = ruleParam.split(",");
              let typeValid = false;
              for (const type2 of acceptedTypes) {
                if (file.type.match(type2.replace("*", ".*"))) {
                  typeValid = true;
                  break;
                }
              }
              if (!typeValid) {
                isValid = false;
                errorMessage = this.getErrorMessage(field, ruleName);
              }
            }
            break;
          case "fileSize":
            if (field.files.length > 0) {
              const file = field.files[0];
              const maxSize = this.parseFileSize(ruleParam);
              if (file.size > maxSize) {
                isValid = false;
                errorMessage = this.getErrorMessage(field, ruleName);
              }
            }
            break;
          case "passwordStrength": {
            if (value) {
              const evaluation = this.evaluatePassword(value, this._parsePasswordPolicy(ruleParam));
              if (!evaluation.valid) {
                isValid = false;
                errorMessage = this.getPasswordStrengthMessage(field, evaluation);
              }
            }
            break;
          }
          default:
            const customFn = this.options.onCustomRules && this.options.onCustomRules[ruleName];
            if (typeof customFn === "function") {
              const result = customFn.call(this, value, ruleParam, field);
              if (result === false) {
                isValid = false;
                errorMessage = this.getErrorMessage(field, ruleName);
              } else if (typeof result === "string" && result !== "") {
                isValid = false;
                errorMessage = result;
              }
            }
            break;
        }
      }
      if (!isValid) break;
    }
    this.updateFieldUI(field, isValid, errorMessage, dynamic ? dynamic.options : null);
    this._currentDynamic = null;
    if (isValid) {
      return true;
    } else {
      return { field, isValid, errorMessage };
    }
  }
  // ============================================================
  // 动态规则 API（v1.1.0）
  // ============================================================
  /**
   * 动态注册 / 更新字段的校验规则（不依赖模板中的 data-validation）
   *
   * 适用两类场景：
   *   1. **条件必填**：用 `options.when` 控制规则生效时机；条件不成立时自动清除错误态
   *   2. **非原生控件**（ws-select / ws-date-picker 等）：真值不在 DOM 上或事件模型不同，
   *      用 `options.getValue` 取真值、`options.uiTarget` / `options.feedback` 指定状态与提示位置
   *
   * @param {HTMLElement|string} target 元素，或选择器（只取第一个匹配）
   * @param {string|Object|Array|null} rules 规则串 `'required|minLength:2'`、
   *        对象 `{required:true, minLength:2}`、数组；传 null / '' 表示移除
   * @param {Object} [options]
   * @param {Function} [options.getValue] `(field) => any` 自定义取值
   * @param {Function} [options.when] `(field) => boolean` 条件成立才校验
   * @param {Object} [options.messages] `{ ruleName: '错误文案' }`
   * @param {HTMLElement} [options.uiTarget] 加 `is-valid` / `is-invalid` 的元素
   * @param {HTMLElement} [options.feedback] `.invalid-feedback` 容器
   * @param {boolean} [options.live=true] 是否绑定实时校验
   * @returns {WsFormValidator}
   */
  setRules(target, rules, options2 = {}) {
    const el = typeof target === "string" ? this.form.querySelector(target) : target;
    if (!el) {
      console.warn("[WsFormValidator] setRules: 目标元素不存在", target);
      return this;
    }
    if (rules === null || rules === void 0 || rules === "") {
      this._dynamicRules.delete(el);
      this.updateFieldUI(el, true, "", options2);
      return this;
    }
    this._dynamicRules.set(el, { rules, options: Object.assign({ live: true }, options2) });
    if (options2.live !== false) this._bindLive(el);
    return this;
  }
  /** 移除动态规则（等价于 setRules(target, null)） */
  clearRules(target) {
    return this.setRules(target, null);
  }
  /** 规则对象 / 数组 → 规则串（与 data-validation 同一套语法） */
  _serializeRules(rules) {
    if (!rules) return "";
    if (typeof rules === "string") return rules;
    if (Array.isArray(rules)) return rules.filter(Boolean).join("|");
    return Object.keys(rules).filter((name) => rules[name] !== false && rules[name] !== null && rules[name] !== void 0).map((name) => {
      const param = rules[name];
      return param === true || param === "" ? name : name + ":" + param;
    }).join("|");
  }
  // 获取表单数据
  getFormData() {
    const formData = new FormData(this.form);
    const data3 = {};
    formData.forEach((value, key) => {
      data3[key] = value;
    });
    return data3;
  }
  // 获取错误消息
  getErrorMessage(field, ruleName) {
    const dynamicMessages = this._currentDynamic && this._currentDynamic.options ? this._currentDynamic.options.messages : null;
    if (dynamicMessages && dynamicMessages[ruleName]) return dynamicMessages[ruleName];
    const customMessage = field.getAttribute(`data-validation-message-${ruleName}`);
    if (customMessage) return customMessage;
    const defaultMessages = {
      required: "此字段为必填项",
      minLength: "输入内容太短",
      maxLength: "输入内容太长",
      email: "请输入有效的邮箱地址",
      phone: "请输入有效的电话号码",
      number: "请输入有效的数字",
      min: "数值太小",
      max: "数值太大",
      match: "输入不匹配",
      date: "请输入有效的日期",
      fileType: "文件类型不正确",
      fileSize: "文件太大",
      passwordStrength: "密码强度不足"
    };
    return defaultMessages[ruleName] || "输入无效";
  }
  // 更新字段UI
  // @param {Object} [override] 动态规则的覆盖项 { uiTarget, feedback }
  updateFieldUI(field, isValid, errorMessage, override) {
    override = override || {};
    const groupName = field.name;
    const elements = override.uiTarget ? [override.uiTarget] : document.querySelectorAll(`[name="${groupName}"]`);
    elements.forEach((element) => {
      element.classList.remove("is-valid", "is-invalid");
    });
    let feedbackElement;
    if (field.type === "radio" || field.type === "checkbox") {
      const firstField = this.form.querySelector(`[name="${groupName}"]`);
      feedbackElement = firstField.closest(".form-check")?.parentNode?.querySelector(".invalid-feedback");
      feedbackElement = feedbackElement || firstField.closest(".form-check")?.parentNode?.nextElementSibling?.querySelector(".invalid-feedback");
    } else {
      feedbackElement = field.parentNode?.querySelector(".invalid-feedback") || field.parentNode?.nextElementSibling?.querySelector(".invalid-feedback");
    }
    if (override.feedback) feedbackElement = override.feedback;
    if (isValid) {
      elements.forEach((element) => {
        element.classList.add("is-valid");
      });
      if (feedbackElement) {
        if (this.options.feedbackPlaceholder) {
          feedbackElement.style.visibility = "hidden";
        } else {
          feedbackElement.style.display = "none";
        }
      }
    } else {
      elements.forEach((element) => {
        element.classList.add("is-invalid");
      });
      if (feedbackElement) {
        const textElement = feedbackElement.querySelector(".validation-text");
        if (textElement) {
          textElement.textContent = errorMessage;
        }
        if (this.options.feedbackPlaceholder) {
          feedbackElement.style.visibility = "visible";
        } else {
          feedbackElement.style.display = "flex";
        }
      }
    }
  }
  // 重置表单
  resetForm() {
    const inputs = this.form.querySelectorAll("input, select, textarea");
    inputs.forEach((input2) => {
      input2.classList.remove("is-valid", "is-invalid");
      const feedbackElement = input2.nextElementSibling;
      if (feedbackElement && feedbackElement.classList.contains("invalid-feedback")) {
        if (this.options.feedbackPlaceholder) {
          feedbackElement.style.visibility = "hidden";
        } else {
          feedbackElement.style.display = "none";
        }
      }
    });
    var customInputs = this.form.querySelectorAll("[data-validation]");
    for (var i = 0; i < customInputs.length; i++) {
      var input = customInputs[i];
      var ruleMap = this._parseRules(input);
      if (ruleMap["passwordStrength"] !== void 0) {
        var strengthIndicator = this._resolveStrengthIndicator(input);
        if (strengthIndicator) {
          this._paintStrengthBar(strengthIndicator, "", { score: 0 });
          var requirements = strengthIndicator.nextElementSibling;
          if (requirements && requirements.classList.contains("password-requirements")) {
            requirements.innerHTML = "";
            requirements.style.display = "none";
          }
        }
      }
      if (ruleMap["charCount"] !== void 0 && ruleMap["maxLength"] !== void 0) {
        var counterId = input.id + "CharCount";
        var charCounter = document.getElementById(counterId);
        if (charCounter) {
          charCounter.textContent = "0";
        }
      }
      if (ruleMap["fileType"] !== void 0 || ruleMap["fileSize"] !== void 0) {
        var infoId = input.id + "FileInfo";
        var fileInfo = document.getElementById(infoId);
        if (fileInfo) {
          fileInfo.textContent = "";
        }
      }
    }
  }
  // 显示成功消息
  showSuccessMessage(message) {
    this.showMessage(message, "success");
  }
  // 滚动到第一个错误字段
  scrollToFirstError() {
    var firstError = this.form.querySelector(".is-invalid");
    if (!firstError) return;
    if (firstError.type === "radio" || firstError.type === "checkbox") {
      var group = this.form.querySelector(".is-invalid:checked") || firstError;
      firstError = group;
    }
    firstError.scrollIntoView({ behavior: "smooth", block: "center" });
    setTimeout(function() {
      firstError.focus();
    }, 300);
  }
  // 显示错误消息
  showErrorMessage(message) {
    this.showMessage(message, "danger");
  }
  // 显示消息
  showMessage(message, type2) {
    const toastId = `toast-${Date.now()}`;
    const toast2 = document.createElement("div");
    toast2.className = `toolkit-toast-container toast-container position-fixed top-0 end-0 p-3`;
    toast2.innerHTML = `
                <div id="${toastId}" class="toast fade" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
                    <div class="toast-header bg-${type2} text-white">
                        <strong class="me-auto">提示</strong>
                        <button type="button" class="ml-2 mb-1 btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body">${message}</div>
                </div>
            `;
    this.form.insertBefore(toast2, this.form.lastElementChild);
    $5(`#${toastId}`).toast("show");
    setTimeout(() => {
      $5(`#${toastId}`).remove();
    }, 5500);
  }
  //绑定密码强度检测事件
  bindPasswordStrengthEvent(input, strengthIndicator, indicatorClassName, ruleParam) {
    if (!strengthIndicator) return;
    var render3 = () => {
      this.checkPasswordStrength(input.value, strengthIndicator, indicatorClassName, ruleParam);
    };
    input.addEventListener("input", render3);
    render3();
  }
  /**
   * 解析 passwordStrength 规则参数
   *
   * 语法：`passwordStrength:8,letter,digit`
   *   - 纯数字令牌       → 最小长度
   *   - letter|alpha     → 至少 1 个字母
   *   - upper|uppercase  → 至少 1 个大写字母
   *   - lower|lowercase  → 至少 1 个小写字母
   *   - digit|number     → 至少 1 个数字
   *   - special|symbol   → 至少 1 个特殊字符
   * 不传参数时回退到 WsFormValidator.PASSWORD_POLICY。
   *
   * @param {string} param 规则参数
   * @returns {{minLength:number,maxLength:number,require:string[]}}
   */
  _parsePasswordPolicy(param) {
    const defaults3 = _WsFormValidator.PASSWORD_POLICY;
    const policy = {
      minLength: defaults3.minLength,
      maxLength: defaults3.maxLength,
      require: defaults3.require.slice()
    };
    if (!param) return policy;
    const require2 = [];
    String(param).split(",").forEach((raw) => {
      const token = raw.trim().toLowerCase();
      if (!token) return;
      if (/^\d+$/.test(token)) {
        policy.minLength = parseInt(token, 10);
      } else if (token === "letter" || token === "alpha") {
        require2.push("letter");
      } else if (token === "upper" || token === "uppercase") {
        require2.push("upper");
      } else if (token === "lower" || token === "lowercase") {
        require2.push("lower");
      } else if (token === "digit" || token === "number" || token === "num") {
        require2.push("digit");
      } else if (token === "special" || token === "symbol") {
        require2.push("special");
      }
    });
    if (require2.length > 0) policy.require = require2;
    return policy;
  }
  /**
   * 计算密码是否满足策略（校验与强度条的唯一判定入口）
   *
   * @param {string} password
   * @param {Object} [policy]
   * @returns {{valid:boolean,unmet:Array,requirements:Array,score:number}}
   */
  evaluatePassword(password, policy) {
    const value = password === null || password === void 0 ? "" : String(password);
    const effectivePolicy = policy || _WsFormValidator.PASSWORD_POLICY;
    const requirements = this._passwordRequirements(effectivePolicy, value);
    const unmet = requirements.filter((item) => !item.met);
    return {
      valid: unmet.length === 0,
      unmet,
      requirements,
      score: this._passwordScore(value, effectivePolicy)
    };
  }
  /** 生成逐项判定结果（顺序即「首条原因」的优先级） */
  _passwordRequirements(policy, value) {
    const requirements = [];
    const length = value.length;
    requirements.push({
      code: "minLength",
      label: "至少 " + policy.minLength + " 位",
      met: length >= policy.minLength,
      message: "密码至少 " + policy.minLength + " 位（当前 " + length + " 位）"
    });
    if (policy.maxLength) {
      requirements.push({
        code: "maxLength",
        label: "不超过 " + policy.maxLength + " 位",
        met: length <= policy.maxLength,
        message: "密码不能超过 " + policy.maxLength + " 位（当前 " + length + " 位）"
      });
    }
    if (policy.require.includes("letter")) {
      requirements.push({
        code: "letter",
        label: "包含字母",
        met: /[A-Za-z]/.test(value),
        message: "需包含至少 1 个字母"
      });
    }
    if (policy.require.includes("lower")) {
      requirements.push({
        code: "lower",
        label: "包含小写字母",
        met: /[a-z]/.test(value),
        message: "需包含至少 1 个小写字母"
      });
    }
    if (policy.require.includes("upper")) {
      requirements.push({
        code: "upper",
        label: "包含大写字母",
        met: /[A-Z]/.test(value),
        message: "需包含至少 1 个大写字母"
      });
    }
    if (policy.require.includes("digit")) {
      requirements.push({
        code: "digit",
        label: "包含数字",
        met: /[0-9]/.test(value),
        message: "需包含至少 1 个数字"
      });
    }
    if (policy.require.includes("special")) {
      requirements.push({
        code: "special",
        label: "包含特殊字符",
        met: /[^A-Za-z0-9]/.test(value),
        message: "需包含至少 1 个特殊字符"
      });
    }
    requirements.push({
      code: "whitespace",
      label: "不包含空格",
      met: !/\s/.test(value),
      message: "密码不能包含空格"
    });
    return requirements;
  }
  /** 强度分（0~5），仅用于进度条着色，不参与是否通过判定 */
  _passwordScore(password, policy) {
    let score = 0;
    if (password.length >= policy.minLength) score += 1;
    if (/[a-z]/.test(password)) score += 1;
    if (/[A-Z]/.test(password)) score += 1;
    if (/[0-9]/.test(password)) score += 1;
    if (/[^A-Za-z0-9]/.test(password)) score += 1;
    return score;
  }
  /**
   * 解析密码强度条元素
   *
   * 顺序：data-strength-target → {input.id}Strength → 同容器 .password-strength
   * （最后一项兼容历史模板中 id 与 input 不对应的情况）
   */
  _resolveStrengthIndicator(input) {
    const explicitId = input.getAttribute("data-strength-target");
    if (explicitId) {
      const explicit = document.getElementById(explicitId);
      if (explicit) return explicit;
    }
    if (input.id) {
      const byConvention = document.getElementById(input.id + "Strength");
      if (byConvention) return byConvention;
    }
    return input.parentNode ? input.parentNode.querySelector(".password-strength") : null;
  }
  /** 首条未满足原因；允许 data-validation-message-passwordStrength 整体覆盖 */
  getPasswordStrengthMessage(field, evaluation) {
    const custom = field.getAttribute("data-validation-message-passwordStrength");
    if (custom) return custom;
    return evaluation.unmet.length > 0 ? evaluation.unmet[0].message : "密码强度不足";
  }
  // 绑定字符计数事件
  bindCharCountEvent(input, counterId, maxLength) {
    var charCounter = document.getElementById(counterId);
    if (!charCounter) return;
    input.addEventListener("input", () => {
      this.updateCharCount(input, charCounter, maxLength);
    });
    this.updateCharCount(input, charCounter, maxLength);
  }
  // 绑定文件上传事件
  bindFileUploadEvent(input, areaId, infoId) {
    var fileUploadArea = document.getElementById(areaId);
    var fileInfo = document.getElementById(infoId);
    if (!fileUploadArea) return;
    fileUploadArea.addEventListener("click", () => {
      input.click();
    });
    input.addEventListener("change", () => {
      this.handleFileUpload(input, fileInfo);
    });
    fileUploadArea.addEventListener("dragover", (e) => {
      e.preventDefault();
      fileUploadArea.style.borderColor = "#0d6efd";
      fileUploadArea.style.backgroundColor = "#f8f9fa";
    });
    fileUploadArea.addEventListener("dragleave", (e) => {
      e.preventDefault();
      fileUploadArea.style.borderColor = "#dee2e6";
      fileUploadArea.style.backgroundColor = "";
    });
    fileUploadArea.addEventListener("drop", (e) => {
      e.preventDefault();
      fileUploadArea.style.borderColor = "#dee2e6";
      fileUploadArea.style.backgroundColor = "";
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        this.handleFileUpload(input, fileInfo);
      }
    });
  }
  // 密码强度检测（强度条 + 条件清单，与校验共用 evaluatePassword）
  checkPasswordStrength(password, strengthIndicator, indicatorClassName, ruleParam) {
    if (!strengthIndicator) return;
    const policy = this._parsePasswordPolicy(ruleParam);
    const evaluation = this.evaluatePassword(password, policy);
    this._paintStrengthBar(strengthIndicator, password, evaluation);
    this._renderPasswordChecklist(strengthIndicator, password, evaluation);
  }
  /** 绘制强度条（只做视觉反馈，颜色与 score 对应） */
  _paintStrengthBar(strengthIndicator, password, evaluation) {
    const strengthClasses = ["bg-danger", "w-25", "bg-warning", "w-50", "bg-success", "w-75", "bg-info", "w-100"];
    strengthIndicator.classList.remove(...strengthClasses);
    if (!password) {
      strengthIndicator.style.cssText = "";
      return;
    }
    strengthIndicator.style.cssText = "height: 5px;margin-top: 0.5rem;border-radius: 3px;transition: all 0.3s;";
    const score = evaluation.score;
    if (score <= 1) {
      strengthIndicator.classList.add("bg-danger", "w-25");
    } else if (score === 2) {
      strengthIndicator.classList.add("bg-warning", "w-50");
    } else if (score === 3) {
      strengthIndicator.classList.add("bg-success", "w-75");
    } else {
      strengthIndicator.classList.add("bg-info", "w-100");
    }
  }
  /** 渲染条件清单：逐项标注是否满足，解决「只提示强度不足」的问题 */
  _renderPasswordChecklist(strengthIndicator, password, evaluation) {
    let list = strengthIndicator.nextElementSibling;
    if (!list || !list.classList.contains("password-requirements")) {
      list = document.createElement("ul");
      list.className = "password-requirements list-unstyled small mt-1 mb-0";
      strengthIndicator.parentNode.insertBefore(list, strengthIndicator.nextSibling);
    }
    if (!password) {
      list.innerHTML = "";
      list.style.display = "none";
      return;
    }
    list.style.display = "";
    list.innerHTML = evaluation.requirements.map((item) => {
      const cls = item.met ? "text-success" : "text-body-secondary";
      const icon = item.met ? "bi-check-circle-fill" : "bi-circle";
      return '<li class="' + cls + '"><i class="bi ' + icon + ' me-1"></i>' + item.label + "</li>";
    }).join("");
  }
  // 更新字符计数
  updateCharCount(input, charCounter, maxLength) {
    if (!charCounter) return;
    var currentLength = input.value.length;
    charCounter.textContent = currentLength;
    if (currentLength > maxLength * 1) {
      charCounter.style.color = "#dc3545";
    } else if (currentLength > maxLength * 0.7) {
      charCounter.style.color = "#fd7e14";
    } else {
      charCounter.style.color = "";
    }
  }
  // 处理文件上传
  handleFileUpload(input, fileInfo) {
    if (!fileInfo) return;
    if (input.files.length > 0) {
      var file = input.files[0];
      fileInfo.textContent = "已选择: " + file.name + " (" + this.formatFileSize(file.size) + ")";
      this.validateField(input);
    } else {
      fileInfo.textContent = "";
    }
  }
  // 辅助方法
  isValidEmail(email) {
    const emailRegex = _WsFormValidator.REGEX.email;
    return emailRegex.test(email);
  }
  isValidPhone(phone) {
    const phoneRegex = _WsFormValidator.REGEX.phone;
    return phoneRegex.test(phone) && phone.replace(/\D/g, "").length >= 10;
  }
  isValidDate(date) {
    return !isNaN(Date.parse(date));
  }
  isStrongPassword(password) {
    return this.evaluatePassword(password, _WsFormValidator.PASSWORD_POLICY).valid;
  }
  parseFileSize(sizeString) {
    const units = {
      "B": 1,
      "KB": 1024,
      "MB": 1024 * 1024,
      "GB": 1024 * 1024 * 1024,
      "TB": 1024 * 1024 * 1024 * 1024
    };
    const match = sizeString.match(/^(\d+)([KMGT]?B)$/i);
    if (match) {
      const value = parseInt(match[1]);
      const unit = match[2].toUpperCase();
      return value * (units[unit] || 1);
    }
    return 0;
  }
  formatFileSize(bytes) {
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
  }
};
// 默认配置
__publicField(_WsFormValidator, "DEFAULT_OPTIONS", {
  liveValidation: true,
  // 是否启用实时校验
  showIcons: true,
  // 是否在校验反馈中显示图标
  showMessage: false,
  // 是否显示校验消息
  feedbackPlaceholder: false,
  // 是否使用placeholder作为提示文本
  autoSubmit: false,
  // 是否自动提交表单
  // ---- autoSubmit 的提交细节 ----
  // 不传即保持旧行为：POST + form.action + serialize()（表单编码）
  submitMethod: "post",
  // 请求方法：post / get / put / patch / delete
  submitContentType: "form",
  // 'form'（表单编码，默认）| 'json'
  submitHeaders: null,
  // 额外请求头（CSRF 已由 wise-framework.js 全局注入，通常无需设置）
  submitData: null,
  // (form) => object|FormData，覆盖默认的 serialize()
  onSuccess: null,
  // 校验成功回调函数
  onError: null,
  // 校验失败回调函数
  onSubmit: null,
  // 表单提交回调函数
  onReset: null,
  // 表单重置回调函数
  onCustomRules: {}
  // 自定义校验规则
});
// 正则表达式
__publicField(_WsFormValidator, "REGEX", {
  email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
  url: /^(http[s]?):\/\/[^\s/$.?#].[^\s]*$/,
  date: /^\d{4}-\d{2}-\d{2}$/,
  time: /^\d{2}:\d{2}$/,
  datetime: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/,
  number: /^\d+$/,
  integer: /^-?\d+$/,
  float: /^-?\d+(\.\d+)?$/,
  phone: /^1[3-9]\d{9}$/
});
/**
 * 密码强度默认策略（规则不传参时使用）
 *
 * ⚠️ 与后端 app/admin/validate/Admin.php 的 passwordPolicy() 保持一致：
 *    8~32 位、至少 1 个字母、至少 1 个数字。
 *    调整时两处一起改（模板里的 passwordStrength:8,letter,digit 也要同步）。
 */
__publicField(_WsFormValidator, "PASSWORD_POLICY", {
  minLength: 8,
  maxLength: 32,
  require: ["letter", "digit"]
});
var WsFormValidator = _WsFormValidator;
window.WsFormValidator = WsFormValidator;
window.FormValidator = WsFormValidator;
var ws_form_validator_default = WsFormValidator;

// public/statics/libs/ws-color-picker/ws-color-picker.js
var $6 = window.jQuery;
if (typeof $6 === "undefined") {
  throw new Error("WsColorPicker requires jQuery");
}
var _instanceCounter = 0;
function addStyles() {
}
var gradientColors = [
  { pos: 0, color: [255, 0, 0] },
  // 红
  { pos: 0.17, color: [255, 255, 0] },
  // 黄
  { pos: 0.33, color: [0, 255, 0] },
  // 绿
  { pos: 0.5, color: [0, 255, 255] },
  // 青
  { pos: 0.67, color: [0, 0, 255] },
  // 蓝
  { pos: 0.83, color: [255, 0, 255] },
  // 品红
  { pos: 1, color: [255, 0, 0] }
  // 红（循环回起点）
];
var DEFAULT_STANDARD_COLORS = [
  // 红色系
  "#ff0000",
  "#ff4000",
  "#ff8000",
  "#ffbf00",
  "#ffff00",
  "#bfff00",
  "#80ff00",
  "#40ff00",
  // 绿色系
  "#00ff00",
  "#00ff40",
  "#00ff80",
  "#00ffbf",
  "#00ffff",
  "#00bfff",
  "#0080ff",
  "#0040ff",
  // 蓝色系
  "#0000ff",
  "#4000ff",
  "#8000ff",
  "#bf00ff",
  "#ff00ff",
  "#ff00bf",
  "#ff0080",
  "#ff0040",
  // 中性色
  "#000000",
  "#404040",
  "#808080",
  "#c0c0c0",
  "#ffffff",
  "#ffcccc",
  "#ccffcc",
  "#ccccff"
];
function WsColorPicker(options2) {
  this.options = $6.extend({
    container: document.body,
    initialColor: "#007bff",
    onColorChange: null,
    onColorSelect: null,
    modalClass: "ws-color-picker-modal",
    mode: "default",
    // 弹窗布局：default(完整) / mini(迷你，仅标准色+滑块+最近使用)
    standardColors: null,
    // 可选：自定义标准调色板颜色数组（覆盖默认 32 色）
    storagePrefix: "ws-color-picker",
    // 最近使用/收藏颜色的 localStorage 命名空间
    maxRecent: 8,
    // 最近使用保留的最大数量
    resetOnCancel: true,
    // 取消/关闭(未点确认)时，是否把组件内部颜色还原为打开时的初始色
    onCancel: null,
    // 取消回调：onCancel(当前颜色, 打开时初始色, { changed, lastColor })
    onClose: null
    // 关闭回调：onClose({ action:'confirm'|'cancel', confirmed, originalColor, color, lastColor, changed })
  }, options2);
  if (!/^#[0-9a-fA-F]{3,8}$/.test(this.options.initialColor)) {
    this.options.initialColor = "#007bff";
  }
  if (!/^[a-zA-Z][\w-]*$/.test(this.options.modalClass)) {
    this.options.modalClass = "ws-color-picker-modal";
  }
  if (this.options.mode !== "mini") {
    this.options.mode = "default";
  }
  this.uid = ++_instanceCounter;
  this.currentColor = {
    h: 210,
    s: 100,
    l: 50,
    a: 1
  };
  if (Array.isArray(this.options.standardColors) && this.options.standardColors.length) {
    this.standardColors = [];
    var self = this;
    this.options.standardColors.forEach(function(color) {
      if (/^#[0-9a-fA-F]{6}$/.test(String(color))) {
        self.standardColors.push(String(color).toLowerCase());
      }
    });
    if (!this.standardColors.length) {
      this.standardColors = DEFAULT_STANDARD_COLORS.slice();
    }
  } else {
    this.standardColors = DEFAULT_STANDARD_COLORS.slice();
  }
  this.recentColors = this._loadColorList(this.options.storagePrefix + ":recent");
  this.favoriteColors = this._loadColorList(this.options.storagePrefix + ":favorites");
  this.init();
}
WsColorPicker.prototype = {
  constructor: WsColorPicker,
  init: function() {
    addStyles();
    this.setInitialColor();
    this.createModal();
    this.bindEvents();
  },
  /*
   * 从 localStorage 读取颜色数组并做合法性过滤
   * @param {string} key localStorage 键
   * @returns {Array<string>} 合法的 #rrggbb 列表
   */
  _loadColorList: function(key) {
    var list = [];
    try {
      var raw = window.localStorage.getItem(key);
      if (!raw) return list;
      var parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return list;
      parsed.forEach(function(color) {
        if (typeof color === "string" && /^#[0-9a-fA-F]{6}$/.test(color)) {
          list.push(color.toLowerCase());
        }
      });
    } catch (e) {
    }
    return list;
  },
  /*
   * 将颜色数组写入 localStorage
   * @param {string} key localStorage 键
   * @param {Array<string>} list 颜色列表
   */
  _saveColorList: function(key, list) {
    try {
      window.localStorage.setItem(key, JSON.stringify(list));
    } catch (e) {
    }
  },
  /* 
   * 设置初始颜色
   */
  setInitialColor: function() {
    var initialHSL = this.hexToHsl(this.options.initialColor);
    this.currentColor = {
      h: initialHSL[0],
      s: initialHSL[1],
      l: initialHSL[2],
      a: 1
    };
  },
  /* 
   * 创建颜色选择器弹窗
   */
  createModal: function() {
    var modalHTML = this.options.mode === "mini" ? this.renderMiniModal() : this.renderDefaultModal();
    $6(this.options.container).append(modalHTML);
    this.initializeUI();
  },
  /*
   * 默认模式弹窗模板（完整布局：色相/饱和度面板 + 全部滑块 + 调色板/最近/收藏）
   */
  renderDefaultModal: function() {
    var uid = this.uid;
    return `
                <div class="modal fade ${this.options.modalClass}" id="colorPickerModal_${uid}" tabindex="-1" aria-labelledby="colorPickerModalLabel_${uid}" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content ws-color-picker-container">
                            <div class="modal-header">
                                <h5 class="modal-title" id="colorPickerModalLabel_${uid}">
                                    <i class="bi bi-palette me-2"></i>颜色选择器
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="d-flex justify-content-between align-items-center mb-4">
                                            <div class="d-flex align-items-center">
                                                <div class="ws-color-preview-bg"></div>
                                                <div id="colorPreview_${uid}" class="ws-color-preview me-3" style="background-color: ${this.options.initialColor};"></div>
                                                <div>
                                                    <select id="colorFormat_${uid}" class="form-select ws-color-format-select" aria-label="颜色格式">
                                                        <option value="hex">HEX</option>
                                                        <option value="rgb">RGB</option>
                                                        <option value="hsl">HSL</option>
                                                        <option value="hsv">HSV</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button id="addToFavorites_${uid}" class="btn btn-outline-secondary favorite-toggle-btn" type="button" title="收藏 / 取消收藏当前颜色" aria-pressed="false">
                                                <i class="bi bi-star"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label class="form-label" id="hsLabel_${uid}">色相和饱和度</label>
                                            <div id="hueSaturationPicker_${uid}" class="ws-hue-saturation" role="application" aria-label="色相和饱和度">
                                                <div id="hueSaturationPointer_${uid}" class="ws-picker-pointer" style="top: 0%; left: 100%;"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="ws-slider-container">
                                            <label for="lightnessSlider_${uid}" class="form-label">亮度</label>
                                            <div class="alpha-control">
                                            <input type="range" class="form-range alpha-slider" id="lightnessSlider_${uid}" min="0" max="100" value="50" aria-label="亮度">
                                            </div>
                                        </div>
                                        
                                        <div class="ws-slider-container">
                                            <label for="alphaSlider_${uid}" class="form-label">透明度</label>
                                            <div class="alpha-control">
                                                <div class="grid-bg"></div>
                                                <input type="range" class="form-range alpha-slider" id="alphaSlider_${uid}" min="0" max="100" value="100" aria-label="透明度">
                                            </div>
                                        </div>
                                        <div class="mt-4">
                                            <label for="hexInput_${uid}" class="form-label">十六进制颜色值</label>
                                            <div class="input-group">
                                                <span class="input-group-text">#</span>
                                                <input type="text" class="form-control ws-hex-input" id="hexInput_${uid}" value="${this.options.initialColor.replace("#", "")}" maxlength="7" placeholder="RRGGBB" autocomplete="off" aria-label="十六进制颜色值">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="mb-4">
                                            <label class="form-label" id="stdLabel_${uid}">标准调色板</label>
                                            <div id="standardPalette_${uid}" class="ws-color-palette" role="listbox" aria-label="标准调色板"></div>
                                            <div class="ribbon-picker">
                                                <div class="ribbon-bg"></div>
                                                <input type="range" class="form-range alpha-slider ribbon-slider" id="ribbonSlider_${uid}" min="0" max="100" value="100" aria-label="色相条">
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <label class="form-label">最近使用</label>
                                            <div id="recentColors_${uid}" class="ws-recent-colors" role="listbox" aria-label="最近使用"></div>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label class="form-label">收藏的颜色</label>
                                            <div id="favoriteColors_${uid}" class="ws-favorite-colors" role="listbox" aria-label="收藏的颜色"></div>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <label class="form-label">当前颜色值</label>
                                            <div id="colorValueDisplay_${uid}" class="ws-color-value-display">${this.options.initialColor}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" id="confirmColor_${uid}">确认选择</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
  },
  /*
   * 迷你模式弹窗模板（紧凑：仅 标准调色板 + 色相/亮度/透明度滑块 + 最近使用，
   * 以图标代替文字尽量压缩高度）
   */
  renderMiniModal: function() {
    var uid = this.uid;
    return `
                <div class="modal fade ${this.options.modalClass}" id="colorPickerModal_${uid}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-sm ws-color-picker-mini-dialog">
                        <div class="modal-content ws-color-picker-container ws-color-picker-mini">
                            <div class="modal-header py-1 px-2">
                                <h6 class="modal-title mb-0"><i class="bi bi-palette me-1"></i>颜色</h6>
                                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-2 pt-2">
                                <!-- 当前色预览 + HEX 快捷输入（预览必须为块级元素，行内 span 的宽高不生效导致不显示） -->
                                <div class="d-flex align-items-center gap-2 mini-current-row mb-2">
                                    <div class="position-relative mini-swatch-wrap">
                                        <div class="ws-color-preview-bg mini-bg"></div>
                                        <div id="colorPreview_${uid}" class="ws-color-preview mini-preview" style="background-color: ${this.options.initialColor};"></div>
                                    </div>
                                    <div class="input-group input-group-sm flex-grow-1">
                                        <span class="input-group-text px-2">#</span>
                                        <input type="text" class="form-control ws-hex-input" id="hexInput_${uid}" value="${this.options.initialColor.replace("#", "")}" maxlength="7" placeholder="RRGGBB" autocomplete="off" aria-label="十六进制颜色值">
                                    </div>
                                </div>

                                <!-- 标准调色板 -->
                                <div id="standardPalette_${uid}" class="ws-color-palette" role="listbox" aria-label="标准调色板"></div>

                                <!-- 滑块：色相条 / 亮度 / 透明度（图标代替文字） -->
                                <div class="mini-slider-row">
                                    <i class="bi bi-palette2" title="色相" aria-hidden="true"></i>
                                    <div class="ribbon-picker mini-ribbon flex-grow-1">
                                        <div class="ribbon-bg"></div>
                                        <input type="range" class="form-range alpha-slider ribbon-slider" id="ribbonSlider_${uid}" min="0" max="100" value="100" aria-label="色相">
                                    </div>
                                </div>
                                <div class="mini-slider-row">
                                    <i class="bi bi-brightness-high-fill" title="亮度" aria-hidden="true"></i>
                                    <div class="alpha-control">
                                        <input type="range" class="form-range alpha-slider" id="lightnessSlider_${uid}" min="0" max="100" value="50" aria-label="亮度">
                                    </div>
                                </div>
                                <div class="mini-slider-row">
                                    <i class="bi bi-circle-half" title="透明度" aria-hidden="true"></i>
                                    <div class="alpha-control">
                                        <div class="grid-bg"></div>
                                        <input type="range" class="form-range alpha-slider" id="alphaSlider_${uid}" min="0" max="100" value="100" aria-label="透明度">
                                    </div>
                                </div>

                                <!-- 最近使用 -->
                                <div id="recentColors_${uid}" class="ws-recent-colors" role="listbox" aria-label="最近使用"></div>
                            </div>
                            <div class="modal-footer py-1 px-2 justify-content-end">
                                <button type="button" class="btn btn-primary btn-sm" id="confirmColor_${uid}">
                                    <i class="bi bi-check-lg me-1"></i>确认
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
  },
  /* 
   * 初始化UI组件
   */
  initializeUI: function() {
    var standardPaletteHTML = "";
    this.standardColors.forEach(function(color) {
      standardPaletteHTML += '<div class="ws-color-swatch" data-color="' + color + '" style="background-color: ' + color + ';"></div>';
    });
    $6("#standardPalette_" + this.uid).html(standardPaletteHTML);
    this.updateRibbonSlider();
    this.updateRecentColorsUI();
    this.updateFavoriteColorsUI();
    this.updateColorFromHSLA(this.currentColor.h, this.currentColor.s, this.currentColor.l, this.currentColor.a);
    this.updateHueSaturationBackground();
    this.updateSelectedState();
    this.updateFavoriteButtonUI();
  },
  /*
   * 根据当前颜色是否在收藏中，同步顶部收藏按钮的图标与状态
   */
  updateFavoriteButtonUI: function() {
    var uid = this.uid;
    var $btn = $6("#addToFavorites_" + uid);
    if (!$btn.length) return;
    var hex = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
    var isFav = this.favoriteColors.indexOf(hex) !== -1;
    var $icon = $btn.find("i").first();
    $icon.toggleClass("bi-star", !isFav).toggleClass("bi-star-fill", isFav);
    $btn.toggleClass("btn-outline-secondary", !isFav).toggleClass("btn-warning", isFav).attr("aria-pressed", isFav ? "true" : "false").attr("title", isFav ? "取消收藏当前颜色" : "收藏当前颜色");
  },
  /**
   * 更新色带值
   */
  updateRibbonSlider: function() {
    var pos = this.getPositionFromColor(this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l));
    var percent = pos !== null ? parseInt(pos, 10) : 0;
    $6("#ribbonSlider_" + this.uid).val(percent);
  },
  /* 
   * 更新最近使用颜色UI
   */
  updateRecentColorsUI: function() {
    var recentColorsHTML = "";
    this.recentColors.forEach(function(color) {
      recentColorsHTML += '<div class="ws-color-swatch" data-color="' + color + '" style="background-color: ' + color + ';"></div>';
    });
    $6("#recentColors_" + this.uid).html(recentColorsHTML || '<div class="text-muted small">无最近使用的颜色</div>');
  },
  /* 
   * 更新收藏颜色UI
   */
  updateFavoriteColorsUI: function() {
    var favoriteColorsHTML = "";
    this.favoriteColors.forEach(function(color) {
      favoriteColorsHTML += '<div class="ws-color-swatch" data-color="' + color + '" style="background-color: ' + color + ';"></div>';
    });
    $6("#favoriteColors_" + this.uid).html(favoriteColorsHTML || '<div class="text-muted small">无收藏的颜色</div>');
  },
  /* 
   * 更新颜色从HSLA值
   * @param {number} h - 色相（0-360）
   * @param {number} s - 饱和度（0-100）
   * @param {number} l - 亮度（0-100）
   * @param {number} a - 透明度（0-1）
   */
  updateColorFromHSLA: function(h, s, l, a) {
    this.currentColor.h = h;
    this.currentColor.s = s;
    this.currentColor.l = l;
    this.currentColor.a = a;
    this.updateColorUI();
    if (typeof this.options.onColorChange === "function") {
      this.options.onColorChange(this.getCurrentColor());
    }
  },
  /* 
   * 更新颜色UI
   */
  updateColorUI: function() {
    var uid = this.uid;
    var hexColor = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
    var rgbaColor = "rgba(" + this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l).join(", ") + ", " + this.currentColor.a + ")";
    var rgblsColor = "rgba(" + this.hslToRgb(this.currentColor.h, this.currentColor.s, 0).join(", ") + ", " + this.currentColor.a + ")";
    var rgbleColor = "rgba(" + this.hslToRgb(this.currentColor.h, this.currentColor.s, 100).join(", ") + ", " + this.currentColor.a + ")";
    var rgbasColor = "rgba(" + this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l).join(", ") + ", 0)";
    var rgbaeColor = "rgba(" + this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l).join(", ") + ", 100)";
    $6("#colorPreview_" + uid).css("background-color", rgbaColor);
    this.updateRibbonSlider();
    this.updateColorValueDisplay();
    $6("#hexInput_" + uid).val(hexColor.replace("#", ""));
    $6("#lightnessSlider_" + uid).val(this.currentColor.l);
    $6("#lightnessSlider_" + uid).css("background", "linear-gradient(to right, " + rgblsColor + ", " + rgbleColor + ")");
    $6("#alphaSlider_" + uid).val(this.currentColor.a * 100);
    $6("#alphaSlider_" + uid).css("background", "linear-gradient(to right, " + rgbasColor + ", " + rgbaeColor + ")");
    this.updateHueSaturationPointer();
    this.updateHueSaturationBackground();
    this.updateSelectedState();
  },
  /* 
   * 更新色相饱和度选择器背景
   */
  updateHueSaturationBackground: function() {
    var hueColor = "hsl(" + this.currentColor.h + ", 100%, 50%)";
    $6("#hueSaturationPicker_" + this.uid).css(
      "background",
      "linear-gradient(to right, #fff, " + hueColor + "), linear-gradient(to top, #000, transparent)"
    );
  },
  /* 
   * 更新颜色值显示
   */
  updateColorValueDisplay: function() {
    var uid = this.uid;
    var format = $6("#colorFormat_" + uid).val();
    var colorValue = "";
    switch (format) {
      case "hex":
        colorValue = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
        if (this.currentColor.a < 1) {
          var alphaHex = Math.round(this.currentColor.a * 255).toString(16).padStart(2, "0");
          colorValue += alphaHex;
        }
        break;
      case "rgb":
        var rgb = this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l);
        if (this.currentColor.a === 1) {
          colorValue = "rgb(" + rgb[0] + ", " + rgb[1] + ", " + rgb[2] + ")";
        } else {
          colorValue = "rgba(" + rgb[0] + ", " + rgb[1] + ", " + rgb[2] + ", " + this.currentColor.a + ")";
        }
        break;
      case "hsl":
        if (this.currentColor.a === 1) {
          colorValue = "hsl(" + this.currentColor.h + ", " + this.currentColor.s + "%, " + this.currentColor.l + "%)";
        } else {
          colorValue = "hsla(" + this.currentColor.h + ", " + this.currentColor.s + "%, " + this.currentColor.l + "%, " + this.currentColor.a + ")";
        }
        break;
      case "hsv":
        var hsv = this.hslToHsv(this.currentColor.h, this.currentColor.s, this.currentColor.l);
        colorValue = "hsv(" + hsv[0] + ", " + hsv[1] + "%, " + hsv[2] + "%)";
        break;
    }
    $6("#colorValueDisplay_" + uid).text(colorValue);
  },
  /* 
   * 更新选择状态
   */
  updateSelectedState: function() {
    var uid = this.uid;
    var $modal = $6("#colorPickerModal_" + uid);
    $modal.find(".ws-color-swatch").removeClass("selected");
    var hex = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
    $modal.find('.ws-color-swatch[data-color="' + hex + '"]').addClass("selected");
  },
  /* 
   * 获取滑块当前位置的颜色
   * @param {number} percent - 滑块位置百分比 (0-100)
   * @returns {object} - 包含十六进制、RGB和位置的颜色对象
   */
  getColorAtPosition: function(percent) {
    const pos = Math.max(0, Math.min(100, percent)) / 100;
    for (let i = 0; i < gradientColors.length - 1; i++) {
      const start = gradientColors[i];
      const end = gradientColors[i + 1];
      if (pos >= start.pos && pos <= end.pos) {
        const segmentPos = (pos - start.pos) / (end.pos - start.pos);
        const r = Math.round(start.color[0] + (end.color[0] - start.color[0]) * segmentPos);
        const g = Math.round(start.color[1] + (end.color[1] - start.color[1]) * segmentPos);
        const b = Math.round(start.color[2] + (end.color[2] - start.color[2]) * segmentPos);
        return { hex: this.rgbToHex(r, g, b), rgb: [r, g, b], position: percent };
      }
    }
    return { hex: this.rgbToHex(gradientColors[0].color[0], gradientColors[0].color[1], gradientColors[0].color[2]), rgb: gradientColors[0].color, position: 0 };
  },
  /* 
   * 查找颜色所在的具体颜色段和精确位置
   * @param {Array} targetRgb - 目标颜色的RGB值数组 [r, g, b]
   * @returns {number|null} - 颜色所在的百分比位置 (0-100)，如果未找到则返回null
   */
  getPositionFromColor: function(targetRgb) {
    for (let i = 0; i < gradientColors.length - 1; i++) {
      const start = gradientColors[i];
      const end = gradientColors[i + 1];
      const t = this.findProjectionOnLine(targetRgb, start.color, end.color);
      if (t >= 0 && t <= 1) {
        const projR = Math.round(start.color[0] + (end.color[0] - start.color[0]) * t);
        const projG = Math.round(start.color[1] + (end.color[1] - start.color[1]) * t);
        const projB = Math.round(start.color[2] + (end.color[2] - start.color[2]) * t);
        if (this.colorDistance(targetRgb, [projR, projG, projB]) < 20) {
          const segmentPos = start.pos + (end.pos - start.pos) * t;
          return segmentPos * 100;
        }
      }
    }
    return null;
  },
  /* 
   * 计算两个颜色之间的欧几里得距离
   * @param {Array} rgb1 - 第一个颜色的RGB值数组 [r, g, b]
   * @param {Array} rgb2 - 第二个颜色的RGB值数组 [r, g, b]
   * @returns {number} - 两个颜色之间的距离值
   */
  colorDistance: function(rgb1, rgb2) {
    const dr = rgb1[0] - rgb2[0];
    const dg = rgb1[1] - rgb2[1];
    const db = rgb1[2] - rgb2[2];
    return Math.sqrt(dr * dr + dg * dg + db * db);
  },
  /* 
   * 计算点在直线上的投影参数t
   * @param {Array} point - 目标点的RGB值数组 [r, g, b]
   * @param {Array} lineStart - 直线起点的RGB值数组 [r, g, b]
   * @param {Array} lineEnd - 直线终点的RGB值数组 [r, g, b]
   * @returns {number} - 投影参数t (0-1)，表示点在线段上的位置比例
   */
  findProjectionOnLine: function(point, lineStart, lineEnd) {
    const lineVec = [
      lineEnd[0] - lineStart[0],
      lineEnd[1] - lineStart[1],
      lineEnd[2] - lineStart[2]
    ];
    const pointVec = [
      point[0] - lineStart[0],
      point[1] - lineStart[1],
      point[2] - lineStart[2]
    ];
    const lineLengthSq = lineVec[0] * lineVec[0] + lineVec[1] * lineVec[1] + lineVec[2] * lineVec[2];
    if (lineLengthSq === 0) return 0;
    const dotProduct = pointVec[0] * lineVec[0] + pointVec[1] * lineVec[1] + pointVec[2] * lineVec[2];
    return dotProduct / lineLengthSq;
  },
  /* 
   * 更新色相饱和度选择器指针位置
   */
  updateHueSaturationPointer: function() {
    var x = this.currentColor.s;
    var y = 100 - this.currentColor.l / 100 * 100;
    $6("#hueSaturationPointer_" + this.uid).css({
      left: x + "%",
      top: y + "%"
    });
  },
  /* 
   * RGB转十六进制
   * @param {number} r - 红色（0-255）
   * @param {number} g - 绿色（0-255）
   * @param {number} b - 蓝色（0-255）
   * @returns {string} - 十六进制颜色字符串（例如：#FF0000）
   */
  rgbToHex: function(r, g, b) {
    return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  },
  /* 
   * 十六进制转RGB
   * @param {string} hex - 十六进制颜色字符串（例如：#FF0000）
   * @returns {array} - RGB颜色数组，包含红色（0-255）、绿色（0-255）、蓝色（0-255）
   */
  hexToRgb: function(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? [
      parseInt(result[1], 16),
      parseInt(result[2], 16),
      parseInt(result[3], 16)
    ] : [0, 0, 0];
  },
  /* 
   * 将HSL颜色转换为十六进制
   * @param {number} h - 色相（0-360）
   * @param {number} s - 饱和度（0-100）
   * @param {number} l - 亮度（0-100）
   * @returns {string} - 十六进制颜色字符串（例如：#FF0000）
   */
  hslToHex: function(h, s, l) {
    var rgb = this.hslToRgb(h, s, l);
    return "#" + rgb.map(function(x) {
      var hex = Math.round(x).toString(16);
      return hex.length === 1 ? "0" + hex : hex;
    }).join("");
  },
  /* 
   * 将HSL颜色转换为RGB
   * @param {number} h - 色相（0-360）
   * @param {number} s - 饱和度（0-100）
   * @param {number} l - 亮度（0-100）
   * @returns {array} - RGB颜色数组，包含红色（0-255）、绿色（0-255）、蓝色（0-255）
   */
  hslToRgb: function(h, s, l) {
    h /= 360;
    s /= 100;
    l /= 100;
    var r, g, b;
    if (s === 0) {
      r = g = b = l;
    } else {
      var hue2rgb = function(p2, q2, t) {
        if (t < 0) t += 1;
        if (t > 1) t -= 1;
        if (t < 1 / 6) return p2 + (q2 - p2) * 6 * t;
        if (t < 1 / 2) return q2;
        if (t < 2 / 3) return p2 + (q2 - p2) * (2 / 3 - t) * 6;
        return p2;
      };
      var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      var p = 2 * l - q;
      r = hue2rgb(p, q, h + 1 / 3);
      g = hue2rgb(p, q, h);
      b = hue2rgb(p, q, h - 1 / 3);
    }
    return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
  },
  /* 
   * 将HSL颜色转换为HSV
   * @param {number} h - 色相（0-360）
   * @param {number} s - 饱和度（0-100）
   * @param {number} l - 亮度（0-100）
   * @returns {array} - HSV颜色数组，包含色相（0-360）、饱和度（0-100）、明度（0-100）
   */
  hslToHsv: function(h, s, l) {
    s /= 100;
    l /= 100;
    var v = l + s * Math.min(l, 1 - l);
    var sv = v === 0 ? 0 : 2 * (1 - l / v);
    return [h, Math.round(sv * 100), Math.round(v * 100)];
  },
  /* 
   * 将十六进制颜色转换为HSL
   * @param {string} hex - 十六进制颜色字符串（例如：#FF0000）
   * @returns {array} - HSL颜色数组，包含色相（0-360）、饱和度（0-100）、亮度（0-100）
   */
  hexToHsl: function(hex) {
    hex = hex.replace("#", "");
    if (hex.length === 3) {
      hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }
    var r = parseInt(hex.substr(0, 2), 16) / 255;
    var g = parseInt(hex.substr(2, 2), 16) / 255;
    var b = parseInt(hex.substr(4, 2), 16) / 255;
    var max = Math.max(r, g, b);
    var min = Math.min(r, g, b);
    var h, s, l = (max + min) / 2;
    if (max === min) {
      h = s = 0;
    } else {
      var d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      switch (max) {
        case r:
          h = (g - b) / d + (g < b ? 6 : 0);
          break;
        case g:
          h = (b - r) / d + 2;
          break;
        case b:
          h = (r - g) / d + 4;
          break;
      }
      h /= 6;
    }
    return [Math.round(h * 360), Math.round(s * 100), Math.round(l * 100)];
  },
  /* 
   * 绑定事件
   */
  bindEvents: function() {
    var self = this;
    var uid = this.uid;
    $6("#colorPickerModal_" + uid).on("hidden.bs.modal", function() {
      self._handleModalClosed();
    });
    $6("#hueSaturationPicker_" + uid).on("mousedown", function(e) {
      e.preventDefault();
      var $picker = $6(this);
      function handleMove(e2) {
        var offset2 = $picker.offset();
        var width = $picker.width();
        var height = $picker.height();
        var x = Math.max(0, Math.min(1, (e2.pageX - offset2.left) / width));
        var y = Math.max(0, Math.min(1, (e2.pageY - offset2.top) / height));
        var s = Math.round(x * 100);
        var l = Math.round((1 - y) * 100);
        self.updateColorFromHSLA(self.currentColor.h, s, l, self.currentColor.a);
      }
      function handleUp() {
        $6(document).off("mousemove", handleMove);
        $6(document).off("mouseup", handleUp);
      }
      handleMove(e);
      $6(document).on("mousemove", handleMove);
      $6(document).on("mouseup", handleUp);
    });
    $6("#lightnessSlider_" + uid).on("input", function() {
      var l = parseInt($6(this).val());
      self.updateColorFromHSLA(self.currentColor.h, self.currentColor.s, l, self.currentColor.a);
    });
    $6("#alphaSlider_" + uid).on("input", function() {
      var a = parseInt($6(this).val()) / 100;
      self.updateColorFromHSLA(self.currentColor.h, self.currentColor.s, self.currentColor.l, a);
    });
    $6("#hexInput_" + uid).on("input", function() {
      var hex = $6(this).val().trim().replace(/^#/, "");
      if (/^[0-9a-f]{3}$/i.test(hex)) {
        hex = hex.replace(/[0-9a-f]/gi, function(ch) {
          return ch + ch;
        });
      }
      if (/^[0-9A-F]{6}$/i.test(hex)) {
        var hsl = self.hexToHsl("#" + hex);
        self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
        $6(this).val(hex.toUpperCase());
      }
    });
    $6("#colorFormat_" + uid).on("change", function() {
      self.updateColorValueDisplay();
    });
    $6("#ribbonSlider_" + uid).on("input", function() {
      var color = self.getColorAtPosition($6(this).val()).hex;
      var hsl = self.hexToHsl(color);
      self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
    });
    $6(document).on("click", "#standardPalette_" + uid + " .ws-color-swatch", function() {
      var color = $6(this).data("color");
      var hsl = self.hexToHsl(color);
      self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
    });
    $6(document).on("click", "#recentColors_" + uid + " .ws-color-swatch", function() {
      var color = $6(this).data("color");
      var hsl = self.hexToHsl(color);
      self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
    });
    $6(document).on("click", "#favoriteColors_" + uid + " .ws-color-swatch", function() {
      var color = $6(this).data("color");
      var hsl = self.hexToHsl(color);
      self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
    });
    $6("#addToFavorites_" + uid).on("click", function() {
      var $btn = $6(this);
      var currentHex = self.hslToHex(self.currentColor.h, self.currentColor.s, self.currentColor.l);
      var idx = self.favoriteColors.indexOf(currentHex);
      if (idx === -1) {
        self.favoriteColors.unshift(currentHex);
        if (self.favoriteColors.length > 60) {
          self.favoriteColors.pop();
        }
      } else {
        self.favoriteColors.splice(idx, 1);
      }
      self._saveColorList(self.options.storagePrefix + ":favorites", self.favoriteColors);
      self.updateFavoriteColorsUI();
      self.updateFavoriteButtonUI();
      $btn.addClass("fav-pulse");
      setTimeout(function() {
        $btn.removeClass("fav-pulse");
      }, 500);
    });
    $6("#confirmColor_" + uid).on("click", function() {
      var currentHex = self.hslToHex(self.currentColor.h, self.currentColor.s, self.currentColor.l);
      self._confirmFlag = true;
      var recentIdx = self.recentColors.indexOf(currentHex);
      if (recentIdx !== -1) {
        self.recentColors.splice(recentIdx, 1);
      }
      self.recentColors.unshift(currentHex);
      if (self.recentColors.length > self.options.maxRecent) {
        self.recentColors.pop();
      }
      self._saveColorList(self.options.storagePrefix + ":recent", self.recentColors);
      self.updateRecentColorsUI();
      if (typeof self.options.onColorSelect === "function") {
        self.options.onColorSelect(self.getCurrentColor());
      }
      self.close();
    });
  },
  /*
   * 弹窗关闭后的会话分流：判断本次是「点击确认」还是「取消/关闭」，
   * 并按需还原颜色、触发 onCancel / onClose 回调。
   */
  _handleModalClosed: function() {
    if (!this._sessionOpen) return;
    this._sessionOpen = false;
    var confirmed = !!this._confirmFlag;
    this._confirmFlag = false;
    var lastColor = this.getCurrentColor();
    var originalColor = this._sessionOriginalColor || lastColor;
    var changed = !this._sameColor(lastColor, originalColor);
    if (!confirmed && this.options.resetOnCancel && changed) {
      this.updateColorFromHSLA(
        originalColor.h,
        originalColor.s,
        originalColor.l,
        originalColor.a
      );
    }
    if (!confirmed && typeof this.options.onCancel === "function") {
      this.options.onCancel(this.getCurrentColor(), originalColor, {
        changed,
        lastColor
      });
    }
    if (typeof this.options.onClose === "function") {
      this.options.onClose({
        action: confirmed ? "confirm" : "cancel",
        confirmed,
        originalColor,
        color: this.getCurrentColor(),
        lastColor,
        changed
      });
    }
  },
  /*
   * 比较两个颜色对象（hex 与透明度）是否相同
   */
  _sameColor: function(a, b) {
    return !!a && !!b && String(a.hex).toLowerCase() === String(b.hex).toLowerCase() && Math.abs(Number(a.a) - Number(b.a)) < 1e-6;
  },
  /* 
   * 获取当前颜色
   * @returns {object} - 当前颜色的对象，包含十六进制、RGB、RGBA、HSL、HSLA、H、S、L、A等属性
   */
  getCurrentColor: function() {
    var hex = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
    var rgb = this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l);
    var rgba = "rgba(" + rgb[0] + ", " + rgb[1] + ", " + rgb[2] + ", " + this.currentColor.a + ")";
    return {
      hex,
      rgb: "rgb(" + rgb[0] + ", " + rgb[1] + ", " + rgb[2] + ")",
      rgba,
      hsl: "hsl(" + this.currentColor.h + ", " + this.currentColor.s + "%, " + this.currentColor.l + "%)",
      hsla: "hsla(" + this.currentColor.h + ", " + this.currentColor.s + "%, " + this.currentColor.l + "%, " + this.currentColor.a + ")",
      h: this.currentColor.h,
      s: this.currentColor.s,
      l: this.currentColor.l,
      a: this.currentColor.a
    };
  },
  /* 
   * 打开颜色选择器
   */
  open: function() {
    this.recentColors = this._loadColorList(this.options.storagePrefix + ":recent");
    this.favoriteColors = this._loadColorList(this.options.storagePrefix + ":favorites");
    this.updateRecentColorsUI();
    this.updateFavoriteColorsUI();
    this.updateFavoriteButtonUI();
    this._sessionOpen = true;
    this._confirmFlag = false;
    this._sessionOriginalColor = this.getCurrentColor();
    new bootstrap.Modal("#colorPickerModal_" + this.uid).show();
    this.updateSelectedState();
  },
  /* 
   * 关闭颜色选择器
   */
  close: function() {
    $6("#colorPickerModal_" + this.uid).modal("hide");
  },
  /* 
   * 设置颜色
   * @param {string} color - 要设置的颜色，格式为十六进制字符串（例如：#FF0000）
   */
  setColor: function(color) {
    var hsl = this.hexToHsl(color);
    this.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], this.currentColor.a);
  },
  // 销毁实例
  destroy: function() {
    var uid = this.uid;
    $6(document).off("click", "#standardPalette_" + uid + " .ws-color-swatch");
    $6(document).off("click", "#recentColors_" + uid + " .ws-color-swatch");
    $6(document).off("click", "#favoriteColors_" + uid + " .ws-color-swatch");
    $6("#colorPickerModal_" + uid).remove();
    $6("#ws-color-picker-styles").remove();
  }
};
window.WsColorPicker = WsColorPicker;
var ws_color_picker_default = WsColorPicker;

// public/statics/libs/ws-date-picker/ws-date-picker.js
var $7 = window.jQuery;
if (typeof $7 === "undefined") {
  throw new Error("WsDatePicker requires jQuery");
}
var defaults = {
  type: "date",
  // 日期类型：date, datetime, time, year, month
  // 日期格式
  format: "yyyy-MM-dd",
  // 日期格式
  value: null,
  // 初始值
  min: null,
  // 最小日期，格式为字符串或 Date 对象
  max: null,
  // 最大日期，格式为字符串或 Date 对象
  range: false,
  // 是否启用日期范围选择
  rangeSeparator: " - ",
  // 日期范围分隔符
  mark: {},
  // 标记日期函数，格式为 { 'yyyy-MM-dd': '标记内容' }
  disabledDate: null,
  // 禁用日期函数，格式为函数，返回 true 禁用该日期
  disabledTime: null,
  // 禁用时间，格式为函数，返回 true 禁用该时间
  timeFormat: "HH:mm",
  // 时间格式
  timeSteps: [1, 1, 1],
  // 时间步长，[时, 分, 秒]
  popup: true,
  // 日期选择器位置：true（弹窗）, false（静态）
  // 语言
  lang: "zh-CN",
  // 语言：zh-CN, en
  weekStart: 0,
  // 周开始：0（周日），1（周一）
  showToday: true,
  // 是否显示“今天”按钮
  showClear: true,
  // 是否显示“清空”按钮
  showConfirm: true,
  // 是否显示“确定”按钮
  showQuickSelect: true,
  // 是否显示快速选择按钮
  // 事件回调
  onChange: null,
  // 日期改变回调函数
  onSelect: null,
  // 选择日期回调函数
  onOpen: null,
  // 打开日期选择器回调函数
  onClose: null
  // 关闭日期选择器回调函数
};
var languages = {
  "zh-CN": {
    days: ["日", "一", "二", "三", "四", "五", "六"],
    daysShort: ["日", "一", "二", "三", "四", "五", "六"],
    months: ["一月", "二月", "三月", "四月", "五月", "六月", "七月", "八月", "九月", "十月", "十一月", "十二月"],
    monthsShort: ["1月", "2月", "3月", "4月", "5月", "6月", "7月", "8月", "9月", "10月", "11月", "12月"],
    today: "今天",
    yesterday: "昨天",
    tomorrow: "明天",
    thisWeek: "本周",
    lastWeek: "上周",
    thisMonth: "本月",
    lastMonth: "上月",
    thisYear: "今年",
    lastYear: "去年",
    clear: "清空",
    confirm: "确定",
    selectDate: "选择日期",
    selectTime: "选择时间",
    startDate: "开始日期",
    endDate: "结束日期",
    hour: "时",
    minute: "分",
    second: "秒"
  },
  "en": {
    days: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
    daysShort: ["S", "M", "T", "W", "T", "F", "S"],
    months: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
    monthsShort: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
    today: "Today",
    yesterday: "Yesterday",
    tomorrow: "Tomorrow",
    thisWeek: "This Week",
    lastWeek: "Last Week",
    thisMonth: "This Month",
    lastMonth: "Last Month",
    thisYear: "This Year",
    lastYear: "Last Year",
    clear: "Clear",
    confirm: "OK",
    selectDate: "Select Date",
    selectTime: "Select Time",
    startDate: "Start Date",
    endDate: "End Date",
    hour: "Hour",
    minute: "Minute",
    second: "Second"
  }
};
var utils = {
  /**
   * 格式化日期为指定格式字符串
   * @param {Date} date - 日期对象
   * @param {string} format - 日期格式，默认 'yyyy-MM-dd'
   * @returns {string} 格式化后的日期字符串
   */
  formatDate: function(date, format) {
    if (!date || !(date instanceof Date)) return "";
    const pad = function(num) {
      return num < 10 ? "0" + num : num;
    };
    const year = date.getFullYear();
    const month = date.getMonth() + 1;
    const day = date.getDate();
    const hours = date.getHours();
    const minutes = date.getMinutes();
    const seconds = date.getSeconds();
    return format.replace("yyyy", year).replace("yy", String(year).slice(-2)).replace("MM", pad(month)).replace("M", month).replace("dd", pad(day)).replace("d", day).replace("HH", pad(hours)).replace("H", hours).replace("mm", pad(minutes)).replace("m", minutes).replace("ss", pad(seconds)).replace("s", seconds);
  },
  /**
   * 解析日期字符串为 Date 对象
   * 支持格式：yyyy-mm-dd, yyyy-mm-dd hh:mm, yyyy-mm-dd hh:mm:ss
   * @param {string|Date} value - 日期字符串或 Date 对象
   * @param {string} format - 日期格式，默认 'yyyy-MM-dd'
   * @returns {Date|null} 解析后的 Date 对象，失败返回 null
   */
  parseDate: function(value, format) {
    if (!value) return null;
    if (typeof value === "string") {
      const match = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/);
      if (match) {
        const year = parseInt(match[1]);
        const month = parseInt(match[2]) - 1;
        const day = parseInt(match[3]);
        const hours = match[4] ? parseInt(match[4]) : 0;
        const minutes = match[5] ? parseInt(match[5]) : 0;
        const seconds = match[6] ? parseInt(match[6]) : 0;
        return new Date(year, month, day, hours, minutes, seconds);
      }
      const date = new Date(value);
      if (!isNaN(date.getTime())) {
        return date;
      }
    } else if (value instanceof Date) {
      return value;
    }
    return null;
  },
  /**
   * 检查两个日期是否为同一天
   * @param {Date} date1 - 日期对象1
   * @param {Date} date2 - 日期对象2
   * @returns {boolean} 如果是同一天则返回 true，否则返回 false
   */
  isSameDay: function(date1, date2) {
    if (!date1 || !date2) return false;
    return date1.getFullYear() === date2.getFullYear() && date1.getMonth() === date2.getMonth() && date1.getDate() === date2.getDate();
  },
  /**
   * 获取指定月份的天数
   * @param {number} year - 年份
   * @param {number} month - 月份（0-11）
   * @returns {number} 该月的天数
   */
  getDaysInMonth: function(year, month) {
    return new Date(year, month + 1, 0).getDate();
  },
  /**
   * 向日期添加指定天数
   * @param {Date} date - 日期对象
   * @param {number} days - 要添加的天数
   * @returns {Date} 新的日期对象
   */
  addDays: function(date, days) {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
  },
  /**
   * 向日期添加指定月份
   * @param {Date} date - 日期对象
   * @param {number} months - 要添加的月份数
   * @returns {Date} 新的日期对象
   */
  addMonths: function(date, months) {
    const result = new Date(date);
    result.setMonth(result.getMonth() + months);
    return result;
  },
  addYears: function(date, years) {
    const result = new Date(date);
    result.setFullYear(result.getFullYear() + years);
    return result;
  },
  getWeekStart: function(date, weekStart) {
    const day = date.getDay();
    const diff = (day < weekStart ? 7 : 0) + day - weekStart;
    return utils.addDays(date, -diff);
  },
  getWeekEnd: function(date, weekStart) {
    const start = utils.getWeekStart(date, weekStart);
    return utils.addDays(start, 6);
  },
  // 将日期格式化为 yyyy-MM-dd 字符串，用于 mark 键
  toYMD: function(date) {
    const year = date.getFullYear();
    const month = (date.getMonth() + 1).toString().padStart(2, "0");
    const day = date.getDate().toString().padStart(2, "0");
    return `${year}-${month}-${day}`;
  },
  // 检查日期是否在 min/max 范围内
  isDateInRange: function(date, min, max) {
    if (!date) return false;
    const time = date.getTime();
    if (min && min.getTime && time < min.getTime()) return false;
    if (max && max.getTime && time > max.getTime()) return false;
    return true;
  },
  // 解析 min/max 配置为 Date 对象
  parseRangeLimit: function(limit) {
    if (!limit) return null;
    if (limit instanceof Date) return new Date(limit);
    if (typeof limit === "string") {
      const d = new Date(limit);
      return isNaN(d.getTime()) ? null : d;
    }
    return null;
  },
  /**
   * 严格解析用户手动输入的日期字符串。
   * 与 parseDate 不同，这里会拒绝 2026-13-45 / 2026-02-30 这类
   * 被 new Date() 自动进位"修正"的非法日期。
   * @param {string} value 手动输入值
   * @returns {Date|null} 合法返回 Date，否则返回 null
   */
  parseStrict: function(value) {
    if (!value) return null;
    const str = String(value).trim();
    const m = str.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/);
    if (m) {
      const year = parseInt(m[1], 10);
      const month = parseInt(m[2], 10);
      const day = parseInt(m[3], 10);
      if (month < 1 || month > 12) return null;
      const maxDay = new Date(year, month, 0).getDate();
      if (day < 1 || day > maxDay) return null;
      const hours = m[4] !== void 0 ? parseInt(m[4], 10) : 0;
      const minutes = m[5] !== void 0 ? parseInt(m[5], 10) : 0;
      const seconds = m[6] !== void 0 ? parseInt(m[6], 10) : 0;
      if (hours > 23 || minutes > 59 || seconds > 59) return null;
      return new Date(year, month - 1, day, hours, minutes, seconds);
    }
    const d = new Date(str);
    return isNaN(d.getTime()) ? null : d;
  }
};
var currentOpenPicker = null;
var WsDatePicker = class {
  constructor(element, options2) {
    this.$element = $7(element);
    this.options = $7.extend({}, defaults, options2);
    this.options.value = this.options.value || this.$element.val() || "";
    this.lang = languages[this.options.lang] || languages["zh-CN"];
    this.currentView = this.options.type === "time" ? "time" : "date";
    this.currentDate = /* @__PURE__ */ new Date();
    this.minDate = utils.parseRangeLimit(this.options.min);
    this.maxDate = utils.parseRangeLimit(this.options.max);
    this.selectedDate = null;
    this.rangeStart = null;
    this.rangeEnd = null;
    this._rViewStart = null;
    this._rViewEnd = null;
    this.isSelectingRange = false;
    this.selectedTime = { hours: 0, minutes: 0, seconds: 0 };
    this.isOpen = false;
    this.init();
  }
  // 初始化组件
  init() {
    if (this.options.value) {
      const date = utils.parseDate(this.options.value, this.options.format);
      if (date) {
        this.selectedDate = date;
        this.currentDate = new Date(date);
        this.selectedTime = {
          hours: date.getHours(),
          minutes: date.getMinutes(),
          seconds: date.getSeconds()
        };
        this.updateInput();
      }
    }
    if (this.options.range && this.options.value) {
      const parts = String(this.options.value).split(this.options.rangeSeparator);
      if (parts.length === 2) {
        this.rangeStart = utils.parseDate(parts[0].trim(), this.options.format);
        this.rangeEnd = utils.parseDate(parts[1].trim(), this.options.format);
        if (this.rangeStart) {
          this.currentDate = new Date(this.rangeStart);
        }
      }
    }
    if (!this.selectedDate && !this.rangeStart && !this.rangeEnd) {
      this.selectedDate = /* @__PURE__ */ new Date();
    }
    this.$container = $7('<div class="ws-date-container"></div>');
    if (this.options.popup === false) {
      this.$container.addClass("ws-date-static");
      this.$element.after(this.$container);
      this.render();
    } else {
      this.$element.wrap(this.$container);
      this.$container = this.$element.parent();
      this.bindEvents();
    }
  }
  /**
  * 设置选项
  * @param {string} name - 选项名
  * @param {*} value - 选项值
  */
  setOption(name, value) {
    this.options[name] = value;
    if (this.options.range) {
      this.options.format = this.options.rangeFormat || this.options.format;
    }
    this.init();
  }
  /**
  * 绑定事件
  */
  bindEvents() {
    const self = this;
    this.$element.on("click focus", function(e) {
      e.stopPropagation();
      if (!self.isOpen) {
        self.show();
      }
    });
    $7(document).on("click.wsdate touchstart.wsdate", function(e) {
      if (self.isOpen && !$7(e.target).closest(".ws-date-container").length) {
        self.hide();
      }
    });
    this.$container.on("click touchstart", function(e) {
      e.stopPropagation();
    });
    $7(document).on("keydown.wsdate", function(e) {
      if (e.key === "Escape" && self.isOpen) {
        self.hide();
      }
    });
    this.$element.on("blur", function() {
      self.validateManualInput();
    });
    this.$element.on("keydown", function(e) {
      if (e.key === "Enter") {
        e.preventDefault();
        self.validateManualInput();
        if (self.isOpen) {
          self.hide();
        }
      }
    });
  }
  /**
  * 渲染选择器
  */
  render() {
    if (this.$dropdown) {
      this.$dropdown.remove();
    }
    this.$dropdown = $7('<div class="ws-date-dropdown"></div>');
    if (this.options.range && this.currentView === "date") {
      this.renderRangeView();
    } else {
      switch (this.currentView) {
        case "year":
          this.renderYearView();
          break;
        case "month":
          this.renderMonthView();
          break;
        case "time":
          this.renderTimeView();
          break;
        default:
          this.renderDateView();
      }
    }
    this.$container.append(this.$dropdown);
    if (this.options.showQuickSelect && this.currentView === "date") {
      this.renderQuickSelect();
    }
  }
  /**
  * 渲染快速选择栏
  */
  renderQuickSelect() {
    const $quickSelect = $7('<div class="ws-date-quick-select"></div>');
    const $buttons = $7('<div class="ws-date-quick-buttons"></div>');
    if (this.options.range) {
      const rangeOptions = [
        { text: this.lang.today, value: "today" },
        { text: this.lang.yesterday, value: "yesterday" },
        { text: this.lang.thisWeek, value: "thisWeek" },
        { text: this.lang.lastWeek, value: "lastWeek" },
        { text: this.lang.thisMonth, value: "thisMonth" },
        { text: this.lang.lastMonth, value: "lastMonth" }
      ];
      rangeOptions.forEach((option) => {
        $buttons.append(
          $7('<button class="btn btn-sm btn-outline-secondary" type="button"></button>').text(option.text).on("click", () => this.handleQuickSelect(option.value))
        );
      });
    } else {
      const dateOptions = [
        { text: this.lang.today, value: "today" },
        { text: this.lang.yesterday, value: "yesterday" },
        { text: this.lang.tomorrow, value: "tomorrow" },
        { text: this.lang.thisWeek, value: "thisWeek" },
        { text: this.lang.lastWeek, value: "lastWeek" }
      ];
      dateOptions.forEach((option) => {
        $buttons.append(
          $7('<button class="btn btn-sm btn-outline-secondary" type="button"></button>').text(option.text).on("click", () => this.handleQuickSelect(option.value))
        );
      });
    }
    $quickSelect.append($buttons);
    this.$dropdown.prepend($quickSelect);
  }
  /**
  * 处理快速选择
  * @param {string} type - 快速选择类型
  */
  handleQuickSelect(type2) {
    const today = /* @__PURE__ */ new Date();
    let startDate, endDate;
    switch (type2) {
      case "today":
        startDate = endDate = today;
        break;
      case "yesterday":
        startDate = endDate = utils.addDays(today, -1);
        break;
      case "tomorrow":
        startDate = endDate = utils.addDays(today, 1);
        break;
      case "thisWeek":
        startDate = utils.getWeekStart(today, this.options.weekStart);
        endDate = utils.getWeekEnd(today, this.options.weekStart);
        break;
      case "lastWeek":
        const lastWeek = utils.addDays(today, -7);
        startDate = utils.getWeekStart(lastWeek, this.options.weekStart);
        endDate = utils.getWeekEnd(lastWeek, this.options.weekStart);
        break;
      case "thisMonth":
        startDate = new Date(today.getFullYear(), today.getMonth(), 1);
        endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        break;
      case "lastMonth":
        const lastMonth = utils.addMonths(today, -1);
        startDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1);
        endDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0);
        break;
    }
    if (this.options.range) {
      if (startDate) startDate.setHours(0, 0, 0, 0);
      if (endDate) endDate.setHours(0, 0, 0, 0);
      this.rangeStart = startDate;
      this.rangeEnd = endDate;
      this._rViewStart = startDate ? new Date(startDate.getFullYear(), startDate.getMonth(), 1) : null;
      this._rViewEnd = endDate ? new Date(endDate.getFullYear(), endDate.getMonth(), 1) : null;
    } else {
      this.selectedDate = startDate;
      this.selectedTime = {
        hours: startDate.getHours(),
        minutes: startDate.getMinutes(),
        seconds: startDate.getSeconds()
      };
    }
    this.currentDate = new Date(startDate);
    if (this.options.type === "datetime" && !this.options.range) {
      this.currentView = "time";
      this.render();
      return;
    }
    this.updateContent();
    if (this.options.showConfirm && this.options.popup !== false) {
      return;
    }
    if (!this.options.range && type2 !== "thisWeek" && type2 !== "lastWeek" && type2 !== "thisMonth" && type2 !== "lastMonth") {
      this.confirm();
    } else if (this.options.range && !this.isSelectingRange) {
      this.confirm();
    }
  }
  /**
  * 更新选中的日期时间
  */
  updateSelectedDateTime() {
    if (this.selectedDate) {
      this.selectedDate.setHours(this.selectedTime.hours);
      this.selectedDate.setMinutes(this.selectedTime.minutes);
      this.selectedDate.setSeconds(this.selectedTime.seconds);
    }
    if (this.options.onSelect && this.selectedDate) {
      const value = utils.formatDate(this.selectedDate, this.options.format);
      this.options.onSelect(this.selectedDate, value);
    }
  }
  /**
  * 渲染日期选择器视图
  */
  renderDateView() {
    const self = this;
    const today = /* @__PURE__ */ new Date();
    const year = this.currentDate.getFullYear();
    const month = this.currentDate.getMonth();
    const $header = $7('<div class="ws-date-header"></div>');
    const $navStart = $7('<div class="ws-date-nav-start"></div>');
    $navStart.append(
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一年"><i class="bi bi-chevron-double-left"></i></button>').on("click", (e) => {
        e.stopPropagation();
        this.currentDate.setFullYear(this.currentDate.getFullYear() - 1);
        this.updateContent();
      }),
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一月"><i class="bi bi-chevron-left"></i></button>').on("click", (e) => {
        e.stopPropagation();
        this.currentDate.setMonth(this.currentDate.getMonth() - 1);
        this.updateContent();
      })
    );
    const $title = $7(`<h6 class="ws-date-title">${year}年 ${month + 1}月</h6>`).on("click", () => {
      this.currentView = "month";
      this.render();
    });
    const $navEnd = $7('<div class="ws-date-nav-end"></div>');
    $navEnd.append(
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一月"><i class="bi bi-chevron-right"></i></button>').on("click", (e) => {
        e.stopPropagation();
        this.currentDate.setMonth(this.currentDate.getMonth() + 1);
        this.updateContent();
      }),
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一年"><i class="bi bi-chevron-double-right"></i></button>').on("click", (e) => {
        e.stopPropagation();
        this.currentDate.setFullYear(this.currentDate.getFullYear() + 1);
        this.updateContent();
      })
    );
    $header.append($navStart, $title, $navEnd);
    const $body = $7('<div class="ws-date-body"></div>');
    const $table = $7('<table class="ws-date-calendar"></table>');
    const $thead = $7("<thead></thead>");
    const $tbody = $7("<tbody></tbody>");
    const $trHead = $7("<tr></tr>");
    const days = this.lang.daysShort;
    for (let i = 0; i < 7; i++) {
      const dayIndex = (i + this.options.weekStart) % 7;
      $trHead.append($7("<th></th>").text(days[dayIndex]));
    }
    $thead.append($trHead);
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDay = (firstDay.getDay() - this.options.weekStart + 7) % 7;
    const daysInMonth = lastDay.getDate();
    const totalCells = startDay + daysInMonth;
    const rows = Math.ceil(totalCells / 7);
    let date = 1;
    for (let i = 0; i < rows; i++) {
      const $tr = $7("<tr></tr>");
      for (let j = 0; j < 7; j++) {
        const $td = $7("<td></td>");
        let dayClass = "ws-date-day";
        let dayText = "";
        let dayDate = null;
        let isDisabled = false;
        let navigateMonth = 0;
        if (i === 0 && j < startDay) {
          dayClass += " other-month";
          navigateMonth = -1;
          dayDate = new Date(year, month - 1, lastDay.getDate() - startDay + j + 1);
          dayText = dayDate.getDate();
          isDisabled = true;
        } else if (date > daysInMonth) {
          dayText = date - daysInMonth;
          dayClass += " other-month";
          navigateMonth = 1;
          dayDate = new Date(year, month + 1, date - daysInMonth);
          isDisabled = true;
          date++;
        } else {
          dayText = date;
          dayDate = new Date(year, month, date);
          const inRange = utils.isDateInRange(dayDate, this.minDate, this.maxDate);
          if (!inRange) {
            isDisabled = true;
          }
          if (!isDisabled && typeof this.options.disabledDate === "function" && this.options.disabledDate(dayDate)) {
            isDisabled = true;
          }
          if (today.getFullYear() === year && today.getMonth() === month && today.getDate() === date) {
            dayClass += " today";
          }
          if (this.selectedDate && utils.isSameDay(this.selectedDate, dayDate)) {
            dayClass += " selected";
          }
          const ymd = utils.toYMD(dayDate);
          if (this.options.mark && this.options.mark[ymd]) {
            dayClass += " ws-date-marked";
            $td.data("mark", this.options.mark[ymd]);
          }
          date++;
        }
        if (navigateMonth !== 0) {
          dayClass += " navigate-month";
        }
        if (isDisabled && navigateMonth === 0) {
          dayClass += " disabled";
        }
        const $dayDiv = $7(`<div class="${dayClass}">${dayText}</div>`);
        if (dayDate) {
          $dayDiv.data("date", dayDate.toISOString());
        }
        $td.append($dayDiv);
        if (navigateMonth !== 0 && dayDate) {
          (function(d) {
            $td.on("click", function() {
              self.handleDateClick(d);
            });
          })(dayDate);
        } else if (dayDate && !isDisabled) {
          $td.on("click", function() {
            self.handleDateClick(dayDate);
          });
        }
        $tr.append($td);
      }
      $tbody.append($tr);
    }
    $table.append($thead, $tbody);
    $body.append($table);
    const $footer = this.createFooter();
    this.$dropdown.append($header, $body, $footer);
  }
  /**
  * 渲染日期范围选择器视图
  */
  renderRangeView() {
    if (!this._rViewStart) {
      this._rViewStart = this._viewMonthOf(this.rangeStart || /* @__PURE__ */ new Date());
    }
    if (!this._rViewEnd) {
      this._rViewEnd = this.rangeEnd ? this._viewMonthOf(this.rangeEnd) : new Date(this._rViewStart.getFullYear(), this._rViewStart.getMonth() + 1, 1);
    }
    const startDate = new Date(this._rViewStart);
    const endDate = new Date(this._rViewEnd);
    const $rangeContainer = $7('<div class="ws-date-range"></div>');
    const $startPanel = this.createRangePanel(startDate, "start");
    const $endPanel = this.createRangePanel(endDate, "end");
    $rangeContainer.append($startPanel, $endPanel);
    const $footer = this.createFooter();
    this.$dropdown.append($rangeContainer, $footer);
  }
  /**
  * 取日期所在月的面板视图基准（当月 1 号 00:00）
  */
  _viewMonthOf(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
  }
  /**
  * 创建日期范围选择器面板
  * @param {Date} date - 面板显示的日期
  * @param {string} type - 面板类型（start或end）
  * @returns {jQuery} - 包含面板HTML的jQuery对象
  */
  createRangePanel(date, type2) {
    const self = this;
    const today = /* @__PURE__ */ new Date();
    const year = date.getFullYear();
    const month = date.getMonth();
    const $panel = $7('<div class="ws-date-calendar-panel"></div>');
    const $panelType = $7('<div class="ws-date-panel-type"></div>').text(type2 === "start" ? self.lang.startDate : self.lang.endDate);
    $panel.append($panelType);
    const $header = $7('<div class="ws-date-header"></div>');
    const $title = $7(`<h6 class="ws-date-title">${year}年 ${month + 1}月</h6>`);
    const shiftMonthYear = (dm, dy) => {
      const field = type2 === "start" ? "_rViewStart" : "_rViewEnd";
      const cur = self[field] || new Date(year, month, 1);
      self[field] = new Date(cur.getFullYear() + (dy || 0), cur.getMonth() + (dm || 0), 1);
      self.updateContent();
    };
    const $navStart = $7('<div class="ws-date-nav-start"></div>');
    $navStart.append(
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一年"><i class="bi bi-chevron-double-left"></i></button>').on("click", (e) => {
        e.stopPropagation();
        shiftMonthYear(0, -1);
      }),
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一月"><i class="bi bi-chevron-left"></i></button>').on("click", (e) => {
        e.stopPropagation();
        shiftMonthYear(-1, 0);
      })
    );
    const $navEnd = $7('<div class="ws-date-nav-end"></div>');
    $navEnd.append(
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一月"><i class="bi bi-chevron-right"></i></button>').on("click", (e) => {
        e.stopPropagation();
        shiftMonthYear(1, 0);
      }),
      $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一年"><i class="bi bi-chevron-double-right"></i></button>').on("click", (e) => {
        e.stopPropagation();
        shiftMonthYear(0, 1);
      })
    );
    $header.append($navStart, $title, $navEnd);
    const $body = $7('<div class="ws-date-body"></div>');
    const $table = $7('<table class="ws-date-calendar"></table>');
    const $thead = $7("<thead></thead>");
    const $tbody = $7("<tbody></tbody>");
    const $trHead = $7("<tr></tr>");
    const days = this.lang.daysShort;
    for (let i = 0; i < 7; i++) {
      const dayIndex = (i + this.options.weekStart) % 7;
      $trHead.append($7("<th></th>").text(days[dayIndex]));
    }
    $thead.append($trHead);
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDay = (firstDay.getDay() - this.options.weekStart + 7) % 7;
    const daysInMonth = lastDay.getDate();
    const totalCells = startDay + daysInMonth;
    const rows = Math.ceil(totalCells / 7);
    let dateNum = 1;
    for (let i = 0; i < rows; i++) {
      const $tr = $7("<tr></tr>");
      for (let j = 0; j < 7; j++) {
        const $td = $7("<td></td>");
        let dayClass = "ws-date-day";
        let dayText = "";
        let dayDate = null;
        let isDisabled = false;
        let navigateMonth = 0;
        if (i === 0 && j < startDay) {
          dayClass += " other-month navigate-month";
          navigateMonth = -1;
          dayDate = new Date(year, month - 1, lastDay.getDate() - startDay + j + 1);
          dayText = dayDate.getDate();
          isDisabled = true;
        } else if (dateNum > daysInMonth) {
          dayText = dateNum - daysInMonth;
          dayClass += " other-month navigate-month";
          navigateMonth = 1;
          dayDate = new Date(year, month + 1, dateNum - daysInMonth);
          isDisabled = true;
          dateNum++;
        } else {
          dayText = dateNum;
          dayDate = new Date(year, month, dateNum);
          const inRange = utils.isDateInRange(dayDate, this.minDate, this.maxDate);
          if (!inRange) {
            isDisabled = true;
          }
          if (!isDisabled && this.options.disabledDate && this.options.disabledDate(dayDate)) {
            isDisabled = true;
          }
          if (today.getFullYear() === year && today.getMonth() === month && today.getDate() === dateNum) {
            dayClass += " today";
          }
          if (this.rangeStart && !this.rangeEnd && dayDate.getTime() === this.rangeStart.getTime()) {
            dayClass += " selected";
          }
          if (!this.rangeStart && this.rangeEnd && dayDate.getTime() === this.rangeEnd.getTime()) {
            dayClass += " selected";
          }
          if (this.rangeStart && this.rangeEnd) {
            const dayTime = dayDate.getTime();
            const startTime = this.rangeStart.getTime();
            const endTime = this.rangeEnd.getTime();
            if (dayTime === startTime) {
              dayClass += " ws-date-range-start";
            } else if (dayTime === endTime) {
              dayClass += " ws-date-range-end";
            } else if (dayTime > startTime && dayTime < endTime) {
              dayClass += " ws-date-in-range";
            }
          }
          const ymd = utils.toYMD(dayDate);
          if (this.options.mark && this.options.mark[ymd]) {
            dayClass += " ws-date-marked";
            $td.data("mark", this.options.mark[ymd]);
          }
          dateNum++;
        }
        if (isDisabled && navigateMonth === 0) {
          dayClass += " disabled";
        }
        const $dayDiv = $7(`<div class="${dayClass}">${dayText}</div>`);
        if (dayDate) {
          $dayDiv.data("date", dayDate.toISOString());
        }
        $td.append($dayDiv);
        if (navigateMonth !== 0) {
          $td.on("click", function() {
            const field = type2 === "start" ? "_rViewStart" : "_rViewEnd";
            const cur = self[field] || new Date(year, month, 1);
            self[field] = new Date(cur.getFullYear(), cur.getMonth() + navigateMonth, 1);
            self.updateContent();
          });
        } else if (dayDate && !isDisabled) {
          $td.on("click", function() {
            self.handleRangeDateClick(dayDate, type2);
          });
        }
        $tr.append($td);
      }
      $tbody.append($tr);
    }
    $table.append($thead, $tbody);
    $body.append($table);
    $panel.append($header, $body);
    return $panel;
  }
  /**
  * 渲染年份选择器视图
  */
  renderYearView() {
    const year = this.currentDate.getFullYear();
    const $body = $7('<div class="ws-date-body"></div>');
    const $grid = $7('<div class="ws-date-year-month-view"></div>');
    const startYear = Math.floor(year / 10) * 10 - 1;
    for (let i = 0; i < 12; i++) {
      const currentYear = startYear + i;
      const $yearItem = $7('<div class="ws-date-year-month-item"></div>').text(currentYear).on("click", () => {
        this.currentDate.setFullYear(currentYear);
        if (this.options.type === "year") {
          this.selectedDate = new Date(currentYear, 0, 1);
          this.confirm();
        } else {
          this.currentView = "month";
          this.render();
        }
      });
      if (currentYear === year) {
        $yearItem.addClass("selected");
      }
      $grid.append($yearItem);
    }
    $body.append($grid);
    const $header = this.createHeader("year", year);
    const $footer = this.createFooter();
    this.$dropdown.append($header, $body, $footer);
  }
  /**
  * 渲染月份选择器视图
  */
  renderMonthView() {
    const year = this.currentDate.getFullYear();
    const $body = $7('<div class="ws-date-body"></div>');
    const $grid = $7('<div class="ws-date-year-month-view"></div>');
    for (let i = 0; i < 12; i++) {
      const $monthItem = $7('<div class="ws-date-year-month-item"></div>').text(this.lang.monthsShort[i]).on("click", () => {
        this.currentDate.setMonth(i);
        if (this.options.type === "month") {
          this.selectedDate = new Date(year, i, 1);
          this.confirm();
        } else {
          this.currentView = "date";
          this.render();
        }
      });
      if (i === this.currentDate.getMonth()) {
        $monthItem.addClass("selected");
      }
      $grid.append($monthItem);
    }
    $body.append($grid);
    const $header = this.createHeader("month", year);
    const $footer = this.createFooter();
    this.$dropdown.append($header, $body, $footer);
  }
  /**
  * 渲染时间选择器视图
  */
  renderTimeView() {
    const self = this;
    const $body = $7('<div class="ws-date-body"></div>');
    const $timeDisplay = $7('<div class="ws-date-time-display"></div>');
    const $timeGridContainer = $7('<div class="ws-date-time-grid-container"></div>');
    const currentDate = this.selectedDate || /* @__PURE__ */ new Date();
    const hours = this.selectedTime.hours !== void 0 ? this.selectedTime.hours : currentDate.getHours();
    const minutes = this.selectedTime.minutes !== void 0 ? this.selectedTime.minutes : currentDate.getMinutes();
    const updateDisplay = function() {
      const tempDate = /* @__PURE__ */ new Date();
      tempDate.setHours(self.selectedTime.hours, self.selectedTime.minutes, 0);
      const displayStr = utils.formatDate(tempDate, self.options.timeFormat);
      $timeDisplay.text(displayStr);
    };
    updateDisplay();
    const hourStep = this.options.timeSteps[0] || 1;
    const $hourGrid = $7('<div class="ws-date-time-grid mb-2"></div>');
    for (let i = 0; i < 24; i += hourStep) {
      let disabled = false;
      if (this.options.disabledTime) {
        disabled = this.options.disabledTime({ hour: i, minute: minutes, second: 0 });
      }
      const $cell = $7(`<div class="ws-date-time-cell ${disabled ? "disabled" : ""}" data-hour="${i}">${i.toString().padStart(2, "0")}</div>`).on("click", function() {
        if (disabled) return;
        self.selectedTime.hours = parseInt($7(this).data("hour"));
        $hourGrid.find(".ws-date-time-cell").removeClass("selected");
        $7(this).addClass("selected");
        updateDisplay();
        self.updateSelectedDateTime();
      });
      if (i === hours) {
        $cell.addClass("selected");
      }
      $hourGrid.append($cell);
    }
    const minuteStep = this.options.timeSteps[1] || 1;
    const $minuteGrid = $7('<div class="ws-date-time-grid"></div>');
    for (let i = 0; i < 60; i += minuteStep) {
      let disabled = false;
      if (this.options.disabledTime) {
        disabled = this.options.disabledTime({ hour: hours, minute: i, second: 0 });
      }
      const $cell = $7(`<div class="ws-date-time-cell ${disabled ? "disabled" : ""}" data-minute="${i}">${i.toString().padStart(2, "0")}</div>`).on("click", function() {
        if (disabled) return;
        self.selectedTime.minutes = parseInt($7(this).data("minute"));
        $minuteGrid.find(".ws-date-time-cell").removeClass("selected");
        $7(this).addClass("selected");
        updateDisplay();
        self.updateSelectedDateTime();
      });
      const closestMinute = Math.floor(minutes / minuteStep) * minuteStep;
      if (i === closestMinute) {
        $cell.addClass("selected");
      }
      $minuteGrid.append($cell);
    }
    const $hourCol = $7('<div class="ws-date-time-col"></div>');
    const $minuteCol = $7('<div class="ws-date-time-col"></div>');
    $hourCol.append('<div class="text-center mb-1"><small class="text-muted">' + this.lang.hour + "</small></div>", $hourGrid);
    $minuteCol.append('<div class="text-center mb-1"><small class="text-muted">' + this.lang.minute + "</small></div>", $minuteGrid);
    $timeGridContainer.addClass("ws-date-time-grid-container").append($hourCol, $minuteCol);
    $body.append($timeDisplay, $timeGridContainer);
    const $header = this.createHeader("time");
    if (this.options.type === "datetime") {
      const $back = $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="返回选择日期"><i class="bi bi-arrow-left"></i></button>');
      $back.on("click", (e) => {
        e.stopPropagation();
        this.currentView = "date";
        this.render();
      });
      $header.find(".ws-date-nav-start").append($back);
      if (this.selectedDate) {
        const dateStr = utils.formatDate(this.selectedDate, "yyyy-MM-dd");
        $header.find(".ws-date-title").text(`${dateStr} · ${this.lang.selectTime}`);
      }
    }
    const $footer = this.createFooter();
    this.$dropdown.append($header, $body, $footer);
  }
  /**
  * 创建日期选择器头部
  * @param {string} type - 头部类型（date、month、year、time）
  * @param {number} year - 显示的年份（仅在month和year类型时使用）
  * @returns {jQuery} - 包含头部HTML的jQuery对象
  */
  createHeader(type2, year) {
    const currentYear = this.currentDate.getFullYear();
    const currentMonth = this.currentDate.getMonth();
    const $header = $7('<div class="ws-date-header"></div>');
    let title = "";
    switch (type2) {
      case "year":
        title = `${Math.floor(currentYear / 10) * 10 - 1} - ${Math.floor(currentYear / 10) * 10 + 10}`;
        break;
      case "month":
        title = currentYear.toString();
        break;
      case "time":
        title = this.lang.selectTime;
        break;
      default:
        title = `${currentYear}年 ${currentMonth + 1}月`;
    }
    const $title = $7(`<h6 class="ws-date-title">${title}</h6>`);
    const $navStart = $7('<div class="ws-date-nav-start"></div>');
    const $navEnd = $7('<div class="ws-date-nav-end"></div>');
    if (type2 === "date") {
      $navStart.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一年"><i class="bi bi-chevron-double-left"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setFullYear(this.currentDate.getFullYear() - 1);
          this.updateContent();
        }),
        $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一月"><i class="bi bi-chevron-left"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setMonth(this.currentDate.getMonth() - 1);
          this.updateContent();
        })
      );
      $navEnd.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一月"><i class="bi bi-chevron-right"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setMonth(this.currentDate.getMonth() + 1);
          this.updateContent();
        }),
        $7('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一年"><i class="bi bi-chevron-double-right"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setFullYear(this.currentDate.getFullYear() + 1);
          this.updateContent();
        })
      );
    } else if (type2 === "month") {
      $navStart.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-left"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setFullYear(this.currentDate.getFullYear() - 1);
          this.updateContent();
        })
      );
      $navEnd.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-right"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setFullYear(this.currentDate.getFullYear() + 1);
          this.updateContent();
        })
      );
    } else if (type2 === "year") {
      $navStart.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-left"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setFullYear(this.currentDate.getFullYear() - 10);
          this.updateContent();
        })
      );
      $navEnd.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-right"></i></button>').on("click", (e) => {
          e.stopPropagation();
          this.currentDate.setFullYear(this.currentDate.getFullYear() + 10);
          this.updateContent();
        })
      );
    }
    $header.append($navStart, $title, $navEnd);
    return $header;
  }
  /**
  * 创建日期选择器底部
  * @returns {jQuery} - 包含底部HTML的jQuery对象
  */
  createFooter() {
    const $footer = $7('<div class="ws-date-footer"></div>');
    const $buttons = $7('<div class="ws-date-buttons"></div>');
    if (this.options.showToday && !this.options.range && this.currentView === "date") {
      $buttons.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button"></button>').text(this.lang.today).on("click", () => {
          const today = /* @__PURE__ */ new Date();
          this.handleDateClick(today);
        })
      );
    }
    if (this.options.showClear) {
      $buttons.append(
        $7('<button class="btn btn-sm btn-outline-secondary" type="button"></button>').text(this.lang.clear).on("click", () => {
          this.clear();
        })
      );
    }
    let needConfirm = this.options.showConfirm && this.options.popup || !this.options.popup && (this.options.type === "datetime" || this.options.type === "time");
    if (this.options.type === "datetime" && this.currentView === "date") {
      needConfirm = false;
    }
    if (needConfirm) {
      $buttons.append(
        $7('<button class="btn btn-sm btn-primary" type="button"></button>').text(this.lang.confirm).on("click", () => {
          this.confirm();
        })
      );
    }
    $footer.append($buttons);
    return $footer;
  }
  /**
  * 处理日期点击事件
  * @param {Date} date - 点击的日期对象
  */
  handleDateClick(date) {
    date.setHours(this.selectedTime.hours);
    date.setMinutes(this.selectedTime.minutes);
    date.setSeconds(this.selectedTime.seconds);
    this.selectedDate = date;
    this.currentDate = new Date(date);
    if (this.options.onSelect) {
      const value = utils.formatDate(date, this.options.format);
      this.options.onSelect(date, value);
    }
    if (this.options.type === "datetime") {
      this.currentView = "time";
      this.render();
      return;
    }
    this.updateContent();
    if (this.options.showConfirm && this.options.popup !== false) {
      return;
    }
    if (this.options.type === "time") {
      return;
    }
    this.confirm();
  }
  /**
  * 处理日期范围点击事件
  * @param {Date} date - 点击的日期对象
  * @param {string} type - 范围类型（start或end）
  */
  handleRangeDateClick(date, type2) {
    if (type2 === "start") {
      this.rangeStart = date;
      this.isSelectingRange = true;
      if (this.rangeEnd && this.rangeStart > this.rangeEnd) {
        this.rangeEnd = null;
      }
    } else {
      this.rangeEnd = date;
      this.isSelectingRange = false;
      if (this.rangeStart && this.rangeEnd && this.rangeStart > this.rangeEnd) {
        const temp = this.rangeStart;
        this.rangeStart = this.rangeEnd;
        this.rangeEnd = temp;
      }
    }
    this._rViewStart = this._viewMonthOf(this.rangeStart || this.rangeEnd || this._rViewStart || /* @__PURE__ */ new Date());
    this._rViewEnd = this.rangeEnd ? this._viewMonthOf(this.rangeEnd) : new Date(this._rViewStart.getFullYear(), this._rViewStart.getMonth() + 1, 1);
    this.updateContent();
    if (this.options.showConfirm && this.options.popup !== false) {
      return;
    }
    if (this.rangeStart && this.rangeEnd && !this.isSelectingRange) {
      this.confirm();
    }
  }
  /**
  * 更新日期选择器内容
  */
  updateContent() {
    const wasVisible = this.isOpen;
    this.render();
    if (wasVisible) {
      this.$dropdown.show();
    }
  }
  /**
  * 更新日期选择器输入框值
  */
  updateInput() {
    let value = "";
    if (this.options.range && this.rangeStart && this.rangeEnd) {
      const startStr = utils.formatDate(this.rangeStart, this.options.format);
      const endStr = utils.formatDate(this.rangeEnd, this.options.format);
      value = startStr + this.options.rangeSeparator + endStr;
    } else if (this.selectedDate) {
      value = utils.formatDate(this.selectedDate, this.options.format);
    }
    this.options.value = value;
    this.$element.val(value);
  }
  /**
  * 清空日期选择器值
  */
  clear() {
    this.selectedDate = null;
    this.rangeStart = null;
    this.rangeEnd = null;
    this._rViewStart = null;
    this._rViewEnd = null;
    this.selectedTime = { hours: 0, minutes: 0, seconds: 0 };
    this.$element.val("");
    this.hide();
    if (this.options.onChange) {
      this.options.onChange(null, "");
    }
    if (this.options.onSelect) {
      this.options.onSelect(null, "");
    }
  }
  /**
  * 确认日期选择器值
  */
  confirm() {
    this.updateInput();
    if (this.options.onChange) {
      let value = this.$element.val();
      if (this.options.range) {
        this.options.onChange([this.rangeStart, this.rangeEnd], value);
      } else {
        this.options.onChange(this.selectedDate, value);
      }
    }
    this.hide();
  }
  /**
   * 校验并应用手动输入（失焦 / 回车时触发）。
   * 合法输入会被规范化回写并触发 onChange；
   * 非法输入（2026-13-45 / 2026-02-30 等）会回滚到最近一次合法值并给出视觉提示。
   */
  validateManualInput() {
    const self = this;
    const raw = String(this.$element.val() || "").trim();
    if (!raw) return;
    let start = null;
    let end = null;
    if (this.options.range) {
      const parts = raw.split(this.options.rangeSeparator).map(function(s) {
        return s.trim();
      });
      if (parts.length === 2) {
        start = utils.parseStrict(parts[0]);
        end = utils.parseStrict(parts[1]);
      }
      if (!start || !end || start > end) {
        this._revertManualInput();
        return;
      }
      this.rangeStart = start;
      this.rangeEnd = end;
      this._rViewStart = new Date(start.getFullYear(), start.getMonth(), 1);
      this._rViewEnd = new Date(end.getFullYear(), end.getMonth(), 1);
      this.selectedDate = new Date(start);
      this.currentDate = new Date(start);
    } else if (this.options.type === "time") {
      const tm = raw.match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/);
      if (!tm || parseInt(tm[1], 10) > 23 || parseInt(tm[2], 10) > 59 || tm[3] !== void 0 && parseInt(tm[3], 10) > 59) {
        this._revertManualInput();
        return;
      }
      const base = this.selectedDate || /* @__PURE__ */ new Date();
      this.selectedDate = new Date(
        base.getFullYear(),
        base.getMonth(),
        base.getDate(),
        parseInt(tm[1], 10),
        parseInt(tm[2], 10),
        tm[3] !== void 0 ? parseInt(tm[3], 10) : 0
      );
      this.selectedTime = {
        hours: parseInt(tm[1], 10),
        minutes: parseInt(tm[2], 10),
        seconds: tm[3] !== void 0 ? parseInt(tm[3], 10) : 0
      };
    } else {
      start = utils.parseStrict(raw);
      if (!start) {
        this._revertManualInput();
        return;
      }
      this.selectedDate = start;
      this.currentDate = new Date(start);
      this.selectedTime = {
        hours: start.getHours(),
        minutes: start.getMinutes(),
        seconds: start.getSeconds()
      };
    }
    const fmt = this.options.range ? this.options.rangeFormat || this.options.format : this.options.format;
    const normalized = this.options.range ? utils.formatDate(this.rangeStart, fmt) + this.options.rangeSeparator + utils.formatDate(this.rangeEnd, fmt) : utils.formatDate(this.selectedDate, fmt);
    if (normalized === this.options.value) {
      return;
    }
    this.updateInput();
    if (this.options.onChange) {
      const value = this.$element.val();
      if (this.options.range) {
        this.options.onChange([this.rangeStart, this.rangeEnd], value);
      } else {
        this.options.onChange(this.selectedDate, value);
      }
    }
    if (this.isOpen) {
      this.updateContent();
    }
  }
  /**
   * 非法输入回滚：恢复最近一次合法值并闪烁提示
   */
  _revertManualInput() {
    const self = this;
    this.$element.val(this.options.value);
    this.$element.addClass("ws-date-input-invalid");
    setTimeout(function() {
      self.$element.removeClass("ws-date-input-invalid");
    }, 1500);
  }
  /**
  * 显示日期选择器
  */
  show() {
    if (currentOpenPicker && currentOpenPicker !== this) {
      currentOpenPicker.hide();
    }
    currentOpenPicker = this;
    if (this.options.type === "datetime" && this.currentView === "time") {
      this.currentView = "date";
    }
    if (this.options.range) {
      this._rViewStart = null;
      this._rViewEnd = null;
    }
    if (this.options.onOpen) {
      this.options.onOpen();
    }
    if (!this.$dropdown) {
      this.render();
    } else {
      this.updateContent();
    }
    this.$dropdown.show();
    this.isOpen = true;
  }
  /**
  * 隐藏日期选择器
  */
  hide() {
    if (this.options.onClose) {
      this.options.onClose();
    }
    if (this.$dropdown && this.options.popup) {
      this.$dropdown.hide();
    }
    this.isOpen = false;
    if (currentOpenPicker === this) {
      currentOpenPicker = null;
    }
  }
  /**
  * 切换日期选择器显示状态
  */
  toggle() {
    if (this.isOpen) {
      this.hide();
    } else {
      this.show();
    }
  }
  /**
  * 设置日期选择器值
  * @param {string} value - 日期值，格式根据options.format而定
  */
  setValue(value) {
    if (!value) return;
    if (this.options.range) {
      const parts = String(value).split(this.options.rangeSeparator);
      if (parts.length === 2) {
        this.rangeStart = utils.parseDate(parts[0].trim(), this.options.format);
        this.rangeEnd = utils.parseDate(parts[1].trim(), this.options.format);
      }
    } else {
      const date = utils.parseDate(value, this.options.format);
      if (date) {
        this.selectedDate = date;
        this.currentDate = new Date(date);
        this.selectedTime = {
          hours: date.getHours(),
          minutes: date.getMinutes(),
          seconds: date.getSeconds()
        };
      }
    }
    this.updateInput();
  }
  /**
  * 获取日期选择器当前值
  * @returns {string} - 日期值，格式根据options.format而定
  */
  getValue() {
    return this.$element.val();
  }
  /**
  * 销毁日期选择器实例
  */
  destroy() {
    this.hide();
    if (this.$dropdown) {
      this.$dropdown.remove();
    }
    if (this.options.popup !== false) {
      this.$element.unwrap();
    } else {
      this.$container.remove();
    }
    this.$element.off("click focus");
    $7(document).off("click.wsdate touchstart.wsdate keydown.wsdate");
  }
};
$7.fn.wsDatePicker = function(options2) {
  return this.each(function() {
    if (!$7(this).data("wsDatePicker")) {
      $7(this).data("wsDatePicker", new WsDatePicker(this, options2));
    }
  });
};
$7.wsDatePicker = {
  setDefaults: function(options2) {
    $7.extend(defaults, options2);
  },
  version: "2.0.0",
  /**
   * 自动初始化所有带有 data-role="wsdatepicker" 属性的元素
   * @param {string} selector 可选，自定义选择器，默认为 '[data-role="wsdatepicker"]'
   */
  autoInit: function(selector) {
    selector = selector || '[data-role="wsdatepicker"]';
    $7(selector).each(function() {
      var $this = $7(this);
      if (!$this.data("wsDatePicker")) {
        var options2 = $this.data();
        if (options2.hasOwnProperty("role")) {
          delete options2.role;
        }
        if ($this.val()) {
          options2.value = $this.val();
        }
        for (let key in options2) {
          if (options2.hasOwnProperty(key)) {
            if (["range", "showToday", "showClear", "showConfirm", "showQuickSelect", "popup"].includes(key)) {
              options2[key] = Boolean(options2[key]);
            } else if (["onChange", "onSelect", "onOpen", "onClose"].includes(key) && window[options2[key]]) {
              options2[key] = window[options2[key]];
            } else if (["disabledDate", "disabledTime"].includes(key) && window[options2[key]]) {
              options2[key] = window[options2[key]];
            } else if (["weekStart", "min", "max"].includes(key)) {
              options2[key] = Number(options2[key]);
            } else if (["mark", "timeSteps"].includes(key)) {
              try {
                options2[key] = JSON.parse(options2[key]);
              } catch (e) {
                console.warn(`解析属性 ${key} 为 JSON 失败: ${e.message}`);
              }
            }
          }
        }
        ;
        $this.wsDatePicker(options2);
      }
    });
  }
};
$7(function() {
  $7.wsDatePicker.autoInit();
});
window.WsDatePicker = WsDatePicker;
var ws_date_picker_default = WsDatePicker;

// public/statics/libs/ws-hint-input/ws-hint-input.js
function injectStyles() {
}
var defaultOptions = {
  minChars: 1,
  // 触发提示的最小字符数
  delay: 300,
  // 输入延迟（防抖）
  maxItems: 10,
  // 最大显示建议数
  highlightMatches: true,
  // 是否高亮匹配部分
  data: [],
  // 本地数据数组
  url: null,
  // 远程数据URL
  paramName: "q",
  // 远程请求参数名
  onSelect: null,
  // 选择建议时的回调
  onFetchStart: null,
  // 开始获取数据时的回调
  onFetchEnd: null,
  // 结束获取数据时的回调
  onError: null
  // 错误处理回调
};
var HintInput = class {
  /**
   * 构造函数
   * @param {HTMLElement|string} inputElement - 输入框元素或选择器字符串
   * @param {Object} [options={}] - 配置选项
   * @property {number} minChars - 触发提示的最小字符数
   * @property {number} delay - 输入延迟（防抖）
   * @property {number} maxItems - 最大显示建议数
   * @property {boolean} highlightMatches - 是否高亮匹配部分
   * @property {Array} data - 本地数据数组
   * @property {string} url - 远程数据URL
   * @property {string} paramName - 远程请求参数名
   * @property {function} onSelect - 选择建议时的回调
   * @property {function} onFetchStart - 开始获取数据时的回调
   * @property {function} onFetchEnd - 结束获取数据时的回调
   * @property {function} onError - 错误处理回调
   */
  constructor(inputElement, options2 = {}) {
    if (typeof inputElement === "string") {
      inputElement = document.querySelector(inputElement);
    }
    if (!(inputElement instanceof HTMLElement)) {
      throw new Error("HintInput: 未找到目标元素，请确认选择器或 DOM 元素有效");
    }
    if (inputElement.tagName !== "INPUT" || inputElement.type !== "text" && inputElement.type !== "search") {
      throw new Error('HintInput: 必须传入文本输入框元素（type="text" 或 type="search"）');
    }
    this.input = inputElement;
    this.options = { ...defaultOptions, ...options2 };
    this.suggestions = [];
    this.activeIndex = -1;
    this.isOpen = false;
    this.timeoutId = null;
    injectStyles();
    this.instanceId = "ws-hintinput-" + Math.random().toString(36).substring(2, 9);
    this.init();
  }
  // 初始化
  init() {
    this.container = document.createElement("div");
    this.container.className = "ws-hintinput-suggestions";
    this.container.id = this.instanceId;
    document.body.appendChild(this.container);
    this.bindEvents();
  }
  // 绑定事件
  bindEvents() {
    this.input.addEventListener("input", (e) => {
      this.handleInput(e.target.value);
    });
    this.input.addEventListener("keydown", (e) => {
      this.handleKeyDown(e);
    });
    this.input.addEventListener("focus", (e) => {
      const query = this.input.value.trim();
      if (query.length >= this.options.minChars) {
        this.fetchSuggestions(query);
      } else if (query.length === 0 && !this.options.url) {
        this.fetchSuggestions("");
      }
    });
    this._outsideClickHandler = (e) => {
      if (!this.input.contains(e.target) && !this.container.contains(e.target)) {
        this.close();
      }
    };
    document.addEventListener("click", this._outsideClickHandler);
    this.resizeHandler = () => {
      if (this.isOpen) {
        this.updatePosition();
      }
    };
    this.scrollHandler = () => {
      if (this.isOpen) {
        this.updatePosition();
      }
    };
    window.addEventListener("resize", this.resizeHandler);
    window.addEventListener("scroll", this.scrollHandler, true);
  }
  // 更新下拉框位置
  updatePosition() {
    const rect = this.input.getBoundingClientRect();
    const scrollY = window.scrollY || document.documentElement.scrollTop;
    const scrollX = window.scrollX || document.documentElement.scrollLeft;
    this.container.style.width = `${rect.width}px`;
    this.container.style.left = `${rect.left + scrollX}px`;
    this.container.style.top = `${rect.bottom + scrollY}px`;
    const viewportHeight = window.innerHeight;
    const dropdownHeight = this.container.offsetHeight;
    const spaceBelow = viewportHeight - rect.bottom;
    if (spaceBelow < dropdownHeight && rect.top > dropdownHeight) {
      this.container.style.top = `${rect.top + scrollY - dropdownHeight}px`;
    }
  }
  // 处理输入
  handleInput(value = "") {
    if (this.timeoutId) {
      clearTimeout(this.timeoutId);
    }
    if (value && value.trim().length > 0 && value.trim().length < this.options.minChars) {
      this.close();
      return;
    }
    this.timeoutId = setTimeout(() => {
      this.fetchSuggestions(value);
    }, this.options.delay);
  }
  // 获取建议
  fetchSuggestions(query) {
    if (this.options.url) {
      this.fetchRemoteSuggestions(query);
    } else {
      this.fetchLocalSuggestions(query.trim());
    }
  }
  // 获取本地建议
  fetchLocalSuggestions(query = "") {
    const filtered = this.options.data.filter((item) => item.toLowerCase().includes(query.toLowerCase())).slice(0, this.options.maxItems);
    this.displaySuggestions(filtered, query);
    if (this.options.onFetchEnd) {
      this.options.onFetchEnd(filtered);
    }
  }
  // 获取远程建议
  fetchRemoteSuggestions(query = "") {
    if (this.options.onFetchStart) {
      this.options.onFetchStart();
    }
    this.container.innerHTML = "";
    this.container.setAttribute("data-ws-state", "loading");
    this.container.style.display = "block";
    this.updatePosition();
    const url2 = this.buildUrl(query);
    fetch(url2).then((response) => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    }).then((data3) => {
      const suggestions = Array.isArray(data3) ? data3.slice(0, this.options.maxItems) : this.processRemoteData(data3);
      this.displaySuggestions(suggestions, query);
      if (this.options.onFetchEnd) {
        this.options.onFetchEnd(suggestions);
      }
    }).catch((error) => {
      console.error("获取远程数据失败:", error);
      this.container.innerHTML = "";
      this.container.removeAttribute("data-ws-state");
      this.container.setAttribute("data-ws-state", "error");
      this.container.setAttribute("data-ws-error-text", error.message || "加载失败");
      this.container.style.display = "block";
      this.isOpen = true;
      this.updatePosition();
      if (this.options.onError) {
        this.options.onError(error);
      }
    });
  }
  // 构建远程请求URL
  buildUrl(query) {
    const url2 = new URL(this.options.url, window.location.origin);
    url2.searchParams.set(this.options.paramName, query);
    return url2.toString();
  }
  // 处理远程数据（如果不是数组格式）
  processRemoteData(data3) {
    return data3;
  }
  // 显示建议
  displaySuggestions(suggestions, query) {
    query = query.trim();
    this.suggestions = suggestions;
    this.activeIndex = -1;
    this.container.removeAttribute("data-ws-state");
    this.container.removeAttribute("data-ws-error-text");
    if (suggestions.length === 0) {
      this.container.innerHTML = "";
      this.container.setAttribute("data-ws-state", "empty");
      this.container.setAttribute("data-ws-empty-text", "无匹配结果");
      this.container.style.display = "block";
      this.isOpen = true;
      this.updatePosition();
      return;
    }
    this.container.innerHTML = "";
    suggestions.forEach((suggestion, index) => {
      const item = document.createElement("div");
      item.className = "ws-hintinput-suggestion";
      if (this.options.highlightMatches) {
        const regex = new RegExp(`(${this.escapeRegex(query)})`, "gi");
        const highlighted = query?.trim()?.length > 0 ? suggestion.replace(regex, '<span class="ws-hintinput-highlight">$1</span>') : suggestion;
        item.innerHTML = highlighted;
      } else {
        item.textContent = suggestion;
      }
      item.addEventListener("click", (e) => {
        this.selectSuggestion(index);
      });
      item.addEventListener("mouseover", (e) => {
        this.setActiveItem(index);
      });
      this.container.appendChild(item);
    });
    this.open();
  }
  // 打开下拉
  open() {
    this.updatePosition();
    this.container.style.display = "block";
    this.isOpen = true;
    setTimeout(() => {
      if (this.isOpen) {
        this.updatePosition();
      }
    }, 0);
  }
  // 关闭下拉
  close() {
    this.container.style.display = "none";
    this.isOpen = false;
    this.activeIndex = -1;
  }
  // 处理键盘事件
  handleKeyDown(e) {
    if (!this.isOpen) return;
    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        this.navigate(1);
        break;
      case "ArrowUp":
        e.preventDefault();
        this.navigate(-1);
        break;
      case "Enter":
        e.preventDefault();
        if (this.activeIndex >= 0) {
          this.selectSuggestion(this.activeIndex);
        }
        break;
      case "Tab":
        if (this.activeIndex >= 0) {
          e.preventDefault();
          this.selectSuggestion(this.activeIndex);
        }
        break;
      case "Escape":
        this.close();
        break;
    }
  }
  // 导航
  navigate(direction) {
    const items = this.container.children;
    if (items.length === 0) return;
    if (this.activeIndex >= 0) {
      items[this.activeIndex].classList.remove("ws-active");
    }
    this.activeIndex += direction;
    if (this.activeIndex < 0) {
      this.activeIndex = items.length - 1;
    } else if (this.activeIndex >= items.length) {
      this.activeIndex = 0;
    }
    items[this.activeIndex].classList.add("ws-active");
    items[this.activeIndex].scrollIntoView({
      block: "nearest"
    });
  }
  // 设置激活项
  setActiveItem(index) {
    const items = this.container.children;
    if (this.activeIndex >= 0) {
      items[this.activeIndex].classList.remove("ws-active");
    }
    this.activeIndex = index;
    items[this.activeIndex].classList.add("ws-active");
  }
  // 选择建议
  selectSuggestion(index) {
    const value = this.suggestions[index];
    this.input.value = value;
    this.close();
    if (this.options.onSelect) {
      this.options.onSelect(value, index);
    }
  }
  // 更新数据
  updateData(newData) {
    this.options.data = newData;
  }
  // 更新URL
  updateUrl(newUrl) {
    this.options.url = newUrl;
  }
  // 转义正则表达式特殊字符
  escapeRegex(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }
  // 销毁实例
  destroy() {
    this.close();
    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container);
    }
    if (this._outsideClickHandler) {
      document.removeEventListener("click", this._outsideClickHandler);
      this._outsideClickHandler = null;
    }
    window.removeEventListener("resize", this.resizeHandler);
    window.removeEventListener("scroll", this.scrollHandler, true);
  }
};
window.WsHintInput = HintInput;
window.HintInput = HintInput;
var ws_hint_input_default = HintInput;

// public/statics/libs/ws-linkage-select/ws-linkage-select.js
function injectStyles2() {
}
function ensureWrapper(select) {
  if (select.parentNode && select.parentNode.classList.contains("ws-linkage-select-wrapper")) {
    return select.parentNode;
  }
  var wrapper = document.createElement("div");
  wrapper.className = "ws-linkage-select-wrapper";
  select.parentNode.insertBefore(wrapper, select);
  wrapper.appendChild(select);
  var errEl = document.createElement("div");
  errEl.className = "ws-linkage-error-text";
  wrapper.appendChild(errEl);
  var hint = document.createElement("div");
  hint.className = "ws-linkage-cascade-hint";
  hint.textContent = "上级选项已变化，请重新选择";
  wrapper.appendChild(hint);
  return wrapper;
}
function ensureGroup(selects) {
  var first = selects[0];
  var existingGroup = first.closest(".ws-linkage-select-group");
  if (existingGroup) return existingGroup;
  var group = document.createElement("div");
  group.className = "ws-linkage-select-group";
  var parent2 = first.parentNode;
  if (parent2.classList.contains("ws-linkage-select-wrapper")) {
    parent2 = parent2.parentNode;
  }
  var wrappers = selects.map(function(s) {
    return ensureWrapper(s);
  });
  wrappers.forEach(function(w) {
    group.appendChild(w);
  });
  parent2.appendChild(group);
  return group;
}
var _LinkageSelect = class _LinkageSelect {
  /**
   * 构造函数
   * @param {Object} config - 配置对象
   * @param {Array<string>} config.selects - select元素的ID数组，至少两个
   * @param {Function} config.dataSource - 数据获取函数，参数为当前选择的父级值，返回Promise
   * @param {Object} [config.options] - 可选配置项
   */
  constructor(config2) {
    injectStyles2();
    if (!config2.selects || !Array.isArray(config2.selects) || config2.selects.length < 2) {
      throw new Error("必须提供至少两个select元素的ID数组");
    }
    if (!config2.dataSource) {
      throw new Error("必须提供dataSource配置");
    }
    this.selects = config2.selects.map((id) => {
      const element = document.getElementById(id);
      if (!element) {
        throw new Error(`找不到ID为"${id}"的select元素`);
      }
      element.classList.add("ws-linkage-select");
      return element;
    });
    this.dataSource = config2.dataSource;
    this.levels = this.selects.length;
    this.initialized = false;
    this._lastValues = new Array(this.levels).fill(null);
    this.options = {
      ..._LinkageSelect.DEFAULT_OPTIONS,
      ...config2.options
    };
    ensureGroup(this.selects);
    this.init();
  }
  /**
   * 设置 select 的三态
   * @param {HTMLSelectElement} select
   * @param {'loading'|'empty'|'error'} state - 设为 null 则清除状态
   * @param {string} [text] - 错误或空状态的文字
   */
  _setState(select, state, text) {
    var wrapper = select.closest(".ws-linkage-select-wrapper");
    var errEl = wrapper ? wrapper.querySelector(".ws-linkage-error-text") : null;
    select.removeAttribute("data-ws-state");
    if (errEl) errEl.style.display = "none";
    if (!state) return;
    select.setAttribute("data-ws-state", state);
    if (state === "loading") {
      select.disabled = true;
      select.innerHTML = '<option value="">' + (text || this.options.loadingText) + "</option>";
    } else if (state === "empty") {
      select.setAttribute("data-ws-empty-text", text || this.options.emptyText);
    } else if (state === "error") {
      if (errEl) {
        errEl.textContent = text || this.options.errorText;
        errEl.style.display = "block";
      }
      select.disabled = false;
    }
  }
  _clearState(select) {
    select.removeAttribute("data-ws-state");
    select.removeAttribute("data-ws-empty-text");
    select.disabled = false;
    select.classList.remove("linked-select-loading");
    var wrapper = select.closest(".ws-linkage-select-wrapper");
    if (wrapper) wrapper.classList.remove("ws-cascade-changed");
  }
  async init() {
    this.selects.forEach((select, index) => {
      select._linkageSelectHandler = select._linkageSelectHandler || [];
      select._linkageSelectHandler[index] = () => {
        this.onSelectChange(index);
      };
      select.addEventListener("change", select._linkageSelectHandler[index]);
    });
    await this.loadData(0);
    this.initialized = true;
  }
  async loadData(level, parentValue) {
    if (parentValue === void 0) parentValue = null;
    var select = this.selects[level];
    this._setState(select, "loading");
    try {
      var data3;
      if (typeof this.dataSource === "string") {
        var url2 = parentValue ? this.dataSource + "?parent=" + parentValue : this.dataSource;
        var response = await fetch(url2);
        if (!response.ok) {
          throw new Error("HTTP error! status: " + response.status);
        }
        data3 = await response.json();
      } else if (typeof this.dataSource === "function") {
        data3 = await this.dataSource(level, parentValue);
      } else {
        data3 = parentValue ? this.findChildren(this.dataSource, parentValue) : this.dataSource.filter((item) => !item[this.options.parentField]);
      }
      this.updateSelectOptions(select, data3, level);
      if (!data3 || data3.length === 0) {
        this._setState(select, "empty", this.options.emptyText);
      } else {
        this._clearState(select);
      }
    } catch (error) {
      console.error("加载数据失败:", error);
      this._setState(select, "error", error.message || this.options.errorText);
      select.innerHTML = '<option value="">' + this.options.errorText + "</option>";
    }
  }
  findChildren(data3, parentValue) {
    var self = this;
    return data3.filter(function(item) {
      return item[self.options.parentField] == parentValue;
    });
  }
  updateSelectOptions(select, data3, level) {
    var currentValue = select.value;
    select.innerHTML = "";
    var defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = level === 0 ? this.options.placeholder : "请先选择" + this.getLevelName(level - 1);
    select.appendChild(defaultOption);
    data3.forEach((item) => {
      var option = document.createElement("option");
      option.value = item[this.options.valueField] || item.value;
      option.textContent = item[this.options.labelField] || item.label || item.name;
      option.dataset.data = JSON.stringify(item);
      select.appendChild(option);
    });
    if (currentValue && data3.some((item) => (item[this.options.valueField] || item.value) == currentValue)) {
      select.value = currentValue;
    }
    if (this.initialized && select.value) {
      this.onSelectChange(level);
    }
  }
  getLevelName(level) {
    var label = document.querySelector('label[for="' + this.selects[level].id + '"]');
    return label ? label.textContent : "级别" + (level + 1);
  }
  async onSelectChange(level) {
    var select = this.selects[level];
    var value = select.value;
    this._clearState(select);
    var isValueChanged = this._lastValues[level] !== null && this._lastValues[level] !== value;
    this._lastValues[level] = value;
    for (var i = level + 1; i < this.levels; i++) {
      var childSelect = this.selects[i];
      this._clearState(childSelect);
      var hadValue = this._lastValues[i] !== null && this._lastValues[i] !== "";
      this._lastValues[i] = null;
      var wrapper = childSelect.closest(".ws-linkage-select-wrapper");
      if (isValueChanged && hadValue && wrapper) {
        wrapper.classList.add("ws-cascade-changed");
      } else if (wrapper) {
        wrapper.classList.remove("ws-cascade-changed");
      }
      this._setState(childSelect, "empty", "请先选择" + this.getLevelName(i - 1));
      childSelect.innerHTML = '<option value="">请先选择' + this.getLevelName(i - 1) + "</option>";
      childSelect.disabled = true;
      childSelect.value = "";
    }
    if (value && level < this.levels - 1) {
      this._clearState(this.selects[level + 1]);
      this.selects[level + 1].disabled = false;
      await this.loadData(level + 1, value);
      var nextWrapper = this.selects[level + 1].closest(".ws-linkage-select-wrapper");
      if (nextWrapper) nextWrapper.classList.remove("ws-cascade-changed");
    }
    this.triggerChangeEvent();
  }
  triggerChangeEvent() {
    var values = {};
    this.selects.forEach(function(select, index) {
      values["level" + index] = select.value;
      if (select.value) {
        var selectedOption = select.options[select.selectedIndex];
        try {
          values["level" + index + "Data"] = JSON.parse(selectedOption.dataset.data || "{}");
        } catch (e) {
          values["level" + index + "Data"] = {};
        }
      }
    });
    var event = new CustomEvent("linkageSelectChange", {
      detail: values
    });
    this.selects[0].dispatchEvent(event);
  }
  getValues() {
    var values = {};
    this.selects.forEach(function(select, index) {
      values["level" + index] = select.value;
      if (select.value) {
        var selectedOption = select.options[select.selectedIndex];
        try {
          values["level" + index + "Data"] = JSON.parse(selectedOption.dataset.data || "{}");
        } catch (e) {
          values["level" + index + "Data"] = {};
        }
      }
    });
    return values;
  }
  async reset() {
    var self = this;
    this.selects.forEach(function(select, index) {
      self._clearState(select);
      select.innerHTML = '<option value="">' + (index === 0 ? self.options.placeholder : "请先选择" + self.getLevelName(index - 1)) + "</option>";
      select.disabled = index > 0;
      select.value = "";
      self._lastValues[index] = null;
    });
    await this.loadData(0);
    this.triggerChangeEvent();
  }
  destroy() {
    this.selects.forEach(function(select, index) {
      if (select._linkageSelectHandler && select._linkageSelectHandler[index]) {
        select.removeEventListener("change", select._linkageSelectHandler[index]);
        delete select._linkageSelectHandler[index];
      }
    });
  }
};
/**
 * 默认配置项
 */
__publicField(_LinkageSelect, "DEFAULT_OPTIONS", {
  placeholder: "请选择",
  loadingText: "加载中...",
  errorText: "加载失败",
  emptyText: "暂无数据",
  parentField: "parent_id",
  valueField: "id",
  labelField: "name"
});
var LinkageSelect = _LinkageSelect;
window.WsLinkageSelect = LinkageSelect;
window.LinkageSelect = LinkageSelect;
var ws_linkage_select_default = LinkageSelect;

// public/statics/libs/ws-popmenu/ws-popmenu.js
var $8 = window.jQuery;
if (typeof $8 === "undefined") {
  throw new Error("WsPopMenu requires jQuery");
}
var defaults2 = {
  trigger: "click",
  // 'click', 'hover' 或 'contextmenu'
  placement: "bottom-start",
  // 'top', 'bottom', 'left', 'right', 以及它们的-start和-end变体
  items: [],
  offsetX: 0,
  // 水平偏移
  offsetY: 0,
  // 垂直偏移
  autoClose: true,
  // 点击菜单外部是否自动关闭
  onShow: null,
  onHide: null,
  onItemClick: null
};
var utils2 = {
  // 获取元素在文档中的位置
  getOffset: function(element) {
    var rect = element.getBoundingClientRect();
    return {
      top: rect.top + window.pageYOffset,
      left: rect.left + window.pageXOffset,
      width: rect.width,
      height: rect.height
    };
  },
  // 检查元素是否可见
  isVisible: function(element) {
    return element.offsetWidth > 0 && element.offsetHeight > 0;
  },
  // 防抖函数
  debounce: function(func, wait) {
    var timeout;
    return function() {
      var context = this, args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(function() {
        func.apply(context, args);
      }, wait);
    };
  },
  // 生成唯一ID
  generateId: function() {
    return "ws-popmenu-" + Math.random().toString(36).substr(2, 9);
  }
};
var WsPopMenu = function(options2) {
  this.options = $8.extend({}, defaults2, options2);
  this.$element = null;
  this.$trigger = null;
  this.isVisible = false;
  this.hideTimeout = null;
  this.submenuHideTimeouts = {};
  this.id = utils2.generateId();
  this.isAttached = false;
  this._pendingFocus = false;
  this.init();
};
WsPopMenu.prototype = {
  constructor: WsPopMenu,
  init: function() {
    this.createElement();
    this.buildMenu(this.$element, this.options.items);
    this.bindEvents();
  },
  createElement: function() {
    this.$element = $8('<div class="ws-popmenu" tabindex="-1"></div>');
    this.$element.attr("id", this.id);
    $8("body").append(this.$element);
  },
  /* 
   * 构建菜单 
   * @param {jQuery} $container - 菜单容器元素
   * @param {Array} items - 菜单项数组
   *  - text {string} - 菜单文本
   *  - icon {string} - Bootstrap Icons 类名
   *  - id {string} - 可选，用于标识
   *  - type {string} - 可选，'divider' 或 'header'
   *  - shortcut {string} - 可选，快捷键
   *  - group {string} - 可选，分组名称
   *  - disabled {boolean} - 可选，是否禁用
   *  - children {Array} - 可选，子菜单数组
   */
  buildMenu: function($container, items) {
    $container.empty();
    $container.attr("role", "menu");
    var currentGroup = null;
    var self = this;
    items.forEach(function(item) {
      if (item.type === "divider") {
        $container.append('<div class="ws-popmenu-divider"></div>');
      } else if (item.type === "header") {
        $container.append('<div class="ws-popmenu-header">' + item.text + "</div>");
      } else {
        if (item.group && item.group !== currentGroup) {
          if (currentGroup !== null) {
            $container.append('<div class="ws-popmenu-divider"></div>');
          }
          currentGroup = item.group;
        }
        var $item = $8(
          '<button type="button" class="ws-popmenu-item" data-id="' + (item.id || "") + '" tabindex="-1" role="menuitem"><span class="d-flex align-items-center me-3">' + (item.icon ? '<i class="' + item.icon + '"></i>' : "") + item.text + "</span>" + (item.children ? '<i class="bi bi-chevron-right"></i>' : "") + (item.shortcut ? '<span class="ws-popmenu-shortcut">' + item.shortcut + "</span>" : "") + "</button>"
        );
        if (item.disabled) {
          $item.addClass("disabled");
          $item.attr("aria-disabled", "true");
        }
        if (item.children && item.children.length > 0) {
          $item.attr("aria-haspopup", "menu");
          $item.attr("aria-expanded", "false");
        }
        if (item.children && item.children.length > 0) {
          var $submenu = $8('<div class="ws-popmenu ws-popmenu-submenu" tabindex="-1"></div>');
          self.buildMenu($submenu, item.children);
          $item.append($submenu);
          self.bindSubmenuEvents($item, $submenu);
        }
        $container.append($item);
      }
    });
  },
  bindSubmenuEvents: function($item, $submenu) {
    var self = this;
    var closeSelf = function() {
      clearTimeout($item.data("wspmSubmenuTimer"));
      $submenu.removeClass("show");
      $item.attr("aria-expanded", "false");
    };
    var scheduleHide = function() {
      clearTimeout($item.data("wspmSubmenuTimer"));
      var timer = setTimeout(closeSelf, 150);
      $item.data("wspmSubmenuTimer", timer);
    };
    $item.on("mouseenter", function() {
      clearTimeout($item.data("wspmSubmenuTimer"));
      $submenu.addClass("show");
      $item.attr("aria-expanded", "true");
    });
    $item.on("mouseleave", scheduleHide);
    $submenu.on("mouseenter", function() {
      clearTimeout($item.data("wspmSubmenuTimer"));
    });
    $submenu.on("mouseleave", scheduleHide);
  },
  bindEvents: function() {
    var self = this;
    this.$element.on("click", ".ws-popmenu-item", function(e) {
      var $item = $8(this);
      if ($item.hasClass("disabled")) return;
      if ($item.find(".ws-popmenu-submenu").length > 0) {
        e.stopPropagation();
        return;
      }
      var id = $item.data("id");
      if (self.options.onItemClick) {
        self.options.onItemClick(id, $item);
      }
      self.hide();
    });
    this.$element.on("mouseenter", ".ws-popmenu-item", function() {
      var $item = $8(this);
      var $menu = $item.closest(".ws-popmenu");
      self.clearStaleMenuFocus($item);
      $menu.children(".ws-popmenu-item").each(function() {
        if (this === $item[0]) return;
        var $sibling = $8(this);
        var $sub = $sibling.children(".ws-popmenu-submenu").first();
        clearTimeout($sibling.data("wspmSubmenuTimer"));
        if ($sub.length) {
          $sub.removeClass("show");
          $sibling.attr("aria-expanded", "false");
        }
      });
    });
    this.$element.on("keydown", ".ws-popmenu-item", function(e) {
      var $item = $8(this);
      var key = e.key;
      if ($item[0] !== document.activeElement) return;
      if (e.originalEvent && e.originalEvent._wspmKeyHandled) return;
      if (e.originalEvent) e.originalEvent._wspmKeyHandled = true;
      if (key === "Enter" || key === " ") {
        e.preventDefault();
        $item.trigger("click");
        return;
      }
      var $menu = $item.closest(".ws-popmenu");
      var $parentItem = $menu.closest(".ws-popmenu-item");
      switch (key) {
        case "Escape":
          e.preventDefault();
          e.stopPropagation();
          if ($parentItem.length) {
            $menu.removeClass("show");
            $parentItem.removeAttr("aria-expanded");
            $parentItem.trigger("focus");
          } else {
            self.hide();
          }
          break;
        case "ArrowDown":
        case "ArrowUp":
          e.preventDefault();
          self.moveFocus($menu, $item, key === "ArrowDown" ? 1 : -1);
          break;
        case "ArrowRight":
          var $submenu = $item.children(".ws-popmenu-submenu").first();
          if ($submenu.length) {
            e.preventDefault();
            var $cont = $item.parent();
            $cont.children(".ws-popmenu-item").each(function() {
              if (this === $item[0]) return;
              var $sibSub = $8(this).children(".ws-popmenu-submenu").first();
              if ($sibSub.length) {
                $sibSub.removeClass("show");
                $8(this).attr("aria-expanded", "false");
              }
            });
            $submenu.addClass("show");
            $item.attr("aria-expanded", "true");
            self.focusFirstItem($submenu);
          }
          break;
        case "ArrowLeft":
          if ($parentItem.length) {
            e.preventDefault();
            $menu.removeClass("show");
            $parentItem.attr("aria-expanded", "false");
            $parentItem.trigger("focus");
          }
          break;
      }
    });
    if (this.options.autoClose) {
      $8(document).on("click." + this.id, function(e) {
        if (self.isVisible && !self.$element.is(e.target) && self.$element.has(e.target).length === 0 && (!self.$trigger || !self.$trigger.is(e.target) && self.$trigger.has(e.target).length === 0)) {
          self.hide();
        }
      });
      if (this.options.trigger === "contextmenu") {
        $8(document).on("contextmenu." + this.id, function(e) {
          if (self.isVisible && !self.$element.is(e.target) && self.$element.has(e.target).length === 0 && (!self.$trigger || !self.$trigger.is(e.target) && self.$trigger.has(e.target).length === 0)) {
            self.hide();
          }
        });
      }
    }
  },
  attachTo: function($trigger) {
    if (this.isAttached) {
      this.detach();
    }
    this.$trigger = $trigger;
    var self = this;
    $trigger.on("keydown." + this.id, function(e) {
      if (e.key === "Escape") {
        if (self.isVisible) {
          e.preventDefault();
          self.hide();
        }
      } else if (e.key === "ArrowDown" || e.key === "ArrowUp") {
        e.preventDefault();
        if (self.isVisible) {
          self.focusFirstItem(self.$element);
        } else {
          self._pendingFocus = true;
          self.show();
        }
      }
    });
    if (this.options.trigger === "click") {
      $trigger.on("click." + this.id, function(e) {
        e.stopPropagation();
        self.toggle();
      });
    } else if (this.options.trigger === "hover") {
      $trigger.on("mouseenter." + this.id, function() {
        self.show();
      });
      $trigger.on("mouseleave." + this.id, function() {
        self.hideTimeout = setTimeout(function() {
          if (!self.$element.is(":hover")) {
            self.hide();
          }
        }, 100);
      });
      this.$element.on("mouseenter." + this.id, function() {
        clearTimeout(self.hideTimeout);
      });
      this.$element.on("mouseleave." + this.id, function() {
        self.hide();
      });
    } else if (this.options.trigger === "contextmenu") {
      $trigger.on("contextmenu." + this.id, function(e) {
        e.preventDefault();
        e.stopPropagation();
        self.updatePosition(e.clientX, e.clientY);
        self.show();
      });
    }
    this.isAttached = true;
    return this;
  },
  detach: function() {
    if (this.$trigger) {
      this.$trigger.off("." + this.id);
    }
    this.$element.off("." + this.id);
    $8(document).off("." + this.id);
    this.isAttached = false;
    return this;
  },
  show: function() {
    if (this.isVisible) return this;
    if (this.options.trigger !== "contextmenu") {
      this.updatePosition();
    }
    this.$element.addClass("show");
    this.isVisible = true;
    var pendingFocus = this._pendingFocus;
    this._pendingFocus = false;
    if (pendingFocus) {
      var self = this;
      setTimeout(function() {
        if (self.isVisible) {
          self.focusFirstItem(self.$element);
        }
      }, 120);
    }
    if (this.options.onShow) {
      this.options.onShow();
    }
    return this;
  },
  hide: function() {
    if (!this.isVisible) return this;
    this.$element.find(".ws-popmenu-submenu").removeClass("show").closest(".ws-popmenu-item").removeAttr("aria-expanded");
    this.$element.removeClass("show");
    this.isVisible = false;
    if (this.$trigger && document.activeElement && this.$element.has(document.activeElement).length) {
      this.$trigger.trigger("focus");
    }
    if (this.options.onHide) {
      this.options.onHide();
    }
    return this;
  },
  /**
   * 鼠标操作进入菜单项时，清除键盘遗留的焦点高亮。
   * 键盘导航会让某一项保持 :focus（其子菜单因 `:focus > 子菜单` 规则持续展开），
   * 此时鼠标移到其它项若不做处理，会出现"某一项始终高亮 / 旧子菜单不隐藏"。
   * @param {jQuery} [$keep] 保持焦点的菜单项（一般传当前 hover 项，不参与清除）
   */
  clearStaleMenuFocus: function($keep) {
    var active = document.activeElement;
    if (!active) return;
    var $active = $8(active);
    if ($active.closest(".ws-popmenu").length && (!$keep || active !== $keep[0])) {
      if (typeof active.blur === "function") {
        active.blur();
      }
    }
  },
  /**
   * 聚焦菜单内第一个可用的菜单项
   * @param {jQuery} $menu 菜单容器（根菜单或子菜单）
   */
  focusFirstItem: function($menu) {
    var $item = $menu.children(".ws-popmenu-item").not(".disabled").first();
    if ($item.length) {
      $item.trigger("focus");
    }
    return this;
  },
  /**
   * 在当前菜单内上下移动焦点（循环）
   * @param {jQuery} $menu 菜单容器
   * @param {jQuery} $current 当前聚焦的菜单项
   * @param {number} direction 1=向下 / -1=向上
   */
  moveFocus: function($menu, $current, direction) {
    var $items = $menu.children(".ws-popmenu-item").not(".disabled");
    if (!$items.length) return this;
    var index = $items.index($current);
    if (index < 0) index = direction > 0 ? -1 : 0;
    var next = (index + direction + $items.length) % $items.length;
    $items.eq(next).trigger("focus");
    return this;
  },
  toggle: function() {
    if (this.isVisible) {
      this.hide();
    } else {
      this.show();
    }
    return this;
  },
  updatePosition: function(x, y) {
    if (this.options.trigger === "contextmenu" && x !== void 0 && y !== void 0) {
      var scrollX = window.pageXOffset;
      var scrollY = window.pageYOffset;
      this.$element.css({
        top: y + scrollY + this.options.offsetY + "px",
        left: x + scrollX + this.options.offsetX + "px"
      });
      this.adjustPosition();
      return this;
    }
    if (!this.$trigger) return this;
    var triggerRect = this.$trigger[0].getBoundingClientRect();
    var menuWidth = this.$element[0].offsetWidth;
    var menuHeight = this.$element[0].offsetHeight;
    var scrollX = window.pageXOffset;
    var scrollY = window.pageYOffset;
    var top, left;
    var placement = this.options.placement;
    var parts = placement.split("-");
    var primary = parts[0];
    var secondary = parts[1] || "start";
    switch (primary) {
      case "top":
        top = triggerRect.top + scrollY - menuHeight - 5;
        if (secondary === "start") {
          left = triggerRect.left + scrollX;
        } else if (secondary === "end") {
          left = triggerRect.right + scrollX - menuWidth;
        } else {
          left = triggerRect.left + scrollX + (triggerRect.width - menuWidth) / 2;
        }
        break;
      case "bottom":
        top = triggerRect.bottom + scrollY + 5;
        if (secondary === "start") {
          left = triggerRect.left + scrollX;
        } else if (secondary === "end") {
          left = triggerRect.right + scrollX - menuWidth;
        } else {
          left = triggerRect.left + scrollX + (triggerRect.width - menuWidth) / 2;
        }
        break;
      case "left":
        left = triggerRect.left + scrollX - menuWidth - 5;
        if (secondary === "start") {
          top = triggerRect.top + scrollY;
        } else if (secondary === "end") {
          top = triggerRect.bottom + scrollY - menuHeight;
        } else {
          top = triggerRect.top + scrollY + (triggerRect.height - menuHeight) / 2;
        }
        break;
      case "right":
        left = triggerRect.right + scrollX + 5;
        if (secondary === "start") {
          top = triggerRect.top + scrollY;
        } else if (secondary === "end") {
          top = triggerRect.bottom + scrollY - menuHeight;
        } else {
          top = triggerRect.top + scrollY + (triggerRect.height - menuHeight) / 2;
        }
        break;
      default:
        top = triggerRect.bottom + scrollY + 5;
        left = triggerRect.left + scrollX;
    }
    this.$element.css({
      top: top + this.options.offsetY + "px",
      left: left + this.options.offsetX + "px"
    });
    this.adjustPosition();
    return this;
  },
  adjustPosition: function() {
    var menuWidth = this.$element[0].offsetWidth;
    var menuHeight = this.$element[0].offsetHeight;
    var viewportWidth = window.innerWidth;
    var viewportHeight = window.innerHeight;
    var scrollX = window.pageXOffset;
    var scrollY = window.pageYOffset;
    var top = parseFloat(this.$element.css("top")) || 0;
    var left = parseFloat(this.$element.css("left")) || 0;
    if (left + menuWidth > viewportWidth + scrollX) {
      left = viewportWidth + scrollX - menuWidth - 10;
    }
    if (left < scrollX) {
      left = scrollX + 10;
    }
    if (top + menuHeight > viewportHeight + scrollY) {
      top = viewportHeight + scrollY - menuHeight - 10;
    }
    if (top < scrollY) {
      top = scrollY + 10;
    }
    this.$element.css({
      top: top + "px",
      left: left + "px"
    });
    return this;
  },
  updateItems: function(newItems) {
    this.options.items = newItems;
    this.buildMenu(this.$element, newItems);
    return this;
  },
  updateOptions: function(newOptions) {
    this.options = $8.extend({}, this.options, newOptions);
    if (newOptions.trigger || newOptions.autoClose !== void 0) {
      this.detach();
      this.bindEvents();
      if (this.$trigger) {
        this.attachTo(this.$trigger);
      }
    }
    return this;
  },
  destroy: function() {
    this.detach();
    this.$element.remove();
    clearTimeout(this.hideTimeout);
    for (var key in this.submenuHideTimeouts) {
      clearTimeout(this.submenuHideTimeouts[key]);
    }
    return this;
  }
};
$8.fn.wsPopMenu = function(options2) {
  return this.each(function() {
    var $this = $8(this);
    var existing = $this.data("wsPopmenu");
    if (existing) {
      existing.destroy();
    }
    var instance = new WsPopMenu(options2);
    instance.attachTo($this);
    $this.data("wsPopmenu", instance);
  });
};
window.WsPopMenu = WsPopMenu;
window.WsPopMenu = WsPopMenu;
var ws_popmenu_default = WsPopMenu;

// public/statics/libs/ws-markdown/ws-markdown.js
var CLASS_ROOT = "ws-md";
var ESC_MAP = { "&": "&amp;", "<": "&lt;", '"': "&quot;", "'": "&#39;" };
var ESCAPABLE = "\\`*_~[]()#>-+.!|";
var MAX_DEPTH = 6;
var LIST_RE = /^( *)([-*+]|\d{1,9}[.)]) +(.*)$/;
var FENCE_OPEN_RE = /^ {0,3}(`{3,}|~{3,}) ?([A-Za-z0-9+#._-]{1,20})?/;
var HEADING_RE = /^ {0,3}(#{1,6}) +(.*)$/;
var BLOCKQUOTE_RE = /^ {0,3}> ?(.*)$/;
var DEFAULTS3 = {
  /** 代码块是否渲染「复制」按钮 */
  copyButton: true,
  /** 复制按钮文案 */
  copyLabel: "复制",
  /** 复制成功后的按钮文案 */
  copiedLabel: "已复制",
  /** 外链是否新窗口打开（内链 / 锚点始终当前窗口） */
  externalLinkTarget: true,
  /** stream() 的重渲染节流间隔（ms） */
  throttle: 80
};
var OPT = DEFAULTS3;
function resolveOptions(options2) {
  return options2 ? Object.assign({}, DEFAULTS3, options2) : DEFAULTS3;
}
function escapeHTML(str) {
  return String(str == null ? "" : str).replace(/[&<"']/g, (c) => ESC_MAP[c]);
}
function decodeEntities(str) {
  let out = String(str);
  for (let pass = 0; pass < 3; pass++) {
    const next = decodeOnePass(out);
    if (next === out) break;
    out = next;
  }
  return out;
}
function decodeOnePass(str) {
  return str.replace(
    /&(?:#(\d{1,7})|#[xX]([0-9a-fA-F]{1,6})|(amp|lt|gt|quot|#39));/g,
    (match, dec, hex, named) => {
      if (dec !== void 0 || hex !== void 0) {
        const code = dec !== void 0 ? Number(dec) : parseInt(hex, 16);
        if (!Number.isFinite(code) || code < 0 || code > 1114111) return match;
        try {
          return String.fromCodePoint(code);
        } catch (_) {
          return match;
        }
      }
      return named === "amp" ? "&" : named === "lt" ? "<" : named === "gt" ? ">" : named === "quot" ? '"' : "'";
    }
  );
}
function isExternalUrl(url2) {
  const probe = decodeEntities(url2).replace(/[\u0000-\u0020\u007f]+/g, "");
  return /^(https?|mailto|tel):/i.test(probe);
}
function safeUrl(raw) {
  const url2 = String(raw == null ? "" : raw).trim();
  if (!url2) return "";
  const probe = decodeEntities(url2).replace(/[\u0000-\u0020\u007f]+/g, "");
  if (probe.startsWith("//")) return "";
  if (/^[a-z][a-z0-9+.\-]*:/i.test(probe)) {
    return /^(https?|mailto|tel):/i.test(probe) ? url2 : "";
  }
  return url2;
}
function findClose(text, from, token) {
  let idx = text.indexOf(token, from);
  while (idx !== -1) {
    if (idx > from && !/\s/.test(text[idx - 1])) return idx;
    idx = text.indexOf(token, idx + 1);
  }
  return -1;
}
function readLink(text, at) {
  let depth = 0;
  let j = at;
  let label = "";
  for (; j < text.length; j++) {
    const ch = text[j];
    if (ch === "\\" && j + 1 < text.length) {
      label += ch + text[j + 1];
      j++;
      continue;
    }
    if (ch === "[") {
      depth++;
      if (depth === 1) continue;
    }
    if (ch === "]") {
      depth--;
      if (depth === 0) break;
    }
    label += ch;
  }
  if (depth !== 0 || text[j] !== "]" || text[j + 1] !== "(") return null;
  let k = j + 2;
  let d = 1;
  let url2 = "";
  for (; k < text.length; k++) {
    const ch = text[k];
    if (ch === "\\") {
      url2 += text[k + 1] || "";
      k++;
      continue;
    }
    if (ch === "(") d++;
    if (ch === ")") {
      d--;
      if (d === 0) break;
    }
    url2 += ch;
  }
  if (d !== 0) return null;
  return { label, url: url2.trim().split(/\s+/)[0] || "", end: k + 1 };
}
function inline(text) {
  let out = "";
  let i = 0;
  const n = text.length;
  while (i < n) {
    const c = text[i];
    if (c === "\\" && i + 1 < n && ESCAPABLE.includes(text[i + 1])) {
      out += text[i + 1];
      i += 2;
      continue;
    }
    if (c === "\n") {
      out += "<br>";
      i++;
      continue;
    }
    if (c === "`") {
      const run = /^`+/.exec(text.slice(i))[0].length;
      const close = text.indexOf("`".repeat(run), i + run);
      if (close !== -1) {
        out += `<code>${text.slice(i + run, close)}</code>`;
        i = close + run;
        continue;
      }
      out += c;
      i++;
      continue;
    }
    if (c === "!" && text[i + 1] === "[") {
      const link = readLink(text, i + 1);
      if (link) {
        out += renderLink(link);
        i = link.end;
        continue;
      }
      out += c;
      i++;
      continue;
    }
    if (c === "[") {
      const link = readLink(text, i);
      if (link) {
        out += renderLink(link);
        i = link.end;
        continue;
      }
      out += c;
      i++;
      continue;
    }
    const isMarker = c === "*" || c === "_" || c === "~";
    const intrawordUnderscore = c === "_" && i > 0 && /[\w]/.test(text[i - 1]);
    if (isMarker && !intrawordUnderscore && text[i + 1] === c) {
      const triple = c !== "~" && text[i + 2] === c;
      const width = triple ? 3 : 2;
      const close = findClose(text, i + width, c.repeat(width));
      const inner = close === -1 ? "" : text.slice(i + width, close);
      if (close !== -1 && inner && !/^\s/.test(inner) && !/\s$/.test(inner)) {
        const body = inline(inner);
        const tag = c === "~" ? "del" : "strong";
        out += triple ? `<strong><em>${body}</em></strong>` : `<${tag}>${body}</${tag}>`;
        i = close + width;
        continue;
      }
    }
    if ((c === "*" || c === "_") && !intrawordUnderscore) {
      const close = findClose(text, i + 1, c);
      const inner = close === -1 ? "" : text.slice(i + 1, close);
      if (close !== -1 && inner && !/^\s/.test(inner) && !/\s$/.test(inner)) {
        out += `<em>${inline(inner)}</em>`;
        i = close + 1;
        continue;
      }
    }
    out += c;
    i++;
  }
  return out;
}
function renderLink(link) {
  const label = inline(link.label || link.url) || link.url;
  const url2 = safeUrl(link.url);
  if (!url2) return label;
  const attrs = OPT.externalLinkTarget && isExternalUrl(url2) ? ' target="_blank" rel="noopener noreferrer nofollow"' : "";
  return `<a href="${url2}"${attrs}>${label}</a>`;
}
function closeOpenFences(text) {
  const openers = text.match(/^ {0,3}(`{3,}|~{3,})/gm) || [];
  if (openers.length % 2 === 0) return text;
  const marker = openers[openers.length - 1].trim();
  return text + (text.endsWith("\n") ? "" : "\n") + marker + "\n";
}
function codeBlockHTML(code, lang) {
  const label = lang ? `<span class="ws-md-lang">${lang}</span>` : "";
  let bar = "";
  if (OPT.copyButton) {
    bar = `<button type="button" class="ws-md-copy" data-ws-md-copy="1" data-ws-md-copied="${escapeHTML(OPT.copiedLabel)}" title="复制代码" aria-label="复制代码">` + escapeHTML(OPT.copyLabel) + "</button>";
  }
  return `<div class="ws-md-codeblock"><div class="ws-md-codebar">${label}${bar}</div><pre><code>${code.replace(/\n$/, "")}</code></pre></div>`;
}
function isHr(line) {
  return /^ {0,3}(?:(?:\* *){3,}|(?:- *){3,}|(?:_ *){3,})$/.test(line);
}
function isTableDelimiter(line) {
  if (!line || !line.includes("-")) return false;
  return /^ {0,3}\|? *:?-+:? *(?:\| *:?-+:? *)*\|? *$/.test(line);
}
function splitRow(line) {
  let s = line.trim();
  if (s.startsWith("|")) s = s.slice(1);
  if (s.endsWith("|")) s = s.slice(0, -1);
  return s.split("|");
}
function indentOf(line) {
  return /^ */.exec(line)[0].length;
}
function parseTable(lines, start) {
  const head = splitRow(lines[start]);
  const delim = splitRow(lines[start + 1]);
  if (!head.length || !delim.length) return null;
  const aligns = delim.map((cell) => {
    const t = cell.trim();
    if (/^:-+:$/.test(t)) return "center";
    if (/^-+:$/.test(t)) return "right";
    if (/^:-+$/.test(t)) return "left";
    return "";
  });
  const attr2 = (idx) => aligns[idx] ? ` style="text-align:${aligns[idx]}"` : "";
  let html = '<div class="ws-md-tablewrap"><table><thead><tr>';
  head.forEach((cell, idx) => {
    html += `<th${attr2(idx)}>${inline(cell.trim())}</th>`;
  });
  html += "</tr></thead><tbody>";
  let i = start + 2;
  for (; i < lines.length; i++) {
    const line = lines[i];
    if (!line.trim() || !line.includes("|")) break;
    const cells = splitRow(line);
    html += "<tr>";
    for (let c = 0; c < head.length; c++) {
      html += `<td${attr2(c)}>${inline((cells[c] === void 0 ? "" : cells[c]).trim())}</td>`;
    }
    html += "</tr>";
  }
  return { html: html + "</tbody></table></div>", next: i };
}
function parseList(lines, start, depth) {
  const first = LIST_RE.exec(lines[start]);
  const base = first[1].length;
  const ordered = /^\d/.test(first[2]);
  const contentIndent = base + first[2].length + 1;
  const items = [];
  let i = start;
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) {
      const next = lines[i + 1] || "";
      if (next.trim() && indentOf(next) >= contentIndent) {
        if (items.length) items[items.length - 1].rest.push("");
        i++;
        continue;
      }
      break;
    }
    const m = LIST_RE.exec(line);
    if (m && m[1].length === base && /^\d/.test(m[2]) === ordered) {
      items.push({ text: m[3], rest: [] });
      i++;
      continue;
    }
    if (indentOf(line) >= contentIndent) {
      if (!items.length) break;
      items[items.length - 1].rest.push(line.slice(contentIndent));
      i++;
      continue;
    }
    break;
  }
  const tag = ordered ? "ol" : "ul";
  let html = `<${tag}>`;
  for (const item of items) {
    let inner = inline(item.text);
    const rest = item.rest.filter((l) => l.trim());
    if (rest.length) {
      if (LIST_RE.test(rest[0])) {
        inner += renderBlocks(rest, depth + 1);
      } else {
        inner += `<br>${inline(rest.join("\n"))}`;
      }
    }
    html += `<li>${inner}</li>`;
  }
  return { html: html + `</${tag}>`, next: i };
}
function startsBlock(line) {
  return !line.trim() || FENCE_OPEN_RE.test(line) || HEADING_RE.test(line) || BLOCKQUOTE_RE.test(line) || isHr(line) || LIST_RE.test(line) || isTableDelimiter(line);
}
function renderBlocks(lines, depth) {
  if (depth > MAX_DEPTH) return `<p>${inline(lines.join("\n"))}</p>`;
  const out = [];
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) {
      i++;
      continue;
    }
    const fence = FENCE_OPEN_RE.exec(line);
    if (fence) {
      const marker = fence[1][0];
      const closeRe = new RegExp(`^ {0,3}${marker === "`" ? "`" : "~"}{${fence[1].length},}\\s*$`);
      const lang = fence[2] || "";
      const buf2 = [];
      let j2 = i + 1;
      for (; j2 < lines.length; j2++) {
        if (closeRe.test(lines[j2])) break;
        buf2.push(lines[j2]);
      }
      out.push(codeBlockHTML(buf2.join("\n"), lang));
      i = j2 < lines.length ? j2 + 1 : j2;
      continue;
    }
    const head = HEADING_RE.exec(line);
    if (head) {
      const level = head[1].length;
      out.push(`<h${level}>${inline(head[2].replace(/ +#+ *$/, ""))}</h${level}>`);
      i++;
      continue;
    }
    if (isHr(line)) {
      out.push("<hr>");
      i++;
      continue;
    }
    if (BLOCKQUOTE_RE.test(line)) {
      const buf2 = [];
      let j2 = i;
      for (; j2 < lines.length; j2++) {
        const m = BLOCKQUOTE_RE.exec(lines[j2]);
        if (!m) break;
        buf2.push(m[1]);
      }
      out.push(`<blockquote>${renderBlocks(buf2, depth + 1)}</blockquote>`);
      i = j2;
      continue;
    }
    if (line.includes("|") && isTableDelimiter(lines[i + 1] || "")) {
      const table2 = parseTable(lines, i);
      if (table2) {
        out.push(table2.html);
        i = table2.next;
        continue;
      }
    }
    if (LIST_RE.test(line)) {
      const list = parseList(lines, i, depth);
      out.push(list.html);
      i = list.next;
      continue;
    }
    const buf = [line];
    let j = i + 1;
    for (; j < lines.length; j++) {
      if (startsBlock(lines[j])) break;
      buf.push(lines[j]);
    }
    out.push(`<p>${inline(buf.join("\n"))}</p>`);
    i = j;
  }
  return out.join("");
}
function render(source, options2) {
  const prev = OPT;
  OPT = resolveOptions(options2);
  try {
    let text = String(source == null ? "" : source).replace(/\r\n?/g, "\n").replace(/\t/g, "    ");
    text = closeOpenFences(text);
    text = escapeHTML(text);
    return renderBlocks(text.split("\n"), 0);
  } finally {
    OPT = prev;
  }
}
function copyText(text) {
  if (window.navigator.clipboard && window.isSecureContext) {
    try {
      return window.navigator.clipboard.writeText(text).then(() => true, () => execCopy(text));
    } catch (_) {
    }
  }
  return Promise.resolve(execCopy(text));
}
function execCopy(text) {
  const ta = document.createElement("textarea");
  ta.value = text;
  ta.setAttribute("readonly", "readonly");
  ta.style.cssText = "position:fixed;top:-1000px;left:-1000px;opacity:0;";
  document.body.appendChild(ta);
  ta.select();
  let ok = false;
  try {
    ok = document.execCommand("copy");
  } catch (_) {
    ok = false;
  }
  document.body.removeChild(ta);
  return ok;
}
function bindCopy(el) {
  if (el.__wsMdCopyBound) return;
  el.__wsMdCopyBound = true;
  el.addEventListener("click", (e) => {
    const target = e.target instanceof Element ? e.target : null;
    const btn = target ? target.closest("[data-ws-md-copy]") : null;
    if (!btn) return;
    if (e.__wsMdHandled) return;
    e.__wsMdHandled = true;
    const block = btn.closest(".ws-md-codeblock");
    const code = block ? block.querySelector("code") : null;
    copyText(code ? code.textContent : "");
    if (btn.dataset.wsMdCopied2 === "1") return;
    const restore2 = btn.textContent;
    btn.dataset.wsMdCopied2 = "1";
    btn.textContent = btn.dataset.wsMdCopied || restore2;
    setTimeout(() => {
      btn.textContent = restore2;
      delete btn.dataset.wsMdCopied2;
    }, 1200);
  });
}
function mount(el, source, options2) {
  if (!el) return null;
  el.classList.add(CLASS_ROOT);
  el.innerHTML = render(source, options2);
  bindCopy(el);
  return el;
}
function findScrollContainer(el) {
  let node = el.parentElement;
  while (node && node !== document.body && node !== document.documentElement) {
    const overflowY = window.getComputedStyle(node).overflowY;
    if (overflowY === "auto" || overflowY === "scroll") return node;
    node = node.parentElement;
  }
  return null;
}
function stream(target, options2) {
  const opts = resolveOptions(options2);
  const resolve = typeof target === "function" ? target : () => target;
  const probe = resolve();
  const scroller = options2 && options2.scrollContainer || (probe ? findScrollContainer(probe) : null);
  let buffer = "";
  let timer = 0;
  let lastAt = 0;
  const nearBottom = () => !scroller || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 48;
  const paint = () => {
    const el = resolve();
    if (!el) return;
    const stick = nearBottom();
    el.classList.add(CLASS_ROOT);
    el.innerHTML = render(buffer, opts);
    bindCopy(el);
    if (stick && scroller) scroller.scrollTop = scroller.scrollHeight;
  };
  const flush = () => {
    lastAt = Date.now();
    paint();
  };
  const schedule = () => {
    if (timer) return;
    const wait = Math.max(0, opts.throttle - (Date.now() - lastAt));
    timer = setTimeout(() => {
      timer = 0;
      flush();
    }, wait);
  };
  const clear = () => {
    if (timer) {
      clearTimeout(timer);
      timer = 0;
    }
  };
  return {
    /**
     * 追加增量文本（节流渲染）
     * @param {string} chunk
     */
    push(chunk) {
      if (chunk) buffer += String(chunk);
      schedule();
    },
    /**
     * 直接设定全文并立即渲染
     * @param {string} text
     */
    set(text) {
      buffer = String(text == null ? "" : text);
      clear();
      flush();
    },
    /**
     * 结束流式：清理定时器并做一次终态渲染
     * @param {string} [finalText] 传入时以其为准（用于校正节流滞后）
     */
    end(finalText) {
      if (typeof finalText === "string") buffer = finalText;
      clear();
      lastAt = 0;
      paint();
    },
    /** 中止并清理（不渲染） */
    destroy() {
      clear();
    },
    /** 当前已累积文本 */
    get text() {
      return buffer;
    }
  };
}
var WsMarkdown = { render, mount, stream, bindCopy, escapeHTML, closeOpenFences };
if (typeof window !== "undefined") {
  window.WsMarkdown = WsMarkdown;
}
var ws_markdown_default = WsMarkdown;

// public/statics/modules/ws/index.js
var ws_default = {
  WsLayer: ws_layer_default,
  WsSelect: ws_select_default,
  WsUpload: ws_upload_default,
  WsFormValidator: ws_form_validator_default,
  WsColorPicker: ws_color_picker_default,
  WsDatePicker: ws_date_picker_default,
  WsHintInput: ws_hint_input_default,
  WsLinkageSelect: ws_linkage_select_default,
  WsPopMenu: ws_popmenu_default,
  WsMarkdown: ws_markdown_default
};

// public/statics/modules/wise-bootstrap.js
init_wise_core();
init_wise_util();
init_wise_ui();

// public/statics/modules/wise-dom.js
var wise_dom_exports = {};
__export(wise_dom_exports, {
  addClass: () => addClass,
  attr: () => attr,
  create: () => create,
  data: () => data2,
  delegate: () => delegate,
  isInViewport: () => isInViewport,
  offset: () => offset,
  removeClass: () => removeClass,
  render: () => render2,
  renderRaw: () => renderRaw,
  scrollTo: () => scrollTo,
  toggleClass: () => toggleClass
});
init_wise_util();
function create(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, val] of Object.entries(attrs)) {
    if (val === null || val === void 0) continue;
    switch (key) {
      case "text":
        el.textContent = val;
        break;
      case "html":
        el.innerHTML = val;
        break;
      case "class":
        if (Array.isArray(val)) el.classList.add(...val.filter(Boolean));
        else el.className = val;
        break;
      case "style":
        if (typeof val === "object") Object.assign(el.style, val);
        else el.style.cssText = val;
        break;
      default:
        if (key.startsWith("on") && typeof val === "function") {
          el.addEventListener(key.slice(2).toLowerCase(), val);
        } else if (key.startsWith("data-")) {
          el.setAttribute(key, val);
        } else {
          el.setAttribute(key, val);
        }
    }
  }
  for (const child of children) {
    if (child == null) continue;
    if (typeof child === "string") {
      el.appendChild(document.createTextNode(codec.htmlEscape(child)));
    } else if (child instanceof Node) {
      el.appendChild(child);
    } else {
      el.appendChild(document.createTextNode(String(child)));
    }
  }
  return el;
}
function render2(template, data3 = {}) {
  return template.replace(/\{\{(.+?)\}\}/g, (_, key) => {
    const val = key.trim().split(".").reduce((o, k) => (o || {})[k], data3);
    return val != null ? codec.htmlEscape(String(val)) : "";
  });
}
function renderRaw(template, data3 = {}) {
  return template.replace(/\{\{(.+?)\}\}/g, (_, key) => {
    const val = key.trim().split(".").reduce((o, k) => (o || {})[k], data3);
    return val != null ? String(val) : "";
  });
}
function attr(el, attrs) {
  for (const [k, v] of Object.entries(attrs)) {
    if (v == null) el.removeAttribute(k);
    else el.setAttribute(k, v);
  }
}
function addClass(el, ...classes) {
  el.classList.add(...classes.filter(Boolean));
}
function removeClass(el, ...classes) {
  el.classList.remove(...classes.filter(Boolean));
}
function toggleClass(el, cls) {
  el.classList.toggle(cls);
}
function data2(el, key, value) {
  if (arguments.length === 2) {
    return el.dataset[key];
  }
  el.dataset[key] = value;
}
function scrollTo(el, opts = {}) {
  const target = typeof el === "string" ? document.querySelector(el) : el;
  if (target) target.scrollIntoView({ behavior: "smooth", block: "start", ...opts });
}
function isInViewport(el, threshold = 0) {
  const rect = el.getBoundingClientRect();
  const vh = window.innerHeight || document.documentElement.clientHeight;
  const vw = window.innerWidth || document.documentElement.clientWidth;
  return rect.top < vh - threshold && rect.bottom > threshold && rect.left < vw - threshold && rect.right > threshold;
}
function offset(el) {
  const rect = el.getBoundingClientRect();
  return {
    top: rect.top + window.scrollY,
    left: rect.left + window.scrollX
  };
}
function delegate(parent2, selector, eventName, handler) {
  const root = typeof parent2 === "string" ? document.querySelector(parent2) : parent2;
  if (!root) return () => {
  };
  const listener = function(e) {
    let target = e.target;
    while (target && target !== root) {
      if (target.matches(selector)) {
        handler.call(target, target, e);
        return;
      }
      target = target.parentElement;
    }
  };
  root.addEventListener(eventName, listener);
  return () => root.removeEventListener(eventName, listener);
}

// public/statics/modules/wise-plugin.js
init_wise_core();
var _plugins = {};
var _hooks = {};
var _cssRegistry = {};
var _moduleRegistry = {};
var plugin = {
  /**
   * 注册插件
   * @param {string} name - 插件唯一标识
   * @param {Object} definition
   * @param {Function} definition.install - 安装回调 (wise, options) => void
   * @param {Function} [definition.uninstall] - 卸载回调 (wise) => void
   * @param {Function} [definition.activate] - 激活回调 (wise) => void
   * @param {Function} [definition.deactivate] - 停用回调 (wise) => void
   * @param {string} [definition.version] - 版本号
   * @param {string} [definition.description] - 描述
   * @returns {boolean} 是否注册成功
   */
  register(name, definition) {
    if (!name || typeof definition.install !== "function") {
      logger.error("plugin", `插件 ${name} 缺少 install 方法`);
      return false;
    }
    if (_plugins[name]) {
      logger.warn("plugin", `插件 ${name} 已存在，将覆盖`);
    }
    _plugins[name] = {
      definition,
      status: "registered"
    };
    registry.register(`plugin:${name}`, this);
    eventBus.emit("ws:plugin:registered", { name, definition });
    logger.info("plugin", `插件 ${name} v${definition.version || "?"} 已注册`);
    return true;
  },
  /**
   * 获取已注册的插件
   * @param {string} name
   * @returns {Object|null}
   */
  get(name) {
    return _plugins[name] || null;
  },
  /**
   * 列出所有插件
   * @param {string} [status] - 按状态过滤
   * @returns {Array<{name:string, version:string, status:string, description:string}>}
   */
  list(status) {
    return Object.entries(_plugins).filter(([, pluginEntry]) => !status || pluginEntry.status === status).map(([name, pluginEntry]) => ({
      name,
      version: pluginEntry.definition.version || "-",
      status: pluginEntry.status,
      description: pluginEntry.definition.description || ""
    }));
  },
  /**
   * 安装插件（执行 install + activate）
   * @param {string} name
   * @param {Object} [options] - 传递给 install 的配置
   * @param {Object} [wise] - Wise 全局对象引用
   * @returns {boolean}
   */
  install(name, options2 = {}, wise) {
    const entry = _plugins[name];
    if (!entry) {
      logger.error("plugin", `插件 ${name} 未注册`);
      return false;
    }
    try {
      entry.definition.install(wise || window.Wise, options2);
      entry.status = "installed";
      if (entry.definition.activate) {
        entry.definition.activate(wise || window.Wise);
        entry.status = "active";
      }
      eventBus.emit("ws:plugin:activated", { name });
      logger.info("plugin", `插件 ${name} 已安装`);
      return true;
    } catch (err) {
      logger.error("plugin", `插件 ${name} 安装失败`, err);
      entry.status = "error";
      return false;
    }
  },
  /**
   * 卸载插件
   * @param {string} name
   * @param {Object} [wise]
   */
  uninstall(name, wise) {
    const entry = _plugins[name];
    if (!entry) return;
    try {
      if (entry.definition.uninstall) {
        entry.definition.uninstall(wise || window.Wise);
      }
      entry.status = "uninstalled";
      eventBus.emit("ws:plugin:uninstalled", { name });
      logger.info("plugin", `插件 ${name} 已卸载`);
    } catch (err) {
      logger.error("plugin", `插件 ${name} 卸载失败`, err);
    }
  },
  // ============================================================
  // 钩子系统
  // ============================================================
  /**
   * 添加钩子（支持异步）
   * @param {string} hook - 钩子名，如 'table.toolbar.before'
   * @param {Function} callback - (context) => context|void
   * @param {number} [priority=10] - 优先级，越小越先执行
   */
  addHook(hook, callback, priority = 10) {
    if (!_hooks[hook]) _hooks[hook] = [];
    _hooks[hook].push({ callback, priority });
    _hooks[hook].sort((a, b) => a.priority - b.priority);
  },
  /**
   * 移除钩子
   * @param {string} hook
   * @param {Function} [callback] - 不传则移除该钩子的所有回调
   */
  removeHook(hook, callback) {
    if (!callback) {
      delete _hooks[hook];
      return;
    }
    if (_hooks[hook]) {
      _hooks[hook] = _hooks[hook].filter((h) => h.callback !== callback);
      if (_hooks[hook].length === 0) delete _hooks[hook];
    }
  },
  /**
   * 触发钩子链（支持异步和取消）
   * @param {string} hook
   * @param {*} context - 传递给钩子的上下文
   * @returns {Promise<*>} 处理后的上下文
   */
  async applyHooks(hook, context) {
    if (!_hooks[hook]) return context;
    let result = context;
    for (const entry of _hooks[hook]) {
      try {
        const hookResult = entry.callback(result);
        if (hookResult instanceof Promise) {
          result = await hookResult;
        } else if (hookResult !== void 0) {
          result = hookResult;
        }
        if (result === null) return null;
      } catch (err) {
        logger.error("plugin", `钩子 ${hook} 执行异常`, err);
      }
    }
    return result;
  },
  // ============================================================
  // 资源注册
  // ============================================================
  /**
   * 注册 CSS
   * @param {string} name
   * @param {string} url
   */
  registerCss(name, url2) {
    if (_cssRegistry[name]) return;
    _cssRegistry[name] = url2;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = url2;
    link.setAttribute("data-ws-plugin", name);
    document.head.appendChild(link);
  },
  /**
   * 注册 JS 模块（仅记录，由调用方按需加载）
   * @param {string} name
   * @param {string} url
   */
  registerModule(name, url2) {
    _moduleRegistry[name] = url2;
  },
  /**
   * 获取已注册的模块 URL
   * @param {string} name
   * @returns {string|null}
   */
  getModuleUrl(name) {
    return _moduleRegistry[name] || null;
  }
};

// public/statics/modules/wise-theme.js
init_wise_core();
var STORAGE_KEY = "wise.theme";
var COOKIE_KEY = "ws_theme";
var COOKIE_DAYS = 365;
var CHANNEL_NAME = "wise-theme";
var MODES = ["light", "dark", "auto"];
var SCHEMES = ["blue", "green", "red", "purple"];
var SIDEBARS = ["auto", "dark", "light"];
var DEFAULTS4 = Object.freeze({ mode: "dark", scheme: "blue", sidebar: "auto" });
var TRANSITION_MS = 220;
var prefersDark = window.matchMedia("(prefers-color-scheme: dark)");
var _syncTimer = null;
var _transitionTimer = null;
var _theme = { ...DEFAULTS4 };
var _listeners2 = /* @__PURE__ */ new Set();
var _channel = null;
try {
  if (typeof BroadcastChannel !== "undefined") {
    _channel = new BroadcastChannel(CHANNEL_NAME);
  }
} catch (e) {
  _channel = null;
}
var _inited = false;
function _normalize(value, allowed, fallback) {
  return allowed.includes(value) ? value : fallback;
}
function _normalizeTheme(partial) {
  const src = partial && typeof partial === "object" ? partial : {};
  return {
    mode: _normalize(src.mode, MODES, DEFAULTS4.mode),
    scheme: _normalize(src.scheme, SCHEMES, DEFAULTS4.scheme),
    sidebar: _normalize(src.sidebar, SIDEBARS, DEFAULTS4.sidebar)
  };
}
function _resolveMode(mode) {
  if (mode !== "auto") return mode;
  return prefersDark.matches ? "dark" : "light";
}
function _resolveSidebar(sidebar, resolvedMode) {
  return sidebar === "auto" ? resolvedMode : sidebar;
}
function _prefersReducedMotion() {
  return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}
function _readStorage() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? parsed : null;
  } catch (e) {
    logger.warn("theme", "读取本地主题失败，回退默认值", e.message);
    return null;
  }
}
function _writeStorage(theme2) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(theme2));
  } catch (e) {
    logger.warn("theme", "写入本地主题失败（可能是隐私模式）", e.message);
  }
}
function _writeCookie(theme2) {
  try {
    const value = encodeURIComponent(JSON.stringify(theme2));
    const secure = window.location.protocol === "https:" ? "; secure" : "";
    document.cookie = `${COOKIE_KEY}=${value}; path=/; max-age=${COOKIE_DAYS * 86400}; samesite=Lax${secure}`;
  } catch (e) {
    logger.warn("theme", "写入主题 Cookie 失败", e.message);
  }
}
function _getCsrfToken() {
  const cookieKey = config.get("csrfCookieKey", "csrf_token");
  const escaped = cookieKey.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const match = document.cookie.match(new RegExp("(?:^|;\\s*)" + escaped + "=([^;]*)"));
  if (match && match[1]) {
    return decodeURIComponent(match[1]);
  }
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") || "" : "";
}
function _applyToDom(theme2, options2 = {}) {
  const root = document.documentElement;
  if (!root) return { mode: "dark", scheme: theme2.scheme, sidebar: "dark" };
  const mode = _resolveMode(theme2.mode);
  const sidebar = _resolveSidebar(theme2.sidebar, mode);
  if (options2.animate && !_prefersReducedMotion()) {
    root.classList.add("ws-theme-transition");
    clearTimeout(_transitionTimer);
    _transitionTimer = setTimeout(() => {
      root.classList.remove("ws-theme-transition");
    }, TRANSITION_MS);
  }
  root.setAttribute("data-bs-theme", mode);
  root.setAttribute("data-color-scheme", theme2.scheme);
  root.setAttribute("data-ws-sidebar", sidebar);
  root.style.colorScheme = mode;
  _syncSidebarScope(sidebar, theme2.scheme);
  return { mode, scheme: theme2.scheme, sidebar };
}
function _syncSidebarScope(sidebar, scheme) {
  const scopes = document.querySelectorAll('[data-ws-theme-scope="sidebar"]');
  scopes.forEach((el) => {
    el.setAttribute("data-bs-theme", sidebar);
    if (scheme) {
      el.setAttribute("data-color-scheme", scheme);
    }
  });
}
function _emit(resolved, theme2) {
  const payload = { ...theme2, resolved };
  _listeners2.forEach((fn) => {
    try {
      fn(payload);
    } catch (e) {
      logger.error("theme", "主题变更订阅者执行异常", e.message);
    }
  });
  eventBus.emit("ws:theme:change", payload);
}
function _syncToServer(theme2) {
  clearTimeout(_syncTimer);
  _syncTimer = setTimeout(() => _doSync(theme2), 800);
}
async function _doSync(theme2) {
  const moduleurl = config.get("moduleurl", "");
  const url2 = `${moduleurl}/index/saveTheme`;
  try {
    const resp = await fetch(url2, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": _getCsrfToken()
      },
      body: JSON.stringify({
        mode: theme2.mode,
        scheme: theme2.scheme,
        sidebar: theme2.sidebar
      })
    });
    if (!resp.ok) {
      logger.warn("theme", `主题保存失败 HTTP ${resp.status}`);
      return;
    }
    const json = await resp.json().catch(() => null);
    if (!json || Number(json.code) !== 0) {
      logger.warn("theme", "主题保存被拒绝", json ? json.msg : `HTTP ${resp.status}`);
      return;
    }
    logger.info("theme", "主题已保存到服务端");
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
    logger.warn("theme", "主题保存失败，已降级为本地存储", e.message);
  }
}
var theme = {
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
    const serverTheme = _normalizeTheme(window.__WS_THEME__ || null);
    const localTheme = _readStorage();
    _theme = localTheme ? _normalizeTheme(localTheme) : serverTheme;
    if (!localTheme) {
      _writeStorage(_theme);
      _writeCookie(_theme);
    }
    const resolved = _applyToDom(_theme);
    const onSchemeChange = () => {
      if (_theme.mode !== "auto" && _theme.sidebar !== "auto") return;
      const next = _applyToDom(_theme, { animate: true });
      _emit(next, _theme);
    };
    if (typeof prefersDark.addEventListener === "function") {
      prefersDark.addEventListener("change", onSchemeChange);
    } else if (typeof prefersDark.addListener === "function") {
      prefersDark.addListener(onSchemeChange);
    }
    window.addEventListener("storage", (e) => {
      if (e.key !== STORAGE_KEY || !e.newValue) return;
      try {
        const incoming = _normalizeTheme(JSON.parse(e.newValue));
        _theme = incoming;
        const next = _applyToDom(_theme, { animate: true });
        _emit(next, _theme);
      } catch (err) {
        logger.warn("theme", "跨标签页主题同步失败", err.message);
      }
    });
    if (_channel) {
      _channel.onmessage = (e) => {
        const data3 = e.data;
        if (!data3 || data3.type !== "theme-change") return;
        _theme = _normalizeTheme(data3.theme);
        const next = _applyToDom(_theme, { animate: true });
        _emit(next, _theme);
      };
    }
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => this.applyScopes());
    }
    _inited = true;
    logger.info("theme", `主题已就绪: ${_theme.mode}/${_theme.scheme}/${_theme.sidebar}`);
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
      sidebar: _resolveSidebar(_theme.sidebar, mode)
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
    return this.set({ mode: current === "dark" ? "light" : "dark" });
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
  set(values, options2 = {}) {
    const { animate = true, sync = true } = options2;
    const next = _normalizeTheme({ ..._theme, ...values });
    const changed = next.mode !== _theme.mode || next.scheme !== _theme.scheme || next.sidebar !== _theme.sidebar;
    _theme = next;
    const resolved = _applyToDom(_theme, { animate: animate && changed });
    if (changed) {
      _writeStorage(_theme);
      _writeCookie(_theme);
      if (sync) {
        _syncToServer(_theme);
      }
      if (_channel) {
        try {
          _channel.postMessage({ type: "theme-change", theme: _theme });
        } catch (e) {
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
    const moduleurl = config.get("moduleurl", "");
    try {
      const resp = await fetch(`${moduleurl}/index/resetTheme`, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-TOKEN": _getCsrfToken()
        }
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
      logger.warn("theme", "重置主题失败，保留当前设置", e.message);
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
    if (typeof handler !== "function") return () => {
    };
    _listeners2.add(handler);
    return () => _listeners2.delete(handler);
  },
  /**
   * 系统是否处于深色偏好（供 UI 展示「跟随系统」当前实际效果）
   * @returns {boolean}
   */
  systemPrefersDark() {
    return prefersDark.matches;
  }
};

// public/statics/modules/wise-bootstrap.js
window.Wise = {
  version: "1.0.0",
  config,
  logger,
  eventBus,
  util: wise_util_exports,
  ui: wise_ui_exports,
  dom: wise_dom_exports,
  plugin,
  ws: ws_exports,
  // 业务模块占位（后续按需加载）
  table: null,
  navtabs: null,
  nav: null,
  permission: null,
  upload: null,
  grid: null,
  search: null,
  slot: null,
  theme
};
var W = window.Wise;
var WS_CONFIG = window.__WS_CONFIG__ || {};
config.merge(WS_CONFIG.site || {});
config.merge({
  modulename: WS_CONFIG.modulename || "",
  controllername: WS_CONFIG.controllername || WS_CONFIG.controller || "",
  actionname: WS_CONFIG.action || "",
  moduleurl: WS_CONFIG.moduleurl || "",
  upload: WS_CONFIG.upload || {}
});
theme.init();
registry.register("theme", theme);
if (WS_CONFIG.site?.debug) {
  logger.setLevel("debug");
  logger.info("core", `Wise v${W.version} 已就绪（调试模式）`);
} else {
  logger.setLevel("warn");
  logger.info("core", `Wise v${W.version} 已就绪`);
}
Promise.resolve().then(() => (init_wise_adapter(), wise_adapter_exports)).catch(() => {
  logger.warn("core", "适配层加载失败（可忽略）");
});
async function _loadBusinessModules() {
  const mods = [
    { name: "permission", target: "permission", load: () => Promise.resolve().then(() => (init_wise_permission(), wise_permission_exports)) },
    { name: "upload", target: "upload", load: () => Promise.resolve().then(() => (init_wise_upload(), wise_upload_exports)) },
    { name: "navtabs", target: "navtabs", load: () => Promise.resolve().then(() => (init_wise_navtabs(), wise_navtabs_exports)) },
    // nav 依赖 navtabs（打开标签）与 framework（兜底开标签/tab meta），
    // 但都只在**调用时**访问，故顺序只需满足「装配早于页面控制器」即可。
    { name: "nav", target: "nav", load: () => Promise.resolve().then(() => (init_wise_navmenu(), wise_navmenu_exports)) },
    { name: "framework", target: "framework", load: () => Promise.resolve().then(() => (init_wise_framework(), wise_framework_exports)) },
    { name: "table", target: "table", load: () => Promise.resolve().then(() => (init_wise_table(), wise_table_exports)) },
    { name: "grid", target: "grid", load: () => Promise.resolve().then(() => (init_wise_grid(), wise_grid_exports)) },
    { name: "search", target: "search", load: () => Promise.resolve().then(() => (init_wise_search(), wise_search_exports)) },
    { name: "slot", target: "slot", load: () => Promise.resolve().then(() => (init_wise_slot(), wise_slot_exports)) }
  ];
  const settled = await Promise.allSettled(mods.map((mod) => mod.load()));
  settled.forEach((result, index) => {
    const mod = mods[index];
    if (result.status === "rejected") {
      logger.warn("core", `业务模块加载失败: ${mod.name}`, result.reason?.message ?? result.reason);
      return;
    }
    try {
      const m = result.value;
      let instance = m[mod.target] ?? m.default;
      if (!instance) {
        logger.warn("core", `业务模块 ${mod.name} 缺少可用导出（需 export ${mod.target} 或 export default）`);
        instance = m;
      }
      window.Wise[mod.target] = instance;
      registry.register(mod.target, instance);
      if (mod.name === "navtabs" && instance) {
        instance._eventBus = eventBus;
        window.navtab = instance;
      }
      if (mod.name === "search" && instance && typeof instance.init === "function") {
        instance.init();
      }
      logger.info("core", `业务模块已加载: ${mod.name}`);
    } catch (err) {
      logger.warn("core", `业务模块装配失败: ${mod.name}`, err.message);
    }
  });
}
async function _loadFeatureModules() {
  const modules = window.__WS_FEATURE_MODULES__ || [];
  const loaded = await Promise.all(modules.map(async (m) => {
    if (!m || !m.url) return null;
    const key = m.key || "module";
    try {
      const mod = await import(
        /* @vite-ignore */
        m.url
      );
      const instance = mod[key] ?? mod.default;
      if (!instance) {
        logger.warn("core", `功能模块缺少可用导出: ${key}`);
        return null;
      }
      W[key] = instance;
      registry.register(key, instance);
      return { key, instance };
    } catch (err) {
      logger.warn("core", `功能模块加载失败: ${key}`, err?.message ?? err);
      return null;
    }
  }));
  for (const item of loaded) {
    if (!item) continue;
    try {
      if (typeof item.instance.init === "function") {
        await item.instance.init();
      }
      logger.info("core", `功能模块已加载: ${item.key}`);
    } catch (err) {
      logger.warn("core", `功能模块初始化失败: ${item.key}`, err?.message ?? err);
    }
  }
}
var controllerJs = WS_CONFIG.js;
var action2 = WS_CONFIG.action || "index";
function _controllerVersion() {
  const siteVersion = WS_CONFIG.site && WS_CONFIG.site.version ? WS_CONFIG.site.version : "";
  const version = WS_CONFIG.jsv || siteVersion || WS_CONFIG.version || "";
  return version === "" || version === null || version === void 0 ? "" : String(version);
}
function _versionedPath(jsPath) {
  const version = _controllerVersion();
  if (!version) {
    return jsPath;
  }
  const sep = jsPath.includes("?") ? "&" : "?";
  return jsPath + sep + "v=" + encodeURIComponent(version);
}
async function _loadController() {
  await Promise.all([_loadBusinessModules(), _loadFeatureModules()]);
  if (controllerJs) {
    const jsPath = controllerJs.endsWith(".js") ? controllerJs : controllerJs + ".js";
    const modulePath = new URL(_versionedPath(jsPath), import.meta.url).href;
    try {
      const module = await import(modulePath);
      const Controller = module.default || module;
      if (Controller && typeof Controller[action2] === "function") {
        logger.info("core", `加载控制器: ${controllerJs}#${action2}`);
        if (document.readyState === "loading") {
          document.addEventListener("DOMContentLoaded", () => {
            Controller[action2]();
            eventBus.emit("ws:core:ready");
          });
        } else {
          Controller[action2]();
          eventBus.emit("ws:core:ready");
        }
      } else {
        logger.warn("core", `控制器 ${controllerJs} 缺少方法 ${action2}`);
        eventBus.emit("ws:core:ready");
      }
    } catch (err) {
      logger.error("core", `控制器加载失败: ${controllerJs}`, err);
      eventBus.emit("ws:core:ready");
    }
  } else {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => eventBus.emit("ws:core:ready"));
    } else {
      eventBus.emit("ws:core:ready");
    }
  }
}
_loadController();
eventBus.on("ws:core:ready", async () => {
  if (W.slot && typeof W.slot.renderAll === "function") {
    try {
      const page = WS_CONFIG.controller || "dashboard";
      await W.slot.init(page);
      await W.slot.renderAll();
    } catch (err) {
      logger.warn("slot", "插槽渲染失败", err.message);
    }
  }
});
window.Wise = W;
export {
  config,
  wise_dom_exports as dom,
  eventBus,
  logger,
  plugin,
  theme,
  wise_ui_exports as ui,
  wise_util_exports as util
};
//# sourceMappingURL=wise-bundle.js.map
