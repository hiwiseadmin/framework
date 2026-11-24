/**
 * wise-slot.js — 前端插槽渲染系统 (ESM)
 *
 * 为管理后台提供命名插槽机制，支持插件动态注入 UI 组件。
 *
 * 架构：
 *  - 页面标记 data-slot="name" 占位元素
 *  - bootstrap 注入 __WS_CONFIG__.slots 插槽元数据
 *  - SlotManager 异步加载并渲染插件组件到对应占位元素
 *
 * 使用方式：
 * <pre>
 * // 页面模板
 * <div data-slot="dashboard.widgets.top"></div>
 *
 * // JS 初始化 (在 wise-bootstrap.js 中自动调用)
 * await Wise.slot.init();
 *
 * // 手动渲染指定插槽
 * await Wise.slot.render('dashboard.widgets.top');
 * </pre>
 *
 * 插件组件规范：
 * <pre>
 * // plugin/shop/widget/SalesOverview.js
 * export default {
 *     async render(container, props) {
 *         container.innerHTML = '<div class="stat-card">...</div>';
 *     },
 *     async destroy(container) {
 *         container.innerHTML = '';
 *     }
 * };
 * </pre>
 *
 * @package Wise.Slot
 * @since 1.1.0
 */

const DEFAULT_LOADING_HTML = '<div class="d-flex justify-content-center p-3"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div></div>';
const DEFAULT_ERROR_HTML = '<div class="text-center text-muted p-2"><small>组件加载失败</small></div>';

/**
 * 插槽渲染器
 */
class SlotRenderer {
    /**
     * Slot 元数据注册表
     * @type {Map<string, Object>}
     */
    #definitions = new Map();

    /**
     * 已渲染的组件实例缓存
     * @type {Map<string, Array<{name: string, instance: Object, container: HTMLElement}>>}
     */
    #rendered = new Map();

    /**
     * 初始化：从 __WS_CONFIG__ 或 API 加载插槽元数据
     *
     * @param {string} page 页面标识，如 'dashboard'
     * @returns {Promise<void>}
     */
    async init(page = 'dashboard') {
        // 优先从 __WS_CONFIG__ 读取（PHP 注入，零额外请求）
        const config = window.__WS_CONFIG__;
        if (config && config.slots && Array.isArray(config.slots)) {
            for (const slot of config.slots) {
                this.#definitions.set(slot.name, slot);
            }
            this.#log(`已从配置加载 ${config.slots.length} 个插槽`);
            return;
        }

        // 回退：AJAX 获取
        try {
            const url = Wise.config.get('slots.api', '/admin/slot/list') + '?page=' + page;
            const resp = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await resp.json();
            if (json.code === 0 && json.data && json.data.slots) {
                for (const slot of json.data.slots) {
                    this.#definitions.set(slot.name, slot);
                }
                this.#log(`已从 API 加载 ${json.data.slots.length} 个插槽`);
            }
        } catch (err) {
            this.#warn('加载插槽配置失败', err);
        }
    }

    /**
     * 渲染页面上的所有插槽
     * 查找所有带 data-slot 属性的元素并渲染。
     *
     * @returns {Promise<void>}
     */
    async renderAll() {
        const containers = document.querySelectorAll('[data-slot]');
        if (containers.length === 0) return;

        this.#log(`发现 ${containers.length} 个插槽占位元素`);

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
            this.#warn(`插槽 [${slotName}] 的占位元素不存在`);
            return;
        }

        const definition = this.#definitions.get(slotName);
        if (!definition) {
            this.#warn(`插槽 [${slotName}] 未在配置中定义`);
            return;
        }

        const contents = definition.contents || [];
        if (contents.length === 0) {
            // 无内容，隐藏占位容器（避免空白区域）
            if (container.dataset.slotHideEmpty !== 'false') {
                container.style.display = 'none';
            }
            return;
        }

        const renderer = definition.renderer || 'stack';

        // 设置渲染容器 CSS 类
        this.#applyRendererClass(container, renderer);

        // 渲染所有内容
        const instances = [];
        for (const item of contents) {
            const itemContainer = this.#createItemContainer(container, renderer, item);
            const instance = await this.#renderComponent(item, itemContainer);
            if (instance) {
                instances.push({ name: item.component, instance, container: itemContainer });
            }
        }

        this.#rendered.set(slotName, instances);
        this.#log(`插槽 [${slotName}] 渲染完成 (${instances.length}/${contents.length})`);
    }

    /**
     * 销毁指定插槽的所有组件
     *
     * @param {string} slotName 插槽名称
     */
    async destroy(slotName) {
        const instances = this.#rendered.get(slotName);
        if (!instances) return;

        for (const { instance, container } of instances) {
            try {
                if (instance && typeof instance.destroy === 'function') {
                    await instance.destroy(container);
                }
            } catch (err) {
                this.#warn(`销毁组件失败 [${slotName}]`, err);
            }
            if (container && container.parentNode) {
                container.remove();
            }
        }

        this.#rendered.delete(slotName);
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
        this.#definitions.set(slotName, definition);
    }

    /**
     * 获取插槽定义
     *
     * @param {string} slotName
     * @returns {Object|undefined}
     */
    getDefinition(slotName) {
        return this.#definitions.get(slotName);
    }

    // ============================================================
    // 内部方法
    // ============================================================

    /**
     * 动态加载并渲染单个组件
     */
    async #renderComponent(item, container) {
        const componentPath = item.component;
        if (!componentPath) return null;

        // 显示加载状态
        container.innerHTML = DEFAULT_LOADING_HTML;

        try {
            // 动态 import 插件组件
            // 路径约定: /statics/plugins/{plugin_ident}/js/widgets/{WidgetName}.js
            const modulePath = this.#resolveComponentPath(componentPath);
            const module = await import(modulePath);

            const Component = module.default || module;
            if (!Component || typeof Component.render !== 'function') {
                throw new Error(`组件 [${componentPath}] 必须导出 render(container, props) 方法`);
            }

            // 调用组件的 render 方法
            const props = item.props || {};
            await Component.render(container, props);

            this.#log(`组件 [${componentPath}] 渲染成功`);
            return Component;

        } catch (err) {
            this.#warn(`加载组件 [${componentPath}] 失败`, err);
            container.innerHTML = DEFAULT_ERROR_HTML;
            return null;
        }
    }

    /**
     * 解析组件路径为可 import 的模块路径
     *
     * 约定规则：
     *  - plugin/shop/SalesWidget → /statics/plugins/shop/js/SalesWidget.js
     *  - @system/statCard → /statics/modules/widgets/statCard.js
     */
    #resolveComponentPath(componentPath) {
        if (componentPath.startsWith('plugin/')) {
            const parts = componentPath.split('/');
            const pluginIdent = parts[1];
            const widgetName = parts.slice(2).join('/');
            return `/statics/plugins/${pluginIdent}/js/${widgetName}.js`;
        }

        if (componentPath.startsWith('@system/')) {
            const name = componentPath.replace('@system/', '');
            return `/statics/modules/widgets/${name}.js`;
        }

        // 直接返回（假设已经是完整路径或相对路径）
        return componentPath;
    }

    /**
     * 创建子容器的包装元素
     */
    #createItemContainer(parent, renderer, item) {
        const wrapper = document.createElement('div');
        wrapper.className = 'slot-item';

        // 添加标题栏（如果有）
        if (item.title) {
            const header = document.createElement('div');
            header.className = 'slot-item-header';
            if (item.icon) {
                const iconEl = document.createElement('i');
                iconEl.className = `${item.icon} me-1`;
                header.appendChild(iconEl);
                header.appendChild(document.createTextNode(item.title));
            } else {
                header.textContent = item.title;
            }
            wrapper.appendChild(header);
        }

        const body = document.createElement('div');
        body.className = 'slot-item-body';
        wrapper.appendChild(body);

        parent.appendChild(wrapper);
        return body;
    }

    /**
     * 根据渲染模式设置容器的 CSS 类
     */
    #applyRendererClass(container, renderer) {
        const classMap = {
            'grid':  'slot-grid',
            'stack': 'slot-stack',
            'list':  'slot-list',
            'tabs':  'slot-tabs',
        };
        const cls = classMap[renderer] || 'slot-stack';
        container.classList.add('slot-container', cls);
        container.style.display = '';
    }

    #log(msg, ...args) {
        if (window.Wise && window.Wise.logger) {
            window.Wise.logger.debug(`[Slot] ${msg}`, ...args);
        }
    }

    #warn(msg, ...args) {
        if (window.Wise && window.Wise.logger) {
            window.Wise.logger.warn(`[Slot] ${msg}`, ...args);
        }
    }
}

// 导出单例
const slotRenderer = new SlotRenderer();
export default slotRenderer;
export { SlotRenderer };
