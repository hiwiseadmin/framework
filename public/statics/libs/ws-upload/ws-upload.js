// 样式：ws-upload.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-upload/ws-upload.css">

// jQuery 走全局单例：本组件是 jQuery 插件，若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
if (typeof $ === 'undefined') {
    throw new Error('WsUpload requires jQuery');
}

if (typeof window.bootstrap === 'undefined') {
    console.warn('WsUpload: Bootstrap 5 is recommended for full functionality');
}

import { parseDataConfig, pickData, getByPath, toElements, T } from '../ws-common/ws-common.js';

/**
 * 派发**原生** input / change 事件。
 *
 * 不能用 `$(el).trigger('input').trigger('change')`：jQuery 的 trigger 只走 jQuery
 * 自己的事件系统，`HTMLInputElement` 上没有 `input()` / `change()` 方法，jQuery 也就
 * 不会补一次 `dispatchEvent`，元素上的 `addEventListener('input'|'change', ...)` 收不到。
 * ws-form-validator 的实时校验正是原生绑定 —— 用 jQuery trigger 会表现为
 * 「传完文件必填红框不消失」「清空后不报错，要点提交才发现」。
 *
 * 原生 dispatchEvent 同时命中 jQuery 的 `.on('input')` / `.on('change')`（jQuery 内部
 * 也是原生监听），所以存量 jQuery 写法无需改动。与 ws-date-picker /
 * ws-color-picker / ws-linkage-select 的做法保持一致。
 *
 * @param {jQuery|HTMLElement} target
 * @param {string[]} [types=['input', 'change']]
 */
function emitNative(target, types) {
    const el = target && target.jquery ? target[0] : target;
    if (!el || typeof el.dispatchEvent !== 'function') return;
    (types || ['input', 'change']).forEach(function (type) {
        el.dispatchEvent(new Event(type, { bubbles: true }));
    });
}

    // ── 上传地址 ──────────────────────────────────────────────
    // 框架后端的默认上传接口。取值优先级（高 → 低）：
    //   1. 元素属性 data-url / data-remote-url / data-action
    //   2. WsUpload.setDefaultUrl('...')            （运行时改一次，全局生效）
    //   3. window.__WS_CONFIG__.upload.url          （后端 config/wise-attachment.php 注入）
    //   4. DEFAULT_UPLOAD_URL                       （框架约定，与 app\common\controller\Attachment::upload 对齐）
    var DEFAULT_UPLOAD_URL = '/api/attachment/upload';

    // 运行时覆盖（setDefaultUrl 设置）
    var runtimeDefaultUrl = null;

    /**
     * 解析当前生效的默认上传地址
     * @returns {string}
     */
    function resolveDefaultUrl() {
        if (runtimeDefaultUrl) return runtimeDefaultUrl;

        // 后端注入：base.html 输出 window.__WS_CONFIG__ = {...upload:{url}}
        try {
            var injected = window.__WS_CONFIG__
                && window.__WS_CONFIG__.upload
                && window.__WS_CONFIG__.upload.url;
            if (injected) return String(injected);
        } catch (e) {
            // 非框架环境，忽略
        }

        // 框架配置中心（若已初始化）
        try {
            var cfg = window.Wise && window.Wise.config;
            if (cfg && typeof cfg.get === 'function') {
                var configured = cfg.get('uploadUrl');
                if (configured) return String(configured);
            }
        } catch (e) {
            // 忽略
        }

        return DEFAULT_UPLOAD_URL;
    }

    /**
     * data-* 白名单（`data-role="wsupload"` 声明式初始化用）。
     *
     * 与表单集成相关的四个键是本次新增的核心：
     * - `data-name`           提交字段名 → 生成隐藏载体（多文件时自动变成 `name[]`）
     * - `data-value`          初始已上传路径（字符串或 JSON 数组）
     * - `data-response-field` 从上传响应中提取路径的字段路径
     * - `data-json-value`     多值改为"单字段塞 JSON 数组串"（默认 false = 原生 name[] 多值）
     */
    var UPLOAD_SCHEMA = {
        name: T.RAW,
        value: T.JSON,
        responseField: T.RAW,
        jsonValue: T.BOOL,
        url: T.RAW,
        method: T.RAW,
        field: T.RAW,
        multiple: T.BOOL,
        accept: T.RAW,
        exts: T.RAW,
        maxSize: T.NUM,
        maxFiles: T.NUM,
        autoUpload: T.BOOL,
        theme: T.RAW,
        previewType: T.RAW,
        listLayout: T.RAW,
        showPreview: T.BOOL,
        showFileList: T.BOOL,
        showStatistics: T.BOOL,
        showActions: T.BOOL,
        dragDrop: T.BOOL,
        placeholder: T.RAW,
        onSuccess: T.FN,
        onError: T.FN,
        onComplete: T.FN,
        onSelect: T.FN,
        onRemove: T.FN,
        onProgress: T.FN,
    };

    // 默认配置
    var DEFAULTS = {
        // 基础配置
        // 上传接口URL。默认指向框架后端 /api/attachment/upload，
        // 单组件可用 data-url 覆盖，全局可用 WsUpload.setDefaultUrl() 或后端配置覆盖。
        url: DEFAULT_UPLOAD_URL,
        method: 'post',             // 请求方法 可选：post, put
        field: 'file',              // 上传文件的字段名
        multiple: false,            // 是否支持多文件上传
        accept: 'file',             // 接受的文件类型
        acceptMime: '',             // 接受的MIME类型
        exts: '',                   // 允许的文件扩展名
        maxSize: 0,                 // 最大文件大小（字节）
        maxFiles: 0,                // 最大上传文件数
        autoUpload: true,           // 是否自动上传
        chunkedUpload: false,       // 是否启用分块上传
        chunkSize: 1024 * 1024,     // 分块上传大小（字节）
        retryAttempts: 3,           // 上传失败重试次数
        parallelUploads: 3,         // 并行上传数
        
        // 外观配置
        theme: 'default',       // 主题样式 可选：default, drag, minimal
        showPreview: true,      // 是否显示预览
        showFileList: true,     // 是否显示文件列表
        previewType: 'list',    // 预览类型（list 或 grid）
        listLayout: '',         // 列表版式：''(默认展开) | 'compact'(紧凑表格行) | 'mini-queue'(上传中收进迷你队列)
        dragDrop: true,         // 是否启用拖拽上传
        showStatistics: true,   // 是否显示上传统计
        showActions: true,      // 是否显示操作按钮

        // 操作按钮配置 未实现
        showAttach: true,       // 是否显示选择附件按钮
        showCancel: true,       // 是否显示取消按钮
        showDelete: true,       // 是否显示删除按钮
        showRetry: true,        // 是否显示重试按钮
        
        // 文本配置
        browseText: '<i class="bi bi-cloud-arrow-up"></i> 选择文件',  // 选择文件按钮文本
        attachText: '选择附件', // 选择附件按钮文本
        dragText: '拖拽文件到此处或<u>点击选择文件</u>', // 拖拽上传提示文本
        dropText: '释放文件上传',  // 释放文件上传提示文本
        uploadText: '开始上传',   // 开始上传按钮文本
        cancelText: '取消上传',   // 取消上传按钮文本
        deleteText: '删除',       // 删除按钮文本
        clearText: '清空列表',    // 清空列表按钮文本
        retryText: '重试',        // 重试按钮文本
        
        // 验证消息
        messages: {
            maxFiles: '最多只能上传 {maxFiles} 个文件',
            maxSize: '文件大小不能超过 {maxSize}',
            invalidExt: '不支持的文件格式: {file}',
            invalidType: '不支持的文件类型: {file}',
            uploadFailed: '上传失败: {file}',
            uploadSuccess: '上传成功: {file}',
            networkError: '网络错误，请检查连接',
            serverError: '服务器错误: {status}'
        },
        
        // 回调函数
        onInit: null,           // 初始化时调用
        onSelect: null,         // 选择文件时调用
        onRemove: null,         // 删除文件时调用
        beforeUpload: null,     // 上传前调用
        onProgress: null,       // 上传进度时调用
        onSuccess: null,        // 上传成功时调用
        onError: null,          // 上传失败时调用
        onComplete: null,       // 上传完成时调用
        onChunkUpload: null,    // 分块上传时调用
        onChunkSuccess: null,   // 分块上传成功时调用
        onChunkError: null,     // 分块上传失败时调用
        
        // 额外数据
        data: {},
        headers: {},
        
        // 服务器响应配置
        responseType: 'json',    // 服务器响应类型
        successField: 'success', // 成功标识字段名
        messageField: 'message', // 消息字段名
        dataField: 'data',       // 数据字段名

        // ── 表单集成（隐藏值载体）──────────────────────────
        /**
         * 提交字段名。设置后组件会自动生成 `<input type="hidden" class="ws-carrier">`，
         * 使上传结果能被 `$(form).serialize()` / `FormData` 收集。
         * 不设则组件纯上传，不参与表单提交（保持历史行为）。
         */
        name: null,
        /** 初始已上传路径：字符串或数组（编辑页回填） */
        value: null,
        /**
         * 从上传响应中提取「文件路径」的字段路径，支持点号（`data.url`）。
         * 响应经 `dataField` 解包后仍取不到时会自动尝试去掉 `data.` 前缀再取一次。
         */
        responseField: 'data.url',
        /**
         * 多值时是否以 JSON 数组写入**单个**隐藏域。
         *
         * 默认 false —— 走原生语义：一个值一个 `name[]` 隐藏域，
         * 提交结果 `images[]=a&images[]=b`，与 `<input type="file" multiple name="images[]">`
         * 以及 DataFilter::toArrayValue() 完全对齐。
         * 需要"单字段塞 JSON 串"的老约定时显式传 true。
         */
        jsonValue: false,
        /** 非 JSON 模式下的多值分隔符（已不参与多值载体，仅保留兼容） */
        valueSeparator: ',',
    };

    // 文件类型映射
    var FILE_TYPES = {
        images: {
            mime: 'image/*',
            exts: 'jpg,jpeg,png,gif,bmp,webp,svg',
            icon: 'bi bi-file-image',
            color: '#198754'
        },
        video: {
            mime: 'video/*',
            exts: 'mp4,avi,mov,wmv,flv,mkv,webm',
            icon: 'bi bi-file-play',
            color: '#dc3545'
        },
        audio: {
            mime: 'audio/*',
            exts: 'mp3,wav,ogg,flac,aac,m4a',
            icon: 'bi bi-file-music',
            color: '#6f42c1'
        },
        document: {
            mime: 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain',
            exts: 'pdf,doc,docx,txt,rtf',
            icon: 'bi bi-file-text',
            color: '#fd7e14'
        },
        archive: {
            mime: 'application/zip,application/x-rar-compressed,application/x-7z-compressed',
            exts: 'zip,rar,7z,tar,gz',
            icon: 'bi bi-file-zip',
            color: '#6c757d'
        },
        file: {
            mime: '*/*',
            exts: '',
            icon: 'bi bi-file-earmark',
            color: '#0d6efd'
        }
    };

    // 文件图标映射
    var FILE_ICONS = {
        pdf: 'bi bi-file-pdf text-danger',
        doc: 'bi bi-file-word text-primary',
        docx: 'bi bi-file-word text-primary',
        txt: 'bi bi-file-text text-secondary',
        rtf: 'bi bi-file-text text-info',
        zip: 'bi bi-file-zip text-warning',
        rar: 'bi bi-file-zip text-warning',
        '7z': 'bi bi-file-zip text-warning',
        tar: 'bi bi-file-zip text-warning',
        gz: 'bi bi-file-zip text-warning',
        js: 'bi bi-file-code text-warning',
        html: 'bi bi-file-code text-danger',
        css: 'bi bi-file-code text-primary',
        json: 'bi bi-file-code text-warning',
        xml: 'bi bi-file-code text-success',
        mp4: 'bi bi-file-play text-danger',
        avi: 'bi bi-file-play text-primary',
        mov: 'bi bi-file-play text-success',
        mp3: 'bi bi-file-music text-primary',
        wav: 'bi bi-file-music text-info'
    };

    /**
     * WsUpload 主构造函数
     * @param {Element} element - DOM 元素
     * @param {Object} options - 配置选项
     */
    var WsUpload = function (element, options) {
        this.element = $(element);
        this.options = $.extend(true, {}, DEFAULTS, options);

        // 未显式给 url 时走解析链（运行时覆盖 > 后端注入 > 框架默认），
        // 而不是直接用 DEFAULTS 里的常量 —— 否则 setDefaultUrl / 后端配置对
        // `new WsUpload(el, {...})` 这种直接构造的用法不生效。
        if (!(options && options.url)) {
            this.options.url = resolveDefaultUrl();
        }

        this.files = []; // 文件列表
        // 多值「额外载体」：多选走原生 name[] 语义时，一个值一个隐藏域，
        // 主载体承载第 1 个，其余放这里（与 ws-select 同一套实现）。
        this._extraCarriers = [];
        this.uploadQueue = []; // 上传队列
        this.uploading = false; // 上传状态
        this.activeUploads = 0; // 活跃上传数
        this.completedUploads = 0; // 完成上传数
        this.failedUploads = 0; // 失败上传数
        this.xhrPool = []; // XHR 请求池
        // 迷你队列（listLayout='mini-queue'）：上传中文件收进一个浮动迷你条，几乎不占表单位置
        this.$miniQueue = null;   // 迷你队列整体（fixed 右下）
        this._miniOpen = false;   // 迷你队列是否展开

        this.init();
    };

    WsUpload.prototype = {
        constructor: WsUpload,
        
        /**
         * 初始化组件
         */
        init: function () {
            this.values = [];   // 已确认的文件路径（初始值 + 上传成功），是提交值的唯一来源

            this.render();
            this.bindEvents();
            this.updateStatistics();

            // 表单集成：载体必须在 render() 之后建。
            // render() 会重写 element 的 innerHTML，提前建的隐藏域会被清掉
            // （ws-select 历史上就踩过这个坑：hidden 被 empty() 清掉，字段值静默丢失）。
            this.createCarrier();

            // 编辑页回填：把已有路径渲染成「已存在」条目
            var initial = this.options.value;
            if (initial !== null && initial !== undefined && initial !== '') {
                this.setValues(Array.isArray(initial) ? initial : [initial]);
            }

            // 触发初始化回调
            if (typeof this.options.onInit === 'function') {
                this.options.onInit.call(this);
            }
        },

        /* ============================================================
         * 表单集成（隐藏值载体）
         * ============================================================ */

        /**
         * 创建/复取隐藏值载体（幂等）。
         *
         * 载体是上传结果进入表单的**唯一通道**：没有它 `serialize()` / `FormData`
         * 拿不到任何东西（组件外层原本只有一个 file input，且其值不含已上传路径）。
         */
        createCarrier: function () {
            var name = this.options.name;
            this.carrier = null;

            // 重建载体时旧影子一并清掉，避免换字段名后残留
            this._removeExtraCarriers();

            if (!name) return;

            var carrier = this.element.children('[data-ws-carrier]').first();

            if (!carrier.length) {
                carrier = $('<input type="hidden" class="ws-carrier">');
                carrier.attr('data-ws-carrier', 'hidden');
                this.element.append(carrier);
            }

            carrier.attr('name', this.resolveCarrierName(name));

            // 校验属性迁移到载体：ws-form-validator 按 [name][data-validation] 找规则，
            // 而上传组件的真值全在载体上；宿主 div 上保留一份会被当成无名目标空跑。
            var hostEl = this.element[0];
            var self = this;

            ['data-validation', 'data-required'].forEach(function (attr) {
                if (hostEl.hasAttribute(attr)) {
                    carrier.attr(attr, hostEl.getAttribute(attr));
                    hostEl.removeAttribute(attr);
                }
            });
            Array.prototype.slice.call(hostEl.attributes).forEach(function (attr) {
                if (attr.name.indexOf('data-validation-message-') === 0) {
                    carrier.attr(attr.name, attr.value);
                    hostEl.removeAttribute(attr.name);
                }
            });

            this.carrier = carrier;
            this.syncCarrier(true);
        },

        /**
         * 把 `this.values` 同步到载体并派发变更事件。
         *
         * @param {boolean} [silent=false] 为 true 时不派发事件（仅用于初始化）
         */
        /**
         * 载体 name 的最终形态
         *
         * 与 ws-select 保持同一套语义：
         *   单值  → `name="cover"`        → `cover=a`
         *   多值  → `name="images[]"` × N → `images[]=a&images[]=b`
         *   JSON 模式（显式 `data-json-value="true"`）→ 单字段塞 JSON 数组串
         *
         * @param {string} name 基础字段名
         * @returns {string}
         */
        resolveCarrierName: function (name) {
            if (!name) return name;
            if (!this.options.multiple || this.options.jsonValue) return name;
            return /\[\]$/.test(name) ? name : name + '[]';
        },

        /**
         * 是否走「原生多值」语义（多文件且非 JSON 模式）
         * @returns {boolean}
         */
        isNativeMultiValue: function () {
            return !!this.options.multiple && !this.options.jsonValue;
        },

        syncCarrier: function (silent) {
            if (!this.carrier || !this.carrier.length) return;

            if (this.isNativeMultiValue()) {
                this._writeMultiCarriers(this.values);
            } else {
                var value = this.options.multiple
                    ? JSON.stringify(this.values)
                    : (this.values.length ? this.values[0] : '');

                this.carrier.prop('disabled', false).val(value);
            }

            if (silent) return;

            // 通知表单：ws-form-validator 的实时校验绑的是**原生** input/change，
            // 必须 dispatchEvent（jQuery trigger 不产生原生事件，详见 emitNative 注释）。
            emitNative(this.carrier, ['input', 'change']);

            // 自定义事件仍走 jQuery trigger：保留 `function (e, data)` 的二参写法，
            // 存量外部监听零改动（原生 CustomEvent 的 detail 语义不同）。
            this.element.trigger('ws-field:change', [{ name: this.options.name, value: this.values.slice() }]);
        },

        /**
         * 多值写入：主载体承载第 1 个路径，其余每个路径一个同 name 隐藏域。
         *
         * 提交结果即 `images[]=a&images[]=b`，后端 `$_POST['images']` 直接是数组，
         * 与原生 `<input type="file" multiple name="images[]">`、
         * 以及 `DataFilter::toArrayValue()` 的处理完全对齐。
         *
         * 一个文件都没有时把主载体 `disabled` —— disabled 控件不参与提交，
         * 等价于原生"没选文件就不发这个字段"，避免塞一个空的 `images[]=`。
         *
         * @param {string[]} values
         */
        _writeMultiCarriers: function (values) {
            var $carrier = this.carrier;
            var name = $carrier.attr('name');

            var list = (values || [])
                .map(function (v) { return String(v); })
                .filter(function (v) { return v !== ''; });

            if (!list.length) {
                $carrier.val('').prop('disabled', true);
                this._removeExtraCarriers();
                return;
            }

            $carrier.prop('disabled', false).val(list[0]);

            // 数量每次都变，直接重建影子比增量维护更不容易出错
            this._removeExtraCarriers();

            var anchor = $carrier;
            for (var i = 1; i < list.length; i++) {
                var $extra = $('<input type="hidden" class="ws-carrier" data-ws-carrier="extra">')
                    .attr('name', name)
                    .val(list[i]);

                $extra.insertAfter(anchor);
                this._extraCarriers.push($extra);
                anchor = $extra;
            }
        },

        /**
         * 移除全部影子载体
         */
        _removeExtraCarriers: function () {
            if (!this._extraCarriers || !this._extraCarriers.length) {
                this._extraCarriers = [];
                return;
            }
            this._extraCarriers.forEach(function ($el) {
                if ($el && $el.length) $el.remove();
            });
            this._extraCarriers = [];
        },

        /**
         * 从上传响应中提取文件路径。
         *
         * @param {*} data     经 `dataField` 解包后的数据
         * @param {*} response 原始响应
         * @returns {string} 取不到时返回 ''
         */
        extractPath: function (data, response) {
            var field = this.options.responseField;
            if (!field) return '';

            // 响应可能已被 dataField 解包（`{code,data:{url}}` → `{url}`），
            // 所以同一个路径要在解包前后各试一次，并兼容带 `data.` 前缀的写法
            var candidates = [field];
            if (String(field).indexOf('data.') === 0) candidates.push(String(field).slice(5));

            var value;
            for (var i = 0; i < candidates.length; i++) {
                value = getByPath(data, candidates[i]);
                if (value === undefined || value === null || value === '') {
                    value = getByPath(response, candidates[i]);
                }
                if (value !== undefined && value !== null && value !== '') break;
            }

            if (value !== undefined && value !== null && typeof value === 'object') {
                value = value.url || value.path || value.src || '';
            }

            return value === undefined || value === null ? '' : String(value);
        },

        /**
         * 设置已上传文件路径（编辑页回填 / 程序化赋值）。
         *
         * `addFiles()` 只接受 File 对象，无法用历史路径回填，因此单独提供本方法：
         * 已有文件以「已存在」条目渲染，不上传、不可预览进度，但计入提交值。
         *
         * @param {string|string[]} paths
         * @returns {WsUpload}
         */
        setValues: function (paths) {
            var list = Array.isArray(paths) ? paths : [paths];

            this.values = list
                .filter(function (p) { return p !== null && p !== undefined && String(p) !== ''; })
                .map(String);

            if (this.previewContainer && this.previewContainer.length) {
                this.previewContainer.find('[data-existing-index]').remove();
            }

            if (this.options.showFileList && this.previewContainer) {
                this.values.forEach(function (path, index) {
                    this.renderExistingItem(path, index);
                }.bind(this));
            }

            this.syncCarrier();
            this.updateStatistics();
            return this;
        },

        /** 读取当前已上传路径（与 getUploadedFiles 的关系：本方法只看提交值） */
        getValues: function () {
            return this.values.slice();
        },

        /**
         * 渲染「已存在」文件条目（回填用，与 renderFileItem 的视觉结构保持一致）。
         */
        renderExistingItem: function (path, index) {
            var self = this;
            var name = String(path).split('/').pop().split('?')[0] || String(path);
            var isImage = /\.(png|jpe?g|gif|webp|svg|bmp)$/i.test(name);

            var item = $(
                '<div class="ws-upload-file-item" data-existing-index="' + index + '">' +
                '<div class="ws-upload-file-icon"><i class="' + (isImage ? 'bi bi-file-image' : 'bi bi-file-earmark') + '"></i></div>' +
                '<div class="ws-upload-file-info">' +
                '<div class="ws-upload-file-name" title="' + this.escapeHtml(String(path)) + '">' + this.escapeHtml(name) + '</div>' +
                '<div class="ws-upload-status"><i class="bi bi-check-circle text-success"></i> 已存在</div>' +
                '</div>' +
                '<div class="ws-upload-file-actions">' +
                (this.options.showPreview
                    ? '<a href="' + this.escapeHtml(String(path)) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary existing-preview-btn" title="查看"><i class="bi bi-eye"></i></a>'
                    : '') +
                '<button type="button" class="btn btn-sm btn-outline-danger existing-remove-btn" title="移除"><i class="bi bi-trash"></i></button>' +
                '</div>' +
                '</div>'
            );

            item.find('.existing-remove-btn').on('click', function () {
                var idx = self.values.indexOf(String(path));
                if (idx !== -1) self.values.splice(idx, 1);
                item.remove();
                self.syncCarrier();
            });

            this.previewContainer.append(item);
        },
        
        /**
         * 渲染组件UI
         */
        render: function () {
            var options = this.options;
            var html = '';
            
            // 根据主题渲染不同的UI
            if (options.theme === 'default') {
                html = `
                    <div class="ws-upload-container">
                        <div class="d-flex gap-2 align-items-center mb-3">
                            <button type="button" class="btn btn-primary browse-btn">${options.browseText}</button>
                            ${options.showAttach ? `<button type="button" class="btn btn-info attach-btn">${options.attachText}</button>` : ''}
                            ${options.autoUpload ? '' : `<button type="button" class="btn btn-success upload-action-btn">${options.uploadText}</button>`}
                            ${options.showActions ? `<button type="button" class="btn btn-outline-danger clear-btn">${options.clearText}</button>` : ''}
                        </div>
                        <input type="file" class="d-none" ${options.multiple ? 'multiple' : ''}>
                        ${options.showFileList ? '<div class="ws-upload-preview mt-3"></div>' : ''}
                        ${this.renderStatistics()}
                    </div>
                `;
            } else if (options.theme === 'drag') {
                html = `
                    <div class="ws-upload-container">
                        <div class="ws-upload-dropzone">
                            <div class="ws-upload-dropzone-content pe-none">
                                <i class="bi bi-cloud-arrow-up display-4 text-muted mb-3"></i>
                                <h5>${options.dragText}</h5>
                                <p class="text-muted mb-3">支持单个或多个文件上传</p>
                                <button type="button" class="btn btn-primary browse-btn pe-auto">${options.browseText}</button>
                            </div>
                        </div>
                        <input type="file" class="d-none" ${options.multiple ? 'multiple' : ''}>
                        ${options.showFileList ? '<div class="ws-upload-preview mt-3"></div>' : ''}
                        ${this.renderActions()}
                        ${this.renderStatistics()}
                    </div>
                `;
            } else if (options.theme === 'minimal') {
                html = `
                    <div class="ws-upload-container">
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary browse-btn">${options.browseText}</button>
                            ${options.autoUpload ? '' : '<button type="button" class="btn btn-sm btn-success upload-action-btn">' + options.uploadText + '</button>'}
                        </div>
                        <input type="file" class="d-none" ${options.multiple ? 'multiple' : ''}>
                        ${options.showFileList ? '<div class="ws-upload-preview mt-2"></div>' : ''}
                        ${this.renderStatistics()}
                    </div>
                `;
            }

            this.element.html(html);
            this.fileInput = this.element.find('input[type="file"]');
            this.previewContainer = this.element.find('.ws-upload-preview');
            this.dropzone = this.element.find('.ws-upload-dropzone');
            this.uploadBtn = this.element.find('.upload-action-btn');
            this.clearBtn = this.element.find('.clear-btn');
            this.browseBtn = this.element.find('.browse-btn');
            this.statisticsContainer = this.element.find('.ws-upload-statistics');

            // 紧凑列表 / 迷你队列 版式：仅对 list 预览生效
            if (this.options.previewType === 'list') {
                if (this.options.listLayout === 'compact') {
                    this.previewContainer.addClass('ws-upload-compact');
                } else if (this.options.listLayout === 'mini-queue') {
                    this.previewContainer.addClass('ws-upload-mini');
                }
            }
            
            this.setAcceptAttribute();
        },
        
        /**
         * 渲染操作按钮区域
         * @returns {string} HTML 字符串
         */
        renderActions: function () {
            if (!this.options.showActions) return '';
            
            return `
                <div class="ws-upload-actions">
                    <div class="d-flex gap-2 align-items-center">
                        ${this.options.autoUpload ? '' : `<button type="button" class="btn btn-success upload-action-btn">${this.options.uploadText}</button>`}
                        ${this.options.showAttach ? `<button type="button" class="btn btn-info attach-btn">${this.options.attachText}</button>` : ''}
                        <button type="button" class="btn btn-outline-danger clear-btn">${this.options.clearText}</button>
                        ${this.options.chunkedUpload ? `<button type="button" class="btn btn-outline-warning cancel-btn">${this.options.cancelText}</button>` : ''}
                    </div>
                </div>
            `;
        },
        
        /**
         * 渲染统计信息区域
         * @returns {string} HTML 字符串
         */
        renderStatistics: function () {
            if (!this.options.showStatistics) return '';
            
            return `
                <div class="ws-upload-statistics">
                    <small>
                        <span class="total-files">文件总数: 0</span> | 
                        <span class="uploaded-files">已上传: 0</span> | 
                        <span class="failed-files">失败: 0</span> |
                        <span class="total-size">总大小: 0</span>
                    </small>
                </div>
            `;
        },
        
        /**
         * 设置文件输入框的 accept 属性
         */
        setAcceptAttribute: function () {
            var accept = this.options.accept;
            var exts = this.options.exts;
            var acceptMime = this.options.acceptMime;
            
            // 优先使用自定义 MIME 类型
            if (acceptMime) {
                this.fileInput.attr('accept', acceptMime);
                return;
            }
            
            // 根据文件类型设置 accept
            if (accept !== 'custom' && FILE_TYPES[accept]) {
                var fileType = FILE_TYPES[accept];
                var acceptValue = exts ? 
                    '.' + exts.split(',').join(',.') : 
                    fileType.mime;
                this.fileInput.attr('accept', acceptValue);
            } else if (exts) {
                // 使用自定义扩展名
                this.fileInput.attr('accept', '.' + exts.split(',').join(',.'));
            }
        },
        
        /**
         * 绑定事件监听器
         */
        bindEvents: function () {
            var self = this;
            
            // 文件选择按钮点击事件
            this.browseBtn.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.fileInput.click();
            });
            
            // 文件选择变更事件
            this.fileInput.on('change', function (e) {
                self.handleFileSelect(e.target.files);
                $(this).val(''); // 重置input值，允许重复选择相同文件
            });
            
            // 手动上传按钮点击事件
            if (this.uploadBtn.length) {
                this.uploadBtn.on('click', function () {
                    self.upload();
                });
            }
            
            // 清空列表按钮点击事件
            if (this.clearBtn.length) {
                this.clearBtn.on('click', function () {
                    self.clearFiles();
                });
            }
            
            // 拖拽事件处理
            if (this.options.dragDrop && this.dropzone.length) {
                this.dropzone.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.fileInput.click();
                });
                this.dropzone.on('dragover', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).addClass('is-dragover');
                });
                
                this.dropzone.on('dragleave', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('is-dragover');
                });
                
                this.dropzone.on('drop', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $(this).removeClass('is-dragover');
                    self.handleFileSelect(e.originalEvent.dataTransfer.files);
                });
            }
            
            // 取消上传按钮点击事件
            this.element.on('click', '.cancel-btn', function () {
                self.cancelUpload();
            });
            
            // 重试上传按钮点击事件
            this.element.on('click', '.retry-btn', function () {
                var fileId = $(this).closest('.ws-upload-file-item').data('file-id');
                self.retryUpload(fileId);
            });
        },
        
        /**
         * 处理文件选择
         * @param {FileList} fileList - 文件列表
         */
        handleFileSelect: function (fileList) {
            var files = Array.from(fileList);
            var validFiles = [];
            
            // 验证每个文件
            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                if (this.validateFile(file)) {
                    validFiles.push(file);
                }
            }
            
            if (validFiles.length === 0) return;
            
            // 添加有效文件
            this.addFiles(validFiles);
            
            // 触发选择回调
            if (typeof this.options.onSelect === 'function') {
                this.options.onSelect.call(this, validFiles);
            }
            
            // 自动上传
            if (this.options.autoUpload) {
                this.upload();
            }
        },
        
        /**
         * 验证文件
         * @param {File} file - 文件对象
         * @returns {boolean} 是否通过验证
         */
        validateFile: function (file) {
            var options = this.options;
            var maxFiles = options.maxFiles;
            var maxSize = options.maxSize;
            var accept = options.accept;
            var exts = options.exts;
            var messages = options.messages;
            
            // 检查文件数量限制
            if (maxFiles > 0 && this.files.length >= maxFiles) {
                this.showMessage(messages.maxFiles.replace('{maxFiles}', maxFiles), 'warning');
                return false;
            }
            
            // 检查文件大小限制
            if (maxSize > 0 && file.size > maxSize) {
                var maxSizeText = this.formatFileSize(maxSize);
                this.showMessage(
                    messages.maxSize.replace('{maxSize}', maxSizeText) + ' - ' + file.name, 
                    'error'
                );
                return false;
            }
            
            // 检查文件类型
            if (accept !== 'file' && accept !== 'custom') {
                var fileType = FILE_TYPES[accept];
                var fileExt = file.name.split('.').pop().toLowerCase();
                var allowedExts = exts ? exts.split(',') : fileType.exts.split(',');
                
                if (!allowedExts.includes(fileExt)) {
                    this.showMessage(
                        messages.invalidExt.replace('{file}', file.name), 
                        'error'
                    );
                    return false;
                }
            } else if (exts) {
                var fileExt = file.name.split('.').pop().toLowerCase();
                var allowedExts = exts.split(',');
                
                if (!allowedExts.includes(fileExt)) {
                    this.showMessage(
                        messages.invalidExt.replace('{file}', file.name), 
                        'error'
                    );
                    return false;
                }
            }
            
            return true;
        },
        
        /**
         * 添加文件到列表
         * @param {Array} files - 文件数组
         */
        addFiles: function (files) {
            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                // 生成唯一文件ID
                file.id = this.generateFileId();
                file.retryCount = 0; // 重试次数
                file.status = 'pending'; // 文件状态
                file.uploadedSize = 0; // 已上传大小
                
                this.files.push(file);
                
                // 渲染文件项
                if (this.options.showFileList) {
                    this.renderFileItem(file);
                }
            }
            
            this.updateStatistics();
        },
        
        /**
         * 渲染文件项
         * @param {File} file - 文件对象
         */
        renderFileItem: function (file) {
            var fileExt = file.name.split('.').pop().toLowerCase();
            var fileIcon = FILE_ICONS[fileExt] || FILE_TYPES[this.getFileType(file)].icon;
            var fileSize = this.formatFileSize(file.size);
            var options = this.options;
            
            var fileItem = $(`
                <div class="ws-upload-file-item" data-file-id="${file.id}">
                    <div class="ws-upload-file-icon">
                        <i class="${fileIcon}"></i>
                    </div>
                    <div class="ws-upload-file-info">
                        <div class="ws-upload-file-name">${file.name}</div>
                        <div class="ws-upload-file-size">${fileSize}</div>
                        <div class="progress ws-upload-file-progress d-none">
                            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div class="ws-upload-status"></div>
                    </div>
                    <div class="ws-upload-file-actions">
                        ${options.showRetry ? `<button type="button" class="btn btn-sm btn-outline-warning retry-btn d-none" title="重试上传"><i class="bi bi-arrow-clockwise"></i> ${options.retryText}</button>` : ''}
                        <button type="button" class="btn btn-sm btn-outline-danger file-remove-btn" title="删除文件">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `);
            
            this.previewContainer.append(fileItem);
            
            // 绑定删除事件
            fileItem.find('.file-remove-btn').on('click', function (e) {
                this.removeFile(file.id);
            }.bind(this));
            
            // 图片预览
            if (this.options.showPreview && this.isImageFile(file)) {
                this.renderImagePreview(file, fileItem);
            }
        },
        
        /**
         * 渲染图片预览
         * @param {File} file - 文件对象
         * @param {jQuery} fileItem - 文件项元素
         */
        renderImagePreview: function (file, fileItem) {
            var reader = new FileReader();
            var self = this;
            
            reader.onload = function (e) {
                if (self.options.previewType === 'grid') {
                    self.previewContainer.addClass('d-flex');
                    // 网格预览模式
                    var gridItem = $(`
                        <div class="ws-upload-grid-item" data-file-id="${file.id}">
                            <img src="${e.target.result}" alt="${file.name}">
                            <div class="ws-upload-grid-item-overlay">
                                <button type="button" class="btn btn-light btn-sm preview-btn" title="预览">
                                    <i class="bi bi-eye"></i>
                                </button>
                                ${options.showRetry ? '<button type="button" class="btn btn-light btn-sm ms-1 retry-btn d-none" title="重试上传"><i class="bi bi-arrow-clockwise"></i></button>' : ''}
                                <button type="button" class="btn btn-light btn-sm ms-1 remove-btn" title="删除">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `);
                    
                    gridItem.find('.preview-btn').on('click', function () {
                        self.previewImage(e.target.result, file.name);
                    });
                    
                    gridItem.find('.remove-btn').on('click', function () {
                        self.removeFile(file.id);
                    });
                    
                    self.previewContainer.append(gridItem);
                    fileItem.hide();
                } else {
                    // 列表预览模式
                    var previewBtn = $(`
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2 preview-btn">
                            <i class="bi bi-eye"></i> 预览
                        </button>
                    `);
                    
                    previewBtn.on('click', function () {
                        self.previewImage(e.target.result, file.name);
                    });
                    
                    fileItem.find('.ws-upload-file-info').append(previewBtn);
                }
            };
            
            reader.readAsDataURL(file);
        },
        
        /**
         * 预览图片
         * @param {string} src - 图片数据URL
         * @param {string} filename - 文件名
         */
        previewImage: function (src, filename) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                // 使用 Bootstrap Modal 预览
                var modalHtml = `
                    <div class="modal fade ws-upload-image-preview-modal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">${filename}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <img src="${src}" alt="${filename}" class="img-fluid">
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                var modal = $(modalHtml).appendTo('body');
                var bsModal = new bootstrap.Modal(modal[0]);
                bsModal.show();
                
                modal.on('hidden.bs.modal', function () {
                    modal.remove();
                });
            } else {
                // 降级方案：在新窗口打开
                var newWindow = window.open();
                newWindow.document.write('<img src="' + src + '" alt="' + filename + '" style="max-width: 100%;">');
            }
        },
        
        // ==================== 迷你队列（mini-queue 版式） ====================

        /**
         * 确保迷你队列 DOM 存在。当配置 listLayout='mini-queue' 且当前有上传任务时，
         * 上传中文件会收进一个 fixed 在视口右下角的迷你队列，主列表不铺开占位。
         * @returns {jQuery} 迷你队列
         */
        _ensureMiniQueue: function () {
            var self = this;
            if (this.$miniQueue && this.$miniQueue.length) return this.$miniQueue;

            var queue = $(`
                <div class="ws-upload-mini-queue" role="status" aria-live="polite">
                    <button type="button" class="ws-upload-mini-head" data-mini-toggle>
                        <i class="bi bi-cloud-arrow-up"></i>
                        <span class="ws-upload-mini-head-text">上传中…</span>
                        <span class="ws-upload-mini-head-count"></span>
                        <i class="bi bi-chevron-down ws-upload-mini-caret"></i>
                    </button>
                    <div class="ws-upload-mini-bar">
                        <div class="ws-upload-mini-progress"></div>
                    </div>
                    <div class="ws-upload-mini-list"></div>
                </div>
            `).appendTo('body');

            queue.on('click', '[data-mini-toggle]', function (e) {
                e.stopPropagation();
                self._miniOpen = !self._miniOpen;
                queue.toggleClass('open', self._miniOpen);
            });
            // 点击迷你队列外部自动收起展开面板（命名空间唯一，避免多实例串扰）
            this._miniNs = 'wsUploadMini' + this.generateFileId();
            $(document).on('click.' + this._miniNs, function (e) {
                if (self.$miniQueue && self.$miniQueue.length &&
                    !self.$miniQueue.is(e.target) && self.$miniQueue.has(e.target).length === 0) {
                    self._miniOpen = false;
                    self.$miniQueue.removeClass('open');
                }
            });

            this.$miniQueue = queue;
            return queue;
        },

        /**
         * 刷新迷你队列头部：上传中计数 + 聚合进度
         */
        _miniRefresh: function () {
            if (!this.$miniQueue) return;

            var self = this;
            var uploading = this.files.filter(function (f) { return f.status === 'uploading'; });
            var done = this.files.filter(function (f) { return f.status === 'success'; });
            var total = uploading.length + done.length;
            var pct = 0;
            if (total > 0) {
                var sum = 0;
                uploading.forEach(function (f) { sum += f.uploadedPercent || 0; });
                done.forEach(function () { sum += 100; });
                pct = Math.round(sum / total);
            }

            this.$miniQueue.find('.ws-upload-mini-head-count').text(uploading.length);
            this.$miniQueue.find('.ws-upload-mini-head-text').text(
                uploading.length > 0
                    ? ('上传中 ' + uploading.length + ' · 完成 ' + done.length)
                    : ('已完成 ' + done.length)
            );
            this.$miniQueue.find('.ws-upload-mini-progress').css('width', pct + '%');

            // 展开面板内无上传中文件时自动收起
            if (uploading.length === 0 && this._miniOpen) {
                this._miniOpen = false;
                this.$miniQueue.removeClass('open');
            }
        },

        /**
         * 把一个上传中的文件加入迷你队列（隐藏主列表行，队列内新增一行）
         */
        _miniShowFile: function (file) {
            var queue = this._ensureMiniQueue();
            queue.find('.ws-upload-mini-list').append(
                '<div class="ws-upload-mini-file" data-file-id="' + file.id + '">' +
                    '<span class="ws-upload-mini-file-icon"><i class="' + this._iconFor(file) + '"></i></span>' +
                    '<span class="ws-upload-mini-file-name" title="' + this.escapeHtml(file.name) + '">' + this.escapeHtml(file.name) + '</span>' +
                    '<span class="ws-upload-mini-file-pct">0%</span>' +
                '</div>'
            );
            queue.addClass('show');
            // 隐藏主列表中的对应行，避免铺开占位
            this.element.find('[data-file-id="' + file.id + '"]').addClass('ws-upload-mini-hide');
            this._miniRefresh();
        },

        /**
         * 更新迷你队列中某文件行的进度
         */
        _miniUpdateFile: function (fileId, percent) {
            if (!this.$miniQueue) return;
            var row = this.$miniQueue.find('.ws-upload-mini-file[data-file-id="' + fileId + '"]');
            row.find('.ws-upload-mini-file-pct').text(percent + '%');
            this._miniRefresh();
        },

        /**
         * 某文件离开上传中态（成功/失败/取消）：从迷你队列移除，并恢复主列表行显示
         */
        _miniRemoveFile: function (fileId) {
            if (this.$miniQueue) {
                this.$miniQueue.find('.ws-upload-mini-file[data-file-id="' + fileId + '"]').remove();
            }
            this.element.find('[data-file-id="' + fileId + '"]').removeClass('ws-upload-mini-hide');
            this._miniRefresh();

            // 没有上传中文件时收起迷你队列
            var stillUploading = this.files.some(function (f) { return f.status === 'uploading'; });
            if (this.$miniQueue && !stillUploading) {
                this.$miniQueue.removeClass('show');
            }
        },

        /**
         * 取文件图标类
         */
        _iconFor: function (file) {
            var ext = file.name.split('.').pop().toLowerCase();
            return FILE_ICONS[ext] || FILE_TYPES[this.getFileType(file)].icon;
        },

        /**
         * 清理迷你队列 DOM 与全局事件
         */
        _miniDestroy: function () {
            if (this.$miniQueue) {
                this.$miniQueue.remove();
                this.$miniQueue = null;
            }
            if (this._miniNs) {
                $(document).off('click.' + this._miniNs);
                this._miniNs = null;
            }
            this._miniOpen = false;
        },

        /**
         * 移除文件
         * @param {string} fileId - 文件ID
         */
        removeFile: function (fileId) {
            var index = this.files.findIndex(function (f) { return f.id === fileId; });
            if (index !== -1) {
                // 如果文件正在上传，取消上传
                var file = this.files[index];
                if (file.status === 'uploading') {
                    this.cancelFileUpload(fileId);
                }

                // 已上传成功的文件被移除时，提交值也必须同步剔除，
                // 否则「界面上删掉了、数据库里还留着」
                if (file.value) {
                    var vi = this.values.indexOf(file.value);
                    if (vi !== -1) this.values.splice(vi, 1);
                }

                this.files.splice(index, 1);
                this.syncCarrier();
            }
            
            this.element.find('[data-file-id="' + fileId + '"]').remove();
            this._miniRemoveFile(fileId);
            this.updateStatistics();
            
            // 触发移除回调
            if (typeof this.options.onRemove === 'function') {
                this.options.onRemove.call(this, fileId);
            }
        },
        
        /**
         * 开始上传
         */
        upload: function () {
            if (this.files.length === 0) {
                this.showMessage('请先选择文件', 'warning');
                return;
            }
            
            if (this.uploading) {
                this.showMessage('文件正在上传中', 'warning');
                return;
            }
            
            this.uploading = true;
            this.completedUploads = 0;
            this.failedUploads = 0;
            
            // 触发上传前回调
            if (typeof this.options.beforeUpload === 'function') {
                var result = this.options.beforeUpload.call(this, this.files);
                if (result === false) {
                    this.uploading = false;
                    return;
                }
            }
            
            // 准备上传队列（排除已成功的文件）
            this.uploadQueue = this.files.filter(function (file) {
                return file.status !== 'success';
            });
            
            if (this.uploadQueue.length === 0) {
                this.uploading = false;
                this.showMessage('没有需要上传的文件', 'info');
                return;
            }
            
            // 更新文件状态
            this.uploadQueue.forEach(function (file) {
                file.status = 'uploading';
                this.updateFileStatus(file.id, 'uploading', '上传中...');
            }.bind(this));
            
            // 根据配置选择上传方式
            if (this.options.chunkedUpload) {
                this.uploadChunked();
            } else if (this.options.parallelUploads > 1) {
                this.uploadParallel();
            } else {
                this.uploadSequential();
            }
        },
        
        /**
         * 顺序上传
         */
        uploadSequential: function () {
            var self = this;
            var uploadNext = function (index) {
                if (index >= self.uploadQueue.length) {
                    self.uploadComplete();
                    return;
                }
                
                var file = self.uploadQueue[index];
                self.uploadFile(file).always(function () {
                    uploadNext(index + 1);
                });
            };
            
            uploadNext(0);
        },
        
        /**
         * 并行上传
         */
        uploadParallel: function () {
            var self = this;
            var parallelCount = Math.min(this.options.parallelUploads, this.uploadQueue.length);
            
            // 启动多个并行上传
            for (var i = 0; i < parallelCount; i++) {
                this.uploadNextInQueue();
            }
        },
        
        /**
         * 上传队列中的下一个文件
         */
        uploadNextInQueue: function () {
            var self = this;
            var file = this.uploadQueue.find(function (f) {
                return f.status === 'uploading' && !f.xhr;
            });
            
            if (!file) return;
            
            this.uploadFile(file).always(function () {
                if (self.activeUploads < self.options.parallelUploads) {
                    self.uploadNextInQueue();
                }
            });
        },
        
        /**
         * 上传单个文件
         * @param {File} file - 文件对象
         * @returns {jqXHR} jQuery XHR 对象
         */
        uploadFile: function (file) {
            var self = this;
            var formData = new FormData();
            
            // 添加文件到表单数据
            formData.append(this.options.field, file);
            
            // 添加额外数据
            Object.keys(this.options.data).forEach(function (key) {
                var value = typeof self.options.data[key] === 'function' ? 
                    self.options.data[key](file) : 
                    self.options.data[key];
                formData.append(key, value);
            });
            
            var fileItem = this.element.find('[data-file-id="' + file.id + '"]');
            fileItem.addClass('is-uploading');
            fileItem.find('.ws-upload-file-progress').removeClass('d-none');
            fileItem.find('.ws-upload-status').html('<span class="ws-upload-loading"></span>上传中...');

            // mini-queue 版式：上传中文件收进右下迷你队列，不占表单位置
            if (this.options.listLayout === 'mini-queue') {
                this._miniShowFile(file);
            }

            this.activeUploads++;
            
            // 创建 AJAX 请求
            var xhr = $.ajax({
                url: this.options.url,
                type: this.options.method,
                data: formData,
                processData: false,
                contentType: false,
                headers: this.options.headers,
                xhr: function () {
                    var xhr = new window.XMLHttpRequest();
                    
                    // 监听上传进度
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            var percent = Math.round((e.loaded / e.total) * 100);
                            file.uploadedPercent = percent;
                            fileItem.find('.progress-bar').css('width', percent + '%');
                            if (self.options.listLayout === 'mini-queue') {
                                self._miniUpdateFile(file.id, percent);
                            }
                            
                            // 触发进度回调
                            if (typeof self.options.onProgress === 'function') {
                                self.options.onProgress.call(self, file, percent, e.loaded, e.total);
                            }
                        }
                    }, false);
                    
                    return xhr;
                },
                success: function (response) {
                    self.handleUploadSuccess(file, response);
                },
                error: function (xhr, status, error) {
                    self.handleUploadError(file, error, xhr);
                }
            });
            
            file.xhr = xhr;
            this.xhrPool.push(xhr);
            
            return xhr;
        },
        
        /**
         * 分片上传（需要后端支持）
         */
        uploadChunked: function () {
            // 分片上传实现（需后端支持，当前回退到并行上传）
            this.uploadParallel(); // 暂时回退到普通并行上传
        },
        
        /**
         * 处理上传成功
         * @param {File} file - 文件对象
         * @param {*} response - 服务器响应
         */
        handleUploadSuccess: function (file, response) {
            file.status = 'success';
            file.xhr = null;
            
            var fileItem = this.element.find('[data-file-id="' + file.id + '"]');
            fileItem.removeClass('is-uploading').addClass('is-success');
            fileItem.find('.ws-upload-status').html('<i class="bi bi-check-circle text-success"></i> ' + this.escapeHtml(this.options.messages.uploadSuccess.replace('{file}', file.name)));
            fileItem.find('.progress-bar').addClass('bg-success');
            this._miniRemoveFile(file.id);
            
            this.completedUploads++;
            this.activeUploads--;
            this.updateStatistics();
            
            // 解析响应数据
            var success = true;
            var message = '';
            var data = response;
            
            if (this.options.responseType === 'json') {
                if (response && typeof response === 'object') {
                    success = response[this.options.successField] !== false;
                    message = response[this.options.messageField] || '';
                    data = response[this.options.dataField] || response;
                }
            }
            
            if (success) {
                // 先把提取到的路径写入载体，**再**触发用户回调。
                // 响应体本身不会被留存（`file.response` 从未赋值），
                // 早前调用方只能在 onSuccess 里自行收集，稍有遗漏就丢值。
                var uploadedPath = this.extractPath(data, response);

                if (uploadedPath) {
                    if (this.options.multiple) {
                        if (this.values.indexOf(uploadedPath) === -1) this.values.push(uploadedPath);
                    } else {
                        this.values = [uploadedPath];
                    }
                    file.value = uploadedPath;
                    this.syncCarrier();
                }

                // 触发成功回调
                if (typeof this.options.onSuccess === 'function') {
                    this.options.onSuccess.call(this, file, data, response);
                }
            } else {
                this.handleUploadError(file, message || '服务器返回失败状态');
            }
            
            this.checkUploadComplete();
        },
        
        /**
         * HTML 转义（防 XSS）
         * @param {string} str
         * @returns {string}
         */
        escapeHtml: function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        /**
         * 处理上传错误
         * @param {File} file - 文件对象
         * @param {string} error - 错误信息
         * @param {XMLHttpRequest} xhr - XHR 对象
         */
        handleUploadError: function (file, error, xhr) {
            file.status = 'error';
            file.xhr = null;
            
            var fileItem = this.element.find('[data-file-id="' + file.id + '"]');
            fileItem.removeClass('is-uploading').addClass('is-error');
            
            var errorMessage = error || this.options.messages.uploadFailed.replace('{file}', file.name);
            if (xhr && xhr.status) {
                errorMessage = this.options.messages.serverError.replace('{status}', xhr.status) + ': ' + errorMessage;
            }
            
            fileItem.find('.ws-upload-status').html('<i class="bi bi-x-circle text-danger"></i> ' + this.escapeHtml(errorMessage));
            fileItem.find('.progress-bar').addClass('bg-danger');
            fileItem.find('.retry-btn').removeClass('d-none');
            this._miniRemoveFile(file.id);
            
            this.failedUploads++;
            this.activeUploads--;
            this.updateStatistics();
            
            // 触发错误回调
            if (typeof this.options.onError === 'function') {
                this.options.onError.call(this, file, error, xhr);
            }
            
            this.checkUploadComplete();
        },
        
        /**
         * 检查上传是否完成
         */
        checkUploadComplete: function () {
            if (this.completedUploads + this.failedUploads === this.uploadQueue.length) {
                this.uploadComplete();
            }
        },
        
        /**
         * 上传完成处理
         */
        uploadComplete: function () {
            this.uploading = false;
            this.uploadQueue = [];
            this.activeUploads = 0;
            this.xhrPool = [];
            
            // 触发完成回调
            if (typeof this.options.onComplete === 'function') {
                this.options.onComplete.call(this, this.files);
            }
            
            var message = '上传完成! 成功: ' + this.completedUploads + ', 失败: ' + this.failedUploads;
            this.showMessage(message, this.failedUploads === 0 ? 'success' : 'warning');
        },
        
        /**
         * 取消上传
         */
        cancelUpload: function () {
            // 中止所有 XHR 请求
            this.xhrPool.forEach(function (xhr) {
                xhr.abort();
            });
            
            // 更新文件状态
            this.uploadQueue.forEach(function (file) {
                if (file.status === 'uploading') {
                    file.status = 'cancelled';
                    this.updateFileStatus(file.id, 'cancelled', '上传已取消');
                    this._miniRemoveFile(file.id);
                }
            }.bind(this));
            
            this.uploading = false;
            this.uploadQueue = [];
            this.activeUploads = 0;
            this.xhrPool = [];
            
            this.showMessage('上传已取消', 'info');
        },
        
        /**
         * 取消单个文件上传
         * @param {string} fileId - 文件ID
         */
        cancelFileUpload: function (fileId) {
            var file = this.files.find(function (f) { return f.id === fileId; });
            if (file && file.xhr) {
                file.xhr.abort();
            }
        },
        
        /**
         * 重试上传
         * @param {string} fileId - 文件ID
         */
        retryUpload: function (fileId) {
            var file = this.files.find(function (f) { return f.id === fileId; });
            if (file && file.status === 'error') {
                if (file.retryCount < this.options.retryAttempts) {
                    file.retryCount++;
                    file.status = 'uploading';
                    this.updateFileStatus(fileId, 'uploading', '重试上传中... (' + file.retryCount + '/' + this.options.retryAttempts + ')');
                    
                    if (this.uploading) {
                        this.uploadFile(file);
                    } else {
                        this.uploadQueue = [file];
                        this.uploadFile(file);
                    }
                } else {
                    this.showMessage('重试次数已达上限: ' + file.name, 'error');
                }
            }
        },
        
        /**
         * 更新文件状态显示
         * @param {string} fileId - 文件ID
         * @param {string} status - 状态类型
         * @param {string} message - 状态消息
         */
        updateFileStatus: function (fileId, status, message) {
            var fileItem = this.element.find('[data-file-id="' + fileId + '"]');
            var statusElement = fileItem.find('.ws-upload-status');
            
            fileItem.removeClass('uploading success error');
            
            var escapedMsg = this.escapeHtml(message);
            switch (status) {
                case 'uploading':
                    fileItem.addClass('is-uploading');
                    statusElement.html('<span class="ws-upload-loading"></span>' + escapedMsg);
                    fileItem.find('.retry-btn').addClass('d-none');
                    break;
                case 'success':
                    fileItem.addClass('is-success');
                    statusElement.html('<i class="bi bi-check-circle text-success"></i> ' + escapedMsg);
                    fileItem.find('.retry-btn').addClass('d-none');
                    break;
                case 'error':
                    fileItem.addClass('is-error');
                    statusElement.html('<i class="bi bi-x-circle text-danger"></i> ' + escapedMsg);
                    fileItem.find('.retry-btn').removeClass('d-none');
                    break;
                case 'cancelled':
                    statusElement.html('<i class="bi bi-slash-circle text-warning"></i> ' + escapedMsg);
                    fileItem.find('.retry-btn').removeClass('d-none');
                    break;
            }
        },
        
        /**
         * 清空文件列表
         */
        clearFiles: function () {
            if (this.uploading) {
                if (!confirm('文件正在上传中，确定要清空列表吗？')) {
                    return;
                }
                this.cancelUpload();
            }
            
            // 中止所有文件上传
            this.files.forEach(function (file) {
                if (file.xhr) {
                    file.xhr.abort();
                }
            });
            
            this.files = [];
            this.values = [];   // 清空列表 = 清空提交值（含已存在的历史文件）
            this.previewContainer.empty();
            this._miniDestroy();
            this.updateStatistics();
            this.syncCarrier();
            
            this.showMessage('文件列表已清空', 'info');
        },
        
        /**
         * 更新统计信息
         */
        updateStatistics: function () {
            if (!this.options.showStatistics) return;

            var totalSize = this.files.reduce(function (sum, file) {
                return sum + file.size;
            }, 0);

            var successCount = this.files.filter(function (file) {
                return file.status === 'success';
            }).length;

            var errorCount = this.files.filter(function (file) {
                return file.status === 'error';
            }).length;

            // 回填的历史文件没有 File 对象，但已经在列表里展示、且已进入提交值。
            // 不计入会出现「列表里挂着 3 个附件，统计却写文件总数: 0」的自相矛盾。
            var existingCount = Math.max(0, (this.values ? this.values.length : 0) - successCount);

            this.statisticsContainer.html(`
                <small>
                    <span class="total-files">文件总数: ${this.files.length + existingCount}</span> | 
                    <span class="uploaded-files">已上传: ${successCount + existingCount}</span> | 
                    <span class="failed-files">失败: ${errorCount}</span> |
                    <span class="total-size">总大小: ${this.formatFileSize(totalSize)}</span>
                </small>
            `);
        },
        
        // ==================== 工具方法 ====================
        
        /**
         * 生成文件ID
         * @returns {string} 唯一文件ID
         */
        generateFileId: function () {
            return Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        },
        
        /**
         * 格式化文件大小
         * @param {number} bytes - 字节数
         * @returns {string} 格式化后的文件大小
         */
        formatFileSize: function (bytes) {
            if (bytes === 0) return '0 Bytes';
            
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        /**
         * 获取文件类型
         * @param {File} file - 文件对象
         * @returns {string} 文件类型
         */
        getFileType: function (file) {
            if (file.type.startsWith('image/')) return 'images';
            if (file.type.startsWith('video/')) return 'video';
            if (file.type.startsWith('audio/')) return 'audio';
            if (file.type.startsWith('application/pdf') || 
                file.type.includes('word') || 
                file.type.includes('text')) return 'document';
            if (file.type.includes('zip') || 
                file.type.includes('rar') || 
                file.type.includes('archive')) return 'archive';
            return 'file';
        },
        
        /**
         * 检查是否为图片文件
         * @param {File} file - 文件对象
         * @returns {boolean} 是否为图片
         */
        isImageFile: function (file) {
            return file.type.startsWith('image/');
        },
        
        /**
         * 显示消息
         * @param {string} message - 消息内容
         * @param {string} type - 消息类型 (success, error, warning, info)
         */
        showMessage: function (message, type) {
            // 简单实现 - 可以使用更复杂的通知系统
            var alertClass = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            }[type] || 'alert-info';
            
            var alertHtml = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // 在组件上方显示消息
            var alertElement = $(alertHtml).insertBefore(this.element.find('.ws-upload-container'));
            
            // 5秒后自动消失
            setTimeout(function () {
                if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                    alertElement.alert('close');
                } else {
                    alertElement.fadeOut(400, function () { $(this).remove(); });
                }
            }, 5000);
        },
        
        // ==================== 公共API方法 ====================
        
        /**
         * 获取所有文件
         * @returns {Array} 文件列表
         */
        getFiles: function () {
            return this.files;
        },
        
        /**
         * 获取已上传成功的文件
         * @returns {Array} 已上传文件列表
         */
        getUploadedFiles: function () {
            return this.files.filter(function (file) {
                return file.status === 'success';
            });
        },
        
        /**
         * 获取上传失败的文件
         * @returns {Array} 失败文件列表
         */
        getFailedFiles: function () {
            return this.files.filter(function (file) {
                return file.status === 'error';
            });
        },
        
        /**
         * 检查是否正在上传
         * @returns {boolean} 上传状态
         */
        isUploading: function () {
            return this.uploading;
        },
        
        /**
         * 获取上传进度百分比
         * @returns {number} 进度百分比
         */
        getProgress: function () {
            var total = this.files.length;
            var completed = this.files.filter(function (file) {
                return file.status === 'success';
            }).length;
            
            return total > 0 ? Math.round((completed / total) * 100) : 0;
        },
        
        /**
         * 销毁组件
         */
        destroy: function () {
            this.cancelUpload();
            this._miniDestroy();
            
            // 移除事件监听
            this.fileInput.off();
            this.element.find('button').off();
            this.element.off();
            
            // 清空元素
            this.element.empty();
            this._extraCarriers = [];
            this.carrier = null;
            
            // 移除数据
            this.element.removeData('wsUpload');
        }
    };

    // ==================== jQuery 插件封装 ====================
    
    /**
     * jQuery 插件方法
     * @param {Object} options - 配置选项
     * @returns {jQuery} jQuery 对象
     */
    $.fn.wsUpload = function (options) {
        return this.each(function () {
            if (!$(this).data('wsUpload')) {
                $(this).data('wsUpload', new WsUpload(this, options));
            }
        });
    };

    // 全局配置
    $.fn.wsUpload.defaults = DEFAULTS;
    $.fn.wsUpload.fileTypes = FILE_TYPES;

    /* ============================================================
     * 声明式接入（data-role="wsupload"）
     * ============================================================ */

    /**
     * 扫描并初始化 `[data-role="wsupload"]`。
     *
     * 典型写法（编辑页回填；不写 `data-url` 时自动用框架默认接口
     * `/api/attachment/upload`）：
     * ```html
     * <div data-role="wsupload"
     *      data-name="cover"
     *      data-response-field="data.url"
     *      data-value="{$row.cover}"></div>
     * ```
     *
     * 需要换接口时再写 `data-url`（优先级最高）：
     * ```html
     * <div data-role="wsupload" data-name="cover" data-url="/admin/attachment/upload"></div>
     * ```
     *
     * @param {*} [root] document / 元素 / 选择器
     * @returns {number} 初始化数量
     */
    WsUpload.autoInitialize = function (root) {
        var count = 0;

        toElements(root || document).forEach(function (scope) {
            var list = [];

            if (scope.nodeType === 1 && scope.matches && scope.matches('[data-role="wsupload"]')) list.push(scope);
            if (scope.querySelectorAll) list.push.apply(list, Array.prototype.slice.call(scope.querySelectorAll('[data-role="wsupload"]')));

            list.forEach(function (el) {
                if (el.__wsUpload) return;

                var options = parseDataConfig(el, UPLOAD_SCHEMA);

                // 远程地址 / 字段名别名；都没写就用框架默认上传接口
                if (!options.url) {
                    options.url = pickData(el, ['remoteUrl', 'action'], T.RAW) || resolveDefaultUrl();
                }

                // 兼容 data-json-value / data-use-json-value 两种写法
                if (options.jsonValue === undefined) {
                    var legacy = pickData(el, ['useJsonValue'], T.BOOL);
                    if (legacy !== undefined) options.jsonValue = legacy;
                }

                // 透传标签文本（可选）
                var texts = parseDataConfig(el, {
                    browseText: T.RAW, dragText: T.RAW, dropText: T.RAW,
                    uploadText: T.RAW, clearText: T.RAW, attachText: T.RAW,
                });
                Object.assign(options, texts);

                try {
                    el.__wsUpload = new WsUpload(el, options);
                    count++;
                } catch (err) {
                    console.error('[ws-upload] 初始化失败', el, err);
                }
            });
        });

        return count;
    };

    /**
     * 框架默认上传接口（`/api/attachment/upload`）
     * @type {string}
     */
    WsUpload.DEFAULT_UPLOAD_URL = DEFAULT_UPLOAD_URL;

    /**
     * 获取当前生效的默认上传地址
     * @returns {string}
     */
    WsUpload.getDefaultUrl = resolveDefaultUrl;

    /**
     * 全局覆盖默认上传地址
     *
     * 只影响**之后**创建的实例；已初始化的组件请用 `instance.options.url = ...`
     * 或直接在元素上写 `data-url`。传 null 恢复"后端注入 > 框架默认"的解析链。
     *
     * @param {string|null} url
     */
    WsUpload.setDefaultUrl = function (url) {
        runtimeDefaultUrl = url ? String(url) : null;
    };

// 保留全局挂载：wise-ui.js 与插件业务代码通过 window.WsUpload 访问，存量调用零改造。
window.WsUpload = WsUpload;

export default WsUpload;