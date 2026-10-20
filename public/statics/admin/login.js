/**
 * admin/login.js — 登录页控制器（ESM）
 *
 * 依赖：jQuery/Bootstrap（全局 script）、
 *       FormValidator（window.WsFormValidator）、
 *       Layer（window.Layer）、Wise.util/Cookie（ESM 门面）
 */
const $ = window.jQuery;
const Layer = window.Layer;
const FormValidator = window.WsFormValidator;
const Cookie = window.Cookie;

const Controller = {
    index() {
        const captchaEnabled = window.captcha_enabled !== false;

        // 刷新验证码
        function refreshCaptcha(retryCount = 0) {
            const img = document.getElementById('captchaImg');
            const keyInput = document.getElementById('captcha-key');
            if (!img || !keyInput) return;
            const url = window.captcha_image_url + '?t=' + Date.now();

            fetch(url)
                .then(function (response) {
                    const key = response.headers.get('X-Captcha-Key');
                    if (key) keyInput.value = key;
                    return response.blob();
                })
                .then(function (blob) {
                    if (img._captchaObjectUrl) URL.revokeObjectURL(img._captchaObjectUrl);
                    img._captchaObjectUrl = URL.createObjectURL(blob);
                    img.src = img._captchaObjectUrl;
                    img.style.opacity = '1';
                })
                .catch(function () {
                    // 第一次失败：自动重试一次
                    if (retryCount < 1) {
                        return refreshCaptcha(retryCount + 1);
                    }
                    // 二次失败：清空 key，降级直接加载图片，并提示用户
                    keyInput.value = '';
                    img.src = url;
                    img.style.opacity = '0.6';
                    Layer.toast({ content: '验证码加载失败，请刷新重试', type: 'warning', time: 3000 });
                });
        }

        if (captchaEnabled) {
            $('.captcha-img').click(function () { refreshCaptcha(); });
            refreshCaptcha();
        }

        // 表单校验 + 提交
        new FormValidator('loginForm', {
            liveValidation: true,
            showIcons: true,
            feedbackPlaceholder: true,
            onSubmit(form, e) {
                const data = $(form).serialize();
                $.ajax({
                    url: window.dologin_url,
                    type: 'post',
                    data: data,
                    dataType: 'json',
                    beforeSend() {
                        $('button[type="submit"]').prop('disabled', true).text('登录中...');
                    },
                    success(res) {
                        if (res.code == 0) {
                            // 仅记住勾选偏好；真正的免登录令牌由服务端以 HttpOnly Cookie 下发
                            $('[name="keeplogin"]').prop('checked')
                                ? Cookie.set('keeplogin', '1', { days: 7, path: '/' })
                                : Cookie.delete('keeplogin', { path: '/' });
                            Layer.toast({
                                content: res.msg,
                                type: 'success',
                                time: 2000,
                                end() {
                                    if (window.self === window.top) {
                                        window.location.href = res.data.url;
                                    } else {
                                        const referer = (Wise?.util?.url?.getParam?.('referer')) || res.data.url;
                                        window.location.href = referer;
                                    }
                                },
                            });
                        } else {
                            Layer.toast({ content: res.msg, type: 'warning' });
                            // Pipeline 返回 needcaptcha:true 时，需要输入验证码
                            if (res?.data?.needcaptcha) {
                                $('[name="captcha_code"]').prop('required', true);
                            }
                            if (captchaEnabled) {
                                $('.captcha-img').click();
                                $('[name="captcha_code"]').val('');
                            }
                            $('button[type="submit"]').prop('disabled', false).text('登录');
                        }
                    },
                    error(jqXHR) {
                        const status = jqXHR.status;
                        let msg;
                        switch (status) {
                            case 401: msg = '登录凭证无效或已过期，请刷新页面重试'; break;
                            case 403: msg = '账号已被禁用或IP受限，请联系管理员'; break;
                            case 429: msg = '请求过于频繁，请稍后再试'; break;
                            case 500: msg = '服务器内部错误，请稍后重试'; break;
                            case 503: msg = '服务暂不可用，请稍后重试'; break;
                            default:  msg = '网络错误，请检查网络连接后重试'; break;
                        }
                        Layer.toast({ content: msg, type: 'warning', time: 4000 });
                        $('button[type="submit"]').prop('disabled', false).text('登录');
                    },
                });
            },
        });

        // 密码可见性切换
        $('#togglePassword').on('click', function () {
            var input = document.getElementById('loginPassword');
            var icon = this.querySelector('i');
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
            this.setAttribute('aria-label', isPassword ? '隐藏密码' : '显示密码');
        });
    },
};

export default Controller;
