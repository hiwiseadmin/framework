/**
 * ============================================================
 * WiseEditor - TipTap 适配器（UI 层）
 * ============================================================
 *
 * 把富文本编辑能力适配到 WiseEditor 统一 API。
 *
 * ── 引擎分层（改动前必读）──────────────────────────────────
 *   引擎：`vendor/tiptap.bundle.js`（由 build/build-editor.mjs 用 esbuild 打包）
 *         只负责扩展集装配、命令映射与 Editor 生命周期，导入即把
 *         `globalThis.TipTap = { version, create, Editor }` 挂上。
 *   UI  ：本文件。工具栏、表格工具条、链接弹窗、图片上传、源码切换、
 *         只读、销毁与表单同步都在这里，引擎细节不向业务代码泄漏。
 *
 * ── 引擎策略（渐进增强 / 可降级）───────────────────────────
 *   1. 动态 import 自建 bundle → 真实 TipTap 引擎（engine: 'tiptap'）。
 *   2. bundle 缺失或加载失败 → 退化为内置零依赖 contenteditable 引擎
 *      （engine: 'native'），保证后台在未打包 vendor 时依然可用。
 *
 *   两种引擎对外暴露完全一致的 EditorInstance 接口（见 wiseeditor.js），
 *   业务代码无感知。
 *
 * CSP 约束：后台 `script-src 'self'`，禁 CDN、禁内联脚本，
 * 因此 bundle 必须自托管并以相对路径动态导入（见 loadVendor）。
 *
 * @module wiseeditor-tiptap
 */

import WiseEditorUpload from './wiseeditor-upload.js';

/** 内置引擎的工具栏预设（与后端 TipTapDriver::getDefaultConfig() 对齐） */
const TOOLBAR_PRESETS = {
    full: [
        'h2', 'h3', 'h4', 'paragraph', '|',
        'bold', 'italic', 'underline', 'strike', '|',
        'bulletList', 'orderedList', 'blockquote', 'codeBlock', '|',
        'link', 'image', 'table', 'horizontalRule', 'clearFormat', '|',
        'undo', 'redo', '|', 'source',
    ],
    basic: [
        'bold', 'italic', 'underline', '|',
        'bulletList', 'orderedList', '|',
        'link', 'image', '|',
        'undo', 'redo',
    ],
    minimal: ['bold', 'italic', 'link'],
};

/** 内置引擎插入表格用的 HTML 片段（真实引擎走 bundle 的 table 命令） */
const TABLE_HTML_SNIPPET =
    '<table><thead><tr><th></th><th></th><th></th></tr></thead>' +
    '<tbody><tr><td></td><td></td><td></td></tr><tr><td></td><td></td><td></td></tr></tbody>' +
    '</table><p></p>';

/**
 * 按钮定义
 *
 * 同一个按钮要同时服务两种引擎，因此命令与激活态分开声明：
 *   command — 内置引擎：`document.execCommand(name, false, arg)`
 *   state   — 内置引擎：`document.queryCommandState(name)`
 *   tiptap  — 真实引擎：bundle `COMMANDS` 中的命令名
 *   active  — 真实引擎：`editor.isActive(name, attrs)` 的入参
 *   action  — 需要额外交互（弹窗 / 上传 / 源码切换）的按钮
 */
const BUTTONS = {
    h2:            { icon: 'bi bi-type-h2',            title: '二级标题', command: ['formatBlock', '<h2>'],         tiptap: 'h2',         active: ['heading', { level: 2 }] },
    h3:            { icon: 'bi bi-type-h3',            title: '三级标题', command: ['formatBlock', '<h3>'],         tiptap: 'h3',         active: ['heading', { level: 3 }] },
    h4:            { icon: 'bi bi-type-h4',            title: '四级标题', command: ['formatBlock', '<h4>'],         tiptap: 'h4',         active: ['heading', { level: 4 }] },
    paragraph:     { icon: 'bi bi-paragraph',          title: '正文',     command: ['formatBlock', '<p>'],          tiptap: 'paragraph',  active: ['paragraph'] },

    bold:          { icon: 'bi bi-type-bold',          title: '加粗',     command: ['bold'],                        tiptap: 'bold',       active: ['bold'],       state: 'bold' },
    italic:        { icon: 'bi bi-type-italic',        title: '斜体',     command: ['italic'],                      tiptap: 'italic',     active: ['italic'],     state: 'italic' },
    underline:     { icon: 'bi bi-type-underline',     title: '下划线',   command: ['underline'],                   tiptap: 'underline',  active: ['underline'],  state: 'underline' },
    strike:        { icon: 'bi bi-type-strikethrough', title: '删除线',   command: ['strikeThrough'],               tiptap: 'strike',     active: ['strike'],     state: 'strikeThrough' },

    bulletList:    { icon: 'bi bi-list-ul',            title: '无序列表', command: ['insertUnorderedList'],         tiptap: 'bulletList',  active: ['bulletList'],  state: 'insertUnorderedList' },
    orderedList:   { icon: 'bi bi-list-ol',            title: '有序列表', command: ['insertOrderedList'],           tiptap: 'orderedList', active: ['orderedList'], state: 'insertOrderedList' },
    blockquote:    { icon: 'bi bi-quote',              title: '引用',     command: ['formatBlock', '<blockquote>'], tiptap: 'blockquote',  active: ['blockquote'] },
    codeBlock:     { icon: 'bi bi-code-slash',         title: '代码块',   command: ['formatBlock', '<pre>'],        tiptap: 'codeBlock',   active: ['codeBlock'] },

    link:          { icon: 'bi bi-link-45deg',         title: '链接',     action: 'link',      active: ['link'] },
    image:         { icon: 'bi bi-image',              title: '插入图片', action: 'image' },
    table:         { icon: 'bi bi-table',              title: '插入表格', command: ['insertHTML', TABLE_HTML_SNIPPET], tiptap: 'table' },
    horizontalRule:{ icon: 'bi bi-dash-lg',            title: '分割线',   command: ['insertHorizontalRule'],        tiptap: 'horizontalRule' },
    clearFormat:   { icon: 'bi bi-eraser',             title: '清除格式', command: ['removeFormat'],                tiptap: 'clearFormat' },

    undo:          { icon: 'bi bi-arrow-counterclockwise', title: '撤销', command: ['undo'],                        tiptap: 'undo' },
    redo:          { icon: 'bi bi-arrow-clockwise',    title: '重做',     command: ['redo'],                        tiptap: 'redo' },
    source:        { icon: 'bi bi-code-square',        title: '源码',     action: 'source' },
};

/**
 * 表格工具条按钮（仅在光标位于表格内时显示，命令名对齐 bundle COMMANDS）
 */
const TABLE_BUTTONS = [
    { cmd: 'addRowBefore',    icon: 'bi bi-arrow-up-square',    title: '上方插入行' },
    { cmd: 'addRowAfter',     icon: 'bi bi-arrow-down-square',  title: '下方插入行' },
    { cmd: 'deleteRow',       icon: 'bi bi-dash-square',        title: '删除当前行' },
    { cmd: 'addColumnBefore', icon: 'bi bi-arrow-left-square',  title: '左侧插入列' },
    { cmd: 'addColumnAfter',  icon: 'bi bi-arrow-right-square', title: '右侧插入列' },
    { cmd: 'deleteColumn',    icon: 'bi bi-dash-square-dotted', title: '删除当前列' },
    { cmd: 'mergeCells',      icon: 'bi bi-arrows-collapse',    title: '合并单元格' },
    { cmd: 'splitCell',       icon: 'bi bi-arrows-expand',      title: '拆分单元格' },
    { cmd: 'deleteTable',     icon: 'bi bi-trash',              title: '删除表格' },
];

/** 主工具栏里会因不可用而置灰的按钮（少而便宜，避免每次刷新跑一堆 dry-run） */
const CAN_CHECK = new Set(['undo', 'redo']);

/**
 * 共享的工具栏构建
 *
 * @param {HTMLElement} toolbarEl
 * @param {string} presetName - TOOLBAR_PRESETS 的键
 * @param {Function} onClick  - (def, button) => void
 */
function buildToolbar(toolbarEl, presetName, onClick) {
    const preset = TOOLBAR_PRESETS[presetName] || TOOLBAR_PRESETS.full;

    preset.forEach((key) => {
        if (key === '|') {
            const divider = document.createElement('span');
            divider.className = 'toolbar-divider';
            toolbarEl.appendChild(divider);
            return;
        }

        const def = BUTTONS[key];
        if (!def) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'wiseeditor-btn';
        btn.title = def.title;
        btn.dataset.key = key;
        btn.innerHTML = '<i class="' + def.icon + '"></i>';
        btn.addEventListener('mousedown', (e) => e.preventDefault()); // 保持选区
        btn.addEventListener('click', () => onClick(def, btn));
        toolbarEl.appendChild(btn);
    });
}

// ============================================================================
// 引擎加载
// ============================================================================

/** 缓存的 bundle 加载 Promise（同一页面多个编辑器只加载一次） */
let vendorLoader = null;

/**
 * 加载真实 TipTap 引擎
 *
 * 页面若已通过其它途径注入 window.TipTap 则直接复用；否则动态 import
 * 自托管 bundle（ESM，导入即执行副作用并挂载 globalThis.TipTap）。
 *
 * @returns {Promise<object>} window.TipTap
 */
function loadVendor() {
    if (window.TipTap && typeof window.TipTap.create === 'function') {
        return Promise.resolve(window.TipTap);
    }

    if (!vendorLoader) {
        vendorLoader = import('./vendor/tiptap.bundle.js')
            .then(() => {
                const api = window.TipTap;
                if (!api || typeof api.create !== 'function') {
                    throw new Error('vendor bundle 已加载，但未暴露 window.TipTap.create');
                }
                return api;
            })
            .catch((err) => {
                vendorLoader = null; // 允许下次重试（例如资源被替换后重新加载）
                throw err;
            });
    }

    return vendorLoader;
}

/**
 * 创建 TipTap 编辑器实例
 *
 * @param {Element} container - 编辑器容器 DOM 元素
 * @param {object} options    - 配置选项
 * @returns {Promise<object>} EditorInstance
 */
async function create(container, options = {}) {
    if (!container) {
        throw new Error('[WiseEditor] 缺少容器元素');
    }

    try {
        const tipTap = await loadVendor();
        return createWithVendor(tipTap, container, options);
    } catch (err) {
        console.warn('[WiseEditor][tiptap] 真实引擎不可用，退化为内置引擎: %o', err);
    }

    return createWithNative(container, options);
}

// ============================================================================
// 真实引擎（vendor bundle）
// ============================================================================

/**
 * 使用真实 TipTap 引擎（UI 层：工具栏 + 表格工具条 + 源码 + 上传）
 *
 * @param {object} tipTap - window.TipTap
 * @param {Element} container
 * @param {object} options
 * @returns {object} EditorInstance
 */
function createWithVendor(tipTap, container, options = {}) {
    const mode = options.mode === 'simple' ? 'basic' : 'full';
    const readonly = !!options.readonly;
    const placeholder = options.placeholder || '';
    const uploadUrl = options.uploadUrl || '/admin/attachment/upload';

    const callbacks = [];
    let destroyed = false;
    let refreshQueued = false;

    // ---- DOM 结构（先搭骨架，再挂引擎，最后填充工具栏）----
    container.classList.add('wiseeditor-container');
    container.innerHTML = '';

    const toolbar = document.createElement('div');
    toolbar.className = 'wiseeditor-toolbar';
    toolbar.setAttribute('role', 'toolbar');

    const tablebar = document.createElement('div');
    tablebar.className = 'wiseeditor-tablebar';
    tablebar.setAttribute('role', 'toolbar');
    tablebar.hidden = true;

    const body = document.createElement('div');
    body.className = 'wiseeditor-body';

    /** 引擎挂载点：独立一层包裹，便于源码模式整体隐藏 */
    const pane = document.createElement('div');
    pane.className = 'wiseeditor-pane';

    const source = document.createElement('textarea');
    source.className = 'wiseeditor-source';
    source.hidden = true;
    source.spellcheck = false;

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.hidden = true;

    body.appendChild(pane);
    body.appendChild(source);
    body.appendChild(fileInput);

    if (!readonly) {
        container.appendChild(toolbar);
        container.appendChild(tablebar);
    }
    container.appendChild(body);

    // ---- 引擎 ----
    let inst;
    try {
        inst = tipTap.create(pane, {
            content: options.content || '',
            editable: !readonly,
            placeholder,
            autofocus: false,
        });
    } catch (err) {
        container.innerHTML = '';
        throw err;
    }

    // ---- 回调分发 ----
    function emit(html) {
        for (const cb of callbacks) {
            try { cb(html); } catch (e) { console.warn('[WiseEditor] onUpdate 回调异常: %o', e); }
        }
    }

    function getContent() {
        if (destroyed) return '';
        try { return inst.getHTML() || ''; } catch (e) { return ''; }
    }

    function setContent(html) {
        if (destroyed) return;
        inst.setHTML(html || '');
    }

    function runCommand(name, arg) {
        if (destroyed) return;
        inst.run(name, arg);
        scheduleRefresh();
    }

    // ---- 激活态刷新（节流到一帧，避免连续输入时重复计算）----
    function scheduleRefresh() {
        if (destroyed || refreshQueued) return;
        refreshQueued = true;
        const run = () => { refreshQueued = false; refreshStates(); };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(run);
        } else {
            window.setTimeout(run, 16);
        }
    }

    function refreshStates() {
        if (destroyed || readonly) return;

        toolbar.querySelectorAll('button[data-key]').forEach((btn) => {
            const def = BUTTONS[btn.dataset.key];
            if (!def) return;

            if (def.active) {
                const [name, attrs] = def.active;
                let active = false;
                try { active = attrs ? inst.isActive(name, attrs) : inst.isActive(name); } catch (e) { /* ignore */ }
                btn.classList.toggle('is-active', !!active);
            }

            if (CAN_CHECK.has(btn.dataset.key)) {
                let enabled = true;
                try { enabled = inst.can(def.tiptap, def.tiptapArg); } catch (e) { enabled = true; }
                btn.disabled = !enabled;
            }
        });

        if (tablebar.childElementCount === 0) return;

        let inTable = false;
        try { inTable = !!inst.isActive('table'); } catch (e) { /* ignore */ }
        tablebar.hidden = !inTable;

        if (inTable) {
            tablebar.querySelectorAll('button[data-table-cmd]').forEach((btn) => {
                let enabled = true;
                try { enabled = inst.can(btn.dataset.tableCmd); } catch (e) { enabled = true; }
                btn.disabled = !enabled;
            });
        }
    }

    // ---- 交互动作 ----
    function runAction(action, button) {
        if (action === 'link') {
            openLinkDialog();
            return;
        }
        if (action === 'image') {
            fileInput.click();
            return;
        }
        if (action === 'source') {
            toggleSource(button);
        }
    }

    function openLinkDialog() {
        let current = '';
        try { current = (inst.getAttributes('link') || {}).href || ''; } catch (e) { /* ignore */ }

        // 取消（null）什么都不做；清空（''）表示移除链接
        const url = window.prompt('请输入链接地址（留空则取消链接）', current || 'https://');
        if (url === null) return;

        runCommand('link', url.trim());
    }

    function toggleSource(button) {
        if (destroyed) return;

        if (!source.hidden) {
            // 源码 -> 富文本（setContent 默认 emitUpdate，会自动同步隐藏域）
            inst.setHTML(source.value);
            source.hidden = true;
            pane.hidden = false;
            button.classList.remove('is-active');
            inst.focus();
            scheduleRefresh();
        } else {
            // 富文本 -> 源码
            source.value = getContent();
            pane.hidden = true;
            source.hidden = false;
            button.classList.add('is-active');
            source.focus();
        }
    }

    // ---- 工具栏 ----
    if (!readonly) {
        buildToolbar(toolbar, mode, (def, btn) => {
            if (def.action) {
                runAction(def.action, btn);
            } else if (def.tiptap) {
                runCommand(def.tiptap, def.tiptapArg);
            }
        });

        TABLE_BUTTONS.forEach((spec) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wiseeditor-btn';
            btn.title = spec.title;
            btn.dataset.tableCmd = spec.cmd;
            btn.innerHTML = '<i class="' + spec.icon + '"></i>';
            btn.addEventListener('mousedown', (e) => e.preventDefault());
            btn.addEventListener('click', () => runCommand(spec.cmd));
            tablebar.appendChild(btn);
        });
    }

    // ---- 引擎事件 ----
    inst.onUpdate((html) => {
        scheduleRefresh();
        emit(html);
    });

    if (inst.editor && typeof inst.editor.on === 'function') {
        inst.editor.on('selectionUpdate', scheduleRefresh);
        inst.editor.on('focus', scheduleRefresh);
        inst.editor.on('blur', scheduleRefresh);
    }

    // ---- 上传 / 源码输入 ----
    fileInput.addEventListener('change', async () => {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (!file) return;

        try {
            const url = await WiseEditorUpload.uploadImage(file, { url: uploadUrl });
            if (url) runCommand('image', url);
        } catch (err) {
            const msg = '图片上传失败: ' + err.message;
            if (window.Layer && window.Layer.msg) {
                window.Layer.msg(msg, { icon: 2 });
            } else {
                window.alert(msg);
            }
        }
    });

    // 源码模式下直接改动源码也要同步到表单字段（与内置引擎行为一致）
    source.addEventListener('input', () => emit(source.value));

    // ---- 对外实例 ----
    return {
        engine: 'tiptap',
        version: tipTap.version,

        getContent,
        setContent,
        isDirty() {
            return getContent() !== '';
        },
        focus() {
            if (!destroyed) inst.focus();
        },
        onUpdate(cb) {
            if (typeof cb === 'function') callbacks.push(cb);
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            callbacks.length = 0;
            try { inst.destroy(); } catch (e) { console.warn('[WiseEditor] 销毁 TipTap 实例失败: %o', e); }
            container._wiseEditorInstance = null;
            container.innerHTML = '';
        },
        getInternal() {
            return inst;
        },
    };
}

// ============================================================================
// 内置零依赖引擎（降级路径：contenteditable + 工具栏）
// ============================================================================

/**
 * @returns {object} EditorInstance
 */
function createWithNative(container, options = {}) {
    const mode = options.mode === 'simple' ? 'basic' : 'full';
    const readonly = !!options.readonly;
    const placeholder = options.placeholder || '';
    const uploadUrl = options.uploadUrl || '/admin/attachment/upload';
    const callbacks = [];
    let destroyed = false;

    // ---- DOM 结构 ----
    container.classList.add('wiseeditor-container');
    container.innerHTML = '';

    const toolbar = document.createElement('div');
    toolbar.className = 'wiseeditor-toolbar';
    toolbar.setAttribute('role', 'toolbar');

    const body = document.createElement('div');
    body.className = 'wiseeditor-body';

    const content = document.createElement('div');
    content.className = 'wiseeditor-content ProseMirror';
    content.setAttribute('contenteditable', readonly ? 'false' : 'true');
    content.setAttribute('role', 'textbox');
    content.setAttribute('aria-multiline', 'true');
    if (placeholder) {
        content.setAttribute('data-placeholder', placeholder);
    }

    const source = document.createElement('textarea');
    source.className = 'wiseeditor-source';
    source.hidden = true;
    source.spellcheck = false;

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.hidden = true;

    body.appendChild(content);
    body.appendChild(source);
    body.appendChild(fileInput);

    if (!readonly) {
        container.appendChild(toolbar);
    }
    container.appendChild(body);

    // ---- 工具栏 ----
    if (!readonly) {
        buildToolbar(toolbar, mode, (def, btn) => {
            if (def.action) {
                runAction(def.action, btn);
            } else if (def.command) {
                exec(def.command[0], def.command[1] ?? null);
            }
        });
    }

    // ---- 工具函数 ----
    function exec(command, value = null) {
        content.focus();
        try {
            document.execCommand(command, false, value);
        } catch (e) {
            console.warn('[WiseEditor] 命令执行失败:', command, e);
        }
        notify();
        refreshStates();
    }

    function notify() {
        const html = getContent();
        callbacks.forEach((cb) => {
            try { cb(html); } catch (e) { console.warn('[WiseEditor] onUpdate 回调异常: %o', e); }
        });
    }

    function getContent() {
        const html = (content.innerHTML || '').trim();
        // 归一化"空内容"：contenteditable 常留下 <br> / <p><br></p>
        if (/^<(br|p|div)[^>]*>(\s*<br[^>]*>)?\s*<\/(p|div)>$/i.test(html) || html === '<br>') {
            return '';
        }
        return html;
    }

    function setContent(html) {
        if (source.hidden === false) {
            source.value = html || '';
        }
        content.innerHTML = html || '';
        notify();
    }

    function runAction(action, button) {
        if (action === 'link') {
            const url = window.prompt('请输入链接地址（http/https）', 'https://');
            if (url) {
                exec('createLink', url);
            }
            return;
        }
        if (action === 'image') {
            fileInput.click();
            return;
        }
        if (action === 'source') {
            toggleSource(button);
        }
    }

    function toggleSource(button) {
        const showingSource = !source.hidden;
        if (showingSource) {
            // 源码 -> 富文本
            content.innerHTML = source.value;
            source.hidden = true;
            content.hidden = false;
            button.classList.remove('is-active');
            notify();
        } else {
            // 富文本 -> 源码
            source.value = getContent();
            content.hidden = true;
            source.hidden = false;
            button.classList.add('is-active');
        }
    }

    function refreshStates() {
        if (readonly || toolbar.childElementCount === 0) return;
        toolbar.querySelectorAll('button[data-key]').forEach((btn) => {
            const def = BUTTONS[btn.dataset.key];
            if (!def || !def.state) return;
            let active = false;
            try { active = document.queryCommandState(def.state); } catch (e) { /* ignore */ }
            btn.classList.toggle('is-active', active);
        });
    }

    // ---- 事件绑定 ----
    const onInput = () => notify();
    const onSelectionChange = () => refreshStates();
    content.addEventListener('input', onInput);
    content.addEventListener('blur', onInput);
    content.addEventListener('keyup', refreshStates);
    content.addEventListener('mouseup', refreshStates);
    document.addEventListener('selectionchange', onSelectionChange);

    source.addEventListener('input', notify);

    // 粘贴时只保留纯文本，避免带来源站点的脏样式/标签
    content.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
        notify();
    });

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (!file) return;
        try {
            const url = await WiseEditorUpload.uploadImage(file, { url: uploadUrl });
            if (url) {
                exec('insertImage', url);
            }
        } catch (err) {
            const msg = '图片上传失败: ' + err.message;
            if (window.Layer && window.Layer.msg) {
                window.Layer.msg(msg, { icon: 2 });
            } else {
                window.alert(msg);
            }
        }
    });

    return {
        engine: 'native',
        getContent,
        setContent,
        isDirty() {
            return getContent() !== '';
        },
        focus() {
            content.focus();
        },
        onUpdate(cb) {
            if (typeof cb === 'function') callbacks.push(cb);
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            callbacks.length = 0;
            document.removeEventListener('selectionchange', onSelectionChange);
            content.removeEventListener('input', onInput);
            content.removeEventListener('blur', onInput);
            container._wiseEditorInstance = null;
            container.innerHTML = '';
        },
        getInternal() {
            return content;
        },
    };
}

export { TOOLBAR_PRESETS };
export default { create, TOOLBAR_PRESETS };
