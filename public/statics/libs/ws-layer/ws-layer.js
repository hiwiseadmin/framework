/**
 * WsLayer - 基于 Bootstrap 5 的弹层组件
 * @version 1.0.0
 * @author Wise Admin
 * @license MIT
 */

// 样式：ws-layer.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-layer/ws-layer.css">

// jQuery 走全局单例：本组件是 jQuery 插件，若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
if (typeof $ === 'undefined') {
    throw new Error('WsLayer requires jQuery');
}

    /**
     * 弹层配置选项
     * @typedef {Object} LayerOptions
     * @property {string} [frameName=''] 框架名称
     * @property {number} [type=0] 弹层类型: 0-对话框, 1-页面层, 2-iframe层, 3-loading加载层, 4-tips提示层
     * @property {string|boolean} [title='信息'] 标题
     * @property {string|Array} [content=''] 内容，支持 HTML 和 url，iframe 类型支持 [url, 'auto|no']
     * @property {string} [skin=''] 皮肤样式
     * @property {string|Array} [area='auto'] 宽高 [width, height]
     * @property {string|Array} [offset='auto'] 位置
     * @property {number|string} [icon=-1] 图标类型 -1:无, 0:信息, 1:成功, 2:警告, 3:错误, 4:问号, 5:锁, 6:哭脸, 7:笑脸
     * @property {string|Array|boolean} [btn=['确定']] 按钮
     * @property {boolean} [closeBtn=true] 关闭按钮
     * @property {boolean} [minBtn=false] 最小化按钮
     * @property {boolean} [maxBtn=false] 最大化按钮
     * @property {boolean|number} [shade=true] 遮罩
     * @property {boolean} [shadeClose=false] 点击遮罩关闭
     * @property {number} [time=0] 自动关闭时间(毫秒)
     * @property {string|number} [id=null] 弹层 ID
     * @property {boolean} [anim=true] 动画
     * @property {boolean} [isOutAnim=true] 退出动画
     * @property {boolean} [fixed=true] 固定定位
     * @property {boolean} [resize=false] 允许调整大小
     * @property {boolean} [scrollbar=true] 允许滚动条
     * @property {number} [maxWidth=860] 最大宽度
     * @property {number} [maxHeight=0] 最大高度
     * @property {number} [zIndex=1060] 层级
     * @property {boolean} [onlyOne=true] 只能显示一个弹层
     * @property {boolean} [minStack=true] 最小化堆叠
     * @property {boolean} [move=true] 允许拖拽
     * @property {number} [moveType=1] 拖拽类型
     * @property {boolean} [moveOut=false] 允许拖出窗口
     * @property {number} [tips=1] 提示方向 1:上, 2:右, 3:下, 4:左
     * @property {boolean} [btnAsync=false] 异步按钮
     * @property {boolean} [tipsMore=false] 允许多个提示
     * @property {boolean} [closeOnEsc=true] ESC 键关闭
     * @property {boolean} [submitOnEnter=true] Enter 键提交
     * @property {boolean} [submitOnEnterInInput=true] 在输入框内也允许 Enter 提交
     * @property {string} [loadingAnimation='ws-layer-spin'] 默认加载动画
     * @property {boolean} [autoUpdateTitle=false] 自动更新 iframe 标题
     * @property {string} [loadingIconSize='fs-4'] 加载图标大小
     * @property {string} [loadingTextColor='text-white'] 加载文字颜色
     * @property {function} [success] 创建成功回调
     * @property {function} [yes] 确定按钮回调
     * @property {function} [cancel] 取消按钮回调
     * @property {function} [end] 关闭后回调
     * @property {function} [full] 最大化回调
     * @property {function} [restore] 还原回调
     * @property {function} [minEnd] 最小化回调
     * @property {function} [moveEnd] 拖拽结束回调
     * @property {function} [resizing] 调整大小回调
     * @property {function} [iframeTitle] iframe 标题回调函数
     * @property {function} [iframeLoad] iframe 加载完成回调
     * @property {function} [iframeLoadError] iframe 加载错误回调
     */

    // 组件配置
    const WsLayer = {
        // 版本号
        version: '1.0.0',
        // 默认配置
        DEFAULT_CONFIG: {
            frameName : '',             // 框架名称
            type: 0,                    // 弹层类型: 0-对话框, 1-页面层, 2-iframe层, 3-loading加载层, 4-tips提示层
            title: '信息',              // 标题
            content: '',                // 内容，支持HTML 和 url ,iframe类型支持 [url, 'auto|no']
            skin: '',                   // 皮肤样式
            area: 'auto',               // 宽高 [width, height]
            offset: 'auto',             // 位置
            icon: -1,                   // 图标类型 -1: 无, 0: 信息, 1: 成功, 2: 警告, 3: 错误, 4: 问号, 5: 锁, 6: 哭脸, 7: 笑脸
            btn: ['确定'],              // 按钮
            closeBtn: true,             // 关闭按钮
            minBtn: false,              // 最小化按钮
            maxBtn: false,              // 最大化按钮
            shade: true,                // 遮罩
            shadeClose: false,          // 点击遮罩关闭
            time: 0,                    // 自动关闭时间(毫秒)
            id: null,                   // 弹层ID
            anim: true,                 // 动画 
            isOutAnim: true,            // 退出动画
            fixed: true,                // 固定定位
            resize: false,              // 允许调整大小
            scrollbar: true,            // 允许滚动条
            maxWidth: 860,              // 最大宽度
            maxHeight: 0,               // 最大高度
            zIndex: 1060,               // 层级
            onlyOne: true,              // 只能显示一个弹层
            minStack: true,             // 最小化堆叠
            move: true,                 // 允许拖拽
            moveType: 1,                // 拖拽类型
            moveOut: false,             // 允许拖出窗口
            tips: 1,                    // 提示方向 1:上, 2:右, 3:下, 4:左
            // 事件回调
            success: null,              // 创建成功回调
            yes: null,                  // 确定按钮回调
            cancel: null,               // 取消按钮回调
            end: null,                  // 关闭后回调
            full: null,                 // 最大化回调
            restore: null,              // 还原回调
            minEnd: null,               // 最小化回调
            moveEnd: null,              // 拖拽结束回调
            resizing: null,             // 调整大小回调
            // 按钮配置
            btnAsync: false,            // 异步按钮
            tipsMore: false,            // 允许多个提示
            closeOnEsc: true,           // ESC 键关闭
            submitOnEnter: true,        // Enter 键提交
            submitOnEnterInInput: true,  // 在输入框内也允许 Enter 提交
            loadingAnimation: 'ws-layer-spin', // 默认加载动画
            // iframe 配置
            autoUpdateTitle: false,      // 自动更新 iframe 标题
            iframeTitle: null,          // iframe 标题回调函数
            iframeLoad: null,           // iframe 加载完成回调
            iframeLoadError: null,       // iframe 加载错误回调
            // loading 层配置
            loadingIconSize: 'fs-4',     // 加载图标大小
            loadingTextColor: 'text-white', // 加载文字颜色
        },
        
        // 当前打开的弹层索引
        index: 0,
        
        // 共享数据存储
        sharedData: {},
        
        // 弹层实例存储
        instances: [],
        
        // 最小化堆叠数组
        minStackArr: [],
        
        // 国际化配置
        i18n: {
            'zh-CN': {
                confirm: '确定',
                cancel: '取消',
                defaultTitle: '信息',
                prompt: {
                    InputLengthPrompt: '最多输入 {length} 个字符'
                },
                photos: {
                    noData: '没有图片',
                    tools: {
                        rotate: '旋转',
                        scaleX: '水平变换',
                        zoomIn: '放大',
                        zoomOut: '缩小',
                        reset: '还原',
                        close: '关闭'
                    },
                    viewPicture: '查看原图',
                    urlError: {
                        prompt: '当前图片地址异常，\n是否继续查看下一张？',
                        confirm: '下一张',
                        cancel: '不看了'
                    }
                }
            }
        },
        
        // 当前语言
        lang: 'zh-CN',

        /**
         * 初始化组件
         */
        init: function() {
            this.createContainer();
            this.injectStyles();
        },
        
        /**
         * 创建容器
         */
        createContainer: function() {
            // 创建遮罩容器
            if (!$('#ws-layer-shade-container').length) {
                $('body').append('<div id="ws-layer-shade-container"></div>');
            }
            
            // 创建弹层容器
            if (!$('#ws-layer-container').length) {
                $('body').append('<div id="ws-layer-container"></div>');
            }
            
            // 创建 Toast 容器
            if (!$('#ws-toast-container').length) {
                $('body').append('<div id="ws-toast-container" class="ws-toast-container"></div>');
            }
        },
        
        /**
         * 注入样式
         * 已改由 statics/modules/ws/ws-components.css 统一引入（见文件顶部说明）。
         * 保留空实现：内部存在 injectStyles() 调用点，直接删除会抛 ReferenceError。
         */
        injectStyles: function() {
            // no-op：样式已随 bundle 加载
        },
        
        /**
         * 顶层窗口链式调用函数
         * @param {number|string} [level] - 指定层级：
         *   不传：按原逻辑，在 iframe 中且顶层可访问则使用顶层，否则当前窗口；
         *   0 或 'self'：强制当前窗口；
         *   1 或 'parent'：强制父窗口；
         *   2 及以上：逐级向上访问。
         * @returns {Object} 链式调用对象
         */
        top: function(level) {
            const self = this;
            let targetWin = window;

            // 确定目标窗口
            if (level === undefined) {
                // 在 iframe 中且顶层可访问则用顶层，否则当前
                if (self.isInIframe() && self.hasLayerInTopWindow()) {
                    targetWin = window.top;
                }
            } else {
                // 转换为数字层级
                let lvl = 0;
                if (typeof level === 'string') {
                    if (level.toLowerCase() === 'self') {lvl = 0;}
                    else if (level.toLowerCase() === 'parent') {lvl = 1;}
                    else {lvl = parseInt(level, 10) || 0;}
                } else {
                    lvl = parseInt(level, 10) || 0;
                }

                // 逐级向上找窗口
                while (lvl > 0 && targetWin !== targetWin.top) {
                    try {
                        targetWin = targetWin.parent;
                        // 如果父窗口可访问，减少层数
                        if (targetWin) {lvl--;} 
                        else {break;}
                    } catch (e) {
                        console.warn('访问父窗口失败（可能跨域）:', e);
                        break;
                    }
                }
            }
            // 创建链式调用对象
            const chain = {
                /**
                 * 执行顶层窗口的方法
                 * @param {String} method 方法名
                 * @param {Array} args 参数数组
                 * @returns {*} 方法返回值
                 */
                execute: function(method, args) {
                    try {
                        // 优先尝试目标窗口的 wsLayer
                        if (targetWin.wsLayer && typeof targetWin.wsLayer[method] === 'function') {
                            return targetWin.wsLayer[method].apply(targetWin.wsLayer, args);
                        }
                    } catch (e) {
                        console.error(`在目标窗口执行 ${method} 失败:`, e);
                    }
                    // 降级到当前窗口执行
                    return self[method].apply(self, args);
                }
            };
            
            // 一次性为所有公共方法创建链式调用
            const publicMethods = [
                // 核心方法
                'open', 'close', 'closeAll', 'closeLast', 'min', 'max', 'restore', 'title',
                // 快捷方法
                'iframe', 'alert', 'confirm', 'msg', 'load', 'tips', 'prompt', 'tab', 'photos', 'toast',
                // 工具方法
                'getChildFrame', 'getInstances', 'setup', 'setLang', 'addMessages',
                // 共享数据操作方法
                'setShared', 'getShared', 'hasShared', 'removeShared', 'clearShared'
            ];
            
            publicMethods.forEach(method => {
                if (typeof this[method] === 'function') {
                    chain[method] = function() {
                        return chain.execute(method, Array.prototype.slice.call(arguments));
                    };
                }
            });
            
            return chain;
        },

        /**
         * 判断当前页面是否在 iframe 中
         * @returns {Boolean}
         */
        isInIframe: function() {
            try {
                return window.self !== window.top;
            } catch (e) {
                return true;
            }
        },

        /**
         * 检查顶层页面是否有 layer 组件
         * @returns {Boolean}
         */
        hasLayerInTopWindow: function() {
            try {
                return !!(window.top && window.top.wsLayer);
            } catch (e) {
                // 跨域情况下无法访问顶层窗口
                return false;
            }
        },

        /**
         * 打开弹层
         * @param {LayerOptions} options 配置选项
         * @returns {Number} 弹层索引
         */
        open: function(options) {
            const config = $.extend(true, {}, this.DEFAULT_CONFIG, options);
            const index = ++this.index;
            
            // 如果onlyOne为true，关闭所有其他弹层
            if (config.onlyOne) {
                this.closeAll('dialog');
                this.closeAll('loading');
                this.closeAll('tips');
            }
            
            // 只有 iframe 类型弹层需要设置 window.name，避免破坏其他代码对 window.name 的依赖
            if (config.type === 2) {
                window.name = config.frameName || 'ws-layer-window-' + index;
            }
            // 创建弹层实例：必须先注册到 instances 再执行 create()，
            // 保证 success 回调执行时 WsLayer.instances[index] 已可用。
            const instance = new LayerInstance(index, config);
            this.instances[index] = instance;
            instance.create();
            
            return index;
        },
        
        /**
         * 关闭弹层
         * @param {Number} index 弹层索引
         * @param {String} exitType 退出类型 close|cancel
         */
        close: function(index, exitType = 'close') {
            if (this.instances[index]) {
                this.instances[index].close(exitType);
                this.instances[index] = null;
            }
        },
        
        /**
         * 关闭所有弹层
         * @param {String} type 弹层类型
         */
        closeAll: function(type) {
            let typeMap = {
                'dialog': 0,
                'page': 1,
                'iframe': 2,
                'loading': 3,
                'tips': 4
            }
            type = typeMap[type] || type;
            $.each(this.instances, function(index, instance) {
                if (instance && (!type || instance.config.type == type)) {
                    instance.close();
                }
            });
            // 清空实例引用，避免残留 null 条目累积
            if (!type) {
                this.instances = [];
                this.index = 0;
            }
        },
        
        /**
         * 关闭最近打开的弹层
         * @param {String} type 弹层类型
         */
        closeLast: function(type) {
            let maxIndex = 0;
            let targetIndex = 0;
            
            $.each(this.instances, function(index, instance) {
                if ((!type || (instance && instance.config.type == type)) && index > maxIndex) {
                    maxIndex = index;
                    targetIndex = index;
                }
            });
            
            if (targetIndex > 0) {
                this.close(targetIndex);
            }
        },
        
        /**
         * 警告框
         * @param {String} content 内容
         * @param {LayerOptions|Function} options 配置选项或回调函数
         * @param {Function} [yes] 确定回调
         * @returns {Number} 弹层索引
         */
        alert: function(content, options, yes) {
            const type = typeof options === 'function';
            if (type) {
                yes = options;
                options = {};
            }
            
            return this.open($.extend({
                content: content,
                btn: ['确定'],
                yes: yes
            }, type ? {} : options));
        },
        
        /**
         * 确认框
         * @param {String} content 内容
         * @param {LayerOptions|Function} options 配置选项或回调函数
         * @param {Function} [yes] 确定回调
         * @param {Function} [cancel] 取消回调
         * @returns {Number} 弹层索引
         */
        confirm: function(content, options, yes, cancel) {
            const type = typeof options === 'function';
            if (type) {
                cancel = yes;
                yes = options;
                options = {};
            }
            
            return this.open($.extend({
                content: content,
                btn: ['确定', '取消'],
                yes: yes,
                cancel: cancel
            }, type ? {} : options));
        },
        
        /**
         * iframe弹层
         * @param {String} url iframe地址
         * @param {LayerOptions|Function} options 配置选项或成功回调函数
         * @param {Function} [yes] 确定回调
         * @param {Function} [cancel] 取消回调
         * @returns {Number} 弹层索引
         */
        iframe: function(url, options, yes, cancel) {
            const type = typeof options === 'function';
            if (type) {
                cancel = yes;
                yes = options;
                options = {};
            }
            
            return this.open($.extend({
                type: 2,
                content: [url, 'auto'],
                title: '弹层',
                area: ['800px', '600px'],
                yes: yes,
                cancel: cancel
            }, type ? {} : options));
        },

        /**
         * loading弹层
         * @param {LayerOptions} [options] 配置选项
         * @returns {Number} 弹层索引
         */
        loading: function(options) {
            return this.open($.extend({
                type: 3,
                content: '加载中...',
                icon: -1,
            }, options));
        },

        /**
         * 消息提示
         * @param {String} content 内容
         * @param {LayerOptions|Function} options 配置选项或回调函数
         * @param {Function} [end] 关闭回调
         * @returns {Number} 弹层索引
         */
        msg: function(content, options, end) {
            const type = typeof options === 'function';
            if (type) {
                end = options;
                options = {};
            }
            
            return this.open($.extend({
                type: 4,
                content: content,
                time: 3000,
                shade: false,
                title: false,
                closeBtn: false,
                btn: false,
                anim: false,
                end: end
            }, type ? {} : options));
        },

        /**
         * 加载层
         * @param {Number|LayerOptions} [icon] 动画类型 0:默认旋转加载 1:沙漏加载 2:双环加载 3:脉冲加载 4:缩放旋转加载 5:弹跳加载；或直接传配置对象
         * @param {LayerOptions} [options] 配置选项
         * @returns {Number} 弹层索引
         */
        load: function(icon, options) {
            let config = {};
            
            // 处理参数重载
            if (typeof icon === 'object') {
                config = icon;
            } else {
                config = $.extend({
                    type: 3,
                    icon: icon || 0,
                    shade: 0.01,
                    title: false,
                    closeBtn: false
                }, options);
            }
            
            return this.open(config);
        },
        
        /**
         * 提示层
         * @param {String} content 内容
         * @param {String|Object} follow 跟随元素（选择器或 DOM 元素）
         * @param {LayerOptions} [options] 配置选项
         * @returns {Number} 弹层索引
         */
        tips: function(content, follow, options) {
            return this.open($.extend({
                type: 4,
                content: [content, follow],
                tips: 1,                    // 提示方向 1:上, 2:右, 3:下, 4:左
                time: 3000,
                shade: false,
                title: false,
                closeBtn: false,
                fixed: false,
                anim: false
            }, options));
        },
        
        /**
         * 输入框
         * @param {LayerOptions|Function} options 配置选项或确定回调
         * @param {Function} [yes] 确定回调
         * @returns {Number} 弹层索引
         */
        prompt: function(options, yes) {
            if (typeof options === 'function') {
                yes = options;
                options = {};
            }
            
            var self = this;
            
            const escapeHtml = WsLayer.escapeHtml;
            const content = options.formType === 'textarea' 
                ? `<textarea class="form-control" rows="4" placeholder="${escapeHtml(options.placeholder || '')}">${escapeHtml(options.value || '')}</textarea>`
                : `<input type="${escapeHtml(options.formType || 'text')}" class="form-control" placeholder="${escapeHtml(options.placeholder || '')}" value="${escapeHtml(options.value || '')}">`;
            
            return this.open($.extend({
                type: 1,
                title: options.title || '输入',
                content: content,
                btn: ['确定', '取消'],
                area: options.area || ['400px', 'auto'],
                success: function(layero) {
                    const input = layero.find('.form-control');
                    input.focus();
                    // 添加输入框的特殊事件处理，传入正确的 layero DOM
                    self.handlePromptEvents(layero);
                    if (options.success) options.success(layero);
                },
                yes: function(index, layero) {
                    const value = layero.find('.form-control').val();
                    if (yes) yes(value, index, layero);
                }
            }, options));
        },
        /**
         * 输入框弹层的特殊处理
         */
        handlePromptEvents($layer) {
            const self = this;
            const $input = $layer.find('.form-control');
            
            // 输入框的 Enter 键提交
            $input.on('keydown', function(e) {
                if (e.keyCode === 13 && self.DEFAULT_CONFIG.submitOnEnter && self.DEFAULT_CONFIG.submitOnEnterInInput) {
                    e.preventDefault();
                    const $confirmBtn = $layer.find('[data-layer-btn="0"]');
                    if ($confirmBtn.length && !$confirmBtn.prop('disabled')) {
                        $confirmBtn.trigger('click');
                    }
                }
            });
            
            // 自动聚焦
            setTimeout(() => {
                $input.focus();
                if ($input.is('input[type="text"], input[type="password"]')) {
                    $input.select();
                }
            }, 100);
        },
        /**
         * 标签页弹层
         * @param {LayerOptions} [options] 配置选项
         * @returns {Number} 弹层索引
         */
        tab: function(options) {
            options = options || {};
            const tab = options.tab || [];
            
            let tabHeaders = '';
            let tabContents = '';
            
            const escapeHtml = WsLayer.escapeHtml;
            tab.forEach((item, index) => {
                const activeClass = index === 0 ? 'active' : '';
                tabHeaders += `
                    <li class="nav-item">
                        <button class="nav-link ${activeClass}" data-bs-toggle="tab" 
                                data-bs-target="#tab-${index}" type="button">
                            ${escapeHtml(item.title)}
                        </button>
                    </li>
                `;
                
                tabContents += `
                    <div class="tab-pane fade ${activeClass ? 'show active' : ''}" id="tab-${index}">
                        ${escapeHtml(item.content || '')}
                    </div>
                `;
            });
            
            const content = `
                <ul class="nav nav-tabs mb-3">${tabHeaders}</ul>
                <div class="tab-content">${tabContents}</div>
            `;
            
            return this.open($.extend({
                type: 1,
                title: options.title || '标签页',
                content: content,
                area: options.area || ['600px', '400px'],
                success: function(layero) {
                    // 初始化 Bootstrap 标签页
                    const triggerTabList = layero.find('button[data-bs-toggle="tab"]');
                    triggerTabList.on('click', function (event) {
                        event.preventDefault();
                        const target = $(this).data('bs-target');
                        layero.find('.tab-pane').removeClass('show active');
                        layero.find(target).addClass('show active');
                        triggerTabList.removeClass('active');
                        $(this).addClass('active');
                    });
                    
                    if (options.success) options.success(layero);
                }
            }, options));
        },
        
        /**
         * 图片浏览器
         * @param {LayerOptions} [options] 配置选项
         * @returns {Number} 弹层索引
         */
        photos: function(options) {
            options = options || {};
            const photos = options.photos || {};
            const data = photos.data || [];
            const start = photos.start || 0;
            
            if (data.length === 0) {
                return this.msg(this.$t('photos.noData'));
            }
            
            const currentIndex = start;
            const currentPhoto = data[currentIndex];
            const escapeHtml = WsLayer.escapeHtml;

            const content = `
                <div class="ws-layer-photos-main">
                    <img src="${escapeHtml(currentPhoto.src)}" alt="${escapeHtml(currentPhoto.alt || '')}" 
                         style="max-width: 100%; max-height: 100%;">
                </div>
                <div class="ws-layer-photos-toolbar ws-layer-photos-header">
                    <button class="btn" data-photos-action="rotate" title="${this.$t('photos.tools.rotate')}">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button class="btn" data-photos-action="prev" ${data.length <= 1 ? 'disabled' : ''}>
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="mx-2">${currentIndex + 1} / ${data.length}</span>
                    <button class="btn" data-photos-action="next" ${data.length <= 1 ? 'disabled' : ''}>
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <button class="btn" data-photos-action="close" title="${this.$t('photos.tools.close')}">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="ws-layer-photos-toolbar ws-layer-photos-footer">
                    <div>${escapeHtml(currentPhoto.alt || '')}</div>
                    <a href="${escapeHtml(currentPhoto.src)}" target="_blank" class="btn btn-sm btn-link text-white">
                        ${this.$t('photos.viewPicture')}
                    </a>
                </div>
            `;
            
            const instanceIndex = this.open($.extend({
                type: 1,
                skin: 'ws-layer-photos',
                content: content,
                title: false,
                closeBtn: false,
                shade: true,
                shadeClose: true,
                success: function(layero, index, layerInstance) {
                    // 优先使用回调传入的实例，其次回退到注册表（保证旧调用方式同样可用）
                    const instance = layerInstance || WsLayer.instances[index];
                    if (!instance) return;

                    instance.photosData = {
                        data: data,
                        currentIndex: currentIndex,
                        rotate: 0
                    };

                    // 图片层需要随内容尺寸变化持续校正位置，详见 _schedulePhotosFit()。
                    const $photosImg = layero.find('img');
                    // 若图片在绑定 load 之前已加载完成（命中缓存），load 事件不会再次触发，
                    // 此时必须主动启动一次校正，否则弹层会按加载前的高度定位而溢出视口。
                    $photosImg.on('load', function() {
                        instance._schedulePhotosFit();
                    });
                    instance._schedulePhotosFit();
                    if ($photosImg.length && $photosImg[0].complete) {
                        instance._schedulePhotosFit();
                    }
                    const onViewportChange = function() {
                        instance._schedulePhotosFit();
                    };
                    $(window).on('resize.layerPhotos', onViewportChange);

                    // 绑定图片浏览器事件
                    layero.find('[data-photos-action]').on('click', function() {
                        const action = $(this).data('photos-action');
                        instance.handlePhotosAction(action);
                    });
                    
                    // 键盘事件
                    $(document).on('keyup.layerPhotos', function(e) {
                        if (e.keyCode === 27) { // ESC
                            WsLayer.close(index);
                        } else if (e.keyCode === 37) { // 左箭头
                            instance.handlePhotosAction('prev');
                        } else if (e.keyCode === 39) { // 右箭头
                            instance.handlePhotosAction('next');
                        }
                    });
                    
                    if (options.success) options.success(layero);
                },
                end: function() {
                    $(document).off('keyup.layerPhotos');
                    $(window).off('resize.layerPhotos');
                    // 停止位置轮询定时器
                    const inst = WsLayer.instances[instanceIndex];
                    if (inst && inst._stopPhotosAutoFit) {
                        inst._stopPhotosAutoFit();
                        inst._stopPhotosAutoFit = null;
                    }
                }
            }, options));
            
            return instanceIndex;
        },
        
        /**
         * Toast 通知
         * @param {Object|string} options 配置选项或提示内容字符串
         * @returns {String} Toast 实例 ID
         */
        toast: function(options) {
            if (typeof options === 'string') {
                options = { content: options };
            }
            
            const config = $.extend({
                content: '',
                type: 'info', // info, success, warning, error
                time: 3000,
                position: 'top-end'
            }, options);
            
            // 创建 Toast HTML
            const toastId = 'toast-' + Date.now();
            const iconClass = this.getToastIconClass(config.type);
            const bgClass = this.getToastBgClass(config.type);
            const escapeHtml = WsLayer.escapeHtml;
            
            const toastHtml = `
                <div id="${toastId}" class="ws-toast toast show" role="alert">
                    <div class="toast-header ${bgClass} text-white">
                        <i class="bi ${iconClass} me-2"></i>
                        <strong class="me-auto">${escapeHtml(this.getToastTitle(config.type))}</strong>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">
                        ${escapeHtml(config.content)}
                    </div>
                </div>
            `;
            
            // 添加到容器
            $('#ws-toast-container').append(toastHtml);
            
            // 自动关闭（使用闭包变量防止重复触发 end 回调）
            let ended = false;
            const tryEnd = function() {
                if (!ended && typeof config.end === 'function') {
                    ended = true;
                    config.end();
                }
            };
            
            if (config.time > 0) {
                setTimeout(function() {
                    $(`#${toastId}`).remove();
                    tryEnd();
                }, config.time);
            }
            
            // 绑定关闭事件
            $(`#${toastId} .btn-close`).on('click', function() {
                $(`#${toastId}`).remove();
                tryEnd();
            });
            
            return toastId;
        },
        
        /**
         * 最小化弹层
         * @param {Number} index 弹层索引
         */
        min: function(index) {
            if (this.instances[index]) {
                this.instances[index].minimize();
            }
        },
        
        /**
         * 最大化弹层
         * @param {Number} index 弹层索引
         */
        max: function(index) {
            if (this.instances[index]) {
                this.instances[index].maximize();
            }
        },
        
        /**
         * 还原弹层
         * @param {Number} index 弹层索引
         */
        restore: function(index) {
            if (this.instances[index]) {
                this.instances[index].restore();
            }
        },
        
        /**
         * 动态设置标题
         * @param {String} title 标题
         * @param {Number} index 弹层索引
         */
        title: function(title, index) {
            if (this.instances[index]) {
                this.instances[index].setTitle(title);
            }
        },
        
        /**
         * 获取子iframe的DOM
         * @param {String} selector 选择器
         * @param {Number} index 弹层索引
         */
        getChildFrame: function(selector, index) {
            if (this.instances[index] && this.instances[index].config.type === 2) {
                return $(`#ws-layer-iframe-${index}`).contents().find(selector);
            }
            return $();
        },
        
        /**
         * 获取 Toast 图标类名
         * @param {String} type 图标类型
         * @returns {String} 图标类名
         */
        getToastIconClass: function(type) {
            const iconMap = {
                info: 'bi-info-circle',
                success: 'bi-check-circle',
                warning: 'bi-exclamation-triangle',
                error: 'bi-x-circle',
                danger: 'bi-x-circle',
            };
            return iconMap[type] || 'bi-info-circle';
        },
        
        /**
         * 获取 Toast 背景类名
         * @param {String} type 类型
         * @returns {String} 背景类名
         */
        getToastBgClass: function(type) {
            const bgMap = {
                info: 'bg-info',
                success: 'bg-success',
                warning: 'bg-warning',
                error: 'bg-danger',
                danger: 'bg-danger'
            };
            return bgMap[type] || 'bg-info';
        },
        
        /**
         * 获取 Toast 标题
         * @param {String} type 类型
         * @returns {String} 标题
         */
        getToastTitle: function(type) {
            const titleMap = {
                info: '信息',
                success: '成功',
                warning: '警告',
                error: '错误',
                danger: '错误'
            };
            return titleMap[type] || '信息';
        },
        
/**
         * 获取iframe层的索引
         * @param {String} windowName 窗口名称，通常是 window.name
         * @returns {Number} 弹层索引，未找到返回 -1
         */
        getFrameIndex: function(windowName) {
            if (!windowName) {
                console.warn('getFrameIndex: windowName 参数不能为空');
                return -1;
            }
            
            // 遍历所有弹层实例，查找匹配的iframe窗口
            for (const index in this.instances) {
                const instance = this.instances[index];
                
                // 只处理iframe类型的弹层
                if (instance && instance.config.type === 2) {
                    try {
                        const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                        if (iframe && iframe.contentWindow) {
                            // 检查iframe的window.name是否匹配
                            if (iframe.contentWindow.name === windowName) {
                                return parseInt(index);
                            }
                        }
                    } catch (error) {
                        // 跨域访问时会抛出错误，忽略这些实例
                        console.warn('跨域iframe，无法访问内容:', error.message);
                        continue;
                    }
                }
            }
            
            console.warn(`getFrameIndex: 未找到匹配的iframe弹层，windowName: ${windowName}`);
            return -1;
        },
        
        /**
         * 获取当前iframe的父层索引（在iframe内部调用）
         * @returns {Number} 父层索引，未找到返回 -1
         */
        getParentFrameIndex: function() {
            if (window === window.top) {
                console.warn('getParentFrameIndex: 当前窗口不是iframe');
                return -1;
            }
            
            try {
                // 先测试是否能访问父窗口文档，避免跨域异常
                if (!parent.document) {
                    console.warn('getParentFrameIndex: 无法访问父窗口文档（可能跨域）');
                    return -1;
                }
                // 获取当前iframe元素
                const iframes = parent.document.getElementsByTagName('iframe');
                let currentIframe = null;
                // 查找包含当前窗口的iframe
                for (let i = 0; i < iframes.length; i++) {
                    try {
                        if (iframes[i].contentWindow === window) {
                            currentIframe = iframes[i];
                            break;
                        }
                    } catch (error) {
                        // 跨域情况下无法访问contentWindow
                        console.warn('跨域iframe，无法访问内容:', error.message)
                        continue;
                    }
                }
                if (currentIframe) {
                    // 从iframe的id中提取索引
                    const id = currentIframe.id;
                    const match = id.match(/ws-layer-iframe-(\d+)/);
                    if (match && match[1]) {
                        return parseInt(match[1]);
                    }
                }
            } catch (error) {
                console.error('getParentFrameIndex: 获取父层索引失败', error);
            }
            
            return -1;
        },
        
        /**
         * 关闭当前iframe所在的弹层（在iframe内部调用）
         * @param {Object} data 关闭时传递的数据（可选）
         */
        closeFrame: function(data) {
            const frameIndex = this.getParentFrameIndex();

            if (frameIndex !== -1) {
                // 如果有数据，可以存储在实例中供父窗口使用
                if (data !== undefined && this.instances[frameIndex]) {
                    this.instances[frameIndex].closeData = data;
                }
                this.close(frameIndex);
            } else {
                console.warn('closeFrame: 无法找到当前iframe对应的弹层');
            }
        },
        
        /**
         * 从iframe内部调用父窗口的方法
         * @param {String} method 方法名
         * @param {...any} args 参数
         */
        callParent: function(method, ...args) {
            try {
                if (window.parent && window.parent.wsLayer) {
                    return window.parent.wsLayer[method](...args);
                } else {
                    console.error('callParent: 父窗口未找到wsLayer对象');
                }
            } catch (error) {
                console.error('callParent: 调用父窗口方法失败', error);
            }
        },

        /**
         * 国际化翻译
         * @param {String} key 翻译键
         * @param {Object} options 替换参数
         * @returns {String} 翻译后的文本
         */
        $t: function(key, options) {
            const keys = key.split('.');
            let result = this.i18n[this.lang];
            
            for (let i = 0; i < keys.length; i++) {
                if (result === undefined || result === null) {
                    return key;
                }
                // 如果已到达叶子节点（非对象），但还有更多 key 层级，返回当前找到的值
                if (typeof result !== 'object') {
                    return result || key;
                }
                result = result[keys[i]];
            }
            
            if (result === undefined || result === null) {
                return key;
            }
            
            if (typeof result === 'string' && options) {
                return result.replace(/\{(\w+)\}/g, function(match, k) {
                    return options[k] !== undefined ? options[k] : match;
                });
            }
            
            return result || key;
        },
        
        /**
         * 设置语言
         * @param {String} lang 语言代码
         */
        setLang: function(lang) {
            if (this.i18n[lang]) {
                this.lang = lang;
            }
        },
        
        /**
         * 添加语言包
         * @param {String} lang 语言代码
         * @param {Object} messages 语言消息
         */
        addMessages: function(lang, messages) {
            this.i18n[lang] = $.extend(true, this.i18n[lang] || {}, messages);
        },
        
        /**
         * 设置全局配置
         * @param {Object} options 配置选项
         */
        config: function(options) {
            $.extend(true, this.DEFAULT_CONFIG, options);
        },
        
        /**
         * 获取当前所有弹层
         * @returns {Object} 弹层实例对象
         */
        getInstances: function() {
            return this.instances;
        },

        /**
         * 设置共享数据
         * @param {String|Object} key 键名或键值对象
         * @param {*} value 值（当key为字符串时）
         * @returns {Object} 返回当前实例，支持链式调用
         */
        setShared: function(key, value) {
            if (typeof key === 'object' && key !== null) {
                $.extend(this.sharedData, key);
            } else if (typeof key === 'string') {
                this.sharedData[key] = value;
            }
            return this;
        },

        /**
         * 获取共享数据
         * @param {String} key 键名，不传则返回所有数据的浅拷贝
         * @returns {*} 对应的值，或所有数据的浅拷贝
         */
        getShared: function(key) {
            if (key === undefined) {
                return $.extend({}, this.sharedData);
            }
            return this.sharedData[key];
        },

        /**
         * 检查共享数据是否存在
         * @param {String} key 键名
         * @returns {Boolean}
         */
        hasShared: function(key) {
            return this.sharedData.hasOwnProperty(key);
        },

        /**
         * 删除共享数据
         * @param {String} key 键名
         * @returns {Object} 返回当前实例
         */
        removeShared: function(key) {
            delete this.sharedData[key];
            return this;
        },

        /**
         * 清空所有共享数据
         * @returns {Object} 返回当前实例
         */
        clearShared: function() {
            // 清空对象本身，保持引用不变
            for (let key in this.sharedData) {
                if (this.sharedData.hasOwnProperty(key)) {
                    delete this.sharedData[key];
                }
            }
            return this;
        },

        /**
         * HTML 转义，防止 XSS 注入
         * @param {String} str 待转义字符串
         * @returns {String} 转义后的安全字符串
         */
        escapeHtml: function(str) {
            if (typeof str !== 'string') return '';
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };
    
    /**
     * =======================  弹层实例类  ============================
     */
    class LayerInstance {
        constructor(index, config) {
            this.index = index;
            this.config = config;
            this.isMinimized = false;
            this.isMaximized = false;
            // 常规（非最大化）几何快照：min/max/restore 反复切换时不会丢失普通位置
            this._normalStyle = null;
            // 最小化前所处的窗口状态：'normal' | 'maximized'（用于最小化还原时回到原状态）
            this._stateBeforeMin = null;
            // 注意：create() 不再由构造函数触发。
            // create() 内部会同步执行 success 回调，若在此处调用，
            // WsLayer.instances[index] 尚未完成赋值，回调中拿到的实例是 undefined
            // （典型症状：photos() 抛 "Cannot set properties of undefined (setting 'photosData')"）。
            // 现改由 WsLayer.open() 在完成实例注册后再调用 create()。
        }
        
        /**
         * 创建弹层
         */
        create() {
            this.createShade();
            this.createLayer();
            this.bindEvents();
            this.autoClose();
            
            // 执行成功回调
            if (this.config.success) {
                this.config.success(this.$layer, this.index, this);
            }
        }
        
        /**
         * 创建遮罩
         */
        createShade() {
            if (this.config.shade) {
                const shadeHtml = `
                    <div class="ws-layer-shade" id="ws-layer-shade-${this.index}"></div>
                `;
                $('#ws-layer-shade-container').append(shadeHtml);
                this.$shade = $(`#ws-layer-shade-${this.index}`);
                this.$shade.show();
            }
        }
        
        /**
         * 创建弹层主体
         */
        createLayer() {
            const config = this.config;
            const layerHtml = this.getLayerHtml();
            $('#ws-layer-container').append(layerHtml);
            this.$layer = $(`#ws-layer-${this.index}`);
            
            // 先隐藏弹层，使用 visibility 而不是 opacity
            this.$layer.css({
                'visibility': 'hidden',
                'display': 'flex'
            });
            
            // 设置位置和尺寸
            this.setSize();
            this.setPosition();

            // 绑定键盘事件
            this.bindKeyboardEvents();

            // 如果是提示层，显示提示内容
            if (config.type === 4) {
                this.showTips();
            }
            // 如果是 iframe 弹层，绑定 iframe 事件
            if (config.type === 2) {
                this.bindIframeEvents();
            }
            this.$layer.css('visibility', 'visible').addClass('ws-layer-fade-in');

            // 弹层显示后自动聚焦到默认按钮
            this.autoFocusDefaultButton();
        }

        /*
        * 自动聚焦到默认按钮
        */
        autoFocusDefaultButton() {
            const config = this.config;

            // 如果是加载层或提示层，不进行聚焦
            if (config.type === 3 || config.type === 4) return;
            
            // 如果有按钮，聚焦到第一个按钮（通常是确定按钮）
            const $firstBtn = this.$layer.find('[data-layer-btn="0"]');
            if ($firstBtn.length) {
                setTimeout(() => {
                    $firstBtn.focus();
                }, 100);
            }
        }

        /**
         * 获取弹层 HTML
         * @returns {String} HTML 字符串
         */
        getLayerHtml() {
            const config = this.config;
            let html = '';
            
            switch (config.type) {
                case 0: // 对话框
                case 1: // 页面层
                    html = this.getDialogHtml();
                    break;
                case 2: // iframe层
                    html = this.getIframeHtml();
                    break;
                case 3: // 加载层
                    html = this.getLoadingHtml();
                    break;
                case 4: // 提示层
                    html = this.getTipsHtml();
                    break;
            }
            
            return html;
        }
        
        /**
         * 获取对话框 HTML
         * @returns {String} HTML 字符串
         */
        getDialogHtml() {
            const config = this.config;
            const iconHtml = config.icon !== -1 ? this.getDialogIconHtml() : '';
            const closeBtnHtml = config.closeBtn ? '<button type="button" class="btn btn-close" data-layer-close></button>' : '';
            const btnHtml = config.btn ? this.getBtnHtml() : '';
            const resizeHtml = config.resize ? '<div class="ws-layer-resize"></div>' : '';

            return `
                <div class="ws-layer ws-layer-dialog ${config.skin}" id="ws-layer-${this.index}" tabindex="-1">
                    <div class="ws-layer-header">
                        <h5 class="mb-0">${iconHtml}${config.title}</h5>
                        <div>
                            ${config.minBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-min><i class="bi bi-dash"></i></button>' : ''}
                            ${config.maxBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-max><i class="bi bi-square"></i></button>' : ''}
                            ${closeBtnHtml}
                        </div>
                    </div>
                    <div class="ws-layer-body">
                        ${config.content}
                    </div>
                    ${btnHtml}
                    ${resizeHtml}
                </div>
            `;
        }
        
        /**
         * 获取iframe层 HTML
         * @returns {String} HTML 字符串
         */
        getIframeHtml() {
            const config = this.config;
            const content = typeof config.content === 'string' ? [config.content, 'auto'] : config.content;
            const closeBtnHtml = config.closeBtn ? '<button type="button" class="btn-close" data-layer-close></button>' : '';
            const btnHtml = config.btn ? this.getBtnHtml() : '';
            const resizeHtml = config.resize ? '<div class="ws-layer-resize"></div>' : '';
            
            return `
                <div class="ws-layer ws-layer-iframe border shadow ${config.skin}" id="ws-layer-${this.index}">
                    <div class="ws-layer-header">
                        <h5 class="mb-0">${config.title}</h5>
                        <div>
                            ${config.minBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-min><i class="bi bi-dash"></i></button>' : ''}
                            ${config.maxBtn ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-layer-max><i class="bi bi-square"></i></button>' : ''}
                            ${closeBtnHtml}
                        </div>
                    </div>
                    <div class="ws-layer-body p-0">
                        <iframe id="ws-layer-iframe-${this.index}" 
                                name="ws-layer-iframe-${this.index}"
                                src="${content[0]}" 
                                frameborder="0" 
                                style="width:100%;height:100%;"
                                scrolling="${content[1] || 'auto'}">
                        </iframe>
                    </div>
                    ${btnHtml}
                    ${resizeHtml}
                </div>
            `;
        }
        
        /**
         * 获取加载层 HTML
         * @returns {String} HTML 字符串
         */
        getLoadingHtml() {
            const config = this.config;
            let iconHtml = '';
            let animationClass = '';

            // 根据 icon 类型选择不同的动画效果
            switch (config.icon) {
                case 0: // 默认旋转加载
                    iconHtml = `<i class="bi bi-arrow-clockwise ws-layer-loading-icon ${config.loadingAnimation || 'ws-layer-spin'}"></i>`;
                    animationClass = 'ws-layer-spin';
                    break;
                    
                case 1: // 沙漏加载
                    iconHtml = `<i class="bi bi-hourglass-split ws-layer-loading-icon"></i>`;
                    animationClass = 'ws-layer-breathe';
                    break;
                    
                case 2: // 双环加载
                    return `
                        <div class="ws-layer ws-layer-loading" id="ws-layer-${this.index}">
                            <div class="ws-layer-body">
                                <div class="ws-layer-loading-2"></div>
                                <div class="ws-layer-loading-text">${config.content || '加载中...'}</div>
                            </div>
                        </div>
                    `;
                    
                case 3: // 脉冲加载
                    iconHtml = `<i class="bi bi-circle ws-layer-loading-icon"></i>`;
                    animationClass = 'ws-layer-pulse';
                    break;
                    
                case 4: // 缩放旋转加载
                    iconHtml = `<i class="bi bi-arrow-repeat ws-layer-loading-icon"></i>`;
                    animationClass = 'ws-layer-scale-rotate';
                    break;
                    
                case 5: // 弹跳加载
                    iconHtml = `<i class="bi bi-dot ws-layer-loading-icon"></i>`;
                    animationClass = 'ws-layer-bounce';
                    break;
                    
                default: // 默认
                    iconHtml = `<i class="bi bi-arrow-clockwise ws-layer-loading-icon"></i>`;
                    animationClass = 'ws-layer-spin';
            }
            
            // 添加动画类
            if (animationClass) {
                iconHtml = iconHtml.replace('ws-layer-loading-icon', 
                    `ws-layer-loading-icon ${animationClass}`);
            }

            // 添加图标大小样式
            if (config.loadingIconSize) {
                iconHtml = iconHtml.replace('ws-layer-loading-icon', 
                    `ws-layer-loading-icon  ${config.loadingTextColor} ${config.loadingIconSize}`);
            }
            
            return `
                <div class="ws-layer ws-layer-loading" id="ws-layer-${this.index}">
                    <div class="ws-layer-body">
                        ${iconHtml}
                        <div class="ws-layer-loading-text ${config.loadingTextColor}">${config.content || '加载中...'}</div>
                    </div>
                </div>
            `;
        }
        
        /**
         * 获取提示层 HTML
         * @returns {String} HTML 字符串
         */
        getTipsHtml() {
            const config = this.config;
            const content = Array.isArray(config.content) ? config.content[0] : config.content;
            
            return `
                <div class="ws-layer ws-layer-tips" id="ws-layer-${this.index}">
                    <div class="ws-layer-body">
                        ${content}
                    </div>
                    <div class="ws-layer-tips-arrow"></div>
                </div>
            `;
        }
        
        /**
         * 提示层位置计算和显示
         */
        showTips() {
            const config = this.config;
            // msg() 等场景 content 是字符串，不存在跟随元素；只有 tips() 会传入
            // content = [content, follow]。此前误把字符串的第二个字符当选择器，
            // 导致每次 msg() 都抛 "Tips: 跟随元素不存在"，这里先做类型判断。
            const follow = config.follow || (Array.isArray(config.content) ? config.content[1] : null);

            // 无跟随元素（msg/toast 场景）：保持默认居中展示即可，无需定位、不报错
            if (!follow) return;

            // 获取跟随元素
            const $follow = $(follow);
            if (!$follow.length) {
                // tips() 显式传入的跟随元素无效时给出一次可读的告警，不刷 console.error
                if (window.console && console.warn) {
                    console.warn('[WsLayer] tips: 跟随元素不存在，已按默认位置显示');
                }
                return;
            }
            
            // 获取弹层和跟随元素尺寸
            const followRect = $follow[0].getBoundingClientRect();
            const layerWidth = this.$layer.outerWidth();
            const layerHeight = this.$layer.outerHeight();
            const arrowSize = 10; // 小三角尺寸
            
            // 窗口尺寸
            const windowWidth = $(window).width();
            const windowHeight = $(window).height();
            const scrollTop = $(window).scrollTop();
            const scrollLeft = $(window).scrollLeft();
            
            // 可用空间检查
            const space = {
                top: followRect.top - layerHeight - arrowSize,
                right: windowWidth - followRect.right - layerWidth - arrowSize,
                bottom: windowHeight - followRect.bottom - layerHeight - arrowSize,
                left: followRect.left - layerWidth - arrowSize
            };

            // 计算最佳方向
            let direction = config.tips || 1;
            if (typeof direction === 'number') {
                // 验证方向是否可用
                const directionMap = { 1: 'top', 2: 'right', 3: 'bottom', 4: 'left' };
                const dirKey = directionMap[direction];
                
                // 如果指定方向空间不足，寻找最佳方向
                if (space[dirKey] < 20) {
                    const bestDirection = Object.keys(space).reduce((a, b) => 
                        space[a] > space[b] ? a : b
                    );
                    direction = { top: 1, right: 2, bottom: 3, left: 4 }[bestDirection];
                }
            }
            
            // 计算位置
            let top, left, arrowPosition, arrowClass;
            
            switch (direction) {
                case 1: // 上方
                    top = followRect.top + scrollTop - layerHeight - arrowSize;
                    left = followRect.left + scrollLeft + (followRect.width - layerWidth) / 2;
                    arrowClass = 'bottom';
                    arrowPosition = {
                        top: '100%',
                        left: '50%',
                        marginLeft: -arrowSize + 'px'
                    };
                    break;
                    
                case 2: // 右侧
                    top = followRect.top + scrollTop + (followRect.height - layerHeight) / 2;
                    left = followRect.right + scrollLeft + arrowSize;
                    arrowClass = 'left';
                    arrowPosition = {
                        top: '50%',
                        left: -arrowSize * 2 + 'px',
                        marginTop: -arrowSize + 'px'
                    };
                    break;
                    
                case 3: // 下方
                    top = followRect.bottom + scrollTop + arrowSize;
                    left = followRect.left + scrollLeft + (followRect.width - layerWidth) / 2;
                    arrowClass = 'top';
                    arrowPosition = {
                        top: -arrowSize + 'px',
                        left: '50%',
                        marginLeft: -arrowSize + 'px'
                    };
                    break;
                    
                case 4: // 左侧
                    top = followRect.top + scrollTop + (followRect.height - layerHeight) / 2;
                    left = followRect.left + scrollLeft - layerWidth - arrowSize;
                    arrowClass = 'right';
                    arrowPosition = {
                        top: '50%',
                        left: '100%',
                        marginTop: -arrowSize + 'px'
                    };
                    break;
            }
            
            // 边界检查
            if (!config.fixed) {
                top = Math.max(10, Math.min(top, scrollTop + windowHeight - layerHeight - 10));
                left = Math.max(10, Math.min(left, scrollLeft + windowWidth - layerWidth - 10));
            } else {
                top = Math.max(10, Math.min(top, windowHeight - layerHeight - 10));
                left = Math.max(10, Math.min(left, windowWidth - layerWidth - 10));
            }

            // 应用位置
            this.$layer.css({
                top: top + 'px',
                left: left + 'px'
            });
            
            // 设置小三角
            const $arrow = this.$layer.find('.ws-layer-tips-arrow');
            $arrow.attr('class', `ws-layer-tips-arrow ${arrowClass}`);
            $arrow.css(Object.assign({
                position: 'absolute',
                width: 0,
                height: 0,
                borderStyle: 'solid',
            }, arrowPosition, this.getArrowBorder(direction)));
        }

        /**
         * 获取小三角边框样式
         * @param {Number} direction 方向
         * @returns {Object} 边框样式
         */
        getArrowBorder(direction) {
            const borderColor = 'rgba(0, 0, 0, 0.8)';
            const transparent = 'transparent';
            const arrowSize = 10;
            
            const styles = {
                1: { // 上方
                    borderWidth: `${arrowSize}px ${arrowSize}px 0 ${arrowSize}px`,
                    borderColor: `${borderColor} ${transparent} ${transparent} ${transparent}`
                },
                2: { // 右侧
                    borderWidth: `${arrowSize}px 0 ${arrowSize}px ${arrowSize}px`,
                    borderColor: `${transparent} ${transparent} ${transparent} ${borderColor}`
                },
                3: { // 下方
                    borderWidth: `0 ${arrowSize}px ${arrowSize}px ${arrowSize}px`,
                    borderColor: `${transparent} ${transparent} ${borderColor} ${transparent}`
                },
                4: { // 左侧
                    borderWidth: `${arrowSize}px ${arrowSize}px ${arrowSize}px 0`,
                    borderColor: `${transparent} ${borderColor} ${transparent} ${transparent}`
                }
            };
            
            return styles[direction] || styles[1];
        }

        /**
         * 获取图标 HTML
         * @returns {String} HTML 字符串
         */
        getDialogIconHtml() {
            const config = this.config;
            const iconMap = {
                'info': 'bi-info-circle text-info',
                'success': 'bi-check-circle text-success',
                'warning': 'bi-exclamation-triangle text-warning',
                'danger': 'bi-x-circle text-danger',
                'primary': 'bi-question-circle text-primary',
                'lock': 'bi-lock text-secondary',
                'frown': 'bi-emoji-frown text-warning',
                'smile': 'bi-emoji-smile text-success'
            };

            const iconClass = (typeof config.icon === 'string' && config.icon in iconMap) ? iconMap[config.icon] : 
                            ((typeof config.icon === 'number') ? Object.values(iconMap)[config.icon] : 'bi-info-circle text-info');
            return `<i class="${iconClass} ws-layer-icon"></i>`;
        }
        
        /**
         * 获取按钮 HTML
         * @returns {String} HTML 字符串
         */
        getBtnHtml() {
            const config = this.config;
            let btnHtml = '<div class="ws-layer-footer">';
            
            if (typeof config.btn === 'string') {
                config.btn = [config.btn];
            }
            
            config.btn.forEach((text, index) => {
                const btnClass = index === 0 ? 'btn-primary' : 'btn-outline-secondary';
                btnHtml += `<button type="button" class="btn ${btnClass} me-2" data-layer-btn="${index}">${text}</button>`;
            });
            
            btnHtml += '</div>';
            return btnHtml;
        }
        
        /**
         * 设置弹层位置
         */
        setPosition() {
            const config = this.config;
            const windowWidth = $(window).width();
            const windowHeight = $(window).height();
            
            // 先获取弹层尺寸：测量前隐藏避免闪动。测量完成后必须恢复
            // 原先的 visibility，否则弹层会一直停留在隐藏态——
            // 首次定位时由 createLayer() 之后统一置 visible 掩盖了问题，
            // 但该方法被二次调用时（如 photos 图片加载后重定位）弹层将
            // 彻底不可见、不可交互。
            const prevVisibility = this.$layer.css('visibility');
            this.$layer.css({
                'visibility': 'hidden',
                'display': 'flex'
            });
            
            const layerWidth = this.$layer.outerWidth();
            const layerHeight = this.$layer.outerHeight();
            
            let top, left;
            
            if (config.offset === 'auto') {
                // 居中显示
                top = (windowHeight - layerHeight) / 2;
                left = (windowWidth - layerWidth) / 2;
            } else if (typeof config.offset === 'object') {
                // 自定义位置
                top = config.offset[0];
                left = config.offset[1] || (windowWidth - layerWidth) / 2;
                
                // 处理百分比
                if (typeof top === 'string' && top.includes('%')) {
                    top = (windowHeight * parseInt(top)) / 100;
                }
                if (typeof left === 'string' && left.includes('%')) {
                    left = (windowWidth * parseInt(left)) / 100;
                }
            } else {
                // 方向定位
                const offsetMap = {
                    't': 0, // 上
                    'r': [0, windowWidth - layerWidth], // 右
                    'b': [windowHeight - layerHeight, 0], // 下
                    'l': [0, 0], // 左
                    'lt': [0, 0], // 左上
                    'lb': [windowHeight - layerHeight, 0], // 左下
                    'rt': [0, windowWidth - layerWidth], // 右上
                    'rb': [windowHeight - layerHeight, windowWidth - layerWidth], // 右下
                    'c': [(windowHeight - layerHeight) / 2, (windowWidth - layerWidth) / 2] // 居中
                };
                
                const position = offsetMap[config.offset] || [(windowHeight - layerHeight) / 2, (windowWidth - layerWidth) / 2];
                top = Array.isArray(position) ? position[0] : position;
                left = Array.isArray(position) ? position[1] : (windowWidth - layerWidth) / 2;
            }
            
            // 边界检查
            top = Math.max(0, Math.min(top, windowHeight - layerHeight - 10));
            left = Math.max(0, Math.min(left, windowWidth - layerWidth - 10));
            
            // 固定定位时不需要添加滚动距离
            if (!config.fixed) {
                top += $(window).scrollTop();
                left += $(window).scrollLeft();
            }
            
            this.$layer.css({
                top: top + 'px',
                left: left + 'px'
            });

            // 恢复测量前隐藏的可见状态
            this.$layer.css('visibility', prevVisibility);
        }

        /**
         * 设置弹层尺寸
         */
        setSize() {
            const config = this.config;
            const windowWidth = $(window).width();
            const windowHeight = $(window).height();
            
            if (config.area && config.area !== 'auto') {
                let width, height;
                
                if (typeof config.area === 'string') {
                    width = config.area;
                    height = 'auto';
                } else if (Array.isArray(config.area)) {
                    width = config.area[0];
                    height = config.area[1];
                }
                
                // 处理百分比和自动
                if (width === 'auto' || (typeof width === 'string' && width.includes('%'))) {
                    const maxWidth = config.maxWidth || windowWidth - 40;
                    if (typeof width === 'string' && width.includes('%')) {
                        width = Math.min((windowWidth * parseInt(width)) / 100, maxWidth);
                    } else {
                        width = Math.min(this.$layer.width(), maxWidth);
                    }
                    width = width + 'px';
                }
                
                if (height === 'auto' || (typeof height === 'string' && height.includes('%'))) {
                    const maxHeight = config.maxHeight || windowHeight - 40;
                    if (typeof height === 'string' && height.includes('%')) {
                        height = Math.min((windowHeight * parseInt(height)) / 100, maxHeight);
                    } else {
                        height = Math.min(this.$layer.height(), maxHeight);
                    }
                    height = height + 'px';
                }
                
                this.$layer.css({
                    width: width,
                    height: height
                });
            }
            
            // 确保不超过最大尺寸
            if (config.maxWidth) {
                const currentWidth = this.$layer.outerWidth();
                if (currentWidth > config.maxWidth) {
                    this.$layer.css('width', config.maxWidth + 'px');
                }
            }
            
            if (config.maxHeight) {
                const currentHeight = this.$layer.outerHeight();
                if (currentHeight > config.maxHeight) {
                    this.$layer.css('height', config.maxHeight + 'px');
                }
            }
        }
        
        /**
         * 绑定事件
         */
        bindEvents() {
            this.bindCloseEvent();
            this.bindBtnEvents();
            this.bindShadeClose();
            this.bindDragEvents();
            this.bindResizeEvents();
            this.bindMinMaxEvents();
        }
        
        /**
         * 绑定iframe事件
         */
        bindIframeEvents() {
            const self = this;
            const $iframe = this.$layer.find('iframe');
            const iframeId = `ws-layer-iframe-${this.index}`;
            
            // iframe 加载完成事件
            $iframe.on('load', function() {
                let loadSuccess = true;
                try {
                    // 尝试获取 iframe 的 title
                    const iframeDoc = this.contentDocument || this.contentWindow.document;
                    const iframeTitle = iframeDoc.title;
                    
                    // 如果 iframe 有标题且配置了 iframeTitle 回调
                    if (iframeTitle && self.config.iframeTitle) {
                        const newTitle = self.config.iframeTitle(iframeTitle, self.index, self.$layer, self);
                        
                        // 如果回调返回字符串，则更新标题
                        if (typeof newTitle === 'string') {
                            self.setTitle(newTitle);
                        }
                        // 如果返回 false，则不更新
                        // 其他情况保持原样
                    } else if (iframeTitle && self.config.autoUpdateTitle !== false) {
                        // 自动更新标题（如果配置允许）
                        self.setTitle(iframeTitle);
                    }
                } catch (e) {
                    // 跨域情况下无法访问 iframe 内容，忽略错误
                    console.warn('无法访问 iframe 内容，可能是跨域限制:', e.message);
                    loadSuccess = false;
                    
                    // 触发跨域错误回调
                    if (self.config.iframeLoadError) {
                        self.config.iframeLoadError(e, self.index, self.$layer, self);
                    }
                }
                
                // 仅在加载成功时触发 iframe 加载完成回调
                if (loadSuccess && self.config.iframeLoad) {
                    self.config.iframeLoad(self.index, self.$layer, self);
                }
            });
            
            // iframe 加载错误处理
            $iframe.on('error', function(e) {
                console.error('iframe 加载错误:', e);
                
                if (self.config.iframeLoadError) {
                    self.config.iframeLoadError(e, self.index, self.$layer, self);
                }
            });
        }

        /**
         * 绑定关闭事件
         */
        bindCloseEvent() {
            const self = this;
            
            // 关闭按钮
            this.$layer.find('[data-layer-close]').on('click', function() {
                if (self.config.cancel) {
                    self.config.cancel(self.index, self.$layer, self);
                }
                self.close('cancel');
            });
        }
        
        /**
         * 绑定按钮事件
         */
        bindBtnEvents() {
            const self = this;
            
            this.$layer.find('[data-layer-btn]').on('click', function() {
                const btnIndex = $(this).data('layer-btn');
                
                // 异步按钮处理
                if (self.config.btnAsync) {
                    const btnCallback = btnIndex === 0 ? self.config.yes : self.config['btn' + (btnIndex + 1)];
                    if (btnCallback) {
                        const result = btnCallback(self.index, self.$layer, self);
                        if (result && typeof result.then === 'function') {
                            // Promise 处理
                            const $btn = $(this);
                            $btn.prop('disabled', true).prepend('<span class="spinner-border spinner-border-sm me-2"></span>');
                            result.then(function(res) {
                                if (res !== false) {
                                    self.close();
                                }
                            }).finally(function() {
                                $btn.prop('disabled', false).find('.spinner-border').remove();
                            });
                        } else if (result !== false) {
                            self.close();
                        }
                    } else {
                        self.close();
                    }
                } else {
                    // 同步按钮处理
                    if (btnIndex === 0) {
                        if (self.config.yes) {
                            const close = self.config.yes(self.index, self.$layer, self);
                            if (close === true) {
                                self.close();
                            }
                        } else {
                            self.close();
                        }
                    } else {
                        if (self.config['btn' + (btnIndex + 1)]) {
                            const close = self.config['btn' + (btnIndex + 1)](self.index, self.$layer, self);
                            if (close === true) {
                                self.close();
                            }
                        } else {
                            self.close();
                        }
                    }
                }
            });
        }
        
        /**
         * 绑定遮罩关闭事件
         */
        bindShadeClose() {
            const self = this;
            
            if (this.config.shadeClose && this.$shade) {
                this.$shade.on('click', function() {
                    self.close();
                });
            }
        }
        
        /**
         * 绑定拖拽事件
         */
        bindDragEvents() {
            if (!this.config.move) return;
            
            const self = this;
            const $header = this.$layer.find('.ws-layer-header');
            let isDragging = false;
            let startX, startY, initialX, initialY;
            
            $header.addClass('ws-layer-draggable');
            
            $header.on('mousedown', function(e) {
                if (self.isMaximized) return;
                
                isDragging = true;
                self._minDragMoved = false;
                startX = e.clientX;
                startY = e.clientY;
                initialX = parseInt(self.$layer.css('left'));
                initialY = parseInt(self.$layer.css('top'));
                
                $('body').css('cursor', 'move');
                e.preventDefault();
            });
            
            // 使用命名空间事件，便于 destroy 时统一清理
            $(document).off('mousemove.layerDrag mouseup.layerDrag');
            $(document).on('mousemove.layerDrag', function(e) {
                if (!isDragging) return;
                
                const deltaX = e.clientX - startX;
                const deltaY = e.clientY - startY;
                
                // 拖拽超过阈值时抑制最小化条的“点击还原”，避免拖动后误还原
                if (Math.abs(deltaX) > 4 || Math.abs(deltaY) > 4) {
                    self._minDragMoved = true;
                }
                
                let newX = initialX + deltaX;
                let newY = initialY + deltaY;
                
                // 限制拖拽范围
                if (!self.config.moveOut) {
                    const windowWidth = $(window).width();
                    const windowHeight = $(window).height();
                    const layerWidth = self.$layer.outerWidth();
                    const layerHeight = self.$layer.outerHeight();
                    
                    newX = Math.max(0, Math.min(newX, windowWidth - layerWidth));
                    newY = Math.max(0, Math.min(newY, windowHeight - layerHeight));
                }
                
                self.$layer.css({
                    left: newX + 'px',
                    top: newY + 'px',
                    transform: 'none'
                });
            });
            
            $(document).on('mouseup.layerDrag', function() {
                if (isDragging) {
                    isDragging = false;
                    $('body').css('cursor', '');
                    
                    if (self.config.moveEnd) {
                        self.config.moveEnd(self.$layer, self);
                    }
                    // 点击事件在 mouseup 之后触发：拖拽痕迹稍后清除，让点击处理能判断本次是否为拖动
                    if (self.isMinimized) {
                        setTimeout(function() {
                            self._minDragMoved = false;
                        }, 0);
                    }
                }
            });
        }
        
        /**
         * 绑定调整大小事件
         */
        bindResizeEvents() {
            if (!this.config.resize) return;
            
            const self = this;
            const $resize = this.$layer.find('.ws-layer-resize');
            
            // 移除之前可能绑定的事件，避免重复绑定
            $resize.off('mousedown.layerResize');
            
            $resize.on('mousedown.layerResize', function(e) {
                if (self.isMaximized || self.isMinimized) return;
                
                // 阻止事件冒泡和默认行为
                e.stopPropagation();
                e.preventDefault();
                
                const startX = e.clientX;
                const startY = e.clientY;
                const initialWidth = self.$layer.outerWidth();
                const initialHeight = self.$layer.outerHeight();
                
                $('body').css('cursor', 'se-resize');
                self.$layer.addClass('ws-layer-resizing');
                
                // 定义 mousemove 和 mouseup 处理函数
                const handleMouseMove = function(e) {
                    const deltaX = e.clientX - startX;
                    const deltaY = e.clientY - startY;
                    
                    // 计算新的尺寸，限制最小尺寸
                    const newWidth = Math.max(200, initialWidth + deltaX);
                    const newHeight = Math.max(150, initialHeight + deltaY);
                    
                    // 限制最大尺寸（不超过窗口大小）
                    const maxWidth = $(window).width() - 20;
                    const maxHeight = $(window).height() - 20;
                    
                    const finalWidth = Math.min(newWidth, maxWidth);
                    const finalHeight = Math.min(newHeight, maxHeight);
                    
                    // 应用新尺寸
                    self.$layer.css({
                        width: finalWidth + 'px',
                        height: finalHeight + 'px'
                    });
                    
                    // 更新内容区域尺寸
                    self.updateContentSize();
                    
                    // 触发调整大小回调
                    if (self.config.resizing) {
                        self.config.resizing(self.$layer, self);
                    }
                };
                
                const handleMouseUp = function() {
                    $('body').css('cursor', '');
                    self.$layer.removeClass('ws-layer-resizing');
                    
                    // 重新计算位置，确保弹层不会超出屏幕
                    self.adjustPositionAfterResize();
                    
                    // 只移除当前调整会话的事件，不移除 resize 元素的 mousedown 事件
                    $(document).off('mousemove.layerResize mouseup.layerResize');
                };
                
                // 绑定文档级别的事件
                $(document)
                    .off('mousemove.layerResize mouseup.layerResize') // 先移除可能存在的旧事件
                    .on('mousemove.layerResize', handleMouseMove)
                    .on('mouseup.layerResize', handleMouseUp);
            });
        }
        
        /**
         * 调整大小后重新定位
         */
        adjustPositionAfterResize() {
            const windowWidth = $(window).width();
            const windowHeight = $(window).height();
            const layerWidth = this.$layer.outerWidth();
            const layerHeight = this.$layer.outerHeight();
            
            let top = parseInt(this.$layer.css('top'));
            let left = parseInt(this.$layer.css('left'));
            
            // 确保弹层不会超出屏幕右侧
            if (left + layerWidth > windowWidth) {
                left = windowWidth - layerWidth - 10;
            }
            
            // 确保弹层不会超出屏幕底部
            if (top + layerHeight > windowHeight) {
                top = windowHeight - layerHeight - 10;
            }
            
            // 确保弹层不会超出屏幕左侧和顶部
            left = Math.max(10, left);
            top = Math.max(10, top);
            
            this.$layer.css({
                top: top + 'px',
                left: left + 'px'
            });
        }

        /**
         * 更新内容区域尺寸
         */
        updateContentSize() {
            const $header = this.$layer.find('.ws-layer-header');
            const $body = this.$layer.find('.ws-layer-body');
            const $footer = this.$layer.find('.ws-layer-footer');
            
            const headerHeight = $header.outerHeight() || 0;
            const footerHeight = $footer.outerHeight() || 0;
            const layerHeight = this.$layer.outerHeight();
            
            // 计算内容区域可用高度
            const contentHeight = layerHeight - headerHeight - footerHeight;
            
            if (contentHeight > 0) {
                $body.css('height', contentHeight + 'px');
            }
            
            // 如果是 iframe，也需要调整 iframe 尺寸
            if (this.config.type === 2) {
                const $iframe = this.$layer.find('iframe');
                if ($iframe.length) {
                    const iframeHeight = contentHeight - 
                        (parseInt($body.css('padding-top')) || 0) - 
                        (parseInt($body.css('padding-bottom')) || 0);
                    
                    if (iframeHeight > 0) {
                        $iframe.css('height', iframeHeight + 'px');
                    }
                }
            }
        }

        /**
         * 绑定最小化最大化事件
         */
        bindMinMaxEvents() {
            const self = this;
            
            // 最小化
            this.$layer.find('[data-layer-min]').on('click', function() {
                if (self.config.minEnd) {
                    self.config.minEnd(self.index, self.$layer, self);
                }
                self.minimize();
            });
            
            // 最大化/还原
            this.$layer.find('[data-layer-max]').on('click', function() {
                if (self.isMaximized || self.isMinimized) {
                    if (self.config.restore) {
                        self.config.restore(self.index, self.$layer, self);
                    }
                    self.restore();
                } else {
                    if (self.config.full) {
                        self.config.full(self.index, self.$layer, self);
                    }
                    self.maximize();
                }
            });
        }
        
        /**
         * 绑定键盘事件
         */
        bindKeyboardEvents() {
            const self = this;

            // 使用命名空间避免事件冲突
            $(document).on(`keydown.ws-layer-${this.index}`, function(e) {
                // 使用缓存的全局 index 代替每次遍历数组
                const maxIndex = WsLayer.index;

                // ESC 键关闭弹层
                if (e.keyCode === 27 && self.config.closeOnEsc !== false && self.index === maxIndex) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    
                    if (self.config.cancel) {
                        self.config.cancel(self.index, self.$layer, self);
                    }
                    self.close('cancel');
                    return false;
                }
                
                // Enter 键提交
                if (e.keyCode === 13 && self.config.submitOnEnter !== false) {
                    // 检查是否在输入框中
                    const $focused = $(document.activeElement);
                    const isInInput = $focused.is('input, textarea, select');
                    const isInLayer = $focused.closest('.ws-layer').length > 0;
                    
                    if (isInLayer && (!isInInput || self.config.submitOnEnterInInput)) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        
                        // 触发确定按钮
                        const $confirmBtn = self.$layer.find('[data-layer-btn="0"]');
                        if ($confirmBtn.length && !$confirmBtn.prop('disabled')) {
                            $confirmBtn.trigger('click');
                        }
                        return false;
                    }
                }
            });
        }

        /**
         * 移除键盘事件
         */
        unbindKeyboardEvents() {
            $(document).off(`keydown.ws-layer-${this.index}`);
        }

        /**
         * 自动关闭
         */
        autoClose() {
            const self = this;
            
            if (this.config.time > 0) {
                setTimeout(function() {
                    self.close();
                }, this.config.time);
            }
        }
        
        /**
         * 最小化弹层
         * 弹层为 fixed 定位，top 必须相对「视口高度」计算，
         * 若按整页文档高度（$(document).height()）定位，页面一长，
         * 最小化条就会被推到视口之外而“消失”。
         */
        minimize() {
            if (this.isMinimized) return;

            // 记录“最小化前的状态”，并确保普通几何快照已采集。
            // 从最大化直接最小化：还原时应回到最大化（而非普通尺寸）。
            if (this.isMaximized) {
                this._stateBeforeMin = 'maximized';
            } else {
                this._stateBeforeMin = 'normal';
                if (!this._normalStyle) {
                    this._normalStyle = this._captureGeometry();
                }
            }

            this.$layer.addClass('ws-layer-minimized');
            this.$layer.find('[data-layer-max]').html('<i class="bi bi-fullscreen-exit"></i>');
            
            // 先取小条实际高度，再把它贴到视口底部
            const barH = this.$layer.outerHeight();
            const vw = $(window).width();
            const vh = $(window).height();
            const gap = 10;
            const slotW = 260; // 每条占位宽：条宽 240 + 间距 20

            // 多条最小化条从左上至右下排布，自动换行
            if (WsLayer.minStackArr.indexOf(this.index) === -1) {
                WsLayer.minStackArr.push(this.index);
            }
            const order = WsLayer.minStackArr.indexOf(this.index);
            const rowCap = Math.max(1, Math.floor((vw - gap * 2) / slotW));
            const row = Math.floor(order / rowCap);
            const col = order % rowCap;

            this.$layer.css({
                width: '240px',
                height: 'auto',
                top: Math.max(0, vh - (row + 1) * (barH + gap)) + 'px',
                left: (gap + col * slotW) + 'px',
                right: 'auto',
                bottom: 'auto',
                transform: 'none'
            });
            
            if (this.$shade) {
                this.$shade.hide();
            }

            // 点击最小化条（标题文字/留白区域）即可还原；操作按钮点击由各自处理。
            // 拖拽结束时 _minDragMoved 会短暂保持，避免拖动后误触发还原。
            if (!this._minRestoreBound) {
                const self = this;
                this._minRestoreBound = true;
                this.$layer.find('.ws-layer-header').on('click.layerMinRestore', function(e) {
                    if (!self.isMinimized || self._minDragMoved) return;
                    if ($(e.target).closest('button').length) return;
                    self.restore();
                });
            }
            
            this.isMinimized = true;
            this.isMaximized = false;
        }
        
        /**
         * 捕获当前常规几何（用于还原普通状态）
         */
        _captureGeometry() {
            return {
                width: this.$layer.css('width'),
                height: this.$layer.css('height'),
                top: this.$layer.css('top'),
                left: this.$layer.css('left'),
                right: this.$layer.css('right'),
                bottom: this.$layer.css('bottom'),
                transform: this.$layer.css('transform')
            };
        }
        
        /**
         * 应用最大化几何
         */
        _applyMaximizeCss() {
            this.$layer.css({
                width: '100%',
                height: '100%',
                top: 0,
                left: 0,
                right: 'auto',
                bottom: 'auto',
                transform: 'none'
            });
        }
        
        /**
         * 最大化弹层
         */
        maximize() {
            if (this.isMaximized || this.isMinimized) return;
            
            if (!this._normalStyle) {
                this._normalStyle = this._captureGeometry();
            }
            
            this._applyMaximizeCss();
            this.$layer.find('[data-layer-max]').html('<i class="bi bi-fullscreen-exit"></i>');
            this.isMaximized = true;
        }
        
        /**
         * 还原弹层
         * 状态机：normal <-> maximized <-> minimized。
         * 最小化还原会回到“最小化前的状态”（最大化 或 普通），
         * 最大化还原回到普通；不会因反复切换而丢失普通几何或状态位。
         */
        restore() {
            if (!this.isMinimized && !this.isMaximized) return;

            const fromMinimized = this.isMinimized;
            const backToMaximized = fromMinimized && this._stateBeforeMin === 'maximized';

            this.$layer.removeClass('ws-layer-minimized');

            if (backToMaximized) {
                // 最小化前处于最大化：还原为最大化
                this._applyMaximizeCss();
                this.$layer.find('[data-layer-max]').html('<i class="bi bi-fullscreen-exit"></i>');
                this.isMaximized = true;
                this.isMinimized = false;
            } else {
                // 回到普通几何（最小化自普通态 或 从最大化还原）
                this.$layer.css(this._normalStyle || {});
                this.$layer.find('[data-layer-max]').html('<i class="bi bi-square"></i>');
                this.isMaximized = false;
                this.isMinimized = false;
            }
            this._stateBeforeMin = null;
            
            // 从最小化堆叠中移除
            const pos = WsLayer.minStackArr.indexOf(this.index);
            if (pos > -1) {
                WsLayer.minStackArr.splice(pos, 1);
            }
            
            if (this.$shade) {
                this.$shade.show();
            }
        }
        
        /**
         * 设置标题
         * @param {String} title 新标题
         */
        setTitle(title) {
            this.$layer.find('.ws-layer-header h5').html(title);
        }
        
        /**
         * 处理图片浏览器操作
         * @param {String} action 操作类型
         */
        handlePhotosAction(action) {
            if (!this.photosData) return;
            
            switch (action) {
                case 'prev':
                    if (this.photosData.currentIndex > 0) {
                        this.photosData.currentIndex--;
                        this.updatePhotosView();
                    }
                    break;
                    
                case 'next':
                    if (this.photosData.currentIndex < this.photosData.data.length - 1) {
                        this.photosData.currentIndex++;
                        this.updatePhotosView();
                    }
                    break;
                    
                case 'rotate':
                    this.photosData.rotate = (this.photosData.rotate + 90) % 360;
                    this.$layer.find('img').css('transform', `rotate(${this.photosData.rotate}deg)`);
                    break;
                    
                case 'close':
                    WsLayer.close(this.index);
                    break;
            }
        }
        
        /**
         * 更新图片浏览器视图
         */
        updatePhotosView() {
            const photo = this.photosData.data[this.photosData.currentIndex];
            const $img = this.$layer.find('img');
            const $counter = this.$layer.find('.ws-layer-photos-header span');
            const $footer = this.$layer.find('.ws-layer-photos-footer div');
            const $link = this.$layer.find('.ws-layer-photos-footer a');

            $img.attr('src', photo.src).attr('alt', photo.alt || '');
            $counter.text(`${this.photosData.currentIndex + 1} / ${this.photosData.data.length}`);
            $footer.text(photo.alt || '');
            $link.attr('href', photo.src);

            // 更新按钮状态
            this.$layer.find('[data-photos-action="prev"]').prop('disabled', this.photosData.currentIndex === 0);
            this.$layer.find('[data-photos-action="next"]').prop('disabled', this.photosData.currentIndex === this.photosData.data.length - 1);

            // 切换图片后尺寸会异步变化，交由轮询校正弹层位置与图片尺寸约束
            this._schedulePhotosFit();
        }

        /**
         * 图片层自适应：约束图片尺寸并按弹层高度轮询校正位置，直到尺寸稳定。
         *
         * 背景：弹层在图片加载/解码完成之前就会被定位，而图片异步就位后
         * 弹层高度会突变。load 事件、complete 检查、单次 rAF 都无法覆盖
         * "命中缓存"与"解码耗时"两类场景，因此采用低频轮询——每 80ms
         * 重新定位一次，直到连续 3 次弹层高度一致（视为尺寸稳定），
         * 或超过 4s 上限后自动停止，保证弹层始终完整落在视口内。
         */
        _schedulePhotosFit() {
            const self = this;
            if (this._stopPhotosAutoFit) this._stopPhotosAutoFit();

            const $img = this.$layer.find('img');
            $img.css({
                'max-width': '100%',
                'max-height': Math.max(160, $(window).height() - 120) + 'px'
            });

            let lastH = null;
            let stable = 0;
            let count = 0;
            const timer = setInterval(function() {
                count++;
                self.setPosition();
                const h = self.$layer.outerHeight();
                if (h === lastH) {
                    stable++;
                } else {
                    stable = 0;
                    lastH = h;
                }
                if (stable >= 3 || count > 50) {
                    clearInterval(timer);
                }
            }, 80);

            this._stopPhotosAutoFit = function() {
                clearInterval(timer);
            };
        }
        
        /**
         * 关闭弹层
         * @param {String} exitType 退出类型，可选值为 'close' 或 'cancel'
         */
        close(exitType = 'close') {
            const self = this;

            // 从最小化堆叠中移除，避免索引残留导致后续排布错位
            const stackPos = WsLayer.minStackArr.indexOf(this.index);
            if (stackPos > -1) {
                WsLayer.minStackArr.splice(stackPos, 1);
            }

            // 移除键盘事件
            this.unbindKeyboardEvents();

            if (this.config.isOutAnim) {
                this.$layer.removeClass('ws-layer-fade-in').addClass('ws-layer-fade-out');
                
                setTimeout(function() {
                    self.remove();
                }, 300);
            } else {
                this.remove();
            }
            
            // 执行结束回调
            if (this.config.end) {
                this.config.end(exitType);
            }
            WsLayer.instances[self.index] = null;
        }
        
        /**
         * 移除弹层
         */
        remove() {
            if (this.$shade) {
                this.$shade.remove();
            }
            // 清理 document 级拖拽事件，防止内存泄漏
            $(document).off('mousemove.layerDrag mouseup.layerDrag');
            this.$layer.remove();
        }
    }
    
    // 初始化组件
    WsLayer.init();
// 全局挂载：统一使用 Ws* 标准名 window.WsLayer；wsLayer/Layer 为历史别名，兼容存量调用零改造。
window.WsLayer = WsLayer;
window.wsLayer = WsLayer;
window.Layer = WsLayer;

export default WsLayer;