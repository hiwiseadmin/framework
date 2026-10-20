import api from '../common/api';
const $ = window.jQuery;

    'use strict';

    export default {
        form() {
            // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
            const validator = new window.WsFormValidator('link-form', { liveValidation: true });

            $('#link-form').on('submit', function(e) {
                e.preventDefault();

                // 字段级校验交由 WsFormValidator（错误内联到字段，不再弹 alert）
                if (validator.validateForm() !== true) return;

                var data = $(this).serializeArray().reduce((obj, item) => { obj[item.name] = item.value; return obj; }, {});
                api.post('/plugin/shortlink/link/save', data, {
                    success(res) {
                        // 该分支走插件自有的 api 封装，不受内核统一错误处理覆盖，需自行判定
                        if (res.code === 200) {
                            window.Wise.ui.toast.success('保存成功');
                            setTimeout(function () { window.location.href = '/plugin/shortlink/link/index'; }, 600);
                        } else {
                            window.Wise.ui.toast.error(res.msg || '保存失败');
                        }
                    }
                });
            });
        }
    };
