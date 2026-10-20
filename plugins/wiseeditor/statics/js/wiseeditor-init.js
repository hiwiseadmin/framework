/**
 * ============================================================
 * WiseEditor - 页面入口（自动初始化）
 * ============================================================
 *
 * 页面只需引入本文件（type="module"），即可自动：
 *   1. 注册内置适配器（tiptap / milkdown）
 *   2. 扫描并挂载页面上所有 [data-editor="wiseeditor"] 容器
 *   3. 将内容同步回表单字段
 *
 * CSP 说明：后台 CSP 为 script-src 'self'，禁止内联脚本与 CDN，
 * 因此必须通过本外部模块完成初始化，而不是在模板里写 <script> 内联代码。
 *
 * 用法（服务端渲染时输出）：
 *   <script type="module" src="/statics/plugins/wiseeditor/js/wiseeditor-init.js"></script>
 *
 * 上传地址解析优先级：
 *   1. 容器自身的 data-upload-url
 *   2. <meta name="wise-editor-upload-url" content="...">
 *   3. 默认 /admin/attachment/upload
 *
 * @module wiseeditor-init
 */

import WiseEditor from './wiseeditor.js';
import TipTapAdapter from './wiseeditor-tiptap.js';
import MilkdownAdapter from './wiseeditor-milkdown.js';

WiseEditor.registerAdapter('tiptap', TipTapAdapter);
WiseEditor.registerAdapter('milkdown', MilkdownAdapter);

function resolveUploadUrl() {
    const meta = document.querySelector('meta[name="wise-editor-upload-url"]');
    return (meta && meta.content) || '/admin/attachment/upload';
}

function resolveCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.content) || '';
}

function boot() {
    WiseEditor.init({
        uploadUrl: resolveUploadUrl(),
        csrfToken: resolveCsrfToken(),
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

export default WiseEditor;
