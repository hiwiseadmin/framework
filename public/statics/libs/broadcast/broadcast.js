/**
 * Broadcast — 跨标签页/跨 iframe 广播（ESM 模块）
 *
 * 位置：public/statics/libs/broadcast/broadcast.js
 * 加载：随 wise-bundle.js 打包（wise-bootstrap.js 顶部静态 import），页面模板不再单独引入。
 *       wise-framework.js 在模块顶层就取 window.Broadcast，原先依赖"classic defer 先于
 *       module 脚本执行"这一隐含时序；并入主包后由 import 图保证，不再看模板书写顺序。
 *
 * 兼容：模块求值末尾仍挂 window.Broadcast，页面控制器与插件等存量代码零改动。
 * 独立静态页（demo 等）：import Broadcast from '/statics/libs/broadcast/broadcast.js';
 *
 * 通信：localStorage（跨标签页）+ window.postMessage（同页内），支持心跳、持久化消息、
 *       通配符订阅。键名为 broadcast_<channelName>，同频道跨页共享。
 *
 * @module libs/broadcast
 */

/**
 * 高级广播系统
 * 使用localStorage实现跨标签页通信
 */
class Broadcast {
  /**
   * 构造函数
   * @param {Object} options 配置选项
   * @param {string} options.channelName 频道名称，默认为'default_channel'
   * @param {number} options.maxHistory 最大历史消息数，默认为50
   * @param {boolean} options.debug 是否开启调试模式，默认为false
   * @param {boolean} options.heartbeat 是否开启心跳检测，默认为false
   * @param {number} options.heartbeatInterval 心跳间隔(毫秒)，默认为30000
   * @param {string} options.prefix 存储键名前缀，默认为'broadcast_'
   */
  constructor(options = {}) {
    this.channelName = options.channelName || 'default_channel';
    this.storageKey = (options.prefix || 'broadcast_') + this.channelName;
    this.listeners = new Map();
    this.messageHistory = [];
    this.maxHistory = options.maxHistory || 50;
    this.debug = options.debug || false;
    this.heartbeatEnabled = options.heartbeat || false;
    this.heartbeatInterval = options.heartbeatInterval || 30000;
    this.heartbeatTimer = null;
    this.messageCounter = 0;
    this.instanceId = this.generateInstanceId();
    this.pendingMessages = new Set();
    
    // 绑定事件处理函数，以便后续可以正确移除
    this._boundHandleExternalMessage = this._handleExternalMessage.bind(this);
    this._boundHandleInternalMessage = this._handleInternalMessage.bind(this);
    
    this._init();
  }

  /**
   * 初始化
   * @private
   */
  _init() {
    // 监听storage事件（来自其他标签页）
    window.addEventListener('storage', this._boundHandleExternalMessage);
    
    // 监听当前页面的消息
    window.addEventListener('message', this._boundHandleInternalMessage);
    
    // 初始化心跳检测
    if (this.heartbeatEnabled) {
      this._startHeartbeat();
    }
    
    // 发送初始化消息，通知其他标签页本实例已启动
    this._sendSystemMessage('instance_started', {
      instanceId: this.instanceId,
      timestamp: Date.now()
    });
    
    this._log('info', `广播系统已初始化，频道: ${this.channelName}，实例ID: ${this.instanceId}`);
  }

  /**
   * 发送消息
   * @param {string} type 消息类型
   * @param {any} payload 消息内容
   * @param {Object} options 选项
   * @param {string} options.source 消息来源，默认为'unknown'
   * @param {string|Array} options.target 消息目标，默认为'all'
   * @param {number} options.ttl 消息生存时间(毫秒)，默认0(永久)
   * @param {boolean} options.persistent 是否持久化消息，默认false
   * @returns {string} 消息ID
   */
  send(type, payload, options = {}) {
    const messageId = this._generateMessageId();
    const timestamp = Date.now();
    
    const message = {
      type,
      payload,
      meta: {
        id: messageId,
        timestamp,
        source: options.source || 'unknown',
        target: options.target || 'all',
        ttl: options.ttl || 0,
        persistent: options.persistent || false,
        broadcastId: this.channelName,
        instanceId: this.instanceId,
        expiresAt: options.ttl ? timestamp + options.ttl : 0
      }
    };

    // 保存到历史记录
    this.messageHistory.push(message);
    if (this.messageHistory.length > this.maxHistory) {
      this.messageHistory.shift();
    }

    // 如果是持久化消息，单独存储
    if (options.persistent) {
      this._savePersistentMessage(message);
    }

    // 发送到其他标签页
    this._sendToOtherTabs(message);

    // 在当前页面触发
    this._triggerLocal(message);

    this._log('send', `发送消息: ${type}`, message);

    return messageId;
  }

  /**
   * 订阅消息
   * @param {string} type 消息类型，可以使用通配符'*'订阅所有消息
   * @param {Function} callback 回调函数
   * @param {Object} options 选项
   * @param {string} options.id 监听器ID，默认自动生成
   * @param {boolean} options.once 是否只监听一次，默认false
   * @returns {Function} 取消订阅的函数
   */
  subscribe(type, callback, options = {}) {
    if (typeof callback !== 'function') {
      throw new Error('回调函数必须是一个函数');
    }

    const listenerId = options.id || this._generateListenerId();
    
    if (!this.listeners.has(type)) {
      this.listeners.set(type, []);
    }
    
    const listener = {
      id: listenerId,
      callback,
      once: options.once || false,
      type: type
    };
    
    this.listeners.get(type).push(listener);
    
    this._log('subscribe', `订阅消息: ${type}, 监听器ID: ${listenerId}`);

    // 返回取消订阅函数
    return () => {
      this.unsubscribe(type, listenerId);
    };
  }

  /**
   * 一次性订阅
   * @param {string} type 消息类型
   * @param {Function} callback 回调函数
   * @returns {Function} 取消订阅的函数
   */
  once(type, callback) {
    return this.subscribe(type, callback, { once: true });
  }

  /**
   * 取消订阅
   * @param {string} type 消息类型
   * @param {string} listenerId 监听器ID
   */
  unsubscribe(type, listenerId) {
    if (!this.listeners.has(type)) {
      return;
    }
    
    const listeners = this.listeners.get(type);
    const index = listeners.findIndex(l => l.id === listenerId);
    
    if (index > -1) {
      listeners.splice(index, 1);
      this._log('unsubscribe', `取消订阅: ${type}, 监听器ID: ${listenerId}`);
    }
    
    // 如果没有监听器了，删除该类型
    if (listeners.length === 0) {
      this.listeners.delete(type);
    }
  }

  /**
   * 获取频道中所有活跃的实例
   * @returns {Promise<Array>} 活跃实例列表
   */
  async getActiveInstances() {
    // 发送发现请求
    const requestId = this.send('system_discover', {
      requestId: this._generateMessageId(),
      sender: this.instanceId,
      timestamp: Date.now()
    }, { ttl: 5000 });

    // 等待响应
    return new Promise((resolve) => {
      const instances = new Set([this.instanceId]);
      let timeout;

      const handleResponse = (payload, meta) => {
        if (payload.responseTo === requestId && payload.instanceId) {
          instances.add(payload.instanceId);
        }
      };

      // 订阅响应
      this.subscribe('system_discover_response', handleResponse, { once: false });

      // 设置超时
      timeout = setTimeout(() => {
        this.unsubscribe('system_discover_response', handleResponse);
        resolve(Array.from(instances));
      }, 1000);
    });
  }

  /**
   * 获取历史消息
   * @param {number} limit 限制条数
   * @returns {Array} 历史消息
   */
  getHistory(limit = 10) {
    return this.messageHistory.slice(-limit).reverse();
  }

  /**
   * 获取持久化消息
   * @returns {Array} 持久化消息
   */
  getPersistentMessages() {
    try {
      const key = `persistent_${this.storageKey}`;
      const data = localStorage.getItem(key);
      return data ? JSON.parse(data) : [];
    } catch (e) {
      this._log('error', '获取持久化消息失败:', e);
      return [];
    }
  }

  /**
   * 清除持久化消息
   */
  clearPersistentMessages() {
    try {
      const key = `persistent_${this.storageKey}`;
      localStorage.removeItem(key);
      this._log('info', '持久化消息已清除');
    } catch (e) {
      this._log('error', '清除持久化消息失败:', e);
    }
  }

  /**
   * 销毁实例，清理资源
   */
  destroy() {
    // 发送实例关闭消息
    this._sendSystemMessage('instance_stopped', {
      instanceId: this.instanceId,
      timestamp: Date.now()
    });

    // 清理事件监听
    window.removeEventListener('storage', this._boundHandleExternalMessage);
    window.removeEventListener('message', this._boundHandleInternalMessage);

    // 停止心跳
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }

    // 清理数据
    this.listeners.clear();
    this.messageHistory = [];
    this.pendingMessages.clear();

    this._log('info', '广播系统已销毁');
  }

  /**
   * 处理外部消息（来自其他标签页）
   * @private
   */
  _handleExternalMessage(event) {
    if (event.key === this.storageKey && event.newValue) {
      try {
        const message = JSON.parse(event.newValue);
        this._processMessage(message);
      } catch (e) {
        this._log('error', '消息解析失败:', e);
      }
    }
  }

  /**
   * 处理内部消息
   * @private
   */
  _handleInternalMessage(event) {
    if (event.data && 
        event.data.meta && 
        event.data.meta.broadcastId === this.channelName &&
        event.data.meta.instanceId !== this.instanceId) {
      this._processMessage(event.data);
    }
  }

  /**
   * 处理消息
   * @private
   */
  _processMessage(message) {
    // 检查消息是否过期
    if (message.meta.ttl > 0 && Date.now() > message.meta.expiresAt) {
      this._log('warn', `消息已过期: ${message.type}`);
      return;
    }

    // 检查是否已处理过
    if (this.pendingMessages.has(message.meta.id)) {
      return;
    }
    
    this.pendingMessages.add(message.meta.id);
    
    // 限制pending消息数量
    if (this.pendingMessages.size > 100) {
      const first = this.pendingMessages.values().next().value;
      this.pendingMessages.delete(first);
    }

    // 检查目标是否匹配
    if (!this._isMessageForMe(message)) {
      return;
    }

    // 处理系统消息
    if (message.type.startsWith('system_')) {
      this._handleSystemMessage(message);
    }

    // 触发特定类型监听器
    if (this.listeners.has(message.type)) {
      const listeners = [...this.listeners.get(message.type)];
      
      listeners.forEach(listener => {
        try {
          listener.callback(message.payload, message.meta);
          
          // 一次性监听器移除
          if (listener.once) {
            this.unsubscribe(message.type, listener.id);
          }
        } catch (e) {
          this._log('error', '监听器执行失败:', e);
        }
      });
    }

    // 触发通配符监听器
    if (this.listeners.has('*')) {
      this.listeners.get('*').forEach(listener => {
        try {
          listener.callback(message.payload, message.meta);
        } catch (e) {
          this._log('error', '通配符监听器执行失败:', e);
        }
      });
    }
  }

  /**
   * 处理系统消息
   * @private
   */
  _handleSystemMessage(message) {
    switch (message.type) {
      case 'system_discover':
        // 响应发现请求
        this.send('system_discover_response', {
          responseTo: message.payload.requestId,
          instanceId: this.instanceId,
          timestamp: Date.now()
        }, { target: message.meta.instanceId });
        break;
        
      case 'system_heartbeat':
        // 心跳响应
        this._log('debug', `收到心跳来自: ${message.meta.instanceId}`);
        break;
        
      case 'instance_started':
        this._log('info', `新实例启动: ${message.payload.instanceId}`);
        break;
        
      case 'instance_stopped':
        this._log('info', `实例关闭: ${message.payload.instanceId}`);
        break;
    }
  }

  /**
   * 检查消息是否针对当前实例
   * @private
   */
  _isMessageForMe(message) {
    const target = message.meta.target;
    
    if (target === 'all') {
      return true;
    }
    
    if (target === 'self' && message.meta.instanceId === this.instanceId) {
      return true;
    }
    
    if (Array.isArray(target) && target.includes(this.instanceId)) {
      return true;
    }
    
    return target === this.instanceId;
  }

  /**
   * 发送到其他标签页
   * @private
   */
  _sendToOtherTabs(message) {
    try {
      // 使用setTimeout确保异步，避免阻塞
      setTimeout(() => {
        localStorage.setItem(this.storageKey, JSON.stringify(message));
        
        // 立即移除，避免占用空间
        setTimeout(() => {
          localStorage.removeItem(this.storageKey);
        }, 100);
      }, 0);
    } catch (e) {
      this._log('error', 'localStorage写入失败:', e);
    }
  }

  /**
   * 在当前页面触发消息
   * @private
   */
  _triggerLocal(message) {
    // 使用setTimeout确保异步
    setTimeout(() => {
      window.postMessage(message, '*');
    }, 0);
  }

  /**
   * 发送系统消息
   * @private
   */
  _sendSystemMessage(type, payload) {
    const message = {
      type,
      payload,
      meta: {
        id: this._generateMessageId(),
        timestamp: Date.now(),
        source: 'system',
        target: 'all',
        broadcastId: this.channelName,
        instanceId: this.instanceId
      }
    };
    
    this._sendToOtherTabs(message);
    this._triggerLocal(message);
  }

  /**
   * 保存持久化消息
   * @private
   */
  _savePersistentMessage(message) {
    try {
      const key = `persistent_${this.storageKey}`;
      const messages = this.getPersistentMessages();
      messages.push({
        ...message,
        savedAt: Date.now()
      });
      
      // 限制持久化消息数量
      if (messages.length > 20) {
        messages.shift();
      }
      
      localStorage.setItem(key, JSON.stringify(messages));
    } catch (e) {
      this._log('error', '保存持久化消息失败:', e);
    }
  }

  /**
   * 开始心跳检测
   * @private
   */
  _startHeartbeat() {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
    }
    
    this.heartbeatTimer = setInterval(() => {
      this.send('system_heartbeat', {
        instanceId: this.instanceId,
        timestamp: Date.now()
      }, { ttl: this.heartbeatInterval * 2 });
    }, this.heartbeatInterval);
    
    this._log('info', '心跳检测已启动');
  }

  /**
   * 生成消息ID
   * @private
   */
  _generateMessageId() {
    return `${Date.now()}_${this.instanceId}_${++this.messageCounter}`;
  }

  /**
   * 生成实例ID
   * @private
   */
  generateInstanceId() {
    return `${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  /**
   * 生成监听器ID
   * @private
   */
  _generateListenerId() {
    return `listener_${Date.now()}_${Math.random().toString(36).substr(2, 6)}`;
  }

  /**
   * 日志记录
   * @private
   */
  _log(level, message, data = null) {
    if (!this.debug/*  && level === 'debug' */) {
      return;
    }
    
    const timestamp = new Date().toISOString();
    const logMessage = `[${timestamp}] [${level.toUpperCase()}] [${this.channelName}] ${message}`;
    
    switch (level) {
      case 'error':
        console.error(logMessage, data || '');
        break;
      case 'warn':
        console.warn(logMessage, data || '');
        break;
      case 'info':
        console.info(logMessage, data || '');
        break;
      case 'debug':
        console.debug(logMessage, data || '');
        break;
      default:
        console.log(logMessage, data || '');
    }
  }
}

// 兼容挂载点：存量代码以 window.Broadcast 使用（wise-framework、admin/index.js、profile.js），保留。
if (typeof window !== 'undefined') {
    window.Broadcast = Broadcast;
}

export default Broadcast;
export { Broadcast };
