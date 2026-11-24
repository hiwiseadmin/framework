/**
 * Wise.upload — 通用上传（ESM）
 *
 * 头像模式 + 通用文件模式 + 完整头像上传器（含预设头像、Cropper 裁切、旋转缩放）
 * 不依赖旧的 avatar-upload.js。
 *
 * @module wise-upload
 * @version 2.0.0
 */

import { registry, eventBus, logger } from './wise-core.js';
import { http } from './wise-util.js';

// ========== 工具函数 ==========

/** 选择器速记（兼容 jQuery 对象 / 字符串 / DOM） */
function $(selector, parent) {
    if (selector instanceof HTMLElement || selector instanceof Document) return selector;
    return (parent || document).querySelector(selector);
}

function $$(selector, parent) {
    return Array.from((parent || document).querySelectorAll(selector));
}

/** 创建元素并设置属性 */
function createEl(tag, attrs = {}, children = []) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (k === 'className') el.className = v;
        else if (k === 'innerHTML') el.innerHTML = v;
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (k.startsWith('data')) el.setAttribute(k.replace(/([A-Z])/g, '-$1').toLowerCase(), v);
        else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
        else el.setAttribute(k, v);
    }
    children.forEach(c => { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
}

// ========== Toast 兼容层 ==========
function showToast(msg, type = 'success') {
    // 优先使用全局 Layer
    try {
        if (window.Layer && typeof window.Layer.top === 'function') {
            window.Layer.top().toast({ content: msg, type });
            return;
        }
        if (window.Layer && typeof window.Layer.toast === 'function') {
            window.Layer.toast({ content: msg, type });
            return;
        }
    } catch (_) { /* fallback */ }
    // 回退到 eventBus 事件
    eventBus.emit('ws:toast', { message: msg, type });
}

// ========== Cropper 检测 ==========
function getCropper() {
    if (window.Cropper) return window.Cropper;
    // 可能挂在 requirejs 已加载模块
    if (window.requirejs && window.requirejs.defined && window.requirejs.defined('cropper')) {
        return window.requirejs('cropper');
    }
    return null;
}

const upload = {
    /**
     * 头像上传（含裁切预览）
     * @param {Object} options
     * @param {string|HTMLElement} options.el - 触发元素
     * @param {string} options.url - 上传地址
     * @param {number} [options.maxSize=2] - MB
     * @param {string[]} [options.accept=['image/*']] - 接受的文件类型
     * @param {Function} [options.onSuccess] - (url) => void
     * @param {Function} [options.onError] - (err) => void
     * @param {Function} [options.onProgress] - (percent) => void
     */
    avatar(options) {
        const {
            el, url, maxSize = 2, accept = ['image/*'],
            onSuccess, onError, onProgress,
        } = options;

        const trigger = typeof el === 'string' ? document.querySelector(el) : el;
        if (!trigger) return;

        const input = document.createElement('input');
        input.type = 'file';
        input.accept = accept.join(',');
        input.style.display = 'none';
        document.body.appendChild(input);

        trigger.addEventListener('click', () => input.click());

        input.addEventListener('change', async () => {
            const file = input.files[0];
            if (!file) return;

            if (file.size > maxSize * 1024 * 1024) {
                eventBus.emit('ws:upload:error', { file, error: '文件过大' });
                if (onError) onError(new Error(`文件不能超过 ${maxSize}MB`));
                return;
            }

            try {
                const formData = new FormData();
                formData.append('file', file);

                const result = await http.upload(url, formData, {
                    onProgress: (pct) => {
                        if (onProgress) onProgress(pct);
                        eventBus.emit('ws:upload:progress', { file, percent: pct });
                    },
                });

                const avatarUrl = result.url || result;
                if (onSuccess) onSuccess(avatarUrl);
                eventBus.emit('ws:upload:success', { file, result });
            } catch (err) {
                logger.error('upload', '上传失败', err);
                if (onError) onError(err);
                eventBus.emit('ws:upload:error', { file, error: err });
            }

            input.value = '';
        });
    },

    /**
     * 通用文件上传
     * @param {Object} options
     * @param {string|HTMLElement} options.el - 触发元素或容器
     * @param {string} options.url - 上传地址
     * @param {number} [options.maxSize=10] - MB
     * @param {string[]} [options.accept] - 文件类型
     * @param {boolean} [options.multiple=false] - 多文件
     * @param {boolean} [options.dropzone=false] - 拖拽上传
     * @param {Function} [options.onSuccess] - (file, result) => void
     * @param {Function} [options.onError] - (file, err) => void
     * @param {Function} [options.onProgress] - (file, percent) => void
     * @returns {{ destroy: Function }} 清理函数
     */
    file(options) {
        const {
            el, url, maxSize = 10, accept = [],
            multiple = false, dropzone = false,
            onSuccess, onError, onProgress,
        } = options;

        const container = typeof el === 'string' ? document.querySelector(el) : el;
        if (!container) return { destroy() {} };

        const uploadFile = async (file) => {
            if (file.size > maxSize * 1024 * 1024) {
                if (onError) onError(file, new Error(`文件 ${file.name} 超过 ${maxSize}MB`));
                return;
            }

            try {
                const formData = new FormData();
                formData.append('file', file);

                const result = await http.upload(url, formData);
                if (onSuccess) onSuccess(file, result);
                eventBus.emit('ws:upload:success', { file, result });
            } catch (err) {
                if (onError) onError(file, err);
                eventBus.emit('ws:upload:error', { file, error: err });
            }
        };

        // 点击选择
        const input = document.createElement('input');
        input.type = 'file';
        if (accept.length) input.accept = accept.join(',');
        input.multiple = multiple;
        input.style.display = 'none';
        document.body.appendChild(input);

        container.addEventListener('click', (e) => {
            if (e.target === container || container.contains(e.target)) {
                input.click();
            }
        });

        input.addEventListener('change', () => {
            [...input.files].forEach(uploadFile);
            input.value = '';
        });

        // 拖拽
        if (dropzone) {
            const prevent = (e) => { e.preventDefault(); e.stopPropagation(); };
            container.addEventListener('dragenter', prevent);
            container.addEventListener('dragover', prevent);
            container.addEventListener('dragleave', prevent);
            container.addEventListener('drop', (e) => {
                prevent(e);
                [...(e.dataTransfer?.files || [])].forEach(uploadFile);
            });
        }

        return {
            destroy() {
                input.remove();
            },
        };
    },

    // ============================================================
    // 完整头像上传器（模态框 + 预设头像 + Cropper 裁切）
    // ============================================================

    /**
     * 头像上传器（完整功能：预设头像 + 上传裁切 + 旋转/缩放 + 保存）
     *
     * @param {Object} options
     * @param {string|HTMLElement} options.modal        - 模态框选择器或元素（#avatarSelectModal）
     * @param {string}  [options.presetUrl]              - 预设头像 API 地址
     * @param {string}  [options.presetContainer]        - 预设头像容器选择器（#presetAvatarsContainer）
     * @param {string}  [options.uploadCroppedAvatarUrl] - 上传裁切后头像的 API 地址
     * @param {string}  [options.updateAvatarUrl]        - 更新用户头像的 API 地址
     * @param {string}  [options.uploadArea]             - 上传区域选择器（#uploadArea）
     * @param {string}  [options.imageInput]             - 文件输入选择器（#imageUpload）
     * @param {string}  [options.selectImageBtn]         - 选择图片按钮（#selectImageBtn）
     * @param {string}  [options.cropperContainer]       - 裁切容器（#cropperContainer）
     * @param {string}  [options.cropperImage]           - 待裁切图片（#imageToCrop）
     * @param {string}  [options.previewContainer]       - 预览容器（#previewContainer）
     * @param {string}  [options.avatarPreview]          - 预览图片（#avatarPreview）
     * @param {string}  [options.saveBtn]                - 保存按钮（#saveAvatarBtn）
     * @param {string}  [options.uploadTips]             - 上传提示（#uploadTips）
     * @param {string}  [options.resetUploadBtn]         - 重新选择按钮（#resetUploadBtn）
     * @param {string}  [options.rotateBtns]             - 旋转按钮（.rotateBtn）
     * @param {string}  [options.rotateAngleInput]       - 旋转角度输入（#rotateAngle）
     * @param {string}  [options.zoomSlider]             - 缩放滑块（#zoomSlider）
     * @param {string}  [options.cropShapeRadios]        - 裁切形状单选（input[name="cropShape"]）
     * @param {string}  [options.tabContainer]           - 选项卡容器（#avatarTab）
     * @param {number}  [options.maxSize=2]              - 文件大小限制 MB
     * @param {string}  [options.cropShape='circle']     - 默认裁切形状
     * @param {number}  [options.cropWidth=300]          - 裁切输出宽度
     * @param {number}  [options.cropHeight=300]         - 裁切输出高度
     * @param {boolean} [options.showUploadType=true]    - 是否显示「上传头像」选项卡
     * @param {Function} [options.onSuccess]             - (avatarUrl) => void
     * @param {Function} [options.onError]               - (err) => void
     * @param {Function} [options.onModalShow]           - 模态框显示后回调
     * @param {Function} [options.onModalHide]           - 模态框关闭后回调
     * @returns {{ show: Function, hide: Function, destroy: Function }}
     */
    avatarUploader(options) {
        const opts = {
            // 选择器
            modal: '#avatarSelectModal',
            presetUrl: null,
            presetContainer: '#presetAvatarsContainer',
            uploadCroppedAvatarUrl: null,
            updateAvatarUrl: null,
            uploadArea: '#uploadArea',
            imageInput: '#imageUpload',
            selectImageBtn: '#selectImageBtn',
            cropperContainer: '#cropperContainer',
            cropperImage: '#imageToCrop',
            previewContainer: '#previewContainer',
            avatarPreview: '#avatarPreview',
            saveBtn: '#saveAvatarBtn',
            uploadTips: '#uploadTips',
            resetUploadBtn: '#resetUploadBtn',
            rotateBtns: '.rotateBtn',
            rotateAngleInput: '#rotateAngle',
            zoomSlider: '#zoomSlider',
            cropShapeRadios: 'input[name="cropShape"]',
            tabContainer: '#avatarTab',

            // 配置
            maxSize: 2,
            cropShape: 'circle',
            cropWidth: 300,
            cropHeight: 300,
            showUploadType: true,

            // 回调
            onSuccess: null,
            onError: null,
            onModalShow: null,
            onModalHide: null,

            ...options,
        };

        // ---------- 状态 ----------
        let cropper = null;
        let currentAvatarType = 'preset';
        let selectedAvatarUrl = '';
        let _inited = false;

        // ---------- DOM 缓存 ----------
        const modalEl = $(opts.modal);
        if (!modalEl) {
            logger.error('upload', `头像模态框未找到: ${opts.modal}`);
            return { show() {}, hide() {}, destroy() {} };
        }

        // ---------- 内部方法 ----------

        /** 设置按钮 loading 状态 */
        function setBtnLoading(btn, loading, originalText) {
            if (loading) {
                btn.disabled = true;
                btn._originalHTML = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>保存中...';
            } else {
                btn.disabled = false;
                btn.innerHTML = originalText || btn._originalHTML || '<i class="bi bi-check-circle me-1"></i>保存头像';
            }
        }

        /** 销毁裁切器 */
        function destroyCropper() {
            if (cropper) {
                try { cropper.destroy(); } catch (_) { /* 忽略 */ }
                cropper = null;
            }
        }

        /** 重置上传区域状态 */
        function resetUploadArea() {
            // 隐藏裁切/预览，显示上传区域
            const crp = $(opts.cropperContainer);
            const prev = $(opts.previewContainer);
            const area = $(opts.uploadArea);
            const tips = $(opts.uploadTips);

            if (crp) crp.classList.add('d-none');
            if (prev) prev.classList.add('d-none');
            if (area) area.classList.remove('d-none');
            if (tips) tips.classList.remove('d-none');

            // 重置文件输入
            const imgInput = $(opts.imageInput);
            if (imgInput) imgInput.value = '';

            // 销毁裁切器
            destroyCropper();

            // 重置状态
            currentAvatarType = 'preset';
            selectedAvatarUrl = '';

            // 禁用保存按钮
            const saveBtn = $(opts.saveBtn);
            if (saveBtn) saveBtn.disabled = true;
        }

        /** 更新头像预览（从 URL） */
        function updateAvatarPreview(url) {
            const preview = $(opts.avatarPreview);
            if (preview) preview.src = url;
        }

        /** 从 Cropper 更新预览 */
        function updateAvatarPreviewFromCropper() {
            if (!cropper) return;

            const canvas = cropper.getCroppedCanvas({
                width: 200,
                height: 200,
                fillColor: '#fff',
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            if (canvas) {
                const preview = $(opts.avatarPreview);
                if (preview) preview.src = canvas.toDataURL('image/png');
                currentAvatarType = 'upload';
            }
        }

        /** 加载预设头像 */
        function loadPresetAvatars() {
            if (!opts.presetUrl || !opts.presetContainer) return;

            const container = $(opts.presetContainer);
            if (!container) return;

            // interceptor=false：手动解析响应（兼容 code:200 和 code:1）
            http.get(opts.presetUrl, null, { interceptor: false, autoError: false })
                .then((resp) => {
                    let avatars = Array.isArray(resp) ? resp : (resp?.data || resp?.avatars || []);
                    container.innerHTML = '';

                    avatars.forEach(avatar => {
                        const avatarUrl = avatar.url || avatar;
                        const avatarId = avatar.id || avatarUrl;
                        const avatarName = avatar.name || '';

                        const col = createEl('div', { className: 'col-4 col-md-3' });
                        const item = createEl('div', {
                            className: 'avatar-item',
                            dataAvatarId: avatarId,
                            dataAvatarUrl: avatarUrl,
                        });

                        const ratio = createEl('div', { className: 'ratio ratio-1x1' });
                        const img = createEl('img', {
                            src: avatarUrl,
                            alt: avatarName,
                            className: 'avatar-img',
                        });
                        ratio.appendChild(img);
                        item.appendChild(ratio);
                        col.appendChild(item);
                        container.appendChild(col);

                        // 点击选中
                        item.addEventListener('click', () => {
                            $$('.avatar-item', container).forEach(it => it.classList.remove('selected'));
                            item.classList.add('selected');
                            selectedAvatarUrl = avatarUrl;
                            currentAvatarType = 'preset';
                            updateAvatarPreview(avatarUrl);
                            const saveBtn = $(opts.saveBtn);
                            if (saveBtn) saveBtn.disabled = false;
                        });
                    });
                })
                .catch((err) => {
                    if (container) {
                        container.innerHTML = `
                            <div class="col-12 text-center py-5">
                                <i class="bi bi-wifi-off display-4 text-muted mb-3"></i>
                                <p class="text-muted">加载预设头像失败</p>
                            </div>`;
                    }
                    logger.error('upload', '加载预设头像失败', err);
                });
        }

        /** 初始化 Cropper */
        function initCropper(imageSrc) {
            const CropperClass = getCropper();
            if (!CropperClass) {
                showToast('Cropper 组件未加载，请刷新页面后重试', 'error');
                return;
            }

            const imgEl = $(opts.cropperImage);
            if (!imgEl) return;

            // 销毁旧实例
            destroyCropper();

            imgEl.src = imageSrc;

            cropper = new CropperClass(imgEl, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 0.8,
                responsive: true,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                ready() {
                    updateAvatarPreviewFromCropper();
                },
                crop() {
                    updateAvatarPreviewFromCropper();
                },
            });

            // 应用默认裁切形状
            _applyCropShape(opts.cropShape);
        }

        /** 应用裁切形状 */
        function _applyCropShape(shape) {
            if (!cropper) return;

            const container = $(opts.cropperContainer);
            if (container) {
                if (shape === 'circle') {
                    container.classList.remove('cropper-square');
                    container.classList.add('cropper-circle');
                } else {
                    container.classList.remove('cropper-circle');
                    container.classList.add('cropper-square');
                }
            }

            cropper.setAspectRatio(1);
            updateAvatarPreviewFromCropper();
        }

        /** 处理图片文件 */
        function handleImageFile(file) {
            // 验证类型
            if (!file.type.match(/image\//)) {
                showToast('请选择图片文件（JPG、PNG格式）', 'error');
                return;
            }

            // 验证大小
            if (file.size > opts.maxSize * 1024 * 1024) {
                showToast(`图片大小不能超过${opts.maxSize}MB`, 'error');
                return;
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                // 隐藏上传区域
                const area = $(opts.uploadArea);
                const tips = $(opts.uploadTips);
                if (area) area.classList.add('d-none');
                if (tips) tips.classList.add('d-none');

                // 显示裁切和预览
                const crp = $(opts.cropperContainer);
                const prev = $(opts.previewContainer);
                if (crp) crp.classList.remove('d-none');
                if (prev) prev.classList.remove('d-none');

                // 初始化裁切器
                initCropper(e.target.result);

                // 启用保存按钮
                const saveBtn = $(opts.saveBtn);
                if (saveBtn) saveBtn.disabled = false;
            };
            reader.readAsDataURL(file);
        }

        /** 保存裁切后头像 */
        function saveCroppedAvatar(saveBtn) {
            if (!cropper) {
                showToast('请先上传图片', 'error');
                return;
            }

            if (!opts.uploadCroppedAvatarUrl) {
                showToast('请先配置上传裁切后的头像URL', 'error');
                setBtnLoading(saveBtn, false);
                return;
            }

            const canvas = cropper.getCroppedCanvas({
                width: opts.cropWidth,
                height: opts.cropHeight,
                fillColor: '#fff',
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            if (!canvas) {
                showToast('无法获取裁切后的图片', 'error');
                setBtnLoading(saveBtn, false);
                return;
            }

            const avatarData = canvas.toDataURL('image/png');

            // interceptor=false：手动解析响应（兼容 code:200 和 code:1）
            http.post(opts.uploadCroppedAvatarUrl, { avatar_data: avatarData }, { interceptor: false })
                .then((resp) => {
                    const result = resp?.data || resp || {};
                    const avatarUrl = result.avatar_url || result.url;
                    if (!avatarUrl) {
                        throw new Error('服务器未返回头像URL');
                    }
                    updateUserAvatar(avatarUrl, saveBtn);
                })
                .catch((err) => {
                    showToast(err.message || '网络错误，请稍后重试', 'error');
                    setBtnLoading(saveBtn, false);
                    if (opts.onError) opts.onError(err);
                });
        }

        /** 更新用户头像 */
        function updateUserAvatar(avatarUrl, saveBtn) {
            const doFinish = () => {
                setBtnLoading(saveBtn, false);
                hideModal();

                if (opts.onSuccess) {
                    opts.onSuccess(avatarUrl);
                }
                eventBus.emit('ws:upload:avatar:updated', { avatarUrl });
            };

            if (!opts.updateAvatarUrl) {
                doFinish();
                return;
            }

            // interceptor=false：手动解析响应
            http.post(opts.updateAvatarUrl, { avatar: avatarUrl }, { interceptor: false })
                .then(() => {
                    showToast('头像更新成功', 'success');
                    doFinish();
                })
                .catch((err) => {
                    showToast(err.message || '头像更新失败', 'error');
                    setBtnLoading(saveBtn, false);
                    // 即使更新失败，也触发回调（上传成功的URL）
                    if (opts.onSuccess) opts.onSuccess(avatarUrl);
                    if (opts.onError) opts.onError(err);
                });
        }

        /** 保存头像主入口 */
        function handleSave() {
            const saveBtn = $(opts.saveBtn);
            if (!saveBtn) return;

            setBtnLoading(saveBtn, true);

            if (currentAvatarType === 'preset') {
                if (!selectedAvatarUrl) {
                    showToast('请选择头像', 'error');
                    setBtnLoading(saveBtn, false);
                    return;
                }
                updateUserAvatar(selectedAvatarUrl, saveBtn);
            } else if (currentAvatarType === 'upload' && cropper) {
                saveCroppedAvatar(saveBtn);
            } else {
                showToast('请选择或上传头像', 'error');
                setBtnLoading(saveBtn, false);
            }
        }

        /** 显示模态框 */
        function showModal() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                bsModal.show();
            } else {
                // 回退：直接用 jQuery（如果有）
                if (window.$) {
                    window.$(modalEl).modal('show');
                } else {
                    modalEl.style.display = 'block';
                    modalEl.classList.add('show');
                }
            }
        }

        /** 隐藏模态框 */
        function hideModal() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            } else if (window.$) {
                window.$(modalEl).modal('hide');
            } else {
                modalEl.style.display = 'none';
                modalEl.classList.remove('show');
            }
        }

        // ---------- 事件绑定 ----------

        function bindEvents() {
            if (_inited) return;
            _inited = true;

            // --- 点击选择图片 ---
            const selectBtn = $(opts.selectImageBtn);
            const uploadAreaEl = $(opts.uploadArea);
            const imgInput = $(opts.imageInput);

            if (selectBtn && imgInput) {
                selectBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    imgInput.click();
                });
            }
            if (uploadAreaEl && imgInput) {
                uploadAreaEl.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    imgInput.click();
                });
            }

            // --- 拖拽上传 ---
            if (uploadAreaEl) {
                uploadAreaEl.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadAreaEl.classList.add('dragover');
                });
                uploadAreaEl.addEventListener('dragleave', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadAreaEl.classList.remove('dragover');
                });
                uploadAreaEl.addEventListener('drop', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    uploadAreaEl.classList.remove('dragover');

                    const files = e.dataTransfer?.files;
                    if (files && files.length > 0) {
                        handleImageFile(files[0]);
                    }
                });
            }

            // --- 文件选择 ---
            if (imgInput) {
                imgInput.addEventListener('change', function () {
                    if (this.files && this.files[0]) {
                        handleImageFile(this.files[0]);
                    }
                });
            }

            // --- 旋转按钮 ---
            $$(opts.rotateBtns).forEach(btn => {
                btn.addEventListener('click', () => {
                    if (!cropper) return;
                    const action = btn.getAttribute('data-action');
                    if (action === 'left') cropper.rotate(-90);
                    else if (action === 'right') cropper.rotate(90);

                    const data = cropper.getData();
                    const angleInput = $(opts.rotateAngleInput);
                    if (angleInput) angleInput.value = data.rotate;
                });
            });

            // --- 旋转角度手动输入 ---
            const angleInput = $(opts.rotateAngleInput);
            if (angleInput) {
                angleInput.addEventListener('change', () => {
                    if (!cropper) return;
                    const angle = parseInt(angleInput.value) || 0;
                    cropper.rotateTo(angle);
                });
            }

            // --- 缩放滑块 ---
            const zoomSlider = $(opts.zoomSlider);
            if (zoomSlider) {
                zoomSlider.addEventListener('input', () => {
                    if (!cropper) return;
                    const zoom = parseFloat(zoomSlider.value);
                    cropper.zoomTo(zoom);
                });
            }

            // --- 裁切形状切换 ---
            $$(opts.cropShapeRadios).forEach(radio => {
                radio.addEventListener('change', function () {
                    _applyCropShape(this.value);
                });
            });

            // --- 重新选择 ---
            const resetBtn = $(opts.resetUploadBtn);
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    resetUploadArea();
                    // 切换到上传选项卡
                    const uploadTabBtn = $('#upload-tab');
                    if (uploadTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                        const tab = new bootstrap.Tab(uploadTabBtn);
                        tab.show();
                    }
                });
            }

            // --- 保存按钮 ---
            const saveBtn = $(opts.saveBtn);
            if (saveBtn) {
                saveBtn.addEventListener('click', handleSave);
            }

            // --- 选项卡切换 ---
            const tabContainer = $(opts.tabContainer);
            if (tabContainer) {
                tabContainer.addEventListener('shown.bs.tab', (e) => {
                    const targetId = e.target.getAttribute('data-bs-target');
                    if (targetId === '#upload') {
                        resetUploadArea();
                    } else if (targetId === '#preset') {
                        const presetContainer = $(opts.presetContainer);
                        if (presetContainer && presetContainer.children.length === 0) {
                            loadPresetAvatars();
                        }
                    }
                });
            }

            // --- 模态框关闭清理 ---
            if (modalEl) {
                modalEl.addEventListener('hidden.bs.modal', () => {
                    // 裁切器保留，避免重复初始化；但重置变量
                    // destroyCropper();
                    // resetUploadArea();
                    if (opts.onModalHide) opts.onModalHide();
                });
                modalEl.addEventListener('shown.bs.modal', () => {
                    if (opts.onModalShow) opts.onModalShow();
                });
            }

            // --- 隐藏上传选项卡（如果不显示） ---
            if (!opts.showUploadType) {
                const uploadTabLi = $('#upload-tab')?.parentElement;
                if (uploadTabLi) uploadTabLi.classList.add('d-none');
            }
        }

        // ---------- 初始化 ----------
        function init() {
            bindEvents();

            // 预加载预设头像
            if (opts.presetUrl) {
                loadPresetAvatars();
            }
        }

        // 执行初始化
        init();

        // ---------- 返回公开 API ----------
        return {
            show() {
                showModal();
            },
            hide() {
                hideModal();
            },
            destroy() {
                destroyCropper();
                if (_inited) {
                    // 移除保存按钮事件（通过克隆）
                    const saveBtn = $(opts.saveBtn);
                    if (saveBtn) {
                        const newBtn = saveBtn.cloneNode(true);
                        saveBtn.parentNode?.replaceChild(newBtn, saveBtn);
                    }
                }
                _inited = false;
                logger.info('upload', 'avatarUploader 已销毁');
            },
            /** 重新初始化 Cropper（当图片通过其他方式加载时） */
            recrop(imageSrc) {
                initCropper(imageSrc);
            },
        };
    },

    /**
     * 订阅上传事件
     * @param {'success'|'error'|'progress'} event
     * @param {Function} handler
     * @returns {Function}
     */
    on(event, handler) {
        return eventBus.on(`ws:upload:${event}`, handler);
    },
};

registry.register('upload', upload);

export default upload;
export { upload };
