/**
 * MessageManager — 顶部导航消息下拉组件
 *
 * 数据接口统一对接 SystemMessage 控制器（基于 wise-notification 包）：
 *   - 未读数：/admin/system_message/getStats  → data.unread
 *   - 消息列表：/admin/system_message/getList  → data.messages[]（item.message.*）
 *
 * 兼容历史调用方（字段结构跟随 SystemMessageService 返回格式）。
 */
class MessageManager {
    constructor(options = {}) {
        this.options = Object.assign({
            refreshInterval: 30000,
            maxRetries: 3,
            retryDelay: 5000
        }, options);

        this.retryCount = 0;
        this.init();
    }

    init() {
        this.loadUnreadCount();
        this.loadMessages();
        this.setupAutoRefresh();
        this.setupEventListeners();
        this.setupWebSocket(); // 可选：WebSocket支持
    }

    // 加载未读数量（带重试机制）
    async loadUnreadCount() {
        try {
            const response = await this.fetchWithTimeout('/admin/system_message/getStats');
            const data = await response.json();

            if (data.code === 0) {
                this.updateBadge((data.data && data.data.unread) || 0);
                this.retryCount = 0; // 重置重试计数
            } else {
                this.handleError('加载未读消息数量失败');
            }
        } catch (error) {
            this.handleError('加载未读消息数量失败:', error);
        }
    }

    // 加载消息列表
    async loadMessages() {
        try {
            const response = await this.fetchWithTimeout('/admin/system_message/getList?limit=5');
            const data = await response.json();

            if (data.code === 0) {
                this.renderMessages(data.data && data.data.messages);
                this.retryCount = 0;
            } else {
                this.handleError('加载消息列表失败');
            }
        } catch (error) {
            this.handleError('加载消息列表失败:', error);
        }
    }

    // 带超时的fetch
    fetchWithTimeout(url, options = {}) {
        const { timeout = 5000 } = options;

        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);

        return fetch(url, {
            ...options,
            signal: controller.signal
        }).finally(() => clearTimeout(id));
    }

    // 错误处理
    handleError(message, error) {
        console.error(message, error);

        this.retryCount++;
        if (this.retryCount <= this.options.maxRetries) {
            setTimeout(() => {
                this.loadUnreadCount();
                this.loadMessages();
            }, this.options.retryDelay);
        } else {
            this.showErrorMessage('消息系统暂时不可用');
        }
    }

    // 显示错误消息
    showErrorMessage(message) {
        // 可以使用Toast或其他UI组件显示错误
        console.warn(message);
    }

    // 更新徽章
    updateBadge(count) {
        const badge = document.getElementById('message-badge');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline' : 'none';
        }
    }

    // 渲染消息列表
    renderMessages(messages) {
        const container = document.getElementById('message-items');

        if (!messages || messages.length === 0) {
            container.innerHTML = `
                <li>
                    <a class="dropdown-item text-center text-muted" href="#">
                        <i class="bi bi-inbox"></i> 暂无消息
                    </a>
                </li>
            `;
            return;
        }

        container.innerHTML = messages.map(item => {
            // SystemMessageService 返回结构：item.message.* + item.status(1=未读/2=已读)
            const message = item.message || {};
            const status = item.status === 1 ? 'unread' : 'read';
            return `
            <li>
                <a class="dropdown-item message-item ${status}"
                   href="#" data-id="${message.id || item.id}" data-url="${message.action_url || '#'}">
                    <div class="d-flex w-100 justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center mb-1">
                                <i class="${this.getMessageIcon(message.message_type)} ${this.getMessageColor(message.message_type)} me-2"></i>
                                <h6 class="mb-0 flex-grow-1">${this.escapeHtml(message.title)}</h6>
                                ${(message.priority || 1) >= 3 ? '<span class="badge bg-danger ms-1">!</span>' : ''}
                            </div>
                            <p class="mb-1 small text-muted">${this.escapeHtml(message.content)}</p>
                            ${message.action_text ? `
                                <small class="text-primary">${message.action_text}</small>
                            ` : ''}
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block">${this.formatTime(message.create_time)}</small>
                            ${status === 'unread' ? '<span class="badge bg-primary badge-dot"></span>' : ''}
                        </div>
                    </div>
                </a>
            </li>
        `;
        }).join('');
    }

    // HTML转义
    escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // 获取消息颜色
    getMessageColor(type) {
        const colors = {
            'task_assign': 'text-primary',
            'system_alert': 'text-warning',
            'default': 'text-info'
        };
        return colors[type] || colors.default;
    }

    // 获取消息图标
    getMessageIcon(type) {
        const icons = {
            'task_assign': 'bi bi-list-check',
            'system_alert': 'bi bi-exclamation-triangle',
            'default': 'bi bi-envelope'
        };
        return icons[type] || icons.default;
    }

    // 格式化时间
    formatTime(value) {
        if (!value) return '';
        // 去掉末尾秒的冗余展示，兼容 "Y-m-d H:i:s"
        return String(value).substring(0, 16);
    }

    // WebSocket支持（可选）
    setupWebSocket() {
        if (typeof io !== 'undefined') {
            try {
                const socket = io();
                socket.on('new_message', (data) => {
                    this.loadUnreadCount();
                    this.showNewMessageNotification(data);
                });
            } catch (error) {
                console.warn('WebSocket初始化失败，使用轮询模式:', error);
            }
        }
    }

    // 显示新消息通知
    showNewMessageNotification(message) {
        // 使用浏览器的Notification API
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(message.title || message.message?.title, {
                body: message.content || message.message?.content,
                icon: '/favicon.ico'
            });
        }

        // 或者使用Toast通知
        this.showToast(message);
    }

    // 显示Toast通知
    showToast(message) {
        // 实现Toast通知逻辑
        console.log('新消息:', message);
    }
}

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    // 请求通知权限
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    new MessageManager();
});
