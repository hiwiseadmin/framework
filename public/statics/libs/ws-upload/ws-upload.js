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

    // 默认配置
    var DEFAULTS = {
        // 基础配置
        url: '',                    // 上传接口URL
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
        this.files = []; // 文件列表
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
            this.render();
            this.bindEvents();
            this.updateStatistics();
            
            // 触发初始化回调
            if (typeof this.options.onInit === 'function') {
                this.options.onInit.call(this);
            }
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
                
                this.files.splice(index, 1);
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
            this.previewContainer.empty();
            this._miniDestroy();
            this.updateStatistics();
            
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
            
            this.statisticsContainer.html(`
                <small>
                    <span class="total-files">文件总数: ${this.files.length}</span> | 
                    <span class="uploaded-files">已上传: ${successCount}</span> | 
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

// 保留全局挂载：wise-ui.js 与插件业务代码通过 window.WsUpload 访问，存量调用零改造。
window.WsUpload = WsUpload;

export default WsUpload;