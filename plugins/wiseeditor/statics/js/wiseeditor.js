/**
 * ============================================================
 * WiseEditor - 编辑器前端抽象层（核心）
 * ============================================================
 *
 * 统一的编辑器前端 API：扫描 DOM 中的编辑器容器，按 `data-editor-type`
 * 找到对应适配器并挂载，内容变化自动同步回表单字段（隐藏域 / textarea）。
 *
 * 适配器可由入口脚本预先 registerAdapter()，也可由本模块按需动态 import()。
 * 因此任何编辑器类型只要约定好模块路径，就能"插拔式"接入。
 *
 * 用法:
 *   import WiseEditor from './wiseeditor.js';
 *   await WiseEditor.init({ uploadUrl: '/admin/attachment/upload' });
 *   const editor = WiseEditor.get('editor_content');
 *   editor.getContent();
 *
 * 页面控制器通过 window.WiseEditor 访问。
 *
 * @module wiseeditor
 * @license MIT
 */

/** 已知类型的适配器模块路径（按需动态加载的兜底） */
const ADAPTER_MODULES = {
    tiptap: new URL('./wiseeditor-tiptap.js', import.meta.url).href,
    milkdown: new URL('./wiseeditor-milkdown.js', import.meta.url).href,
};

const WiseEditor = (() => {
    'use strict';

    /** @type {Map<string, object>} 适配器注册表 (type → adapter) */
    const adapters = new Map();

    /** @type {Map<string, object>} 编辑器实例表 (elementId → instance) */
    const instances = new Map();

    /**
     * 注册编辑器适配器
     *
     * @param {string} type - 编辑器类型标识
     * @param {object} adapter - 适配器对象（须实现 create(container, options)）
     */
    function registerAdapter(type, adapter) {
        adapters.set(type, adapter);
    }

    /**
     * 确保指定类型的适配器可用（未注册则尝试动态 import）
     *
     * @param {string} type
     * @returns {Promise<object|null>}
     */
    async function ensureAdapter(type) {
        if (adapters.has(type)) {
            return adapters.get(type);
        }

        const modulePath = ADAPTER_MODULES[type];
        if (!modulePath) {
            return null;
        }

        try {
            const mod = await import(/* @vite-ignore */ modulePath);
            const adapter = mod && (mod.default || mod[type]);
            if (adapter) {
                registerAdapter(type, adapter);
                return adapter;
            }
        } catch (err) {
            console.error('[WiseEditor] 加载适配器 "%s" 失败: %o', type, err);
        }

        return null;
    }

    /**
     * 取得容器的初始值与表单同步目标
     *
     * 约定：容器 id 为 `<id>`，则
     *  - 富文本：隐藏域 id = `<id>_input`
     *  - Markdown：textarea id = `<id>_textarea`
     *
     * @param {Element} container
     * @returns {{initialValue: string, syncTarget: Element|null}}
     */
    function resolveField(container) {
        const inputEl = document.getElementById(container.id + '_input');
        if (inputEl) {
            return { initialValue: inputEl.value, syncTarget: inputEl };
        }
        const textareaEl = document.getElementById(container.id + '_textarea');
        if (textareaEl) {
            return { initialValue: textareaEl.value, syncTarget: textareaEl };
        }
        return { initialValue: '', syncTarget: null };
    }

    /**
     * 初始化页面上的所有编辑器容器
     *
     * 扫描 DOM 中 [data-editor="wiseeditor"] 的元素，按需挂载对应编辑器。
     *
     * @param {object} [options] - 全局配置
     * @param {string} [options.uploadUrl] - 图片上传地址
     * @param {string} [options.csrfToken] - CSRF Token
     * @returns {Promise<object[]>} 成功创建的编辑器实例数组
     */
    async function init(options = {}) {
        const containers = document.querySelectorAll('[data-editor="wiseeditor"]');
        const results = [];

        for (const container of containers) {
            if (container._wiseEditorInstance) {
                continue;
            }

            const editorType = container.dataset.editorType;
            const adapter = await ensureAdapter(editorType);

            if (!adapter) {
                console.warn('[WiseEditor] 未找到 "%s" 类型适配器，跳过 #%s', editorType, container.id);
                continue;
            }

            const { initialValue, syncTarget } = resolveField(container);

            try {
                const instance = await adapter.create(container, {
                    uploadUrl: container.dataset.uploadUrl || options.uploadUrl,
                    csrfToken: options.csrfToken,
                    name: container.dataset.name,
                    mode: container.dataset.mode || 'richtext',
                    elementId: container.id,
                    placeholder: container.dataset.placeholder || '',
                    readonly: container.dataset.readonly === 'true',
                });

                // 先接内容更新回调，再灌入初始值，保证初始值也写回表单字段。
                if (typeof instance.onUpdate === 'function' && syncTarget) {
                    instance.onUpdate((content) => {
                        syncTarget.value = content;
                        syncTarget.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }

                if (initialValue && typeof instance.setContent === 'function') {
                    instance.setContent(initialValue);
                }

                container._wiseEditorInstance = instance;
                instances.set(container.id, instance);
                results.push(instance);
            } catch (err) {
                console.error('[WiseEditor] 初始化失败 #%s: %o', container.id, err);
            }
        }

        return results;
    }

    /**
     * 在指定容器上挂载编辑器
     *
     * @param {string|Element} selector - CSS 选择器或 DOM 元素
     * @param {object} options - 编辑器选项
     * @returns {Promise<object>}
     */
    async function mount(selector, options = {}) {
        const container = typeof selector === 'string'
            ? document.querySelector(selector)
            : selector;

        if (!container) {
            throw new Error('[WiseEditor] 找不到元素: ' + selector);
        }

        const type = container.dataset.editorType || options.type;
        const adapter = await ensureAdapter(type);

        if (!adapter) {
            throw new Error('[WiseEditor] 未找到适配器: ' + type);
        }

        const instance = await adapter.create(container, options);
        const id = container.id || ('editor_' + Date.now());
        instances.set(id, instance);
        container._wiseEditorInstance = instance;
        return instance;
    }

    /**
     * 获取指定编辑器实例
     *
     * @param {string} id - 容器元素 ID
     * @returns {object|undefined}
     */
    function get(id) {
        return instances.get(id);
    }

    /**
     * 销毁指定编辑器实例
     *
     * @param {string} id - 容器元素 ID
     */
    function destroy(id) {
        const instance = instances.get(id);
        if (instance) {
            try {
                if (instance.destroy) instance.destroy();
            } catch (e) {
                console.warn('[WiseEditor] 销毁 #%s 失败: %o', id, e);
            }
            instances.delete(id);
        }
    }

    /**
     * 销毁所有编辑器实例（页面卸载时调用）
     */
    function destroyAll() {
        instances.forEach((instance, id) => {
            try {
                if (instance.destroy) instance.destroy();
            } catch (e) {
                console.warn('[WiseEditor] 销毁 #%s 失败: %o', id, e);
            }
        });
        instances.clear();
    }

    if (typeof window !== 'undefined') {
        window.WiseEditor = {
            init, mount, get, destroy, destroyAll,
            registerAdapter,
            adapters,
            instances,
        };
    }

    return { init, mount, get, destroy, destroyAll, registerAdapter, adapters, instances };
})();

/**
 * @typedef {object} EditorInstance
 * @property {Function} getContent - 获取编辑器当前内容
 * @property {Function} setContent - 设置编辑器内容
 * @property {Function} [isDirty] - 内容是否被修改
 * @property {Function} [focus] - 聚焦编辑器
 * @property {Function} destroy - 销毁编辑器实例
 * @property {Function} [onUpdate] - 注册内容更新回调
 */

/**
 * @typedef {object} EditorAdapter
 * @property {Function} create - (container: Element, options: object) => Promise<EditorInstance>
 */

export default WiseEditor;
