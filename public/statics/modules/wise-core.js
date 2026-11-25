/**
 * Wise.core — 框架内核
 * 提供配置管理、日志系统、模块注册表、统一事件总线
 *
 * @module wise-core
 * @version 1.0.0
 * @license MIT
 */

// ============================================================
// 配置管理
// ============================================================
const _config = {};

/**
 * 按点号分隔的路径获取嵌套对象的值
 * @param {Object} obj
 * @param {string} path - 如 'upload.maxSize'
 * @returns {*}
 */
function _getByPath(obj, path) {
    const keys = path.split('.');
    let current = obj;
    for (const key of keys) {
        if (current == null || typeof current !== 'object') return undefined;
        current = current[key];
    }
    return current;
}

/**
 * 按点号分隔的路径设置嵌套对象的值
 * @param {Object} obj
 * @param {string} path
 * @param {*} value
 */
function _setByPath(obj, path, value) {
    const keys = path.split('.');
    const lastKey = keys.pop();
    let current = obj;
    for (const key of keys) {
        if (!(key in current) || typeof current[key] !== 'object' || current[key] === null) {
            current[key] = {};
        }
        current = current[key];
    }
    current[lastKey] = value;
}

const config = {
    /**
     * 获取配置值（支持点号路径）
     * @param {string} path - 配置路径，如 'upload.maxSize'
     * @param {*} [defaultValue] - 默认值
     * @returns {*}
     */
    get(path, defaultValue) {
        const val = _getByPath(_config, path);
        return val !== undefined ? val : defaultValue;
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
    merge(options) {
        if (!options || typeof options !== 'object') return;
        for (const [key, value] of Object.entries(options)) {
            if (typeof value === 'object' && !Array.isArray(value) && value !== null) {
                _config[key] = { ...(_config[key] || {}), ...value };
            } else {
                _config[key] = value;
            }
        }
    },

    /** 获取全部配置（只读） */
    all() {
        return Object.freeze({ ..._config });
    },
};

// ============================================================
// 日志系统
// ============================================================
const LOG_LEVELS = { debug: 0, info: 1, warn: 2, error: 3, none: 99 };
let _logLevel = LOG_LEVELS.warn;
let _logEnabled = true;

const logger = {
    /**
     * 输出日志
     * @param {string} module - 模块名
     * @param {'debug'|'info'|'warn'|'error'} level
     * @param {string} message
     * @param {*} [data]
     */
    log(module, level, message, data) {
        if (!_logEnabled || LOG_LEVELS[level] < _logLevel) return;
        const prefix = `[Wise:${module}]`;
        const fn = level === 'error' ? console.error
            : level === 'warn' ? console.warn
            : level === 'info' ? console.info
            : console.debug;
        // console.log(prefix, message, data !== undefined ? data : '');
        fn(prefix, message, data !== undefined ? data : '');
    },

    setLevel(level) {
        if (level in LOG_LEVELS) _logLevel = LOG_LEVELS[level];
    },

    enable() { _logEnabled = true; },
    disable() { _logEnabled = false; },
};

// 便捷方法
['debug', 'info', 'warn', 'error'].forEach(lvl => {
    logger[lvl] = function (module, message, data) {
        this.log(module, lvl, message, data);
    };
});

// ============================================================
// 模块注册表
// ============================================================
const _registry = {};
const REGISTRY_ORDER = [];

const registry = {
    /**
     * 注册模块
     * @param {string} name - 模块名
     * @param {Object} instance - 模块实例
     * @param {string[]} [dependencies] - 依赖的模块名列表
     */
    register(name, instance, dependencies = []) {
        if (_registry[name]) {
            logger.warn('registry', `模块 ${name} 已注册，将被覆盖`);
        }
        _registry[name] = { instance, dependencies };
        logger.info('registry', `模块 ${name} 已注册`);
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
    },
};

// ============================================================
// 统一事件总线
// ============================================================
const _listeners = {};

const eventBus = {
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
        // 按优先级降序排列
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
            _listeners[event] = _listeners[event].filter(e => e.handler !== handler);
            if (_listeners[event].length === 0) delete _listeners[event];
        }
    },

    /**
     * 触发事件（异步，不阻塞主线程）
     * @param {string} event
     * @param {*} [data] - 事件数据
     * @returns {Promise<Array>} 所有处理函数的返回值
     */
    emit(event, data) {
        if (!_listeners[event]) return Promise.resolve([]);
        const handlers = [..._listeners[event]];

        // 移除 once 监听器
        _listeners[event] = _listeners[event].filter(e => !e.once);
        if (_listeners[event].length === 0) delete _listeners[event];

        const results = [];
        const promises = handlers.map(entry => {
            try {
                const result = entry.handler(data);
                results.push(result);
                return result instanceof Promise ? result : Promise.resolve(result);
            } catch (err) {
                logger.error('eventBus', `事件 ${event} 处理异常`, err);
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
    },
};

// ============================================================
// 导出
// ============================================================
export { config, logger, registry, eventBus };
