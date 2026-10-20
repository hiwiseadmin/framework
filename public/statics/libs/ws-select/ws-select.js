/**
 * 多功能下拉选择组件
 * 基于 jQuery 和 Bootstrap 5 的现代化下拉选择组件
 * 支持多字段显示、搜索、分组、多选、远程数据、分页、树形结构等高级功能
 * 
 * @version 3.5.0
 * @license MIT
 */

// 样式：ws-select.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-select/ws-select.css">

// jQuery 走全局单例：本组件是 jQuery 插件，若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
if (typeof $ === 'undefined') {
    throw new Error('WsSelect requires jQuery');
}

    /**
     * 默认配置选项
     * @typedef {Object} WsSelectOptions
     * @property {Array} options - 选项数据数组 参考 fieldMapping 配置
     * @property {*} value - 当前选中的值
     * @property {*} defaultValue - 默认选中的值
     * @property {boolean} multiple - 是否支持多选
     * @property {number} multipleMaxSelect - 多选时最大可选数量，0表示不限制
     * @property {boolean} disabled - 是否禁用组件
     * @property {boolean} searchable - 是否启用搜索功能
     * @property {boolean} autoClearSearchValue - 是否自动清空搜索值，默认值为false
     * @property {boolean} clearable - 是否显示清除按钮
     * @property {string} placeholder - 占位符文本
     * @property {string} size - 组件尺寸，可选值：'small', 'medium', 'large'
     * @property {Function} onChange - 值变化时的回调函数
     * @property {Function} onOptionChange - 选项变化时的回调函数 - 未实现
     * @property {Function} onRender - 渲染时的回调函数 - 未实现
     * @property {Function} onSearch - 搜索时的回调函数
     * @property {Function} onFocus - 获取焦点时的回调函数
     * @property {Function} onBlur - 失去焦点时的回调函数
     * @property {Function} onSelectOptionRender - 自定义选定选项渲染函数 - 未实现
     * @property {Function} onSelectValueRender - 自定义选定值渲染函数
     * @property {boolean} loading - 是否显示加载状态
     * @property {boolean|Function} filterOption - 选项筛选函数或是否启用本地筛选
     * @property {boolean} allowClear - 是否允许清除选中项
     * @property {boolean} showArrow - 是否显示下拉箭头
     * @property {boolean} dropdownMatchSelectWidth - 下拉菜单是否与选择器同宽
     * @property {string} remoteUrl - 远程数据URL
     * @property {string} url - 远程数据URL别称
     * @property {Function} remoteMethod - 远程数据获取方法
     * @property {Function} onError - 远程数据获取错误时的回调函数
     * @property {boolean} secondaryColumn - 二级文本列风格，默认值为false
     * @property {string} emptyText - 无匹配选项时的文本
     * @property {string} loadingText - 加载中时的文本
     * @property {Object} fieldMapping - 字段映射配置
     * @property {string} displayMode - 显示模式，可选值：'default', 'group'
     * @property {number} remoteDelay - 远程搜索延迟时间（毫秒）
     * @property {number} minSearchLength - 最小搜索字符数
     * @property {string} maxOptionHeight - 最大选项高度（像素），支持数字、数字+单位(px|rem|%...)，默认值为'300px'
     * @property {Object} ajaxOptions - AJAX请求配置
     * @property {boolean} pagination - 是否启用分页
     * @property {number} pageSize - 每页显示数量
     * @property {Array} pageSizeOptions - 每页数量选项
     * @property {Object} paginationFieldMapping - 分页字段映射
     * @property {boolean} showTotal - 是否显示总数
     * @property {boolean} showSizeChanger - 是否显示每页数量切换
     * @property {boolean} treeMode - 是否启用树形模式
     * @property {string} treeFieldMapping - 树形字段映射
     * @property {boolean} treeExpandAll - 是否默认展开所有节点
     * @property {string} lastLevelLayout - 最后一级排列方式：'vertical' 或 'horizontal'
     * @property {boolean} treeCheckable - 树形是否可勾选（多选）
     * @property {boolean} treeCheckStrictly - 树形勾选是否严格模式
     * @property {string} name - 隐藏input的name属性，用于表单提交
     * @property {string} inputName - 隐藏input的name属性别名
     * @property {string} id - 隐藏input的id属性
     * @property {string} inputId - 隐藏input的id属性别名
     * @property {boolean} required - 是否必填
     * @property {string} valueSeparator - 多选时值的分隔符，默认为逗号
     * @property {boolean} useJsonValue - 是否使用JSON格式存储多选值
     * @property {boolean} autoCreateInput - 是否自动创建隐藏input，默认为true
     */
    var DEFAULTS = {
        options: [],
        value: null,
        defaultValue: null,
        multiple: false,
        multipleMaxSelect: 0,
        disabled: false,
        searchable: false,
        autoClearSearchValue: false,
        clearable: true,
        placeholder: '请选择',
        size: 'medium',
        onChange: null,
        onOptionChange: null,
        onRender: null,
        onSearch: null,
        onFocus: null,
        onBlur: null,
        onSelectOptionRender: null,
        onSelectValueRender: null,
        loading: false,
        filterOption: true,
        allowClear: true,
        showArrow: true,
        dropdownMatchSelectWidth: true,
        remoteUrl: null,
        url: null,
        remoteMethod: null,
        onError: null,
        secondaryColumn: false,
        emptyText: '无匹配选项',
        loadingText: '加载中...',
        fieldMapping: {             // 字段映射配置
            value: 'value',
            label: 'label',
            secondary: 'secondary',
            icon: 'icon',
            group: 'group',
            disabled: 'disabled'
        },
        displayMode: 'default', // 'default' or 'group'
        remoteDelay: 300,
        minSearchLength: 1,
        maxOptionHeight: '300px',
        ajaxOptions: {},
        pagination: false,
        pageSize: 10,
        pageSizeOptions: [10, 20, 50, 100],
        paginationFieldMapping: {   // 分页字段映射配置
            page: 'page',
            pageSize: 'pageSize',
            total: 'total',
            data: 'data'
        },
        showTotal: true,
        showSizeChanger: true,
        // 树形模式相关配置
        treeMode: false,
        treeFieldMapping: {         // 树形字段映射配置 
            value: 'value',
            label: 'label',
            children: 'children',
            disabled: 'disabled',
            icon: 'icon',
            secondary: 'secondary',
            isLeaf: 'isLeaf'
        },
        treeExpandAll: false,
        lastLevelLayout: 'vertical', // 'vertical' or 'horizontal'
        treeCheckable: false,
        treeCheckStrictly: false,
        // 表单集成相关配置
        name: null,             // 隐藏input的name属性，用于表单提交
        inputName: null,        // 隐藏input的name属性别名
        id: null,               // 隐藏input的id属性
        inputId: null,          // 隐藏input的id属性别名
        required: false,        // 是否必填
        valueSeparator: ',',    // 多选时值的分隔符，默认为逗号
        useJsonValue: false,    // 是否使用JSON格式存储多选值
        autoCreateInput: true   // 是否自动创建隐藏input，默认为true
    };

    /**
     * 多功能下拉选择组件类
     * @class WsSelect
     * @param {HTMLElement} element - 容器元素
     * @param {WsSelectOptions} options - 配置选项
     */
    function WsSelect(element, options) {
        this.$element = $(element);
        this.settings = $.extend(true, {}, DEFAULTS, options);
        this.settings.remoteUrl = this.settings.remoteUrl || this.settings.url;
        this.init();
    }

    // 原型方法
    WsSelect.prototype = {
        /**
         * 初始化组件
         */
        init: function() {
            this.selectedValues = this.settings.multiple ? 
                (Array.isArray(this.settings.value) ? this.settings.value : 
                 (this.settings.value ? [this.settings.value] : [])) : 
                (this.settings.value || null);
            
            this.isOpen = false;
            this.searchValue = '';
            this.focusedIndex = -1;
            this.remoteSearchTimer = null;
            this.isLoading = false;
            this.remoteData = [];
            this.localOptions = this.transformRemoteData(this.settings.options || []);

            // 分页相关
            this.currentPage = 1;
            this.total = this.localOptions.length;
            this.pageSize = this.settings.pageSize;
            
            // 树形相关
            this.treeData = [];
            this.expandedKeys = new Set(); // 展开的节点key

            // 表单相关
            this.hiddenInput = null;
            
            // 创建隐藏input元素
            if (this.settings.autoCreateInput) {
                this.createHiddenInput();
            }

            this.render();

            // 如果是树形模式，处理树形数据
            if (this.settings.treeMode) {
                this.processTreeData();
                if (this.settings.treeExpandAll) {
                    this.expandAllNodes();
                }
                // 更新选项
                this.updateOptionsList();
            }else{
                
            }

            this.bindEvents();

            // 如果有远程URL且没有提供本地选项，则预加载远程数据
            if ((this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') && this.localOptions.length === 0) {
                this.loadRemoteData();
            }

        },

        /**
         * 动态重新配置组件
         * @param {Object} newOptions - 新的配置选项，将与现有配置合并
         * @param {boolean} [resetSelectedValues=false] - 是否重置选中的值为新配置中的value或默认值
         */
        reconfigure: function(newOptions, resetSelectedValues) {
            if (!newOptions || typeof newOptions !== 'object') return;
            
            var oldSettings = $.extend(true, {}, this.settings);
            
            // 合并新配置
            $.extend(true, this.settings, newOptions);
            this.settings.remoteUrl = this.settings.remoteUrl || this.settings.url;
            
            // 记录哪些配置发生了变化
            var changes = {
                options: !this.deepEqual(oldSettings.options, this.settings.options),
                multiple: oldSettings.multiple !== this.settings.multiple,
                disabled: oldSettings.disabled !== this.settings.disabled,
                searchable: oldSettings.searchable !== this.settings.searchable,
                clearable: oldSettings.clearable !== this.settings.clearable,
                placeholder: oldSettings.placeholder !== this.settings.placeholder,
                size: oldSettings.size !== this.settings.size,
                displayMode: oldSettings.displayMode !== this.settings.displayMode,
                treeMode: oldSettings.treeMode !== this.settings.treeMode,
                pagination: oldSettings.pagination !== this.settings.pagination,
                remoteUrl: oldSettings.remoteUrl !== this.settings.remoteUrl,
                fieldMapping: !this.deepEqual(oldSettings.fieldMapping, this.settings.fieldMapping),
                name: oldSettings.name !== this.settings.name || oldSettings.inputName !== this.settings.inputName,
                valueSeparator: oldSettings.valueSeparator !== this.settings.valueSeparator,
                useJsonValue: oldSettings.useJsonValue !== this.settings.useJsonValue,
                remoteMethod: oldSettings.remoteMethod !== this.settings.remoteMethod,
            };
            
            // 处理选中值的变化
            if (resetSelectedValues) {
                // 使用新配置中的value或defaultValue重置
                this.selectedValues = this.settings.multiple ? 
                    (Array.isArray(this.settings.value) ? this.settings.value : 
                    (this.settings.value ? [this.settings.value] : [])) : 
                    (this.settings.value || null);
            } else if (changes.multiple) {
                // 如果切换了单选/多选，调整selectedValues格式
                if (this.settings.multiple) {
                    // 从单选变为多选：将原值转为数组
                    if (!Array.isArray(this.selectedValues) && this.selectedValues !== null) {
                        this.selectedValues = [this.selectedValues];
                    } else if (this.selectedValues === null) {
                        this.selectedValues = [];
                    }
                } else {
                    // 从多选变为单选：取第一个值或null
                    if (Array.isArray(this.selectedValues) && this.selectedValues.length > 0) {
                        this.selectedValues = this.selectedValues[0];
                    } else {
                        this.selectedValues = null;
                    }
                }
            }
            
            // 根据配置变化更新相关部分
            
            // 更新CSS类（尺寸、显示模式、树形类等）
            this.$element.removeClass('size-sm size-medium size-lg display-mode-default display-mode-group ws-select-tree-mode last-level-horizontal');
            if (this.settings.size !== 'medium') {
                this.$element.addClass('size-' + this.settings.size);
            }
            this.$element.addClass('display-mode-' + this.settings.displayMode);
            if (this.settings.treeMode) {
                this.$element.addClass('ws-select-tree-mode');
                if (this.settings.lastLevelLayout === 'horizontal') {
                    this.$element.addClass('last-level-horizontal');
                }
            }
            
            // 更新触发器样式（禁用状态）
            if (changes.disabled) {
                if (this.settings.disabled) {
                    this.$trigger.addClass('disabled');
                } else {
                    this.$trigger.removeClass('disabled');
                }
            }
            
            // 更新清除按钮（clearable变化可能需要重建）
            if (changes.clearable || changes.disabled) {
                // 简单的处理：如果clearable变化，重新创建清除按钮
                if (this.$clearBtn) {
                    this.$clearBtn.remove();
                    this.$clearBtn = null;
                }
                if (this.settings.allowClear && !this.settings.disabled) {
                    this.$clearBtn = $('<span class="custom-select-clear"><i class="bi bi-x-circle"></i></span>');
                    this.$trigger.append(this.$clearBtn);
                    this.$clearBtn.on('click', function(e) {
                        e.stopPropagation();
                        this.clear();
                    }.bind(this));
                }
            }
            
            // 更新搜索框（searchable变化可能需要重建）
            if (changes.searchable || changes.remoteUrl) {
                var searchExists = this.$search && this.$search.length > 0;
                var needSearch = this.settings.searchable || this.settings.remoteUrl || this.settings.treeMode;
                
                if (needSearch && !searchExists) {
                    // 需要但不存在，添加搜索框
                    this.$search = $(
                        '<div class="custom-select-search">' +
                        '<input type="text" class="form-control form-control-sm" placeholder="搜索...">' +
                        '</div>'
                    );
                    this.$dropdown.prepend(this.$search);
                    this.$search.find('input').on('input', function() {
                        var searchValue = $(this).val();
                        this.handleSearch(searchValue);
                    }.bind(this));
                } else if (!needSearch && searchExists) {
                    // 不需要但存在，移除搜索框
                    this.$search.remove();
                    this.$search = null;
                }
            }
            
            // 处理分页组件的更新
            if (changes.pagination) {
                if (this.settings.pagination && !this.$pagination) {
                    this.$pagination = $('<div class="custom-select-pagination"></div>');
                    this.$dropdown.append(this.$pagination);
                } else if (!this.settings.pagination && this.$pagination) {
                    this.$pagination.remove();
                    this.$pagination = null;
                }
            }
            
            // 如果分页存在且分页相关配置变化，重新渲染分页
            if (this.$pagination && (changes.pagination || changes.pageSize || changes.pageSizeOptions)) {
                this.renderPagination();
            }
            
            // 处理树形模式变化
            if (changes.treeMode) {
                if (this.settings.treeMode) {
                    this.processTreeData();
                    if (this.settings.treeExpandAll) {
                        this.expandAllNodes();
                    }
                } else {
                    this.treeData = [];
                    this.expandedKeys.clear();
                }
            }
            
            // 处理选项数据变化
            if (changes.options || changes.treeMode || changes.fieldMapping) {
                if (this.settings.treeMode) {
                    this.processTreeData();
                } else {
                    this.localOptions = this.settings.options || [];
                }
            }
            
            // 更新隐藏input（如果名称变化或需要重新创建）
            if (changes.name && this.settings.autoCreateInput) {
                this.createHiddenInput();
            }
            
            // 重新渲染选项列表和显示值
            this.updateOptionsList();
            this.updateValueDisplay();
            
            // 如果远程URL变化且没有本地选项，重新加载数据
            if ((changes.remoteUrl && this.settings.remoteUrl && this.localOptions.length === 0) 
                || (changes.remoteMethod && typeof this.settings.remoteMethod === 'function')) {
                this.loadRemoteData();
            }
            
            // 触发配置更新事件（可选）
            this.$element.trigger('reconfigured.multiSelect', [this.settings]);
        },

        /**
         * 简单的深度比较函数（仅适用于JSON可序列化的对象）
         * @param {*} a - 比较值a
         * @param {*} b - 比较值b
         * @returns {boolean} 是否相等
         */
        deepEqual: function(a, b) {
            if (a === b) return true;
            if (a == null || b == null) return false;
            if (typeof a !== typeof b) return false;
            
            // 处理基本类型
            if (typeof a !== 'object') return a === b;
            
            // 处理数组
            if (Array.isArray(a) && Array.isArray(b)) {
                if (a.length !== b.length) return false;
                for (var i = 0; i < a.length; i++) {
                    if (!this.deepEqual(a[i], b[i])) return false;
                }
                return true;
            }
            
            // 处理对象
            var keysA = Object.keys(a);
            var keysB = Object.keys(b);
            if (keysA.length !== keysB.length) return false;
            
            for (var key in a) {
                if (!b.hasOwnProperty(key)) return false;
                if (!this.deepEqual(a[key], b[key])) return false;
            }
            return true;
        },
        /**
         * 创建隐藏input元素
         */
        createHiddenInput: function() {
            // 如果已经有隐藏input，先移除
            if (this.hiddenInput) {
                this.hiddenInput.remove();
            }
            
            // 确定input的name属性
            var inputName = this.settings.inputName || this.settings.name;
            if (!inputName) {
                // 如果没有指定name，尝试从原元素获取
                var originalInput = this.$element.find('input[type="hidden"], select').first();
                if (originalInput.length && originalInput.attr('name')) {
                    inputName = originalInput.attr('name');
                } else {
                    // 如果还没有，则不创建隐藏input
                    return;
                }
            }
            // 确定input的id属性
            var inputId = this.settings.inputId || this.settings.id;
            if (!inputId) {
                // 如果没有指定id，尝试从原元素获取
                var originalElementId = this.$element.attr('id');
                if (originalElementId) {
                    inputId = originalElementId + '_hidden';
                }
            }
            
            // 创建隐藏input
            this.hiddenInput = $('<input type="hidden">');
            
            // 设置属性
            this.hiddenInput.attr('name', inputName);
            if (inputId) {
                this.hiddenInput.attr('id', inputId);
            }
            
            // 设置初始值
            this.updateHiddenInputValue();
            
            // 添加到容器中
            this.$element.append(this.hiddenInput);
            
            // 如果原元素是select，隐藏它
            var originalSelect = this.$element.find('select').first();
            if (originalSelect.length) {
                originalSelect.hide();
                // 复制其他属性到隐藏input
                var attrsToCopy = ['required', 'data-required', 'class', 'data-validate', 'data-parsley-required'];
                attrsToCopy.forEach(function(attr) {
                    var val = originalSelect.attr(attr);
                    if (val !== undefined) {
                        this.hiddenInput.attr(attr, val);
                    }
                }.bind(this));
            }
        },

        /**
         * 更新隐藏input的值
         */
        updateHiddenInputValue: function() {
            if (!this.hiddenInput) return;
            
            var value = '';
            
            if (this.selectedValues) {
                if (this.settings.multiple) {
                    if (Array.isArray(this.selectedValues) && this.selectedValues.length > 0) {
                        if (this.settings.useJsonValue) {
                            // 使用JSON格式
                            value = JSON.stringify(this.selectedValues);
                        } else {
                            // 使用分隔符格式
                            value = this.selectedValues.join(this.settings.valueSeparator);
                        }
                    }
                } else {
                    // 单选
                    value = this.selectedValues || '';
                }
            }
            
            this.hiddenInput.val(value);
            
            // 触发change事件，以便表单验证等可以监听到
            this.hiddenInput.trigger('change');
            
            // 如果原元素是select，也更新它的值
            var originalSelect = this.$element.find('select').first();
            if (originalSelect.length) {
                if (this.settings.multiple) {
                    originalSelect.val(this.selectedValues || []);
                } else {
                    originalSelect.val(this.selectedValues || '');
                }
                originalSelect.trigger('change');
            }
        },

        /**
         * 渲染组件结构
         */
        render: function() {
            // 创建组件结构
            this.$element.empty();
            this.$element.addClass('ws-select-container display-mode-' + this.settings.displayMode);
            
            if (this.settings.size !== 'medium') {
                this.$element.addClass('size-' + this.settings.size);
            }
            
            if (this.settings.treeMode) {
                this.$element.addClass('ws-select-tree-mode');
                if (this.settings.lastLevelLayout === 'horizontal') {
                    this.$element.addClass('last-level-horizontal');
                }
            }

            // 触发器
            this.$trigger = $('<div class="ws-select-trigger" tabindex="0"></div>');
            if (this.settings.disabled) {
                this.$trigger.addClass('disabled');
            }
            
            // 显示选中值
            this.$valueDisplay = $('<div class="ws-select-value"></div>');
            this.updateValueDisplay();
            
            // 清除按钮
            if (this.settings.allowClear && !this.settings.disabled) {
                this.$clearBtn = $('<span class="ws-select-clear"><i class="bi bi-x-circle"></i></span>');
                this.$trigger.append(this.$clearBtn);
            }
            
            // 下拉箭头
            if (this.settings.showArrow) {
                this.$arrow = $('<span class="ws-select-arrow"><i class="bi bi-chevron-down"></i></span>');
                this.$trigger.append(this.$arrow);
            }
            
            this.$trigger.prepend(this.$valueDisplay);
            this.$element.append(this.$trigger);
            
            // 下拉菜单
            this.$dropdown = $('<div class="ws-select-dropdown"></div>');
            
            // 搜索框
            if (this.settings.searchable || this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') {
                this.$search = $(
                    '<div class="ws-select-search">' +
                    '<input type="text" class="form-control form-control-sm" placeholder="搜索..." value="' + this.searchValue + '">' +
                    '</div>'
                );
                this.$dropdown.append(this.$search);
            }
            
            // 选项列表
            const maxHeight = ((typeof this.settings.maxOptionHeight === 'number') ? 'max-height:' + this.settings.maxOptionHeight + 'px' : 
                              ((typeof this.settings.maxOptionHeight === 'string' && /^\d+(\.\d+)?$/.test(this.settings.maxOptionHeight)) ? 
                              'max-height:' + this.settings.maxOptionHeight + 'px' : 'max-height:' + this.settings.maxOptionHeight)) || '';

            this.$optionsList = $(`<ul class="ws-select-options" style="${maxHeight}"></ul>`);
            this.$dropdown.append(this.$optionsList);

            // 分页组件
            if (this.settings.pagination) {
                this.$pagination = $('<div class="ws-select-pagination"></div>');
                this.$dropdown.append(this.$pagination);
                this.renderPagination();
            }

            this.$element.append(this.$dropdown);

            // 隐藏 input 承载「随表单提交的真实值」。
            // init() 里 createHiddenInput() 先于本方法执行，而本方法开头的
            // $element.empty() 会把它一并清掉，且此后无任何重新挂载点 ——
            // 结果是 FormData / serialize() 拿不到值，ws-select 的值被静默丢弃。
            // 这里在渲染完成后补挂（幂等：已在 DOM 中则跳过）。
            if (this.hiddenInput && this.hiddenInput.length && !this.hiddenInput.parent().length) {
                this.$element.append(this.hiddenInput);
            }

            // 更新选项
            this.updateOptionsList();
        },

        /**
         * 更新选中值的显示
         */
        updateValueDisplay: function() {
            this.$valueDisplay.empty();
            
            if (this.selectedValues && 
                (this.settings.multiple ? this.selectedValues.length > 0 : this.selectedValues)) {
                
                if (this.settings.multiple && Array.isArray(this.selectedValues)) {
                    var $tags = $('<div class="ws-select-tags"></div>');
                    
                    this.selectedValues.forEach(function(value) {
                        var option = this.findOptionByValue(value);
                        if (option) {
                            if (typeof this.settings.onSelectValueRender === 'function') {
                                var $tag = $(
                                    '<div class="ws-select-tag">' +
                                    this.settings.onSelectValueRender(option) +
                                    (!this.settings.disabled ? '<span class="ws-select-tag-remove"><i class="bi bi-x"></i></span>' : '') +
                                    '</div>'
                                );
                            }else{
                                $tag = $(
                                    '<div class="ws-select-tag">' +
                                    '<span>' + option.label + '</span>' +
                                    (!this.settings.disabled ? '<span class="ws-select-tag-remove"><i class="bi bi-x"></i></span>' : '') +
                                    '</div>'
                                );
                            }
                            
                            $tag.data('value', value);
                            $tags.append($tag);
                        }
                    }.bind(this));
                    this.$valueDisplay.append($tags);
                } else {
                    var option = this.findOptionByValue(this.selectedValues);
                    if (option) {
                        if (typeof this.settings.onSelectValueRender === 'function') {
                            this.$valueDisplay.html(this.settings.onSelectValueRender(option));
                        } else {
                            this.$valueDisplay.text(option.label);
                        }
                    }
                }
            } else {
                this.$valueDisplay.html('<span class="ws-select-placeholder">' + this.settings.placeholder + '</span>');
            }
            // 更新隐藏input的值
            this.updateHiddenInputValue();
        },

        /**
         * 更新选项列表
         */
        updateOptionsList: function() {            
            // 显示加载状态
            if (this.isLoading) {
                this.$optionsList.append(
                    '<div class="ws-select-loading">' +
                    '<div class="spinner-border spinner-border-sm" role="status"></div>' +
                    this.settings.loadingText + 
                    '</div>'
                );
                return;
            }
            
            if (this.settings.treeMode) {
                var treeDataToRender = this.treeData;
                // 如果启用了搜索且有搜索词，过滤树数据
                if (this.searchValue && (this.settings.searchable || this.settings.remoteUrl)) {
                    treeDataToRender = this.filterTree(this.treeData, this.searchValue);
                    // 同时展开所有包含匹配项的节点路径
                    if (treeDataToRender.length > 0) {
                        this.expandedKeys = this.collectExpandKeysFromFilteredTree(treeDataToRender);
                    } else {
                        this.expandedKeys.clear();
                    }
                } else {
                    // 如果没有搜索词，恢复默认展开状态（例如 treeExpandAll 配置）
                    if (this.settings.treeExpandAll) {
                        this.expandAllNodes();
                    } else {
                        // 可以选择保持上次的展开状态，或者清空
                        // 这里为了简单，如果之前搜索过但清空了，可以不清空，或者重置
                        // 我们可以在 handleSearch 中处理保存/恢复，这里不处理
                    }
                }
                this.renderTree(treeDataToRender);
            } else {
                var optionsToShow = this.getCurrentOptions();

                // 搜索筛选
                if ((this.settings.searchable || this.settings.remoteUrl) && this.searchValue) {
                    if (this.settings.remoteUrl) {
                        // 远程搜索已在loadRemoteData中处理
                    } else if (typeof this.settings.filterOption === 'function') {
                        optionsToShow = optionsToShow.filter(function(option) {
                            return this.settings.filterOption(this.searchValue, option);
                        }.bind(this));
                    } else if (typeof this.settings.filterOption === 'boolean' && this.settings.filterOption) {
                        var searchLower = this.searchValue.toLowerCase();
                        optionsToShow = optionsToShow.filter(function(option) {
                            return option.label.toLowerCase().includes(searchLower) ||
                                (option.secondary && option.secondary.toLowerCase().includes(searchLower));
                        });
                    }
                }

                // 分页处理
                if (this.settings.pagination) {
                    // 本地分页：计算总数
                    if (!this.settings.remoteUrl && typeof this.settings.remoteMethod !== 'function') {
                        this.total = optionsToShow.length;
                    }

                    // 如果总数为0，显示无数据
                    if (this.total === 0) {
                        this.$optionsList.html('<div class="ws-select-no-options">' + this.settings.emptyText + '</div>');
                        if (this.$pagination) {
                            this.renderPagination();
                        }
                        return;
                    }
                    if (this.total == optionsToShow.length) {
                        var start = (this.currentPage - 1) * this.pageSize;
                        var end = start + this.pageSize;
                        optionsToShow = optionsToShow.slice(start, end);
                    }
                }

                // 分组处理
                var groupedOptions = this.groupOptions(optionsToShow);

                // 渲染选项
                if (groupedOptions.length === 0) {
                    this.$optionsList.html('<div class="ws-select-no-options">' + this.settings.emptyText + '</div>');
                } else {
                    this.$optionsList.empty();
                    groupedOptions.forEach(function(group) {
                        if (group.group && this.settings.displayMode === 'group') {
                            this.$optionsList.append('<div class="ws-select-group"><i class="bi bi-view-list me-2"></i>' + group.group + '</div>');
                        }
                        
                        group.options.forEach(function(option) {
                            this.renderOption(option);
                        }.bind(this));
                    }.bind(this));
                }
            }
        },

        /**
         * 过滤树数据（保留匹配节点及其祖先）
         * @param {Array} nodes - 树节点数组
         * @param {string} searchValue - 搜索关键词
         * @returns {Array} 过滤后的树数据
         */
        filterTree: function(nodes, searchValue) {
            var self = this;
            var searchLower = searchValue.toLowerCase();
            var result = [];
            
            nodes.forEach(function(node) {
                // 克隆节点以避免修改原始数据
                var nodeCopy = $.extend(true, {}, node);
                
                // 检查当前节点是否匹配
                var matches = node.label.toLowerCase().includes(searchLower) ||
                            (node.secondary && node.secondary.toLowerCase().includes(searchLower));
                
                // 如果有子节点，递归过滤子节点
                var filteredChildren = [];
                if (node.children && node.children.length > 0) {
                    filteredChildren = self.filterTree(node.children, searchValue);
                }
                
                // 如果当前节点匹配或有匹配的子节点，则保留
                if (matches || filteredChildren.length > 0) {
                    if (filteredChildren.length > 0) {
                        nodeCopy.children = filteredChildren;
                    } else {
                        delete nodeCopy.children; // 如果没有子节点保留，则删除children属性
                    }
                    result.push(nodeCopy);
                }
            });
            
            return result;
        },

        /**
         * 从过滤后的树中收集所有需要展开的节点 key
         * @param {Array} filteredTree - 过滤后的树数据
         * @returns {Set} 包含所有节点 value 的 Set
         */
        collectExpandKeysFromFilteredTree: function(filteredTree) {
            var keys = new Set();
            var collect = function(nodes) {
                nodes.forEach(function(node) {
                    keys.add(node.value);
                    if (node.children && node.children.length > 0) {
                        collect(node.children);
                    }
                });
            };
            collect(filteredTree);
            return keys;
        },

        /**
         * 渲染树形结构
         * @param {Array} [treeData] - 要渲染的树数据，默认为 this.treeData
         */
        renderTree: function(treeData) {
            treeData = treeData || this.treeData;

            if (treeData && treeData.length === 0) {
                this.$optionsList.html(`<div class="ws-select-no-options">${this.settings.emptyText}</div>`);
                return;
            }
            var renderTreeNode = function(node, level) {
                var $node = $('<li class="ws-select-tree-node"></li>');
                var $content = $('<div class="ws-select-tree-node-content"></div>');

                // 判断是否最后一级并且需要横向排列
                var isLastLevelHorizontal = level >= 0 && this.settings.lastLevelLayout === 'horizontal';

                // 不是最后一级或者不需要横向排列时添加缩进
                if (!isLastLevelHorizontal) {
                    for (var i = 0; i < level; i++) {
                        $content.append('<span class="ws-select-tree-node-indent"></span>');
                    }
                }
                
                var hasChildren = node.children && node.children.length > 0;
                var isExpanded = this.expandedKeys.has(node.value);

                // 折叠/展开按钮
                if (hasChildren) {
                    var $toggle = $('<span class="ws-select-tree-node-toggle"><i class="bi bi-caret-right-fill"></i></span>');
                    if (isExpanded) {
                        $toggle.addClass('expanded');
                    }
                    $content.append($toggle);
                    $toggle.data('node', node);
                } else {
                    // $content.append('<span class="ws-select-tree-node-toggle" style="visibility: hidden;"><i class="bi bi-caret-right-fill"></i></span>');
                }
                
                // 选项内容
                var isSelected = this.selectedValues && this.selectedValues.includes(node.value);
                var isDisabled = node.disabled || this.settings.disabled;
                
                if (this.settings.treeCheckable && this.settings.multiple) {
                    var $checkbox = $('<span class="ws-select-tree-checkbox"><i class="bi"></i></span>');
                    
                    // 计算半选状态（非严格模式下）
                    var isIndeterminate = false;
                    if (!this.settings.treeCheckStrictly && hasChildren) {
                        var childSelectionState = this.getChildSelectionState(node);
                        if (childSelectionState.someSelected && !childSelectionState.allSelected) {
                            isIndeterminate = true;
                        }
                    }
                    
                    if (isIndeterminate) {
                        $checkbox.find('i').addClass('bi-dash-square');
                    } else if (isSelected) {
                        $checkbox.find('i').addClass('bi-check-square-fill');
                    } else {
                        $checkbox.find('i').addClass('bi-square');
                    }
                    
                    $content.append($checkbox);
                }

                if (isSelected) {
                    $content.addClass('selected');
                }
                if (isDisabled) {
                    $content.addClass('disabled');
                }
                if (isIndeterminate) {
                    $content.addClass('indeterminate');
                }

                var $optionContent = $('<div class="ws-select-option-content"></div>');
                if (node.icon) {
                    $optionContent.append('<span class="ws-select-option-icon"><i class="' + node.icon + '"></i></span>');
                }
                $optionContent.append('<span class="ws-select-option-main">' + node.label + '</span>');
                if (node.secondary && !isLastLevelHorizontal) {
                    $optionContent.append('<span class="ws-select-option-secondary">' + node.secondary + '</span>');
                }
                
                $content.append($optionContent);
                $content.data('node', node);
                $node.append($content);
                
                // 子节点
                if (hasChildren) {
                    var $children = $('<ul class="ws-select-tree-node-children"></ul>');
                    if (isExpanded) {
                        $children.addClass('expanded');
                    }
                    
                    // 判断是否最后一级并且需要横向排列
                    if (isLastLevelHorizontal && node.children.every(function(child) {
                        return !child.children || child.children.length === 0;
                    })) {
                        $children.addClass('horizontal');
                    }
                    
                    node.children.forEach(function(child) {
                        $children.append(renderTreeNode.call(this, child, level + 1));
                    }.bind(this));
                    
                    $node.append($children);
                }
                
                return $node;
            }.bind(this);
            
            this.$optionsList.empty();
            treeData.forEach(function(node) {
                this.$optionsList.append(renderTreeNode.call(this, node, 0));
            }.bind(this));
        },

        /**
         * 获取子节点的选择状态
         * @param {Object} node - 父节点
         * @returns {Object} 包含 someSelected 和 allSelected 的对象
         */
        getChildSelectionState: function(node) {
            var result = {
                someSelected: false,
                allSelected: true,
                total: 0,
                selected: 0
            };
            
            var countSelected = function(childNode) {
                result.total++;
                var isSelected = this.selectedValues.indexOf(childNode.value) !== -1;
                if (isSelected) {
                    result.selected++;
                    result.someSelected = true;
                } else {
                    result.allSelected = false;
                }
                
                if (childNode.children && childNode.children.length > 0) {
                    childNode.children.forEach(function(grandChild) {
                        countSelected.call(this, grandChild);
                    }.bind(this));
                }
            }.bind(this);
            
            if (node.children && node.children.length > 0) {
                node.children.forEach(function(child) {
                    countSelected.call(this, child);
                }.bind(this));
            }
            
            return result;
        },

        /**
         * 获取当前选中的所有节点值（包括子节点）
         * @returns {Array} 选中的节点值数组
         */
        getCheckedValues: function() {
            if (this.settings.treeCheckStrictly) {
                // 严格模式：只返回直接选中的节点
                return this.selectedValues;
            } else {
                // 非严格模式：返回所有选中的节点，包括由于父节点选中而自动选中的子节点
                var allChecked = [];
                
                var collectChecked = function(nodes) {
                    nodes.forEach(function(node) {
                        if (this.selectedValues.indexOf(node.value) !== -1) {
                            allChecked.push(node.value);
                            // 如果父节点被选中，收集所有子节点
                            if (node.children && node.children.length > 0) {
                                this.collectAllChildrenValues(node, allChecked);
                            }
                        } else if (node.children && node.children.length > 0) {
                            // 递归检查子节点
                            collectChecked.call(this, node.children);
                        }
                    }.bind(this));
                }.bind(this);
                
                collectChecked.call(this, this.treeData);
                return [...new Set(allChecked)]; // 去重
            }
        },

        /**
         * 收集节点的所有子节点值
         * @param {Object} node - 父节点
         * @param {Array} result - 结果数组
         */
        collectAllChildrenValues: function(node, result) {
            if (!node.children || node.children.length === 0) return;
            
            node.children.forEach(function(child) {
                result.push(child.value);
                this.collectAllChildrenValues(child, result);
            }.bind(this));
        },

        /**
         * 处理树形数据
         */
        processTreeData: function() {
            var mapData = function(data) {
                return data.map(function(item) {
                    var node = {
                        value: item[this.settings.treeFieldMapping.value],
                        label: item[this.settings.treeFieldMapping.label],
                        disabled: item[this.settings.treeFieldMapping.disabled] || false,
                        icon: item[this.settings.treeFieldMapping.icon],
                        secondary: item[this.settings.treeFieldMapping.secondary],
                        isLeaf: item[this.settings.treeFieldMapping.isLeaf] || false
                    };
                    
                    if (item[this.settings.treeFieldMapping.children] && item[this.settings.treeFieldMapping.children].length > 0) {
                        node.children = mapData.call(this, item[this.settings.treeFieldMapping.children]);
                    }
                    
                    return node;
                }.bind(this));
            }.bind(this);
            
            this.treeData = mapData.call(this, this.settings.options);
            return this.treeData;
        },

        /**
         * 获取所有树节点的扁平化列表
         * @returns {Array} 所有树节点的数组
         */
        getAllTreeNodes: function() {
            var allNodes = [];
            
            var traverse = function(nodes) {
                nodes.forEach(function(node) {
                    allNodes.push(node);
                    if (node.children && node.children.length > 0) {
                        traverse(node.children);
                    }
                });
            };
            
            traverse(this.treeData);
            return allNodes;
        },

        /**
         * 展开所有节点
         */
        expandAllNodes: function() {
            var expandNode = function(node) {
                this.expandedKeys.add(node.value);
                if (node.children && node.children.length > 0) {
                    node.children.forEach(function(child) {
                        expandNode.call(this, child);
                    }.bind(this));
                }
            }.bind(this);
            
            this.treeData.forEach(function(node) {
                expandNode.call(this, node);
            }.bind(this));
        },
        
        /**
         * 获取当前显示的选项（本地或远程）
         * @returns {Array} 选项数组
         */
        getCurrentOptions: function() {
            if (this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') {
                // 如果有远程数据源，优先使用远程数据
                if (this.searchValue && this.searchValue.length >= (typeof this.settings.minSearchLength === 'number' ? this.settings.minSearchLength : 1)) {
                    return this.remoteData;
                } else if (!this.searchValue && this.remoteData.length > 0) {
                    // 没有搜索词但已有远程数据时，显示远程数据
                    return this.remoteData;
                }
            }
            return this.localOptions;
        },

        /**
         * 对选项进行分组处理
         * @param {Array} options - 选项数组
         * @returns {Array} 分组后的选项数组
         */
        groupOptions: function(options) {
            var groups = {};
            var ungrouped = [];
            
            options.forEach(function(option) {
                if (option.group && this.settings.displayMode === 'group') {
                    if (!groups[option.group]) {
                        groups[option.group] = [];
                    }
                    groups[option.group].push(option);
                } else {
                    ungrouped.push(option);
                }
            }.bind(this));
            
            var result = [];
            
            // 添加未分组的选项
            if (ungrouped.length > 0) {
                result.push({
                    group: null,
                    options: ungrouped
                });
            }
            
            // 添加分组的选项
            Object.keys(groups).forEach(function(groupName) {
                result.push({
                    group: groupName,
                    options: groups[groupName]
                });
            });
            
            return result;
        },

        /**
         * 渲染单个选项
         * @param {Object} option - 选项对象
         */
        renderOption: function(option) {
            var isSelected = this.settings.multiple ? 
                this.selectedValues.includes(option.value) : 
                this.selectedValues === option.value;
            
            var isDisabled = option.disabled || this.settings.disabled;
            var $option = $(
                '<li class="ws-select-option ' + (isSelected ? 'selected' : '') + ' ' + (isDisabled ? 'disabled' : '') + '">' +
                '<div class="ws-select-option-content ' + (this.settings.secondaryColumn ? ' secondary-column' : '') + '">' +
                (option.icon ? '<span class="ws-select-option-icon"><i class="' + option.icon + '"></i></span>' : '') +
                '<span class="ws-select-option-main">' + option.label + '</span>' +
                (option.secondary ? '<span class="ws-select-option-secondary">' + option.secondary + '</span>' : '') +
                '</div>' +
                '</li>'
            );
            
            $option.data('option', option);
            this.$optionsList.append($option);
        },

        /**
         * 根据值查找选项
         * @param {*} value - 选项值
         * @returns {Object|null} 找到的选项对象，未找到返回null
         */
        findOptionByValue: function(value) {
            // 如果是树形模式，从 treeData 中查找
            if (this.settings.treeMode) {
                return this.findTreeNodeByValue(value);
            }

            var allOptions = this.localOptions.concat(this.remoteData);
            return allOptions.find(function(option) {
                return option.value === value;
            });
        },

        /**
         * 根据值查找树节点
         * @param {*} value - 节点值
         * @param {Array} [nodes] - 要搜索的节点数组（默认为根节点）
         * @returns {Object|null} 找到的节点对象，未找到返回null
         */
        findTreeNodeByValue: function(value, nodes) {
            if (!value) return null;
            
            nodes = nodes || this.treeData;
            var result = null;
            
            // 递归搜索树
            var searchTree = function(nodes) {
                for (var i = 0; i < nodes.length; i++) {
                    var node = nodes[i];
                    
                    // 检查当前节点
                    if (node.value === value) {
                        result = node;
                        return true;
                    }
                    
                    // 如果有子节点，递归搜索
                    if (node.children && node.children.length > 0) {
                        if (searchTree(node.children)) {
                            return true;
                        }
                    }
                }
                return false;
            };
            
            searchTree(nodes);
            return result;
        },

        /**
         * 渲染分页组件
         */
        renderPagination: function () {
            if (!this.$pagination) return;

            this.$pagination.empty();

            // 计算总页数 - 处理total为0的情况
            var totalItems = this.total || 0;
            var totalPages = totalItems > 0 ? Math.ceil(totalItems / this.pageSize) : 0;

            // 如果没有数据且不显示总数，隐藏分页组件
            if (totalItems === 0 && !this.settings.showTotal) {
                this.$pagination.hide();
                return;
            } else {
                this.$pagination.show();
            }

            // 分页信息
            if (this.settings.showTotal) {
                var $info = $('<span class="ws-select-pagination-info"></span>');
                var infoText = '共 ' + totalItems + ' 条';
                $info.text(infoText);
                this.$pagination.append($info);
            }

            // 如果没有数据，不显示分页控制按钮
            if (totalPages <= 0) {
                return;
            }

            // 分页控制
            var $controls = $('<div class="ws-select-pagination-controls"></div>');

            // 上一页按钮
            var $prevBtn = $('<button class="ws-select-pagination-btn" type="button" title="上一页" role="prev"><i class="bi bi-chevron-left"></i></button>');
            if (this.currentPage <= 1) {
                $prevBtn.prop('disabled', true);
            }
            $controls.append($prevBtn);

            // 添加分页信息
            $controls.append(`<span class="ws-select-pagination-info">${this.currentPage} / ${totalPages}</span>`);

            // 下一页按钮
            var $nextBtn = $('<button class="ws-select-pagination-btn" type="button" title="下一页" role="next"><i class="bi bi-chevron-right"></i></button>');
            if (this.currentPage >= totalPages) {
                $nextBtn.prop('disabled', true);
            }
            $controls.append($nextBtn);

            // 每页数量选择器
            if (this.settings.showSizeChanger) {
                var $sizeSelect = $('<select class="ws-select-pagination-size"></select>');
                this.settings.pageSizeOptions.forEach(function (size) {
                    var $option = $('<option value="' + size + '">' + size + ' 条/页</option>');
                    if (size === this.pageSize) {
                        $option.prop('selected', true);
                    }
                    $sizeSelect.append($option);
                }.bind(this));
                $controls.append($sizeSelect);
            }

            this.$pagination.append($controls);
        },
        
        /**
         * 绑定事件处理函数
         */
        bindEvents: function() {
            var self = this;
            
            // 点击触发器
            this.$trigger.on('click', function(e) {
                if (!self.settings.disabled) {
                    self.toggleDropdown();
                }
            });
            
            // 清除按钮
            if (this.$clearBtn) {
                this.$clearBtn.on('click', function(e) {
                    e.stopPropagation();
                    self.clear();
                });
            }
            
            // 搜索输入
            if (this.$search) {
                this.$search.find('input').on('input', function() {
                    var searchValue = $(this).val();
                    self.handleSearch(searchValue);
                });
            }
            
            // 选项点击
            this.$optionsList.on('click', '.ws-select-option:not(.disabled)', function(e) {
                var option = $(this).data('option');
                self.selectOption(option);
                if (!self.settings.multiple) {
                    self.closeDropdown();
                }else{
                    e.stopPropagation();
                }
            });
            
            // 树形节点点击
            this.$optionsList.on('click', '.ws-select-tree-node-content:not(.disabled)', function(e) {
                e.stopPropagation();
                var node = $(this).data('node');
                self.selectTreeNode(node);
                
                if (!self.settings.multiple) {
                    self.closeDropdown();
                }
            });
            
            // 树形节点展开/折叠
            this.$optionsList.on('click', '.ws-select-tree-node-toggle', function(e) {
                e.stopPropagation();
                var node = $(this).data('node');
                self.toggleTreeNode(node);
            });

            // 移除标签
            this.$valueDisplay.on('click', '.ws-select-tag-remove', function(e) {
                e.stopPropagation();
                var $tag = $(this).closest('.ws-select-tag');
                var value = $tag.data('value');
                self.deselectOption(value);
            });
            
            // 点击外部关闭下拉
            $(document).on('click.wsSelect', function(e) {
                if (!$(e.target).closest('.ws-select-container').is(self.$element)) {
                    self.closeDropdown();
                }
            });
            
            // 分页按钮点击（使用事件委托，处理动态生成的元素）
            if (this.$pagination) {
                this.$pagination.on('click', '.ws-select-pagination-btn', function(e) {
                    e.stopPropagation();
                    var $btn = $(this);
                    if ($btn.prop('disabled')) return;
                    
                    var isPrev = $btn.attr('role') === 'prev';
                    var isNext = $btn.attr('role') === 'next';
                    
                    if (isPrev && self.currentPage > 1) {
                        self.goToPage(self.currentPage - 1);
                    } else if (isNext && self.currentPage < Math.ceil(self.total / self.pageSize)) {
                        self.goToPage(self.currentPage + 1);
                    }
                });
                
                this.$pagination.on('click', '.ws-select-pagination-page:not(.disabled):not(.active)', function(e) {
                    e.stopPropagation();
                    var page = $(this).data('page');
                    if (page) {
                        self.goToPage(parseInt(page));
                    }
                });
                
                this.$pagination.on('change', '.ws-select-pagination-size', function(e) {
                    e.stopPropagation();
                    var newSize = parseInt($(this).val());
                    if (newSize !== self.pageSize && !isNaN(newSize)) {
                        self.pageSize = newSize;
                        self.currentPage = 1;
                        if (self.settings.remoteUrl || typeof self.settings.remoteMethod === 'function') {
                            self.loadRemoteData(self.searchValue);
                        } else {
                            self.updateOptionsList();
                            self.renderPagination();
                        }
                    }
                });
            }
            
            // 键盘导航
            this.$element.on('keydown', function(e) {
                self.handleKeyDown(e);
            });
        },

        /**
         * 处理搜索输入
         * @param {string} searchValue - 搜索关键词
         */
        handleSearch: function(searchValue) {
            this.searchValue = searchValue;
            this.currentPage = 1;

            // 远程搜索
            if (this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') {
                // 清除之前的计时器
                if (this.remoteSearchTimer) {
                    clearTimeout(this.remoteSearchTimer);
                }
                
                // 设置新的计时器
                this.remoteSearchTimer = setTimeout(function() {
                    if (searchValue.length >= this.settings.minSearchLength || searchValue.length === 0) {
                        this.loadRemoteData(searchValue);
                    }
                }.bind(this), (typeof this.settings.remoteDelay === 'number' ? this.settings.remoteDelay : 300));
            } 
            // 本地搜索
            else {
                // 树形模式下，如果搜索清空，可能需要恢复展开状态
                if (this.settings.treeMode && searchValue.length === 0) {
                    // 恢复默认展开状态
                    if (this.settings.treeExpandAll) {
                        this.expandAllNodes();
                    } else {
                        this.expandedKeys.clear();
                    }
                }

                this.updateOptionsList();

                if (this.settings.pagination) {
                    this.renderPagination();
                }
            }
            
            if (typeof this.settings.onSearch === 'function') {
                this.settings.onSearch(searchValue);
            }
        },

        /**
         * 加载远程数据
         * @param {string} searchValue - 搜索关键词
         */
        loadRemoteData: function(searchValue) {
            var self = this;
            
            // 显示加载状态
            this.isLoading = true;
            // this.updateOptionsList();
            
            // 准备请求参数
            var requestData = $.extend(true, {}, this.settings.ajaxOptions.data || {});
            if (searchValue) {
                requestData.search = searchValue;
                requestData.q = searchValue;
            }
            
            // 添加分页参数
            if (this.settings.pagination) {
                var mapping = this.settings.paginationFieldMapping;
                requestData[mapping.page] = this.currentPage;
                requestData[mapping.pageSize] = this.pageSize;
            }
            
            var ajaxOptions = $.extend(true, {
                url: this.settings.remoteUrl,
                method: 'GET',
                data: requestData,
                dataType: 'json'
            }, this.settings.ajaxOptions);

            // 使用自定义远程方法或默认AJAX
            var request;
            if (typeof this.settings.remoteMethod === 'function') {
                request = this.settings.remoteMethod(searchValue, this.currentPage, this.pageSize);
                // 处理 Promise 返回值
                if (request && typeof request.then === 'function') {
                } else {
                    // 如果不是 Promise，包装成 resolved Promise
                    request = Promise.resolve(request);
                }
            } else if (this.settings.remoteUrl) {
                // 使用默认 AJAX
                var ajaxOptions = $.extend(true, {
                    url: this.settings.remoteUrl,
                    method: 'GET',
                    data: requestData,
                    dataType: 'json'
                }, this.settings.ajaxOptions);
                request = $.ajax(ajaxOptions);
            } else {
                // 没有配置远程数据源，直接返回
                this.isLoading = false;
                this.updateOptionsList();
                return;
            }
            
            // 统一处理请求结果
            var handleSuccess = function(response) {
                var mapping = self.settings.paginationFieldMapping;
                var data = self.getNestedValue(response, mapping.data) || response.results || response.list || response.items || response;
                // 处理分页数据
                if (self.settings.pagination) {
                    var total = self.getNestedValue(response, mapping.total) || response.total || response.totalCount || response.totalRecords || 
                       response[mapping.total + 'Count'] || response[mapping.total + 'Records'] || 
                       (Array.isArray(data) ? data.length : 0);
                    total = parseInt(total) || 0;

                    self.total = total;
                }

                if (self.settings.treeMode) {
                    self.settings.options = data;
                    self.treeData = self.processTreeData();
                }else{
                    self.remoteData = self.transformRemoteData(data);
                }

                self.isLoading = false;
                self.updateOptionsList();
                if (self.settings.pagination) {
                    self.renderPagination();
                }
            };
            
            var handleError = function(xhr, status, error) {
                console.error('远程数据加载失败:', error);
                self.remoteData = [];
                self.isLoading = false;
                self.updateOptionsList();
                
                if (self.settings.pagination) {
                    self.renderPagination();
                }

                // 调用错误回调
                if (typeof self.settings.onError === 'function') {
                    self.settings.onError(xhr, status, error);
                }
            };

            // 处理不同类型的请求对象
            if (request && typeof request.then === 'function') {
                // Promise 风格
                request
                    .then(handleSuccess)
                    .catch(function(error) {
                        handleError(null, 'error', error);
                    });
            } else if (request && typeof request.done === 'function') {
                // jQuery AJAX 风格
                request
                    .done(handleSuccess)
                    .fail(handleError);
            } else {
                // 直接数据
                handleSuccess(request);
            }
        },

        /**
         * 获取嵌套对象的属性值
         * @param {*} obj - 源对象
         * @param {string} path - 属性路径（例如：'a.b.c'）
         * @returns {*} 属性值或undefined
         */
        getNestedValue: function(obj, path) {
            return path.split('.').reduce(function(current, key) {
                return current && current[key] !== undefined ? current[key] : undefined;
            }, obj);
        },
        
        /**
         * 转换远程数据格式
         * @param {*} response - 远程响应数据
         * @returns {Array} 转换后的选项数组
         */
        transformRemoteData: function(response) {
            var data = response;
            
            // 如果响应有data字段，使用data字段
            if (response && response.data !== undefined) {
                data = response.data;
            }
            
            // 如果响应有results字段，使用results字段（常见于分页API）
            if (response && response.results !== undefined) {
                data = response.results;
            }

            // 确保是数组
            if (!Array.isArray(data)) {
                data = [data];
            }
            
            // 应用字段映射
            return data.map(function(item) {
                var mappedItem = {};
                var mapping = typeof this.settings.fieldMapping === 'object' ? this.settings.fieldMapping : {};
                
                for (var key in mapping) {
                    if (mapping.hasOwnProperty(key)) {
                        var sourceField = mapping[key];
                        if (item.hasOwnProperty(sourceField)) {
                            mappedItem[key] = item[sourceField];
                        } else {
                            mappedItem[key] = null;
                        }
                    }
                }

                // 确保必要的字段有值
                if (!mappedItem.value && item) {
                    mappedItem.value = item.id || item.value || Math.random().toString(36).substring(2, 9);
                }
                if (!mappedItem.label && item) {
                    mappedItem.label = item.name || item.label || '未知选项';
                }

                return mappedItem;
            }.bind(this));
        },

        /**
         * 跳转到指定页码
         * @param {number} page - 页码
         */
        goToPage: function(page) {
            if (page === this.currentPage || page < 1) return;
            
            var totalPages = Math.ceil(this.total / this.pageSize) || 1;
            if (page > totalPages) {
                page = totalPages;
            }
            
            this.currentPage = page;
            
            if (this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') {
                this.loadRemoteData(this.searchValue);
            } else {
                this.updateOptionsList();
                this.renderPagination();
            }
        },

        /**
         * 选择树形节点
         * @param {Object} node - 树节点
         */
        selectTreeNode: function(node) {
            if (node.disabled || this.settings.disabled) return;
            
            if (this.settings.treeCheckable && this.settings.multiple) {
                // 树形多选模式
                var index = this.selectedValues.indexOf(node.value);
                var wasSelected = index !== -1;
                
                if (this.settings.treeCheckStrictly) {
                    // 严格模式：只切换当前节点
                    if (wasSelected) {
                        this.selectedValues.splice(index, 1);
                    } else {
                        this.selectedValues.push(node.value);
                    }
                } else {
                    // 非严格模式：处理父子节点关联
                    if (wasSelected) {
                        // 取消选中：移除当前节点及其所有子节点
                        this.selectedValues.splice(index, 1);
                        this.deselectAllChildren(node);
                    } else {
                        // 选中：添加当前节点及其所有子节点
                        this.selectedValues.push(node.value);
                        this.selectAllChildren(node);
                    }
                    
                    // 更新父节点状态
                    this.updateParentNodes(node);
                }
            } else {
                // 树形单选模式
                this.selectedValues = node.value;
            }
            
            this.updateValueDisplay();
            this.updateOptionsList();
            
            if (this.settings.onChange) {
                this.settings.onChange(this.selectedValues, node);
            }
        },

        /**
         * 递归选中所有子节点
         * @param {Object} node - 父节点
         */
        selectAllChildren: function(node) {
            if (!node.children || node.children.length === 0) return;
            
            node.children.forEach(function(child) {
                if (!child.disabled && this.selectedValues.indexOf(child.value) === -1) {
                    this.selectedValues.push(child.value);
                }
                this.selectAllChildren(child);
            }.bind(this));
        },

        /**
         * 递归取消选中所有子节点
         * @param {Object} node - 父节点
         */
        deselectAllChildren: function(node) {
            if (!node.children || node.children.length === 0) return;
            
            node.children.forEach(function(child) {
                var index = this.selectedValues.indexOf(child.value);
                if (index !== -1) {
                    this.selectedValues.splice(index, 1);
                }
                this.deselectAllChildren(child);
            }.bind(this));
        },

        /**
         * 更新父节点选择状态（非严格模式）
         * @param {Object} node - 当前节点
         */
        updateParentNodes: function(node) {
            if (this.settings.treeCheckStrictly) return;
            
            // 查找并更新所有父节点的选中状态
            var updateParent = function(childNode) {
                var parent = this.findParentNode(childNode);
                if (parent) {
                    this.updateNodeSelectionState(parent);
                    updateParent.call(this, parent);
                }
            }.bind(this);
            
            updateParent(node);
        },

        /**
         * 查找节点的父节点
         * @param {Object} childNode - 子节点
         * @returns {Object|null} 父节点
         */
        findParentNode: function(childNode) {
            var result = null;
            
            var searchParent = function(nodes, parent) {
                for (var i = 0; i < nodes.length; i++) {
                    var node = nodes[i];
                    if (node.value === childNode.value) {
                        return parent;
                    }
                    if (node.children && node.children.length > 0) {
                        var found = searchParent(node.children, node);
                        if (found) return found;
                    }
                }
                return null;
            };
            
            return searchParent(this.treeData, null);
        },

        /**
         * 更新节点的选择状态（考虑半选状态）
         * @param {Object} node - 节点
         */
        updateNodeSelectionState: function(node) {
            if (!node.children || node.children.length === 0) return;
            
            var allChildrenSelected = true;
            var someChildrenSelected = false;
            
            // 计算子节点的选中状态
            var checkChildren = function(children) {
                children.forEach(function(child) {
                    var isSelected = this.selectedValues.indexOf(child.value) !== -1;
                    if (isSelected) someChildrenSelected = true;
                    if (!isSelected) allChildrenSelected = false;
                    
                    if (child.children && child.children.length > 0) {
                        checkChildren.call(this, child.children);
                    }
                }.bind(this));
            }.bind(this);
            
            checkChildren(node.children);
            
            // 更新当前节点的选中状态
            var nodeIndex = this.selectedValues.indexOf(node.value);
            
            if (allChildrenSelected && node.children.length > 0) {
                // 所有子节点都选中，自动选中父节点
                if (nodeIndex === -1) {
                    this.selectedValues.push(node.value);
                }
            } else if (someChildrenSelected) {
                // 部分子节点选中，父节点处于半选状态
                // 这里我们不改变父节点的实际选中状态，但会在渲染时标记为半选
            } else {
                // 没有子节点选中，取消父节点选中
                if (nodeIndex !== -1) {
                    this.selectedValues.splice(nodeIndex, 1);
                }
            }
        },

        /**
         * 切换树形节点展开/折叠
         * @param {Object} node - 树节点
         */
        toggleTreeNode: function(node) {
            if (this.expandedKeys.has(node.value)) {
                this.expandedKeys.delete(node.value);
            } else {
                this.expandedKeys.add(node.value);
            }
            this.updateOptionsList();
        },
        
        /**
         * 切换下拉菜单显示状态
         */
        toggleDropdown: function() {
            if (this.isOpen) {
                this.closeDropdown();
            } else {
                this.openDropdown();
            }
        },

        /**
         * 打开下拉菜单
         */
        openDropdown: function() {
            this.isOpen = true;
            this.$dropdown.addClass('show');
            if (this.$arrow) {
                this.$arrow.addClass('rotated');
            }
            
            if (this.settings.searchable || this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') {
                this.$search.find('input').focus();
            }
            
            if (typeof this.settings.onFocus === 'function') {
                this.settings.onFocus();
            }
        },

        /**
         * 关闭下拉菜单
         */
        closeDropdown: function() {
            if(!this.$dropdown.hasClass('show')){
                return;
            }

            this.isOpen = false;
            this.$dropdown.removeClass('show');
            if (this.$arrow) {
                this.$arrow.removeClass('rotated');
            }
            
            // 当有搜索功能或远程URL或远程方法时，清空搜索值
            if (this.settings.autoClearSearchValue && 
                    (this.settings.searchable || this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function')) {
                this.searchValue = '';
                this.$search.find('input').val('');
                if (!this.settings.remoteUrl && typeof this.settings.remoteMethod !== 'function') {
                    this.updateOptionsList();
                } else {
                    this.handleSearch('');
                }
            }
            
            this.focusedIndex = -1;
            
            if (typeof this.settings.onBlur === 'function') {
                this.settings.onBlur();
            }
        },

        /**
         * 选择选项
         * @param {Object} option - 要选择的选项对象
         */
        selectOption: function(option) {
            if (this.settings.multiple) {
                if (!this.selectedValues.includes(option.value)) {
                    this.selectedValues.push(option.value);
                } else {
                    this.selectedValues = this.selectedValues.filter(function(v) {
                        return v !== option.value;
                    });
                }
            } else {
                this.selectedValues = option.value;
            }
            
            this.updateValueDisplay();
            this.updateOptionsList();
            
            if (typeof this.settings.onChange === 'function') {
                this.settings.onChange(this.selectedValues, option);
            }
        },

        /**
         * 取消选择选项
         * @param {*} value - 要取消选择的选项值
         */
        deselectOption: function(value) {
            if (this.settings.multiple) {
                this.selectedValues = this.selectedValues.filter(function(v) {
                    return v !== value;
                });
                this.updateValueDisplay();
                this.updateOptionsList();
                
                if (typeof this.settings.onChange === 'function') {
                    this.settings.onChange(this.selectedValues, null);
                }
            }
        },

        /**
         * 清除所有选中项
         */
        clear: function() {
            this.selectedValues = this.settings.multiple ? [] : null;
            this.updateValueDisplay();
            this.updateOptionsList();
            
            if (typeof this.settings.onChange === 'function') {
                this.settings.onChange(this.selectedValues, null);
            }
        },

        /**
         * 处理键盘事件
         * @param {Event} e - 键盘事件对象
         */
        handleKeyDown: function(e) {
            if (!this.isOpen) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.openDropdown();
                }
                return;
            }
            
            var options = this.$optionsList.find('.ws-select-option:not(.disabled)');
            
            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.focusedIndex = this.focusedIndex < options.length - 1 ? this.focusedIndex + 1 : 0;
                    this.updateFocus();
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    this.focusedIndex = this.focusedIndex > 0 ? this.focusedIndex - 1 : options.length - 1;
                    this.updateFocus();
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (this.focusedIndex >= 0) {
                        var option = $(options[this.focusedIndex]).data('option');
                        this.selectOption(option);
                        if (!this.settings.multiple) {
                            this.closeDropdown();
                        }
                    }
                    break;
                case 'Escape':
                    e.preventDefault();
                    this.closeDropdown();
                    break;
                case 'Tab':
                    this.closeDropdown();
                    break;
            }
        },

        /**
         * 更新键盘导航焦点
         */
        updateFocus: function() {
            this.$optionsList.find('.ws-select-option').removeClass('focused');
            if (this.focusedIndex >= 0) {
                var $option = this.$optionsList.find('.ws-select-option:not(.disabled)').eq(this.focusedIndex);
                $option.addClass('focused');
                $option[0].scrollIntoView({ block: 'nearest' });
            }
        },

        /**
         * 获取当前选中的值
         * @returns {*|Array} 当前选中的值
         */
        getValue: function() {
            return this.selectedValues;
        },

        /**
         * 设置选中的值
         * @param {*|Array} value - 要设置的值
         */
        setValue: function(value) {
            this.selectedValues = this.settings.multiple ? 
                (Array.isArray(value) ? value : [value]) : 
                value;
            this.updateValueDisplay();
            this.updateOptionsList();
        },

        /**
         * 获取隐藏input的DOM元素
         * @returns {HTMLElement|null} 隐藏input元素
         */
        getHiddenInput: function() {
            return this.hiddenInput ? this.hiddenInput[0] : null;
        },

        /**
         * 获取隐藏input的值
         * @returns {string} 隐藏input的值
         */
        getHiddenInputValue: function() {
            return this.hiddenInput ? this.hiddenInput.val() : '';
        },

        /**
         * 同步到原始select元素
         */
        syncToOriginalSelect: function() {
            var originalSelect = this.$element.find('select').first();
            if (originalSelect.length) {
                if (this.settings.multiple) {
                    originalSelect.val(this.selectedValues || []);
                } else {
                    originalSelect.val(this.selectedValues || '');
                }
            }
        },

        /**
         * 从原始select元素同步
         */
        syncFromOriginalSelect: function() {
            var originalSelect = this.$element.find('select').first();
            if (originalSelect.length) {
                if (this.settings.multiple) {
                    this.selectedValues = originalSelect.val() || [];
                } else {
                    this.selectedValues = originalSelect.val() || null;
                }
                this.updateValueDisplay();
                this.updateOptionsList();
            }
        },

        /**
         * 重新创建隐藏input
         * @param {Object} options - 新的配置选项
         */
        recreateHiddenInput: function(options) {
            if (options) {
                $.extend(true, this.settings, options);
            }
            this.createHiddenInput();
        },

        /**
         * 启用组件
         */
        enable: function() {
            this.settings.disabled = false;
            this.$trigger.removeClass('disabled');
            this.updateOptionsList();
        },

        /**
         * 禁用组件
         */
        disable: function() {
            this.settings.disabled = true;
            this.$trigger.addClass('disabled');
            this.closeDropdown();
            this.updateOptionsList();
        },

        /**
         * 更新选项数据
         * @param {Array} newOptions - 新的选项数据
         */
        updateOptions: function(newOptions) {
            if (newOptions) {
                this.localOptions = this.transformRemoteData(newOptions || []);
                if (this.settings.treeMode) {
                    this.settings.options = this.localOptions;
                    this.treeData = this.processTreeData();
                }
            }
            this.updateOptionsList();
            if(this.$pagination){
                this.renderPagination();
            }
        },
        
        /**
         * 设置显示模式
         * @param {string} mode - 显示模式，'default' 或 'group'
         */
        setDisplayMode: function(mode) {
            this.settings.displayMode = mode;
            this.$element
                .removeClass('ws-display-mode-default ws-display-mode-group')
                .addClass('ws-display-mode-' + mode);
            this.updateOptionsList();
        },
        
        /**
         * 设置选择模式
         * @param {boolean} isMultiple - 是否多选
         */
        setMultiple: function(isMultiple) {
            this.settings.multiple = isMultiple;
            
            // 如果从多选切换到单选且当前有多个选中值，只保留第一个
            if (!isMultiple && Array.isArray(this.selectedValues) && this.selectedValues.length > 1) {
                this.selectedValues = this.selectedValues[0];
            }else if(isMultiple && !Array.isArray(this.selectedValues)){
                this.selectedValues = this.selectedValues ? [this.selectedValues] : [];
            }
            
            this.updateValueDisplay();
            this.updateOptionsList();
        },
        
        /**
         * 重新加载远程数据
         * @param {string} searchValue - 搜索关键词
         */
        reloadRemoteData: function(searchValue) {
            if (this.settings.remoteUrl || typeof this.settings.remoteMethod === 'function') {
                this.loadRemoteData(searchValue || this.searchValue);
            }
        },

        /**
         * 设置树形模式
         * @param {boolean} enable - 是否启用树形模式
         */
        setTreeMode: function(enable) {
            this.settings.treeMode = enable;
            if (enable) {
                this.$element.addClass('ws-select-tree-mode');
                this.processTreeData();
                if (this.settings.treeExpandAll) {
                    this.expandAllNodes();
                }
            } else {
                this.$element.removeClass('ws-select-tree-mode');
            }
            this.updateOptionsList();
        },
        
        /**
         * 设置最后一级布局方式
         * @param {string} layout - 'vertical' 或 'horizontal'
         */
        setLastLevelLayout: function(layout) {
            this.settings.lastLevelLayout = layout;
            this.$element
                .removeClass('last-level-horizontal')
                .addClass(layout === 'horizontal' ? 'last-level-horizontal' : '');
            this.updateOptionsList();
        },
        
        /**
         * 展开所有树节点
         */
        expandAll: function() {
            if (this.settings.treeMode) {
                this.expandedKeys.clear();
                this.expandAllNodes();
                this.updateOptionsList();
            }
        },
        
        /**
         * 折叠所有树节点
         */
        collapseAll: function() {
            if (this.settings.treeMode) {
                this.expandedKeys.clear();
                this.updateOptionsList();
            }
        },

        /**
         * 销毁组件实例，清理事件绑定和DOM元素
         */
        destroy: function() {
            // 清理事件绑定
            $(document).off('.wsSelect');
            this.$element.off();
            this.$element.empty();
            this.$element.removeData('wsSelect');
            return null;
        }
    };

    /**
     * 从元素 data 属性解析配置
     * @param {HTMLElement} element - DOM元素
     * @returns {Object} 配置对象
     */
    function parseConfigFromElement(element) {
        var $el = $(element);
        var config = {};
        
        // 解析基本属性
        var dataAttrs = [
            'options', 'value', 'defaultValue', 'multiple', 'disabled',
            'searchable', 'clearable', 'placeholder', 'size', 'loading',
            'allowClear', 'showArrow', 'dropdownMatchSelectWidth', 'maxOptionHeight',
            'remoteUrl', 'url', 'fieldMapping', 'displayMode', 'remoteDelay',
            'minSearchLength', 'ajaxOptions', 'filterOption',
            'pagination', 'pageSize', 'pageSizeOptions', 'paginationFieldMapping',
            'showTotal', 'showSizeChanger', 'treeMode', 'treeFieldMapping',
            'treeExpandAll', 'lastLevelLayout', 'treeCheckable', 'treeCheckStrictly',
            'name', 'inputName', 'id', 'inputId', 'required', 'valueSeparator',
            'useJsonValue', 'autoCreateInput'
        ];
        
        dataAttrs.forEach(function(attr) {
            var value = $el.data(attr);
            if (value !== undefined) {
                config[attr] = value;
            }
        });
        
        // 特殊处理 JSON 字符串
        ['options', 'fieldMapping', 'ajaxOptions', 'paginationFieldMapping', 
         'pageSizeOptions', 'treeFieldMapping'].forEach(function(key) {
            var value = $el.data(key);
            if (typeof value === 'string') {
                try {
                    config[key] = JSON.parse(value);
                } catch (e) {
                    console.warn('Failed to parse data-' + key + ' as JSON:', e);
                }
            }
        });
        
        // 处理布尔值属性
        ['multiple', 'disabled', 'searchable', 'clearable', 'loading', 
         'allowClear', 'showArrow', 'dropdownMatchSelectWidth', 'pagination',
         'showTotal', 'showSizeChanger', 'treeMode', 'treeExpandAll',
         'treeCheckable', 'treeCheckStrictly', 'required', 'useJsonValue', 
         'autoCreateInput'].forEach(function(key) {
            var value = $el.data(key);
            if (typeof value === 'string') {
                config[key] = value === 'true' || value === '1';
            }
        });
        
        // 处理数字属性
        ['remoteDelay', 'minSearchLength', 'pageSize'].forEach(function(key) {
            var value = $el.data(key);
            if (typeof value === 'string') {
                var numValue = parseFloat(value);
                if (!isNaN(numValue)) {
                    config[key] = numValue;
                }
            }
        });
        
        // 处理事件回调
        ['onChange', 'onSearch', 'onFocus', 'onBlur', 'onSelectOptionRender', 
         'onSelectValueRender', 'remoteMethod', 'filterOption'].forEach(function(key) {
            var value = $el.data(key);
            if (typeof value === 'string' && window[value]) {
                config[key] = window[value];
            }
        });
        
        // 处理别称
        config['remoteUrl'] = config['remoteUrl'] || config['url'];
        return config;
    }

    /**
     * 自动初始化所有包含 data-role="wsselect" 属性的元素
     * @param {string} selector - 选择器，默认为 '[data-role="wsselect"]'
     */
    WsSelect.autoInitialize = function(selector) {
        selector = selector || '[data-role="wsselect"]';
        $(selector).each(function() {
            if (!$(this).data('wsSelect')) {
                var config = parseConfigFromElement(this);
                $(this).wsSelect(config);
            }
        });
    };
    
    // jQuery 插件封装
    $.fn.wsSelect = function(options) {
        // 如果传递的是方法名
        if (typeof options === 'string') {
            var args = Array.prototype.slice.call(arguments, 1);
            var methodName = options;
            
            // 对于有返回值的方法，只处理第一个元素并返回结果
            // 对于无返回值的方法，处理所有元素并返回 jQuery 对象
            var hasReturnValue = false;
            var returnValue;
            
            // 处理第一个元素来检测方法是否有返回值
            var firstInstance = this.first().data('wsSelect');
            if (firstInstance && typeof firstInstance[methodName] === 'function') {
                // 调用方法并检查返回值
                returnValue = firstInstance[methodName].apply(firstInstance, args);
                hasReturnValue = (returnValue !== undefined && returnValue !== firstInstance);
            }

            if (hasReturnValue) {
                // 方法有返回值，只处理第一个元素并返回结果
                return returnValue;
            } else {
                // 方法没有返回值，处理所有元素并返回 jQuery 对象保持链式调用
                this.each(function(index, element) {
                    if(index > 0){
                        var instance = $(element).data('wsSelect');
                        if (instance && typeof instance[methodName] === 'function') {
                            instance[methodName].apply(instance, args);
                        }
                    }
                });

                return this;
            }
        }
        
        // 否则是初始化
        return this.each(function() {
            if (!$(this).data('wsSelect')) {
                $(this).data('wsSelect', new WsSelect(this, options));
            }
        });
    };

    // 自动初始化方法
    $.fn.autoInitializeWsSelect = function() {
        return this.each(function() {
            if ($(this).data('role') === 'wsselect' && !$(this).data('wsSelect')) {
                var config = parseConfigFromElement(this);
                $(this).wsSelect(config);
            }
        });
    };

    // 文档加载完成后自动初始化
    $(function() {
        WsSelect.autoInitialize();

        // 自动转换select元素
        $('select[data-convert-to-wsselect]').each(function() {
            var $select = $(this);
            var $container = $('<div></div>');
            
            // 复制所有属性
            var attrs = $select[0].attributes;
            for (var i = 0; i < attrs.length; i++) {
                var attr = attrs[i];
                if (!['data-convert-to-wsselect', 'class'].includes(attr.name)) {
                    $container.attr(attr.name, attr.value);
                }
            }
            $container.data('role', 'wsselect');

            // 从select的option构建options数组
            var options = [];
            var groups = [];
            
            // 处理optgroup和option
            $select.children().each(function() {
                if (this.tagName === 'OPTGROUP') {
                    var $group = $(this);
                    var groupLabel = $group.attr('label');
                    groups.push(groupLabel);
                    
                    $group.children('option').each(function() {
                        var $option = $(this);
                        options.push({
                            value: $option.val(),
                            label: $option.text(),
                            group: groupLabel,
                            disabled: $option.prop('disabled')
                        });
                    });
                } else if (this.tagName === 'OPTION') {
                    var $option = $(this);
                    options.push({
                        value: $option.val(),
                        label: $option.text(),
                        disabled: $option.prop('disabled')
                    });
                }
            });
            
            // 将options设置为data-options属性（JSON字符串）
            $container.attr('data-options', JSON.stringify(options));
            
            // 处理multiple属性
            if ($select.prop('multiple')) {
                $container.attr('data-multiple', 'true');
            }
            
            // 处理disabled属性
            if ($select.prop('disabled')) {
                $container.attr('data-disabled', 'true');
            }
            
            // 处理默认选中的值（如果有selected的option）
            var selectedValues = $select.val();
            if (selectedValues !== null && selectedValues !== '') {
                // 如果是多选，selectedValues是一个数组；单选是字符串
                if ($select.prop('multiple') && Array.isArray(selectedValues)) {
                    // 多选：设置为JSON数组字符串或逗号分隔，取决于useJsonValue配置
                    // 我们默认使用JSON数组格式，因为更安全
                    $container.attr('data-value', JSON.stringify(selectedValues));
                    // 同时设置useJsonValue为true以确保正确解析
                    $container.attr('data-use-json-value', 'true');
                } else {
                    // 单选：直接设置value
                    $container.attr('data-value', selectedValues);
                }
            }
            
            // 处理placeholder属性（如果select有placeholder属性或第一个option为空值）
            if (!$select.attr('placeholder')) {
                var firstOption = $select.find('option').first();
                if (firstOption.length && firstOption.val() === '') {
                    $container.attr('data-placeholder', firstOption.text());
                }
            }
            // 包裹select
            $select.wrap($container);
            var $wrapper = $select.parent();

            // 自动初始化
            $wrapper.autoInitializeWsSelect();
        });
    });

    // 导出组件类（用于直接实例化）
// 保留全局挂载：wise-ui.js 与插件业务代码通过 window.WsSelect 访问，存量调用零改造。
window.WsSelect = WsSelect;

export default WsSelect;