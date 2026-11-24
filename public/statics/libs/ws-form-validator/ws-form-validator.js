/**
 * ws-form-validator
 * 一个基于 Bootstrap 5 表单校验插件，支持实时校验、自定义规则、异步校验等功能。
 * 支持 Bootstrap 5 表单校验功能，包括必填项、邮箱格式、手机号格式、数字格式、自定义规则等。
 * 支持自定义校验规则，用户可以根据需求添加自定义校验规则。
 * 
 * // 初始化表单校验组件
 * document.addEventListener('DOMContentLoaded', function() {
 *      var validator = new WsFormValidator('validationForm', {
 *          liveValidation: true,
 *          showIcons: true
 *      });
 * });
 * 
 * @url https://www.wiseadmin.top/
 * @author WiseAdmin
 * @version 1.1.1
 * @license MIT
 *
 * v1.1.1 passwordStrength 支持参数化并输出具体原因：
 *        `passwordStrength:8,letter,digit`（无参 = 8 位 + 字母 + 数字）
 *        - 失败文案不再是「密码强度不足」，而是首条未满足项（长度/字母/数字/空格…）
 *        - 强度条与校验共用同一份判定，避免「条是绿的但校验不过」
 *        - 规则旁自动渲染条件清单，逐项标注是否满足
 *
 * v1.1.0 新增「动态规则 API」：setRules() / clearRules() / refresh()
 *        新增 autoSubmit 提交细节配置：submitMethod / submitContentType /
 *        submitHeaders / submitData（默认值与旧行为一致，向后兼容）
 *   - 支持条件必填（options.when）
 *   - 支持非原生控件接入（options.getValue / uiTarget / feedback），
 *     如 ws-select 的真值在组件内部而非传入的 field 上
 *   - onCustomRules 从「仅声明」变为真正可用
 *   - 实时校验补绑 change 事件（原生 select 与 ws-* 组件靠它通知值变化）
 */
// ws-layer 是本组件的硬依赖（校验失败提示走弹层），改为显式 ESM 依赖，
// 由打包器保证求值顺序，不再依赖全局 window.Layer 的加载时序。
import Layer from '../ws-layer/ws-layer.js';

// jQuery / Bootstrap 走全局单例：若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
const bootstrap = window.bootstrap;
if (typeof $ === 'undefined') {
    throw new Error('WsFormValidator requires jQuery');
}
    
    class WsFormValidator {
        constructor(formId, options = {}) {
            if (!formId) {
                throw new Error('WsFormValidator requires a form ID');
            }

            this.form = document.getElementById(formId);
            if (!this.form) {
                throw new Error('Form with ID "' + formId + '" not found');
            }
            // 禁止浏览器默认校验
            this.form.setAttribute('novalidate', '');
            this.form.classList.add('needs-validation');

            // 合并默认配置和用户配置
            this.options = Object.assign({}, WsFormValidator.DEFAULT_OPTIONS, options);

            // 动态规则表：element → { rules, options }
            // 用于条件必填与非原生控件（ws-select / ws-date-picker 等）——
            // 这类字段无法在模板里写 data-validation：真值不在 DOM 上，或规则随条件变化
            this._dynamicRules = new Map();

            // 当前正在校验的动态规则配置。供 getErrorMessage 读取自定义文案，
            // 避免给十几处规则分支都加一个参数。
            this._currentDynamic = null;

            this.init();
        }

        // 默认配置
        static DEFAULT_OPTIONS = {
            liveValidation: true, // 是否启用实时校验
            showIcons: true, // 是否在校验反馈中显示图标
            showMessage: false, // 是否显示校验消息
            feedbackPlaceholder: false, // 是否使用placeholder作为提示文本
            autoSubmit: false, // 是否自动提交表单
            // ---- autoSubmit 的提交细节 ----
            // 不传即保持旧行为：POST + form.action + serialize()（表单编码）
            submitMethod: 'post', // 请求方法：post / get / put / patch / delete
            submitContentType: 'form', // 'form'（表单编码，默认）| 'json'
            submitHeaders: null, // 额外请求头（CSRF 已由 wise-framework.js 全局注入，通常无需设置）
            submitData: null, // (form) => object|FormData，覆盖默认的 serialize()
            onSuccess: null, // 校验成功回调函数
            onError: null, // 校验失败回调函数
            onSubmit: null, // 表单提交回调函数
            onReset: null, // 表单重置回调函数
            onCustomRules: {} // 自定义校验规则
        }

        // 正则表达式
        static REGEX = {
            email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
            url: /^(http[s]?):\/\/[^\s/$.?#].[^\s]*$/,
            date: /^\d{4}-\d{2}-\d{2}$/,
            time: /^\d{2}:\d{2}$/,
            datetime: /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/,
            number: /^\d+$/,
            integer: /^-?\d+$/,
            float: /^-?\d+(\.\d+)?$/,
            phone: /^1[3-9]\d{9}$/,
        }

        /**
         * 密码强度默认策略（规则不传参时使用）
         *
         * ⚠️ 与后端 app/admin/validate/Admin.php 的 passwordPolicy() 保持一致：
         *    8~32 位、至少 1 个字母、至少 1 个数字。
         *    调整时两处一起改（模板里的 passwordStrength:8,letter,digit 也要同步）。
         */
        static PASSWORD_POLICY = {
            minLength: 8,
            maxLength: 32,
            require: ['letter', 'digit'],
        }

        init() {
            // 防止重复初始化
            if (this._initialized) return;
            this._initialized = true;

            // 绑定表单提交事件
            this.form.addEventListener('submit', (el) => {
                el.preventDefault();
                this.validateForm(el);
            });
            
            // 绑定实时校验事件
            if (this.options.liveValidation) {
                this.setupLiveValidation();
            }
            
            // 绑定重置事件
            this.form.addEventListener('reset', (el) => {
                this.resetForm();
                if(typeof this.options.onReset === 'function') {
                    this.options.onReset(el, this.form);
                }
            });
            
            // 设置自定义事件监听
            this.setupCustomEvents();

            // 初始化占位符
            this.setupPlaceholders();
        }
        
        // 设置实时校验（保留原方法名；实际逻辑收敛到 refresh()）
        setupLiveValidation() {
            this.refresh();
        }

        /**
         * 重新扫描并绑定实时校验
         *
         * 覆盖两类目标：
         *   1. 带 data-validation 的元素（含同 name 的 radio/checkbox 组）
         *   2. setRules() 动态注册的元素（可能是无 name 的容器 div）
         *
         * 幂等：同一元素重复调用不会重复绑定。
         * @returns {WsFormValidator}
         */
        refresh() {
            const targets = new Set();

            this.form.querySelectorAll('[data-validation]').forEach(input => {
                // 对同 name 组的所有元素（如 radio/checkbox）都绑定事件
                const groupName = input.getAttribute('name');
                if (!groupName) return;
                this.form.querySelectorAll('[name="' + CSS.escape(groupName) + '"]')
                    .forEach(el => targets.add(el));
            });

            this._dynamicRules.forEach((_cfg, el) => {
                if (el && el.isConnected) targets.add(el);
            });

            targets.forEach(el => this._bindLive(el));
            return this;
        }

        /** 为单个元素绑定实时校验（幂等） */
        _bindLive(el) {
            if (!el || el._wsFvBound) return;
            el._wsFvBound = true;

            const handler = () => this.validateField(el);
            el.addEventListener('input', handler);
            el.addEventListener('blur', handler);
            // change 不可省：原生 select 与 ws-select / ws-date-picker 类组件靠 change 通知值变化
            el.addEventListener('change', handler);
        }
        
        /**
         * 解析元素的 data-validation 属性为规则映射表
         * @param {HTMLElement} input
         * @returns {Object}  { ruleName: ruleParam, ... }
         */
        _parseRules(input) {
            var validationRules = input.getAttribute('data-validation');
            if (!validationRules) return {};
            var rules = validationRules.split('|');
            var ruleMap = {};
            for (var j = 0; j < rules.length; j++) {
                var rule = rules[j];
                var ruleParts = rule.split(':');
                var ruleName = ruleParts[0];
                var ruleParam = ruleParts[1] || '';
                ruleMap[ruleName] = ruleParam;
            }
            return ruleMap;
        }

        // 设置自定义事件
        setupCustomEvents() {
            var inputs = this.form.querySelectorAll('[data-validation]');
            for (var i = 0; i < inputs.length; i++) {
                var input = inputs[i];
                var ruleMap = this._parseRules(input);
                if (Object.keys(ruleMap).length === 0) continue;

                // 根据规则类型绑定不同的事件
                // 密码强度检测
                if(ruleMap['passwordStrength'] !== undefined){
                    var strengthIndicator = this._resolveStrengthIndicator(input);
                    this.bindPasswordStrengthEvent(input, strengthIndicator, input.id + '_strength', ruleMap['passwordStrength']);
                }
                // 字符计数
                if(ruleMap['charCount'] !== undefined && ruleMap['maxLength'] !== undefined) {
                    var counterId = input.id + 'CharCount';
                    var maxLength = ruleMap['maxLength'];
                    this.bindCharCountEvent(input, counterId, maxLength);
                }
                // 文件上传
                if(ruleMap['fileType'] !== undefined || ruleMap['fileSize'] !== undefined) {
                    var areaId = input.id + 'UploadArea';
                    var infoId = input.id + 'FileInfo';
                    this.bindFileUploadEvent(input, areaId, infoId);
                }
            }
        }
        
        // 初始化占位符
        setupPlaceholders() {
            if(this.options.feedbackPlaceholder){
                const elements = this.form.querySelectorAll('.invalid-feedback');
                elements.forEach(element => {
                    element.style.visibility = 'hidden';
                    element.style.display = 'block';
                });
            }
        }
        // 校验整个表单
        validateForm(el) {
            let self = this;
            let isValid = true;
            let errors = [];
            // 原生控件 + 动态注册的非原生控件
            // （如 data-role="wsselect" 的容器 div，它不是 input/select/textarea，只靠原生查询会漏掉）
            const inputs = Array.from(this.form.querySelectorAll('input, select, textarea'));
            this._dynamicRules.forEach((_cfg, el) => {
                if (el && el.isConnected && !el.matches('input, select, textarea')) inputs.push(el);
            });

            inputs.forEach(input => {
                let result = this.validateField(input);
                if (result !== true) {
                    isValid = false;
                    errors.push(result);
                }
            });
            
            if (isValid) {
                if (this.options.showMessage) {
                    this.showSuccessMessage('表单校验通过，可以提交！');
                }

                if (typeof this.options.onSuccess === 'function') {
                    this.options.onSuccess(this.form, el);
                }

                if (this.options.autoSubmit) {
                    this._autoSubmit(el);
                } else if (typeof this.options.onSubmit === 'function') {
                    this.options.onSubmit(this.form, el, '');
                }
                
            } else {
                if (this.options.showMessage) {
                    this.showErrorMessage('表单中存在错误，请检查后重新提交！');
                }

                // 滚动到第一个错误字段
                this.scrollToFirstError();

                if (typeof this.options.onError === 'function') {
                    this.options.onError(this.form, el, errors);
                }
            }
            
            return isValid;
        }

        /**
         * 校验整个表单 —— `validateForm()` 的别名。
         *
         * 存在原因：`Wise.ui.dialog.form` 提交时调用的是
         * `typeof validator.validate === 'function' && validator.validate() === false`，
         * 而本类原本只有 `validateForm()` 没有 `validate()` ——
         * `typeof` 守卫直接把整段校验跳过了，表现为「弹窗表单的必填从不拦截」。
         * 这里补上别名，比改调用方更安全（其他调用方也可能用这个名字）。
         *
         * @param {Event} [el] 触发事件
         * @returns {boolean}
         */
        validate(el) {
            return this.validateForm(el);
        }

        /**
         * 内置自动提交（autoSubmit: true 时调用）
         *
         * 默认行为与旧版**完全一致**：POST + form.action + serialize()（表单编码）。
         * 以下配置可覆盖（都不传即保持旧行为）：
         *   - submitMethod       'post' | 'get' | 'put' | 'patch' | 'delete'
         *   - submitContentType  'form'（默认，表单编码）| 'json'
         *   - submitHeaders      额外请求头
         *   - submitData         (form) => object | FormData，覆盖默认的 serialize()
         *
         * ⚠️ 成功判定（res.code == 0）与 iframe 内自动关闭弹层是本组件的硬编码约定，
         *    扩展时不要改动，否则会影响现有使用 autoSubmit 的后台页面。
         *
         * @param {Event} el 触发表单提交的事件
         */
        _autoSubmit(el) {
            const self = this;
            const form = this.form;
            const options = this.options;

            const method = String(options.submitMethod || 'post').toLowerCase();
            const useJson = String(options.submitContentType || 'form').toLowerCase() === 'json';

            // 数据来源：submitData > serialize()
            let payload = typeof options.submitData === 'function'
                ? options.submitData(form)
                : $(form).serialize();

            const ajaxOptions = {
                url: form.action,
                type: method,
                dataType: 'json',
                beforeSend: function () {
                    Layer.loading({ content: '正在提交...' });
                },
                success: function (res) {
                    if (res.code == 0) {
                        Layer.toast({ content: res?.msg || '提交成功', type: 'success', time: 2000 });
                        if (typeof self.options.onSubmit === 'function') {
                            self.options.onSubmit(self.form, el, (res || '提交成功'));
                        }
                        if (window.parent !== window && window.parent.Layer) {
                            window.parent.Layer.close(window.parent.Layer.getFrameIndex(window.name));
                        }
                    } else {
                        Layer.toast({ content: res?.msg || '提交失败', type: 'warning', time: 20000 });
                        if (typeof self.options.onSubmit === 'function') {
                            self.options.onSubmit(self.form, el, (res || '提交失败'));
                        }
                    }
                    Layer.closeAll('loading');
                },
                error: function () {
                    Layer.toast({ content: '网络错误，请重试', type: 'warning' });
                    if (typeof self.options.onSubmit === 'function') {
                        self.options.onSubmit(self.form, el, '网络错误，请重试');
                    }
                    Layer.closeAll('loading');
                }
            };

            if (useJson) {
                ajaxOptions.contentType = 'application/json; charset=UTF-8';
                // FormData 与已序列化的字符串不重复处理
                if (!(payload instanceof FormData) && typeof payload !== 'string') {
                    payload = JSON.stringify(payload);
                }
            }

            if (options.submitHeaders && typeof options.submitHeaders === 'object') {
                ajaxOptions.headers = options.submitHeaders;
            }

            ajaxOptions.data = payload;
            $.ajax(ajaxOptions);
        }

        // 校验单个字段
        validateField(field) {
            if (!field) return true;

            // 动态注册的字段优先（不依赖模板里的 data-validation）
            const dynamic = this._dynamicRules.get(field) || null;
            this._currentDynamic = dynamic;

            // 条件不成立 → 不校验，并清掉可能残留的错误态（条件必填场景）
            if (dynamic && typeof dynamic.options.when === 'function' && !dynamic.options.when(field)) {
                this.updateFieldUI(field, true, '', dynamic.options);
                this._currentDynamic = null;
                return true;
            }

            // 规则来源：动态规则 > data-validation 属性
            const groupName = field.name;
            const ruleSource = dynamic
                ? dynamic
                : (groupName ? document.querySelector(`[name="${groupName}"][data-validation]`) : null);
            if (!ruleSource) {
                this._currentDynamic = null;
                return true;
            }

            const rules = dynamic
                ? this._serializeRules(dynamic.rules)
                : ruleSource.getAttribute('data-validation');
            if (!rules) {
                this._currentDynamic = null;
                return true;
            }

            // 取值：动态规则可用 getValue 覆盖
            // （ws-select 的真值在组件内部/生成的隐藏 input 上，不一定在传入的 field 上）
            const rawValue = (dynamic && typeof dynamic.options.getValue === 'function')
                ? dynamic.options.getValue(field)
                : field.value;
            const value = rawValue === null || rawValue === undefined ? '' : String(rawValue);
            let isValid = true;
            let errorMessage = '';
            
            // 解析规则
            const ruleList = rules.split('|');
            
            for (const rule of ruleList) {
                let [ruleName, ruleParam] = rule.split(':');
                
                // 特殊处理单选按钮和复选框
                if ((field.type === 'radio' || field.type === 'checkbox') && ruleName === 'required') {
                    const checked = this.form.querySelector(`[name="${groupName}"]:checked`);
                    if (!checked) {
                        isValid = false;
                        // 修复：原为未定义的 fieldAttr，必抛 ReferenceError，
                        // 导致复选框/单选框「必选」校验从不显示红框与提示。
                        errorMessage = this.getErrorMessage(field, ruleName);
                        break;
                    }
                } else {
                    // 校验规则
                    switch (ruleName) {
                        case 'required':
                            if (!value.trim()) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'minLength':
                            if (value.length < parseInt(ruleParam)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'maxLength':
                            if (value.length > parseInt(ruleParam)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'email':
                            if (value && !this.isValidEmail(value)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'phone':
                            if (value && !this.isValidPhone(value)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'number':
                            // 允许空值（非必填场景），有值时校验是否为有效数字
                            if (value !== '' && isNaN(Number(value))) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'min':
                            if (value && parseFloat(value) < parseFloat(ruleParam)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'max':
                            if (value && parseFloat(value) > parseFloat(ruleParam)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'match':
                            // 去除可能包裹的斜杠：/pattern/ → pattern；不使用 'g' 标志避免 lastIndex 粘性 bug
                            ruleParam = ruleParam.replace(/^\/|\/$/g, '');
                            if (value && !(new RegExp(ruleParam).test(value))) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;

                        case 'confirmPassword':
                            const fieldToMatch = document.getElementById(ruleParam);
                            if (fieldToMatch && value !== fieldToMatch.value) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;

                        case 'date':
                            if (value && !this.isValidDate(value)) {
                                isValid = false;
                                errorMessage = this.getErrorMessage(field, ruleName);
                            }
                            break;
                            
                        case 'fileType':
                            if (field.files.length > 0) {
                                const file = field.files[0];
                                const acceptedTypes = ruleParam.split(',');
                                let typeValid = false;
                                
                                for (const type of acceptedTypes) {
                                    if (file.type.match(type.replace('*', '.*'))) {
                                        typeValid = true;
                                        break;
                                    }
                                }
                                
                                if (!typeValid) {
                                    isValid = false;
                                    errorMessage = this.getErrorMessage(field, ruleName);
                                }
                            }
                            break;
                            
                        case 'fileSize':
                            if (field.files.length > 0) {
                                const file = field.files[0];
                                const maxSize = this.parseFileSize(ruleParam);
                                
                                if (file.size > maxSize) {
                                    isValid = false;
                                    errorMessage = this.getErrorMessage(field, ruleName);
                                }
                            }
                            break;
                            
                        case 'passwordStrength': {
                            if (value) {
                                const evaluation = this.evaluatePassword(value, this._parsePasswordPolicy(ruleParam));
                                if (!evaluation.valid) {
                                    isValid = false;
                                    // 具体原因：长度不足 / 缺字母 / 缺数字 / 含空格…
                                    // 如需整体覆盖，仍可用 data-validation-message-passwordStrength
                                    errorMessage = this.getPasswordStrengthMessage(field, evaluation);
                                }
                            }
                            break;
                        }

                        default:
                            // 自定义规则：onCustomRules[ruleName](value, ruleParam, field)
                            //   返回 false → 使用默认/自定义文案
                            //   返回非空字符串 → 该字符串即错误文案
                            //   其他（true / undefined）→ 视为通过
                            const customFn = this.options.onCustomRules && this.options.onCustomRules[ruleName];
                            if (typeof customFn === 'function') {
                                const result = customFn.call(this, value, ruleParam, field);
                                if (result === false) {
                                    isValid = false;
                                    errorMessage = this.getErrorMessage(field, ruleName);
                                } else if (typeof result === 'string' && result !== '') {
                                    isValid = false;
                                    errorMessage = result;
                                }
                            }
                            break;
                    }
                }
                
                if (!isValid) break;
            }

            // 更新UI（动态规则可指定状态元素与反馈容器）
            this.updateFieldUI(field, isValid, errorMessage, dynamic ? dynamic.options : null);
            this._currentDynamic = null;

            if(isValid){
                return true;
            }else {
                return {field, isValid, errorMessage};
            }
        }

        // ============================================================
        // 动态规则 API（v1.1.0）
        // ============================================================

        /**
         * 动态注册 / 更新字段的校验规则（不依赖模板中的 data-validation）
         *
         * 适用两类场景：
         *   1. **条件必填**：用 `options.when` 控制规则生效时机；条件不成立时自动清除错误态
         *   2. **非原生控件**（ws-select / ws-date-picker 等）：真值不在 DOM 上或事件模型不同，
         *      用 `options.getValue` 取真值、`options.uiTarget` / `options.feedback` 指定状态与提示位置
         *
         * @param {HTMLElement|string} target 元素，或选择器（只取第一个匹配）
         * @param {string|Object|Array|null} rules 规则串 `'required|minLength:2'`、
         *        对象 `{required:true, minLength:2}`、数组；传 null / '' 表示移除
         * @param {Object} [options]
         * @param {Function} [options.getValue] `(field) => any` 自定义取值
         * @param {Function} [options.when] `(field) => boolean` 条件成立才校验
         * @param {Object} [options.messages] `{ ruleName: '错误文案' }`
         * @param {HTMLElement} [options.uiTarget] 加 `is-valid` / `is-invalid` 的元素
         * @param {HTMLElement} [options.feedback] `.invalid-feedback` 容器
         * @param {boolean} [options.live=true] 是否绑定实时校验
         * @returns {WsFormValidator}
         */
        setRules(target, rules, options = {}) {
            const el = typeof target === 'string' ? this.form.querySelector(target) : target;
            if (!el) {
                console.warn('[WsFormValidator] setRules: 目标元素不存在', target);
                return this;
            }

            // 移除规则：清表并抹掉错误态
            if (rules === null || rules === undefined || rules === '') {
                this._dynamicRules.delete(el);
                this.updateFieldUI(el, true, '', options);
                return this;
            }

            this._dynamicRules.set(el, { rules, options: Object.assign({ live: true }, options) });
            if (options.live !== false) this._bindLive(el);
            return this;
        }

        /** 移除动态规则（等价于 setRules(target, null)） */
        clearRules(target) {
            return this.setRules(target, null);
        }

        /** 规则对象 / 数组 → 规则串（与 data-validation 同一套语法） */
        _serializeRules(rules) {
            if (!rules) return '';
            if (typeof rules === 'string') return rules;
            if (Array.isArray(rules)) return rules.filter(Boolean).join('|');

            return Object.keys(rules)
                .filter(name => rules[name] !== false && rules[name] !== null && rules[name] !== undefined)
                .map(name => {
                    const param = rules[name];
                    return (param === true || param === '') ? name : name + ':' + param;
                })
                .join('|');
        }

        // 获取表单数据
        getFormData() {
            const formData = new FormData(this.form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            return data;
        }
        // 获取错误消息
        getErrorMessage(field, ruleName) {
            // 动态规则的文案优先（由 setRules 的 options.messages 提供）
            const dynamicMessages = this._currentDynamic && this._currentDynamic.options
                ? this._currentDynamic.options.messages
                : null;
            if (dynamicMessages && dynamicMessages[ruleName]) return dynamicMessages[ruleName];

            const customMessage = field.getAttribute(`data-validation-message-${ruleName}`);
            if (customMessage) return customMessage;
            
            // 默认错误消息
            const defaultMessages = {
                required: '此字段为必填项',
                minLength: '输入内容太短',
                maxLength: '输入内容太长',
                email: '请输入有效的邮箱地址',
                phone: '请输入有效的电话号码',
                number: '请输入有效的数字',
                min: '数值太小',
                max: '数值太大',
                match: '输入不匹配',
                date: '请输入有效的日期',
                fileType: '文件类型不正确',
                fileSize: '文件太大',
                passwordStrength: '密码强度不足'
            };
            
            return defaultMessages[ruleName] || '输入无效';
        }
        
        /**
         * 在 node 的下一个兄弟中查找 .invalid-feedback
         *
         * 兄弟**自身**可能就是反馈容器：ws-date-picker 会把原生 input 包进
         * `<ws-date-container>`，模板里原本紧跟 input 的 `.invalid-feedback`
         * 于是变成容器的兄弟、而不是 input 的兄弟。只查后代（querySelector）
         * 会漏掉这一种，表现为「红框出现了但提示文案不显示」。
         */
        _siblingFeedback(node) {
            const sibling = node && node.nextElementSibling;
            if (!sibling) return null;
            if (sibling.classList && sibling.classList.contains('invalid-feedback')) return sibling;
            return sibling.querySelector('.invalid-feedback');
        }

        // 更新字段UI
        // @param {Object} [override] 动态规则的覆盖项 { uiTarget, feedback }
        updateFieldUI(field, isValid, errorMessage, override) {
            override = override || {};

            // 获取字段的name值，用于查找对应的反馈元素
            const groupName = field.name;

            // 声明式 ws-* 组件：真值在隐藏载体（input[type=hidden]）上，
            // 直接按 name 命中的是不可见元素，加 is-invalid 用户看不到任何反馈。
            // 组件宿主统一带 data-role="ws*"，回退到宿主元素。
            const wsHost = (!override.uiTarget && field.closest)
                ? field.closest('[data-role^="ws"]')
                : null;

            // 宿主内部可声明真正的「视觉目标」：组件把红框打在触发元素上
            // （容器本身往往没有边框，加 is-invalid 看不出任何变化）
            const wsUiTarget = wsHost
                ? (wsHost.querySelector('[data-ws-ui]') || wsHost)
                : null;

            // 需要标记状态的元素：默认按 name 找同名元素；
            // 动态规则（如 ws-select 的容器）可显式指定 uiTarget
            const elements = override.uiTarget
                ? [override.uiTarget]
                : (wsUiTarget ? [wsUiTarget] : document.querySelectorAll(`[name="${groupName}"]`));
    
            // 移除之前的校验状态
            elements.forEach(element => {
                element.classList.remove('is-valid', 'is-invalid');
            });
            
            // 查找反馈元素
            let feedbackElement;

            // 1) 优先在「字段行容器」内找。
            //    组件常把控件再包一层（ws-date-picker → <ws-date-container>），
            //    此时 .invalid-feedback 既不是控件的后代、也不是它的兄弟，
            //    而是行容器里另一列（Bootstrap5Theme 的 .col-auto.tips）。
            //    只按"控件 → 父级 → 兄弟"找会全部落空，红框出来了但提示文案不显示。
            if (!override.feedback && field.closest) {
                const rowEl = field.closest('.form-field-container, [data-field], .form-group');
                if (rowEl) {
                    feedbackElement = rowEl.querySelector('.invalid-feedback');
                }
            }

            // 2) 行容器里没有时，再按原有就近规则兜底
            if (!feedbackElement && (field.type === 'radio' || field.type === 'checkbox')) {
                const firstField = this.form.querySelector(`[name="${groupName}"]`);
                feedbackElement = firstField.closest('.form-check')?.parentNode?.querySelector('.invalid-feedback');
                feedbackElement = feedbackElement || firstField.closest('.form-check')?.parentNode?.nextElementSibling?.querySelector('.invalid-feedback');
            } else if (!feedbackElement && wsHost) {
                // 宿主内优先，其次宿主的父级/父级兄弟（模板通常把 .invalid-feedback 放在组件旁边）
                feedbackElement = wsHost.querySelector('.invalid-feedback')
                    || wsHost.parentNode?.querySelector('.invalid-feedback')
                    || this._siblingFeedback(wsHost.parentNode);
            } else if (!feedbackElement) {
                feedbackElement = field.parentNode?.querySelector('.invalid-feedback')
                    || this._siblingFeedback(field.parentNode);
            }

            // 动态规则可显式指定反馈容器，覆盖上面的推断结果
            if (override.feedback) feedbackElement = override.feedback;
            
            if (isValid) {
                elements.forEach(element => {
                    element.classList.add('is-valid');
                });
                if (feedbackElement) {
                    if(this.options.feedbackPlaceholder){
                        feedbackElement.style.visibility = 'hidden';
                    }else{
                        feedbackElement.style.display = 'none';
                    }
                }
            } else {
                elements.forEach(element => {
                    element.classList.add('is-invalid');
                });
                if (feedbackElement) {
                    const textElement = feedbackElement.querySelector('.validation-text');
                    if (textElement) {
                        textElement.textContent = errorMessage;
                    }
                    if(this.options.feedbackPlaceholder){
                        feedbackElement.style.visibility = 'visible';
                    }else{
                        feedbackElement.style.display = 'flex';
                    }
                }
            }
        }
        
        // 重置表单
        resetForm() {
            const inputs = this.form.querySelectorAll('input, select, textarea');
            
            inputs.forEach(input => {
                input.classList.remove('is-valid', 'is-invalid');
                
                // 隐藏反馈消息
                const feedbackElement = this._siblingFeedback(input);
                if (feedbackElement) {
                    if(this.options.feedbackPlaceholder){
                        feedbackElement.style.visibility = 'hidden';
                    }else{
                        feedbackElement.style.display = 'none';
                    }
                }
            });
            
            var customInputs = this.form.querySelectorAll('[data-validation]');
            for (var i = 0; i < customInputs.length; i++) {
                var input = customInputs[i];
                var ruleMap = this._parseRules(input);

                // 根据规则类型重置不同的事件
                // 重置密码强度指示器与条件清单
                if(ruleMap['passwordStrength'] !== undefined){
                    var strengthIndicator = this._resolveStrengthIndicator(input);
                    if (strengthIndicator) {
                        this._paintStrengthBar(strengthIndicator, '', { score: 0 });
                        var requirements = strengthIndicator.nextElementSibling;
                        if (requirements && requirements.classList.contains('password-requirements')) {
                            requirements.innerHTML = '';
                            requirements.style.display = 'none';
                        }
                    }
                }
                // 重置字符计数
                if(ruleMap['charCount'] !== undefined && ruleMap['maxLength'] !== undefined){
                    var counterId = input.id + 'CharCount';
                    var charCounter = document.getElementById(counterId);
                    if (charCounter) {
                        charCounter.textContent = '0';
                    }
                }
                // 重置文件信息
                if(ruleMap['fileType'] !== undefined || ruleMap['fileSize'] !== undefined){
                    var infoId = input.id + 'FileInfo';
                    var fileInfo = document.getElementById(infoId);
                    if (fileInfo) {
                        fileInfo.textContent = '';
                    }
                }
            }
        }
        
        // 显示成功消息
        showSuccessMessage(message) {
            this.showMessage(message, 'success');
        }
        
        // 滚动到第一个错误字段
        scrollToFirstError() {
            var firstError = this.form.querySelector('.is-invalid');
            if (!firstError) return;

            // 如果是 radio/checkbox 组，取第一个可见的兄弟
            if (firstError.type === 'radio' || firstError.type === 'checkbox') {
                var group = this.form.querySelector('.is-invalid:checked') || firstError;
                firstError = group;
            }

            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // 延迟聚焦，避免和 scrollIntoView 冲突
            setTimeout(function () { firstError.focus(); }, 300);
        }

        // 显示错误消息
        showErrorMessage(message) {
            this.showMessage(message, 'danger');
        }
        
        // 显示消息
        showMessage(message, type) {
            const toastId = `toast-${Date.now()}`;
            const toast = document.createElement('div');
            toast.className = `toolkit-toast-container toast-container position-fixed top-0 end-0 p-3`;
            toast.innerHTML = `
                <div id="${toastId}" class="toast fade" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
                    <div class="toast-header bg-${type} text-white">
                        <strong class="me-auto">提示</strong>
                        <button type="button" class="ml-2 mb-1 btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body">${message}</div>
                </div>
            `;

            this.form.insertBefore(toast, this.form.lastElementChild);

            // 显示toast
            $(`#${toastId}`).toast('show');
            
            // 自动移除
            setTimeout(() => {
                $(`#${toastId}`).remove();
            }, 5500);
        }
        
        //绑定密码强度检测事件
        bindPasswordStrengthEvent(input, strengthIndicator, indicatorClassName, ruleParam) {
            if (!strengthIndicator) return;

            var render = () => {
                this.checkPasswordStrength(input.value, strengthIndicator, indicatorClassName, ruleParam);
            };

            input.addEventListener('input', render);
            // 初始化：回填值（如浏览器自动填充）也要显示真实状态
            render();
        }

        /**
         * 解析 passwordStrength 规则参数
         *
         * 语法：`passwordStrength:8,letter,digit`
         *   - 纯数字令牌       → 最小长度
         *   - letter|alpha     → 至少 1 个字母
         *   - upper|uppercase  → 至少 1 个大写字母
         *   - lower|lowercase  → 至少 1 个小写字母
         *   - digit|number     → 至少 1 个数字
         *   - special|symbol   → 至少 1 个特殊字符
         * 不传参数时回退到 WsFormValidator.PASSWORD_POLICY。
         *
         * @param {string} param 规则参数
         * @returns {{minLength:number,maxLength:number,require:string[]}}
         */
        _parsePasswordPolicy(param) {
            const defaults = WsFormValidator.PASSWORD_POLICY;
            const policy = {
                minLength: defaults.minLength,
                maxLength: defaults.maxLength,
                require: defaults.require.slice(),
            };

            if (!param) return policy;

            const require = [];
            String(param).split(',').forEach((raw) => {
                const token = raw.trim().toLowerCase();
                if (!token) return;

                if (/^\d+$/.test(token)) {
                    policy.minLength = parseInt(token, 10);
                } else if (token === 'letter' || token === 'alpha') {
                    require.push('letter');
                } else if (token === 'upper' || token === 'uppercase') {
                    require.push('upper');
                } else if (token === 'lower' || token === 'lowercase') {
                    require.push('lower');
                } else if (token === 'digit' || token === 'number' || token === 'num') {
                    require.push('digit');
                } else if (token === 'special' || token === 'symbol') {
                    require.push('special');
                }
            });

            // 只给了长度（passwordStrength:10）时，仍沿用默认字符要求
            if (require.length > 0) policy.require = require;

            return policy;
        }

        /**
         * 计算密码是否满足策略（校验与强度条的唯一判定入口）
         *
         * @param {string} password
         * @param {Object} [policy]
         * @returns {{valid:boolean,unmet:Array,requirements:Array,score:number}}
         */
        evaluatePassword(password, policy) {
            const value = password === null || password === undefined ? '' : String(password);
            const effectivePolicy = policy || WsFormValidator.PASSWORD_POLICY;
            const requirements = this._passwordRequirements(effectivePolicy, value);
            const unmet = requirements.filter((item) => !item.met);

            return {
                valid: unmet.length === 0,
                unmet,
                requirements,
                score: this._passwordScore(value, effectivePolicy),
            };
        }

        /** 生成逐项判定结果（顺序即「首条原因」的优先级） */
        _passwordRequirements(policy, value) {
            const requirements = [];
            const length = value.length;

            requirements.push({
                code: 'minLength',
                label: '至少 ' + policy.minLength + ' 位',
                met: length >= policy.minLength,
                message: '密码至少 ' + policy.minLength + ' 位（当前 ' + length + ' 位）',
            });

            if (policy.maxLength) {
                requirements.push({
                    code: 'maxLength',
                    label: '不超过 ' + policy.maxLength + ' 位',
                    met: length <= policy.maxLength,
                    message: '密码不能超过 ' + policy.maxLength + ' 位（当前 ' + length + ' 位）',
                });
            }

            if (policy.require.includes('letter')) {
                requirements.push({
                    code: 'letter',
                    label: '包含字母',
                    met: /[A-Za-z]/.test(value),
                    message: '需包含至少 1 个字母',
                });
            }
            if (policy.require.includes('lower')) {
                requirements.push({
                    code: 'lower',
                    label: '包含小写字母',
                    met: /[a-z]/.test(value),
                    message: '需包含至少 1 个小写字母',
                });
            }
            if (policy.require.includes('upper')) {
                requirements.push({
                    code: 'upper',
                    label: '包含大写字母',
                    met: /[A-Z]/.test(value),
                    message: '需包含至少 1 个大写字母',
                });
            }
            if (policy.require.includes('digit')) {
                requirements.push({
                    code: 'digit',
                    label: '包含数字',
                    met: /[0-9]/.test(value),
                    message: '需包含至少 1 个数字',
                });
            }
            if (policy.require.includes('special')) {
                requirements.push({
                    code: 'special',
                    label: '包含特殊字符',
                    met: /[^A-Za-z0-9]/.test(value),
                    message: '需包含至少 1 个特殊字符',
                });
            }

            requirements.push({
                code: 'whitespace',
                label: '不包含空格',
                met: !/\s/.test(value),
                message: '密码不能包含空格',
            });

            return requirements;
        }

        /** 强度分（0~5），仅用于进度条着色，不参与是否通过判定 */
        _passwordScore(password, policy) {
            let score = 0;
            if (password.length >= policy.minLength) score += 1;
            if (/[a-z]/.test(password)) score += 1;
            if (/[A-Z]/.test(password)) score += 1;
            if (/[0-9]/.test(password)) score += 1;
            if (/[^A-Za-z0-9]/.test(password)) score += 1;
            return score;
        }

        /**
         * 解析密码强度条元素
         *
         * 顺序：data-strength-target → {input.id}Strength → 同容器 .password-strength
         * （最后一项兼容历史模板中 id 与 input 不对应的情况）
         */
        _resolveStrengthIndicator(input) {
            const explicitId = input.getAttribute('data-strength-target');
            if (explicitId) {
                const explicit = document.getElementById(explicitId);
                if (explicit) return explicit;
            }

            if (input.id) {
                const byConvention = document.getElementById(input.id + 'Strength');
                if (byConvention) return byConvention;
            }

            return input.parentNode ? input.parentNode.querySelector('.password-strength') : null;
        }

        /** 首条未满足原因；允许 data-validation-message-passwordStrength 整体覆盖 */
        getPasswordStrengthMessage(field, evaluation) {
            const custom = field.getAttribute('data-validation-message-passwordStrength');
            if (custom) return custom;

            return evaluation.unmet.length > 0 ? evaluation.unmet[0].message : '密码强度不足';
        }
        
        // 绑定字符计数事件
        bindCharCountEvent(input, counterId, maxLength) {
            var charCounter = document.getElementById(counterId);
            if (!charCounter) return;
            
            input.addEventListener('input', () => {
                this.updateCharCount(input, charCounter, maxLength);
            });
            
            // 初始化字符计数
            this.updateCharCount(input, charCounter, maxLength);
        }

        // 绑定文件上传事件
        bindFileUploadEvent(input, areaId, infoId) {
            var fileUploadArea = document.getElementById(areaId);
            var fileInfo = document.getElementById(infoId);
            
            if (!fileUploadArea) return;
            
            fileUploadArea.addEventListener('click', () => {
                input.click();
            });
            
            input.addEventListener('change', () => {
                this.handleFileUpload(input, fileInfo);
            });
            
            // 拖拽上传
            fileUploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadArea.style.borderColor = '#0d6efd';
                fileUploadArea.style.backgroundColor = '#f8f9fa';
            });
            
            fileUploadArea.addEventListener('dragleave', (e) => {
                e.preventDefault();
                fileUploadArea.style.borderColor = '#dee2e6';
                fileUploadArea.style.backgroundColor = '';
            });
            
            fileUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadArea.style.borderColor = '#dee2e6';
                fileUploadArea.style.backgroundColor = '';
                
                if (e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    this.handleFileUpload(input, fileInfo);
                }
            });
        }

        // 密码强度检测（强度条 + 条件清单，与校验共用 evaluatePassword）
        checkPasswordStrength(password, strengthIndicator, indicatorClassName, ruleParam) {
            if (!strengthIndicator) return;

            const policy = this._parsePasswordPolicy(ruleParam);
            const evaluation = this.evaluatePassword(password, policy);

            this._paintStrengthBar(strengthIndicator, password, evaluation);
            this._renderPasswordChecklist(strengthIndicator, password, evaluation);
        }

        /** 绘制强度条（只做视觉反馈，颜色与 score 对应） */
        _paintStrengthBar(strengthIndicator, password, evaluation) {
            const strengthClasses = ['bg-danger', 'w-25', 'bg-warning', 'w-50', 'bg-success', 'w-75', 'bg-info', 'w-100'];
            // 先清空上一次的档位，避免 6 位→10 位时残留 bg-danger
            strengthIndicator.classList.remove(...strengthClasses);

            if (!password) {
                strengthIndicator.style.cssText = '';
                return;
            }

            strengthIndicator.style.cssText = 'height: 5px;margin-top: 0.5rem;border-radius: 3px;transition: all 0.3s;';

            const score = evaluation.score;
            if (score <= 1) {
                strengthIndicator.classList.add('bg-danger', 'w-25');
            } else if (score === 2) {
                strengthIndicator.classList.add('bg-warning', 'w-50');
            } else if (score === 3) {
                strengthIndicator.classList.add('bg-success', 'w-75');
            } else {
                strengthIndicator.classList.add('bg-info', 'w-100');
            }
        }

        /** 渲染条件清单：逐项标注是否满足，解决「只提示强度不足」的问题 */
        _renderPasswordChecklist(strengthIndicator, password, evaluation) {
            let list = strengthIndicator.nextElementSibling;
            if (!list || !list.classList.contains('password-requirements')) {
                list = document.createElement('ul');
                list.className = 'password-requirements list-unstyled small mt-1 mb-0';
                strengthIndicator.parentNode.insertBefore(list, strengthIndicator.nextSibling);
            }

            if (!password) {
                list.innerHTML = '';
                list.style.display = 'none';
                return;
            }

            list.style.display = '';
            list.innerHTML = evaluation.requirements.map((item) => {
                const cls = item.met ? 'text-success' : 'text-body-secondary';
                const icon = item.met ? 'bi-check-circle-fill' : 'bi-circle';
                return '<li class="' + cls + '"><i class="bi ' + icon + ' me-1"></i>' + item.label + '</li>';
            }).join('');
        }

        // 更新字符计数
        updateCharCount(input, charCounter, maxLength) {
            if (!charCounter) return;
            
            var currentLength = input.value.length;
            charCounter.textContent = currentLength;
            
            // 可选：当接近最大长度时改变颜色
            if (currentLength > maxLength * 1) {
                charCounter.style.color = '#dc3545';
            } else if (currentLength > maxLength * 0.7) {
                charCounter.style.color = '#fd7e14';
            } else {
                charCounter.style.color = '';
            }
        }

        // 处理文件上传
        handleFileUpload(input, fileInfo) {
            if (!fileInfo) return;
            
            if (input.files.length > 0) {
                var file = input.files[0];
                fileInfo.textContent = '已选择: ' + file.name + ' (' + this.formatFileSize(file.size) + ')';
                
                // 校验文件
                this.validateField(input);
            } else {
                fileInfo.textContent = '';
            }
        }
        
        // 辅助方法
        
        isValidEmail(email) {
            const emailRegex = WsFormValidator.REGEX.email;
            return emailRegex.test(email);
        }
        
        isValidPhone(phone) {
            const phoneRegex = WsFormValidator.REGEX.phone;
            return phoneRegex.test(phone) && phone.replace(/\D/g, '').length >= 10;
        }
        
        isValidDate(date) {
            return !isNaN(Date.parse(date));
        }
        
        isStrongPassword(password) {
            // 兼容旧调用：等价于使用默认策略（8 位 + 字母 + 数字）
            return this.evaluatePassword(password, WsFormValidator.PASSWORD_POLICY).valid;
        }
        
        parseFileSize(sizeString) {
            // 单位表必须与下方正则的 [KMGT] 一一对应。历史实现缺 TB：
            // 'fileSize:1TB' 会因 units['TB'] === undefined 退化成 value * 1（= 1 字节），
            // 于是任何文件都被判超限 —— 静默失效，不报错。
            const units = {
                'B': 1,
                'KB': 1024,
                'MB': 1024 * 1024,
                'GB': 1024 * 1024 * 1024,
                'TB': 1024 * 1024 * 1024 * 1024
            };
            
            const match = sizeString.match(/^(\d+)([KMGT]?B)$/i);
            if (match) {
                const value = parseInt(match[1]);
                const unit = match[2].toUpperCase();
                return value * (units[unit] || 1);
            }
            
            return 0;
        }
        
        formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            
            const k = 1024;
            // 与 parseFileSize 的单位表保持同步：数组越界会索引到 undefined，
            // 输出形如 "2 undefined"。TB 同时补上，二者别再各改一半。
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    }
    
// 保留全局挂载：插件业务代码通过 window.WsFormValidator / window.FormValidator 访问，存量调用零改造。
window.WsFormValidator = WsFormValidator;
window.FormValidator = WsFormValidator;

export default WsFormValidator;