/**
 * ShortLink Ajax API
 */
const $ = window.jQuery;

    'use strict';

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    var api = {
        get: function(url, data, options) {
            return this._request('GET', url, data, options);
        },
        post: function(url, data, options) {
            return this._request('POST', url, data, options);
        },
        _request: function(method, url, data, options) {
            options = options || {};
            return $.ajax({
                url: url,
                type: method,
                data: data,
                dataType: 'json',
                beforeSend: options.beforeSend || null,
                success: function(res) {
                    if (options.success) options.success(res);
                },
                error: function(xhr, status, error) {
                    var msg = '请求失败';
                    if (xhr.responseJSON && xhr.responseJSON.msg) msg = xhr.responseJSON.msg;
                    if (!options.silent) alert(msg);
                    if (options.error) options.error(xhr, status, error);
                },
                complete: options.complete || null
            });
        }
    };

export default api;
