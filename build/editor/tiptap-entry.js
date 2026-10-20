/**
 * ============================================================
 * WiseEditor - TipTap 引擎入口（vendor bundle 源码）
 * ============================================================
 *
 * 本文件不是运行时代码，它是 esbuild 的打包入口：
 *   build/editor/tiptap-entry.js  --esbuild-->  plugins/wiseeditor/statics/js/vendor/tiptap.bundle.js
 *
 * ── 职责边界 ────────────────────────────────────────────────
 * 这里只负责「引擎」：扩展集装配、Editor 生命周期、命令映射。
 * UI 层（工具栏按钮、源码切换、上传、表单同步）在 wiseeditor-tiptap.js，
 * 两端通过 window.TipTap.create() 返回的实例协作，互不越界。
 *
 * ── 为什么打成单文件 ────────────────────────────────────────
 * 后台 CSP 为 script-src 'self'：禁 CDN、禁内联脚本。依赖只能自托管，
 * 因此不能用运行时 import 裸模块，必须 esbuild 打包成一个同源文件。
 *
 * ── 版本线约束 ──────────────────────────────────────────────
 * 所有 @tiptap/* 必须同一版本（package.json 用 --save-exact 锁定）。
 * 自 3.30 起 StarterKit 把内嵌扩展钉到自身确切版本，漂移会产生两份 core。
 *
 * @module tiptap-entry
 */

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { CharacterCount, Placeholder } from '@tiptap/extensions';

/** 与 package.json 中 @tiptap/* 锁定的版本保持一致 */
const VERSION = '3.31.4';

/**
 * 命令表：按钮 key → 编辑器命令
 *
 * 每个函数签名 (editor, arg) => boolean，统一走 chain().focus()，
 * 保证命令执行后选区回到编辑器（与自研引擎的行为一致）。
 */
const COMMANDS = {
    h2: (editor) => editor.chain().focus().toggleHeading({ level: 2 }).run(),
    h3: (editor) => editor.chain().focus().toggleHeading({ level: 3 }).run(),
    h4: (editor) => editor.chain().focus().toggleHeading({ level: 4 }).run(),
    paragraph: (editor) => editor.chain().focus().setParagraph().run(),

    bold: (editor) => editor.chain().focus().toggleBold().run(),
    italic: (editor) => editor.chain().focus().toggleItalic().run(),
    underline: (editor) => editor.chain().focus().toggleUnderline().run(),
    strike: (editor) => editor.chain().focus().toggleStrike().run(),

    bulletList: (editor) => editor.chain().focus().toggleBulletList().run(),
    orderedList: (editor) => editor.chain().focus().toggleOrderedList().run(),
    blockquote: (editor) => editor.chain().focus().toggleBlockquote().run(),
    codeBlock: (editor) => editor.chain().focus().toggleCodeBlock().run(),

    /** arg 为空表示取消链接 */
    link: (editor, url) =>
        url
            ? editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
            : editor.chain().focus().extendMarkRange('link').unsetLink().run(),

    /** arg 为图片地址，或 { src, alt, title } */
    image: (editor, arg) => {
        const attrs = typeof arg === 'string' ? { src: arg } : arg || {};
        return editor.chain().focus().setImage(attrs).run();
    },

    horizontalRule: (editor) => editor.chain().focus().setHorizontalRule().run(),
    clearFormat: (editor) => editor.chain().focus().unsetAllMarks().clearNodes().run(),

    /** arg 为 { rows, cols, withHeaderRow } */
    table: (editor, arg) => {
        const opts = { rows: 3, cols: 3, withHeaderRow: true, ...(arg || {}) };
        return editor.chain().focus().insertTable(opts).run();
    },
    addColumnBefore: (editor) => editor.chain().focus().addColumnBefore().run(),
    addColumnAfter: (editor) => editor.chain().focus().addColumnAfter().run(),
    deleteColumn: (editor) => editor.chain().focus().deleteColumn().run(),
    addRowBefore: (editor) => editor.chain().focus().addRowBefore().run(),
    addRowAfter: (editor) => editor.chain().focus().addRowAfter().run(),
    deleteRow: (editor) => editor.chain().focus().deleteRow().run(),
    deleteTable: (editor) => editor.chain().focus().deleteTable().run(),
    mergeCells: (editor) => editor.chain().focus().mergeCells().run(),
    splitCell: (editor) => editor.chain().focus().splitCell().run(),

    undo: (editor) => editor.chain().focus().undo().run(),
    redo: (editor) => editor.chain().focus().redo().run(),
};

/** 空文档的几种等价形态：统一归一化为 ''，避免表单必填校验误判 */
const EMPTY_HTML = new Set(['', '<p></p>', '<p><br></p>', '<p><br class="ProseMirror-trailingBreak"></p>']);

/**
 * 创建 TipTap 编辑器实例
 *
 * @param {HTMLElement} element - 挂载点（由适配器提供的 body 元素）
 * @param {object} options
 * @param {string} [options.content]     - 初始 HTML
 * @param {boolean} [options.editable]   - 是否可编辑
 * @param {string} [options.placeholder] - 占位文本
 * @param {boolean} [options.autofocus]  - 自动聚焦
 * @returns {object} TipTapInstance
 */
function create(element, options = {}) {
    if (!element) {
        throw new Error('[TipTap] 缺少挂载元素');
    }

    const updateCallbacks = [];
    const placeholder = options.placeholder || '';

    const editor = new Editor({
        element,
        content: options.content || '',
        editable: options.editable !== false,
        autofocus: options.autofocus === true,
        extensions: [
            StarterKit.configure({
                // 后台正文只开放 h2~h4：h1 留给页面标题，避免文章内出现多个 h1
                heading: { levels: [2, 3, 4] },
                link: {
                    // 编辑态点击链接不应跳转；自动识别 URL 文本为链接
                    openOnClick: false,
                    autolink: true,
                    linkOnPaste: true,
                    protocols: ['http', 'https', 'ftp', 'mailto', 'tel'],
                    HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
                },
                codeBlock: { HTMLAttributes: { class: 'wiseeditor-codeblock' } },
                // 末尾始终保留一个空段落，方便在表格/代码块后面继续输入
                trailingNode: {},
            }),
            Image.configure({
                inline: false,
                // 禁止 base64：既避免文档膨胀，也避免把任意数据塞进正文
                allowBase64: false,
                HTMLAttributes: { loading: 'lazy' },
            }),
            TableKit.configure({
                table: {
                    resizable: true,
                    lastColumnResizable: false,
                    allowTableNodeSelection: true,
                    HTMLAttributes: { class: 'wiseeditor-table' },
                },
            }),
            Placeholder.configure({ placeholder }),
            CharacterCount.configure({ limit: null }),
        ],
        editorProps: {
            attributes: {
                class: 'wiseeditor-content ProseMirror',
                'data-placeholder': placeholder,
            },
        },
        onUpdate: () => {
            const html = getHTML();
            for (const cb of updateCallbacks) {
                try {
                    cb(html);
                } catch (err) {
                    console.warn('[TipTap] onUpdate 回调异常: %o', err);
                }
            }
        },
    });

    function getHTML() {
        const html = (editor.getHTML() || '').trim();
        return EMPTY_HTML.has(html) ? '' : html;
    }

    return {
        engine: 'tiptap',
        version: VERSION,
        editor,

        getHTML,
        getText: () => editor.getText() || '',

        /** emitUpdate 默认 true：与自研引擎一致，灌入初值也会通知一次 */
        setHTML: (html) => {
            editor.commands.setContent(html || '');
        },

        focus: () => editor.commands.focus(),
        setEditable: (editable) => editor.setEditable(editable !== false),

        onUpdate(cb) {
            if (typeof cb === 'function') updateCallbacks.push(cb);
        },
        offUpdate(cb) {
            const i = updateCallbacks.indexOf(cb);
            if (i > -1) updateCallbacks.splice(i, 1);
        },

        /** 直通 editor.isActive，attrs 用于 heading 的 level 判定 */
        isActive: (name, attrs) => editor.isActive(name, attrs),
        /** 读取当前节点/标记的 attribute，例如 getAttributes('link').href */
        getAttributes: (name) => editor.getAttributes(name),
        /** 判断命令是否可执行 */
        can: (name, arg) => {
            const fn = COMMANDS[name];
            if (!fn) return false;
            try {
                return fn(editor.can(), arg);
            } catch {
                return false;
            }
        },
        /** 执行一条已注册命令，返回是否成功 */
        run: (name, arg) => {
            const fn = COMMANDS[name];
            if (!fn) {
                console.warn('[TipTap] 未注册的命令: %s', name);
                return false;
            }
            try {
                return fn(editor, arg);
            } catch (err) {
                console.warn('[TipTap] 命令执行失败 %s: %o', name, err);
                return false;
            }
        },
        /** 已注册的命令名清单，供 UI 层按需渲染按钮 */
        commandNames: () => Object.keys(COMMANDS),

        /** 字数统计（依赖 CharacterCount 扩展） */
        getStats: () => {
            const storage = editor.storage.characterCount;
            return {
                characters: storage ? storage.characters() : 0,
                words: storage ? storage.words() : 0,
            };
        },

        destroy: () => {
            updateCallbacks.length = 0;
            editor.destroy();
        },
    };
}

const TipTap = { version: VERSION, create, Editor };

// 适配器通过 window.TipTap 取用；同时保留 ESM 默认导出，便于直接 import
if (typeof globalThis !== 'undefined') {
    globalThis.TipTap = TipTap;
}

export default TipTap;
export { create, VERSION as version, Editor };
