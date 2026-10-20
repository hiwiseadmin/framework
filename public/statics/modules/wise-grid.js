/**
 * Wise.grid — 首页网格布局（ESM 门面）
 *
 * 封装 window.GridManager（deprecated/grid-manager.js），
 * 提供 Wise.grid.* 统一 API。
 *
 * @module wise-grid
 * @version 1.0.0
 */

import { registry, eventBus, logger } from './wise-core.js';

function _getGridManager() {
    return window.GridManager || null;
}

const grid = {
    /**
     * 创建网格布局
     * @param {string|HTMLElement} selector - 容器选择器
     * @param {Object} options - { gridItems, baseHeight, columns, onLayoutChange }
     * @returns {Promise<Object|null>}
     */
    async create(selector, options = {}) {
        return new Promise((resolve, reject) => {
            const GM = _getGridManager();
            if (GM) {
                try {
                    const instance = new GM(selector, options);
                    instance.init();
                    eventBus.emit('ws:grid:created', { selector, instance });
                    resolve(instance);
                } catch (err) {
                    logger.error('grid', '创建网格失败', err);
                    reject(err);
                }
                return;
            }

            let attempts = 0;
            const timer = setInterval(() => {
                attempts++;
                const GM = _getGridManager();
                if (GM) {
                    clearInterval(timer);
                    try {
                        const instance = new GM(selector, options);
                        instance.init();
                        eventBus.emit('ws:grid:created', { selector, instance });
                        resolve(instance);
                    } catch (err) {
                        reject(err);
                    }
                } else if (attempts > 100) {
                    clearInterval(timer);
                    reject(new Error('GridManager 加载超时'));
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
    },
};

// 监听 GridManager 就绪
const checkInterval = setInterval(() => {
    if (_getGridManager()) {
        clearInterval(checkInterval);
        eventBus.emit('ws:grid:ready');
        logger.info('grid', 'GridManager 已就绪');
    }
}, 200);
setTimeout(() => clearInterval(checkInterval), 10000);

registry.register('grid', grid);

export default grid;
export { grid };
