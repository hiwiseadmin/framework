/**
 * theme-init.js — 明暗主题引导（必须在样式表之后、渲染之前同步执行）
 *
 * 放在 <head> 中同步加载：浏览器解析到同步脚本时会先执行再继续渲染，
 * 因此首帧即为正确主题，不会出现「浅色闪一下变深色」。
 *
 * 取值优先级：localStorage 记忆 → 系统偏好（prefers-color-scheme）→ 浅色。
 * 同时写入 <html data-bs-theme>，让 Bootstrap 弹层（modal / dropdown）跟随明暗。
 */
(function () {
    'use strict';

    var KEY = 'wisedocs-theme';
    var theme = null;

    try {
        theme = window.localStorage.getItem(KEY);
    } catch (e) {
        theme = null;
    }

    if (theme !== 'dark' && theme !== 'light') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    var root = document.documentElement;
    root.classList.toggle('dark', theme === 'dark');
    root.setAttribute('data-bs-theme', theme === 'dark' ? 'dark' : 'light');
    root.setAttribute('data-wd-theme', theme);
})();
