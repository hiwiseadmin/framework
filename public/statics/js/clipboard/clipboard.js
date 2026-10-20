/**
 * 剪贴板操作库
 * 版本: 1.0.0
 * 作者: wiseAdmin
 */

(function (global, factory) {
  if (typeof define === 'function' && define.amd) {
    // AMD 支持
    define([], factory);
  } else if (typeof exports === 'object' && typeof module !== 'undefined') {
    // CommonJS 支持
    module.exports = factory();
  } else {
    // 浏览器全局变量
    global.Clipboard = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /**
   * 事件管理器类
   * @class EventEmitter
   * @private
   */
  class EventEmitter {
    constructor() {
      /** 
       * @type {Object.<string, Function[]>} 
       * 事件监听器集合 
       */
      this.events = {};
    }

    /**
     * 绑定事件监听器
     * @param {string} event - 事件名称
     * @param {Function} callback - 回调函数
     * @param {*} [ctx] - 上下文对象
     * @returns {EventEmitter} 当前实例
     */
    on(event, callback, ctx) {
        if (!this.events[event]) {
            this.events[event] = [];
        }
        this.events[event].push({ fn: callback, ctx: ctx });
        return this;
    }

    /**
     * 绑定一次性事件监听器
     * @param {string} event - 事件名称
     * @param {Function} callback - 回调函数
     * @param {*} [ctx] - 上下文对象
     * @returns {EventEmitter} 当前实例
     */
    once(event, callback, ctx) {
        const self = this;
        
        function listener() {
            self.off(event, listener);
            callback.apply(ctx, arguments);
        }
        
        listener._ = callback;
        return this.on(event, listener, ctx);
    }

    /**
     * 触发事件
     * @param {string} event - 事件名称
     * @param {...*} [args] - 传递给回调函数的参数
     * @returns {EventEmitter} 当前实例
     */
    emit(event, ...args) {
      const evtArr = (this.events[event] || []).slice();
      
      for (let i = 0; i < evtArr.length; i++) {
        evtArr[i].fn.apply(evtArr[i].ctx, args);
      }
      
      return this;
    }

    /**
     * 移除事件监听器
     * @param {string} event - 事件名称
     * @param {Function} [callback] - 要移除的回调函数（不传则移除所有）
     * @returns {EventEmitter} 当前实例
     */
    off(event, callback) {
      const evts = this.events[event];
      
      if (!evts) return this;
      
      if (!callback) {
        delete this.events[event];
        return this;
      }
      
      const liveEvents = [];
      for (let i = 0; i < evts.length; i++) {
        if (evts[i].fn !== callback && evts[i].fn._ !== callback) {
          liveEvents.push(evts[i]);
        }
      }
      
      if (liveEvents.length) {
        this.events[event] = liveEvents;
      } else {
        delete this.events[event];
      }
      
      return this;
    }
  }

  /**
   * 优化的剪贴板操作主类
   * @class Clipboard
   * @extends EventEmitter
   * 
   * @example
   * // 基础使用
   * const clipboard = new Clipboard('.copy-btn', {
   *   text: '要复制的文本'
   * });
   * 
   * clipboard.on('success', (e) => {
   *   console.log('复制成功:', e.text);
   * });
   * 
   * clipboard.on('error', (e) => {
   *   console.error('复制失败');
   * });
   */
  class Clipboard extends EventEmitter {
    /**
     * 创建剪贴板实例
     * @constructor
     * @param {string|HTMLElement|NodeList|Array.<HTMLElement>} trigger - 触发器元素或选择器
     * @param {Object} [options] - 配置选项
     * @param {string|Function} [options.text] - 要复制的文本或返回文本的函数
     * @param {string|HTMLElement|Function} [options.target] - 目标元素或选择器
     * @param {string} [options.action='copy'] - 操作类型: 'copy' 或 'cut'
     * @param {HTMLElement} [options.container=document.body] - 隐藏元素的容器
     * @param {boolean} [options.feedback=true] - 是否显示复制成功/失败反馈
     * @param {boolean} [options.autoDestroy=true] - 是否在复制后自动销毁临时元素
     */
    constructor(trigger, options = {}) {
        super();
        
        /**
         * 触发器元素
         * @type {HTMLElement|NodeList|Array.<HTMLElement>}
         * @private
         */
        this.trigger = trigger;
        
        /**
         * 配置选项
         * @type {Object}
         * @private
         */
        this.options = {
            container: document.body,
            text: null,
            target: null,
            action: 'copy',
            feedback: false,
            autoDestroy: true,
            ...options
        };
        
        /**
         * 事件监听器列表
         * @type {Array.<Object>}
         * @private
         */
        this.listeners = [];
        
        this.init();
    }

    /**
     * 初始化方法
     * @private
     */
    init() {
        const { trigger } = this;
        
        if (typeof trigger === 'string') {
            // 选择器字符串
            const elements = document.querySelectorAll(trigger);
            elements.forEach(element => this.bindEvents(element));
        } else if (trigger instanceof HTMLElement) {
            // 单个元素
            this.bindEvents(trigger);
        } else if (trigger instanceof NodeList || Array.isArray(trigger)) {
            // 元素列表
            trigger.forEach(element => {
            if (element instanceof HTMLElement) {
                this.bindEvents(element);
            }
            });
        }
    }

    /**
     * 绑定事件到元素
     * @param {HTMLElement} element - 要绑定事件的元素
     * @private
     */
    bindEvents(element) {
        const clickHandler = (e) => {
            e.preventDefault();
            this.handleClick(element);
        };
        
        element.addEventListener('click', clickHandler);
        
        // 添加键盘事件支持（无障碍）
        const keydownHandler = (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.handleClick(element);
            }
        };
        
        element.addEventListener('keydown', keydownHandler);
        
        // 创建复制按钮
        if(element.innerHTML.trim() == '' && (element.dataset.clipboardFeedback == 'true' || this.options.feedback)){
            element.classList.add('btn', 'btn-secondary', 'btn-sm');
            element.innerHTML = '<i class="bi bi-clipboard"></i>';
        }
        // 存储监听器以便后续移除
        this.listeners.push({
            element,
            click: clickHandler,
            keydown: keydownHandler
        });
    }

    /**
     * 处理点击事件
     * @param {HTMLElement} trigger - 触发点击的元素
     * @private
     */
    async handleClick(trigger) {
      try {
        const action = this.getAction(trigger);
        const text = await this.getText(trigger);

        if (action === 'copy' && text !== null) {
            const success = await this.copy(text);
            this.emit(success ? 'success' : 'error', {
                action,
                text,
                trigger,
                success,
                timestamp: Date.now()
            });
            if (success) {
                this.showSuccess(trigger);
            } else {
                this.showError(trigger);
            }
        } else if (action === 'cut' && text !== null) {
            const target = this.getTarget(trigger);
            if (target) {
                const success = await this.cut(target);
                this.emit(success ? 'success' : 'error', {
                    action,
                    text: target.value || target.textContent,
                    trigger,
                    target,
                    success,
                    timestamp: Date.now()
                });
                if (success) {
                    this.showSuccess(trigger);
                } else {
                    this.showError(trigger);
                }
            } else {
                this.emit('error', {
                    action,
                    trigger,
                    error: new Error('Cut target not found'),
                    timestamp: Date.now()
                });
                this.showError(trigger);
            }
        }else{
            this.emit('error', {
                action,
                trigger,
                error: new Error('Text is null'),
                timestamp: Date.now()
            });
            this.showError(trigger);
        }
      } catch (error) {
        this.emit('error', {
            trigger,
            error,
            timestamp: Date.now()
        });
        this.showError(trigger);
      }
    }

    /**
     * 获取操作类型
     * @param {HTMLElement} trigger - 触发元素
     * @returns {string} 操作类型: 'copy' 或 'cut'
     * @private
     */
    getAction(trigger) {
      const { action } = this.options;

      if (action && typeof action === 'function') {
        return action(trigger);
      }
      
      // 从 data 属性获取
      const actionAttr = trigger.getAttribute('data-clipboard-action');
      return actionAttr || (['cut', 'copy'].includes(action) ? action : 'copy');
    }

    /**
     * 获取要复制的文本
     * @param {HTMLElement} trigger - 触发元素
     * @returns {Promise<string>} 要复制的文本
     * @private
     */
    async getText(trigger) {
      const { text } = this.options;
      
      // 如果配置了 text 选项
      if (text !== undefined && text !== null) {
        return typeof text === 'function'
          ? await Promise.resolve(text(trigger))
          : text;
      }
      
      // 从 data 属性获取
      const textAttr = trigger.getAttribute('data-clipboard-text');
      if (textAttr) return textAttr;
      
      // 从目标元素获取
      const target = this.getTarget(trigger);
      if (target) {
        return this.getTargetText(target);
      }else{
        return null;
      }
      
      // 从触发元素自身获取
    //   return trigger.textContent || trigger.innerText || '';
    }

    /**
     * 获取目标元素
     * @param {HTMLElement} trigger - 触发元素
     * @returns {HTMLElement|null} 目标元素
     * @private
     */
    getTarget(trigger) {
      const { target } = this.options;
      
      if (target) {
        const result = typeof target === 'function'
          ? target(trigger)
          : target;
        
        if (typeof result === 'string') {
          return document.querySelector(result);
        }
        return result;
      }
      
      // 从 data 属性获取
      const targetAttr = trigger.getAttribute('data-clipboard-target');
      if (targetAttr) {
        return document.querySelector(targetAttr);
      }
      
      return null;
    }

    /**
     * 从目标元素获取文本
     * @param {HTMLElement} target - 目标元素
     * @returns {string} 文本内容
     * @private
     */
    getTargetText(target) {
      if (target instanceof HTMLInputElement || 
          target instanceof HTMLTextAreaElement) {
        return target.value;
      }
      
      if (target.isContentEditable || target.contentEditable === 'true') {
        return target.textContent;
      }
      
      return target.textContent || target.innerText || '';
    }

    /**
     * 复制文本到剪贴板
     * @param {string} text - 要复制的文本
     * @returns {Promise<boolean>} 是否成功
     * @public
     */
    async copy(text) {
      if (!text && text !== 0) {
        console.warn('Clipboard: No text to copy');
        return false;
      }
      
      try {
        // 优先使用现代 Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(String(text));
          return true;
        }
        
        // 降级到传统方法
        return this.fallbackCopy(String(text));
      } catch (error) {
        console.error('Clipboard copy error:', error);
        return this.fallbackCopy(String(text));
      }
    }

    /**
     * 降级复制方法（传统方式）
     * @param {string} text - 要复制的文本
     * @returns {boolean} 是否成功
     * @private
     */
    fallbackCopy(text) {
      try {
        // 创建隐藏的文本域
        const textarea = this.createHiddenTextarea(text);
        
        // 添加到页面
        this.options.container.appendChild(textarea);
        
        // 使用 Range API 选择文本（避免全选）
        const selection = window.getSelection();
        const range = document.createRange();
        
        range.selectNodeContents(textarea);
        selection.removeAllRanges();
        selection.addRange(range);
        
        // 尝试执行复制命令
        const successful = document.execCommand('copy');
        
        // 清理
        selection.removeAllRanges();
        
        if (this.options.autoDestroy) {
          this.options.container.removeChild(textarea);
        }
        
        return successful;
      } catch (error) {
        console.error('Clipboard fallback copy error:', error);
        return false;
      }
    }

    /**
     * 创建隐藏的文本域元素
     * @param {string} text - 文本内容
     * @returns {HTMLTextAreaElement} 创建的文本域元素
     * @private
     */
    createHiddenTextarea(text) {
      const textarea = document.createElement('textarea');
      
      // 设置样式确保元素不可见且不会影响布局
      textarea.style.cssText = `
        position: absolute;
        left: -9999px;
        top: 0;
        width: 1px;
        height: 1px;
        opacity: 0.01;
        font-size: 12pt;
        border: 0;
        padding: 0;
        margin: 0;
        white-space: pre-wrap;
        word-wrap: break-word;
      `;
      
      // 防止移动端键盘弹出
      textarea.setAttribute('readonly', '');
      textarea.setAttribute('aria-hidden', 'true');
      textarea.setAttribute('tabindex', '-1');
      
      // 设置值
      textarea.value = text;
      
      return textarea;
    }

    /**
     * 剪切文本到剪贴板
     * @param {HTMLElement} target - 要剪切的目标元素
     * @returns {Promise<boolean>} 是否成功
     * @public
     */
    async cut(target) {
        if (!target) {
            console.warn('Clipboard: No target element for cut operation');
            return false;
        }
        
        try {
            const text = this.getTargetText(target);
            
            // 优先使用现代 API 复制
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                
                // 清空源元素内容
                this.clearTargetContent(target);
                return true;
            }
            
            // 降级方案
            const copied = this.fallbackCopy(text);
            if (copied) {
                this.clearTargetContent(target);
            }
            return copied;
        } catch (error) {
            console.error('Clipboard cut error:', error);
            return false;
        }
    }

    /**
     * 清空目标元素内容
     * @param {HTMLElement} target - 目标元素
     * @private
     */
    clearTargetContent(target) {
        if (target instanceof HTMLInputElement || 
            target instanceof HTMLTextAreaElement) {
            target.value = '';
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));
        } else if (target.isContentEditable || target.contentEditable === 'true') {
            target.textContent = '';
        } else {
            target.textContent = '';
        }
    }

    /** 
     * 提示复制/剪切成功 
     * @param {HTMLElement} target - 目标元素
     */
    showSuccess(target) {
        if(target.dataset.clipboardFeedback != 'true' && this.options.feedback != true){
            return;
        }
        const btnText = target.textContent.trim();
        const html = target.innerHTML;
        target.classList.add('bg-success','text-white');
        target.innerHTML = `<i class="bi-check-lg"></i>${btnText ? '复制成功' : ''}`;
        setTimeout(() => {
            target.classList.remove('bg-success','text-white');
            target.innerHTML = html;
        }, 2000);
    }

    /** 
     * 提示复制/剪切成功 
     * @param {HTMLElement} target - 目标元素
     */
    showError(target) {
        if(target.dataset.clipboardFeedback != 'true' && this.options.feedback != true){
            return;
        }
        const btnText = target.textContent.trim();
        const html = target.innerHTML;
        target.classList.add('bg-danger','text-white');
        target.innerHTML = `<i class="bi bi-x-lg"></i>${btnText ? '复制失败' : ''}`;
        setTimeout(() => {
            target.classList.remove('bg-danger','text-white');
            target.innerHTML = html;
        }, 2000);
    }


    /**
     * 销毁实例，清理事件监听
     * @public
     */
    destroy() {
      // 移除所有事件监听器
      this.listeners.forEach(listener => {
        if (listener.element && listener.click) {
          listener.element.removeEventListener('click', listener.click);
        }
        if (listener.element && listener.keydown) {
          listener.element.removeEventListener('keydown', listener.keydown);
        }
      });
      
      this.listeners = [];
      this.events = {};
    }

    /**
     * 静态方法：复制文本
     * @static
     * @param {string} text - 要复制的文本
     * @param {Object} [options] - 配置选项
     * @param {HTMLElement} [options.container=document.body] - 容器元素
     * @returns {Promise<boolean>} 是否成功
     * 
     * @example
     * Clipboard.copy('Hello World').then(success => {
     *   console.log(success ? '复制成功' : '复制失败');
     * });
     */
    static async copy(text, options = {}) {
      const instance = new Clipboard(document.createElement('div'), {
        ...options,
        text
      });
      const result = await instance.copy(text);
      instance.destroy();
      return result;
    }

    /**
     * 静态方法：剪切元素内容
     * @static
     * @param {HTMLElement} target - 目标元素
     * @param {Object} [options] - 配置选项
     * @returns {Promise<boolean>} 是否成功
     */
    static async cut(target, options = {}) {
      const instance = new Clipboard(document.createElement('div'), {
        ...options,
        target
      });
      const result = await instance.cut(target);
      instance.destroy();
      return result;
    }

    /**
     * 静态方法：检查浏览器是否支持剪贴板操作
     * @static
     * @param {string} [action='copy'] - 要检查的操作类型: 'copy' 或 'cut'
     * @returns {boolean} 是否支持
     * 
     * @example
     * if (Clipboard.isSupported()) {
     *   console.log('浏览器支持剪贴板操作');
     * }
     */
    static isSupported(action = 'copy') {
      // 检查现代 Clipboard API
      if (navigator.clipboard && window.isSecureContext) {
        if (action === 'copy') {
          return typeof navigator.clipboard.writeText === 'function';
        } else if (action === 'cut') {
          // 剪切需要检查是否有写入权限
          return true;
        }
      }
      
      // 检查传统 execCommand 支持
      if (action === 'copy' || action === 'cut') {
        return document.queryCommandSupported 
          ? document.queryCommandSupported(action)
          : false;
      }
      
      return false;
    }

    /**
     * 静态方法：获取支持的剪贴板操作列表
     * @static
     * @returns {Array.<string>} 支持的操作列表
     */
    static getSupportedActions() {
      const actions = [];
      
      if (this.isSupported('copy')) {
        actions.push('copy');
      }
      
      if (this.isSupported('cut')) {
        actions.push('cut');
      }
      
      return actions;
    }

    /**
     * 静态方法：设置全局默认配置
     * @static
     * @param {Object} defaults - 默认配置
     */
    static setDefaults(defaults) {
      Clipboard.defaults = {
        ...Clipboard.defaults,
        ...defaults
      };
    }
  }

  /**
   * 全局默认配置
   * @static
   * @type {Object}
   */
  Clipboard.defaults = {
    container: document.body,
    autoDestroy: true
  };

  return Clipboard;
});