/**
 * Enhanced Bootstrap Table Component
 * 基于Bootstrap Table的增强表格组件
 * 版本: 2.0.0 （去 AMD，IIFE 版本）
 */
(function() {
    'use strict';
    const $ = window.jQuery;
    // ws-layer 现已随 wise-bundle（type="module"）加载，执行时机晚于经典 defer 脚本，
    // 顶层直接取值只会拿到 undefined。改为惰性代理：真正的转发发生在用户交互回调里，
    // 那时 bundle 早已执行完毕，window.Layer 必然就绪。
    const Layer = new Proxy({}, {
        get: (_, prop) => (window.Layer || {})[prop],
        set: (_, prop, value) => { (window.Layer || (window.Layer = {}))[prop] = value; return true; },
        has: (_, prop) => !!window.Layer && prop in window.Layer,
    });

    // ============================================================
    // 内置 moment 轻量实现（仅 enhanced-table 所需的 subset）
    // 替代 100KB+ moment.js
    // ============================================================
    const moment = (function () {
        const TOKENS = [
            ['YYYY', function (d) { return String(d.getFullYear()); }],
            ['MM',   function (d) { return String(d.getMonth() + 1).padStart(2, '0'); }],
            ['DD',   function (d) { return String(d.getDate()).padStart(2, '0'); }],
            ['HH',   function (d) { return String(d.getHours()).padStart(2, '0'); }],
            ['mm',   function (d) { return String(d.getMinutes()).padStart(2, '0'); }],
            ['ss',   function (d) { return String(d.getSeconds()).padStart(2, '0'); }],
        ];

        // P1-28：Safari/WebKit 不解析「YYYY-MM-DD HH:mm:ss」（空格分隔日期时间），
        // 得到 Invalid Date → 所有 datetime/date/countdown 列在 Safari 显示为空。
        // 这里把「日期 + 空格 + 时间」归一为 ISO 的「日期T时间」，Chrome/Safari 行为一致。
        function normalizeDate(value) {
            if (typeof value === 'string') {
                const m = value.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)/);
                if (m) return m[1] + 'T' + m[2];
            }
            return value;
        }

        class Moment {
            constructor(date) {
                this._d = date instanceof Date ? date : (date != null ? new Date(normalizeDate(date)) : new Date());
                this._valid = !isNaN(this._d.getTime());
            }
            format(fmt) {
                if (!this._valid) return '';
                let result = fmt || 'YYYY-MM-DD HH:mm:ss';
                for (const [token, fn] of TOKENS) {
                    result = result.replace(token, fn(this._d));
                }
                return result;
            }
            diff(other) {
                const a = this._d.getTime();
                const b = (other && other._d) ? other._d.getTime()
                    : (other instanceof Date) ? other.getTime()
                    : (other != null) ? new Date(normalizeDate(other)).getTime()
                    : new Date().getTime();
                return a - b;
            }
            isBefore(other) {
                return this.diff(other) < 0;
            }
            isAfter(other) {
                return this.diff(other) > 0;
            }
        }

        function duration(ms) {
            const _ms = Math.abs(ms);
            return {
                asDays() { return _ms / 86400000; },
                // P1-24：timeAgo formatter 依赖 years()/months()/days()，原先缺失导致
                // 任何非空时间值都抛 `TypeError: duration.years is not a function`。
                // 这里用近似换算补全（与 moment.js duration 的整年/整月/整天语义一致）：
                // 年=365 天、月=30 天、天=24h，仅用于「相对时间」展示，精度足够。
                days() { return Math.floor(_ms / 86400000); },
                months() { return Math.floor(_ms / (86400000 * 30)); },
                years() { return Math.floor(_ms / (86400000 * 365)); },
                hours() { return Math.floor((_ms % 86400000) / 3600000); },
                minutes() { return Math.floor((_ms % 3600000) / 60000); },
                seconds() { return Math.floor((_ms % 60000) / 1000); },
            };
        }

        function m(date) {
            return new Moment(date);
        }
        m.duration = duration;
        return m;
    })();
    // 事件总线 - 用于组件内外部通信
    class EventBus {
        constructor() {
            this.events = Object.create(null);
        }

        on(event, listener) {
            if (!this.events[event]) this.events[event] = [];
            this.events[event].push(listener);
        }

        off(event, listener) {
            if (!this.events[event]) return;
            this.events[event] = this.events[event].filter(l => l !== listener);
        }

        emit(event, ...args) {
            if (!this.events[event]) return;
            this.events[event].forEach(listener => listener.apply(null, args));
        }
    }

    // 表格组件
    class EnhancedTable {
        constructor(options = {}) {
            if (!options.hasOwnProperty('pageSize')) {
                options.pageSize = EnhancedTable.getPageSize();
            }
            
            if (!options.hasOwnProperty('locale')) {
                options.locale = EnhancedTable.getLocale();
            }
            // 初始化事件总线
            this.eventBus = new EventBus();
            // 原始数据
            this.originalData = {};
            // 合并默认配置
            this.options = this.mergeDefaults(options);
            this.table = null;
            this.tableInstance = null;
            this.selectedIds = [];
            this.selectedData = [];
            this.init();
        }

        // 默认配置
        static get DEFAULTS () {
            return {
                url: '',
                sidePagination: 'server',   // 分页方式：server 服务端分页，client 前端分页
                method: 'get',              // 请求方法：get,post
                toolbar: '.toolbar',        // 工具栏选择器
                toolbarBtn: [],             // 工具栏按钮 add,del,edit,refresh,import,export,preview,multi
                checkboxHeader: true,       // 是否显示表头复选框
                search: true,               // 是否显示搜索框
                cache: false,               // 是否启用缓存
                commonSearch: true,         // 是否启用通用搜索框
                searchFormVisible: true,    // 是否显示搜索表单
                titleForm: '',              // 搜索表单标题
                idTable: 'enhancedTable',   // 表格ID
                showTipsBtn: false,         // 是否显示提示按钮
                showExport: true,           // 是否显示导出按钮
                exportDataType: 'auto',     // 导出数据类型：auto,basic,all
                exportTypes: ['json', 'xml', 'csv', 'txt', 'doc', 'excel'], // 导出文件类型
                exportOptions: {
                    fileName: `export_${moment().format('YYYY-MM-DD')}`,
                    preventInjection: false,
                    mso: {
                        onMsoNumberFormat: (cell, row, col) => {
                            return !isNaN($(cell).text()) ? '\\@' : '';
                        }
                    },
                    ignoreColumn: [0, 'operate']
                },
                pageList: [10, 15, 20, 25, 50, 'All'],
                dataDataField: 'data.list',     // 数据字段
                dataTotalField: 'data.total',   // 总记录数字段
                dataPageNumber: 'data.page',    // 当前页码字段
                pageSize: 10,              // 每页显示记录数字段
                pagination: true,           // 是否显示分页
                paginationLoop: false,       // 是否循环分页
                clickToSelect: true,        // 是否点击行选中复选框
                dblClickToEdit: true,       // 是否双击行编辑
                singleSelect: false,        // 是否单选
                showRefresh: true,          // 是否显示刷新按钮
                showJumpto: true,           // 是否显示跳转页码输入框
                showToggle: false,           // 是否显示切换视图按钮
                showColumns: true,          // 是否显示列选择器
                pk: 'id',                   // 主键字段
                sortName: 'id',             // 排序字段
                sortOrder: 'desc',          // 排序方向：asc,desc
                paginationFirstText: '首页',
                paginationPreText: '上一页',
                paginationNextText: '下一页',
                paginationLastText: '末页',

                // 树形网格配置
                treeShowField: '',          // 显示树状结构的字段，设置后将自动启用树形网格
                idField: 'id',              // 树状结构的 ID 字段
                parentIdField: 'parent_id', // 树状结构的父节点字段
                treeColumn: 0,              // 树形网格的列索引，从 0 开始计数
                initialState: 'collapsed',  // 初始状态：collapsed 折叠，expanded 展开
                
                // 高级搜索配置
                advancedSearch: false,      // 是否启用高级搜索
                actionForm: '',             // 是否启用操作表单
                idForm:'advancedSearch',    // 高级搜索确定表单ID
                idTable:'enhancedTable',    // 表格ID

                cardView: false,            // 是否启用卡片视图
                iosCardView: true,          // 是否启用iOS风格卡片视图
                checkOnInit: true,          // 是否初始化时选中第一行
                escape: true,               // 是否转义HTML字符
                fixDropdownPosition: true,  // 是否修复下拉菜单位置
                dragCheckboxMultiselect: true,   // 是否启用拖动选择复选框
                maintainSelected: false,            // 是否维护选中项，多页选择，默认不维护
                responseHandler: null,      // 自定义响应处理函数
                batchOpenMax: 10,           // 批量操作最大数量
                extend: {
                    preview_url: '',        // 预览URL
                    add_url: '',            // 新增URL 
                    edit_url: '',           // 编辑URL
                    del_url: '',            // 删除URL
                    restore_url: '',        // 恢复URL（Phase 3 撤销支持）
                    import_url: '',         // 导入URL
                    multi_url: '',          // 批量操作URL
                    dragsort_url: 'idler/sort',   // 拖动排序URL
                    preview_title: '预览',  // 预览窗口标题
                    add_title: '新增',      // 新增窗口标题
                    edit_title: '编辑',     // 编辑窗口标题
                    del_title: '删除',      // 删除窗口标题
                }
            }
        }
        
        // 列默认配置
        static get COLUMN_DEFAULTS () {
            return {
                align: 'center',
                valign: 'middle'
            };
        }

        // 操作按钮默认配置
        static get BUTTONS () {
            return {
                preview: {
                    name: 'preview',
                    icon: 'bi bi-book',
                    title: '查看',
                    extend: 'data-bs-toggle="tooltip" data-container="body"',
                    classname: 'btn btn-sm btn-info btn-previewone'
                },
                add: {
                    name: 'add',
                    icon: 'bi bi-plus-lg',
                    title: '新增',
                    extend: 'data-bs-toggle="tooltip" data-container="body"',
                    classname: 'btn btn-sm btn-success btn-addone'
                },
                    edit: {
                    name: 'edit',
                    icon: 'bi bi-pencil-square',
                    title: '编辑',
                    extend: 'data-bs-toggle="tooltip" data-container="body"',
                    classname: 'btn btn-sm btn-primary btn-editone'
                },
                del: {
                    name: 'del',
                    icon: 'bi bi-trash',
                    title: '删除',
                    extend: 'data-bs-toggle="tooltip" data-container="body"',
                    classname: 'btn btn-sm btn-danger btn-delone'
                },
                dragsort: {
                    name: 'dragsort',
                    icon: 'bi bi-shuffle',
                    title: '拖动排序',
                    extend: 'data-bs-toggle="tooltip" data-container="body"',
                    classname: 'btn btn-sm btn-primary btn-dragsort'
                }
            }
        }

        // 初始化
        init() {
            this.applyGlobalConfig();
            this.setupFormatterProxies();
            this.createTable();
            this.createToolbar();
            this.bindEvents();
            this.bindExpandEvents();
            this.bindOperationEvents();
            this.bindCellCommonEvents();
            this.initPlugins();
        }

        // 获取表格实例
        getTable() {
            return this.table;
        }

        // 创建格式化器代理
        createFormatterProxy(column, formatterName) {
            const self = this;
            const staticFormatter = EnhancedTable.FORMATTERS[formatterName];
            
            if (!staticFormatter) {
                console.warn(`Formatter '${formatterName}' not found`);
                return column.formatter;
            }
            
            // 创建代理函数
            const proxyFormatter = function(value, row, index){
                // 调用原始静态格式化器，但注入实例数据
                return staticFormatter.call(this, self, value, row, index);
            };
            
            return proxyFormatter;
        }

        // 设置格式化器代理
        setupFormatterProxies() {
            if (!this.options.columns) return;
            this.options.columns.forEach(column => {
                if (column.formatter && typeof column.formatter === 'string') {
                    const formatterName = column.formatter;
                    
                    // 创建代理替换原始格式化器
                    column.formatter = this.createFormatterProxy(column, formatterName);
                }
                // 给treegrid 配置clickToSelect 为 false 和 点击事件
                if (this.options.treeShowField && column.field === this.options.treeShowField) {
                    column.clickToSelect = false;
                    column.events = {
                        'click span': function (e, value, row, index) {
                            if($(e.target).hasClass('treegrid-expander')) return;
                            if($(e.target).prev('.treegrid-expander').length === 0) return;
                            $(e.target).prev('.treegrid-expander').click();
                        }
                    };
                }
            });
        }

        // 合并默认配置
        mergeDefaults(options) {
            const defaults = EnhancedTable.DEFAULTS;
            const columnDefaults = EnhancedTable.COLUMN_DEFAULTS;
            
            $.extend(true, defaults, options);
            $.extend($.fn.bootstrapTable.columnDefaults, columnDefaults);

            return defaults;
        }

        // 创建表格
        createTable() {
            const $table = $(`#${this.options.idTable}`);
            
            if ($table.length === 0) {
                console.error(`Table element with id '${this.options.idTable}' not found`);
                return;
            }

            // 设置默认的 responseHandler
            if (!this.options.responseHandler && this.options.dataDataField) {
                this.options.responseHandler = this.createDefaultResponseHandler();
            }
            
            // 自定义分页显示信息
            // this.options.formatShowingRows = function (pageFrom, pageTo, totalRows) {
            //     return '共 ' + totalRows + ' 条记录';
            // }

            // this.options.formatRecordsPerPage = function (pageNumber) {
            //     return pageNumber;
            // }
            // console.log('表格配置:', this.options);
            this.table = $table.bootstrapTable(this.options);
            this.tableInstance = $table.data('bootstrapTable');

            // console.log('表格实例创建成功:',this.table, this.tableInstance);
        }

        // 创建工具栏按钮
        createToolbar() {
            if (!this.options.toolbarBtn || this.options.toolbarBtn.length === 0) return;
            const toolbar = $(this.options.toolbar);
            var btns = {
                add:{id:'add', name:'add', title:'添加', icon:'bi bi-plus', disabled:false, class:'btn btn-sm btn-success mx-1'},
                del:{id:'del', name:'del', title:'删除', icon:'bi bi-trash', disabled:true, class:'btn btn-sm btn-danger btn-disabled mx-1'},
                edit:{id:'edit', name:'edit', title:'编辑', icon:'bi bi-pencil', disabled:true, class:'btn btn-sm btn-primary btn-disabled mx-1'},
                refresh:{id:'refresh', name:'refresh', title:'刷新', icon:'bi bi-arrow-repeat', disabled:false, class:'btn btn-sm btn-info mx-1'},
                import:{id:'import', name:'import', title:'导入', icon:'bi bi-upload', disabled:false, class:'btn btn-sm btn-success mx-1'},
                export:{id:'export', name:'export', title:'导出', icon:'bi bi-download', disabled:true, class:'btn btn-sm btn-primary btn-disabled mx-1'},
                preview:{id:'preview',name:'preview', title:'预览', icon:'bi bi-eye', disabled:true, class:'btn btn-sm btn-warning btn-disabled mx-1'},
                multi:{id:'multi', name:'multi', title:'批量操作', icon:'bi bi-gear', disabled:true, class:'btn btn-sm btn-secondary btn-disabled mx-1'}
            };
            const container = toolbar.find('[data-ws-target="toolbar"]').length > 0 ? toolbar.find('[data-ws-target="toolbar"]').first() : toolbar;
            this.options.toolbarBtn.forEach(btn => {
                if(typeof btn === 'object' && btn.id == 'dropdown'){
                    var html = `<div class="dropdown d-inline">
                        <button id="${btn.id}" class="btn-${btn.name} ${btn.class} ${btn.disabled ? 'disabled' : ''} dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            ${btn.icon ? `<i class="${btn.icon}"></i> ` : ''}${btn.title}</button>
                        <ul class="dropdown-menu">
                            ${btn.items.map(item => `<li><a class="dropdown-item" href="javascript:;" 
                                ${item.value ? `data-value="${item.value}"` : ''} 
                                ${item.event ? `data-event="${item.event}"` : ''}>
                                ${item.icon ? `<i class="${item.icon}"></i> ` : ''}${item.title}</a></li>`).join('')}
                        </ul>
                    </div>`;
                    container.append(html);
                }else{
                    var _btn = btns[btn] || btn;
                    const _btnEl = $(`<button id="${_btn.id}" class="btn-${_btn.name} ${_btn.class} ${_btn.disabled ? 'disabled' : ''}" 
                        ${_btn.data ? _btn.data.map(item => `data-${item.key}="${item.value}"`).join(' ') : ''}">
                        ${_btn.event ? `data-event="${_btn.event}"` : ''}
                        <i class="${_btn.icon}"></i> ${_btn.title}</button>`);
                    container.append(_btnEl);
                    // 自定义按钮（对象类型）绑定其 click 回调
                    if (typeof btn === 'object' && typeof btn.click === 'function') {
                        _btnEl.on('click', function (e) {
                            e.preventDefault();
                            btn.click.call(this, e);
                        });
                    }
                }
            });
        }

        // 创建默认的响应处理器
        createDefaultResponseHandler() {
            return (res) => {
                // console.log('原始响应数据:', res);
                this.originalData = res;
                this.eventBus.emit('load-success', res);

                // 如果没有指定 dataDataField
                if (!this.options.dataDataField) {
                    // console.log('未指定 dataDataField');
                    return res;
                }

                let data = {};

                // 根据 dataDataField 路径提取数据
                if(this.options.dataDataField){
                    const fieldPath = this.options.dataDataField.split('.');
                    let current = res;
                    for (const field of fieldPath) {
                        if (current && current[field] !== undefined) {
                            current = current[field];
                        } else {
                            console.warn(`在路径 ${this.options.dataDataField} 中找不到字段 ${field}`);
                            current = [];
                        }
                    }
                    data.rows = Array.isArray(current) ? current : [];
                }

                // 根据 dataTotalField 路径提取数据
                if(this.options.dataTotalField) {
                    const fieldPath = this.options.dataTotalField.split('.');
                    data.total = res;
                    for (const field of fieldPath) {
                        if (data.total && data.total[field] !== undefined) {
                            data.total = data.total[field];
                        } else {
                            console.warn(`在路径 ${this.options.dataTotalField} 中找不到字段 ${field}`);
                            data.total = null;
                        }
                    }
                }

                // 根据 dataPageNumber 路径提取数据
                if(this.options.dataPageNumber){
                    const fieldPath = this.options.dataPageNumber.split('.');
                    data.page = res;
                    for (const field of fieldPath) {
                        if (data.page && data.page[field] !== undefined) {
                            data.page = data.page[field];
                        } else {
                            // console.warn(`在路径 ${this.options.dataPageNumber} 中找不到字段 ${field}`);
                            data.page = 1;
                        }
                    }
                }

                return data;
            };
        }

        // 绑定选择事件
        bindSelectionEvents() {
            if (!this.table) return;

            const self = this;
            const toolbar = $(this.options.toolbar);
            
            // 跨页选择提示按钮
            let tipsBtn = $(".btn-selected-tips", toolbar);
            if (tipsBtn.length === 0) {
                tipsBtn = $(`<a href="javascript:" class="btn btn-warning-light btn-selected-tips invisible" 
                    data-bs-toggle="tooltip" title="单击以取消选中所有">
                    <i class="fa fa-info-circle"></i>多选模式：已选中<b>0</b>条记录)}
                </a>`).appendTo(toolbar);
            }

            // 点击提示按钮取消所有选择
            tipsBtn.off("click").on("click", function() {
                self.clearSelection();
                self.refresh();
            });

            // 选择变化事件
            this.table.on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table', function() {
                self.handleSelectionChange();
            });

            // 表格刷新时更新选择状态
            this.table.on('refresh.bs.table', function() {
                self.handleRefreshSelection();
            });
        }

        // 处理选择变化
        handleSelectionChange() {
            const selectedIds = this.getSelectedIds(true);
            const selectedData = this.getSelectedData(true);
            
            if (this.options.maintainSelected) {
                this.updateMaintainedSelection(selectedIds, selectedData);
            } else {
                this.selectedIds = selectedIds;
                this.selectedData = selectedData;
            }

            // 更新UI
            if(this.options.showTipsBtn){
                const toolbar = $(this.options.toolbar);
                let tipsBtn = $(".btn-selected-tips", toolbar);
                if (this.selectedIds.length >= 0) {
                    $('b', tipsBtn).text(this.selectedIds.length);
                    tipsBtn.removeClass('invisible');
                } else {
                    tipsBtn.addClass('invisible');
                }
            }

            // 更新导出类型
            this.updateExportType();
            
            // 更新禁用按钮状态
            this.updateDisabledButtons();
        }

        // 更新禁用的按钮状态
        updateDisabledButtons() {
            const toolbar = $(this.options.toolbar);
            const hasSelection = this.selectedIds.length > 0;
            $('.btn-disabled', toolbar).toggleClass('disabled', !hasSelection);
        }

        // 处理刷新时的选择状态
        handleRefreshSelection() {
            if (!this.options.maintainSelected) return;
            
            // 重新应用跨页选择
            const options = this.table.bootstrapTable('getOptions');
            const data = this.table.bootstrapTable('getData');
            
            // 更新选择状态
            this.table.bootstrapTable('checkBy', {
                field: options.pk,
                values: this.selectedIds
            });
        }

        // 绑定拖拽事件
        bindDragEvents() {
            if (!this.table) return;
            
            // 初始化拖拽选择
            this.initDragSelection();
            
            // 初始化拖拽排序
            this.initDragSort();
            
            // 绑定拖拽相关按钮事件
            this.bindDragButtonEvents();
        }

        // 初始化拖拽选择
        initDragSelection() {
            if (!this.table || !this.options.dragCheckboxMultiselect) return;
            
            const self = this;
            const checkboxTd = 'tbody>tr>td.bs-checkbox';
            let posx, posy, dragdiv, drag = false, prepare = false;
            
            const mousemove = function(e) {
                if (drag) {
                    const left = Math.min(e.pageX, posx);
                    const top = Math.min(e.pageY, posy);
                    const width = Math.abs(posx - e.pageX);
                    const height = Math.abs(posy - e.pageY);
                    
                    dragdiv.css({
                        left: left + "px", 
                        top: top + "px", 
                        width: width + "px", 
                        height: height + "px"
                    });
                    
                    const dragrect = {x: left, y: top, width: width, height: height};
                    
                    $(checkboxTd, self.table).each(function() {
                        const checkbox = $("input:checkbox", this);
                        const tdrect = this.getBoundingClientRect();
                        tdrect.x += document.documentElement.scrollLeft;
                        tdrect.y += document.documentElement.scrollTop;

                        const td_min_x = tdrect.x;
                        const td_min_y = tdrect.y;
                        const td_max_x = tdrect.x + tdrect.width;
                        const td_max_y = tdrect.y + tdrect.height;

                        const drag_min_x = dragrect.x;
                        const drag_min_y = dragrect.y;
                        const drag_max_x = dragrect.x + dragrect.width;
                        const drag_max_y = dragrect.y + dragrect.height;
                        
                        const overlapped = td_min_x <= drag_max_x && td_max_x >= drag_min_x && 
                                        td_min_y <= drag_max_y && td_max_y >= drag_min_y;
                        
                        if (overlapped) {
                            if (!$(this).hasClass("overlaped")) {
                                $(this).addClass("overlaped");
                                checkbox.trigger("click");
                            }
                        } else {
                            if ($(this).hasClass("overlaped")) {
                                $(this).removeClass("overlaped");
                                checkbox.trigger("click");
                            }
                        }
                    });
                }
            };
            
            const selectstart = function() {
                return false;
            };
            
            const mouseup = function() {
                if (drag) {
                    $(document).off("mousemove", mousemove);
                    $(document).off("selectstart", selectstart);
                    dragdiv.remove();
                }
                drag = false;
                prepare = false;
                $(document.body).css({'MozUserSelect': '', 'webkitUserSelect': ''}).attr('unselectable', 'off');
            };

            $(checkboxTd, this.table).on("mousedown", function(e) {
                // 禁止鼠标右键事件和文本框
                if (e.button === 2 || $(e.target).is("input")) {
                    return false;
                }
                posx = e.pageX;
                posy = e.pageY;
                prepare = true;
            }).on("mousemove", function(e) {
                if (prepare && !drag) {
                    drag = true;
                    dragdiv = $("<div />");
                    dragdiv.css({
                        position: 'absolute', 
                        width: 0, 
                        height: 0, 
                        border: "1px dashed blue", 
                        background: "#0029ff", 
                        left: e.pageX + "px", 
                        top: e.pageY + "px", 
                        opacity: .1
                    });
                    dragdiv.appendTo(document.body);
                    $(document.body).css({'MozUserSelect': 'none', 'webkitUserSelect': 'none'}).attr('unselectable', 'on');
                    $(document).on("mousemove", mousemove).on("mouseup", mouseup).on("selectstart", selectstart);
                    
                    if (self.options.dragCheckboxMultiselect) {
                        $(checkboxTd, self.table).removeClass("overlaped");
                    }
                }
            });
        }

        // 初始化拖拽排序
        initDragSort() {
            if (!this.table) return;

            const self = this;
            const $tbody = $("tbody", this.table);
            const options = this.table.bootstrapTable('getOptions');

            // 仅当 dragsort_url 配置时才启用
            if (!options.extend.dragsort_url) return;

            // 优先使用 jQuery UI sortable（提供实时拖拽预览）
            if (typeof $.fn.sortable !== 'undefined') {
                $tbody.sortable({
                    handle: 'a.btn-dragsort',
                    cursor: 'grabbing',
                    delay: 100,
                    axis: 'y',
                    placeholder: 'sortable-placeholder',
                    forcePlaceholderSize: true,
                    helper: function(e, tr) {
                        const $helper = tr.clone();
                        $helper.addClass('sortable-drag');
                        $helper.children().each(function() {
                            $(this).width($(tr).children().eq($(this).index()).width());
                        });
                        return $helper;
                    },
                    start: function(e, ui) {
                        ui.placeholder.html('<td colspan="' + ui.item.children('td').length + '">&nbsp;</td>');
                        ui.placeholder.addClass('sortable-placeholder-active');
                    },
                    update: function(e, ui) {
                        // 获取新的位置索引
                        const newIndex = ui.item.index();
                        const pk = options.pk || options.idField || 'id';
                        self.handleDragSort(ui.item, newIndex);
                    }
                });
            } else if (typeof $.fn.dragsort !== 'undefined') {
                // 回退到旧 dragsort 插件
                $tbody.dragsort({
                    itemSelector: 'tr:visible',
                    dragSelector: "a.btn-dragsort",
                    dragEnd: function() {
                        self.handleDragSort(this);
                    },
                    placeHolderTemplate: ""
                });
            }
        }

        // 处理拖拽排序
        handleDragSort(elemOrTr, newIndex) {
            const data = this.table.bootstrapTable('getData');
            const options = this.table.bootstrapTable('getOptions');
            const pk = options.pk || options.idField || 'id';

            // jQuery UI sortable 传入的是 tr 元素 + newIndex
            let $tr;
            if (newIndex !== undefined) {
                $tr = $(elemOrTr);
                newIndex = newIndex;
            } else {
                // 兼容旧 dragsort 插件
                $tr = $(elemOrTr);
                newIndex = $tr.index();
            }

            // 获取当前可见行的顺序（树形表只取可见根节点行）
            const allTrs = $("tbody tr:visible", this.table);
            const visibleIds = [];
            const rowIndexMap = {};

            $.each(allTrs, function(i, tr) {
                const dataIdx = parseInt($(tr).data('index'));
                if (!isNaN(dataIdx) && data[dataIdx]) {
                    const id = data[dataIdx][pk];
                    visibleIds.push(id);
                    rowIndexMap[id] = dataIdx;
                }
            });

            // 找到被拖拽项的原始 data index
            const trDataIndex = parseInt($tr.data('index'));
            const currentRow = data[trDataIndex];
            if (!currentRow) return;

            const changeid = currentRow[pk];
            const pid = typeof currentRow.pid !== 'undefined' ? currentRow.pid : (currentRow.parent_id || '');

            const params = {
                url: options.extend.dragsort_url,
                data: {
                    ids: visibleIds.join(','),
                    changeid: changeid,
                    pid: pid,
                    field: 'sort',
                    orderway: options.sortOrder,
                    table: options.extend.table,
                    pk: pk
                }
            };

            this.ajax(params)
                .then(() => {
                    // 更新本地数据的 sort 值（按新顺序）
                    const sortField = 'sort';
                    $.each(visibleIds, function(i, id) {
                        const dataIdx = rowIndexMap[id];
                        if (dataIdx !== undefined && data[dataIdx]) {
                            data[dataIdx][sortField] = i;
                        }
                    });
                    // 重新排序并加载
                    data.sort(function(a, b) {
                        var pa = a.parent_id || 0, pb = b.parent_id || 0;
                        if (pa !== pb) return pa - pb;
                        return (a[sortField] || 0) - (b[sortField] || 0) || (a[pk] || 0) - (b[pk] || 0);
                    });
                    this.table.bootstrapTable('load', data);
                })
                .catch(() => {
                    this.refresh();
                });
        }

        // 绑定拖拽按钮事件
        bindDragButtonEvents() {
            if (!this.table) return;
            
            const self = this;
            
            // 绑定拖拽排序按钮
            this.table.on("click", "a.btn-dragsort", function(e) {
                e.preventDefault();
                // 拖拽排序由 dragsort 插件处理
            });
        }

        // 绑定导出事件
        bindExportEvents() {
            if (!this.table || !this.options.showExport) return;
            
            const self = this;
            const toolbar = $(this.options.toolbar);
            
            // 绑定导出按钮事件
            toolbar.on('click', '.export .dropdown-menu a', function(e) {
                e.preventDefault();
                const exportType = $(this).data('type');
                self.handleExport(exportType);
            });
            
            // 更新导出提示
            this.updateExportTips();
        }

        // 处理导出
        handleExport(exportType) {
            if (!this.table) return;
            
            console.log(`导出类型: ${exportType}`);
            
            // 设置导出数据类型
            const options = this.table.bootstrapTable('getOptions');
            const originalExportDataType = options.exportDataType;
            
            // 临时设置导出类型
            if (this.options.exportDataType === 'auto') {
                const selectedCount = this.getSelectedIds(true).length;
                options.exportDataType = selectedCount > 0 ? 'selected' : 'all';
            }
            
            // 执行导出
            this.table.bootstrapTable('export', {
                type: exportType,
                escape: false
            });
            
            // 恢复原始设置
            options.exportDataType = originalExportDataType;
        }

        // 更新导出提示
        updateExportTips() {
            if (!this.table) return;
            
            const selectedCount = this.getSelectedIds(true).length;
            const exportType = selectedCount > 0 ? '选中' : '全部';
            
            if ($(".export .exporttips").length === 0) {
                $(".export .dropdown-menu").prepend("<li class='exporttips alert alert-warning-light mb-0 no-border p-2'></li>");
            }
            
            $(".export .exporttips").html(`导出记录：${exportType}`);
        }

        // 更新导出类型
        updateExportType() {
            if (this.options.exportDataType === 'auto') {
                const selectedCount = this.getSelectedIds(true).length;
                this.options.exportDataType = selectedCount > 0 ? 'selected' : 'all';
                this.updateExportTips();
            }
        }

        // 应用全局配置
        applyGlobalConfig() {
            this.extendBootstrapTable();
            this.setupLocale();
            
            if (this.options.iosCardView && this.isIOSDevice()) {
                this.options.cardView = true;
                $.fn.bootstrapTable.defaults.cardView = true;
            }
        }

        // 扩展Bootstrap Table
        extendBootstrapTable() {
            $.fn.bootstrapTable.Constructor.prototype.getSelectItem = function() {
                return this.$selectItem;
            };

            const originalOnPageListChange = $.fn.bootstrapTable.Constructor.prototype.onPageListChange;
            $.fn.bootstrapTable.Constructor.prototype.onPageListChange = function() {
                originalOnPageListChange.apply(this, Array.prototype.slice.apply(arguments));
                localStorage.setItem('pagesize', this.options.pageSize);
                return false;
            };
        }

        // 设置本地化
        setupLocale() {
            $.extend($.fn.bootstrapTable.locales[this.options.locale], {
                formatCommonSearch: () => L('Common search'),
                formatCommonSubmitButton: () => L('Search'),
                formatCommonResetButton: () => L('Reset'),
                formatCommonCloseButton: () => L('Close'),
                formatCommonChoose: () => L('Choose'),
                formatJumpto: () => L('Go')
            });
        }

        // 绑定事件
        bindEvents() {
            if (!this.table) return;

            this.bindTableEvents();
            this.bindToolbarEvents();
            this.bindSelectionEvents();
            this.bindDragEvents();
            this.bindExportEvents();
        }

        // 绑定表格事件
        bindTableEvents() {
            const self = this;

            // 加载成功事件
            this.table.on('load-success.bs.table', function(e, data) {
                // console.log('表格数据加载成功:', data);
                self.handleLoadSuccess(data);
                self.handleSelectionChange();
            });

            // 加载错误事件
            this.table.on('load-error.bs.table', function(status, res, e) {
                // console.error('表格数据加载错误:', status, res, e);
                self.handleLoadError(e);
            });

            // 刷新事件
            this.table.on('refresh.bs.table', function(e, settings, data) {
                // console.log('表格刷新');
                self.handleRefresh();
            });

            // 双击行事件
            if (this.options.dblClickToEdit) {
                this.table.on('dbl-click-row.bs.table', function(e, row, element, field) {
                    // console.log('双击行:',e, row, element, field);
                    self.handleDoubleClick(row, element);
                });
            }

            // 更换页码或页面大小事件
            this.table.on('page-change.bs.table', function(e, number, size) {
                // console.log('更改页码或页面大小');
                self.handlePageChange(number, size);
            });
        }

        // 绑定工具栏事件
        bindToolbarEvents() {
            const toolbar = $(this.options.toolbar);
            const self = this;

            // 刷新按钮
            toolbar.on('click', '.btn-refresh', function() {
                self.refresh();
            });

            // 添加按钮
            toolbar.on('click', '.btn-add', function() {
                self.handleAdd();
            });

            // 编辑按钮
            toolbar.on('click', '.btn-preview', function() {
                self.handlePreview();
            });

            // 编辑按钮
            toolbar.on('click', '.btn-edit', function() {
                self.handleEdit();
            });

            // 删除按钮
            toolbar.on('click', '.btn-del', function() {
                self.handleDelete();
            });

            // 批量操作按钮
            toolbar.on('click', '.btn-multi', function() {
                self.handleMultiAction($(this).data('action'));
            });

            // 导入按钮
            toolbar.on('click', '.btn-import', function() {
                self.handleImport();
            });
        }

        // 绑定行操作事件
        bindOperationEvents() {
            // 修复：当表格不存在时才返回，应该是不存在时返回
            if (!this.table) { return; }
            const self = this;

            // tooltip 安全隐藏（兼容 Bootstrap / jQuery UI 冲突）
            const safeHideTooltip = function($el) {
                try { $el.tooltip('hide'); } catch(e) { /* 忽略 tooltip 冲突 */ }
            };

            // 预览按钮
            this.table.on('click', '.btn-previewone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                safeHideTooltip($(this));
                const index = $(this).closest('tr').data('index');
                const row = self.getRowByIndex(index);
                self.handleRowPreview(row, $(this));
            });

            // 添加按钮
            this.table.on('click', '.btn-addone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                safeHideTooltip($(this));
                const index = $(this).closest('tr').data('index');
                const row = self.getRowByIndex(index);
                self.handleRowAdd(row, $(this));
            });

            // 编辑按钮
            this.table.on('click', '.btn-editone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                safeHideTooltip($(this));
                const index = $(this).closest('tr').data('index');
                const row = self.getRowByIndex(index);
                self.handleRowEdit(row, $(this));
            });

            // 删除按钮
            this.table.on('click', '.btn-delone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                safeHideTooltip($(this));
                const index = $(this).closest('tr').data('index');
                const row = self.getRowByIndex(index);
                self.handleRowDelete(row, $(this));
            });
        }

        // 绑定单元格通用事件
        bindCellCommonEvents() {
            if (!this.table) { return; }
            const self = this;

            // 点击行内搜索事件
            this.table.on('click', 'a[role="search"]', function(e) {
                console.log('行内搜索:',e);

            });

            // 点击切换状态事件
            this.table.on('click', '.form-switch>input[role="switch"]:not(.disabled)', function(e) {
                // 隐藏tooltip（兼容 Bootstrap / jQuery UI 冲突）
                try { $(this).tooltip('hide'); } catch(e) {}
                const index = $(this).closest('tr').data('index');
                const row = self.getRowByIndex(index);
                const field = $(this).data('field');
                // 点击后 checkbox.checked 已经是「切换后」的状态：
                // 选中 → 目标值为 on-value（yes）；未选中 → 目标值为 off-value（no）。
                // 必须与 data-params 里的目标值保持一致，否则 updateRow 会按错误值重渲染，开关回弹。
                const value = $(this).prop('checked') ? $(this).data('on-value') : $(this).data('off-value');
                self.handleRowToggleChange(field, value, row, $(this), index);
            });

            // 行内编辑输入框失去焦点保存
            this.table.on('blur', '.input-value', function(e) {
                const $input = $(this);
                const $td = $input.closest('td');
                const $tr = $input.closest('tr');
                const index = $tr.data('index');
                const row = self.getRowByIndex(index);
                if (!row) return;

                // 获取列配置（通过 td 的 data-field 属性）
                const colField = $td.data('field') || $input.data('field');
                if (!colField) return;

                // 查找对应列
                const columns = self.options.columns;
                const column = columns.find(c => c.field === colField);
                if (!column || !column.url) return;

                const newValue = $input.val();
                const oldValue = row[colField];

                if (String(newValue) === String(oldValue)) return; // No change

                // 构建参数
                const pk = self.options.pk || self.options.idField || 'id';
                const params = {};
                params[pk] = row[pk];
                params[colField] = newValue;
                // sort 字段额外传 sort_field
                if (colField === 'sort') {
                    params['sort_field'] = colField;
                }

                self.ajax({
                    url: column.url,
                    data: $.param(params)
                }).then((data, ret) => {
                    // 更新本地数据
                    row[colField] = newValue;

                    if (colField === 'sort') {
                        // sort 字段变更后需要重排序（同组内 sort ASC, id ASC）
                        const allData = self.table.bootstrapTable('getData');
                        allData.sort(function(a, b) {
                            var pa = a.parent_id || 0, pb = b.parent_id || 0;
                            if (pa !== pb) return pa - pb;
                            var sortA = a[colField] || 0, sortB = b[colField] || 0;
                            if (sortA !== sortB) return sortA - sortB;
                            return (a[pk] || 0) - (b[pk] || 0);
                        });
                        self.table.bootstrapTable('load', allData);
                    } else {
                        try {
                            self.table.bootstrapTable('updateRow', { index: index, row: row });
                        } catch (er) {
                            $input.val(newValue);
                        }
                    }
                }).catch((error) => {
                    // 恢复旧值
                    $input.val(oldValue);
                    Layer.top().toast({content: (error.msg || '保存失败'), type: 'danger'});
                });
            });
        }

        // 初始化插件
        initPlugins() {
            var self = this;
            // 这里可以初始化其他插件
            // console.log('初始化表格插件');

            this.table.on('post-body.bs.table', () => {
                var columns = self.table.bootstrapTable('getOptions').columns;

                if (self.options.treeShowField && columns && columns[0][self.options.treeColumn] && columns[0][self.options.treeColumn].visible) {
                    // 安全检查：确保 treegrid 插件及其默认配置已正确加载
                    if (!$.fn.treegrid || !$.fn.treegrid.defaults || typeof $.fn.treegrid.defaults.getRootNodes !== 'function') {
                        console.warn('[EnhancedTable] jquery.treegrid 插件未正确加载，跳过树形网格初始化');
                        return;
                    }
                    self.table.treegrid({
                        expanderExpandedClass: 'bi bi-caret-down-fill',
                        expanderCollapsedClass: 'bi bi-caret-right-fill',
                        treeColumn: self.options.treeColumn,
                        initialState: self.options.initialState,
                        onChange () {
                            self.table.bootstrapTable('resetView');
                        }
                    });
                }
            })
        }

        // 表格加载数据
        loadData(data) {
            if (this.table) {
                this.table.bootstrapTable('load', data);
            }
        }
        
        // 表格追加数据
        appendData(data) {
            if (this.table) {
                this.table.bootstrapTable('append', data);
            }
        }
        
        // API方法
        refresh() {
            if (this.table) {
                this.table.bootstrapTable('refresh');
            }
        }
        
        // 替换URL
        replaceUrl(url) {
            if (this.table) {
                this.table.bootstrapTable('refresh', {
                    url: url
                });
            }
        }

        // 获取选中的IDS
        getSelectedIds(currentPageOnly = false) {
            if (!this.table) return [];
            
            if (!currentPageOnly && this.options.maintainSelected) {
                return this.selectedIds;
            }
            
            const options = this.table.bootstrapTable('getOptions');
            return $.map(this.table.bootstrapTable('getSelections'), row => row[options.pk]);
        }

        // 获取选中的数据
        getSelectedData(currentPageOnly = false) {
            if (!this.table) return [];
            
            if (!currentPageOnly && this.options.maintainSelected) {
                return this.selectedData;
            }
            
            return this.table.bootstrapTable('getSelections');
        }

        // 清除选择
        clearSelection() {
            this.selectedIds = [];
            this.selectedData = [];
            if (this.table) {
                this.table.trigger("uncheckbox");
            }
        }

        // 获取指定ID的行
        getRowById(id) {
            const selectedData = this.getSelectedData();
            for (const row of selectedData) {
                if (row[this.options.pk] == id) {
                    return row;
                }
            }
            return {};
        }

        /**
         * 获取指定索引的行
         * @param {number} index - 行索引
         * @returns {object|null} 行数据或null
         */
        getRowByIndex(index) {
            const data = this.table.bootstrapTable('getData');
            return data[index] || null;
        }

        /**
         * 检查是否为iOS设备
         * @returns {boolean} 是否为iOS设备
         */
        isIOSDevice() {
            return navigator.userAgent.match(/(iPod|iPhone|iPad)/);
        }
        /**
         * 获取当前页面大小
         * @returns {number} 当前页面大小
         */
        static getPageSize() {
            return localStorage.getItem('pagesize') || 10;
        }

        /**
         * 获取当前语言环境
         * @returns {string} 当前语言环境（zh-CN或en-US）
         */
        static getLocale() {
            return (navigator.language || 'zh-CN').toLowerCase() === 'zh-cn' ? 'zh-CN' : 'en-US';
        }

        /**
         * 获取所有ID
         * @returns {Array} 所有ID数组
         */
        getAllIds() {
            if (!this.table) return [];
            const data = this.table.bootstrapTable('getData');
            const options = this.table.bootstrapTable('getOptions');
            return data.map(item => item[options.pk]);
        }

        /**
         * 更新维护的选择状态
         * @param {Array} currentIds - 当前选中的ID数组
         * @param {Array} currentData - 当前选中的数据数组
         */
        updateMaintainedSelection(currentIds, currentData) {
            const allIds = this.getAllIds();
            
            this.selectedIds = this.selectedIds
                .filter(id => allIds.includes(id))
                .concat(currentIds);
                
            this.selectedData = this.selectedData
                .filter(item => allIds.includes(item[this.options.pk]))
                .concat(currentData);
        }

        // 数据读取成功处理
        handleLoadSuccess(data) { 
            // console.log('数据加载成功处理:', data);
        }
        
        // 数据分页变化处理
        handlePageChange(number, size) {
            // console.log('数据分页变化:', `页码=${number}, 页面大小=${size}`);
        }

        // 数据加载失败处理
        handleLoadError(error) { 
            console.error('数据加载错误处理:', error);
        }
        
        // 刷新处理
        handleRefresh() { 
            console.log('刷新处理');
        }
        
        handleDoubleClick(row, element) {
            console.log('双击行处理:', row);
            // 默认双击编辑逻辑
            if (this.options.extend.edit_url) {
                const options = this.table.bootstrapTable('getOptions');
                const ids = row[options.pk];
                const editRow = $.extend({}, row, {ids: ids});
                const url = EnhancedTable.utils.replaceURL(this.options.extend.edit_url, editRow, this.table);
                
                // 打开编辑窗口
                console.log('打开编辑窗口:', url);
            }
        }
        
        // 事件新增方法
        handleAdd() {
            // console.log('添加处理');
            var self = this;
            if (this.options.extend.add_url) {
                const url = EnhancedTable.utils.replaceURL(this.options.extend.add_url, {}, this.table);
                
                // console.log('打开新增窗口:', this.options.extend.add_url, url);
                Layer.iframe(url, {title: this.options.extend.add_title, maxWidth:0, area:['860px','80%'], resize:true, minBtn:true, maxBtn:true, btn:['保存', '重置'], 
                    iframeTitle: function(iframeTitle, index, layero) {
                        return EnhancedTable.utils.iframeTitle(iframeTitle);
                    },
                    yes:function(index, layero){
                        const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                        const iframeDoc = iframe.contentDocument;
                        
                        // 查找提交按钮并点击
                        const submitBtn = iframeDoc.querySelector('button[type="submit"], input[type="submit"], .submit-btn');
                        if (submitBtn) {
                            submitBtn.click();
                        } else {
                            Layer.top().toast({content: '未找到提交按钮', type: 'warning'});
                        }
                    },
                    btn2:function(index, layero){
                        // 添加窗口关闭后执行的回调函数
                        const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                        const iframeDoc = iframe.contentDocument;
                        
                        // 查找重置按钮并点击
                        const resetBtn = iframeDoc.querySelector('button[type="reset"], input[type="reset"], .reset-btn');
                        if (resetBtn) {
                            resetBtn.click();
                        } 
                        return false;
                    },
                    end:function(exitType){
                        if(exitType !== 'cancel'){
                            self.refresh();
                        }
                    }
                });
            }
        }

        // 事件预览方法
        handlePreview() { 
            var self = this;
            // console.log('预览处理');
            const selectedData = this.getSelectedData();
            if (selectedData.length > 0 && this.options.extend.preview_url) {

                if(selectedData.length > this.options.batchOpenMax){
                    Layer.top().toast({content: `最多只能批量预览 ${this.options.batchOpenMax} 条记录`, type: 'warning'});
                    return;
                }

                let currentIndex = 0;
                var options = this.table.bootstrapTable('getOptions');

                const openNextPreview = function() {
                    if(currentIndex >= selectedData.length){return;}

                    const row = selectedData[currentIndex];
                    const id = row[options.pk];
                    const previewRow = $.extend({}, row, {id: id});
                    const url = EnhancedTable.utils.replaceURL(self.options.extend.preview_url, previewRow, self.table);
                    
                    // console.log('打开预览窗口:', self.options.extend.preview_url, url);
                    Layer.iframe(url, {title:`${self.options.extend.preview_title} (${currentIndex + 1}/${selectedData.length})`, 
                                        maxWidth:0, area:['860px','600px'], resize:true, minBtn:true, maxBtn:true, btn:(selectedData.length > 1 ? ['确定', '关闭所有'] : ['确定']),
                        iframeTitle: function(iframeTitle, index, layero) {
                            return EnhancedTable.utils.iframeTitle(iframeTitle);
                        },
                        yes:function(index, layero){
                            Layer.close(index);
                        },
                        btn2:function(index, layero){
                            currentIndex = 99999;
                            Layer.close(index);
                        },
                        end: function(exitType) {
                            currentIndex++;
                            setTimeout(openNextPreview, 200);
                        }
                    });
                }

                openNextPreview();
            }
        }

        // 事件编辑方法
        handleEdit() { 
            var self = this;
            // console.log('编辑处理');
            const selectedData = this.getSelectedData();
            if (selectedData.length > 0 && this.options.extend.edit_url) {
                if(selectedData.length > this.options.batchOpenMax){
                    Layer.top().toast({content: `最多只能批量编辑 ${this.options.batchOpenMax} 条记录`, type: 'warning'});
                    return;
                }

                let currentIndex = 0;
                var options = this.table.bootstrapTable('getOptions');
                var refresh = false;

                const openNextEdit = function() {
                    if(currentIndex >= selectedData.length){return;}

                    const row = selectedData[currentIndex]; 
                    const id = row[options.pk];
                    const editRow = $.extend({}, row, {id: id});
                    const url = EnhancedTable.utils.replaceURL(self.options.extend.edit_url, editRow, self.table);
                    
                    // console.log('打开编辑窗口:', self.options.extend.edit_url, url);
                    Layer.iframe(url, {title: `${self.options.extend.edit_title} (${currentIndex + 1}/${selectedData.length})`,
                                maxWidth:0, area:['860px','80%'], resize:true, minBtn:true, maxBtn:true, btn:(selectedData.length > 1 ? ['保存', '重置', '关闭所有'] : ['保存', '重置']),
                        iframeTitle: function(iframeTitle, index, layero) {
                            return EnhancedTable.utils.iframeTitle(iframeTitle);
                        },
                        yes:function(index, layero){
                            const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                            const iframeDoc = iframe.contentDocument;
                            
                            // 查找提交按钮并点击
                            const submitBtn = iframeDoc.querySelector('button[type="submit"], input[type="submit"], .submit-btn');
                            if (submitBtn) {
                                submitBtn.click();
                            } else {
                                Layer.top().toast({content: '未找到提交按钮', type: 'warning'});
                            }
                        },
                        btn2:function(index, layero){
                            // 添加窗口关闭后执行的回调函数
                            const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                            const iframeDoc = iframe.contentDocument;
                            
                            // 查找重置按钮并点击
                            const resetBtn = iframeDoc.querySelector('button[type="reset"], input[type="reset"], .reset-btn');
                            if (resetBtn) {
                                resetBtn.click();
                            } 
                            return false;
                        },
                        btn3:function(index, layero){
                            currentIndex = 99999;
                            Layer.close(index, 'cancel');
                            return false;
                        },
                        end:function(exitType){
                            refresh = refresh || (exitType !== 'cancel');
                            currentIndex++;
                            if(currentIndex >= selectedData.length && refresh){
                                self.refresh();
                            }else{
                                setTimeout(openNextEdit, 200);
                            }
                        }
                    });
                };

                openNextEdit();
            }
        }
        
        // 事件删除方法
        handleDelete() { 
            const selectedIds = this.getSelectedIds();
            if (selectedIds.length > 0 && this.options.extend.del_url) {
                // 检测是否可用 Wise.ui.action（ESM 模式）
                if (window.Wise && window.Wise.ui && window.Wise.ui.action) {
                    const restoreUrl = this.options.extend.restore_url || this.options.extend.del_url.replace(/\/delete$/, '/restore');
                    window.Wise.ui.action.delete({
                        url: this.options.extend.del_url,
                        data: { ids: selectedIds },
                        restoreUrl: restoreUrl,
                        message: `已删除 ${selectedIds.length} 条记录`,
                    }).then(result => {
                        if (result.success) {
                            this.clearSelection();
                            if (!result.undone) this.refresh();
                        }
                    });
                    return;
                }

                // 兜底：原有逻辑
                Layer.confirm(`确定要删除选中的 ${selectedIds.length} 项吗？`, {
                    icon: 'info',
                    title: this.options.extend.del_title,
                    btn: ['是', '否']
                }, (isConfirm) => {
                    if (!isConfirm) return true;
                    this.multi('del', selectedIds);
                    return true;
                });
            }
        }
        
        // 事件批量操作方法
        handleMultiAction(action) {
            const selectedIds = this.getSelectedIds();
            if (selectedIds.length > 0) {
                this.multi(action, selectedIds);
            }
        }
        
        // 事件导入方法
        handleImport() { 
            console.log('导入处理');
        }

        // 批量操作
        multi(action, ids, element) {
            if (!this.table) return;
            
            const options = this.table.bootstrapTable('getOptions');
            const data = element ? $(element).data() : {};
            
            ids = Array.isArray(ids) ? ids.join(',') : ids;
            
            let url = data.url || (action === 'del' ? options.extend.del_url : options.extend.multi_url);
            let params = data.params ? (typeof data.params === 'object' ? $.param(data.params) : data.params) : '';
            
            const requestOptions = {
                url: url,
                data: { action: action, ids: ids, params: params }
            };

            console.log('执行批量操作:', requestOptions);
            
            this.ajax(requestOptions)
                .then((data, ret) => {
                    this.clearSelection();
                    this.refresh();
                    
                    const success = $(element).data('success');
                    if (typeof success === 'function') {
                        success.call(element, data, ret);
                    }
                })
                .catch((data, ret) => {
                    const error = $(element).data('error');
                    if (typeof error === 'function') {
                        error.call(element, data, ret);
                    }
                });
        }

        // 行内预览按钮处理方法
        handleRowPreview(row, $button) {
            // console.log('行内预览处理:', row);
            if (!row || !this.options.extend.preview_url) return;
            
            var options = this.table.bootstrapTable('getOptions');
            const id = row[options.pk];
            const previewRow = $.extend({}, row, {id: id});
            const url = EnhancedTable.utils.replaceURL(this.options.extend.preview_url, previewRow, this.table);
            
            // console.log('打开预览窗口:', this.options.extend.preview_url, url);
            Layer.iframe(url, {title:this.options.extend.preview_title, maxWidth:0, area:['860px','600px'], resize:true, minBtn:true, maxBtn:true, btn:['确定'],
                iframeTitle: function(iframeTitle, index, layero) {
                    return EnhancedTable.utils.iframeTitle(iframeTitle);
                },
                yes:function(index, layero){
                    Layer.close(index);
                }
            });
        }

        // 行内添加按钮处理方法
        handleRowAdd(row, $button) {
            // console.log('行内添加处理:', row);
            if (!row || !this.options.extend.add_url) return;
            
            var options = this.table.bootstrapTable('getOptions');
            const id = row[options.pk];
            const addRow = $.extend({}, row, {id: id});
            const url = EnhancedTable.utils.replaceURL(this.options.extend.add_url, addRow, this.table);
            
            // console.log('打开添加窗口:', this.options.extend.add_url, url);
            Layer.iframe(url, {title:this.options.extend.add_title, maxWidth:0, area:['860px','600px'], resize:true, minBtn:true, maxBtn:true, btn:['确定'],
                iframeTitle: function(iframeTitle, index, layero) {
                    return EnhancedTable.utils.iframeTitle(iframeTitle);
                },
                yes:function(index, layero){
                    Layer.close(index);
                }
            });
        }

        // 行内编辑按钮处理方法
        handleRowEdit(row, $button) {
            let self = this;
            // console.log('行内编辑处理:', row);
            if (!row || !this.options.extend.edit_url) return;
            
            const options = this.table.bootstrapTable('getOptions');
            const id = row[options.pk];
            const editRow = $.extend({}, row, {id: id});
            const url = EnhancedTable.utils.replaceURL(this.options.extend.edit_url, editRow, this.table);
            
            // console.log('打开编辑窗口:', url);

            Layer.iframe(url, {title: this.options.extend.edit_title, maxWidth:0, area:['860px','80%'], resize:true, minBtn:true, maxBtn:true, btn:['保存', '重置'],
                iframeTitle: function(iframeTitle, index, layero) {
                    return EnhancedTable.utils.iframeTitle(iframeTitle);
                },
                yes:function(index, layero){
                    // console.log('点击保存按钮', index);
                    
                    const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                    const iframeDoc = iframe.contentDocument;

                    // 查找提交按钮并点击
                    const submitBtn = iframeDoc.querySelector('button[type="submit"], input[type="submit"], .submit-btn');
                    if (submitBtn) {
                        submitBtn.click();
                    } else {
                        Layer.top().toast({content: 'iframe内未找到提交按钮', type: 'warning'});
                    }
                },
                btn2:function(index, layero){
                    // 添加窗口关闭后执行的回调函数
                    const iframe = document.getElementById(`ws-layer-iframe-${index}`);
                    const iframeDoc = iframe.contentDocument;
                    
                    // 查找重置按钮并点击
                    const resetBtn = iframeDoc.querySelector('button[type="reset"], input[type="reset"], .reset-btn');
                    if (resetBtn) {
                        resetBtn.click();
                    } 
                    return false;
                },
                end:function(exitType){
                    if(exitType !== 'cancel'){
                        self.refresh();
                    }
                }
            });
        }

        // 行内删除按钮处理方法
        handleRowDelete(row, $button) {
            let self = this;
            if (!row || !this.options.extend.del_url) return;
            
            const options = this.table.bootstrapTable('getOptions');
            const id = row[options.pk];

            // 检测是否可用 Wise.ui.action（ESM 模式）
            if (window.Wise && window.Wise.ui && window.Wise.ui.action) {
                const restoreUrl = this.options.extend.restore_url || this.options.extend.del_url.replace(/\/delete$/, '/restore');
                window.Wise.ui.action.delete({
                    url: this.options.extend.del_url,
                    data: { id: id },
                    restoreUrl: restoreUrl,
                    message: '已删除',
                    target: $button ? $button[0] : null,
                }).then(result => {
                    if (result.success) {
                        self.clearSelection();
                        if (!result.undone) self.refresh();
                    }
                });
                return;
            }

            // 兜底：原有逻辑
            Layer.confirm('您确定要删除此项目吗', {
                icon:'danger'
            }, ()=>{
                const params = {
                    url: this.options.extend.del_url,
                    data: { id: id }
                };
                
                this.ajax(params)
                    .then((data) => {
                        Layer.top().toast({content:'删除成功', type:'success'});
                        self.refresh();
                    })
                    .catch((error) => {
                        Layer.top().toast({content:(error.msg || '删除失败'), type:'danger'});
                    });
                return true;
            });
        }

        // 行内switch切换状态
        handleRowToggleChange(field, value, row, $element, index) {
            if (!this.table) return;

            var url = $element.data('url'),
                params = $element.data('params'),
                confirmMsg = $element.data('confirm');
            if (!url) {
                console.error('未配置切换状态的URL');
                Layer.top().toast({content:'未配置切换状态的URL', type:'danger'});
                // 恢复开关原状态
                $element.prop('checked', !$element.prop('checked'));
                return;
            }

            const doToggle = () => {
                this.ajax({
                    url: url,
                    data: params
                }).then((data, ret) => {
                    if(data.code == 0){
                        // 更新本地行数据（保持数据源一致）
                        row[field] = value;
                        // 关键：checkbox 的 checked 状态在用户点击时已被浏览器自动翻转，
                        // 视觉上已是目标状态，无需重绘任何 DOM。updateRow/updateCell 都会
                        // 触发 initBody 或替换单元格，导致树形结构重置、单元格丢失。
                        // 这里只需同步 data-params，保证下次切换时目标值计算正确。
                        const pk = this.options.pk || this.options.idField || 'id';
                        const yes = $element.data('on-value');
                        const no = $element.data('off-value');
                        const newActive = (value == yes);
                        $element.data('params', `${pk}=${row[pk]}&${field}=${newActive ? no : yes}`);
                        // 成功提示（与删除等操作保持一致）
                        const successMsg = (data && data.msg) ? data.msg : '操作成功';
                        Layer.top().toast({content: successMsg, type: 'success'});
                        // 广播切换成功：页面控制器可据此联动刷新依赖该状态位的其它列
                        // （如插件列表的"安装状态"徽标随启用开关联动），无需整表 refresh。
                        try {
                            this.table.trigger('ws:table:toggle-success', [field, value, row, index, $element]);
                        } catch (e) {
                            console.warn('[EnhancedTable] toggle-success 事件派发失败', e);
                        }
                    }
                    // 注意：业务失败（code!=0）会走 ajax() 封装的 reject → 进入下方 catch，
                    // 因此这里不需要 else 分支。
                }).catch((data, ret) => {
                    console.error('切换状态失败:', data, ret);
                    // 恢复开关原状态（用户点击后 checked 已被浏览器翻转，再翻转一次即还原）
                    $element.prop('checked', !$element.prop('checked'));
                    const msg = (data && data.msg) ? data.msg : '切换状态失败';
                    Layer.top().toast({content: msg, type: 'danger'});
                });
            };

            // P1-27：`toggle` formatter 渲染了 `data-confirm`，但此前处理器从未读取 ——
            // 有确认文案的开关会「直接生效」而非先确认。这里补上：确认后执行，取消则回滚开关。
            if (confirmMsg) {
                Layer.confirm(confirmMsg, { icon: 'warning', title: '操作确认' }, () => {
                    doToggle();
                }, () => {
                    $element.prop('checked', !$element.prop('checked'));
                });
                return;
            }

            doToggle();
        }

        /**
         * 绑定文本展开/收起事件
         */
        bindExpandEvents() {
            const self = this;
            
            // 使用事件委托处理所有展开/收起点击
            if (this.table) {
                // 普通文本展开
                this.table.on('click', '.expandable-content', function(e) {
                    e.stopPropagation();
                    self.toggleTextExpand($(this));
                });
                
                // 长文本展开/收起按钮
                this.table.on('click', '.expand-btn', function(e) {
                    e.stopPropagation();
                    self.expandLongText($(this).closest('.long-text-container'));
                });
                
                this.table.on('click', '.collapse-btn', function(e) {
                    e.stopPropagation();
                    self.collapseLongText($(this).closest('.long-text-container'));
                });
                
                // 代码展开
                this.table.on('click', '.code-expand-btn', function(e) {
                    e.stopPropagation();
                    self.expandCode($(this).closest('.code-snippet'));
                });
                
                // 代码复制
                this.table.on('click', '.code-copy-btn', function(e) {
                    e.stopPropagation();
                    self.copyCode($(this).closest('.code-snippet'));
                });
            }
        }

        /**
         * 切换文本展开状态
         * @param {jQuery} $element - 可展开元素
         */
        toggleTextExpand($element) {
            const isExpanded = $element.hasClass('expanded');
            const $truncated = $element.find('.truncated-text');
            const $full = $element.find('.full-text');
            const $toggle = $element.find('.expand-toggle');
            
            if (isExpanded) {
                // 收起
                $truncated.show();
                $full.hide();
                $element.removeClass('expanded');
                $toggle.html('<i class="bi bi-chevron-down expand-icon" title="点击展开"></i>');
            } else {
                // 展开
                $truncated.hide();
                $full.show();
                $element.addClass('expanded');
                $toggle.html('<i class="bi bi-chevron-up collapse-icon" title="点击收起"></i>');
            }
        }

        /**
         * 展开长文本
         * @param {jQuery} $container - 长文本容器
         */
        expandLongText($container) {
            const $preview = $container.find('.text-preview');
            const $full = $container.find('.text-full');
            const $expandBtn = $container.find('.expand-btn');
            const $collapseBtn = $container.find('.collapse-btn');
            
            $preview.hide();
            $full.show();
            $expandBtn.hide();
            $collapseBtn.show();
            
            // 更新表格视图
            if (this.table) {
                this.table.bootstrapTable('resetView');
            }
        }

        /**
         * 收起长文本
         * @param {jQuery} $container - 长文本容器
         */
        collapseLongText($container) {
            const $preview = $container.find('.text-preview');
            const $full = $container.find('.text-full');
            const $expandBtn = $container.find('.expand-btn');
            const $collapseBtn = $container.find('.collapse-btn');
            
            $preview.show();
            $full.hide();
            $expandBtn.show();
            $collapseBtn.hide();
            
            // 更新表格视图
            if (this.table) {
                this.table.bootstrapTable('resetView');
            }
        }

        /**
         * 展开代码
         * @param {jQuery} $snippet - 代码片段元素
         */
        expandCode($snippet) {
            const fullCode = $snippet.data('full-code');
            const $pre = $snippet.find('pre');
            
            // 使用Layer弹窗显示完整代码
            Layer.open({
                type: 1,
                title: '完整代码',
                area: ['80%', '80%'],
                content: `<pre style="padding: 15px; background: #f8f9fa; border-radius: 4px; overflow: auto; max-height: 100%;"><code>${fullCode}</code></pre>`,
                btn: ['复制', '关闭'],
                yes: function(index) {
                    // 复制代码到剪贴板
                    self.copyToClipboard(fullCode);
                    Layer.close(index);
                }
            });
        }

        /**
         * 复制代码
         * @param {jQuery} $snippet - 代码片段元素
         */
        copyCode($snippet) {
            var code = $snippet.data('full-code') || $snippet.find('code').html();
            if(typeof code == 'object'){
                code = JSON.stringify(code, null, 2);
            }
            this.copyToClipboard(code);
            
            // 显示复制成功提示
            Layer.toast({
                content: '代码已复制到剪贴板',
                type: 'success'
            });
        }

        /**
         * 复制文本到剪贴板
         * @param {string} text - 要复制的文本
         */
        copyToClipboard(text) {
            // 创建临时textarea元素
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = 0;
            document.body.appendChild(textarea);
            
            // 选择并复制文本
            textarea.select();
            textarea.setSelectionRange(0, 99999); // 对于移动设备
            
            try {
                document.execCommand('copy');
            } catch (err) {
                console.error('Failed to copy text: ', err);
            }
            
            // 清理
            document.body.removeChild(textarea);
        }

        // AJAX请求封装
        ajax(options) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: options.url,
                    type: options.type || 'POST',
                    data: options.data,
                    dataType: 'json',
                    success: (data, textStatus, jqXHR) => {
                        if (data.code === 0 || data.success) {
                            resolve(data, data);
                        } else {
                            reject(data, data);
                        }
                    },
                    error: (jqXHR, textStatus, errorThrown) => {
                        reject({ code: 0, msg: errorThrown }, {});
                    }
                });
            });
        }

        /**
         * 监听事件
         * @param {String} event 事件名称
         * @param {Function} listener 事件监听器
         */
        on(event, listener) {
            if(event.endsWith('.bs.table') && this.table) {
                this.table.on(event, listener);
            } else {
                this.eventBus.on(event, listener);
            }
        }
    
        /**
         * 取消监听事件
         * @param {String} event 事件名称
         * @param {Function} listener 事件监听器
         */
        off(event, listener) {
            if(event.endsWith('.bs.table') && this.table) {
                this.table.off(event, listener);
            } else {
                this.eventBus.off(event, listener);
            }
        }
    }

    EnhancedTable.FORMATTERS = {
        // 图标格式化
        icon: function(instance, value, row, index) {
            value = value || this.defaultIcon || 'bi bi-circle';
            value = value.indexOf(' ') > -1 ? value : (value ? `bi bi-${value}` : '');
            return `<i class="${value}"></i>`;
        },

        // 图片格式化
        image: function(instance, value, row, index) {
            return EnhancedTable.FORMATTERS.images.call(this, value, row, index);
        },

        images: function(instance, value, row, index) {            
            // 禁用点击选择行
            this.clickToSelect = false;

            value = value || '';
            const classname = this.classname || 'img-sm img-center';
            const arr = value ? (value.indexOf('data:image/') === -1 ? value.split(',') : [value]) : [];
            const html = [];

            $.each(arr, function(i, val) {
                val = val || '/assets/img/blank.gif';
                let url = EnhancedTable.utils.cdnurl(val, true);
                
                if (Config?.upload?.thumbstyle && 
                    !url.match(/^(\/|data:image\/)/) && 
                    url.indexOf(Config.upload.thumbstyle.substring(0, 1)) === -1) {
                    url += Config.upload.thumbstyle;
                }
                
                html.push(`<a href="javascript:"><img class="${classname}" src="${url}" /></a>`);
            });

            return html.join(' ');
        },

        // 状态格式化
        status: function(instance, value, row, index) {
            const custom = { normal: 'success', hidden: 'gray', deleted: 'danger', locked: 'info' };
            if (this.custom) {
                $.extend(custom, this.custom);
            }
            this.custom = custom;
            this.icon = 'bi bi-circle';
            return EnhancedTable.FORMATTERS.normal.call(this, instance, value, row, index);
        },

        // 通用格式化
        normal: function(instance, value, row, index) {
            const colorArr = ['primary', 'success', 'danger', 'warning', 'info', 'gray'];
            const custom = this.custom || {};
            // value = row[this.field] != null ? row[this.field]+'' : (value != null ? value+'' : '');
            value = value != null ? value+'' : '';
            const keys = this.searchList ? Object.keys(this.searchList) : [];
            const keyIndex = keys.indexOf(value+'');
            let color = custom[value] || (keyIndex > -1 ? colorArr[keyIndex] : 'body-emphasis');
            let display = keyIndex > -1 ? Object.values(this.searchList)[keyIndex] : value;
            const icon = typeof this.icon !== 'undefined' ? this.icon : null;

            value = EnhancedTable.utils.escape(value);
            display = EnhancedTable.utils.escape(display);

            // let html = `<span class="text-${color}">${this.icon ? `<i class="${this.icon}"></i> ` : ''}${display}</span>`;

            // 获取配置参数
            const maxLength = this.maxLength || 50; // 默认最大显示长度
            const ellipsis = this.ellipsis !== false; // 是否显示省略号
            const expandable = this.expandable !== false; // 是否可展开
            const multiline = this.multiline || false; // 是否允许多行显示
            
            let displayText = display;
            let isTruncated = false;
            
            // 检查是否需要截断
            if (ellipsis && display.length > maxLength && expandable) {
                displayText = display.substring(0, maxLength) + '...';
                isTruncated = true;
            }
            
            let html = `<span class="text-${color}">${icon ? `<i class="${icon}"></i> ` : ''}${displayText}</span>`;
            
            // 添加展开/收起功能
            if (isTruncated) {
                const expandIcon = `<i class="bi bi-chevron-down expand-icon" title="展开"></i>`;
                const collapseIcon = `<i class="bi bi-chevron-up collapse-icon" title="收起"></i>`;
                
                html = `
                    <div class="expandable-content ${multiline ? 'multiline' : ''}" data-full-text="${value}">
                        <div class="content-wrapper">
                            <span class="truncated-text">${displayText}</span>
                            <span class="full-text" style="display: none;">${value}</span>
                            <span class="expand-toggle">${expandIcon}</span>
                        </div>
                    </div>
                `;
            }
            
            return html;
        },

        // 专门的长文本格式化器
        longtext: function(instance, value, row, index) {
            value = (value == null || value.length === 0) ? '' : value.toString();
            value = EnhancedTable.utils.escape(value);
            
            // 配置参数
            const maxLength = this.maxLength || 100;
            const lines = this.lines || 3; // 最大显示行数
            const expandable = this.expandable !== false;
            const showToggle = this.showToggle !== false;
            const tooltip = this.tooltip !== false;
            
            // 如果没有内容或不需要截断
            if (!value || value.length <= maxLength) {
                return `<div class="text-content">${value}</div>`;
            }
            
            const truncatedText = value.substring(0, maxLength) + '...';
            const expandIcon = `<i class="bi bi-chevron-down" title="展开"></i>`;
            const collapseIcon = `<i class="bi bi-chevron-up" title="收起"></i>`;
            
            return `
                <div class="long-text-container" data-full-text="${value}">
                    <div class="text-preview" style="
                        display: -webkit-box;
                        -webkit-line-clamp: ${lines};
                        -webkit-box-orient: vertical;
                        overflow: hidden;
                        text-overflow: ellipsis;">
                        ${truncatedText}
                    </div>
                    <div class="text-full" style="display: none;">
                        ${value}
                    </div>
                    ${showToggle ? `
                        <div class="text-toggle">
                            <button type="button" class="btn btn-xs btn-link expand-btn" title="展开">
                                ${expandIcon} 更多
                            </button>
                            <button type="button" class="btn btn-xs btn-link collapse-btn" style="display: none;" title="收起">
                                ${collapseIcon} 更少
                            </button>
                        </div>
                    ` : ''}
                </div>
            `;
        },

        // 搜索格式化
        search: function(instance, value, row, index) {
            this.clickToSelect = false;

            const colorArr = ['primary', 'success', 'danger', 'warning', 'info', 'gray'];
            const custom = this.custom || {};
            
            value = row[this.field] != null ? row[this.field]+'' : (value != null ? value+'' : '');
            const keys = this.searchList ? Object.keys(this.searchList) : [];
            const keyIndex = keys.indexOf(value+'');
            let color = custom[value] || (keyIndex > -1 ? colorArr[keyIndex] : 'primary');
            let display = keyIndex > -1 ? this.searchList[keyIndex] : 
                (value.length > 0 ? L(value.charAt(0).toUpperCase() + value.slice(1)) : L(value));

            value = EnhancedTable.utils.escape(value);
            display = EnhancedTable.utils.escape(display);

            let html = `<span class="text-${color}">${this.icon ? `<i class="${this.icon}"></i> ` : ''}${display}</span>`;
            
            html = `<a href="javascript:" data-bs-toggle="tooltip" role="search"
                title="${L('Click to search %s', display)}" 
                data-field="${this.field}" data-value="${value}">${html}</a>`;

            return html;
        },

        // 日期格式化
        date: function(instance, value, row, index) {
            value = value || '';
            const format = this.format || 'YYYY-MM-DD';
            return value? moment(value).format(format) : '';
        },

        // 时间格式化
        time: function(instance, value, row, index) {
            value = value || '';
            const format = this.format || 'HH:mm:ss';
            return value? moment(value).format(format) : '';
        },

        // 日期时间格式化
        datetime: function(instance, value, row, index) {
            value = value || '';
            const format = this.format || 'YYYY-MM-DD HH:mm:ss';
            return value ? moment(value).format(format) : '';
        },

        // 开关切换格式化器
        toggle: function(instance, value, row, index) {
            // 禁用点击选择行
            this.clickToSelect = false;

            const table = instance.table;
            const options = table ? table.bootstrapTable('getOptions') : {};
            const pk = options.pk || 'id';
            const color = this.color || 'success';
            const yes = this.yes !== undefined ? this.yes : 1;
            const no = this.no !== undefined ? this.no : 0;
            const url = this.url || '';
            const labels = typeof this.labels === 'object' ? (this.labels.length > 0 ? this.labels : { on: 'ON', off: 'OFF' }) : false;
            
            let confirmMsg = '';
            let disable = false;
            
            // 处理确认消息
            if (this.confirm !== undefined) {
                confirmMsg = typeof this.confirm === 'function' ? 
                    this.confirm.call(this, value, row, index) : this.confirm;
            }
            
            // 处理禁用状态
            if (this.disable !== undefined) {
                disable = typeof this.disable === 'function' ? 
                    this.disable.call(this, value, row, index) : this.disable;
            }
            const isActive = value == yes;
            
            return `
                <div class="form-switch px-0">
                    <input class="form-check-input toggle-switch mx-0 float-none" type="checkbox" role="switch"
                        ${isActive ? 'checked' : ''} 
                        ${disable ? 'disabled' : ''}
                        data-id="${row[pk]}"
                        data-field="${this.field}"
                        data-on-value="${yes}"
                        data-off-value="${no}"
                        ${url ? `data-url="${url}"` : ''}
                        ${confirmMsg ? `data-confirm="${confirmMsg}"` : ''}
                        data-params="${pk}=${row[pk]}&${this.field}=${isActive ? no : yes}"
                        style="${this.size === 'lg' ? 'transform: scale(1.3);' : ''}">
                    ${labels ? `<label class="form-check-label">
                        ${isActive ? labels.on : labels.off}
                    </label>` : ''}
                </div>
            `;
        },

        // 进度条格式化器
        progress: function(instance, value, row, index) {
            value = parseInt(value) || 0;
            value = Math.max(0, Math.min(100, value)); // 确保在0-100之间
            
            const colorClass = this.color || 'primary';
            const showLabel = this.showLabel !== false; // 默认显示标签
            const striped = this.striped ? 'progress-bar-striped' : '';
            const animated = this.animated ? 'progress-bar-animated' : '';
            const height = this.height || '20px';
            const labelClass = this.labelClass || 'text-white';
            
            const progressHtml = `
                <div class="progress" style="height: ${height}">
                    <div class="progress-bar bg-${colorClass} ${striped} ${animated}" 
                        role="progressbar" style="width: ${value}%" 
                        aria-valuenow="${value}" aria-valuemin="0" aria-valuemax="100">
                        ${showLabel ? `<span class="${labelClass}">${value}%</span>` : ''}
                    </div>
                </div>
            `;
            
            return progressHtml;
        },

        // 星级评分格式化器
        rating: function(instance, value, row, index) {
            value = parseFloat(value) || 0;
            const maxStars = this.maxStars || 5;
            const size = this.size || 'sm'; // sm, md, lg
            const color = this.color || 'warning';
            const showValue = this.showValue !== false;
            const readonly = this.readonly !== false;
            
            let starsHtml = '';
            const fullStars = Math.floor(value);
            const hasHalfStar = value % 1 >= 0.5;
            
            // 生成星星
            for (let i = 1; i <= maxStars; i++) {
                if (i <= fullStars) {
                    starsHtml += `<i class="bi bi-star-fill text-${color}"></i>`;
                } else if (i === fullStars + 1 && hasHalfStar) {
                    starsHtml += `<i class="bi bi-star-half text-${color}"></i>`;
                } else {
                    starsHtml += `<i class="bi bi-star text-${color}"></i>`;
                }
            }
            
            // 添加评分值
            if (showValue) {
                starsHtml += ` <span class="text-muted ms-1">${value.toFixed(1)}</span>`;
            }
            
            const wrapperClass = `rating-stars rating-${size} ${readonly ? '' : 'rating-editable'}`;
            
            return `<div class="${wrapperClass}" data-value="${value}" data-max="${maxStars}">${starsHtml}</div>`;
        },

        // 徽章格式化器
        badge: function(instance, value, row, index) {
            value = value || '';
            const color = this.color || 'primary';
            const pill = this.pill ? 'badge-pill' : '';
            const size = this.size || ''; // sm, lg
            const sizeClass = size ? `badge-${size}` : '';
            
            return `<span class="badge ${sizeClass} bg-${color} ${pill}">${value}</span>`;
        },

        // 按钮组格式化器
        buttonGroup: function(instance, value, row, index) {
            // 禁用点击选择行
            this.clickToSelect = false;

            const buttons = this.buttons || [];
            const size = this.size || 'sm'; // sm, md, lg
            const vertical = this.vertical ? 'btn-group-vertical' : '';
            
            let buttonsHtml = '';
            buttons.forEach(button => {
                const icon = button.icon ? `<i class="${button.icon}"></i> ` : '';
                const disabled = button.disabled ? 'disabled' : '';
                const outline = button.outline ? 'btn-outline-' + (button.color || 'primary') : 'btn-' + (button.color || 'primary');
                
                buttonsHtml += `
                    <button type="button" class="btn ${outline} btn-${size} ${disabled}"
                        data-action="${button.action || ''}"
                        data-url="${button.url || ''}"
                        onclick="${button.onclick || ''}">
                        ${icon}${button.text || ''}
                    </button>
                `;
            });
            
            return `<div class="btn-group ${vertical}" role="group">${buttonsHtml}</div>`;
        },

        // 链接格式化器
        link: function(instance, value, row, index) {
            // 禁用点击选择行
            this.clickToSelect = false;

            const url = this.url || (typeof value === 'string' ? value : (value?.url || value?.href || ''));
            const icon = this.icon ? `<i class="${this.icon}"></i> ` : '';
            const color = (this.color ? `text-${this.color}` : '') + (url ? '' : ' link-secondary');
            const underline = this.underline === false ? 'text-decoration-none' : '';
            const title = this.alt || (typeof value === 'string' ? value : (value?.title || ''));
            const text = this.text || (value?.text ? value.text : (typeof value === 'string' ? value.split('/').pop() : ''));
            
            return `<a href="${url || 'javascript:void(0);'}" ${url ? '' : 'onclick="return false;"'} class="${color} ${underline}" data-bs-toggle="tooltip" title="${title}" rel="noopener" target="_blank">${icon}${text}</a>`;
        },

        // 二维码格式化器
        qrcode: function(instance, value, row, index) {
            const size = this.size || 80;
            const alt = this.alt || 'QR Code';
            const className = this.className || 'qrcode-img';
            
            // 使用第三方库或API生成二维码，这里使用简单的占位符
            // 实际使用时可以替换为真实的二维码生成逻辑
            const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(value)}`;
            
            return `<img src="${qrCodeUrl}" alt="${alt}" class="${className}" width="${size}" height="${size}" data-value="${value}">`;
        },

        // 头像格式化器
        avatar: function(instance, value, row, index) {
            const size = this.size || 40;
            const alt = this.alt || 'Avatar';
            const shape = this.shape || 'circle'; // circle, square, rounded
            const text = this.text || '';
            const bgColor = this.bgColor || 'primary';
            const textColor = this.textColor || 'white';
            
            let avatarHtml = '';
            
            if (value) {
                // 有图片URL，显示图片头像
                avatarHtml = `<img src="${value}" alt="${alt}" class="avatar avatar-${shape}" width="${size}" height="${size}">`;
            } else if (text) {
                // 没有图片但有文本，显示文字头像
                const initials = text.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
                avatarHtml = `
                    <div class="avatar avatar-${shape} bg-${bgColor} text-${textColor}" 
                        style="width: ${size}px; height: ${size}px; line-height: ${size}px;">
                        ${initials}
                    </div>
                `;
            } else {
                // 默认头像
                avatarHtml = `<div class="avatar avatar-${shape} bg-secondary" style="width: ${size}px; height: ${size}px;"></div>`;
            }
            
            return avatarHtml;
        },

        // 金额格式化器
        currency: function(instance, value, row, index) {
            value = parseFloat(value) || 0;
            const currency = this.currency || '￥';
            const decimals = this.decimals !== undefined ? this.decimals : 2;
            const color = value < 0 ? 'text-danger' : (value > 0 ? 'text-success' : '');
            
            const formattedValue = currency + Math.abs(value).toFixed(decimals).replace(/\d(?=(\d{3})+\.)/g, '$&,');
            
            return `<span class="${color}">${formattedValue}</span>`;
        },

        // 数字缩写格式化器（K、M、B）
        numberAbbr: function(instance, value, row, index) {
            value = parseFloat(value) || 0;
            const decimals = this.decimals !== undefined ? this.decimals : 1;
            
            const absValue = Math.abs(value);
            let formattedValue;
            
            if (absValue >= 1000000000) {
                formattedValue = (value / 1000000000).toFixed(decimals) + 'B';
            } else if (absValue >= 1000000) {
                formattedValue = (value / 1000000).toFixed(decimals) + 'M';
            } else if (absValue >= 1000) {
                formattedValue = (value / 1000).toFixed(decimals) + 'K';
            } else {
                formattedValue = value.toString();
            }
            
            const color = value < 0 ? 'text-danger' : (value > 0 ? 'text-success' : '');
            
            return `<span class="${color}">${formattedValue}</span>`;
        },

        // 标签组格式化器
        tags: function(instance, value, row, index) {
            value = value || '';
            const tags = Array.isArray(value) ? value : (typeof value === 'string' ? value.split(',') : []);
            const colorArr = Array.isArray(this.color) ? this.color : (typeof this.color === 'string' ? this.color.split(',') : ['secondary']);
            const maxDisplay = this.maxDisplay || 0; // 0表示显示全部
            const removable = this.removable || false;
            

            const keys = this.searchList ? Object.keys(this.searchList) : [];
            let tagsHtml = '';
            let displayCount = 0;
            
            tags.forEach((tag, i) => {
                if (maxDisplay > 0 && i >= maxDisplay) return;
                
                displayCount++;
                const removeBtn = removable ? 
                    `<button type="button" class="btn-close btn-close-white ms-1" aria-label="Remove"></button>` : '';
                
                const keyIndex = keys.indexOf(tag);
                var display = keyIndex > -1 ? Object.values(this.searchList)[keyIndex] : tag.trim();
                tagsHtml += `
                    <span class="badge bg-${colorArr[i % colorArr.length]} me-1 mb-1 d-inline-flex align-items-center">
                        ${display}${removeBtn}
                    </span>
                `;
            });
            
            // 如果有隐藏的标签，显示更多提示
            if (maxDisplay > 0 && tags.length > maxDisplay) {
                const remaining = tags.length - maxDisplay;
                tagsHtml += `<span class="badge bg-light text-dark">+${remaining}</span>`;
            }
            
            return `<div class="tags-container">${tagsHtml}</div>`;
        },

        // 时间差格式化器
        timeAgo: function(instance, value, row, index) {
            if (!value) return '';
            
            const timestamp = moment(value);
            const now = moment();
            const diff = now.diff(timestamp);
            const duration = moment.duration(diff);
            
            let timeAgo = '';
            
            if (duration.years() > 0) {
                timeAgo = `${duration.years()}年前`;
            } else if (duration.months() > 0) {
                timeAgo = `${duration.months()}月前`;
            } else if (duration.days() > 0) {
                timeAgo = `${duration.days()}天前`;
            } else if (duration.hours() > 0) {
                timeAgo = `${duration.hours()}小时前`;
            } else if (duration.minutes() > 0) {
                timeAgo = `${duration.minutes()}分钟前`;
            } else {
                timeAgo = '刚刚';
            }
            
            const title = timestamp.format('YYYY-MM-DD HH:mm:ss');
            
            return `<span title="${title}" data-bs-toggle="tooltip">${timeAgo}</span>`;
        },

        // 代码片段格式化器
        code: function(instance, value, row, index) {
            // 禁用点击选择行
            this.clickToSelect = false;
            if(typeof value === 'object' && value !== null){
                value = JSON.stringify(value, null, 2);
            }
            value = (value === '[object Object]' && row[this.field] != null) ? row[this.field] : (value != null ? value+'' : '');

            const language = this.language || '';
            const maxHeight = this.maxHeight || '120px';
            const copyable = this.copyable !== false;
            const expandable = this.expandable !== false;
            const maxLines = this.maxLines || 10;
            
            // 计算行数
            const lineCount = value.split('\n').length;
            const shouldTruncate = expandable && lineCount > maxLines;
            
            const truncatedCode = shouldTruncate ? 
                value.split('\n').slice(0, maxLines).join('\n') + '\n...' : 
                value;
            
            const copyButton = copyable ? 
                `<button class="btn btn-sm btn-outline-secondary code-copy-btn" title="复制代码">
                    <i class="bi bi-clipboard"></i>
                </button>` : '';
                
            const expandButton = shouldTruncate ? 
                `<button class="btn btn-sm btn-outline-primary code-expand-btn" title="展开代码">
                    <i class="bi bi-arrows-expand"></i> 展开
                </button>` : '';
            
            return `
                <div class="code-snippet position-relative ${shouldTruncate ? 'truncated' : ''}" 
                    data-full-code='${EnhancedTable.utils.escape(value)}'>
                    <pre class="language-${language}" style="max-height: ${shouldTruncate ? maxHeight : 'none'}; overflow: auto;">
                        <code>${EnhancedTable.utils.escape(truncatedCode)}</code>
                    </pre>
                    <div class="code-actions">
                        ${copyButton}
                        ${expandButton}
                    </div>
                </div>
            `;
        },
        
        // JSON数据格式化器
        json: function(instance, value, row, index) {
            this.clickToSelect = false;

            value = (value === '[object Object]' && row[this.field] != null) ? row[this.field] : (value != null ? value+'' : '');
            let jsonString = '';
            try {
                if (typeof value === 'string' && value !== null) {
                    const parsed = JSON.parse(value);
                    jsonString = JSON.stringify(parsed, null, 2);
                } else if (typeof value === 'object' && value !== null){
                    jsonString = JSON.stringify(value, null, 2);
                }
            } catch (e) {
                jsonString = value;
            }
            
            this.language = 'json';
            this.maxLines = this.maxLines || 8;
            this.expandable = this.expandable !== false;

            return EnhancedTable.FORMATTERS.code.call(this, jsonString, row, index);
        },

        // 折叠内容格式化器
        collapse: function(instance, value, row, index) {
            value = value || '';
            const maxLength = this.maxLength || 100;
            const showToggle = this.showToggle !== false;
            
            if (value.length <= maxLength) {
                return `<div class="collapse-content">${value}</div>`;
            }
            
            const shortText = value.substring(0, maxLength) + '...';
            const fullText = value;
            
            return `
                <div class="collapse-content">
                    <div class="short-text">${shortText}</div>
                    <div class="full-text collapse">${fullText}</div>
                    ${showToggle ? `
                        <a href="javascript:;" class="collapse-toggle" data-bs-toggle="collapse">
                            <span class="more">展开</span>
                            <span class="less collapse">收起</span>
                        </a>
                    ` : ''}
                </div>
            `;
        },

        // 状态点格式化器
        statusDot: function(instance, value, row, index) {
            const statusMap = this.statusMap || {
                'active': { color: 'success', text: '活跃' },
                'inactive': { color: 'secondary', text: '非活跃' },
                'pending': { color: 'warning', text: '待处理' },
                'error': { color: 'danger', text: '错误' },
                'default': { color: 'primary', text: '默认' }
            };
            
            const status = statusMap[value] || statusMap.default;
            const showText = this.showText !== false;
            
            return `
                <div class="d-flex align-items-center">
                    <span class="status-dot bg-${status.color} me-2"></span>
                    ${showText ? status.text : ''}
                </div>
            `;
        },

        // 文件大小格式化器
        fileSize: function(instance, value, row, index) {
            value = parseInt(value) || 0;
            
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let unitIndex = 0;
            let size = value;
            
            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }
            
            const decimals = this.decimals !== undefined ? this.decimals : (unitIndex === 0 ? 0 : 2);
            
            return `${size.toFixed(decimals)} ${units[unitIndex]}`;
        },

        // 百分比格式化器
        percentage: function(instance, value, row, index) {
            value = parseFloat(value) || 0;
            const decimals = this.decimals !== undefined ? this.decimals : 1;
            const color = this.color || (value < 0 ? 'danger' : (value > 0 ? 'success' : 'secondary'));
            const showSymbol = this.showSymbol !== false;
            
            const symbol = showSymbol ? '%' : '';
            
            return `<span class="text-${color}">${value.toFixed(decimals)}${symbol}</span>`;
        },

        // 颜色块格式化器
        color: function(instance, value, row, index) {
            const size = this.size || 20;
            const showValue = this.showValue !== false;
            const borderRadius = this.borderRadius || '4px';
            
            return `
                <div class="d-flex align-items-center">
                    <div class="color-block me-2" 
                        style="background-color: ${value}; width: ${size}px; height: ${size}px; border-radius: ${borderRadius}; border: 1px solid #dee2e6;">
                    </div>
                    ${showValue ? value : ''}
                </div>
            `;
        },

        // 倒计时格式化器
        countdown: function(instance, value, row, index) {
            if (!value) return '';
            
            const targetTime = moment(value);
            const now = moment();
            
            if (targetTime.isBefore(now)) {
                return '<span class="text-danger">已过期</span>';
            }
            
            const diff = targetTime.diff(now);
            const duration = moment.duration(diff);
            
            const days = Math.floor(duration.asDays());
            const hours = duration.hours();
            const minutes = duration.minutes();
            const seconds = duration.seconds();
            
            let countdownText = '';
            
            if (days > 0) {
                countdownText = `${days}天${hours}小时`;
            } else if (hours > 0) {
                countdownText = `${hours}小时${minutes}分钟`;
            } else if (minutes > 0) {
                countdownText = `${minutes}分钟${seconds}秒`;
            } else {
                countdownText = `${seconds}秒`;
            }
            
            return `<span class="countdown" data-target="${targetTime.format()}">${countdownText}</span>`;
        },

        // 地图位置格式化器
        location: function(instance, value, row, index) {
            if (!value) return '';
            
            const [lat, lng] = value.split(',').map(coord => parseFloat(coord.trim()));
            const zoom = this.zoom || 15;
            const size = this.size || '200x150';
            const markerColor = this.markerColor || 'red';
            
            if (isNaN(lat) || isNaN(lng)) {
                return value;
            }
            
            // 使用静态地图API显示位置
            const mapUrl = `https://maps.googleapis.com/maps/api/staticmap?center=${lat},${lng}&zoom=${zoom}&size=${size}&markers=color:${markerColor}%7C${lat},${lng}&key=YOUR_API_KEY`;
            
            return `
                <a href="https://maps.google.com/?q=${lat},${lng}" target="_blank" class="location-link">
                    <img src="${mapUrl}" alt="位置地图" class="location-map img-fluid" style="max-width: 100%;">
                </a>
            `;
        },

        // 音频播放器格式化器
        audio: function(instance, value, row, index) {
            if (!value) return '';
            
            const controls = this.controls !== false;
            const autoplay = this.autoplay || false;
            const loop = this.loop || false;
            const preload = this.preload || 'metadata';
            
            return `
                <audio ${controls ? 'controls' : ''} ${autoplay ? 'autoplay' : ''} ${loop ? 'loop' : ''} preload="${preload}" style="width: 100%;">
                    <source src="${value}" type="audio/mpeg">
                    您的浏览器不支持音频元素。
                </audio>
            `;
        },

        // 视频播放器格式化器
        video: function(instance, value, row, index) {
            if (!value) return '';
            
            const controls = this.controls !== false;
            const autoplay = this.autoplay || false;
            const loop = this.loop || false;
            const muted = this.muted || false;
            const width = this.width || '100%';
            const height = this.height || 'auto';
            const poster = this.poster || '';
            
            return `
                <video ${controls ? 'controls' : ''} ${autoplay ? 'autoplay' : ''} ${loop ? 'loop' : ''} ${muted ? 'muted' : ''} 
                    poster="${poster}" style="width: ${width}; height: ${height}; max-width: 100%;">
                    <source src="${value}" type="video/mp4">
                    您的浏览器不支持视频元素。
                </video>
            `;
        },

        // 输入框格式化器
        input: function(instance, value, row, index) {
            const eventAttr = this.event ? `data-event="${this.event}"` : '';
            const fieldAttr = this.field ? `data-field="${this.field}"` : '';
            const dataAttrs = this.data ? this.buildDataAttributes(this.data, row, index) : '';
            return `<input type="text" class="form-control form-control-sm input-value text-${{left:'start',right:'end',center:'center'}[this.align] || 'start'}" ${fieldAttr} ${eventAttr} ${dataAttrs} value="${value}">`;
        },

        // 操作列
        operate: function(instance, value, row, index) {
            const hidden = typeof this.hidden === 'function' ?
                this.hidden.call(this, value, row, index) : !!this.hidden;
            if (hidden) return;

            const visible = typeof this.visible === 'function' ?
                this.visible.call(this, value, row, index) : (this.visible !== false);
            if (!visible) return;

            const table = instance.table;
            const options = table ? table.bootstrapTable('getOptions') : {};

            let buttons = [...(this.buttons || [])];
            const names = buttons.map(btn => (typeof btn === 'string' ? btn : btn.name));

            const BUTTONS = EnhancedTable.BUTTONS;
            // 添加默认按钮
            if (options.extend.dragsort_url && names.includes('dragsort')) {
                buttons[names.indexOf('dragsort')] = typeof BUTTONS.dragsort === 'object' ? $.extend({}, BUTTONS.dragsort, buttons[names.indexOf('dragsort')]) : BUTTONS.dragsort;
            }
            if (options.extend.add_url && names.includes('add')) {
                buttons[names.indexOf('add')] = typeof BUTTONS.add === 'object' ? $.extend({}, BUTTONS.add, buttons[names.indexOf('add')]) : BUTTONS.add;
            }
            if (options.extend.preview_url && names.includes('preview')) {
                buttons[names.indexOf('preview')] = typeof BUTTONS.preview === 'object' ? $.extend({}, BUTTONS.preview, buttons[names.indexOf('preview')]) : BUTTONS.preview;
            }
            if (options.extend.edit_url && names.includes('edit')) {
                buttons[names.indexOf('edit')] = typeof BUTTONS.edit === 'object' ? $.extend({}, BUTTONS.edit, buttons[names.indexOf('edit')]) : BUTTONS.edit;
            }
            if (options.extend.del_url && names.includes('del')) {
                buttons[names.indexOf('del')] = typeof BUTTONS.del === 'object' ? $.extend({}, BUTTONS.del, buttons[names.indexOf('del')]) : BUTTONS.del;
            }
            buttons = buttons.filter(item => item !== null && typeof item === 'object' && !Array.isArray(item));

            return EnhancedTable.utils.renderButtons(this, instance, buttons, value, row, index, 'operate');
        }
    }

    // 工具方法
    EnhancedTable.utils = {
        // 转义HTML
        escape: function(text) {
            return $('<div>').text(text).html();
        },

        // 提取 iframe 标题（从完整标题中取最后一段）
        iframeTitle: function(iframeTitle) {
            return EnhancedTable.utils.iframeTitle(iframeTitle);
        },

        // CDN URL处理
        cdnurl: function(url, full = false) {
            // 实现CDN URL转换逻辑
            return url;
        },

        // 渲染按钮
        renderButtons: function(column, instance, buttons, value, row, index, type) {
            const table = instance.table;
            const options = table ? table.bootstrapTable('getOptions') : {};
            const pk = options.pk || 'id';
            const html = [];

            column.clickToSelect = false;

            $.each(buttons, (i, button) => {
                if (type === 'operate') {
                    if (button.name === 'dragsort' && (row.sort == null || !options.extend.dragsort_url)){ 
                        return;
                    }
                    if (['add', 'preview', 'edit', 'del', 'multi', 'dragsort'].includes(button.name)){
                        if (!options.extend[`${button.name}_url`]) {
                            return;
                        }
                    }
                }

                const hidden = typeof button.hidden === 'function' ?
                    button.hidden.call(this,value, row, button) : !!button.hidden;
                if (hidden) return;

                const visible = typeof button.visible === 'function' ?
                    button.visible.call(this,value, row, button) : (button.visible !== false);
                if (!visible) return;


                let url = button.url || '';
                url = typeof url === 'function' ? url.call(this,value, row, button) : 
                    (url ? EnhancedTable.utils.fixurl(this.replaceURL(url, row, table)) : 'javascript:;');

                const classname = button.classname || 'btn-primary';
                
                const icon = button.icon || '';
                const text = typeof button.text === 'function' ? 
                    button.text.call(this,value, row, button) : button.text || '';
                
                const title = typeof button.title === 'function' ? 
                    button.title.call(this,value, row, button) : button.title || text;
                
                const refresh = button.refresh ? `data-refresh="${button.refresh}"` : '';
                
                const confirm = typeof button.confirm === 'function' ? 
                    button.confirm.call(this,value, row, button) : button.confirm;
                const confirmAttr = confirm ? `data-confirm="${confirm}"` : '';
                
                const extend = typeof button.extend === 'function' ? 
                    button.extend.call(this,value, row, button) : button.extend || '';
                
                const disable = typeof button.disable === 'function' ? 
                    button.disable.call(this,value, row, button) : button.disable;
                const disabledClass = disable ? ' disabled' : '';
            
                const eventAttr = button.event ? `data-event="${button.event}"` : '';

                const dataAttrs = button.data ? this.buildDataAttributes(button.data, row, index) : '';

                if (button.dropdown) {
                    var dropdownItems = [];
                    if (Array.isArray(button.dropdown)) {
                        dropdownItems = button.dropdown;
                    } else if (typeof button.dropdown === 'string') {
                        const fieldName = button.dropdown;
                        if (row[fieldName] && Array.isArray(row[fieldName])) {
                            dropdownItems = row[fieldName];
                        }
                    }

                    if(dropdownItems.length > 0) {
                        var dropdownHtml = `<div class="btn-group">
                            <button type="button" class="btn btn-sm ${classname} ${disabledClass}"
                                ${eventAttr} ${dataAttrs}
                                title="${title}"
                                data-id="${row[pk]}"
                                ${url ? `data-url="${url}"` : ''}
                                data-table-id="${table ? table.attr('id') : ''}" 
                                data-field-index="${column.fieldIndex}" 
                                data-button-name="${button.name || 'custom'}"
                                data-row-index="${index}" 
                                data-button-index="${i}">
                                <i class="${icon}"></i>${text ? ` ${text}` : ''}
                            </button>
                            <button type="button" class="btn btn-sm ${classname} ${disabledClass} dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="visually-hidden">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu">`;
                        $.each(dropdownItems, (i2,btn) => {
                            dropdownHtml += `<li><a href="javascript:;" class="dropdown-item"
                                ${eventAttr}
                                ${btn.data ? this.buildDataAttributes(btn.data, row, index) : ''}
                                title="${btn.title || ''}"
                                data-id="${row[pk]}"
                                ${url ? `data-url="${url}"` : ''}
                                data-table-id="${table ? table.attr('id') : ''}" 
                                data-field-index="${column.fieldIndex}" 
                                data-button-name="${btn.name || 'custom'}"
                                data-row-index="${index}" 
                                data-button-index="${i2}">
                                ${btn.icon ? `<i class="${btn.icon || ''}"></i>` : ''} ${btn?.text || (typeof btn === 'string' ? btn : '')}
                            </a></li>`;
                        });
                        dropdownHtml += `</ul></div>`;

                        html.push(dropdownHtml);
                        return;
                    }
                }
                
                const link = `<a href="javascript:;" class="btn btn-sm ${classname} ${disabledClass}" 
                    ${confirmAttr} ${refresh} ${extend} ${eventAttr} ${dataAttrs}
                    title="${title}"
                    data-url="${url}"
                    data-table-id="${table ? table.attr('id') : ''}" 
                    data-field-index="${column.fieldIndex}" 
                    data-button-name="${button.name || 'custom'}"
                    data-row-index="${index}" 
                    data-button-index="${i}">
                    <i class="${icon}"></i>${text ? ` ${text}` : ''}
                </a>`;

                html.push(link);
            });

            return html.join(' ');
        },

        // 构建data-*属性
        buildDataAttributes: function(data, row, index) {
            let attrs = '';
            
            if (typeof data === 'function') {
                data = data(row, index);
            }
            
            if (typeof data === 'object') {
                Object.keys(data).forEach(key => {
                    let value = data[key];
                    if (typeof value === 'function') {
                        value = value(row, index);
                    }
                    attrs += ` data-${key}="${EnhancedTable.utils.escape(value)}"`;
                });
            }
            
            return attrs;
        },

        // 替换URL中的变量
        replaceURL: function(url, row, table) {
            const options = table ? table.bootstrapTable('getOptions') : null;
            const id = options ? row[options.pk] : 0;
            row.id = id || row.id || 0;
            
            url = url || '';
            
            // 自动添加id参数
            if (!url.match(/([?&]id=|\/id\/|\{id})/i)) {
                url += (url.match(/(\?|&)+/) ? '&id=' : '/id/') + '{id}';
            }
            
            return url.replace(/\{(.*?)\}/gi, (match) => {
                const key = match.substring(1, match.length - 1);
                const value = key.split('.').reduce((obj, k) => {
                    return obj === null || obj === undefined ? '' : obj[k];
                }, row);
                
                return EnhancedTable.utils.escape(value);
            });
        },

        // 修复URL
        fixurl: function(url) {
            // URL修复逻辑
            return url;
        }
    }

    // 全局暴露
    window.EnhancedTable = EnhancedTable;

})();