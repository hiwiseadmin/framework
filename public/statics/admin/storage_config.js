/**
 * storage_config.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const FormValidator = window.FormValidator;
const Layer = window.parent.Layer || window.Layer;

const Controller = {
        index() {
            var storageConfigs = [];
            var storageDrivers = {};
        
            function init() {
                initEvents();
                loadStorageDrivers();
                loadStorageConfigs();
            }
            
            function initEvents() {
                // 添加配置按钮
                $('#addStorageBtn').on('click', () => {
                    showAddModal();
                });
                
                // 保存配置按钮
                $('#saveStorageBtn').on('click', () => {
                    saveStorageConfig();
                });
                
                // 存储类型变更事件
                $('#storageDriver').on('change', () => {
                    showConfigFields();
                });
                
                // 表单提交
                $('#storageForm').on('submit', (e) => {
                    e.preventDefault();
                    saveStorageConfig();
                });
            }
            
            // 加载存储类型（wise-attachment 包支持的 5 种驱动）
            function loadStorageDrivers() {
                $.get('/admin/storage_config/drivers', (result) => {
                    if (result.code == 0) {
                        storageDrivers = result.data;
                        renderStorageDriverOptions();
                    } else {
                        showAlert('加载存储类型失败: ' + result.msg, 'danger');
                    }
                });
            }
            
            // 渲染存储类型选项
            function renderStorageDriverOptions() {
                const $select = $('#storageDriver');
                $select.empty().append('<option value="">请选择存储类型</option>');
                
                for (const [key, value] of Object.entries(storageDrivers)) {
                    $select.append(`<option value="${key}">${value}</option>`);
                }
            }
            
            // 显示配置字段（支持 5 种驱动）
            function showConfigFields() {
                const driver = $('#storageDriver').val();
                
                // 隐藏所有配置字段
                $('.config-field').hide();
                
                // 显示对应的配置字段
                if (driver) {
                    const configEl = $(`#${driver}Config`);
                    if (configEl.length) {
                        configEl.show();
                    }
                }
            }
            
            // 加载存储配置列表
            function loadStorageConfigs() {
                $.get('/admin/storage_config/getList', (result) => {
                    if (result.code == 0) {
                        storageConfigs = result.data;
                        renderStorageConfigs();
                    } else {
                        showAlert('加载存储配置失败: ' + result.msg, 'danger');
                    }
                });
            }
            
            // 渲染存储配置卡片
            function renderStorageConfigs() {
                const $container = $('#storageConfigs');
                
                if (storageConfigs.length === 0) {
                    $container.html(`
                        <div class="col-12 text-center py-5">
                            <i class="bi bi-hdd display-1 text-muted"></i>
                            <p class="mt-3 text-muted">暂无存储配置</p>
                        </div>
                    `);
                    return;
                }
                
                let html = '';
                storageConfigs.forEach(config => {
                    const isDefault = config.is_default == 1;
                    const defaultBadge = isDefault ? '<span class="badge bg-success ms-2">默认</span>' : '';
                    const statusBadge = config.status == 1 ? 
                        '<span class="badge bg-success">启用</span>' : 
                        '<span class="badge bg-secondary">禁用</span>';
                    
                    html += `
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card storage-card ${isDefault ? 'storage-default' : ''} h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        ${config.name} ${defaultBadge}
                                    </h5>
                                    ${statusBadge}
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <small class="text-muted">类型:</small>
                                        <span class="ms-2">${storageDrivers[config.driver] || config.driver}</span>
                                    </div>
                                    <div class="mb-2">
                                        <small class="text-muted">ID:</small>
                                        <span class="ms-2">${config.id}</span>
                                    </div>
                                    <div class="mb-3">
                                        <small class="text-muted">创建时间:</small>
                                        <span class="ms-2">${config.created_at || config.create_time}</span>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <div class="btn-group w-100">
                                        <button class="btn btn-sm btn-outline-primary edit-storage" data-id="${config.id}">
                                            <i class="bi bi-pencil"></i> 编辑
                                        </button>
                                        <button class="btn btn-sm btn-outline-success test-storage" data-id="${config.id}">
                                            <i class="bi bi-plug"></i> 测试
                                        </button>
                                        <button class="btn btn-sm btn-outline-info toggle-config-detail" data-id="${config.id}">
                                            <i class="bi bi-journal-code"></i> 详情
                                        </button>
                                        ${!isDefault ? `
                                            <button class="btn btn-sm btn-outline-warning set-default-storage" data-id="${config.id}">
                                                <i class="bi bi-star"></i> 设默认
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-storage" data-id="${config.id}">
                                                <i class="bi bi-trash"></i> 删除
                                            </button>
                                        ` : ''}
                                    </div>
                                    <div class="config-detail mt-2" id="config-detail-${config.id}" style="display:none;">
                                        <pre class="bg-light p-2 rounded small mb-0" style="max-height:150px;overflow:auto;"><code>${JSON.stringify(config.config || {}, null, 2)}</code></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                $container.html(html);
                
                // 绑定事件
                $container.on('click', '.edit-storage', (e) => {
                    const configId = $(e.currentTarget).data('id');
                    showEditModal(configId);
                });
                
                $container.on('click', '.test-storage', (e) => {
                    const configId = $(e.currentTarget).data('id');
                    testStorageConfig(configId);
                });
                
                $container.on('click', '.set-default-storage', (e) => {
                    const configId = $(e.currentTarget).data('id');
                    setDefaultStorage(configId);
                });
                
                $container.on('click', '.delete-storage', (e) => {
                    const configId = $(e.currentTarget).data('id');
                    deleteStorageConfig(configId);
                });

                $container.on('click', '.toggle-config-detail', (e) => {
                    const configId = $(e.currentTarget).data('id');
                    $(`#config-detail-${configId}`).slideToggle(200);
                });
            }
            
            // 显示添加模态框
            function showAddModal() {
                $('#storageModalLabel').text('添加存储配置');
                $('#storageForm')[0].reset();
                $('#storageId').val('');
                $('#storageStatus').val('1');
                $('#storageDefault').val('0');
                showConfigFields();
                $('#storageModal').modal('show');
            }
            
            // 显示编辑模态框
            function showEditModal(configId) {
                const config = storageConfigs.find(c => c.id == configId);
                if (!config) return;
                
                $('#storageModalLabel').text('编辑存储配置');
                $('#storageId').val(config.id);
                $('#storageName').val(config.name);
                $('#storageDriver').val(config.driver);
                $('#storageStatus').val(config.status);
                $('#storageDefault').val(config.is_default);
                
                // 填充配置字段
                fillConfigFields(config.driver, config.config);
                
                showConfigFields();
                $('#storageModal').modal('show');
            }
            
            // 填充配置字段（支持 5 种驱动）
            function fillConfigFields(driver, config) {
                const configObj = config || {};
                switch (driver) {
                    case 'local':
                        $('#localRoot').val(configObj.root_path || configObj.root || '');
                        $('#localUrl').val(configObj.base_url || configObj.url || '');
                        break;
                    case 'ftp':
                        $('#ftpHost').val(configObj.host || '');
                        $('#ftpPort').val(configObj.port || '21');
                        $('#ftpUsername').val(configObj.username || '');
                        $('#ftpPassword').val(configObj.password || '');
                        $('#ftpRoot').val(configObj.root || '/');
                        $('#ftpBaseUrl').val(configObj.base_url || '');
                        break;
                    case 'qiniu':
                        $('#qiniuAccessKey').val(configObj.access_key || '');
                        $('#qiniuSecretKey').val(configObj.secret_key || '');
                        $('#qiniuBucket').val(configObj.bucket || '');
                        $('#qiniuDomain').val(configObj.domain || '');
                        break;
                    case 'alioss':
                        $('#aliossAccessKey').val(configObj.access_key || '');
                        $('#aliossSecretKey').val(configObj.secret_key || '');
                        $('#aliossBucket').val(configObj.bucket || '');
                        $('#aliossEndpoint').val(configObj.endpoint || '');
                        $('#aliossDomain').val(configObj.domain || '');
                        break;
                    case 'cos':
                        $('#cosAccessKey').val(configObj.access_key || '');
                        $('#cosSecretKey').val(configObj.secret_key || '');
                        $('#cosBucket').val(configObj.bucket || '');
                        $('#cosRegion').val(configObj.region || '');
                        $('#cosDomain').val(configObj.domain || '');
                        break;
                }
            }
            
            // 保存存储配置
            function saveStorageConfig() {
                const $btn = $('#saveStorageBtn');
                if ($btn.prop('disabled')) return; // 防止重复提交
                
                const formData = $('#storageForm').serializeArray();
                const data = {};
                
                // 处理表单数据
                formData.forEach(item => {
                    if (item.name.startsWith('config[')) {
                        // 配置字段
                        const key = item.name.match(/config\[(.*?)\]/)[1];
                        if (!data.config) data.config = {};
                        data.config[key] = item.value;
                    } else {
                        data[item.name] = item.value;
                    }
                });
                
                // 验证必要字段
                if (!data.name || !data.driver) {
                    showAlert('请填写完整的配置信息', 'warning');
                    return;
                }
                
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>保存中...');
                
                const url = data.id ? '/admin/storage_config/update' : '/admin/storage_config/add';
                
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: data,
                    success: (result) => {
                        if (result.code == 0) {
                            showAlert('保存成功', 'success');
                            $('#storageModal').modal('hide');
                            loadStorageConfigs();
                        } else {
                            showAlert('保存失败: ' + result.msg, 'danger');
                        }
                    },
                    error: () => {
                        showAlert('网络错误，请稍后重试', 'danger');
                    },
                    complete: () => {
                        $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i>保存');
                    }
                });
            }
            
            // 测试存储配置
            function testStorageConfig(configId) {
                Layer.confirm('确定要测试此存储配置的连接吗？', function(index){                
                    $.post('/admin/storage_config/test', { id: configId }, (result) => {
                        if (result.code == 0) {
                            showAlert('连接测试成功', 'success');
                        } else {
                            showAlert('连接测试失败: ' + result.msg, 'danger');
                        }
                    });
                    Layer.close(index);
                });
            }
            
            // 设置默认存储配置
            function setDefaultStorage(configId) {
                Layer.confirm('确定要设置此配置为默认存储吗？', function(index){ 
                    $.post('/admin/storage_config/set_default', { id: configId }, (result) => {
                        if (result.code == 0) {
                            showAlert('设置成功', 'success');
                            loadStorageConfigs();
                        } else {
                            showAlert('设置失败: ' + result.msg, 'danger');
                        }
                        Layer.close(index);
                    });
                });
            }
            
            // 删除存储配置
            function deleteStorageConfig(configId) {
                const config = storageConfigs.find(c => c.id == configId);
                if (!config) return;
                
                Layer.confirm('确定要删除存储配置 "' + config.name + '" 吗？此操作不可恢复。', function(index){ 
                    $.post('/admin/storage_config/delete', { id: configId }, (result) => {
                        if (result.code == 0) {
                            showAlert('删除成功', 'success');
                            loadStorageConfigs();
                        } else {
                            showAlert('删除失败: ' + result.msg, 'danger');
                        }
                        Layer.close(index);
                    });
                });
            }
            
            // 显示提示消息
            function showAlert(message, type = 'info') {
                const alertId = 'alert-' + Date.now();
                const alertHtml = `
                    <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 1050;">
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                
                $('body').append(alertHtml);
                
                setTimeout(() => {
                    $('#' + alertId).alert('close');
                }, 3000);
            }

            // 初始化事件和数据
            init();
            
            // ========== 图片处理配置 ==========
            loadImageConfig();
            setupImageConfigEvents();

            function loadImageConfig() {
                $.get('/admin/storage_config/getImageConfig', (r) => {
                    if (r.code === 0) {
                        var d = r.data;
                        $('#imageQuality').val(d.quality).trigger('input');
                        $('#watermarkEnabled').prop('checked', d.watermark_enabled == 1);
                        $('input[name="watermarkType"][value="' + (d.watermark_type || 'text') + '"]').prop('checked', true);
                        $('#watermarkText').val(d.watermark_text);
                        $('#watermarkPosition').val(d.watermark_position);
                        $('#watermarkOpacity').val(d.watermark_opacity);
                        $('#watermarkTextGroup').toggleClass('d-none', d.watermark_type === 'image');
                        toggleWatermarkDetails();

                        // 九宫格高亮
                        $('.watermark-position-grid .wp-cell').removeClass('active');
                        $('.watermark-position-grid .wp-cell[data-pos="' + (d.watermark_position || 'bottom-right') + '"]').addClass('active');

                        // 缩略图表格
                        var rows = '';
                        if (d.whitelist_sizes) {
                            for (var key in d.whitelist_sizes) {
                                var sz = d.whitelist_sizes[key];
                                var w = parseInt(sz.width) || 0;
                                var h = parseInt(sz.height) || 0;
                                var maxDim = 80, ratio = (w && h) ? Math.min(maxDim / Math.max(w, h), 1) : 0.3;
                                rows += '<tr><td><code>' + key + '</code></td><td>' + w + ' × ' + h + '</td><td>' + (sz.mode || 'fit') + '</td><td>' +
                                    '<div class="d-inline-block border rounded bg-light position-relative" style="width:' + Math.round(w*ratio) + 'px;height:' + Math.round(h*ratio) + 'px;min-width:20px;min-height:20px;">' +
                                    '<small class="position-absolute top-50 start-50 translate-middle text-muted" style="font-size:10px;">' + w + '×' + h + '</small></div></td></tr>';
                            }
                        }
                        $('#thumbnailSizesBody').html(rows || '<tr><td colspan="4" class="text-muted text-center py-3"><i class="bi bi-inbox me-1"></i>暂无预设尺寸</td></tr>');
                    }
                });
            }

            function setupImageConfigEvents() {
                // 水印开关
                $('#watermarkEnabled').on('change', toggleWatermarkDetails);
                // 水印类型
                $('input[name="watermarkType"]').on('change', function() {
                    $('#watermarkTextGroup').toggleClass('d-none', this.value === 'image');
                });
                // 九宫格位置选择
                $('.watermark-position-grid').on('click', '.wp-cell', function() {
                    var pos = $(this).data('pos');
                    $('.watermark-position-grid .wp-cell').removeClass('active');
                    $(this).addClass('active');
                    $('#watermarkPosition').val(pos);
                });
                // 配置说明
                $('#openConfigHint').on('click', function(e) {
                    e.preventDefault();
                    Layer.alert(
                        '<h6>配置项说明</h6>' +
                        '<ul class="small text-start mb-2">' +
                        '<li><code>image.default_quality</code> — 默认 JPEG/WebP 质量 (10-100)</li>' +
                        '<li><code>image.watermark.enabled</code> — 启用水印 (true/false)</li>' +
                        '<li><code>image.watermark.type</code> — text 或 image</li>' +
                        '<li><code>image.watermark.text.content</code> — 水印文字</li>' +
                        '<li><code>image.watermark.text.position</code> — top-left / center / bottom-right 等</li>' +
                        '<li><code>image.watermark.text.opacity</code> — 透明度 (0-100)</li>' +
                        '<li><code>image.whitelist_sizes</code> — 允许的缩略图尺寸</li></ul>' +
                        '<p class="small text-muted mb-0">修改 <code>config/wise-attachment.php</code> 后刷新缓存生效。</p>',
                        {title: '📋 配置项说明', area:['500px','auto'], btn:['知道了']}
                    );
                });
            }

            function toggleWatermarkDetails() {
                var enabled = $('#watermarkEnabled').prop('checked');
                $('#watermarkDetails').css('opacity', enabled ? 1 : 0.4);
            }
        }
    }

export default Controller;
