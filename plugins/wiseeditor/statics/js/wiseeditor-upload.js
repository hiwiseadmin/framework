/**
 * ============================================================
 * WiseEditor - 上传适配器
 * ============================================================
 *
 * 统一的文件/图片上传接口，对接 WiseAdmin 附件系统。
 *
 * 被 TipTap、Milkdown 等编辑器适配器内部调用。
 *
 * 用法:
 *   const url = await WiseEditorUpload.uploadImage(file);
 */

const WiseEditorUpload = (() => {
    'use strict';

    /**
     * 上传文件到服务器
     *
     * @param {File} file      - 文件对象
     * @param {Object} options - 上传选项
     * @param {string} options.url    - 上传地址
     * @param {string} [options.type] - 文件类型 (image|file)
     * @returns {Promise<{url: string, id: number, name: string, size: number}>}
     */
    async function upload(file, options = {}) {
        const url = options.url || '/admin/attachment/upload';

        const formData = new FormData();
        formData.append('file', file);
        if (options.type) {
            formData.append('type', options.type);
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {},
        });

        if (!response.ok) {
            throw new Error('上传失败: HTTP ' + response.status);
        }

        const result = await response.json();

        if (result.code === 1 && result.data) {
            return {
                url:  result.data.url || result.data.path || '',
                id:   result.data.id || result.data.attachment_id || 0,
                name: result.data.name || file.name,
                size: result.data.size || file.size,
            };
        }

        throw new Error(result.msg || '上传失败');
    }

    /**
     * 上传图片（便捷方法）
     *
     * @param {File} file       - 图片文件
     * @param {Object} [options] - 上传选项
     * @returns {Promise<string>} 图片 URL
     */
    async function uploadImage(file, options = {}) {
        const result = await upload(file, { ...options, type: 'image' });
        return result.url;
    }

    /**
     * 上传文件（便捷方法）
     *
     * @param {File} file       - 文件
     * @param {Object} [options] - 上传选项
     * @returns {Promise<string>} 文件 URL
     */
    async function uploadFile(file, options = {}) {
        const result = await upload(file, { ...options, type: 'file' });
        return result.url;
    }

    return { upload, uploadImage, uploadFile };
})();

export default WiseEditorUpload;
