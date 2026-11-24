// 样式：ws-linkage-select.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-linkage-select/ws-linkage-select.css">

import { getByPath, toElements } from '../ws-common/ws-common.js';

    // 注入组件样式：样式已改为外部引入（见文件顶部说明）。
    // 保留空实现以兼容内部既有调用点。
    function injectStyles() {
        // no-op：样式已由外部样式表提供
    }

    /**
     * 为 select 创建 wrapper（用于容纳 error 文字和状态指示器）
     */
    function ensureWrapper(select) {
        if (select.parentNode && select.parentNode.classList.contains('ws-linkage-select-wrapper')) {
            return select.parentNode;
        }
        var wrapper = document.createElement('div');
        wrapper.className = 'ws-linkage-select-wrapper';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        // 创建 error 文字容器
        var errEl = document.createElement('div');
        errEl.className = 'ws-linkage-error-text';
        wrapper.appendChild(errEl);

        // 创建 cascade 提示
        var hint = document.createElement('div');
        hint.className = 'ws-linkage-cascade-hint';
        hint.textContent = '上级选项已变化，请重新选择';
        wrapper.appendChild(hint);

        return wrapper;
    }

    /**
     * 确保所有 select 处于 group 容器中
     */
    function ensureGroup(selects) {
        var first = selects[0];
        var existingGroup = first.closest('.ws-linkage-select-group');
        if (existingGroup) return existingGroup;

        var group = document.createElement('div');
        group.className = 'ws-linkage-select-group';
        var parent = first.parentNode;
        if (parent.classList.contains('ws-linkage-select-wrapper')) {
            parent = parent.parentNode;
        }

        // 收集所有 select 的父节点，统一包入 group
        var wrappers = selects.map(function (s) { return ensureWrapper(s); });
        wrappers.forEach(function (w) { group.appendChild(w); });
        parent.appendChild(group);
        return group;
    }

    class LinkageSelect {
        /**
         * 默认配置项
         */
        static DEFAULT_OPTIONS = {
            placeholder: '请选择',
            loadingText: '加载中...',
            errorText: '加载失败',
            emptyText: '暂无数据',
            parentField: 'parent_id',
            valueField: 'id',
            labelField: 'name',
            /** 远程响应中数组的路径（支持 `data.rows` 这类点号路径） */
            responsePath: 'data.rows',
            /** 请求上级值时使用的参数名（`?parent=xx`） */
            parentParam: 'parent',
            /**
             * 是否只提交叶子级。
             * 默认 false：各级 `<select>` 各自带 `name`，提交 N 个字段（历史行为）。
             * true：移除各级 name，另建单个隐藏载体存叶子值 —— 关联字段通常只存叶子。
             */
            leafOnly: false,
            /** leafOnly 时的提交字段名 */
            name: null,
        };

        /**
         * 构造函数
         * @param {Object} config - 配置对象
         * @param {Array<string>} config.selects - select元素的ID数组，至少两个
         * @param {Function} config.dataSource - 数据获取函数，参数为当前选择的父级值，返回Promise
         * @param {Object} [config.options] - 可选配置项
         */
        constructor(config) {
            // 注入组件样式
            injectStyles();

            // 验证配置
            if (!config.selects || !Array.isArray(config.selects) || config.selects.length < 2) {
                throw new Error('必须提供至少两个select元素的ID数组');
            }

            if (!config.dataSource) {
                throw new Error('必须提供dataSource配置');
            }

            // 初始化配置
            this.selects = config.selects.map(id => {
                const element = document.getElementById(id);
                if (!element) {
                    throw new Error(`找不到ID为"${id}"的select元素`);
                }
                element.classList.add('ws-linkage-select');
                return element;
            });

            this.dataSource = config.dataSource;
            this.levels = this.selects.length;
            this.initialized = false;
            // 跟踪每个级别上次的值（用于级联变化检测）
            this._lastValues = new Array(this.levels).fill(null);
            // 合并配置项
            this.options = {
                ...LinkageSelect.DEFAULT_OPTIONS,
                ...config.options
            };

            // 建立 wrapper + group 容器
            ensureGroup(this.selects);

            // 初始化
            // ⚠️ init() 是 async（首级数据要等接口回来），而构造函数无法 await。
            // 把 Promise 暴露成 this.ready，调用方（尤其是 data-value 回填）才能
            // 可靠地等待"首级选项已就绪"。早前没有任何就绪信号，
            // 回填必然早于数据到达，表现是「值填不进去 / 填了被清空」。
            this.ready = this.init().catch(function (err) {
                console.error('[ws-linkage-select] 初始化失败', err);
                return null;
            });
        }

        /**
         * 设置 select 的三态
         * @param {HTMLSelectElement} select
         * @param {'loading'|'empty'|'error'} state - 设为 null 则清除状态
         * @param {string} [text] - 错误或空状态的文字
         */
        _setState(select, state, text) {
            var wrapper = select.closest('.ws-linkage-select-wrapper');
            var errEl = wrapper ? wrapper.querySelector('.ws-linkage-error-text') : null;

            // 清除之前的状态
            select.removeAttribute('data-ws-state');
            if (errEl) errEl.style.display = 'none';

            if (!state) return;

            select.setAttribute('data-ws-state', state);

            if (state === 'loading') {
                select.disabled = true;
                select.innerHTML = '<option value="">' + (text || this.options.loadingText) + '</option>';
            } else if (state === 'empty') {
                select.setAttribute('data-ws-empty-text', text || this.options.emptyText);
            } else if (state === 'error') {
                if (errEl) {
                    errEl.textContent = text || this.options.errorText;
                    errEl.style.display = 'block';
                }
                select.disabled = false;
            }
        }

        _clearState(select) {
            select.removeAttribute('data-ws-state');
            select.removeAttribute('data-ws-empty-text');
            select.disabled = false;
            select.classList.remove('linked-select-loading'); // 向后兼容

            var wrapper = select.closest('.ws-linkage-select-wrapper');
            // 隐藏 cascade hint
            if (wrapper) wrapper.classList.remove('ws-cascade-changed');
        }

        async init() {
            // 为每个select添加change事件监听
            this.selects.forEach((select, index) => {
                select._linkageSelectHandler = select._linkageSelectHandler || [];
                select._linkageSelectHandler[index] = () => {
                    this.onSelectChange(index);
                };
                select.addEventListener('change', select._linkageSelectHandler[index]);
            });

            // 加载第一级数据
            await this.loadData(0);
            this.initialized = true;

            // leafOnly：建隐藏载体接管提交值（必须在首级加载后，
            // 否则载体先于 select 值就绪，初始同步会写空）
            this._createCarrier();
        }

        /**
         * leafOnly 模式：建/取隐藏载体，并移除各级 select 的 name。
         *
         * 默认（leafOnly=false）各级 `<select>` 各带 `name`，提交 N 个字段；
         * 但关联字段通常只存叶子 ID，多出来的 `province=xx&city=xx` 会污染后端入参。
         */
        _createCarrier() {
            if (!this.options.leafOnly) return;

            var host = this.selects[0].closest('[data-role="wslinkage"]') || this.selects[0].parentNode;

            var carrier = host.querySelector('[data-ws-carrier]');
            if (!carrier) {
                carrier = document.createElement('input');
                carrier.type = 'hidden';
                carrier.className = 'ws-carrier';
                carrier.setAttribute('data-ws-carrier', 'hidden');
                host.appendChild(carrier);
            }

            if (this.options.name) carrier.setAttribute('name', this.options.name);

            // 各级 select 不再参与提交
            this.selects.forEach(function (select) {
                select.removeAttribute('name');
            });

            // 校验属性迁移到载体（宿主 div 上的副本会被校验器当成无名目标空跑）
            ['data-validation', 'data-required'].forEach(function (attr) {
                if (host.hasAttribute(attr)) {
                    carrier.setAttribute(attr, host.getAttribute(attr));
                    host.removeAttribute(attr);
                }
            });
            Array.prototype.slice.call(host.attributes).forEach(function (attr) {
                if (attr.name.indexOf('data-validation-message-') === 0) {
                    carrier.setAttribute(attr.name, attr.value);
                    host.removeAttribute(attr.name);
                }
            });

            this._carrier = carrier;
            this._syncCarrier(true);
        }

        /**
         * 把叶子值同步到载体并通知表单（leafOnly 模式生效）。
         * @param {boolean} [silent=false]
         */
        _syncCarrier(silent) {
            if (!this._carrier) return;

            var leaf = this.selects[this.levels - 1];
            var value = leaf && leaf.value ? leaf.value : '';

            this._carrier.value = value;

            if (silent) return;

            this._carrier.dispatchEvent(new Event('input', { bubbles: true }));
            this._carrier.dispatchEvent(new Event('change', { bubbles: true }));
            this.selects[0].dispatchEvent(new CustomEvent('ws-field:change', {
                bubbles: true,
                detail: {
                    name: this.options.name || '',
                    value: value,
                    path: this.selects.map(function (s) { return s.value; }),
                },
            }));
        }

        /** 各级当前值的数组（`['330000','330100','330106']`） */
        getLeafPath() {
            return this.selects.map(function (select) { return select.value; });
        }

        /**
         * 按「路径数组」回填多级选中值。
         *
         * 例：`await inst.setValueByPath(['330000', '330100', '330106'])`
         *
         * 逐级进行：设置第 N 级 → 触发第 N+1 级加载 → 再设置。因此调用前
         * 必须先 `await inst.ready`（本方法内部已处理），且服务端需提供
         * 按父级查子级的接口（`data-url` + `?parent=`）。
         *
         * 某一级的值在返回数据中不存在时会**停止**后续回填 ——
         * 否则会把子级的值错位填到父级的位置上。
         *
         * @param {string[]|string} values 路径数组，或逗号分隔字符串
         * @returns {Promise<Object>} `getValues()` 的结果
         */
        async setValueByPath(values) {
            if (this.ready) {
                try {
                    await this.ready;
                } catch (e) {
                    return this.getValues();
                }
            }

            var list = Array.isArray(values)
                ? values
                : String(values === null || values === undefined ? '' : values).split(',');

            for (var level = 0; level < this.levels; level++) {
                var v = list[level];

                if (v === null || v === undefined || String(v) === '') break;

                var select = this.selects[level];
                select.value = String(v);

                // 值不在当前选项里 → 数据不匹配，停止（避免错位）
                if (select.value !== String(v)) break;

                this._lastValues[level] = String(v);

                if (level < this.levels - 1) {
                    this._clearState(this.selects[level + 1]);
                    this.selects[level + 1].disabled = false;
                    await this.loadData(level + 1, v);
                }
            }

            this.triggerChangeEvent();
            return this.getValues();
        }

        async loadData(level, parentValue) {
            if (parentValue === undefined) parentValue = null;
            var select = this.selects[level];

            // 显示加载状态
            this._setState(select, 'loading');

            try {
                var data;

                if (typeof this.dataSource === 'string') {
                    var param = this.options.parentParam || 'parent';
                    var url = parentValue
                        ? this.dataSource + (this.dataSource.indexOf('?') === -1 ? '?' : '&')
                          + param + '=' + encodeURIComponent(parentValue)
                        : this.dataSource;
                    var response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    var json = await response.json();

                    // 统一支持 `{code:0,data:{rows:[...]}}` 外壳；
                    // 早前要求接口直接返回纯数组，套上框架默认外壳就整体失效。
                    var rows = getByPath(json, this.options.responsePath || 'data.rows');
                    data = Array.isArray(rows) ? rows : (Array.isArray(json) ? json : []);
                } else if (typeof this.dataSource === 'function') {
                    data = await this.dataSource(level, parentValue);
                } else {
                    // 注意：回调必须使用箭头函数，否则函数内的 this 会丢失（历史 bug：误用未定义的 self）
                    data = parentValue
                        ? this.findChildren(this.dataSource, parentValue)
                        : this.dataSource.filter((item) => !item[this.options.parentField]);
                }

                // 更新select选项
                this.updateSelectOptions(select, data, level);

                // 检查是否为空
                if (!data || data.length === 0) {
                    this._setState(select, 'empty', this.options.emptyText);
                } else {
                    this._clearState(select);
                }

            } catch (error) {
                console.error('加载数据失败:', error);
                this._setState(select, 'error', error.message || this.options.errorText);
                select.innerHTML = '<option value="">' + this.options.errorText + '</option>';
            }
        }

        findChildren(data, parentValue) {
            var self = this;
            return data.filter(function (item) { return item[self.options.parentField] == parentValue; });
        }

        updateSelectOptions(select, data, level) {
            // 保存当前选中的值
            var currentValue = select.value;

            // 清空选项
            select.innerHTML = '';

            // 添加默认选项
            var defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = level === 0 ? this.options.placeholder : '请先选择' + this.getLevelName(level - 1);
            select.appendChild(defaultOption);

            // 添加数据选项
            // 注意：回调必须使用箭头函数，否则函数内的 this 会丢失（历史 bug：误用未定义的 self）
            data.forEach((item) => {
                var option = document.createElement('option');
                option.value = item[this.options.valueField] || item.value;
                option.textContent = item[this.options.labelField] || item.label || item.name;
                option.dataset.data = JSON.stringify(item);
                select.appendChild(option);
            });

            // 恢复之前选中的值（如果存在）
            if (currentValue && data.some((item) => (item[this.options.valueField] || item.value) == currentValue)) {
                select.value = currentValue;
            }

            // 触发change事件以更新后续select
            if (this.initialized && select.value) {
                this.onSelectChange(level);
            }
        }

        getLevelName(level) {
            var label = document.querySelector('label[for="' + this.selects[level].id + '"]');
            return label ? label.textContent : '级别' + (level + 1);
        }

        async onSelectChange(level) {
            var select = this.selects[level];
            var value = select.value;

            // 清除当前级别的 error 状态
            this._clearState(select);

            // 检测值是否变化（用于级联提示）
            var isValueChanged = this._lastValues[level] !== null && this._lastValues[level] !== value;
            this._lastValues[level] = value;

            // 清空后续所有select
            for (var i = level + 1; i < this.levels; i++) {
                var childSelect = this.selects[i];
                this._clearState(childSelect);

                // 检查该子级之前是否有值
                var hadValue = this._lastValues[i] !== null && this._lastValues[i] !== '';
                this._lastValues[i] = null;

                // 如果值变化且子级之前有选中值，显示 cascade 提示
                var wrapper = childSelect.closest('.ws-linkage-select-wrapper');
                if (isValueChanged && hadValue && wrapper) {
                    wrapper.classList.add('ws-cascade-changed');
                } else if (wrapper) {
                    wrapper.classList.remove('ws-cascade-changed');
                }

                // 设置 empty 状态，显示提示文字
                this._setState(childSelect, 'empty', '请先选择' + this.getLevelName(i - 1));
                childSelect.innerHTML = '<option value="">请先选择' + this.getLevelName(i - 1) + '</option>';
                childSelect.disabled = true;
                childSelect.value = '';
            }

            // 如果有值，加载下一级数据
            if (value && level < this.levels - 1) {
                // 清除 empty 状态再加载
                this._clearState(this.selects[level + 1]);
                this.selects[level + 1].disabled = false;
                await this.loadData(level + 1, value);

                // 加载后隐藏 cascade hint
                var nextWrapper = this.selects[level + 1].closest('.ws-linkage-select-wrapper');
                if (nextWrapper) nextWrapper.classList.remove('ws-cascade-changed');
            }

            // 触发自定义事件
            this.triggerChangeEvent();
        }

        triggerChangeEvent() {
            var values = {};
            this.selects.forEach(function (select, index) {
                values['level' + index] = select.value;
                if (select.value) {
                    var selectedOption = select.options[select.selectedIndex];
                    try {
                        values['level' + index + 'Data'] = JSON.parse(selectedOption.dataset.data || '{}');
                    } catch (e) {
                        values['level' + index + 'Data'] = {};
                    }
                }
            });

            var event = new CustomEvent('linkageSelectChange', {
                detail: values
            });
            this.selects[0].dispatchEvent(event);

            // leafOnly：同步提交值（triggerChangeEvent 是值变化的唯一出口，
            // 所以载体同步放这里，覆盖点选 / 键盘 / 程序化 setValueByPath 全路径）
            this._syncCarrier();
        }

        getValues() {
            var values = {};
            this.selects.forEach(function (select, index) {
                values['level' + index] = select.value;
                if (select.value) {
                    var selectedOption = select.options[select.selectedIndex];
                    try {
                        values['level' + index + 'Data'] = JSON.parse(selectedOption.dataset.data || '{}');
                    } catch (e) {
                        values['level' + index + 'Data'] = {};
                    }
                }
            });
            return values;
        }

        async reset() {
            var self = this;
            this.selects.forEach(function (select, index) {
                self._clearState(select);
                select.innerHTML = '<option value="">' + (index === 0 ? self.options.placeholder : '请先选择' + self.getLevelName(index - 1)) + '</option>';
                select.disabled = index > 0;
                select.value = '';
                self._lastValues[index] = null;
            });
            await this.loadData(0);
            this.triggerChangeEvent();
        }

        destroy() {
            this.selects.forEach(function (select, index) {
                if (select._linkageSelectHandler && select._linkageSelectHandler[index]) {
                    select.removeEventListener('change', select._linkageSelectHandler[index]);
                    delete select._linkageSelectHandler[index];
                }
            });
        }
    }

/* ============================================================
 * 声明式接入（data-role="wslinkage"）
 *
 * 组件本体的 config 只有 `{selects: [id...], dataSource}`，无法从 HTML 表达。
 * 这里把 data-* 翻译成该 config，业务模板即可零 JS 使用：
 *
 * ```html
 * <div data-role="wslinkage"
 *      data-selects="province,city,district"
 *      data-url="/admin/area/options"
 *      data-value-path="330000,330100,330106"
 *      data-leaf-only="true"
 *      data-name="area_id">
 *   <select id="province"></select>
 *   <select id="city"></select>
 *   <select id="district"></select>
 * </div>
 * ```
 * ============================================================ */

var _autoUid = 0;

/** 读布尔 data-*（缺省返回 fallback） */
function readBoolAttr(el, attr, fallback) {
    if (!el.hasAttribute(attr)) return fallback;
    var raw = el.getAttribute(attr);
    return raw === '' || /^(true|1|yes|on)$/i.test(String(raw).trim());
}

/**
 * 扫描并初始化 `[data-role="wslinkage"]`。
 *
 * @param {*} [root] document / 元素 / 选择器
 * @returns {number} 初始化数量
 */
LinkageSelect.autoInitialize = function (root) {
    var count = 0;

    toElements(root || document).forEach(function (scope) {
        var list = [];

        if (scope.nodeType === 1 && scope.matches && scope.matches('[data-role="wslinkage"]')) list.push(scope);
        if (scope.querySelectorAll) {
            list = list.concat(Array.prototype.slice.call(scope.querySelectorAll('[data-role="wslinkage"]')));
        }

        list.forEach(function (host) {
            if (host._wsLinkage) return;

            var url = host.getAttribute('data-url') || host.getAttribute('data-remote-url');
            if (!url) {
                console.warn('[ws-linkage-select] 缺少 data-url，已跳过', host);
                return;
            }

            // 级联的 select：优先按 data-selects 指定的顺序，否则取宿主内全部 select
            var ids = String(host.getAttribute('data-selects') || '')
                .split(',')
                .map(function (s) { return s.trim(); })
                .filter(Boolean);

            var selects = ids.length
                ? ids.map(function (id) { return document.getElementById(id) || host.querySelector('#' + id); }).filter(Boolean)
                : Array.prototype.slice.call(host.querySelectorAll('select'));

            if (selects.length < 2) {
                console.warn('[ws-linkage-select] 至少需要 2 个 select（data-selects 或宿主内 select 子元素）', host);
                return;
            }

            // 构造函数按 id 查找元素，缺失时补一个稳定 id
            selects.forEach(function (s, i) {
                if (!s.id) s.id = 'ws-linkage-' + (++_autoUid) + '-l' + i;
            });

            var inst = new LinkageSelect({
                selects: selects.map(function (s) { return s.id; }),
                dataSource: url,
                options: {
                    placeholder: host.getAttribute('data-placeholder') || '请选择',
                    valueField: host.getAttribute('data-value-field') || 'id',
                    labelField: host.getAttribute('data-label-field') || 'name',
                    parentField: host.getAttribute('data-parent-field') || 'parent_id',
                    parentParam: host.getAttribute('data-parent-param') || 'parent',
                    responsePath: host.getAttribute('data-response-path') || 'data.rows',
                    leafOnly: readBoolAttr(host, 'data-leaf-only', false),
                    name: host.getAttribute('data-name') || null,
                    loadingText: host.getAttribute('data-loading-text') || '加载中...',
                    errorText: host.getAttribute('data-error-text') || '加载失败',
                    emptyText: host.getAttribute('data-empty-text') || '暂无数据',
                },
            });

            host._wsLinkage = inst;
            count++;

            // 回填：data-value-path 是完整路径；data-value 也接受逗号分隔
            var initial = host.getAttribute('data-value-path') || host.getAttribute('data-value');
            if (initial) {
                inst.setValueByPath(String(initial).split(','));
            }
        });
    });

    return count;
};

// 全局挂载：统一使用 Ws* 标准名 window.WsLinkageSelect；LinkageSelect 为历史别名，兼容存量调用零改造。
window.WsLinkageSelect = LinkageSelect;
window.LinkageSelect = LinkageSelect;

export default LinkageSelect;