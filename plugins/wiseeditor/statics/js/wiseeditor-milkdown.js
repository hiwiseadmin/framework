/**
 * ============================================================
 * WiseEditor - Milkdown 适配器（Markdown）
 * ============================================================
 *
 * 将 Markdown 编辑能力适配到 WiseEditor 统一 API。
 *
 * ── 引擎策略（渐进增强）────────────────────────────────────
 *   1. 若页面已提供 Milkdown 运行时（window.Milkdown），使用真实
 *      WYSIWYG Markdown 引擎。
 *   2. 否则退化为"源码 textarea + 实时预览"的分栏编辑器：
 *      左栏编辑 Markdown，右栏用框架内置的 ws-markdown 渲染预览。
 *      数据始终以纯 Markdown 文本为准（表单字段为 textarea）。
 *
 * @module wiseeditor-milkdown
 */

import WiseEditorUpload from './wiseeditor-upload.js';

/** 渲染器缺失只告警一次，避免多实例时刷屏 */
let warnedNoRenderer = false;

/**
 * 解析可用的 Markdown 渲染器（预览栏用）
 *
 * 两条来源都要认，缺一不可：
 *   1. `window.Wise.ws.WsMarkdown` —— 框架内核。后台页面由 wise-bundle.js 统一加载，
 *      这是常规路径。
 *   2. `window.WsMarkdown` —— ws-markdown.js 在自己的模块尾部挂的标准全局
 *      （见其源码「与其他 ws-* 组件保持一致」）。只单独引这一个组件、
 *      没引整包内核的页面（示例页、独立页）靠它。
 *
 * 只认 ① 是历史上的一处窄口子：这种页面里渲染器明明在场，却因为名字对不上而
 * 静默退化成纯文本 —— 预览栏里躺着未渲染的 Markdown 原文。
 *
 * @returns {object|null} 渲染器（须含 render 方法）
 */
function resolveMdRenderer() {
    const candidates = [
        window.Wise && window.Wise.ws && window.Wise.ws.WsMarkdown,
        window.WsMarkdown,
    ];
    for (const fn of candidates) {
        if (fn && typeof fn.render === 'function') {
            return fn;
        }
    }
    return null;
}

/** 分栏工具栏：向选区包裹 / 插入 Markdown 语法 */
const TOOLBAR = [
    { key: 'bold',    icon: 'bi bi-type-bold',      title: '加粗',     before: '**', after: '**' },
    { key: 'italic',  icon: 'bi bi-type-italic',    title: '斜体',     before: '*',  after: '*' },
    { key: 'strike',  icon: 'bi bi-type-strikethrough', title: '删除线', before: '~~', after: '~~' },
    '|',
    { key: 'h2',      icon: 'bi bi-type-h2',        title: '二级标题', line: '## ' },
    { key: 'h3',      icon: 'bi bi-type-h3',        title: '三级标题', line: '### ' },
    '|',
    { key: 'quote',   icon: 'bi bi-quote',          title: '引用',     line: '> ' },
    { key: 'ul',      icon: 'bi bi-list-ul',        title: '无序列表', line: '- ' },
    { key: 'ol',      icon: 'bi bi-list-ol',        title: '有序列表', line: '1. ' },
    { key: 'code',    icon: 'bi bi-code-slash',     title: '代码块',   block: '```\n', blockEnd: '\n```' },
    '|',
    { key: 'link',    icon: 'bi bi-link-45deg',     title: '链接',     before: '[', after: '](https://)' },
    { key: 'image',   icon: 'bi bi-image',          title: '插入图片', action: 'image' },
];

/**
 * 创建 Markdown 编辑器实例
 *
 * @param {Element} container
 * @param {object} options
 * @returns {Promise<object>} EditorInstance
 */
async function create(container, options = {}) {
    const readonly = !!options.readonly;
    const placeholder = options.placeholder || '开始用 Markdown 写作...';
    const uploadUrl = options.uploadUrl || '/admin/attachment/upload';
    const elementId = options.elementId || container.id;

    // 表单字段（真正的提交值载体）
    const field = document.getElementById(elementId + '_textarea');

    if (window.Milkdown && typeof window.Milkdown.create === 'function') {
        try {
            return await createWithVendor(container, options);
        } catch (err) {
            console.warn('[WiseEditor][milkdown] 使用 Milkdown 运行时失败，退化为分栏编辑器: %o', err);
        }
    }

    return createWithSplitView(container, {
        readonly, placeholder, uploadUrl, elementId, field,
    });
}

/** 真实 Milkdown 引擎（页面已注入 window.Milkdown 时） */
async function createWithVendor(container, options) {
    return window.Milkdown.create(container, options);
}

/**
 * 分栏编辑器：Markdown 源码 + 实时预览
 */
function createWithSplitView(container, ctx) {
    const { readonly, placeholder, uploadUrl, elementId, field } = ctx;
    const callbacks = [];
    let destroyed = false;

    container.classList.add('wiseeditor-container', 'wiseeditor-md');
    container.innerHTML = '';

    const toolbar = document.createElement('div');
    toolbar.className = 'wiseeditor-toolbar';

    const body = document.createElement('div');
    body.className = 'wiseeditor-md-body';

    const input = document.createElement('textarea');
    input.className = 'wiseeditor-md-input';
    input.placeholder = placeholder;
    input.spellcheck = false;
    input.readOnly = readonly;

    const preview = document.createElement('div');
    preview.className = 'wiseeditor-md-preview ws-md';

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.hidden = true;

    body.appendChild(input);
    body.appendChild(preview);
    body.appendChild(fileInput);
    if (!readonly) {
        container.appendChild(toolbar);
    }
    container.appendChild(body);

    // ---- 核心行为 ----
    function notify() {
        const md = input.value;
        if (field) {
            field.value = md;
        }
        renderPreview(md);
        callbacks.forEach((cb) => {
            try { cb(md); } catch (e) { console.warn('[WiseEditor] onUpdate 回调异常: %o', e); }
        });
    }

    function renderPreview(md) {
        const renderer = resolveMdRenderer();
        if (renderer) {
            try {
                preview.innerHTML = renderer.render(md || '');
                return;
            } catch (e) {
                // 渲染失败则回退纯文本
            }
        } else if (!warnedNoRenderer) {
            // 刻意不静默：预览退化成 Markdown 原文是"看不出坏"的坏，
            // 排查成本极高，这里必须留一条线索。
            warnedNoRenderer = true;
            console.warn('[WiseEditor][milkdown] 未找到 Markdown 渲染器'
                + '（window.Wise.ws.WsMarkdown / window.WsMarkdown 均缺失），'
                + '预览将退化为纯文本。请引入 ws-markdown 组件。');
        }
        preview.textContent = md || '';
    }

    function surround(before, after) {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selected = input.value.slice(start, end);
        input.setRangeText(before + selected + after, start, end, 'end');
        // 有选中时把光标落在内容末尾，无选中时落在标记中间
        if (selected) {
            input.selectionStart = input.selectionEnd = start + before.length + selected.length + after.length;
        } else {
            input.selectionStart = input.selectionEnd = start + before.length;
        }
        input.focus();
        notify();
    }

    function insertLine(prefix) {
        const start = input.selectionStart;
        const lineStart = input.value.lastIndexOf('\n', start - 1) + 1;
        input.setRangeText(prefix, lineStart, lineStart, 'end');
        input.focus();
        notify();
    }

    function insertBlock(text, textEnd) {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selected = input.value.slice(start, end);
        const content = selected || '';
        const block = text + content + (textEnd || '');
        input.setRangeText(block, start, end, 'end');
        input.selectionStart = input.selectionEnd = start + block.length;
        input.focus();
        notify();
    }

    function runImage() {
        fileInput.click();
    }

    // ---- 工具栏 ----
    if (!readonly) {
        TOOLBAR.forEach((def) => {
            if (def === '|') {
                const divider = document.createElement('span');
                divider.className = 'toolbar-divider';
                toolbar.appendChild(divider);
                return;
            }
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wiseeditor-btn';
            btn.title = def.title;
            btn.innerHTML = '<i class="' + def.icon + '"></i>';
            btn.addEventListener('click', () => {
                if (def.action === 'image') {
                    runImage();
                } else if (def.line) {
                    insertLine(def.line);
                } else if (def.block) {
                    insertBlock(def.block, def.blockEnd);
                } else if (def.before !== undefined) {
                    surround(def.before, def.after);
                }
            });
            toolbar.appendChild(btn);
        });
    }

    input.addEventListener('input', notify);
    input.addEventListener('keydown', (e) => {
        // Tab 输入两个空格，方便写嵌套列表/缩进
        if (e.key === 'Tab') {
            e.preventDefault();
            surround('  ', '');
        }
    });

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (!file) return;
        try {
            const url = await WiseEditorUpload.uploadImage(file, { url: uploadUrl });
            if (url) {
                surround('![', '](' + url + ')');
            }
        } catch (err) {
            if (window.Layer && window.Layer.msg) {
                window.Layer.msg('图片上传失败: ' + err.message, { icon: 2 });
            } else {
                window.alert('图片上传失败: ' + err.message);
            }
        }
    });

    // 初始值：优先取表单字段，其次容器 data-value
    const initial = (field && field.value) || '';
    input.value = initial;
    renderPreview(initial);

    return {
        engine: 'split',
        getContent() {
            return input.value;
        },
        setContent(md) {
            input.value = md || '';
            if (field) {
                field.value = input.value;
            }
            renderPreview(input.value);
            callbacks.forEach((cb) => {
                try { cb(input.value); } catch (e) { /* ignore */ }
            });
        },
        isDirty() {
            return input.value.length > 0;
        },
        focus() {
            input.focus();
        },
        onUpdate(cb) {
            if (typeof cb === 'function') callbacks.push(cb);
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            container._wiseEditorInstance = null;
            container.innerHTML = '';
        },
        getInternal() {
            return input;
        },
    };
}

export { TOOLBAR };
export default { create, TOOLBAR };
