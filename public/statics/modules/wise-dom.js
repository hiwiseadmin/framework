/**
 * Wise.dom — DOM 操作封装
 * 提供安全元素创建、微型模板引擎、事件委托等
 *
 * @module wise-dom
 * @version 1.0.0
 * @license MIT
 */

import { codec } from './wise-util.js';

// ============================================================
// 元素创建
// ============================================================

/**
 * 安全创建元素（HTML 自动转义防 XSS）
 * @param {string} tag - 标签名
 * @param {Object} [attrs] - 属性对象。特殊键：
 *   - html: 内部 HTML（不转义，慎用）
 *   - text: 文本内容（安全转义）
 *   - class: 类名（字符串或数组）
 *   - style: 样式（字符串或对象）
 * @param {...(string|Node)} children - 子元素
 * @returns {HTMLElement}
 */
function create(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);

    for (const [key, val] of Object.entries(attrs)) {
        if (val === null || val === undefined) continue;

        switch (key) {
            case 'text':
                el.textContent = val;
                break;
            case 'html':
                el.innerHTML = val;
                break;
            case 'class':
                if (Array.isArray(val)) el.classList.add(...val.filter(Boolean));
                else el.className = val;
                break;
            case 'style':
                if (typeof val === 'object') Object.assign(el.style, val);
                else el.style.cssText = val;
                break;
            default:
                if (key.startsWith('on') && typeof val === 'function') {
                    el.addEventListener(key.slice(2).toLowerCase(), val);
                } else if (key.startsWith('data-')) {
                    el.setAttribute(key, val);
                } else {
                    el.setAttribute(key, val);
                }
        }
    }

    for (const child of children) {
        if (child == null) continue;
        if (typeof child === 'string') {
            el.appendChild(document.createTextNode(codec.htmlEscape(child)));
        } else if (child instanceof Node) {
            el.appendChild(child);
        } else {
            el.appendChild(document.createTextNode(String(child)));
        }
    }

    return el;
}

// ============================================================
// 微型模板引擎
// ============================================================

/**
 * 模板渲染
 * @param {string} template - 'Hello {{name}}, age: {{age}}!'
 * @param {Object} data
 * @returns {string} HTML 转义后的结果
 */
function render(template, data = {}) {
    return template.replace(/\{\{(.+?)\}\}/g, (_, key) => {
        const val = key.trim().split('.').reduce((o, k) => (o || {})[k], data);
        return val != null ? codec.htmlEscape(String(val)) : '';
    });
}

/**
 * 模板渲染（不转义 HTML，慎用）
 * @param {string} template
 * @param {Object} data
 * @returns {string}
 */
function renderRaw(template, data = {}) {
    return template.replace(/\{\{(.+?)\}\}/g, (_, key) => {
        const val = key.trim().split('.').reduce((o, k) => (o || {})[k], data);
        return val != null ? String(val) : '';
    });
}

// ============================================================
// 属性操作辅助
// ============================================================

function attr(el, attrs) {
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null) el.removeAttribute(k);
        else el.setAttribute(k, v);
    }
}

function addClass(el, ...classes) {
    el.classList.add(...classes.filter(Boolean));
}

function removeClass(el, ...classes) {
    el.classList.remove(...classes.filter(Boolean));
}

function toggleClass(el, cls) {
    el.classList.toggle(cls);
}

function data(el, key, value) {
    if (arguments.length === 2) {
        return el.dataset[key];
    }
    el.dataset[key] = value;
}

// ============================================================
// 几何 & 滚动
// ============================================================

/**
 * 滚动到元素
 * @param {HTMLElement|string} el
 * @param {Object} [opts] - { behavior, block }
 */
function scrollTo(el, opts = {}) {
    const target = typeof el === 'string' ? document.querySelector(el) : el;
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start', ...opts });
}

/**
 * 元素是否在视口内
 * @param {HTMLElement} el
 * @param {number} [threshold=0]
 * @returns {boolean}
 */
function isInViewport(el, threshold = 0) {
    const rect = el.getBoundingClientRect();
    const vh = window.innerHeight || document.documentElement.clientHeight;
    const vw = window.innerWidth || document.documentElement.clientWidth;
    return (
        rect.top < vh - threshold &&
        rect.bottom > threshold &&
        rect.left < vw - threshold &&
        rect.right > threshold
    );
}

/**
 * 获取元素相对于文档的位置
 * @param {HTMLElement} el
 * @returns {{top:number, left:number}}
 */
function offset(el) {
    const rect = el.getBoundingClientRect();
    return {
        top: rect.top + window.scrollY,
        left: rect.left + window.scrollX,
    };
}

// ============================================================
// 事件委托
// ============================================================

/**
 * 事件委托
 * @param {HTMLElement|string} parent - 父容器
 * @param {string} selector - 子元素 CSS 选择器
 * @param {string} eventName
 * @param {Function} handler - 回调参数 (targetEl, event)
 * @returns {Function} 取消委托的函数
 */
function delegate(parent, selector, eventName, handler) {
    const root = typeof parent === 'string' ? document.querySelector(parent) : parent;
    if (!root) return () => {};

    const listener = function (e) {
        let target = e.target;
        while (target && target !== root) {
            if (target.matches(selector)) {
                handler.call(target, target, e);
                return;
            }
            target = target.parentElement;
        }
    };

    root.addEventListener(eventName, listener);
    return () => root.removeEventListener(eventName, listener);
}

// ============================================================
// 导出
// ============================================================
export { create, render, renderRaw, attr, addClass, removeClass, toggleClass, data, scrollTo, isInViewport, offset, delegate };
