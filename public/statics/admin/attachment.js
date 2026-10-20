/**
 * attachment.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const FormValidator = window.FormValidator;
const Layer = window.parent.Layer || window.Layer;
const Clipboard = window.Clipboard;

const Controller = {
        index(){
            
            var selectedAttachments = new Set();
            var currentPage = 1;
            var pageSize = 12;
            var currentCategory = 0;
            var currentKeyword = '';
            var uploadQueue = [];
            var isUploading = false;
            var clipboardIndicator = null;
 
            function init() {
                initAttachmentSelection();
                initEvents();
                loadCategories();
                loadAttachments();
                // initClipboardPaste();
            }
            
            function initEvents() {
                // 筛选事件
                $('#categoryFilter').on('change', () => {
                    currentCategory = $('#categoryFilter').val();
                    currentPage = 1;
                    loadAttachments();
                });
                
                $('#keywordFilter').on('keypress', (e) => {
                    if (e.which == 13) {
                        currentKeyword = $('#keywordFilter').val();
                        currentPage = 1;
                        loadAttachments();
                    }
                });
                
                $('#searchBtn').on('click', () => {
                    currentKeyword = $('#keywordFilter').val();
                    currentPage = 1;
                    loadAttachments();
                });
                
                // 批量操作事件
                $('#batchDeleteBtn').on('click', () => {
                    batchDelete();
                });
                
                $('#batchDownloadBtn').on('click', () => {
                    batchDownload();
                });
                
                $('#batchMoveBtn').on('click', () => {
                    showMoveCategoryModal();
                });
                
                $('#clearSelectionBtn').on('click', () => {
                    clearSelection();
                });
                
                // 上传相关事件
                $('#selectFilesBtn').on('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#fileInput').val('');
                    $('#fileInput').click();
                });
                
                $('#fileInput').on('change', (e) => {
                    addFilesToQueue(e.target.files);
                });
                
                $('#startUploadBtn').on('click', () => {
                    startUpload();
                });
                
                // 移动分类事件
                $('#confirmMoveBtn').on('click', () => {
                    batchMoveCategory();
                });
                
                $('input[name="viewMode"]').on('change', () => {
                    console.log($('input[name="viewMode"]:checked').val());
                    const viewMode = $('input[name="viewMode"]:checked').val();
                    if (viewMode == 'gridView') {
                        $('#attachmentList').removeClass('list-view').addClass('grid-view');
                    } else {
                        $('#attachmentList').removeClass('grid-view').addClass('list-view');
                    }
                    // loadAttachments();
                });
                // 拖拽上传
                initDragAndDrop();
            }
            
            function initDragAndDrop() {
                const dropzone = $('#uploadDropzone')[0];
                
                dropzone.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#fileInput').val('');
                    $('#fileInput').click();
                });

                dropzone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    $('#uploadDropzone').addClass('dragover');
                });
                
                dropzone.addEventListener('dragleave', (e) => {
                    e.preventDefault();
                    $('#uploadDropzone').removeClass('dragover');
                });
                
                dropzone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    $('#uploadDropzone').removeClass('dragover');
                    
                    const files = e.dataTransfer.files;
                    addFilesToQueue(files);
                });
            }
            
            // 加载分类列表
            function loadCategories() {
                $.get('/admin/attachment/categories', (result) => {
                    if (result.code == 0) {
                        renderCategories(result.data);
                    }
                });
            }
            
            function renderCategories(categories) {
                const $categoryFilter = $('#categoryFilter');
                const $targetCategory = $('#targetCategory');
                
                // 清空现有选项（保留第一个选项）
                $categoryFilter.find('option:not(:first)').remove();
                $targetCategory.find('option:not(:first)').remove();
                
                const renderOptions = (items, level = 0) => {
                    items.forEach(item => {
                        const prefix = '&nbsp;'.repeat(level * 4);
                        const option = `<option value="${item.id}">${prefix}${item.name}</option>`;
                        $categoryFilter.append(option);
                        $targetCategory.append(option);
                        
                        if (item.children && item.children.length > 0) {
                            renderOptions(item.children, level + 1);
                        }
                    });
                };
                
                renderOptions(categories);
            }
            
            // 加载附件列表
            function loadAttachments() {
                const params = {
                    page: currentPage,
                    limit: pageSize,
                    category_id: currentCategory,
                    keyword: currentKeyword
                };
                
                $.get('/admin/attachment/getList', params, (result) => {
                    if (result.code == 0) {
                        renderAttachments(result.data);
                    } else {
                        Layer.toast({content: '加载失败: ' + result.msg, type: 'danger'});
                    }
                }).fail(() => {
                    Layer.toast({content: '网络错误，请稍后重试', type: 'danger'});
                });
            }
            
            function renderAttachments(data) {
                const $attachmentList = $('#attachmentList');
                const $paginationContainer = $('#paginationContainer');
                
                // 渲染附件卡片
                if (data.list && data.list.length > 0) {
                    let html = '';
                    data.list.forEach(attachment => {
                        html += renderAttachmentCard(attachment);
                    });
                    $attachmentList.html(html);
                } else {
                    $attachmentList.html(`
                        <div class="col-12 text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <p class="mt-3 text-muted">暂无文件</p>
                        </div>
                    `);
                }
                
                // 渲染分页
                renderPagination(data);
                
                // 更新选择状态
                updateBatchToolbar();
            }
            
            function renderAttachmentCard(attachment) {
                const isImage = attachment.is_image == 1;
                const isSelected = selectedAttachments.has(attachment.id);
                const selectedClass = isSelected ? 'selected' : '';
                const fileSize = formatFileSize(attachment.size);
                const uploadTime = attachment.upload_time;
                const host = (window.__WS_CONFIG__ || {}).site?.host || window.site?.host || '';
                let thumbContent = '';
                if (isImage) {
                    thumbContent = `<img src="${attachment.url}" alt="${attachment.name}" class="img-fluid">`;
                } else {
                    const iconClass = getFileIconClass(attachment.ext);
                    thumbContent = `<i class="bi ${iconClass} file-icon"></i>`;
                }
                
                return `
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="card attachment-card ${selectedClass}" data-id="${attachment.id}">
                            <div class="attachment-thumb">
                                ${thumbContent}
                            </div>
                            <div class="card-body">
                                <h6 class="card-title text-truncate" title="${attachment.name}">${attachment.name}</h6>
                                <div class="card-text small text-muted">
                                    <span>${fileSize}</span>
                                    <p>${uploadTime}</p>
                                </div>
                                <div class="btn-group w-100">
                                    <button class="btn btn-sm btn-outline-primary view-btn" data-id="${attachment.id}">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a class="btn btn-sm btn-outline-info" href="/admin/attachment/download?id=${attachment.id}" title="下载" onclick="event.stopPropagation();">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-success copy-btn" data-clipboard-text="${host}${attachment.url}">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger delete-btn" data-id="${attachment.id}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            function renderPagination(data) {
                const $paginationContainer = $('#paginationContainer');
                const totalPages = data.total_page;
                
                if (totalPages <= 1) {
                    $paginationContainer.html('');
                    return;
                }
                
                let html = '<ul class="pagination justify-content-center">';
                
                // 上一页
                if (currentPage > 1) {
                    html += `<li class="page-item">
                        <a class="page-link" href="javascript:void(0)" data-page="${currentPage - 1}">上一页</a>
                    </li>`;
                }
                
                // 页码
                for (let i = 1; i <= totalPages; i++) {
                    const active = i === currentPage ? 'active' : '';
                    html += `<li class="page-item ${active}">
                        <a class="page-link" href="javascript:void(0)" data-page="${i}">${i}</a>
                    </li>`;
                }
                
                // 下一页
                if (currentPage < totalPages) {
                    html += `<li class="page-item">
                        <a class="page-link" href="javascript:void(0)" data-page="${currentPage + 1}">下一页</a>
                    </li>`;
                }
                
                html += '</ul>';
                $paginationContainer.html(html);
                
                // 绑定分页事件
                $paginationContainer.on('click', '.page-link', (e) => {
                    e.preventDefault();
                    const page = $(e.target).data('page');
                    if (page) {
                        currentPage = page;
                        loadAttachments();
                    }
                });
            }
            
            // 文件图标映射
            function getFileIconClass(ext) {
                const iconMap = {
                    'jpg': 'bi-filetype-jpg',
                    'jpeg': 'bi-filetype-jpg',
                    'png': 'bi-filetype-png',
                    'gif': 'bi-filetype-gif',
                    'bmp': 'bi-filetype-bmp',
                    'pdf': 'bi-file-earmark-pdf',
                    'doc': 'bi-file-earmark-word',
                    'docx': 'bi-file-earmark-word',
                    'xls': 'bi-file-earmark-excel',
                    'xlsx': 'bi-file-earmark-excel',
                    'ppt': 'bi-file-earmark-ppt',
                    'pptx': 'bi-file-earmark-ppt',
                    'zip': 'bi-file-earmark-zip',
                    'rar': 'bi-file-earmark-zip',
                    '7z': 'bi-file-earmark-zip',
                    'txt': 'bi-file-earmark-text',
                    'mp3': 'bi-file-earmark-music',
                    'mp4': 'bi-file-earmark-play',
                    'avi': 'bi-file-earmark-play',
                    'mov': 'bi-file-earmark-play'
                };
                
                return iconMap[ext.toLowerCase()] || 'bi-file-earmark';
            }
            
            // 格式化文件大小
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 B';
                
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            // 附件选择处理
            function initAttachmentSelection() {
                $('#attachmentList').on('click', '.attachment-card', (e) => {
                    // 防止点击按钮时触发选择
                    if ($(e.target).closest('.btn-group').length === 0) {
                        const $card = $(e.currentTarget);
                        const attachmentId = $card.data('id');
                        
                        if (selectedAttachments.has(attachmentId)) {
                            selectedAttachments.delete(attachmentId);
                            $card.removeClass('selected');
                        } else {
                            selectedAttachments.add(attachmentId);
                            $card.addClass('selected');
                        }
                        
                        updateBatchToolbar();
                    }
                });
                
                // 查看按钮
                $('#attachmentList').on('click', '.view-btn', (e) => {
                    e.stopPropagation();
                    const attachmentId = $(e.target).closest('.view-btn').data('id');
                    viewAttachment(attachmentId);
                });
                
                // 复制URL按钮
                var clipboard = new Clipboard('.copy-btn');
                clipboard.on('success', function(e) {
                    Layer.toast({content: 'URL已复制到剪贴板', type: 'success'});
                });
                
                // 删除按钮
                $('#attachmentList').on('click', '.delete-btn', (e) => {
                    e.stopPropagation();
                    const attachmentId = $(e.target).closest('.delete-btn').data('id');
                    deleteAttachment(attachmentId);
                });
            }
            
            // 更新批量操作工具栏
            function updateBatchToolbar() {
                $('#selectedCount').text(`已选择 ${selectedAttachments.size} 个文件`);
                if (selectedAttachments.size > 0) {
                    $('#batchToolbar button').prop('disabled', false);
                } else {
                    $('#batchToolbar button').prop('disabled', true);
                }
            }
            
            // 清空选择
            function clearSelection() {
                selectedAttachments.clear();
                $('.attachment-card').removeClass('selected');
                updateBatchToolbar();
            }
            
            // 查看附件
            function viewAttachment(attachmentId) {
                // 这里可以打开预览模态框或新窗口查看文件
                window.open(`/admin/attachment/info?id=${attachmentId}`, '_blank');
            }
                        
            // 删除单个附件
            function deleteAttachment(attachmentId) {
                Layer.confirm('确定要删除这个文件吗？', {btn: ['确定删除', '取消']}, function(){
                    $.post('/admin/attachment/delete', { ids: attachmentId }, (result) => {
                        if (result.code == 0) {
                            Layer.toast({content: '删除成功', type: 'success'});
                            loadAttachments();
                            selectedAttachments.delete(attachmentId);
                            updateBatchToolbar();
                        } else {
                            Layer.toast({content: '删除失败: ' + result.msg, type: 'danger'});
                        }
                    });
                });
            }
            
            // 批量删除
            function batchDelete() {
                if (selectedAttachments.size === 0) {
                    Layer.toast({content: '请先选择要删除的文件', type: 'warning'});
                    return;
                }
                
                Layer.confirm(`确定要删除选中的 ${selectedAttachments.size} 个文件吗？`, {btn: ['确定删除', '取消']}, function(){
                    const ids = Array.from(selectedAttachments).join(',');
                    
                    $.post('/admin/attachment/delete', { ids: ids }, (result) => {
                        if (result.code == 0) {
                            Layer.toast({content: '批量删除成功', type: 'success'});
                            loadAttachments();
                            clearSelection();
                        } else {
                            Layer.toast({content: '删除失败: ' + result.msg, type: 'danger'});
                        }
                    });
                });
            }
            
            // 批量下载
            function batchDownload() {
                if (selectedAttachments.size === 0) {
                    Layer.toast({content: '请先选择要下载的文件', type: 'warning'});
                    return;
                }
                if (selectedAttachments.size > 50) {
                    Layer.toast({content: '单次最多下载 50 个文件', type: 'warning'});
                    return;
                }
                
                const ids = Array.from(selectedAttachments).join(',');
                // 创建隐藏表单提交（触发下载）
                const form = $('<form method="POST" action="/admin/attachment/batchDownload" style="display:none;"></form>');
                form.append('<input type="hidden" name="ids" value="' + ids + '">');
                $('body').append(form);
                form.submit();
                setTimeout(() => form.remove(), 1000);
            }
            
            // 显示移动分类模态框
            function showMoveCategoryModal() {
                if (selectedAttachments.size === 0) {
                    Layer.toast({content: '请先选择要移动的文件', type: 'warning'});
                    return;
                }
                
                $('#moveCategoryModal').modal('show');
            }
            
            // 批量移动分类
            function batchMoveCategory() {
                const targetCategoryId = $('#targetCategory').val();
                const ids = Array.from(selectedAttachments).join(',');
                
                $.post('/admin/attachment/moveCategory', {
                    ids: ids,
                    category_id: targetCategoryId
                }, (result) => {
                    if (result.code == 0) {
                        Layer.toast({content: '移动成功', type: 'success'});
                        $('#moveCategoryModal').modal('hide');
                        loadAttachments();
                        clearSelection();
                    } else {
                        Layer.toast({content: '移动失败: ' + result.msg, type: 'danger'});
                    }
                });
            }
            
            // 上传相关方法
            function addFilesToQueue(files) {
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    uploadQueue.push({
                        file: file,
                        status: 'pending', // pending, uploading, success, error
                        progress: 0
                    });
                }
                
                renderUploadQueue();
                $('#startUploadBtn').prop('disabled', false);
            }
            
            function renderUploadQueue() {
                const $uploadQueue = $('#uploadQueue');
                let html = '';
                
                uploadQueue.forEach((item, index) => {
                    const file = item.file;
                    const statusIcon = getUploadStatusIcon(item.status);
                    const progressBar = item.status === 'uploading' ? 
                        `<div class="progress mt-1">
                            <div class="progress-bar" style="width: ${item.progress}%"></div>
                        </div>` : '';
                    
                    html += `
                        <div class="d-flex align-items-center mb-2 upload-queue-item" data-index="${index}">
                            <div class="me-2">${statusIcon}</div>
                            <div class="flex-grow-1">
                                <div class="small">${file.name}</div>
                                ${progressBar}
                            </div>
                            <div class="text-muted small">${formatFileSize(file.size)}</div>
                        </div>
                    `;
                });
                
                $uploadQueue.html(html);
            }
            
            function getUploadStatusIcon(status) {
                const icons = {
                    'pending': '<i class="bi bi-clock text-warning"></i>',
                    'uploading': '<i class="bi bi-arrow-up-circle text-primary"></i>',
                    'success': '<i class="bi bi-check-circle text-success"></i>',
                    'error': '<i class="bi bi-x-circle text-danger"></i>'
                };
                return icons[status] || icons.pending;
            }
            
            function startUpload() {
                if (isUploading || uploadQueue.length === 0) {
                    return;
                }
                
                isUploading = true;
                $('#startUploadBtn').prop('disabled', true);
                $('#uploadProgress').show();
                
                uploadNextFile();
            }
            
            function uploadNextFile() {
                const pendingItem = uploadQueue.find(item => item.status === 'pending');
                
                if (!pendingItem) {
                    // 所有文件上传完成
                    isUploading = false;
                    $('#uploadProgress').hide();
                    Layer.toast({content: '所有文件上传完成', type: 'success'});
                    
                    // 刷新附件列表
                    loadAttachments();
                    return;
                }
                
                pendingItem.status = 'uploading';
                renderUploadQueue();
                
                const formData = new FormData();
                formData.append('file', pendingItem.file);
                formData.append('category_id', currentCategory);
                
                $.ajax({
                    url: '/admin/attachment/upload',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: () => {
                        const xhr = new XMLHttpRequest();
                        
                        xhr.upload.addEventListener('progress', (e) => {
                            if (e.lengthComputable) {
                                const percent = Math.round((e.loaded / e.total) * 100);
                                pendingItem.progress = percent;
                                
                                $('#uploadProgressBar').css('width', percent + '%');
                                $('#uploadPercent').text(percent + '%');
                                $('#uploadStatus').text(`正在上传: ${pendingItem.file.name}`);
                                
                                renderUploadQueue();
                            }
                        }, false);
                        
                        return xhr;
                    },
                    success: (result) => {
                        if (result.code == 0) {
                            pendingItem.status = 'success';
                            Layer.toast({content: `文件 ${pendingItem.file.name} 上传成功`, type: 'success'});
                        } else {
                            pendingItem.status = 'error';
                            Layer.toast({content: `文件 ${pendingItem.file.name} 上传失败: ${result.msg}`, type: 'danger'});
                        }
                        
                        renderUploadQueue();
                        uploadNextFile();
                    },
                    error: () => {
                        pendingItem.status = 'error';
                        Layer.toast({content: `文件 ${pendingItem.file.name} 上传失败: 网络错误`, type: 'danger'});
                        renderUploadQueue();
                        uploadNextFile();
                    }
                });
            }

            // 初始化事件和数据
            init();
        }
    }

export default Controller;
