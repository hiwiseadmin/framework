/**
 * wise-member 默认主题（default）前端脚本
 *
 * 极简、无依赖：只做「主题级」的通用增强，不放业务逻辑
 * （业务交互应由各页面/插件自己负责）。
 *
 * 发布链路：theme/default/assets/js/theme.js
 *        → public/statics/member/themes/default/js/theme.js（wise:vendor:publish）
 * 模板引用：<script src="__THEME__/js/theme.js" defer></script>
 */
(function () {
    'use strict';

    /**
     * 表单重复提交防护：提交后禁用提交按钮，避免网络抖动导致重复提交
     * （会员签到、邀请领取等写操作对重复提交敏感）
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || form.dataset.noGuard === '1') {
            return;
        }

        window.setTimeout(function () {
            var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

            Array.prototype.forEach.call(buttons, function (button) {
                button.disabled = true;
                button.dataset.guarded = '1';
            });

            // 返回/缓存恢复时复原，避免用户返回后按钮永久禁用
            window.setTimeout(function () {
                Array.prototype.forEach.call(buttons, function (button) {
                    if (button.dataset.guarded === '1') {
                        button.disabled = false;
                        delete button.dataset.guarded;
                    }
                });
            }, 8000);
        }, 0);
    });

    /**
     * 带 data-confirm 的元素在点击前二次确认（主题级通用能力）
     */
    document.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        var holder = target.closest('[data-confirm]');

        if (!holder) {
            return;
        }

        if (!window.confirm(holder.getAttribute('data-confirm') || '确认执行该操作？')) {
            event.preventDefault();
            event.stopPropagation();
        }
    });
})();
