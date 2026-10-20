/**
 * SmartStock Ajax API — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
/**
 * SmartStock Ajax 请求封装
 *
 * 功能：
 * - 全局 CSRF 令牌设置
 * - 统一错误处理
 * - 统一返回格式处理
 *
 * 使用方式：
 *   define(['smartstock/common/api'], function(api) { ... });
 */

    'use strict';

    // 全局 Ajax 设置
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    var api = {
        /**
         * GET 请求
         * @param {string} url 请求地址
         * @param {object} data 请求参数
         * @param {object} options 额外选项
         * @returns {Promise}
         */
        get: function(url, data, options) {
            return this._request('GET', url, data, options);
        },

        /**
         * POST 请求
         * @param {string} url 请求地址
         * @param {object} data 请求参数
         * @param {object} options 额外选项
         * @returns {Promise}
         */
        post: function(url, data, options) {
            return this._request('POST', url, data, options);
        },

        /**
         * 统一请求方法
         */
        _request: function(method, url, data, options) {
            options = options || {};
            var config = {
                url: url,
                type: method,
                data: data,
                dataType: 'json',
                beforeSend: options.beforeSend || null,
                success: function(res) {
                    if (options.success) {
                        options.success(res);
                    }
                },
                error: function(xhr, status, error) {
                    var msg = '网络请求失败';
                    if (xhr.responseJSON && xhr.responseJSON.msg) {
                        msg = xhr.responseJSON.msg;
                    }
                    if (!options.silent) {
                        alert(msg);
                    }
                    if (options.error) {
                        options.error(xhr, status, error);
                    }
                },
                complete: options.complete || null
            };

            return $.ajax(config);
        }
    };
export default api;
