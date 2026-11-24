/**
 * Bootstrap Table - AdvancedMultipleSearch Advanced Search Plugin
 * 与 TP6 AdvancedMultipleSearch 后端查询参数格式完全匹配
 * 
 * 
 * @version 2.0.0
 */

(function ($) {
    'use strict';

    const Utils = $.fn.bootstrapTable.utils;
    const sprintf = Utils.sprintf;

    // 操作符配置
    const OPERATORS = {
        eq: { label: '等于', symbol: '=', inputType: 'single', valueType: 'auto' },
        ne: { label: '不等于', symbol: '!=', inputType: 'single', valueType: 'auto' },
        neq: { label: '不等于', symbol: '<>', inputType: 'single', valueType: 'auto' },
        gt: { label: '大于', symbol: '>', inputType: 'single', valueType: 'number' },
        lt: { label: '小于', symbol: '<', inputType: 'single', valueType: 'number' },
        gte: { label: '大于等于', symbol: '>=', inputType: 'single', valueType: 'number' },
        lte: { label: '小于等于', symbol: '<=', inputType: 'single', valueType: 'number' },
        like: { label: '包含', symbol: 'like', inputType: 'single', valueType: 'text', placeholder: '支持%通配符' },
        notlike: { label: '不包含', symbol: 'not like', inputType: 'single', valueType: 'text', placeholder: '支持%通配符' },
        in: { label: '在列表中', symbol: 'in', inputType: 'multiple', valueType: 'array', placeholder: '逗号或换行分隔' },
        notin: { label: '不在列表中', symbol: 'not in', inputType: 'multiple', valueType: 'array', placeholder: '逗号或换行分隔' },
        between: { label: '在区间内', symbol: 'between', inputType: 'range', valueType: 'range', placeholder: '起始值, 结束值' },
        notbetween: { label: '不在区间内', symbol: 'not between', inputType: 'range', valueType: 'range', placeholder: '起始值, 结束值' },
        null: { label: '为空', symbol: 'null', inputType: 'none', valueType: 'none' },
        notnull: { label: '不为空', symbol: 'not null', inputType: 'none', valueType: 'none' },
        exists: { label: '存在', symbol: 'exists', inputType: 'single', valueType: 'text' },
        notexists: { label: '不存在', symbol: 'not exists', inputType: 'single', valueType: 'text' }
    };

    // 默认配置
    $.extend($.fn.bootstrapTable.defaults, {
        AdvancedMultipleSearch: false,
        AdvancedMultipleSearchOptions: {
            showLogicGroups: true,
            maxConditions: 20,
            maxOrders: 5,
            btnClass: 'btn btn-outline-secondary',
            btnIcon: 'bi bi-funnel',
            btnText: '高级查询',
            modalTitle: '高级查询',
            modalSize: 'modal-lg'
        }
    });

    // 默认语言配置
    $.extend($.fn.bootstrapTable.locales['zh-CN'] || {}, {
        formatAdvancedMultipleSearch: function () {
            return '高级查询';
        }
    });
    $.extend($.fn.bootstrapTable.defaults, $.fn.bootstrapTable.locales['zh-CN']);

    // 扩展 BootstrapTable 类
    const BootstrapTable = $.fn.bootstrapTable.Constructor;
    const _init = BootstrapTable.prototype.init;
    const _initToolbar = BootstrapTable.prototype.initToolbar;
    const _initBody = BootstrapTable.prototype.initBody;

    BootstrapTable.prototype.init = function () {
        // 初始化插件状态
        this._rqbParams = {};
        this._rqbOriginalQueryParams = null;

        // 调用原始 init
        _init.apply(this, Array.prototype.slice.apply(arguments));

        // 如果启用了插件，初始化
        if (this.options.AdvancedMultipleSearch) {
            this.initAdvancedMultipleSearch();
        }
    };

    BootstrapTable.prototype.initToolbar = function () {
        _initToolbar.apply(this, Array.prototype.slice.apply(arguments));

        if (this.options.AdvancedMultipleSearch) {
            this.initAdvancedMultipleSearchToolbar();
        }
    };

    // 初始化插件核心
    BootstrapTable.prototype.initAdvancedMultipleSearch = function () {
        const opts = this.options.AdvancedMultipleSearchOptions || {};
        this._rqbOptions = opts;

        // 缓存原始 queryParams（只缓存一次）
        if (!this._rqbOriginalQueryParams) {
            const qp = this.options.queryParams;
            if (typeof qp === 'function') {
                this._rqbOriginalQueryParams = qp.bind(this);
            } else {
                this._rqbOriginalQueryParams = function (p) {
                    return {
                        limit: p.limit,
                        offset: p.offset,
                        page: (p.offset / p.limit) + 1,
                        order: p.order,
                        sort: p.sort,
                        search: p.search
                    };
                };
            }
        }

        // 重写 queryParams 以合并高级查询参数
        const self = this;
        this.options.queryParams = function (p) {
            const query = self._rqbOriginalQueryParams(p);
            return $.extend({}, query, self._rqbParams);
        };
    };

    // 初始化工具栏按钮
    BootstrapTable.prototype.initAdvancedSearchToolbar = function () {
        // const opts = this._rqbOptions || {};
        // const btnClass = opts.btnClass || 'btn btn-outline-secondary';
        // const btnIcon = opts.btnIcon || 'bi bi-funnel';
        // const btnText = opts.btnText || '高级查询';

        // const html = [];
        // html.push('<div class="columns columns-right btn-group float-end request-query-builder-toolbar">');
        // html.push(sprintf('<button class="%s" type="button" name="AdvancedMultipleSearch" title="%s">',
        //     btnClass, btnText));
        // html.push(sprintf('<i class="%s"></i> ', btnIcon));
        // html.push(btnText);
        // html.push('<span class="rqb-badge d-none">0</span>');
        // html.push('</button>');
        // html.push(sprintf('<button class="%s d-none" type="button" name="clearAdvancedMultipleSearch" title="清除查询条件">',
        //     btnClass));
        // html.push('<i class="bi bi-x-circle"></i>');
        // html.push('</button>');
        // html.push('</div>');

        // // 插入到工具栏右侧
        // if (this.$toolbar.find('.columns.columns-right').length) {
        //     this.$toolbar.find('.columns.columns-right').first().before($(html.join('')));
        // } else {
        //     this.$toolbar.append($(html.join('')));
        // }

        // 绑定事件
        const self = this;
        this.$toolbar.find('[name="AdvancedMultipleSearch"]').off('click').on('click', function () {
            self.showAdvancedMultipleSearchModal();
        });

        this.$toolbar.find('[name="clearAdvancedMultipleSearch"]').off('click').on('click', function () {
            self.clearAdvancedMultipleSearch();
        });
    };

    // 显示模态框
    BootstrapTable.prototype.showAdvancedMultipleSearchModal = function () {
        if (!this.$rqbModal) {
            this.initAdvancedMultipleSearchModal();
        }

        // 刷新字段选项
        this.refreshAdvancedMultipleSearchFields();
        // BS5 原生 API 替代 jQuery .modal('show')
        this._rqbModalInstance = this._rqbModalInstance || new bootstrap.Modal(this.$rqbModal[0]);
        this._rqbModalInstance.show();
    };

    // 初始化模态框
    BootstrapTable.prototype.initAdvancedMultipleSearchModal = function () {
        const opts = this._rqbOptions || {};
        const modalSize = opts.modalSize || 'modal-lg';
        const modalTitle = opts.modalTitle || '高级查询';

        const html = `
            <div class="modal fade rqb-modal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog ${modalSize}" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="${opts.btnIcon || 'bi bi-funnel'}"></i> ${modalTitle}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <!-- 普通条件区域 -->
                            <div class="rqb-section card bg-light mb-3">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">查询条件</h5>
                                    <button type="button" class="btn btn-sm btn-primary rqb-add-condition">
                                        <i class="bi bi-plus"></i> 添加条件
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="rqb-conditions-list"></div>
                                </div>
                            </div>

                            <!-- 逻辑分组区域 -->
                            <div class="rqb-section card bg-light mb-3 rqb-logic-groups-section" style="${(opts.showLogicGroups !== false) ? '' : 'display:none'}">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">逻辑分组 (AND/OR)</h5>
                                    <button type="button" class="btn btn-sm btn-info rqb-add-group">
                                        <i class="bi bi-plus"></i> 添加分组
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="rqb-groups-list"></div>
                                </div>
                            </div>

                            <!-- 排序区域 -->
                            <div class="rqb-section card bg-light mb-3">
                                <div class="card-header">
                                    <h5 class="mb-0">排序设置</h5>
                                </div>
                                <div class="card-body">
                                    <div class="rqb-order-settings">
                                        <div class="row">
                                            <div class="col-md-5">
                                                <select class="form-control rqb-order-field">
                                                    <option value="">选择排序字段</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <select class="form-control rqb-order-direction">
                                                    <option value="asc">升序 (ASC)</option>
                                                    <option value="desc">降序 (DESC)</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <button type="button" class="btn btn-sm btn-success rqb-add-order">
                                                    <i class="bi bi-plus"></i> 添加排序
                                                </button>
                                            </div>
                                        </div>
                                        <div class="rqb-orders-list"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="button" class="btn btn-warning rqb-reset">重置</button>
                            <button type="button" class="btn btn-primary rqb-apply">应用查询</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('body').append(html);
        this.$rqbModal = $('body > .rqb-modal').last();

        // 绑定模态框内事件
        this.bindAdvancedMultipleSearchModalEvents();
    };

    // 刷新字段选项
    BootstrapTable.prototype.refreshAdvancedMultipleSearchFields = function () {
        const columns = this.options.columns[0] || [];
        const $fieldSelect = this.$rqbModal.find('.rqb-order-field');

        // 保存当前选中的值
        const currentVal = $fieldSelect.val();

        $fieldSelect.empty();
        $fieldSelect.append('<option value="">选择排序字段</option>');

        columns.forEach(col => {
            if (col.field && !col.checkbox && !col.radio) {
                $fieldSelect.append(`<option value="${col.field}">${col.title || col.field}</option>`);
            }
        });

        $fieldSelect.val(currentVal);
    };

    // 绑定模态框事件
    BootstrapTable.prototype.bindAdvancedMultipleSearchModalEvents = function () {
        const self = this;

        // 添加条件
        this.$rqbModal.on('click', '.rqb-add-condition', function () {
            self.addAdvancedMultipleSearchCondition();
        });

        // 删除条件
        this.$rqbModal.on('click', '.rqb-remove-condition', function () {
            $(this).closest('.rqb-condition-item').remove();
        });

        // 操作符改变时更新输入框
        this.$rqbModal.on('change', '.rqb-operator', function () {
            self.updateAdvancedMultipleSearchInputs($(this).closest('.rqb-condition-item'));
        });

        // 添加逻辑分组
        this.$rqbModal.on('click', '.rqb-add-group', function () {
            self.addAdvancedMultipleSearchGroup();
        });

        // 删除逻辑分组
        this.$rqbModal.on('click', '.rqb-remove-group', function () {
            $(this).closest('.rqb-group-item').remove();
        });

        // 添加分组内条件
        this.$rqbModal.on('click', '.rqb-add-group-condition', function () {
            const $group = $(this).closest('.rqb-group-item');
            self.addAdvancedMultipleSearchGroupCondition($group);
        });

        // 添加排序
        this.$rqbModal.on('click', '.rqb-add-order', function () {
            self.addAdvancedMultipleSearchOrder();
        });

        // 删除排序
        this.$rqbModal.on('click', '.rqb-remove-order', function () {
            $(this).closest('.rqb-order-item').remove();
        });

        // 应用查询
        this.$rqbModal.on('click', '.rqb-apply', function () {
            self.applyAdvancedMultipleSearch();
        });

        // 重置
        this.$rqbModal.on('click', '.rqb-reset', function () {
            self.resetAdvancedMultipleSearchModal();
        });
    };

    // 获取字段选项 HTML
    BootstrapTable.prototype.getAdvancedMultipleSearchFieldOptions = function () {
        const columns = this.options.columns[0] || [];
        let html = '<option value="">选择字段</option>';
        columns.forEach(col => {
            if (col.field && !col.checkbox && !col.radio) {
                html += `<option value="${col.field}">${col.title || col.field}</option>`;
            }
        });
        return html;
    };

    // 获取操作符选项 HTML
    BootstrapTable.prototype.getAdvancedMultipleSearchOperatorOptions = function () {
        let html = '';
        Object.keys(OPERATORS).forEach(key => {
            html += `<option value="${key}">${OPERATORS[key].label} (${key})</option>`;
        });
        return html;
    };

    // 添加单个查询条件
    BootstrapTable.prototype.addAdvancedMultipleSearchCondition = function (data) {
        const fieldOptions = this.getAdvancedMultipleSearchFieldOptions();
        const operatorOptions = this.getAdvancedMultipleSearchOperatorOptions();

        const html = `
            <div class="rqb-condition-item row">
                <div class="col-md-3">
                    <select class="form-control rqb-field">${fieldOptions}</select>
                </div>
                <div class="col-md-3">
                    <select class="form-control rqb-operator">${operatorOptions}</select>
                </div>
                <div class="col-md-5 rqb-value-wrapper">
                    <input type="text" class="form-control rqb-value" placeholder="输入值">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger btn-sm rqb-remove-condition">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        `;

        const $item = $(html);
        this.$rqbModal.find('.rqb-conditions-list').append($item);

        if (data) {
            $item.find('.rqb-field').val(data.field);
            $item.find('.rqb-operator').val(data.operator);
            this.updateAdvancedMultipleSearchInputs($item);
            if (data.value !== undefined) {
                const op = OPERATORS[data.operator];
                if (op && op.inputType === 'range') {
                    const values = Array.isArray(data.value) ? data.value : String(data.value).split(',');
                    $item.find('.rqb-value-start').val(values[0] || '');
                    $item.find('.rqb-value-end').val(values[1] || '');
                } else {
                    $item.find('.rqb-value').val(Array.isArray(data.value) ? data.value.join(',') : data.value);
                }
            }
        }

        return $item;
    };

    // 根据操作符更新输入框类型
    BootstrapTable.prototype.updateAdvancedMultipleSearchInputs = function ($item) {
        const operator = $item.find('.rqb-operator').val();
        const $wrapper = $item.find('.rqb-value-wrapper');

        if (!operator || !OPERATORS[operator]) {
            $wrapper.html('<input type="text" class="form-control rqb-value" placeholder="输入值" disabled>');
            return;
        }

        const op = OPERATORS[operator];

        switch (op.inputType) {
            case 'none':
                $wrapper.html('<span class="form-control-static text-muted">无需输入值</span><input type="hidden" class="rqb-value" value="1">');
                break;

            case 'range':
                $wrapper.html(`
                    <div class="row">
                        <div class="col-md-6">
                            <input type="text" class="form-control rqb-value-start" placeholder="起始值">
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control rqb-value-end" placeholder="结束值">
                        </div>
                    </div>
                `);
                break;

            case 'multiple':
                $wrapper.html(`<textarea class="form-control rqb-value" rows="2" placeholder="${op.placeholder || '多个值用逗号分隔'}"></textarea>`);
                break;

            default:
                $wrapper.html(`<input type="${op.valueType === 'number' ? 'number' : 'text'}" class="form-control rqb-value" placeholder="${op.placeholder || '输入值'}">`);
        }
    };

    // 添加逻辑分组
    BootstrapTable.prototype.addAdvancedMultipleSearchGroup = function (data) {
        const fieldOptions = this.getAdvancedMultipleSearchFieldOptions();
        const operatorOptions = this.getAdvancedMultipleSearchOperatorOptions();

        const html = `
            <div class="rqb-group-item card mb-3">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <select class="form-select form-select-sm rqb-group-logic">
                                <option value="AND">且 (AND)</option>
                                <option value="OR">或 (OR)</option>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <span class="text-muted">分组内条件关系</span>
                        </div>
                        <div class="col-md-2 text-end">
                            <button type="button" class="btn btn-danger btn-sm rqb-remove-group">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="rqb-group-conditions"></div>
                    <button type="button" class="btn btn-sm btn-info rqb-add-group-condition mt-2">
                        <i class="bi bi-plus"></i> 添加条件
                    </button>
                </div>
            </div>
        `;

        const $group = $(html);
        this.$rqbModal.find('.rqb-groups-list').append($group);

        if (data) {
            $group.find('.rqb-group-logic').val(data.logic || 'AND');
            if (data.conditions) {
                data.conditions.forEach(cond => {
                    this.addAdvancedMultipleSearchGroupCondition($group, cond);
                });
            }
        } else {
            this.addAdvancedMultipleSearchGroupCondition($group);
        }

        return $group;
    };

    // 添加分组内的条件
    BootstrapTable.prototype.addAdvancedMultipleSearchGroupCondition = function ($group, data) {
        const fieldOptions = this.getAdvancedMultipleSearchFieldOptions();
        const operatorOptions = this.getAdvancedMultipleSearchOperatorOptions();

        const html = `
            <div class="rqb-group-condition-item row">
                <div class="col-md-3">
                    <select class="form-control rqb-field">${fieldOptions}</select>
                </div>
                <div class="col-md-3">
                    <select class="form-control rqb-operator">${operatorOptions}</select>
                </div>
                <div class="col-md-5 rqb-value-wrapper">
                    <input type="text" class="form-control rqb-value" placeholder="输入值">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger btn-sm rqb-remove-condition">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        `;

        const $item = $(html);
        $group.find('.rqb-group-conditions').append($item);

        if (data) {
            $item.find('.rqb-field').val(data.field);
            $item.find('.rqb-operator').val(data.operator);
            this.updateAdvancedMultipleSearchInputs($item);
            if (data.value !== undefined) {
                const op = OPERATORS[data.operator];
                if (op && op.inputType === 'range') {
                    const values = Array.isArray(data.value) ? data.value : String(data.value).split(',');
                    $item.find('.rqb-value-start').val(values[0] || '');
                    $item.find('.rqb-value-end').val(values[1] || '');
                } else {
                    $item.find('.rqb-value').val(Array.isArray(data.value) ? data.value.join(',') : data.value);
                }
            }
        }

        return $item;
    };

    // 添加排序规则
    BootstrapTable.prototype.addAdvancedMultipleSearchOrder = function (data) {
        const field = data ? data.field : this.$rqbModal.find('.rqb-order-field').val();
        const direction = data ? data.direction : this.$rqbModal.find('.rqb-order-direction').val();

        if (!field) return;

        const columns = this.options.columns[0] || [];
        const col = columns.find(c => c.field === field);
        const fieldName = col ? (col.title || field) : field;

        const html = `
            <div class="rqb-order-item" data-field="${field}" data-direction="${direction}">
                <span class="badge bg-primary">${fieldName}</span>
                <span class="badge bg-${direction === 'desc' ? 'danger' : 'success'}">${direction === 'desc' ? '降序' : '升序'}</span>
                <button type="button" class="btn btn-xs btn-link rqb-remove-order">
                    <i class="bi bi-x text-danger"></i>
                </button>
            </div>
        `;

        this.$rqbModal.find('.rqb-orders-list').append(html);
    };

    // 收集查询参数
    BootstrapTable.prototype.collectAdvancedMultipleSearchParams = function () {
        const params = {};

        // 1. 收集普通条件
        this.$rqbModal.find('.rqb-conditions-list .rqb-condition-item').each(function () {
            const $item = $(this);
            const field = $item.find('.rqb-field').val();
            const operator = $item.find('.rqb-operator').val();

            if (!field || !operator) return;

            const op = OPERATORS[operator];
            let value;

            if (op.inputType === 'none') {
                value = '1';
            } else if (op.inputType === 'range') {
                const start = $item.find('.rqb-value-start').val();
                const end = $item.find('.rqb-value-end').val();
                if (start || end) {
                    value = [start, end].filter(v => v !== '');
                }
            } else {
                value = $item.find('.rqb-value').val();
            }

            if (value !== undefined && value !== '' && value !== null) {
                params[`${field}__${operator}`] = Array.isArray(value) ? value.join(',') : value;
            }
        });

        // 2. 收集逻辑分组条件 (_and, _or)
        // 后端期望扁平化格式: _and[0][field][op]=value
        // 修复 P0: 之前构建 JS 对象导致序列化为 [object Object]
        let groupIndex = 0;

        this.$rqbModal.find('.rqb-group-item').each(function () {
            const $group = $(this);
            const logic = $group.find('.rqb-group-logic').val();

            $group.find('.rqb-group-condition-item').each(function () {
                const $item = $(this);
                const field = $item.find('.rqb-field').val();
                const operator = $item.find('.rqb-operator').val();

                if (!field || !operator) return;

                const op = OPERATORS[operator];
                let value;

                if (op.inputType === 'none') {
                    value = '1';
                } else if (op.inputType === 'range') {
                    const start = $item.find('.rqb-value-start').val();
                    const end = $item.find('.rqb-value-end').val();
                    if (start || end) {
                        value = [start, end].filter(v => v !== '');
                    }
                } else {
                    value = $item.find('.rqb-value').val();
                }

                if (value !== undefined && value !== '' && value !== null) {
                    const rawValue = Array.isArray(value) ? value.join(',') : value;
                    const groupKey = logic === 'AND' ? '_and' : '_or';
                    params[`${groupKey}[${groupIndex}][${field}][${operator}]`] = rawValue;
                }
            });

            groupIndex++;
        });

        // 3. 收集排序参数
        const orders = [];
        this.$rqbModal.find('.rqb-orders-list .rqb-order-item').each(function () {
            const field = $(this).data('field');
            const direction = $(this).data('direction');
            orders.push(`${field}__${direction}`);
        });

        if (orders.length > 0) {
            params._order = orders.join(',');
        }

        return params;
    };

    // 应用查询
    BootstrapTable.prototype.applyAdvancedMultipleSearch = function () {
        const params = this.collectAdvancedMultipleSearchParams();

        // 更新条件计数
        const conditionCount = Object.keys(params).filter(k => !k.startsWith('_')).length +
            this.$rqbModal.find('.rqb-group-item').length;

        const $btn = this.$toolbar.find('[name="AdvancedMultipleSearch"]');
        const $clearBtn = this.$toolbar.find('[name="clearAdvancedMultipleSearch"]');

        if (conditionCount > 0) {
            $btn.find('.rqb-badge').text(conditionCount).removeClass('d-none');
            $clearBtn.removeClass('d-none');
        } else {
            $btn.find('.rqb-badge').addClass('d-none');
            $clearBtn.addClass('d-none');
        }

        // 保存查询参数并刷新表格
        this._rqbParams = params;
        // BS5 原生 API 替代 jQuery .modal('hide')
        if (this._rqbModalInstance) {
            this._rqbModalInstance.hide();
        }
        this.refresh();
    };

    // 清除查询
    BootstrapTable.prototype.clearAdvancedMultipleSearch = function () {
        this._rqbParams = {};
        this.resetAdvancedMultipleSearchModal();

        const $btn = this.$toolbar.find('[name="AdvancedMultipleSearch"]');
        const $clearBtn = this.$toolbar.find('[name="clearAdvancedMultipleSearch"]');
        $btn.find('.rqb-badge').addClass('d-none');
        $clearBtn.addClass('d-none');

        this.refresh();
    };

    // 重置模态框
    BootstrapTable.prototype.resetAdvancedMultipleSearchModal = function () {
        if (this.$rqbModal) {
            this.$rqbModal.find('.rqb-conditions-list').empty();
            this.$rqbModal.find('.rqb-groups-list').empty();
            this.$rqbModal.find('.rqb-orders-list').empty();
            this.$rqbModal.find('.rqb-order-field').val('');
            this.$rqbModal.find('.rqb-order-direction').val('asc');
        }
    };

    // 获取当前查询参数（供外部调用）
    BootstrapTable.prototype.getAdvancedMultipleSearchParams = function () {
        return this._rqbParams || {};
    };

    // 设置查询参数（供外部调用）
    BootstrapTable.prototype.setAdvancedMultipleSearchParams = function (params) {
        this.resetAdvancedMultipleSearchModal();

        // 解析普通条件 (field__operator=value)
        Object.keys(params).forEach(key => {
            if (key.startsWith('_')) return;

            const match = key.match(/^(.+)__(.+)$/);
            if (match) {
                this.addAdvancedMultipleSearchCondition({
                    field: match[1],
                    operator: match[2],
                    value: params[key]
                });
            } else {
                this.addAdvancedMultipleSearchCondition({
                    field: key,
                    operator: 'eq',
                    value: params[key]
                });
            }
        });

        // 解析逻辑分组 (_and / _or)
        // 修复 P2: 原来跳过所有 _ 前缀参数导致分组无法还原
        ['_and', '_or'].forEach(groupKey => {
            // 扁平化格式: _and[0][field][op]=value
            const groupRegex = new RegExp(`^${groupKey.replace('_', '_')}\\[(\\d+)\\]\\[([^\\]]+)\\]\\[([^\\]]+)\\]$`);
            const groupConditions = {};
            Object.keys(params).forEach(key => {
                const match = key.match(groupRegex);
                if (match) {
                    const idx = match[1];
                    const field = match[2];
                    const op = match[3];
                    if (!groupConditions[idx]) {
                        groupConditions[idx] = { logic: groupKey === '_and' ? 'AND' : 'OR', conditions: [] };
                    }
                    groupConditions[idx].conditions.push({ field, operator: op, value: params[key] });
                }
            });

            Object.keys(groupConditions).sort().forEach(idx => {
                const g = groupConditions[idx];
                this.addAdvancedMultipleSearchGroup(g);
            });
        });

        // 解析排序
        if (params._order) {
            const orders = params._order.split(',');
            orders.forEach(order => {
                const parts = order.split('__');
                if (parts.length === 2) {
                    this.addAdvancedMultipleSearchOrder({ field: parts[0], direction: parts[1] });
                }
            });
        }

        this._rqbParams = params;
    };

    // 注册公开方法
    $.extend($.fn.bootstrapTable.methods, [
        'showAdvancedMultipleSearchModal',
        'clearAdvancedMultipleSearch',
        'getAdvancedMultipleSearchParams',
        'setAdvancedMultipleSearchParams',
        'resetAdvancedMultipleSearchModal'
    ]);

    // CSS 样式
    const styles = `
        <style id="rqb-bootstrap-table-styles">
            .request-query-builder-toolbar {
                display: inline-block;
                margin-left: 5px;
            }
            .request-query-builder-toolbar .rqb-badge {
                background-color: #d9534f;
                color: white;
                border-radius: 10px;
                padding: 2px 6px;
                font-size: 11px;
                margin-left: 5px;
            }
            .rqb-modal .modal-body {
                max-height: 70vh;
                overflow-y: auto;
            }
            .rqb-section {
                margin-bottom: 20px;
                padding: 15px;
                background: #f9f9f9;
                border-radius: 4px;
            }
            .rqb-section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #e5e5e5;
            }
            .rqb-section-header h5 {
                margin: 0;
                font-weight: bold;
            }
            .rqb-condition-item,
            .rqb-group-condition-item {
                margin-bottom: 10px;
                padding: 10px;
                background: white;
                border-radius: 4px;
                border: 1px solid #ddd;
            }
            .rqb-group-item {
                margin-bottom: 15px;
            }
            .rqb-group-item .panel-heading {
                background-color: #f5f5f5;
            }
            .rqb-group-conditions {
                margin-bottom: 10px;
            }
            .rqb-orders-list {
                margin-top: 10px;
            }
            .rqb-order-item {
                display: inline-block;
                margin: 5px 5px 5px 0;
                padding: 5px 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .rqb-order-item .label {
                margin-right: 5px;
            }
            .rqb-value-wrapper .row {
                margin: 0;
            }
            .rqb-value-wrapper .col-md-6 {
                padding: 0 5px;
            }
            .rqb-value-wrapper .col-md-6:first-child {
                padding-left: 0;
            }
            .rqb-value-wrapper .col-md-6:last-child {
                padding-right: 0;
            }
        </style>
    `;

    // 添加样式到页面
    if (!$('head').find('#rqb-bootstrap-table-styles').length) {
        $('head').append(styles);
    }

})(jQuery);
