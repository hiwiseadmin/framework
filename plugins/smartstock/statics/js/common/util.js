/**
 * SmartStock 插件前端公共工具（兼容别名）
 *
 * 实现只有一套：内核的 `Wise.ui`（toast / dialog / loading / action）与
 * `Wise.util.http`。本文件不含任何实现，仅为存量调用点保留别名。
 *
 * ⚠️ 为什么不再像以前那样 `import '/statics/modules/wise-util.js'`：
 *   插件 import 的是**源码模块**，与 wise-bundle.js 内部打包的那份**不是
 *   同一个实例**。内核的 setInterceptor / setErrorHandler 只作用于后者，
 *   插件自建实例会导致业务错误不 reject、失败却提示"成功"。
 *   转发到 `window.Wise.util.http`（内核已配置好的实例）从根上消除该问题。
 *
 * 时序：内核 `_loadController()` 先 await 业务模块加载完成，再动态 import
 * 页面控制器，因此本文件执行时 `window.Wise` 必然已就绪。
 *
 * @deprecated 请直接使用 `window.Wise.ui` / `window.Wise.util.http`
 */

const W = window.Wise || {};
const UI = W.ui || null;

export const $ = window.jQuery;

/**
 * 内核 http（复用 bundle 内的唯一实例，拦截器/401/错误 toast 均已配置）。
 * 用 Proxy 延迟解析：即便将来加载时序变化也不会取到 undefined 快照。
 */
export const http = new Proxy({}, {
    get(_target, prop) {
        const h = window.Wise && window.Wise.util && window.Wise.util.http;
        const value = h && h[prop];
        return typeof value === 'function' ? value.bind(h) : value;
    },
});

/**
 * 轻量提示
 * @param {string} content
 * @param {'info'|'success'|'warning'|'error'} [type='info']
 * @param {Object} [options]
 */
export function toast(content, type = 'info', options = {}) {
    if (!UI) {
        console.warn('[smartstock] Wise.ui 未就绪，toast 降级为 console：', content);
        return;
    }
    return UI.toast.show(content, type, options);
}

toast.success = (content, options) => toast(content, 'success', options);
toast.error = (content, options) => toast(content, 'error', options);
toast.warning = (content, options) => toast(content, 'warning', options);
toast.info = (content, options) => toast(content, 'info', options);

/**
 * 二次确认
 * @param {string} content
 * @param {Object} [options]
 * @returns {Promise<boolean>}
 */
export function confirm(content, options = {}) {
    if (!UI) return Promise.resolve(window.confirm(content));
    return UI.dialog.confirm(content, options);
}

/** 全局请求指示器 */
export const loading = {
    show: () => { if (UI) UI.loading.show(); },
    hide: () => { if (UI) UI.loading.hide(); },
};

/**
 * 通用提交（新增 / 编辑 / 删除 / 状态切换等）
 *
 * 委托内核 `Wise.ui.action.execute`：确认 → Loading → 请求 → 成功提示，
 * 失败提示由内核 http 的全局 errorHandler 统一承担。
 *
 * @param {Object} options
 * @param {string} options.url
 * @param {Object} [options.data]
 * @param {string} [options.message='操作成功']
 * @param {string|null} [options.confirmTitle] 传了则先弹确认框
 * @param {Function} [options.beforeSubmit] 返回 false 可中断
 * @param {Function} [options.afterSuccess]
 * @param {Function} [options.beforeClose]
 * @returns {Promise<boolean>} 是否成功
 */
export async function submit(options) {
    const {
        url, data = {}, message = '操作成功', confirmTitle = null,
        beforeSubmit, afterSuccess, beforeClose,
    } = options;

    if (typeof beforeSubmit === 'function' && beforeSubmit() === false) return false;
    if (!UI) return false;

    const res = await UI.action.execute({
        method: 'POST',
        url,
        data,
        successMsg: message,
        confirmMessage: confirmTitle,
    });

    if (res && res.success) {
        if (typeof afterSuccess === 'function') afterSuccess(res.data);
        if (typeof beforeClose === 'function') beforeClose();
        return true;
    }
    return false;
}
