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
        onSelect: null,        // 选择建议时的回调
        onFetchStart: null,    // 开始获取数据时的回调
        onFetchEnd: null,      // 结束获取数据时的回调
        onError: null          // 错误处理回调
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

            // 构建URL
            const url = this.buildUrl(query);
            
            // 使用fetch获取数据
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // 假设返回的是数组，如果不是可以在这里处理
                    const suggestions = Array.isArray(data) ? 
                        data.slice(0, this.options.maxItems) : 
                        this.processRemoteData(data);
                    
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
                
                // 高亮匹配部分
                if (this.options.highlightMatches) {
                    const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
                    const highlighted = query?.trim()?.length > 0 ? suggestion.replace(regex, '<span class="ws-hintinput-highlight">$1</span>') : suggestion;
                    item.innerHTML = highlighted;
                } else {
                    item.textContent = suggestion;
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
            const value = this.suggestions[index];
            this.input.value = value;
            this.close();
            
            if (this.options.onSelect) {
                this.options.onSelect(value, index);
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

export default HintInput;