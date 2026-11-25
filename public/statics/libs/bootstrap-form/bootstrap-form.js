/**
 * Bootstrap表单组件
 * 提供表单验证、条件字段、AJAX提交等功能
 * @version 1.0.0
 * @license MIT
 */

(function(global, factory) {
    if (typeof define === 'function' && define.amd) {
        define(['jquery'], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('jquery'));
    } else {
        global.bootstrapLayer = factory(global.jQuery);
    }
}(typeof window !== 'undefined' ? window : this, function($) {
    "use strict";

    // 检查依赖
    if (typeof $ === 'undefined') {
        throw new Error('Bootstrap Form requires jQuery');
    }
    
    /**
     * Bootstrap表单组件类
     * 封装了表单验证、条件字段显示、AJAX提交等功能
     */
    class BootstrapForm {
        /**
         * 构造函数
         * @param {string} formId - 表单元素的ID
         * @param {Object} options - 配置选项
         * @param {boolean} options.ajaxSubmit - 是否启用AJAX提交，默认true
         * @param {boolean} options.validation - 是否启用表单验证，默认true
         * @param {boolean} options.liveValidation - 是否启用实时验证，默认true
         * @param {Function} options.onSuccess - 提交成功回调函数
         * @param {Function} options.onError - 提交失败回调函数
         * @param {Function} options.beforeSubmit - 提交前回调函数
         */
        constructor(formId, options = {}) {
            /** @type {HTMLFormElement} 表单元素 */
            this.form = document.getElementById(formId);
            
            if (!this.form) {
                throw new Error(`Form with ID "${formId}" not found`);
            }
            
            /** @type {Object} 配置选项 */
            this.options = Object.assign({
                ajaxSubmit: true,
                validation: true,
                liveValidation: true,
                onSuccess: null,
                onError: null,
                beforeSubmit: null,
                successMessage: '操作成功',
                errorMessage: '操作失败',
                autoHideMessage: true,
                messageHideDelay: 5000
            }, options);
            
            /** @type {boolean} 表单是否正在提交 */
            this.isSubmitting = false;
            
            this.init();
        }
        
        /**
         * 初始化组件
         * @private
         */
        init() {
            if (this.options.validation) {
                this.setupValidation();
            }
            
            if (this.options.liveValidation) {
                this.setupLiveValidation();
            }
            
            this.setupConditionalFields();
            this.setupFormSubmit();
        }
        
        /**
         * 设置表单验证
         * @private
         */
        setupValidation() {
            this.form.setAttribute('novalidate', 'novalidate');
            
            this.form.addEventListener('submit', (e) => {
                if (!this.form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
                this.form.classList.add('was-validated');
            });
        }
        
        /**
         * 设置实时验证
         * @private
         */
        setupLiveValidation() {
            const inputs = this.form.querySelectorAll('input, select, textarea');
            
            inputs.forEach(input => {
                input.addEventListener('blur', () => {
                    this.validateField(input);
                });
                
                // 对于某些字段类型，在输入时也进行验证
                if (input.type === 'email' || input.type === 'url' || input.hasAttribute('pattern')) {
                    input.addEventListener('input', () => {
                        this.validateField(input);
                    });
                }
            });
        }
        
        /**
         * 验证单个字段
         * @param {HTMLElement} field - 表单字段元素
         * @returns {boolean} 验证是否通过
         * @public
         */
        validateField(field) {
            const isValid = field.checkValidity();
            const feedbackEl = field.parentNode.querySelector('.invalid-feedback');
            
            if (!isValid) {
                field.classList.add('is-invalid');
                field.classList.remove('is-valid');
                
                // 设置具体的错误消息
                if (field.validity.valueMissing) {
                    feedbackEl.textContent = field.dataset.requiredError || '此字段为必填项';
                } else if (field.validity.typeMismatch) {
                    if (field.type === 'email') {
                        feedbackEl.textContent = field.dataset.emailError || '请输入有效的电子邮件地址';
                    } else if (field.type === 'url') {
                        feedbackEl.textContent = field.dataset.urlError || '请输入有效的URL';
                    }
                } else if (field.validity.patternMismatch) {
                    feedbackEl.textContent = field.dataset.patternError || '格式不正确';
                } else if (field.validity.tooShort) {
                    feedbackEl.textContent = `内容至少需要 ${field.minLength} 个字符`;
                } else if (field.validity.tooLong) {
                    feedbackEl.textContent = `内容不能超过 ${field.maxLength} 个字符`;
                } else if (field.validity.rangeUnderflow) {
                    feedbackEl.textContent = `值不能小于 ${field.min}`;
                } else if (field.validity.rangeOverflow) {
                    feedbackEl.textContent = `值不能大于 ${field.max}`;
                }
            } else {
                field.classList.remove('is-invalid');
                field.classList.add('is-valid');
                if (feedbackEl) {
                    feedbackEl.textContent = '';
                }
            }
            
            return isValid;
        }
        
        /**
         * 设置条件字段
         * @private
         */
        setupConditionalFields() {
            const conditionalFields = this.form.querySelectorAll('[data-conditions]');
            
            conditionalFields.forEach(field => {
                try {
                    const conditions = JSON.parse(field.dataset.conditions);
                    this.evaluateConditions(field, conditions);
                    
                    // 监听相关字段的变化
                    conditions.forEach(condition => {
                        const targetField = this.form.querySelector(`[name="${condition.field}"]`);
                        if (targetField) {
                            targetField.addEventListener('change', () => {
                                this.evaluateConditions(field, conditions);
                            });
                            
                            // 对于文本输入框，也监听输入事件
                            if (targetField.type === 'text' || targetField.type === 'textarea' || targetField.type === 'number') {
                                targetField.addEventListener('input', () => {
                                    this.evaluateConditions(field, conditions);
                                });
                            }
                        }
                    });
                } catch (error) {
                    console.error('Invalid conditions JSON for field:', field, error);
                }
            });
        }
        
        /**
         * 评估条件并显示/隐藏字段
         * @param {HTMLElement} field - 条件字段元素
         * @param {Array} conditions - 条件数组
         * @private
         */
        evaluateConditions(field, conditions) {
            let shouldShow = true;
            
            conditions.forEach(condition => {
                const targetField = this.form.querySelector(`[name="${condition.field}"]`);
                if (!targetField) return;
                
                let value;
                if (targetField.type === 'checkbox') {
                    value = Array.from(this.form.querySelectorAll(`[name="${condition.field}"]:checked`))
                                .map(cb => cb.value);
                } else if (targetField.type === 'radio') {
                    const checked = this.form.querySelector(`[name="${condition.field}"]:checked`);
                    value = checked ? checked.value : '';
                } else {
                    value = targetField.value;
                }
                
                const conditionMet = this.checkCondition(value, condition.operator, condition.value);
                if (condition.relation === 'AND') {
                    shouldShow = shouldShow && conditionMet;
                } else if (condition.relation === 'OR') {
                    shouldShow = shouldShow || conditionMet;
                }
            });
            
            const fieldContainer = field.closest('.form-field-container') || field;
            
            if (shouldShow) {
                fieldContainer.style.display = 'block';
                fieldContainer.querySelectorAll('input, select, textarea').forEach(el => {
                    el.disabled = false;
                    el.removeAttribute('aria-hidden');
                });
                fieldContainer.removeAttribute('aria-hidden');
            } else {
                fieldContainer.style.display = 'none';
                fieldContainer.querySelectorAll('input, select, textarea').forEach(el => {
                    el.disabled = true;
                    el.setAttribute('aria-hidden', 'true');
                });
                fieldContainer.setAttribute('aria-hidden', 'true');
            }
        }
        
        /**
         * 检查条件是否满足
         * @param {*} value - 字段值
         * @param {string} operator - 操作符
         * @param {*} expected - 期望值
         * @returns {boolean} 条件是否满足
         * @private
         */
        checkCondition(value, operator, expected) {
            switch (operator) {
                case 'equals':
                    return value == expected;
                case 'not_equals':
                    return value != expected;
                case 'contains':
                    if (Array.isArray(value)) {
                        return value.includes(expected);
                    }
                    return String(value).includes(expected);
                case 'not_contains':
                    if (Array.isArray(value)) {
                        return !value.includes(expected);
                    }
                    return !String(value).includes(expected);
                case 'greater_than':
                    return parseFloat(value) > parseFloat(expected);
                case 'less_than':
                    return parseFloat(value) < parseFloat(expected);
                case 'empty':
                    return !value || (Array.isArray(value) && value.length === 0) || value.toString().trim() === '';
                case 'not_empty':
                    return !!value && (!Array.isArray(value) || value.length > 0) && value.toString().trim() !== '';
                default:
                    return false;
            }
        }
        
        /**
         * 设置表单提交事件
         * @private
         */
        setupFormSubmit() {
            this.form.addEventListener('submit', (e) => {
                if (this.options.ajaxSubmit) {
                    e.preventDefault();
                    this.submitForm();
                }
            });
        }
        
        /**
         * 提交表单
         * @returns {Promise} 提交Promise
         * @public
         */
        async submitForm() {
            if (this.isSubmitting) {
                return;
            }
            
            // 验证所有字段
            if (this.options.validation) {
                const allValid = this.validateAllFields();
                if (!allValid) {
                    this.form.classList.add('was-validated');
                    return;
                }
            }
            
            // 执行提交前回调
            if (this.options.beforeSubmit && typeof this.options.beforeSubmit === 'function') {
                const shouldContinue = this.options.beforeSubmit(this.getFormData());
                if (shouldContinue === false) return;
            }
            
            this.isSubmitting = true;
            this.setSubmitButtonState(true);
            
            try {
                const formData = new FormData(this.form);
                
                const response = await fetch(this.form.action, {
                    method: this.form.method,
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    this.handleSuccess(result);
                } else {
                    this.handleError(result);
                }
            } catch (error) {
                this.handleError({
                    message: this.options.errorMessage || '网络错误，请稍后重试',
                    error: error.message
                });
            } finally {
                this.isSubmitting = false;
                this.setSubmitButtonState(false);
            }
        }
        
        /**
         * 验证所有字段
         * @returns {boolean} 所有字段是否有效
         * @public
         */
        validateAllFields() {
            let allValid = true;
            const fields = this.form.querySelectorAll('input, select, textarea');
            
            fields.forEach(field => {
                if (!field.disabled && !this.validateField(field)) {
                    allValid = false;
                }
            });
            
            return allValid;
        }
        
        /**
         * 获取表单数据
         * @returns {Object} 表单数据对象
         * @public
         */
        getFormData() {
            const formData = new FormData(this.form);
            const data = {};
            
            for (let [key, value] of formData.entries()) {
                if (data[key]) {
                    if (!Array.isArray(data[key])) {
                        data[key] = [data[key]];
                    }
                    data[key].push(value);
                } else {
                    data[key] = value;
                }
            }
            
            return data;
        }
        
        /**
         * 处理提交成功
         * @param {Object} result - 服务器返回结果
         * @private
         */
        handleSuccess(result) {
            this.showMessage(result.message || this.options.successMessage, 'success');
            
            // 执行成功回调
            if (this.options.onSuccess && typeof this.options.onSuccess === 'function') {
                this.options.onSuccess(result, this.getFormData());
            }
            
            // 重置表单（如果需要）
            if (result.resetForm !== false) {
                this.reset();
            }
        }
        
        /**
         * 处理提交错误
         * @param {Object} result - 服务器返回结果
         * @private
         */
        handleError(result) {
            this.showMessage(result.message || this.options.errorMessage, 'error');
            
            // 字段级错误
            if (result.errors) {
                Object.keys(result.errors).forEach(fieldName => {
                    const field = this.form.querySelector(`[name="${fieldName}"]`);
                    const feedbackEl = field ? field.parentNode.querySelector('.invalid-feedback') : null;
                    
                    if (field && feedbackEl) {
                        field.classList.add('is-invalid');
                        feedbackEl.textContent = Array.isArray(result.errors[fieldName]) 
                            ? result.errors[fieldName].join(', ') 
                            : result.errors[fieldName];
                    }
                });
            }
            
            // 执行错误回调
            if (this.options.onError && typeof this.options.onError === 'function') {
                this.options.onError(result, this.getFormData());
            }
        }
        
        /**
         * 显示消息
         * @param {string} message - 消息内容
         * @param {string} type - 消息类型 (success|error|warning|info)
         * @public
         */
        showMessage(message, type = 'info') {
            // 移除现有的消息
            const existingAlert = this.form.querySelector('.form-message');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            const alertClass = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            }[type] || 'alert-info';
            
            const alert = document.createElement('div');
            alert.className = `alert ${alertClass} alert-dismissible form-message mt-3`;
            alert.setAttribute('role', 'alert');
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // 插入到表单顶部或提交按钮前
            const formActions = this.form.querySelector('.form-actions');
            if (formActions) {
                this.form.insertBefore(alert, formActions);
            } else {
                this.form.prepend(alert);
            }
            
            // 自动消失
            if (this.options.autoHideMessage && type !== 'error') {
                setTimeout(() => {
                    if (alert.parentNode) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, this.options.messageHideDelay);
            }
        }
        
        /**
         * 设置提交按钮状态
         * @param {boolean} submitting - 是否正在提交
         * @private
         */
        setSubmitButtonState(submitting) {
            const submitButtons = this.form.querySelectorAll('button[type="submit"], input[type="submit"]');
            
            submitButtons.forEach(button => {
                if (submitting) {
                    button.disabled = true;
                    const originalText = button.getAttribute('data-original-text') || button.textContent;
                    button.setAttribute('data-original-text', originalText);
                    button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 提交中...';
                } else {
                    button.disabled = false;
                    const originalText = button.getAttribute('data-original-text');
                    if (originalText) {
                        button.textContent = originalText;
                        button.removeAttribute('data-original-text');
                    }
                }
            });
        }
        
        /**
         * 动态添加字段
         * @param {Object} fieldConfig - 字段配置
         * @public
         */
        addField(fieldConfig) {
            // 实现动态添加字段的逻辑
            // 这里可以扩展实现动态字段添加功能
        }
        
        /**
         * 重置表单
         * @public
         */
        reset() {
            this.form.reset();
            this.form.classList.remove('was-validated');
            
            // 清除验证样式
            this.form.querySelectorAll('.is-valid, .is-invalid').forEach(el => {
                el.classList.remove('is-valid', 'is-invalid');
            });
            
            // 重新评估条件字段
            this.setupConditionalFields();
            
            // 移除消息
            const existingAlert = this.form.querySelector('.form-message');
            if (existingAlert) {
                existingAlert.remove();
            }
        }
        
        /**
         * 销毁组件，清理事件监听器
         * @public
         */
        destroy() {
            // 清理事件监听器
            this.form.removeEventListener('submit', this.submitHandler);
            
            // 移除实时验证监听器
            const inputs = this.form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.removeEventListener('blur', this.validateField);
                input.removeEventListener('input', this.validateField);
            });
            
            // 重置表单状态
            this.reset();
        }
    }

    return BootstrapForm;
}));