/**
 * WiseAdmin 安装向导 - JavaScript 工具函数
 * 主逻辑已内联在 index.html，此文件提供兼容回退
 */
(function($) {
    'use strict';

    // 密码强度
    $.fn.passwordStrength = function(options) {
        var settings = $.extend({
            minLength: 6,
            classes: { weak:'strength-weak', fair:'strength-fair', good:'strength-good', strong:'strength-strong' }
        }, options);

        return this.each(function() {
            var $input = $(this);
            var $meter = $('<div class="password-strength"><div class="strength-bar"></div></div>');
            var $text = $('<small class="form-text"></small>');
            $input.after($meter).after($text);

            $input.on('input', function() {
                var pwd = $(this).val();
                var s = 0;
                if (pwd.length >= 6) s++;
                if (/[a-z]/.test(pwd)) s++;
                if (/[A-Z]/.test(pwd)) s++;
                if (/[0-9]/.test(pwd)) s++;
                if (/[^a-zA-Z0-9]/.test(pwd)) s++;

                var $bar = $meter.find('.strength-bar');
                if (pwd.length === 0) { $bar.css('width','0%'); $text.text(''); return; }

                var levels = [
                    {w:20,c:settings.classes.weak,t:'很弱'},
                    {w:40,c:settings.classes.fair,t:'弱'},
                    {w:60,c:settings.classes.good,t:'一般'},
                    {w:80,c:settings.classes.good,t:'良好'},
                    {w:100,c:settings.classes.strong,t:'强'}
                ];
                var lv = levels[Math.min(s,5)-1] || levels[0];
                $bar.css('width',lv.w+'%').removeClass(Object.values(settings.classes).join(' ')).addClass(lv.c);
                $text.text('密码强度：'+lv.t);
            });
        });
    };

    // 密码强度计算（独立函数）
    window.checkPasswordStrength = function(password) {
        var score = 0;
        if (password.length >= 6) score++;
        if (/[a-z]/.test(password)) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;
        return score;
    };

    // 表单防止重复提交
    $('form').on('submit', function() {
        var $form = $(this);
        if ($form.data('submitting')) return false;
        $form.data('submitting', true);
        setTimeout(function() { $form.data('submitting', false); }, 5000);
        return true;
    });

})(jQuery);
