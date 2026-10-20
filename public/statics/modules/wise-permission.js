/**
 * Wise.permission — 权限管理（ESM）
 *
 * 独立的 ESM 实现，不依赖旧的 permission-manager.js。
 * 提供 init/can/canAny/canAll/autoControl/renderMenu。
 *
 * @module wise-permission
 * @version 1.0.0
 */

import { registry, eventBus, logger } from './wise-core.js';
import { http } from './wise-util.js';

const permission = {
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
    async init(options = {}) {
        const permissionsUrl = options.permissionsUrl || '/admin/api/index/permissions';
        const menusUrl = options.menusUrl || '/admin/api/index/menus';

        try {
            const [permRes, menuRes] = await Promise.all([
                http.get(permissionsUrl),
                http.get(menusUrl),
            ]);

            this._permissions = (permRes?.data || permRes?.list || []);
            this._menus = (menuRes?.data || menuRes?.list || []);
            this._initialized = true;

            logger.info('permission', `已加载 ${this._permissions.length} 个权限`);
            eventBus.emit('ws:permission:loaded', {
                permissions: this._permissions,
                menus: this._menus,
            });

            return true;
        } catch (err) {
            logger.error('permission', '权限加载失败', err);
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
        return perms.some(p => this._permissions.includes(p));
    },

    /**
     * 检查是否有全部权限
     * @param {string[]} perms
     * @returns {boolean}
     */
    canAll(perms) {
        if (!this._initialized) return true;
        return perms.every(p => this._permissions.includes(p));
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
        document.querySelectorAll('[data-ws-permission]').forEach(el => {
            const perm = el.dataset.wsPermission;
            if (perm && !this.can(perm)) {
                el.style.display = 'none';
            }
        });

        // 也处理旧版 [data-permission] 属性（向后兼容）
        document.querySelectorAll('[data-permission]').forEach(el => {
            const perm = el.dataset.permission;
            if (perm && !this.can(perm)) {
                el.style.display = 'none';
            }
        });
    },

    /**
     * 渲染侧边栏菜单
     * @param {string} [containerId='sidebarMenu']
     */
    renderMenu(containerId = 'sidebarMenu') {
        const container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = '';
        this._menus.forEach(menu => {
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
        return menus.filter(menu => this._checkMenuPermission(menu)).map(menu => ({
            ...menu,
            children: menu.children ? this.filterMenus(menu.children) : [],
        }));
    },

    // ---- 私有 ----

    _checkMenuPermission(menu) {
        const perm = menu.permission || menu.perm || '';
        if (!perm) return true;
        return this.can(perm);
    },

    _renderMenuItem(menu) {
        const li = document.createElement('li');
        li.className = 'nav-item';
        if (menu.active) li.classList.add('menu-open');

        if (menu.children && menu.children.length > 0) {
            const hasVisible = menu.children.some(c => this._checkMenuPermission(c));
            if (!hasVisible) {
                li.style.display = 'none';
                return li;
            }
            li.innerHTML = `
                <a href="#" class="nav-link">
                    <i class="nav-icon ${menu.icon || 'bi-circle'}"></i>
                    <p>${menu.title || menu.name}<i class="nav-arrow bi bi-chevron-right"></i></p>
                </a>
            `;
            const ul = document.createElement('ul');
            ul.className = 'nav nav-treeview';
            menu.children.forEach(child => {
                if (this._checkMenuPermission(child)) {
                    ul.appendChild(this._renderMenuChild(child));
                }
            });
            li.appendChild(ul);
        } else {
            li.innerHTML = `
                <a href="${menu.url || '#'}" class="nav-link ${menu.active ? 'active' : ''}">
                    <i class="nav-icon ${menu.icon || 'bi-circle'}"></i>
                    <p>${menu.title || menu.name}</p>
                </a>
            `;
        }

        return li;
    },

    _renderMenuChild(child) {
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.innerHTML = `
            <a href="${child.url || '#'}" class="nav-link ${child.active ? 'active' : ''}">
                <i class="nav-icon ${child.icon || 'bi-circle'}"></i>
                <p>${child.title || child.name}</p>
            </a>
        `;
        return li;
    },
};

// 注册模块
registry.register('permission', permission);

export default permission;
export { permission };
