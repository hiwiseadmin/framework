/**
 * Wise.crud — 列表页搜索区（.ws-filter-bar）的参数层与装配
 *
 * 职责（见 docs/wiseadmin-后台页面统一方案.md §6.5）：
 *   1. filterParams(form)   把筛选表单序列化为查询参数（**只产生 AND 条件**）
 *   2. bindFilterBar(...)   绑定查询 / 重置 / 回车，并把参数并入表格 queryParams
 *
 * 查询时机（重要）：**只有点「查询」或回车才发请求**。
 * 选项选择、日期点选、文本输入都只改控件值，不触发列表查询 ——
 * 否则多条件筛选会被拆成多次请求（每选一项查一次），既浪费也会看到中间态结果。
 *   3. autoBind()           零侵入接线：页面已有 `#ws-filter` + `#table` 时自动接管
 *   4. page(...)            一次性装配：读 schema → 建表 + 绑定筛选条
 *
 * 为什么渲染层不需要字段映射表：后端 RequestQueryBuilder 直接按参数名解析
 * `field__op=value`（含 between / in / like），所以控件的 name 就是查询参数，
 * 这里只做「序列化 + 空值与单端区间收敛」。
 *
 * 约定：
 *   - 不生成 `_or` 分组（OR / 多条件组合交给表格高级搜索 AdvancedSearch）。
 *   - 显隐与状态记忆由内核工具栏的 `search` 按钮负责（enhanced-table.js），
 *     本模块不写 localStorage，避免两处争抢状态。
 *
 * @module crud-page
 * @version 1.0.0
 */

import { registry, logger, eventBus } from './wise-core.js';
import { http } from './wise-util.js';
// 组件复位统一入口：复位细节在各自组件内实现（reset），业务层只下发
import { resetAll } from './ws/index.js';

// ============================================================
// 工具
// ============================================================

function _resolveEl(target) {
    if (!target) return null;
    if (typeof target === 'string') return document.querySelector(target);
    if (target.jquery) return target[0] || null;
    return target;
}

/** 取控件值并做边界规范化（datetime-local → 'Y-m-d H:i:s'；end-of-day 补 23:59:59） */
function _valueOf(el) {
    if (!el) return '';
    let value = el.value == null ? '' : String(el.value).trim();
    if (value === '') return '';

    // datetime-local 提交的是 2026-01-01T10:30，后端按 'Y-m-d H:i:s' 比较
    const local = value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2})?$/);
    if (local) {
        value = local[1] + ' ' + local[2] + (local[3] || ':00');
    }

    // 区间结束端：只填到日期时补足到当天末（§6.8 风险 7）
    if (el.getAttribute && el.getAttribute('data-end-of-day') === '1'
        && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        value += ' 23:59:59';
    }

    return value;
}

function _isChecked(el) {
    if (!el) return false;
    const type = String(el.type || '').toLowerCase();
    if (type !== 'checkbox' && type !== 'radio') return true;
    return !!el.checked;
}

/** 是否单框区间（ws-date-picker 的 range 模式，一个 input 承载起止） */
function _isRangeInput(el) {
    if (!el || !el.getAttribute) return false;
    const flag = el.getAttribute('data-range');
    return flag === '1' || flag === 'true';
}

/**
 * 拆分单框区间的值：`起<sep>止`。
 *
 * 分隔符必须与渲染层一致（SearchRenderer::RANGE_SEPARATOR → data-range-separator），
 * 所以这里优先读属性，只在属性缺失时回落到组件默认值 ` - `。
 *
 * 结束端是纯日期且带 data-end-of-day 标记时补到当天末，
 * 否则与 datetime 列比较会漏掉当天数据（与双输入版本的行为对齐）。
 *
 * @param {string} value
 * @param {HTMLElement} el
 * @returns {string[]}
 */
function _splitRange(value, el) {
    if (!value) return [];

    const sep = (el && el.getAttribute('data-range-separator')) || ' - ';
    const parts = String(value)
        .split(sep)
        .map((s) => s.trim())
        .filter((s) => s !== '');

    if (parts.length >= 2 && el && el.getAttribute('data-end-of-day') === '1') {
        const last = parts.length - 1;
        if (/^\d{4}-\d{2}-\d{2}$/.test(parts[last])) {
            parts[last] += ' 23:59:59';
        }
    }

    return parts;
}

/**
 * 复位 ws-* 组件（值载体 + 显示态一起回到服务端渲染时的默认值）。
 *
 * 只改 `el.value` 不够：ws-select 提升后真实值在隐藏载体上、显示文本在组件内部；
 * ws-date-picker 会把 input 包进 `<ws-date-container>`。必须走组件 API。
 *
 * @param {HTMLElement} form
 */
function _resetWsControls(form) {
    const jq = window.jQuery;
    if (!jq || !form) return;

    // 首选统一入口：组件各自实现 reset(silent)，复位细节（清空/回填默认值、
    // 多值载体、搜索词、远程 label）留在组件内部，业务层不再逐组件写分支。
    try {
        resetAll(form, { silent: true });
    } catch (err) {
        logger.warn('crud', 'ws-* 复位失败，回退逐组件处理', err && err.message);
    }

    // 兜底：未实现 reset() 的旧版组件仍按各自公开 API 回填
    _resetWsControlsLegacy(form);
}

/**
 * 旧版组件兜底复位（组件没有 `reset()` 时才会执行到）。
 * @param {HTMLElement} form
 */
function _resetWsControlsLegacy(form) {
    const jq = window.jQuery;

    // 日期 / 时间：宿主就是 input，defaultValue 即渲染时的回填值
    Array.prototype.forEach.call(form.querySelectorAll('[data-role="wsdatepicker"]'), (el) => {
        const picker = jq(el).data('wsDatePicker');
        if (!picker || typeof picker.reset === 'function') return;

        const def = el.defaultValue == null ? '' : el.defaultValue;
        // setValue('') 是空操作（内部对空值直接 return），清空必须走 clear()
        if (def === '') {
            if (typeof picker.clear === 'function') {
                picker.clear();
            } else {
                el.value = '';
            }
            return;
        }
        if (typeof picker.setValue === 'function') {
            picker.setValue(def);
        }
    });

    // 下拉：原生 select 被隐藏，值已平移到隐藏载体
    Array.prototype.forEach.call(form.querySelectorAll('.ws-select-container'), (host) => {
        const inst = jq(host).data('wsSelect');
        if (!inst || typeof inst.reset === 'function' || typeof inst.setValue !== 'function') return;

        const select = host.querySelector('select');
        let picked = null;

        if (select) {
            // 先按 option 的 defaultSelected 还原，再取值
            Array.prototype.forEach.call(select.options, (opt) => {
                opt.selected = opt.defaultSelected;
            });
            picked = jq(select).val();
        }

        // 顺带清掉上次的搜索词，否则重置后下拉仍只显示被过滤的选项
        if (typeof inst.searchValue === 'string') {
            inst.searchValue = '';
        }

        if (inst.settings && inst.settings.multiple) {
            inst.setValue(Array.isArray(picked) ? picked : (picked ? [picked] : []));
        } else {
            inst.setValue(picked || '');
        }
    });
}

/** 取表格的 jQuery 对象：接受 jQuery / 选择器 / EnhancedTable 实例 */
function _tableJq(target) {
    const $ = window.jQuery;
    if (!target || !$) return null;
    if (target.jquery) return target;
    if (target.table && target.table.jquery) return target.table;   // EnhancedTable 实例
    if (typeof target === 'string') return $(target);
    return null;
}

/**
 * 确保筛选参数已并入表格 `queryParams`（幂等，可重复调用）。
 *
 * 为什么必须"每次刷新前再试一次"：`bindFilterBar` 会在 `ws:core:ready` 时被
 * `autoBind()` 自动接线，而**页面控制器建表还在其后** —— 此时
 * `bootstrapTable('getOptions')` 拿不到 options，只能跳过；之后没有任何补挂点，
 * 表现为「点查询列表刷新了，但请求里没有筛选条件」。
 *
 * 参数取 `form.__wsFilterParams` 快照而不是闭包里的 `getParams()`：
 * 表格被重建（`bootstrapTable({...})` 再来一次）会丢掉我们包过的函数，
 * 快照则不受影响，重新挂载时仍能取到上一次点查询时的条件。
 *
 * @param {Object} $table jQuery 表格对象
 * @param {HTMLElement} form 筛选表单
 * @returns {boolean} 是否已挂载（或已处于挂载状态）
 */
function _ensureFilterQueryParams($table, form) {
    if (!$table || !$table.length || !form) return false;

    const $ = window.jQuery;
    if (!$) return false;

    // 必须拿**实例上的真实 options**：`bootstrapTable('getOptions')` 返回的是
    // `Utils.extend(true, {}, options)` 的深拷贝，改它不会影响真实表格 ——
    // 之前正是踩了这个坑，表现为「查询参数一条都没进请求」。
    const instance = $( $table[0] ).data('bootstrap.table');
    const opts = instance && instance.options;

    if (!opts) return false;
    if (opts.__wsFilterBound) return true;

    const prev = opts.queryParams;
    opts.queryParams = function (params) {
        const base = typeof prev === 'function'
            ? prev.call(this, params)
            : Object.assign({}, params);
        return Object.assign({}, base || {}, form.__wsFilterParams || {});
    };
    opts.__wsFilterBound = true;
    return true;
}

function _refreshFirstPage($table) {
    if (!$table || !$table.length) return;
    try {
        $table.bootstrapTable('refresh', { pageNumber: 1 });
    } catch (err) {
        logger.warn('crud', '表格刷新失败（实例可能已销毁）', err && err.message);
    }
}

// ============================================================
// Wise.crud
// ============================================================

const crud = {
    /**
     * 把筛选表单序列化为查询参数。
     *
     * 规则（§6.3 / §6.5）：
     *   - 空值不提交（后端 buildWhere 也只跳过 '' / null）
     *   - 区间 `field__between[]=a&...=b`：两端 → between；单端 → gte / lte；两端同值 → eq
     *   - 多选 `field__in[]=1&...=2` → 数组
     *   - 只产生 AND 条件，不生成 `_or`
     *
     * @param {string|HTMLElement} [formSelector='#ws-filter']
     * @returns {Object<string, string|string[]>}
     */
    filterParams(formSelector = '#ws-filter') {
        const form = _resolveEl(formSelector);
        if (!form) return {};

        const out = {};
        const nodes = Array.prototype.slice.call(
            form.querySelectorAll('input[name], select[name], textarea[name]')
        ).filter((el) => !el.disabled);

        // 按 name 分组：区间要靠「同组内的位置」判断填的是起还是止
        const byName = new Map();
        nodes.forEach((el) => {
            const name = el.getAttribute('name');
            if (!byName.has(name)) byName.set(name, []);
            byName.get(name).push(el);
        });

        byName.forEach((els, name) => {
            // 数组形态：field__between[] / field__in[] / field__notin[]
            const arr = name.match(/^(.+?)__([a-z]+)\[\]$/i);
            if (arr) {
                const field = arr[1];
                const op = arr[2].toLowerCase();

                if (op === 'between') {
                    const picked = els
                        .map((el, idx) => ({ idx, value: _isChecked(el) ? _valueOf(el) : '' }))
                        .filter((item) => item.value !== '');
                    if (picked.length === 0) return;
                    if (picked.length >= 2) {
                        const from = picked[0].value;
                        const to = picked[picked.length - 1].value;
                        if (from === to) {
                            out[field + '__eq'] = from;
                        } else {
                            out[field + '__between'] = [from, to];
                        }
                        return;
                    }
                    // 单端：第 0 个是「起」，第 1 个是「止」
                    const one = picked[0];
                    out[field + (one.idx === 0 ? '__gte' : '__lte')] = one.value;
                    return;
                }

                const values = els.filter(_isChecked).map(_valueOf).filter((v) => v !== '');
                if (values.length) out[field + '__' + op] = values;
                return;
            }

            // 单框区间（ws-date-picker range）：一个 input 承载「起 至 止」。
            // 提交形态仍收敛成 field__between 双值 —— 后端 RequestQueryBuilder
            // 与 queryParams 序列化都无需感知"渲染层用了几个输入框"。
            if (_isRangeInput(els[0])) {
                const parts = _splitRange(_valueOf(els[0]), els[0]);
                const field = name.replace(/__[a-z]+$/i, '');
                if (parts.length >= 2) {
                    out[field + '__between'] = [parts[0], parts[parts.length - 1]];
                } else if (parts.length === 1) {
                    // 只填了一端（手输入）：按「起」处理，避免退化成全表查询
                    out[field + '__gte'] = parts[0];
                }
                return;
            }

            // 普通控件：name 已带操作符（field__op），直接用
            const el = els[0];
            if (!_isChecked(el)) return;
            const value = _valueOf(el);
            if (value === '') return;
            out[name] = value;
        });

        return out;
    },

    /**
     * 绑定筛选条行为。
     *
     * - 只在「查询」按钮 / 回车 / 表单提交时发请求（选项变更、输入都不发）
     * - 「重置」按钮：控件回到模板默认值后按默认条件查一次
     * - 自动把 filterParams 并入表格 queryParams（autoMerge=false 可关闭）
     *
     * 幂等：同一表单重复调用只会重新尝试并入 queryParams（表格可能晚于本方法创建），
     * 事件监听只绑定一次，返回同一个 api 对象。
     *
     * @param {Object} options
     * @param {string|HTMLElement} options.form     筛选表单（默认 '#ws-filter'）
     * @param {Object|string}      [options.table]  表格（增强实例 / jQuery / 选择器）
     * @param {boolean}            [options.autoMerge=true] 是否并入表格 queryParams
     * @returns {Object|null} 解绑器 { destroy, refresh, reset, params }
     */
    bindFilterBar(options = {}) {
        const form = _resolveEl(options.form || '#ws-filter');
        if (!form) {
            logger.warn('crud', '未找到筛选表单，bindFilterBar 跳过');
            return null;
        }

        const self = this;
        const $table = _tableJq(options.table);
        const getParams = () => self.filterParams(form);
        const mergeEnabled = options.autoMerge !== false;

        // 查询 = 「取当前控件值 → 存快照 → 确保快照已并入 queryParams → 刷新表格」。
        // 快照与挂载都放在 refresh 里，是因为 bindFilterBar 可能早于表格创建，
        // 一次性挂载会静默失败（详见 _ensureFilterQueryParams 注释）。
        // 分页/排序触发的内部刷新不在本函数里，它们复用上一次的快照 —— 条件得以保持。
        const refresh = () => {
            if (mergeEnabled) {
                form.__wsFilterParams = getParams();
                if (!_ensureFilterQueryParams($table, form)) {
                    // 走到这里说明表格实例拿不到（未初始化 / 已销毁）：
                    // 请求会照发但不带条件，明确告警，避免又一次"静默不生效"
                    logger.warn('crud', '筛选参数未能并入表格 queryParams，本次查询可能不带条件');
                }
            }
            _refreshFirstPage($table);
        };

        // 首次绑定用当前控件的默认值初始化快照：首屏加载要能带上 defineSearch() 的默认条件。
        // 重复调用**不覆盖**（否则会把"已提交的条件"换成"用户改了但没点查询的值"）。
        if (mergeEnabled) {
            if (form.__wsFilterParams === undefined) {
                form.__wsFilterParams = getParams();
            }
            if (!_ensureFilterQueryParams($table, form)) {
                // 表格还没建（autoBind 早于页面控制器建表）：不报错，
                // 点查询时 refresh() 会再挂一次
                logger.debug('crud', '表格尚未就绪，筛选参数延后挂载');
            }
        }

        // 事件只绑一次
        if (form.__wsFilterApi) return form.__wsFilterApi;

        // ── 查询时机：只认「查询」按钮 / 回车 / 表单提交 ──
        // 刻意**不监听** change / input：多条件筛选时每选一项就查一次，
        // 既连打多次请求，也会让用户看到"只带一半条件"的中间态结果。
        const onSubmitKey = (e) => {
            if (e.key !== 'Enter') return;
            const el = e.target;
            if (!el || el.tagName === 'TEXTAREA') return;
            e.preventDefault();
            refresh();
        };

        const onClickSubmit = (e) => {
            const btn = e.target && e.target.closest ? e.target.closest('.ws-filter-submit') : null;
            if (!btn) return;
            e.preventDefault();
            refresh();
        };

        const onClickReset = (e) => {
            const btn = e.target && e.target.closest ? e.target.closest('.ws-filter-reset') : null;
            if (!btn) return;
            e.preventDefault();
            self.resetFilterBar(form);
            refresh();
        };

        // 阻止表单原生提交（废除整页刷新的老写法）
        const onFormSubmit = (e) => {
            e.preventDefault();
            refresh();
        };

        form.addEventListener('keydown', onSubmitKey);
        form.addEventListener('click', onClickSubmit);
        form.addEventListener('click', onClickReset);
        form.addEventListener('submit', onFormSubmit);

        const api = {
            params: getParams,
            refresh,
            reset: () => {
                self.resetFilterBar(form);
                refresh();
            },
            destroy() {
                form.removeEventListener('keydown', onSubmitKey);
                form.removeEventListener('click', onClickSubmit);
                form.removeEventListener('click', onClickReset);
                form.removeEventListener('submit', onFormSubmit);
                delete form.__wsFilterApi;
                delete form.__wsFilterParams;
            },
        };

        form.__wsFilterApi = api;
        return api;
    },

    /** 重置筛选条：控件回到模板默认值（渲染器把默认值写在 value / selected 上） */
    resetFilterBar(formSelector = '#ws-filter') {
        const form = _resolveEl(formSelector);
        if (!form) return;

        // ws-* 组件走各自 API（改 value 不会更新组件显示态）
        _resetWsControls(form);

        Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), (el) => {
            // ws-* 的值载体已由组件 API 回填，这里再按 defaultValue（恒为 ''）覆盖会把默认值抹掉
            if (el.hasAttribute('data-ws-carrier')) return;
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = el.defaultChecked;
                return;
            }
            if (el.tagName === 'SELECT' && el.multiple) {
                Array.prototype.forEach.call(el.options, (opt) => { opt.selected = opt.defaultSelected; });
                return;
            }
            // select 的 defaultValue 就是带 selected 属性的那个 option
            el.value = el.defaultValue == null ? '' : el.defaultValue;
        });
    },

    /**
     * 零侵入接线（存量页面用）：
     *   页面同时存在 `#ws-filter` 与 `#table` 时，把筛选参数并入表格 queryParams 并绑定交互。
     *
     * 说明：不要在模板里同时用 `data-crud-schema`（自动 page() 装配），
     * 两者都会建表/绑参数，页面只需选一种。
     *
     * @param {Object} [options={}] { form, table }
     * @returns {Object|null}
     */
    autoBind(options = {}) {
        const form = _resolveEl(options.form || '#ws-filter');
        if (!form) return null;

        const $ = window.jQuery;
        const $table = _tableJq(options.table || '#table');
        if (!$ || !$table) return null;

        return this.bindFilterBar({ form, table: $table });
    },

    /**
     * 一次性装配（Phase 3 最终形态）：
     *   读 `data-crud-schema` → 按 schema.columns 建表 → 绑定筛选条。
     *
     * 注意：当前 schema 的 columns 由生成器/控制器补齐；为空时只绑定筛选条并打 warning，
     * 不会创建空表（避免出现「有分页没有列」的坏页面）。
     *
     * @param {Object} options
     * @param {string} [options.schemaUrl]     默认为表格上的 data-crud-schema
     * @param {string} [options.tableSelector='#table']
     * @param {Array}  [options.toolbarBtn]    覆盖默认工具栏按钮
     * @returns {Promise<{table:Object|null, filter:Object|null}|null>}
     */
    async page(options = {}) {
        const $ = window.jQuery;
        if (!$) return null;

        const tableSelector = options.tableSelector || '#table';
        const $table = $(tableSelector);
        if (!$table.length) {
            logger.warn('crud', `page(): 未找到表格 ${tableSelector}`);
            return null;
        }

        const schemaUrl = options.schemaUrl || $table.data('crudSchema');
        let schema = null;

        if (schemaUrl) {
            try {
                // schema() 是裸 JSON（无 code/msg 包装），走直通模式拿原始 body
                schema = await http.get(schemaUrl, null, { interceptor: false, autoError: false });
            } catch (err) {
                logger.error('crud', 'schema 拉取失败', err && err.message);
            }
        }

        const columns = (schema && Array.isArray(schema.columns)) ? schema.columns : [];
        let tableInstance = null;

        if (columns.length) {
            const urls = (schema && schema.urls) || {};
            tableInstance = window.Wise.table.createSync({
                idTable: tableSelector.replace(/^#/, ''),
                url: urls.list || $table.data('url') || '',
                columns,
                // 'export' 是字符串死按钮（v2 预设表无此项），服务端导出由 exportUrl 注入
                toolbarBtn: options.toolbarBtn || ['add', 'search', 'advsearch', 'refresh', 'multi'],
                // 仅当控制器声明了 export() 时才由 schema.urls 提供该地址；
                // exportPermission 由后端按 path_to_dot 规则给出，前端据此判断按钮显隐
                exportUrl: options.exportUrl || urls.export || '',
                exportPermission: options.exportPermission || (schema && schema.exportPermission) || '',
                checkboxHeader: true,
                onClick: options.onClick,
            });
        } else {
            logger.warn('crud', 'schema.columns 为空，跳过建表（仅绑定筛选条）');
        }

        const filter = this.bindFilterBar({
            form: options.form || '#ws-filter',
            table: tableInstance || $table,
            autoMerge: options.autoMerge,
        });

        return { table: tableInstance, filter };
    },
};

registry.register('crud', crud);

// ============================================================
// 自动装配
// ============================================================
// ① `data-crud-schema` 存在 → crud.page() 接管（模板生成器的新形态）
// ② 只有 `#ws-filter` + `#table`（存量页面）→ autoBind() 零侵入接线
// 时序：本模块与 wise-table 同批求值，Wise.table 的装配可能晚于此处，
// 因此不在模块顶层直接跑，而是等内核就绪事件（控制器执行前必发）。
function _autoInit() {
    const el = document.querySelector('[data-crud-schema]');
    if (el) {
        crud.page({
            schemaUrl: el.getAttribute('data-crud-schema'),
            tableSelector: '#' + (el.id || 'table'),
        }).catch((err) => logger.warn('crud', '自动装配失败', err && err.message));
        return;
    }

    // 存量页面：筛选条存在但页面控制器未显式 bindFilterBar 时兜底接线
    if (document.getElementById('ws-filter') && document.getElementById('table')) {
        crud.autoBind();
    }
}

if (window.Wise && window.Wise.table) {
    _autoInit();
} else {
    eventBus.on('ws:core:ready', _autoInit);
}

export { crud };
export default crud;
