/**
 * Wise.plugin — 插件 SDK
 * 提供插件注册、生命周期管理、钩子系统和资源注册
 *
 * @module wise-plugin
 * @version 1.0.0
 * @license MIT
 */

import { registry, eventBus, logger } from './wise-core.js';

// ============================================================
// 插件存储
// ============================================================
const _plugins = {};
const _hooks = {};
let _cssRegistry = {};
let _moduleRegistry = {};

// ============================================================
// 插件管理
// ============================================================

const plugin = {
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
        if (!name || typeof definition.install !== 'function') {
            logger.error('plugin', `插件 ${name} 缺少 install 方法`);
            return false;
        }

        if (_plugins[name]) {
            logger.warn('plugin', `插件 ${name} 已存在，将覆盖`);
        }

        _plugins[name] = {
            definition,
            status: 'registered',
        };

        registry.register(`plugin:${name}`, this);
        eventBus.emit('ws:plugin:registered', { name, definition });
        logger.info('plugin', `插件 ${name} v${definition.version || '?'} 已注册`);

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
        return Object.entries(_plugins)
            .filter(([, pluginEntry]) => !status || pluginEntry.status === status)
            .map(([name, pluginEntry]) => ({
                name,
                version: pluginEntry.definition.version || '-',
                status: pluginEntry.status,
                description: pluginEntry.definition.description || '',
            }));
    },

    /**
     * 安装插件（执行 install + activate）
     * @param {string} name
     * @param {Object} [options] - 传递给 install 的配置
     * @param {Object} [wise] - Wise 全局对象引用
     * @returns {boolean}
     */
    install(name, options = {}, wise) {
        const entry = _plugins[name];
        if (!entry) {
            logger.error('plugin', `插件 ${name} 未注册`);
            return false;
        }

        try {
            entry.definition.install(wise || window.Wise, options);
            entry.status = 'installed';

            // 自动激活
            if (entry.definition.activate) {
                entry.definition.activate(wise || window.Wise);
                entry.status = 'active';
            }

            eventBus.emit('ws:plugin:activated', { name });
            logger.info('plugin', `插件 ${name} 已安装`);

            return true;
        } catch (err) {
            logger.error('plugin', `插件 ${name} 安装失败`, err);
            entry.status = 'error';
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
            entry.status = 'uninstalled';
            eventBus.emit('ws:plugin:uninstalled', { name });
            logger.info('plugin', `插件 ${name} 已卸载`);
        } catch (err) {
            logger.error('plugin', `插件 ${name} 卸载失败`, err);
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
            _hooks[hook] = _hooks[hook].filter(h => h.callback !== callback);
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
                } else if (hookResult !== undefined) {
                    result = hookResult;
                }
                // 如果钩子显式返回 null 表示中断
                if (result === null) return null;
            } catch (err) {
                logger.error('plugin', `钩子 ${hook} 执行异常`, err);
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
    registerCss(name, url) {
        if (_cssRegistry[name]) return;
        _cssRegistry[name] = url;

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.setAttribute('data-ws-plugin', name);
        document.head.appendChild(link);
    },

    /**
     * 注册 JS 模块（仅记录，由调用方按需加载）
     * @param {string} name
     * @param {string} url
     */
    registerModule(name, url) {
        _moduleRegistry[name] = url;
    },

    /**
     * 获取已注册的模块 URL
     * @param {string} name
     * @returns {string|null}
     */
    getModuleUrl(name) {
        return _moduleRegistry[name] || null;
    },
};

export { plugin };
