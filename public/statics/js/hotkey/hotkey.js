(function (global, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        global.HotKey = factory();
    }
}(typeof window !== 'undefined' ? window : this, function () {
    "use strict";

    class HotKey {
        /**
         * 
         * @param {string} namespace 命名空间，用于隔离不同框架的快捷键
         * @param {object} options 选项
         * @param {boolean} options.crossPage 是否将本页注册的快捷键对外（跨页面/跨 iframe）共享，默认开启。
         */
        constructor(namespace = 'default', options = {}) {
            this.namespace = namespace;
            this.subscribers = {};
            this.hotkeys = {};
            // 远程注册表（其他框架注册的快捷键，跨 namespace 汇总），
            // 用于「任意框架焦点都能触发全局快捷键」：
            //   本框架按键 → 本地未命中 → 远程命中 → 广播 remote-trigger → 注册者执行
            this.remoteHotkeys = {};
            this.enabled = true;
            this.pageId = this._generatePageId();
            // 是否将本页注册的快捷键对外（跨页面/跨 iframe）共享，默认开启。
            // 该开关只控制「出站」：设为 false 时，本页注册的快捷键不会写入全局注册中心，
            // 因此不会被其它页面触发（实现「仅当前页面生效」）。
            // 注意：开关不影响「入站」——本页仍会监听并接收其它页面注册的全局快捷键，
            // 也仍会响应同 namespace 的跨页面广播。
            this.enableCrossPage = options.crossPage !== false;
            
            // 监听键盘事件
            document.addEventListener('keydown', this._handleKeyDown.bind(this));
            
            // 监听来自其他页面的热键事件（按 namespace 隔离的广播），始终开启（入站）
            window.addEventListener('storage', this._handleStorageEvent.bind(this));
            
            // 全局注册中心：所有框架的所有 HotKey 实例共同监听，
            // 实现「子页面注册的快捷键全局可用」——iframe 晚加载也能同步已有注册。
            // 入站监听始终开启：本页按其它页面的全局快捷键触发。
            window.addEventListener('storage', this._handleGlobalStorage.bind(this));
            this._loadGlobalRegistry();
            
            // 页面卸载时清理本页注册的全局快捷键，避免残留
            window.addEventListener('pagehide', this._handlePageHide.bind(this));
            
            // 初始化时检查是否有已存储的热键状态
            this._loadState();
        }
        
        /**
         * 注册一个快捷键
         * @param {string} hotkey - 快捷键组合，例如 'ctrl+s'、'alt+enter'、'a'
         * @param {Function} callback - 命中时执行的回调，签名为 (hotkey: string, event: Event) => void
         * @param {string} [description=''] - 快捷键的可读描述，用于展示/调试
         * @returns {string} 该快捷键的唯一 id，可用于后续 unregister
         */
        // 注册热键
        register(hotkey, callback, description = '') {
            const id = this._generateId();
            this.hotkeys[id] = {
                hotkey,
                callback,
                description,
                id
            };
            
            // 通知其他页面/iframe
            this._broadcast({
                type: 'hotkey-registered',
                namespace: this.namespace,
                pageId: this.pageId,
                hotkey: {
                    id,
                    hotkey,
                    description
                }
            });
            
            // 写入全局注册中心（所有框架可见），仅当开启跨页面时
            if (this.enableCrossPage) {
                this._addToGlobalRegistry(hotkey, id, description);
            }
            
            return id;
        }
        
        /**
         * 取消注册指定快捷键
         * @param {string} id - register 返回的唯一 id
         * @returns {boolean} 取消成功返回 true；id 不存在返回 false
         */
        // 取消注册热键
        unregister(id) {
            if (this.hotkeys[id]) {
                delete this.hotkeys[id];
                
                // 通知其他页面/iframe
                this._broadcast({
                    type: 'hotkey-unregistered',
                    namespace: this.namespace,
                    pageId: this.pageId,
                    id
                });
                
                // 从全局注册中心移除，仅当开启跨页面时
                if (this.enableCrossPage) {
                    this._removeFromGlobalRegistry(id);
                }
                
                return true;
            }
            return false;
        }
        
        /**
         * 订阅热键相关事件
         * @param {string} eventType - 事件类型，如 'hotkey-triggered'、'hotkey-registered'、'hotkey-unregistered'、'hotkeys-enabled-changed'
         * @param {Function} callback - 事件回调，签名为 (data: object) => void
         * @returns {Function} 取消订阅函数，调用即可移除该回调
         */
        // 订阅热键事件
        subscribe(eventType, callback) {
            if (!this.subscribers[eventType]) {
                this.subscribers[eventType] = [];
            }
            this.subscribers[eventType].push(callback);
            
            return () => {
                this.subscribers[eventType] = this.subscribers[eventType].filter(cb => cb !== callback);
            };
        }
        
        /**
         * 发布（触发）一个事件，通知所有订阅者
         * @param {string} eventType - 事件类型
         * @param {*} data - 传递给订阅者的事件数据
         * @returns {void}
         */
        // 发布事件
        publish(eventType, data) {
            if (this.subscribers[eventType]) {
                this.subscribers[eventType].forEach(callback => {
                    try {
                        callback(data);
                    } catch (error) {
                        console.error('Error in hotkey subscriber:', error);
                    }
                });
            }
        }
        
        /**
         * 启用或禁用当前命名空间下所有热键
         * @param {boolean} enabled - true 启用，false 禁用
         * @returns {void}
         */
        // 启用/禁用热键
        setEnabled(enabled) {
            this.enabled = enabled;
            
            // 保存状态到 localStorage
            this._saveState();
            
            // 通知其他页面/iframe
            this._broadcast({
                type: 'hotkeys-enabled-changed',
                namespace: this.namespace,
                pageId: this.pageId,
                enabled
            });
        }
        
        // 处理键盘事件
        _handleKeyDown(event) {
            if (!this.enabled) return;

            // 无 Ctrl/Alt/Cmd 的快捷键（裸键或仅 Shift）会输入可见字符，
            // 在输入框 / 文本域 / 富文本中必须放行，否则会劫持正常输入（如 "/" 打不出来）。
            // 注意：必须在 preventDefault 之前拦截 —— 一旦阻止默认行为，字符已无法补回。
            if (!event.ctrlKey && !event.altKey && !event.metaKey && this._isEditableTarget(event.target)) return;

            const key = (event.key || '').toLowerCase();
            const ctrl = event.ctrlKey;
            const alt = event.altKey;
            const shift = event.shiftKey;
            const meta = event.metaKey;
            // 构建热键标识
            const hotkeyParts = [];
            if (ctrl) hotkeyParts.push('ctrl');
            if (alt) hotkeyParts.push('alt');
            if (shift) hotkeyParts.push('shift');
            if (meta) hotkeyParts.push('meta');
            hotkeyParts.push(key);
            
            const hotkey = hotkeyParts.join('+');
            
            // 查找匹配的热键（本地注册表）
            let matched = false;
            Object.values(this.hotkeys).forEach(hotkeyConfig => {
                if (hotkeyConfig.hotkey === hotkey) {
                    matched = true;
                    event.preventDefault();
                    event.stopPropagation();
                    
                    // 执行回调
                    hotkeyConfig.callback(hotkey, event);
                    
                    // 发布事件
                    this.publish('hotkey-triggered', {
                        hotkey,
                        description: hotkeyConfig.description,
                        namespace: this.namespace
                    });
                    
                    // 通知其他页面/iframe
                    this._broadcast({
                        type: 'hotkey-triggered',
                        namespace: this.namespace,
                        pageId: this.pageId,
                        hotkey,
                        description: hotkeyConfig.description
                    });
                }
            });
            
            // 本地未命中 → 尝试远程注册表（其他框架注册的全局快捷键）
            // 仅当该按键确属远程注册且本事件尚未被转发时，广播 remote-trigger，
            // 由注册者框架查本地表执行。event.__hotkeyForwarded 保证同一 keydown
            // 事件只被本框架内的多个 HotKey 实例转发一次。
            if (!matched && this.remoteHotkeys[hotkey] && this.remoteHotkeys[hotkey].length && !event.__hotkeyForwarded) {
                event.__hotkeyForwarded = true;
                event.preventDefault();
                event.stopPropagation();
                try {
                    localStorage.setItem(HotKey.GLOBAL_EVENT_KEY, JSON.stringify({
                        type: 'hotkey-remote-trigger',
                        hotkey,
                        pageId: this.pageId,
                        ts: Date.now()
                    }));
                    // 立即移除，避免 key 长期占用；storage 事件在写入瞬间已触发
                    setTimeout(() => {
                        localStorage.removeItem(HotKey.GLOBAL_EVENT_KEY);
                    }, 100);
                } catch (error) {
                    console.error('Error forwarding hotkey:', error);
                }
            }
        }
        
        /**
         * 判断事件目标是否为可编辑元素（输入框 / 文本域 / 下拉 / 富文本）
         * 用于避免裸键（或仅 Shift 组合）快捷键劫持正常字符输入。
         * @param {EventTarget|null} el
         * @returns {boolean}
         */
        _isEditableTarget(el) {
            if (!el) return false;
            const tag = (el.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
            return !!el.isContentEditable;
        }

        // 处理 storage 事件（跨页面/iframe 通信）
        _handleStorageEvent(event) {
            if (event.key !== `hotkey-manager-${this.namespace}`) return;
            
            try {
                const data = JSON.parse(event.newValue);
                if (data && data.namespace === this.namespace) {
                    this._handleBroadcastMessage(data);
                }
            } catch (error) {
                console.error('Error parsing hotkey message:', error);
            }
        }
        
        // 处理广播消息
        _handleBroadcastMessage(data) {
            switch (data.type) {
                case 'hotkey-registered':
                    this.publish('hotkey-registered', {...data.hotkey, pageId: data.pageId });
                    break;
                case 'hotkey-unregistered':
                    this.publish('hotkey-unregistered', { id: data.id, pageId: data.pageId });
                    break;
                case 'hotkey-triggered':
                    this.publish('hotkey-triggered', {
                        hotkey: data.hotkey,
                        description: data.description,
                        namespace: data.namespace,
                        pageId: data.pageId,
                        fromOtherPage: true
                    });
                    break;
                case 'hotkeys-enabled-changed':
                    this.enabled = data.enabled;
                    this.publish('hotkeys-enabled-changed', { enabled: data.enabled, pageId: data.pageId });
                    break;
            }
        }
        
        // ============================================================
        // 全局注册中心：跨框架（顶层 + 所有 iframe）快捷键注册表
        // ============================================================

        // 读取全局注册中心（iframe 晚加载时同步已有注册；storage 事件触发时重同步）
        _loadGlobalRegistry() {
            try {
                const list = this._readGlobalRegistry();
                const remote = {};
                for (const item of list) {
                    if (item.pageId === this.pageId) continue; // 本页注册已在本地表
                    if (!remote[item.hotkey]) remote[item.hotkey] = [];
                    remote[item.hotkey].push(item);
                }
                this.remoteHotkeys = remote;
            } catch (error) {
                console.error('Error loading global hotkey registry:', error);
            }
        }

        // 处理全局注册中心 / 远程触发事件
        _handleGlobalStorage(event) {
            if (event.key === HotKey.GLOBAL_REGISTRY_KEY && event.newValue) {
                this._loadGlobalRegistry();
            } else if (event.key === HotKey.GLOBAL_EVENT_KEY && event.newValue) {
                try {
                    const msg = JSON.parse(event.newValue);
                    if (msg.type === 'hotkey-remote-trigger' && msg.pageId !== this.pageId) {
                        this._handleRemoteTrigger(msg);
                    }
                } catch (error) {
                    console.error('Error parsing global hotkey event:', error);
                }
            }
        }

        // 远程触发：查本地注册表执行（注册者框架收到转发后）
        _handleRemoteTrigger(msg) {
            const found = Object.values(this.hotkeys).filter(h => h.hotkey === msg.hotkey);
            if (!found.length) return;
            found.forEach(hotkeyConfig => {
                try {
                    hotkeyConfig.callback(msg.hotkey, { remote: true });
                    this.publish('hotkey-triggered', {
                        hotkey: msg.hotkey,
                        description: hotkeyConfig.description,
                        namespace: this.namespace,
                        fromRemote: true,
                        fromPageId: msg.pageId
                    });
                } catch (error) {
                    console.error('Error in remote hotkey callback:', error);
                }
            });
        }

        // 写入全局注册中心
        _addToGlobalRegistry(hotkey, id, description) {
            try {
                const list = this._readGlobalRegistry();
                list.push({
                    namespace: this.namespace,
                    hotkey,
                    id,
                    description,
                    pageId: this.pageId,
                    ts: Date.now()
                });
                localStorage.setItem(HotKey.GLOBAL_REGISTRY_KEY, JSON.stringify(list));
            } catch (error) {
                console.error('Error adding to global hotkey registry:', error);
            }
        }

        // 从全局注册中心移除（本页的指定 id）
        _removeFromGlobalRegistry(id) {
            try {
                const list = this._readGlobalRegistry().filter(item => !(item.pageId === this.pageId && item.id === id));
                localStorage.setItem(HotKey.GLOBAL_REGISTRY_KEY, JSON.stringify(list));
            } catch (error) {
                console.error('Error removing from global hotkey registry:', error);
            }
        }

        // 读取全局注册中心原始列表
        _readGlobalRegistry() {
            try {
                const raw = localStorage.getItem(HotKey.GLOBAL_REGISTRY_KEY);
                const list = raw ? JSON.parse(raw) : [];
                return Array.isArray(list) ? list : [];
            } catch (error) {
                return [];
            }
        }

        // 页面卸载：清理本页注册的全局快捷键
        _handlePageHide() {
            try {
                const list = this._readGlobalRegistry().filter(item => item.pageId !== this.pageId);
                localStorage.setItem(HotKey.GLOBAL_REGISTRY_KEY, JSON.stringify(list));
            } catch (error) {
                console.error('Error cleaning global hotkey registry:', error);
            }
        }

        // 广播消息到其他页面/iframe
        _broadcast(message) {
            try {
                localStorage.setItem(`hotkey-manager-${this.namespace}`, JSON.stringify({
                    ...message,
                    timestamp: Date.now()
                }));
            } catch (error) {
                console.error('Error broadcasting hotkey message:', error);
            }
        }
        
        // 保存状态到 localStorage
        _saveState() {
            try {
                localStorage.setItem(`hotkey-state-${this.namespace}`, JSON.stringify({
                    enabled: this.enabled
                }));
            } catch (error) {
                console.error('Error saving hotkey state:', error);
            }
        }
        
        // 从 localStorage 加载状态
        _loadState() {
            try {
                const state = localStorage.getItem(`hotkey-state-${this.namespace}`);
                if (state) {
                    const parsed = JSON.parse(state);
                    this.enabled = parsed.enabled;
                }
            } catch (error) {
                console.error('Error loading hotkey state:', error);
            }
        }
        
        // 生成页面唯一ID
        _generatePageId() {
            return `page-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        }
        
        // 生成唯一 ID
        _generateId() {
            return `hotkey-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        }
        
        /**
         * 获取当前命名空间下已注册的所有热键配置
         * @returns {Array<{id:string, hotkey:string, callback:Function, description:string}>} 热键配置数组
         */
        // 获取所有热键（全局或当前命名空间）
        getAllHotkeys() {
            return Object.values(this.hotkeys);
        }
    }

    // 全局注册中心常量：所有框架的所有 HotKey 实例共同监听，实现跨 iframe 全局快捷键
    HotKey.GLOBAL_REGISTRY_KEY = 'hotkey-global-registry';
    HotKey.GLOBAL_EVENT_KEY = 'hotkey-global-event';

    return HotKey;
}));
