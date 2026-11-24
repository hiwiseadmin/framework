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

/** sessionStorage 缓存键（会话级：关标签即失效，避免换账号残留） */
const CACHE_KEY = 'ws:permission:v1';
/** 缓存有效期（毫秒）：角色调整后最多 5 分钟自动重取 */
const CACHE_TTL = 5 * 60 * 1000;

const permission = {
    _permissions: [],
    _menus: [],
    _super: false,
    _initialized: false,
    _ready: false,
    _loading: null,

    /**
     * 确保权限已就绪（首次调用才发请求，之后走内存/sessionStorage）
     *
     * 数据源优先级：
     *   1. 服务端注入的 `window.__WS_AUTH__`（插件壳等可选用）
     *   2. sessionStorage 缓存（同标签内所有页面/iframe 共享，含 TTL）
     *   3. `GET /admin/index/permissions`（登录即可访问，无权限节点）
     *
     * @param {Object} [options]
     * @param {string} [options.permissionsUrl='/admin/index/permissions']
     * @param {boolean} [options.force=false] 忽略缓存强制重取
     * @returns {Promise<boolean>} 是否拿到权限集合
     */
    async ensure(options = {}) {
        if (this._ready) return true;
        if (this._loading) return this._loading;

        // ① 服务端注入（可选路径）
        const injected = this.snapshot();
        if (injected) {
            this._apply(injected);
            return true;
        }

        // ② sessionStorage 缓存
        if (!options.force) {
            const cached = this._readCache();
            if (cached) {
                this._apply(cached);
                return true;
            }
        }

        // ③ 接口拉取（并发调用共享同一个 Promise）
        this._loading = (async () => {
            try {
                const url = options.permissionsUrl || '/admin/index/permissions';
                const res  = await http.get(url, null, { autoError: false });
                const data = res && Array.isArray(res.perms) ? res : (res && res.data && Array.isArray(res.data.perms) ? res.data : null);

                if (!data) {
                    logger.warn('permission', '权限接口返回结构不符合预期，按钮按无权限处理');
                    this._ready = true;
                    return false;
                }

                this._apply(data);
                this._writeCache(data);

                return true;
            } catch (err) {
                logger.warn('permission', '权限加载失败，按钮按无权限处理', err && err.message);
                this._ready = true;
                return false;
            } finally {
                this._loading = null;
            }
        })();

        return this._loading;
    },

    /** 兼容旧入口：init() ≡ ensure() */
    async init(options = {}) {
        return this.ensure(options);
    },

    /**
     * 读取服务端注入的权限快照（window.__WS_AUTH__）
     *
     * @returns {{super: boolean, perms: string[]}|null} 未注入时返回 null
     */
    snapshot() {
        const injected = typeof window !== 'undefined' ? window.__WS_AUTH__ : null;
        if (!injected || !Array.isArray(injected.perms)) return null;

        return {
            super: !!injected.super,
            perms: injected.perms.map(String),
        };
    },

    /** 应用一份权限数据（注入 / 缓存 / 接口共用） */
    _apply(data) {
        this._super       = !!data.super;
        this._permissions = Array.isArray(data.perms) ? data.perms.map(String) : [];
        this._initialized = true;
        this._ready       = true;

        logger.info('permission', `已就绪：${this._super ? '超级管理员' : this._permissions.length + ' 个权限'}`);
        eventBus.emit('ws:permission:loaded', {
            super: this._super,
            permissions: this._permissions,
        });
    },

    /** 读 sessionStorage 缓存（带 TTL 校验） */
    _readCache() {
        try {
            const raw = sessionStorage.getItem(CACHE_KEY);
            if (!raw) return null;

            const parsed = JSON.parse(raw);
            if (!parsed || !Array.isArray(parsed.perms)) return null;
            if (!parsed.ts || Date.now() - parsed.ts > CACHE_TTL) {
                sessionStorage.removeItem(CACHE_KEY);
                return null;
            }

            return parsed;
        } catch (err) {
            return null;
        }
    },

    /** 写 sessionStorage 缓存 */
    _writeCache(data) {
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({
                uid: data.uid || 0,
                super: !!data.super,
                perms: Array.isArray(data.perms) ? data.perms : [],
                version: data.version || 0,
                ts: Date.now(),
            }));
        } catch (err) {
            // 隐私模式/配额满：忽略，下次仍走接口
        }
    },

    /** 清缓存（登出、角色变更后由调用方触发） */
    clearCache() {
        try {
            sessionStorage.removeItem(CACHE_KEY);
        } catch (err) {
            // 忽略
        }

        this._permissions = [];
        this._super = false;
        this._ready = false;
        this._initialized = false;
    },

    /**
     * 检查是否有某个权限
     *
     * 未就绪时返回 **false**（fail-closed）：历史上的 fail-open 会让无权限账号
     * 看到按钮、点了才 403，属于"能看见但用不了"的坏交互。
     * 页面控制器执行前已由 wise-bootstrap `await ensure()`，正常不会走到未就绪分支。
     *
     * @param {string} perm - 权限码（与 auth_permissions.ident 一致，如 admin.login_log.export）
     * @returns {boolean}
     */
    can(perm) {
        if (!perm) return true;
        if (!this._ready) return false;
        if (this._super) return true;

        return this._permissions.includes('*') || this._permissions.includes(perm);
    },

    /**
     * 检查是否有任意一个权限
     * @param {string[]} perms
     * @returns {boolean}
     */
    canAny(perms) {
        if (!Array.isArray(perms) || perms.length === 0) return true;
        return perms.some(p => this.can(p));
    },

    /**
     * 检查是否有全部权限
     * @param {string[]} perms
     * @returns {boolean}
     */
    canAll(perms) {
        if (!Array.isArray(perms) || perms.length === 0) return true;
        return perms.every(p => this.can(p));
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
     *
     * 权限尚未就绪（ensure 未完成）时直接返回：此时 can() 恒为 false，
     * 若照常执行会把所有受控元素一次性隐藏。正常情况下 wise-bootstrap
     * 已在页面控制器之前 await ensure()，这里是防御性守卫。
     */
    autoControl() {
        if (!this._ready) return;

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
