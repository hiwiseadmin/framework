/**
 * 智能输入提示模块
 * @version 1.0.0
 * @author WiseAdmin
 * @license MIT
 */
// 样式：ws-hint-input.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-hint-input/ws-hint-input.css">

import {
    parseDataConfig,
    pickData,
    normalizeOptions,
    fetchOptions,
    getByPath,
    toElements,
    T,
} from '../ws-common/ws-common.js';

    // 注入组件样式：样式已改为外部引入（见文件顶部说明）。
    // 保留空实现以兼容内部既有调用点。
    function injectStyles() {
        // no-op：样式已由外部样式表提供
    }

    // 默认配置
    const defaultOptions = {
        minChars: 1,           // 触发提示的最小字符数
        delay: 300,            // 输入延迟（防抖）
        maxItems: 10,          // 最大显示建议数
        highlightMatches: true, // 是否高亮匹配部分
        data: [],              // 本地数据数组
        url: null,             // 远程数据URL
        paramName: 'q',        // 远程请求参数名
        params: null,          // 远程请求的固定附加参数（对象）
        responsePath: 'data.rows', // 远程响应中数组的路径，支持 data.rows 这类点号路径
        mapping: null,         // 选项字段映射 {value, label}，默认 {value:'value', label:'label'}
        onSelect: null,        // 选择建议时的回调
        onFetchStart: null,    // 开始获取数据时的回调
        onFetchEnd: null,      // 结束获取数据时的回调
        onError: null          // 错误处理回调
    };

    /**
     * data-* 白名单（`data-role="wshint"` 声明式初始化用）。
     * 不做 `$el.data()` 全量 dump，避免业务属性被当成配置。
     */
    const HINT_SCHEMA = {
        minChars: T.NUM,
        delay: T.NUM,
        maxItems: T.NUM,
        highlightMatches: T.BOOL,
        url: T.RAW,
        paramName: T.RAW,
        params: T.JSON,
        responsePath: T.RAW,
        mapping: T.JSON,
        onSelect: T.FN,
        onFetchStart: T.FN,
        onFetchEnd: T.FN,
        onError: T.FN,
    };
    
    // 智能输入提示类
    class HintInput {
        /**
         * 构造函数
         * @param {HTMLElement|string} inputElement - 输入框元素或选择器字符串
         * @param {Object} [options={}] - 配置选项
         * @property {number} minChars - 触发提示的最小字符数
         * @property {number} delay - 输入延迟（防抖）
         * @property {number} maxItems - 最大显示建议数
         * @property {boolean} highlightMatches - 是否高亮匹配部分
         * @property {Array} data - 本地数据数组
         * @property {string} url - 远程数据URL
         * @property {string} paramName - 远程请求参数名
         * @property {function} onSelect - 选择建议时的回调
         * @property {function} onFetchStart - 开始获取数据时的回调
         * @property {function} onFetchEnd - 结束获取数据时的回调
         * @property {function} onError - 错误处理回调
         */
        constructor(inputElement, options = {}) {
            if (typeof inputElement === 'string') {
                inputElement = document.querySelector(inputElement);
            }

            // 先检查是否为 DOM 元素，避免 null.tagName 抛出 TypeError
            if (!(inputElement instanceof HTMLElement)) {
                throw new Error('HintInput: 未找到目标元素，请确认选择器或 DOM 元素有效');
            }

            if (inputElement.tagName !== 'INPUT' || (inputElement.type !== 'text' && inputElement.type !== 'search')) {
                throw new Error('HintInput: 必须传入文本输入框元素（type="text" 或 type="search"）');
            }

            this.input = inputElement;
            this.options = { ...defaultOptions, ...options };
            this.suggestions = [];
            this.activeIndex = -1;
            this.isOpen = false;
            this.timeoutId = null;
            
            // 注入组件样式
            injectStyles();

            // 为每个实例生成唯一ID
            this.instanceId = 'ws-hintinput-' + Math.random().toString(36).substring(2, 9);
       
            this.init();
        }
        
        // 初始化
        init() {
            // 创建下拉容器
            this.container = document.createElement('div');
            this.container.className = 'ws-hintinput-suggestions';
            this.container.id = this.instanceId;
            document.body.appendChild(this.container);
            
            // 绑定事件
            this.bindEvents();
        }
        
        // 绑定事件
        bindEvents() {
            // 输入事件
            this.input.addEventListener('input', (e) => {
                this.handleInput(e.target.value);
            });
            
            // 键盘事件
            this.input.addEventListener('keydown', (e) => {
                this.handleKeyDown(e);
            });
            
			// 焦点事件：有内容且达到最小字符数时才触发提示
			this.input.addEventListener('focus', (e) => {
				const query = this.input.value.trim();
				if (query.length >= this.options.minChars) {
					this.fetchSuggestions(query);
				} else if (query.length === 0 && !this.options.url) {
					// 本地数据模式：空输入时显示全部建议（方便快速浏览）
					this.fetchSuggestions('');
				}
			});
            
            // 点击外部关闭
            this._outsideClickHandler = (e) => {
                if (!this.input.contains(e.target) && !this.container.contains(e.target)) {
                    this.close();
                }
            };
            document.addEventListener('click', this._outsideClickHandler);
			// 窗口大小变化和滚动时更新位置
			this.resizeHandler = () => {
				if (this.isOpen) {
					this.updatePosition();
				}
			};
			
			this.scrollHandler = () => {
				if (this.isOpen) {
					this.updatePosition();
				}
			};
			
			window.addEventListener('resize', this.resizeHandler);
			window.addEventListener('scroll', this.scrollHandler, true);
		}
		
		// 更新下拉框位置
        updatePosition() {
            const rect = this.input.getBoundingClientRect();
            const scrollY = window.scrollY || document.documentElement.scrollTop;
            const scrollX = window.scrollX || document.documentElement.scrollLeft;
            
            // 设置下拉框的宽度和位置
            this.container.style.width = `${rect.width}px`;
            this.container.style.left = `${rect.left + scrollX}px`;
            this.container.style.top = `${rect.bottom + scrollY}px`;
            
            // 确保下拉框在视口中可见
            const viewportHeight = window.innerHeight;
            const dropdownHeight = this.container.offsetHeight;
            const spaceBelow = viewportHeight - rect.bottom;
            
            // 如果下方空间不足，将下拉框显示在输入框上方
            if (spaceBelow < dropdownHeight && rect.top > dropdownHeight) {
                this.container.style.top = `${rect.top + scrollY - dropdownHeight}px`;
            }
        }
        
        // 处理输入
        handleInput(value = '') {
            // 清除之前的定时器
            if (this.timeoutId) {
                clearTimeout(this.timeoutId);
            }
            
            // 如果字符数不足，关闭下拉
            if (value && value.trim().length > 0 && value.trim().length < this.options.minChars) {
                this.close();
                return;
            }
            
            // 防抖处理
            this.timeoutId = setTimeout(() => {
                this.fetchSuggestions(value);
            }, this.options.delay);
        }
        
        // 获取建议
        fetchSuggestions(query) {
            if (this.options.url) {
                this.fetchRemoteSuggestions(query);
            } else {
                this.fetchLocalSuggestions(query.trim());
            }
        }
        
        // 获取本地建议
        fetchLocalSuggestions(query = '') {
            const filtered = this.options.data
                .filter(item => item.toLowerCase().includes(query.toLowerCase()))
                .slice(0, this.options.maxItems);

            this.displaySuggestions(filtered, query);
            
            if (this.options.onFetchEnd) {
                this.options.onFetchEnd(filtered);
            }
        }
        
        // 获取远程建议
        fetchRemoteSuggestions(query = '') {
            if (this.options.onFetchStart) {
                this.options.onFetchStart();
            }
            
            // 显示 loading 状态
            this.container.innerHTML = '';
            this.container.setAttribute('data-ws-state', 'loading');
            this.container.style.display = 'block';
            this.updatePosition();

            // 统一走 ws-common.fetchOptions：带并发去重 + 结果缓存，
            // 并能吃下框架标准响应 `{code:0,data:{rows:[{value,label}]}}`。
            // 早前只认「纯数组」，接口一换外壳就整体失效。
            fetchOptions({
                url: this.options.url,
                params: this.options.params || undefined,
                search: query,
                searchParam: this.options.paramName || 'q',
                responsePath: this.options.responsePath || 'data.rows',
                mapping: this.options.mapping || undefined,
            })
                .then((rows) => {
                    // 子类若覆写了 processRemoteData，仍交给它做二次处理
                    const custom = this.processRemoteData !== HintInput.prototype.processRemoteData;
                    let suggestions = custom ? this.processRemoteData(rows) : rows;

                    if (!Array.isArray(suggestions)) suggestions = [];

                    const limit = parseInt(this.options.maxItems, 10);
                    if (limit > 0) suggestions = suggestions.slice(0, limit);

                    this.displaySuggestions(suggestions, query);
                    
                    if (this.options.onFetchEnd) {
                        this.options.onFetchEnd(suggestions);
                    }
                })
                .catch(error => {
                    console.error('获取远程数据失败:', error);
                    // 显示 error 状态
                    this.container.innerHTML = '';
                    this.container.removeAttribute('data-ws-state');
                    this.container.setAttribute('data-ws-state', 'error');
                    this.container.setAttribute('data-ws-error-text', (error.message || '加载失败'));
                    this.container.style.display = 'block';
                    this.isOpen = true;
                    this.updatePosition();

                    if (this.options.onError) {
                        this.options.onError(error);
                    }
                });
        }

        /** 建议项的显示文本（兼容字符串与 {value,label} 对象两种形态） */
        labelOf(item) {
            if (item === null || item === undefined) return '';
            return typeof item === 'object' ? String(item.label ?? item.value ?? '') : String(item);
        }

        /** 建议项写回输入框的值 */
        valueOf(item) {
            if (item === null || item === undefined) return '';
            if (typeof item === 'object') {
                return item.value !== undefined && item.value !== null ? String(item.value) : this.labelOf(item);
            }
            return String(item);
        }

        /** HTML 转义：建议文本来自接口，必须转义后再拼高亮 */
        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
        
        // 构建远程请求URL
        buildUrl(query) {
            // 传入 base URL 使得相对路径（如 /api/search）也能正常工作
            // 当 url 为绝对路径时 base 会自动被忽略
            const url = new URL(this.options.url, window.location.origin);
            url.searchParams.set(this.options.paramName, query);
            return url.toString();
        }
        
        // 处理远程数据（如果不是数组格式）
        processRemoteData(data) {
            // 默认实现，假设数据已经是数组
            // 子类可以重写此方法以处理不同的数据格式
            return data;
        }
        
        // 显示建议
        displaySuggestions(suggestions, query) {
            query = query.trim();
            this.suggestions = suggestions;
            this.activeIndex = -1;

            // 清除 loading/error 状态
            this.container.removeAttribute('data-ws-state');
            this.container.removeAttribute('data-ws-error-text');
            
            if (suggestions.length === 0) {
                // 显示 empty 状态，而非直接关闭
                this.container.innerHTML = '';
                this.container.setAttribute('data-ws-state', 'empty');
                this.container.setAttribute('data-ws-empty-text', '无匹配结果');
                this.container.style.display = 'block';
                this.isOpen = true;
                this.updatePosition();
                return;
            }
            
            // 生成HTML
            this.container.innerHTML = '';
            suggestions.forEach((suggestion, index) => {
                const item = document.createElement('div');
                item.className = 'ws-hintinput-suggestion';

                // 显示文本统一取 label（兼容字符串与对象两种建议项）
                const text = this.labelOf(suggestion);

                // 高亮匹配部分：先转义再插标记。
                // 建议文本来自接口，直接 innerHTML 拼原文等于把 XSS 交给后端数据决定。
                if (this.options.highlightMatches && query && query.trim().length > 0) {
                    const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
                    item.innerHTML = this.escapeHtml(text)
                        .replace(regex, '<span class="ws-hintinput-highlight">$1</span>');
                } else {
                    item.textContent = text;
                }
                // 点击事件
                item.addEventListener('click', (e) => {
                    this.selectSuggestion(index);
                });
                
                // 鼠标悬停
                item.addEventListener('mouseover', (e) => {
                    this.setActiveItem(index);
                });
                
                this.container.appendChild(item);
            });
            
            // 显示下拉
            this.open();
        }
        
        // 打开下拉
        open() {
            // 先更新位置再显示，确保位置正确
            this.updatePosition();
            this.container.style.display = 'block';
            this.isOpen = true;
            
            // 使用微任务确保DOM已更新，然后再次检查位置
            setTimeout(() => {
                if (this.isOpen) {
                    this.updatePosition();
                }
            }, 0);
        }
        
        // 关闭下拉
        close() {
            this.container.style.display = 'none';
            this.isOpen = false;
            this.activeIndex = -1;
        }
        
        // 处理键盘事件
        handleKeyDown(e) {
            if (!this.isOpen) return;
            
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.navigate(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.navigate(-1);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (this.activeIndex >= 0) {
                        this.selectSuggestion(this.activeIndex);
                    }
                    break;
                case 'Tab':
                    if (this.activeIndex >= 0) {
                        e.preventDefault();
                        this.selectSuggestion(this.activeIndex);
                    }
                    break;
                case 'Escape':
                    this.close();
                    break;
            }
        }
        
        // 导航
        navigate(direction) {
            const items = this.container.children;
            if (items.length === 0) return;
            
            // 移除当前激活项
            if (this.activeIndex >= 0) {
                items[this.activeIndex].classList.remove('ws-active');
            }
            
            // 计算新索引
            this.activeIndex += direction;
            
            // 循环
            if (this.activeIndex < 0) {
                this.activeIndex = items.length - 1;
            } else if (this.activeIndex >= items.length) {
                this.activeIndex = 0;
            }
            
            // 设置新激活项
            items[this.activeIndex].classList.add('ws-active');
            
            // 滚动到可见区域
            items[this.activeIndex].scrollIntoView({
                block: 'nearest'
            });
        }
        
        // 设置激活项
        setActiveItem(index) {
            const items = this.container.children;
            
            // 移除当前激活项
            if (this.activeIndex >= 0) {
                items[this.activeIndex].classList.remove('ws-active');
            }
            
            // 设置新激活项
            this.activeIndex = index;
            items[this.activeIndex].classList.add('ws-active');
        }
        
        // 选择建议
        selectSuggestion(index) {
            const item = this.suggestions[index];
            const value = this.valueOf(item);

            this.input.value = value;
            this.close();

            // 写值后必须通知表单：早前只改 input.value、不派发事件，
            // 导致 ws-form-validator 的实时校验（绑 input/change）永远收不到变化。
            this.input.dispatchEvent(new Event('input', { bubbles: true }));
            this.input.dispatchEvent(new Event('change', { bubbles: true }));

            if (this.options.onSelect) {
                this.options.onSelect(value, index, item);
            }
        }
        
        // 更新数据
        updateData(newData) {
            this.options.data = newData;
        }
        
        // 更新URL
        updateUrl(newUrl) {
            this.options.url = newUrl;
        }
        
        // 转义正则表达式特殊字符
        escapeRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        // 销毁实例
        destroy() {
            this.close();
            if (this.container && this.container.parentNode) {
                this.container.parentNode.removeChild(this.container);
            }

            // 移除 document 点击事件监听器
            if (this._outsideClickHandler) {
                document.removeEventListener('click', this._outsideClickHandler);
                this._outsideClickHandler = null;
            }

            // 移除窗口事件监听器
            window.removeEventListener('resize', this.resizeHandler);
            window.removeEventListener('scroll', this.scrollHandler, true);
        }
    }
    
// 全局挂载：统一使用 Ws* 标准名 window.WsHintInput；HintInput 为历史别名，兼容存量调用零改造。
window.WsHintInput = HintInput;
window.HintInput = HintInput;

/* ============================================================
 * 声明式接入（data-role="wshint"）
 * ============================================================ */

/**
 * 扫描并初始化 `[data-role="wshint"]`。
 *
 * 宿主就是 `<input type="text">` 自身：`name` 天然参与 `serialize()`，
 * 因此 hint-input **不需要值载体**，是 7 个组件里除 date-picker 外唯一零 carrier 的。
 *
 * 支持的数据源二选一：
 * - `data-options='["张三","李四"]'` / `data-source` / `data-data` → 本地数组
 * - `data-url="/admin/user/suggest"` + `data-params` + `data-response-path` → 远程
 *
 * @param {*} [root] document / 元素 / 选择器
 * @returns {number} 实际初始化数量
 */
HintInput.autoInitialize = function(root) {
    let count = 0;

    for (const scope of toElements(root || document)) {
        const list = [];
        if (scope.nodeType === 1 && scope.matches && scope.matches('[data-role="wshint"]')) list.push(scope);
        if (scope.querySelectorAll) list.push(...scope.querySelectorAll('[data-role="wshint"]'));

        for (const input of list) {
            if (input._wsHintInput) continue;

            const options = parseDataConfig(input, HINT_SCHEMA);

            // 远程地址别名：data-url / data-remote-url / data-data-url
            if (!options.url) {
                options.url = pickData(input, ['remoteUrl', 'dataUrl', 'sourceUrl'], T.RAW) || null;
            }

            // 本地数据别名：data-options / data-source / data-data
            const local = pickData(input, ['options', 'source', 'data'], T.JSON);
            if (local) options.data = local;

            try {
                input._wsHintInput = new HintInput(input, options);
                count++;
            } catch (err) {
                console.error('[ws-hint-input] 初始化失败', input, err);
            }
        }
    }

    return count;
};

// jQuery 插件封装：与 ws-select / ws-date-picker 保持同一调用习惯
if (window.jQuery) {
    window.jQuery.fn.wsHintInput = function(options) {
        return this.each(function() {
            if (!this._wsHintInput) this._wsHintInput = new HintInput(this, options || {});
        });
    };
}

// 页面就绪后自动初始化（幂等；弹窗等动态节点需由调用方传入 root 补挂）
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        HintInput.autoInitialize(document);
    });
} else {
    HintInput.autoInitialize(document);
}

export default HintInput;