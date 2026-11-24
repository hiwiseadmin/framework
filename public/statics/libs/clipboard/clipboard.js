/**
 * Clipboard — 剪贴板写入（ESM 模块）
 *
 * 位置：public/statics/libs/clipboard/clipboard.js
 * 加载：随 wise-bundle.js 打包（wise-bootstrap.js 顶部静态 import），页面模板不再单独引入。
 *       原先 extend/plugin/view/base.html 漏引本文件，插件页 Wise.ui.clipboard 会静默降级到
 *       navigator.clipboard（HTTP 下直接失败）—— 打包后不再存在"漏引"这个失败维度。
 *
 * 兼容：模块求值末尾仍挂 window.Clipboard，icons.js / wise-ui.clipboard 等存量调用零改动。
 * 独立静态页（demo 等）：import Clipboard from '/statics/libs/clipboard/clipboard.js';
 *
 * 写入策略（重点是 iframe）：当前文档 execCommand → copy 事件 →
 *   顶层文档 execCommand / copy 事件 → Clipboard API（仅安全上下文）。
 *
 * @module libs/clipboard
 */

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
          // 事件委托选择器：在 trigger（容器）上挂一个监听，
          // 动态插入的按钮也能复制，避免"初始化时绑定不到就永久失效"
          delegate: null,
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
      const { trigger, options } = this;

      // 事件委托模式：new Clipboard(document, { delegate: '.copy-btn' })
      if (options.delegate) {
          const root = typeof trigger === 'string'
              ? (document.querySelector(trigger) || document)
              : (trigger instanceof HTMLElement ? trigger : document);

          const clickHandler = (e) => {
              const target = e.target instanceof Element ? e.target.closest(options.delegate) : null;
              if (!target || (root !== document && !root.contains(target))) {
                  return;
              }

              e.preventDefault();
              this.handleClick(target);
          };

          root.addEventListener('click', clickHandler);
          this.listeners.push({ element: root, click: clickHandler });
          return;
      }

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
    
    // 统一走多策略写入（含 iframe 顶层文档兜底），详见 writeClipboard
    return writeClipboard(String(text));
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
    if (!text && text !== 0) {
      return false;
    }

    // 与实例方法共用同一套写入策略（含 iframe 顶层文档兜底）
    return writeClipboard(String(text));
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
 * 在指定文档里用 textarea + execCommand 复制
 * @param {Document} doc
 * @param {string} text
 * @returns {boolean}
 */
function execCopyIn(doc, text) {
  if (!doc || !doc.body || typeof doc.execCommand !== 'function') {
    return false;
  }

  const previousFocus = doc.activeElement;
  const textarea = doc.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', 'readonly');
  textarea.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0;';
  doc.body.appendChild(textarea);
  textarea.focus();
  textarea.select();
  try {
    textarea.setSelectionRange(0, text.length);
  } catch (e) {
    // 忽略
  }

  let ok = false;
  try {
    ok = doc.execCommand('copy');
  } catch (e) {
    ok = false;
  }

  doc.body.removeChild(textarea);
  if (previousFocus && typeof previousFocus.focus === 'function') {
    previousFocus.focus();
  }

  return ok === true;
}

/**
 * 通过 copy 事件写入剪贴板（不依赖 textarea 选中）
 * @param {Document} doc
 * @param {string} text
 * @returns {boolean}
 */
function eventCopyIn(doc, text) {
  if (!doc || typeof doc.execCommand !== 'function') {
    return false;
  }

  let handled = false;
  const listener = (e) => {
    if (!e.clipboardData) {
      return;
    }
    try {
      e.clipboardData.setData('text/plain', text);
      e.preventDefault();
      handled = true;
    } catch (err) {
      // 忽略，继续其它策略
    }
  };

  doc.addEventListener('copy', listener, true);
  let ok = false;
  try {
    ok = doc.execCommand('copy');
  } catch (err) {
    ok = false;
  }
  doc.removeEventListener('copy', listener, true);

  return handled && ok === true;
}

/**
 * Clipboard API 写入（需要安全上下文）
 * @param {Window} win
 * @param {string} text
 * @returns {Promise<boolean>}
 */
function apiCopyIn(win, text) {
  try {
    if (win && win.navigator && win.navigator.clipboard && win.navigator.clipboard.writeText) {
      return win.navigator.clipboard.writeText(text).then(() => true).catch(() => false);
    }
  } catch (e) {
    // 跨域/权限异常
  }

  return Promise.resolve(false);
}

/**
 * 写文本到剪贴板（多策略 + iframe 兜底）
 *
 * 顺序说明：
 *   1. 当前文档 execCommand ── 手势内同步执行，HTTP/HTTPS 都可用；
 *   2. 当前文档 copy 事件；
 *   3. 顶层文档 execCommand / copy 事件 ── 后台页面几乎都在同源 iframe 里，
 *      非安全上下文下子框架的写入常被浏览器静默丢弃（execCommand 仍返回 true），
 *      顶层是普通页面，能真正写入，所以即使当前文档"成功"也要再试一次；
 *   4. Clipboard API（仅安全上下文）。
 *
 * @param {string} text
 * @returns {Promise<boolean>}
 */
async function writeClipboard(text) {
  const tried = [];

  const selfExec = execCopyIn(document, text);
  tried.push('exec:self=' + (selfExec ? 1 : 0));

  let selfEvent = false;
  if (!selfExec) {
    selfEvent = eventCopyIn(document, text);
    tried.push('event:self=' + (selfEvent ? 1 : 0));
  }

  let topOk = false;
  if (window.top && window.top !== window) {
    try {
      const topDoc = window.top.document;
      topOk = execCopyIn(topDoc, text);
      tried.push('exec:top=' + (topOk ? 1 : 0));

      if (!topOk) {
        topOk = eventCopyIn(topDoc, text);
        tried.push('event:top=' + (topOk ? 1 : 0));
      }
    } catch (e) {
      tried.push('top=blocked');
    }
  }

  if (selfExec || selfEvent || topOk) {
    window.__clipboardDebug = {
      length: text.length,
      tried,
      win: selfExec ? 'exec:self' : (selfEvent ? 'event:self' : 'exec:top')
    };
    return true;
  }

  if (await apiCopyIn(window, text)) {
    tried.push('api:self=1');
    window.__clipboardDebug = { length: text.length, tried, win: 'api:self' };
    return true;
  }
  tried.push('api:self=0');

  if (window.top && window.top !== window && await apiCopyIn(window.top, text)) {
    tried.push('api:top=1');
    window.__clipboardDebug = { length: text.length, tried, win: 'api:top' };
    return true;
  }
  tried.push('api:top=0');

  window.__clipboardDebug = { length: text.length, tried, win: null };
  console.warn('[Clipboard] 写入失败，已尝试:', tried.join(', '));

  return false;
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

// 兼容挂载点：存量代码以 window.Clipboard 使用（icons.js、Wise.ui.clipboard），保留。
if (typeof window !== 'undefined') {
    window.Clipboard = Clipboard;
}

export default Clipboard;
export { Clipboard };
