/**
 * Wise.adapter — 向后兼容适配层
 *
 * 将旧的全局 API（Toolkit/Framework/navtab/EnhancedTable/permissionManager/L）
 * 映射到新的 Wise.* 命名空间，标记 @deprecated 并输出 console.warn。
 *
 * 此文件在 wise-core 初始化后在浏览器上下文中独立加载。
 *
 * @module wise-adapter
 * @version 1.0.0
 * @license MIT
 */

(function () {
    'use strict';

    if (!window.Wise) {
        console.warn('[WiseAdapter] Wise 核心未加载，跳过适配');
        return;
    }

    const W = window.Wise;
    const warned = {};
    const deprecate = (oldName, newName) => {
        if (!warned[oldName]) {
            console.warn(`[WiseAdapter] \`${oldName}\` 已废弃，请使用 \`${newName}\``);
            warned[oldName] = true;
        }
    };

    // ============================================================
    // Toolkit
    // ============================================================
    if (typeof window.Toolkit === 'undefined') {
        const _Toolkit = {
            config: {
                get apiBaseUrl() { deprecate('Toolkit.config.apiBaseUrl', 'Wise.config'); return W.config.get('apiBaseUrl'); },
                set apiBaseUrl(value) { W.config.set('apiBaseUrl', value); },
            },
            events: {
                onSuccess(data, callback, message) {
                    deprecate('Toolkit.events.onSuccess', 'Wise.ui.toast.success');
                    W.ui.toast.success(message || '操作成功');
                    if (typeof callback === 'function') callback(data);
                },
                onError(error, callback, message) {
                    deprecate('Toolkit.events.onError', 'Wise.ui.toast.error');
                    const resp = error?.jqXHR?.responseJSON;
                    const errMsg = message || (resp?.msg || resp?.message) || (error?.statusText) || '操作失败';
                    W.ui.toast.error(errMsg);
                    if (typeof callback === 'function') callback(error);
                },
            },
            api: {
                showToast(msg, type) {
                    deprecate('Toolkit.api.showToast', 'Wise.ui.toast.show');
                    W.ui.toast.show(msg, type);
                },
                fixUrl(url, isAbsolute) {
                    deprecate('Toolkit.api.fixUrl', 'Wise.util.url.fix');
                    return W.util.url.fix(url, isAbsolute);
                },
                getUrlParam() {
                    deprecate('Toolkit.api.getUrlParam', 'Wise.util.url.parseParams / getParam');
                    // 只兼容基本调用
                    if (arguments.length === 0) return W.util.url.parseParams();
                    return W.util.url.getParam(arguments[0]);
                },
                buildQueryParams(params) {
                    deprecate('Toolkit.api.buildQueryParams', 'Wise.util.url.buildParams');
                    return W.util.url.buildParams(params);
                },
                request(url, data, type) {
                    deprecate('Toolkit.api.request', 'Wise.util.http.request');
                    const method = type || 'GET';
                    if (method === 'GET') return W.util.http.get(url, data);
                    return W.util.http.post(url, data);
                },
            },
        };
        window.Toolkit = _Toolkit;
    }

    // ============================================================
    // L (i18n)
    // ============================================================
    if (typeof window.L === 'undefined') {
        window.L = function () {
            deprecate('window.L()', 'Wise.util.i18n.t()');
            return W.util.i18n.t.apply(W.util.i18n, arguments);
        };
    }

    // ============================================================
    // Layer (ws-layer 全局别名)
    // ============================================================
    // Layer 是 ws-layer 直接挂的，只在缺失时桥接
    if (typeof window.Layer === 'undefined') {
        window.Layer = W.ui.dialog;
    }

    // ============================================================
    // Framework (旧的 framework.js)
    // ============================================================
    if (typeof window.Framework === 'undefined') {
        window.Framework = {
            switchTheme(theme) {
                deprecate('Framework.switchTheme', 'Wise.config.set("theme")');
                W.config.set('theme', theme);
            },
            getTheme() {
                return W.config.get('theme', 'light');
            },
        };
    }

    // ============================================================
    // navtab (旧的 navtabs.js)
    // ============================================================
    if (typeof window.navtab === 'undefined') {
        window.navtab = {};
        ['add', 'close', 'switchTo', 'refresh'].forEach(method => {
            window.navtab[method] = function () {
                deprecate(`navtab.${method}`, `Wise.navtabs.${method}`);
                if (W.navtabs && W.navtabs[method]) {
                    return W.navtabs[method].apply(W.navtabs, arguments);
                }
            };
        });
    }

    // ============================================================
    // EnhancedTable (旧的 table.js)
    // ============================================================
    if (typeof window.EnhancedTable === 'undefined') {
        window.EnhancedTable = {
            init(selector, opts) {
                deprecate('EnhancedTable.init', 'Wise.table.create');
                if (W.table) return W.table.create(selector, opts);
            },
            FORMATTERS: new Proxy({}, {
                get(target, prop) {
                    if (W.table && W.table.formatters) {
                        return W.table.formatters[prop];
                    }
                },
            }),
        };
    }

    // ============================================================
    // permissionManager
    // ============================================================
    if (typeof window.permissionManager === 'undefined') {
        window.permissionManager = {
            init() {
                deprecate('permissionManager.init', 'Wise.permission.init');
                if (W.permission) return W.permission.init();
            },
            can(perm) {
                deprecate('permissionManager.can', 'Wise.permission.can');
                if (W.permission) return W.permission.can(perm);
                return true;
            },
            autoControl() {
                deprecate('permissionManager.autoControl', 'Wise.permission.autoControl');
                if (W.permission) W.permission.autoControl();
            },
        };
    }

    console.log('[WiseAdapter] 向后兼容适配层已就绪');
})();
