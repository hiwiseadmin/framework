(function (global, factory) {
  // UMD 模块定义
  if (typeof define === 'function' && define.amd) {
    // AMD
    define(['jquery'], factory);
  } else if (typeof module === 'object' && module.exports) {
    // CommonJS
    module.exports = factory(require('jquery'));
  } else {
    // 全局变量
    var jQuery = global.jQuery;
    global.ExternalLinkHandler = factory(jQuery);
  }
})(typeof window !== 'undefined' ? window : this, function ($) {
  'use strict';

  /**
   * 外部链接安全打开组件
   * 监听a标签的外部链接点击，进行二次确认
   * 支持白名单、黑名单和指定根节点
   */
  class ExternalLinkHandler {
    // 默认配置
    default = {
        confirmMessage: '您即将离开本站，前往外部链接。确定要继续吗？',
        dangerConfirmMessage: '这是一个高风险链接，确定要继续访问吗？',
        enableConfirm: true,
        enableWhitelist: true,
        enableBlacklist: true,
        whitelist: [], // 白名单域名数组
        blacklist: [], // 黑名单域名数组
        rootElement: document, // 监听的根元素，默认为整个文档
        checkOnInit: true, // 初始化时检查现有链接
        autoDestroyOnRootRemove: true, // 当根元素被移除时自动销毁
      };

    constructor(options = {}) {
      this.options = {
        ...this.default,
        ...options
      };

      // 存储所有观察者实例
      this.observers = [];
      // 存储事件监听器引用
      this.eventListeners = [];
      // 存储MutationObserver
      this.mutationObserver = null;
      // 存储根元素
      this.root = typeof this.options.rootElement === 'string'
        ? document.querySelector(this.options.rootElement)
        : this.options.rootElement;

      if (!this.root) {
        console.error('ExternalLinkHandler: 无法找到根元素:', this.options.rootElement);
        return;
      }

      // 绑定方法
      this.handleClick = this.handleClick.bind(this);
      this.isExternalLink = this.isExternalLink.bind(this);
      this.isInWhitelist = this.isInWhitelist.bind(this);
      this.isInBlacklist = this.isInBlacklist.bind(this);

      // 初始化
      this.init();
    }

    /**
     * 初始化组件
     */
    init() {
      // 监听根元素点击事件（事件委托）
      this.root.addEventListener('click', this.handleClick, true);
      this.eventListeners.push({
        element: this.root,
        type: 'click',
        handler: this.handleClick
      });

      // 初始化时检查所有现有链接
      if (this.options.checkOnInit) {
        this.checkExistingLinks();
      }

      // 监听动态添加的a标签
      this.setupMutationObserver();

      // 如果启用了自动销毁，监听根元素移除
      if (this.options.autoDestroyOnRootRemove) {
        this.setupRootRemovalObserver();
      }
    }

    /**
     * 检查所有现有链接
     */
    checkExistingLinks() {
      const links = this.root.querySelectorAll('a[href]');
      links.forEach(link => {
        this.setupLink(link);
      });
    }

    /**
     * 设置单个链接的监听
     */
    setupLink(link) {
      // 移除之前的监听器
      link.removeEventListener('click', this.handleClick);

      // 添加新的监听器
      link.addEventListener('click', this.handleClick);
      this.eventListeners.push({
        element: link,
        type: 'click',
        handler: this.handleClick
      });
    }

    /**
     * 设置MutationObserver监听DOM变化
     */
    setupMutationObserver() {
      // 如果已经是根文档，使用document.body
      const target = this.root === document ? document.body : this.root;

      this.mutationObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.addedNodes.length) {
            mutation.addedNodes.forEach((node) => {
              if (node.nodeType === Node.ELEMENT_NODE) {
                if (node.nodeName === 'A' && node.href) {
                  this.setupLink(node);
                }
                
                // 检查新增节点内的链接
                const links = node.querySelectorAll('a[href]');
                links.forEach(link => this.setupLink(link));
              }
            });
          }
        });
      });

      this.mutationObserver.observe(target, {
        childList: true,
        subtree: true
      });
    }

    /**
     * 监听根元素是否被移除
     */
    setupRootRemovalObserver() {
      if (this.root === document || this.root === document.documentElement || this.root === document.body) {
        return; // 文档根元素不会被移除
      }

      const removalObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.removedNodes.length) {
            const removed = Array.from(mutation.removedNodes);
            if (removed.some(node => node.contains(this.root))) {
              this.destroy();
              removalObserver.disconnect();
            }
          }
        });
      });

      // 从body开始监听，因为根元素可能在任何地方
      if (this.root.parentNode) {
        removalObserver.observe(this.root.parentNode, {
          childList: true
        });
        this.observers.push(removalObserver);
      }
    }

    /**
     * 判断是否为外部链接
     */
    isExternalLink(url) {
      try {
        const linkUrl = new URL(url, window.location.href);
        const currentUrl = new URL(window.location.href);
        
        // 判断是否为外部链接
        return linkUrl.hostname !== currentUrl.hostname && 
               linkUrl.hostname !== '' && 
               !url.startsWith('#') && 
               !url.startsWith('javascript:') &&
               !url.startsWith('mailto:') &&
               !url.startsWith('tel:');
      } catch (e) {
        return false;
      }
    }

    /**
     * 检查是否在白名单中
     */
    isInWhitelist(url) {
      if (!this.options.enableWhitelist || this.options.whitelist.length === 0) {
        return false;
      }

      try {
        const linkUrl = new URL(url, window.location.href);
        return this.options.whitelist.some(domain => {
          // 支持通配符 *.example.com
          if (domain.startsWith('*.')) {
            const baseDomain = domain.substring(2);
            return linkUrl.hostname === baseDomain || 
                   linkUrl.hostname.endsWith('.' + baseDomain);
          }
          
          // 精确匹配
          return linkUrl.hostname === domain;
        });
      } catch (e) {
        return false;
      }
    }

    /**
     * 检查是否在黑名单中
     */
    isInBlacklist(url) {
      if (!this.options.enableBlacklist || this.options.blacklist.length === 0) {
        return false;
      }

      try {
        const linkUrl = new URL(url, window.location.href);
        return this.options.blacklist.some(domain => {
          // 支持通配符 *.example.com
          if (domain.startsWith('*.')) {
            const baseDomain = domain.substring(2);
            return linkUrl.hostname === baseDomain || 
                   linkUrl.hostname.endsWith('.' + baseDomain);
          }
          
          // 精确匹配
          return linkUrl.hostname === domain;
        });
      } catch (e) {
        return false;
      }
    }

    /**
     * 处理点击事件
     */
    handleClick(event) {
        console.log('click', event);
      const link = event.target.closest('a');
      
      if (!link || !link.href) return;

      const url = link.href;

      // 如果不是外部链接，直接放行
      if (!this.isExternalLink(url)) {
        return;
      }

      // 检查是否在白名单中
      if (this.isInWhitelist(url)) {
        // 白名单链接直接放行
        return;
      }

      // 检查是否在黑名单中
      const isBlacklisted1 = this.isInBlacklist(url);
      // 如果启用了确认功能
      if (this.options.enableConfirm) {
        event.preventDefault();
        event.stopPropagation();

        // 确认消息
        const message = isBlacklisted1 
          ? this.options.dangerConfirmMessage 
          : this.options.confirmMessage;
        // 显示确认对话框
        if(wsLayer !== undefined) {
            Layer.confirm(message, {}, ()=>{
                this.openLink(url, link.target);
            });
        }else{
            if (window.confirm(message)) {
            this.openLink(url, link.target);
            }
        }
      }
    }

    /**
     * 安全打开链接
     */
    openLink(url, target = '_self') {
      // 添加rel属性防止安全漏洞
      const rel = 'noopener noreferrer';
      
      if (target === '_blank') {
        const newWindow = window.open(url, target);
        if (newWindow) {
          newWindow.opener = null;
        }
      } else {
        window.location.href = url;
      }
    }

    /**
     * 添加域名到白名单
     */
    addToWhitelist(domain) {
      if (!this.options.whitelist.includes(domain)) {
        this.options.whitelist.push(domain);
      }
    }

    /**
     * 从白名单中移除域名
     */
    removeFromWhitelist(domain) {
      this.options.whitelist = this.options.whitelist.filter(d => d !== domain);
    }

    /**
     * 添加域名到黑名单
     */
    addToBlacklist(domain) {
      if (!this.options.blacklist.includes(domain)) {
        this.options.blacklist.push(domain);
      }
    }

    /**
     * 从黑名单中移除域名
     */
    removeFromBlacklist(domain) {
      this.options.blacklist = this.options.blacklist.filter(d => d !== domain);
    }

    /**
     * 手动检查并处理链接
     */
    checkAndHandleLink(url) {
      if (!this.isExternalLink(url)) {
        return true; // 直接放行
      }

      if (this.isInWhitelist(url)) {
        return true; // 直接放行
      }

      const isBlacklisted = this.isInBlacklist(url);
      
      if (this.options.enableConfirm) {
        const message = isBlacklisted 
          ? this.options.dangerConfirmMessage 
          : this.options.confirmMessage;
        console.log('message2', message);
        return window.confirm(message);
      }

      return true;
    }

    /**
     * 安全地打开链接（手动调用）
     */
    safeOpenLink(url, target = '_self') {
      if (this.checkAndHandleLink(url)) {
        this.openLink(url, target);
      }
    }

    /**
     * 更新配置
     */
    updateOptions(newOptions) {
      this.options = { ...this.options, ...newOptions };
      
      // 如果根元素改变了，需要重新初始化
      if (newOptions.rootElement) {
        const newRoot = typeof newOptions.rootElement === 'string'
          ? document.querySelector(newOptions.rootElement)
          : newOptions.rootElement;
        
        if (newRoot && newRoot !== this.root) {
          this.destroy();
          this.root = newRoot;
          this.init();
        }
      }
    }

    /**
     * 销毁组件
     */
    destroy() {
      // 移除所有事件监听器
      this.eventListeners.forEach(({ element, type, handler }) => {
        if (element && element.removeEventListener) {
          element.removeEventListener(type, handler);
        }
      });
      this.eventListeners = [];

      // 断开所有观察者
      if (this.mutationObserver) {
        this.mutationObserver.disconnect();
        this.mutationObserver = null;
      }

      this.observers.forEach(observer => observer.disconnect());
      this.observers = [];
    }
  }

  // jQuery插件支持
  if ($ && $.fn) {
    // 存储所有实例
    const instances = new WeakMap();

    $.fn.externalLinkHandler = function(options = {}) {
      return this.each(function() {
        // 如果已存在实例，先销毁
        if (instances.has(this)) {
          instances.get(this).destroy();
        }

        // 创建新实例
        const instanceOptions = {
          ...options,
          rootElement: this
        };

        const instance = new ExternalLinkHandler(instanceOptions);
        instances.set(this, instance);
      });
    };

    // jQuery插件方法
    $.fn.externalLinkHandler.methods = {
      destroy: function() {
        return this.each(function() {
          if (instances.has(this)) {
            instances.get(this).destroy();
            instances.delete(this);
          }
        });
      },
      updateOptions: function(options) {
        return this.each(function() {
          if (instances.has(this)) {
            instances.get(this).updateOptions(options);
          }
        });
      },
      addToWhitelist: function(domain) {
        return this.each(function() {
          if (instances.has(this)) {
            instances.get(this).addToWhitelist(domain);
          }
        });
      },
      removeFromWhitelist: function(domain) {
        return this.each(function() {
          if (instances.has(this)) {
            instances.get(this).removeFromWhitelist(domain);
          }
        });
      },
      addToBlacklist: function(domain) {
        return this.each(function() {
          if (instances.has(this)) {
            instances.get(this).addToBlacklist(domain);
          }
        });
      },
      removeFromBlacklist: function(domain) {
        return this.each(function() {
          if (instances.has(this)) {
            instances.get(this).removeFromBlacklist(domain);
          }
        });
      }
    };

    // 扩展jQuery插件方法
    Object.keys($.fn.externalLinkHandler.methods).forEach(method => {
      $.fn.externalLinkHandler[method] = $.fn.externalLinkHandler.methods[method];
    });
  }

  // 提供全局工厂函数
  ExternalLinkHandler.create = function(options) {
    return new ExternalLinkHandler(options);
  };

  return ExternalLinkHandler;
});