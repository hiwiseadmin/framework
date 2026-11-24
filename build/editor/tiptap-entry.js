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
 * ── 两种内容格式（format）──────────────────────────────────
 * create(el, { format: 'html' })     富文本字段，值 = HTML（默认，行为不变）
 * create(el, { format: 'markdown' }) Markdown 字段，值 = Markdown 纯文本
 *
 * Markdown 能力来自官方 @tiptap/markdown：扩展集里的每个节点/标记自己声明
 * parseMarkdown / renderMarkdown，由它双向翻译。因此 Markdown 字段和富文本
 * 字段用的是**同一个编辑器内核**——预览、导出、前端渲染不会出现两套解析器
 * 各说各话的问题（历史实现依赖 ws-markdown 二次渲染，两边语法支持不一致）。
 *
 * 版本约束：@tiptap/markdown 与 @tiptap/core 必须同版本（peerDependencies
 * 写死了精确版本），package.json 里使用 --save-exact 锁定，不要改成 ^。
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

import { Editor, Extension, getSchema } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import TextAlign from '@tiptap/extension-text-align';
// text-style 这个包已经内置 TextStyle / FontFamily / FontSize / Color，
// 不需要自己写，也不需要单独的 extension-color（少一个依赖）
import { TextStyle, FontFamily, FontSize, Color } from '@tiptap/extension-text-style';
import Highlight from '@tiptap/extension-highlight';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { Markdown, MarkdownManager } from '@tiptap/markdown';
import { DOMParser as PMDOMParser, DOMSerializer } from '@tiptap/pm/model';
// 高亮语言集单独一个模块维护（引擎与 Node 侧往返校验共用，防止清单漂移）
import { lowlight } from './lowlight-languages.js';

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

    /**
     * 标题下拉的统一入口
     *
     * arg = 1~6 → 设为对应标题；arg = null/0 → 正文。
     * 用 `setHeading` 而不是 `toggleHeading`：下拉是"选择"语义，
     * 选中当前同一级不该把它取消掉（toggle 会导致"再点一次变回正文"的困惑）。
     */
    heading: (editor, arg) => {
        const level = Number(arg);
        return level >= 1 && level <= 6
            ? editor.chain().focus().setHeading({ level }).run()
            : editor.chain().focus().setParagraph().run();
    },

    bold: (editor) => editor.chain().focus().toggleBold().run(),
    italic: (editor) => editor.chain().focus().toggleItalic().run(),
    underline: (editor) => editor.chain().focus().toggleUnderline().run(),
    strike: (editor) => editor.chain().focus().toggleStrike().run(),

    /** 字体 / 字号 / 颜色 / 高亮：arg 为空表示清除（回到继承值） */
    fontFamily: (editor, arg) => editor.chain().focus()
        .setMark('textStyle', { fontFamily: arg || null }).run(),
    fontSize: (editor, arg) => editor.chain().focus()
        .setMark('textStyle', { fontSize: arg || null }).run(),
    color: (editor, arg) => editor.chain().focus()
        .setMark('textStyle', { color: arg || null }).run(),
    highlight: (editor, arg) => (arg
        ? editor.chain().focus().setHighlight({ color: arg }).run()
        : editor.chain().focus().unsetHighlight().run()),

    bulletList: (editor) => editor.chain().focus().toggleBulletList().run(),
    orderedList: (editor) => editor.chain().focus().toggleOrderedList().run(),
    taskList: (editor) => editor.chain().focus().toggleTaskList().run(),
    alignLeft: (editor) => editor.chain().focus().setTextAlign('left').run(),
    alignCenter: (editor) => editor.chain().focus().setTextAlign('center').run(),
    alignRight: (editor) => editor.chain().focus().setTextAlign('right').run(),
    alignJustify: (editor) => editor.chain().focus().setTextAlign('justify').run(),
    blockquote: (editor) => editor.chain().focus().toggleBlockquote().run(),
    codeBlock: (editor) => editor.chain().focus().toggleCodeBlock().run(),

    /**
     * arg 支持三种形态：
     *   - 字符串           → { href }
     *   - 对象 { href, target, rel }
     *   - 空 / 无 href     → 取消链接
     */
    link: (editor, arg) => {
        const attrs = typeof arg === 'string' ? { href: arg } : (arg || {});
        const href = (attrs.href || '').trim();

        if (!href) {
            return editor.chain().focus().extendMarkRange('link').unsetLink().run();
        }

        const payload = {
            href,
            // 统一带 noopener/noreferrer：新窗口打开时防 tabnabbing
            rel: attrs.rel || 'noopener noreferrer nofollow',
            target: attrs.target || null,
        };

        return editor.chain().focus().extendMarkRange('link').setLink(payload).run();
    },

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
    toggleHeaderRow: (editor) => editor.chain().focus().toggleHeaderRow().run(),
    /** arg 为 'left' | 'center' | 'right' | null（null = 清除对齐） */
    cellAlign: (editor, arg) => editor.chain().focus().setCellAttribute('align', arg || null).run(),
    mergeCells: (editor) => editor.chain().focus().mergeCells().run(),
    splitCell: (editor) => editor.chain().focus().splitCell().run(),

    undo: (editor) => editor.chain().focus().undo().run(),
    redo: (editor) => editor.chain().focus().redo().run(),
};

/** 空文档的几种等价形态：统一归一化为 ''，避免表单必填校验误判 */
const EMPTY_HTML = new Set(['', '<p></p>', '<p><br></p>', '<p><br class="ProseMirror-trailingBreak"></p>']);

/**
 * 从 DataTransfer 里挑出图片文件
 *
 * 只认 image/*：文档/压缩包等交给浏览器默认行为（或后续附件通道）。
 *
 * @param {DataTransfer|null} dataTransfer
 * @returns {File[]}
 */
function imageFilesFrom(dataTransfer) {
    if (!dataTransfer || !dataTransfer.files) return [];
    return Array.from(dataTransfer.files).filter((file) => /^image\//.test(file.type));
}

// ============================================================================
// 富文本粘贴（外部 HTML → 文档 → 序列化成 Markdown / HTML）
// ============================================================================

/**
 * 整块丢弃的标签：内容是"代码/样式/嵌入"，粘进正文没有任何意义，
 * 留着只会变成一堆乱码文本（Word 的 `<style>` 段最典型）。
 */
const PASTE_DROP_TAGS = new Set([
    'script', 'style', 'meta', 'link', 'title', 'head', 'base', 'noscript',
    'iframe', 'object', 'embed', 'video', 'audio', 'canvas', 'svg', 'math',
    'form', 'input', 'button', 'select', 'textarea', 'option',
]);

/** 保留的标签：与 schema 对得上，且能被 Markdown 表达 */
const PASTE_KEEP_TAGS = new Set([
    'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'strong', 'b', 'em', 'i', 'u', 's', 'del', 'strike', 'code', 'pre',
    'blockquote', 'ul', 'ol', 'li', 'a', 'img',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
]);

/**
 * 各标签允许保留的属性
 *
 * 其余一律剥掉：class、style、data-* 只带噪声，on* 是注入风险。
 */
const PASTE_KEEP_ATTRS = {
    a: ['href', 'title'],
    img: ['src', 'alt', 'title'],
    td: ['colspan', 'rowspan'],
    th: ['colspan', 'rowspan'],
    ol: ['start'],
    code: ['class'],
    ul: ['data-type'],
    li: ['data-type', 'data-checked'],
};

/**
 * URL 白名单
 *
 * 只放行 http(s)/mailto/tel 与站内相对地址。`javascript:`、`data:`、
 * `vbscript:` 一律拒绝 —— 粘贴是最容易被塞进危险协议的入口
 * （服务端还会再净化一次，两道关都要有）。
 *
 * @param {string} value
 * @returns {boolean}
 */
function isSafePasteUrl(value) {
    const v = String(value == null ? '' : value).trim();
    if (v === '') return false;
    if (/^(?:https?:|mailto:|tel:)/i.test(v)) return true;
    // 站内相对：/x、./x、../x、#anchor、?query、//cdn（协议相对）
    return /^(?:\/|\.\/|\.\.\/|#|\?)/.test(v);
}

/**
 * 纯文本 → 文档节点（"粘贴为纯文本"用）
 *
 * 分段规则与"看起来一样"对齐：**空行分段**，段内单换行保留为 hardBreak。
 * 这样从记事本复制一段带空行的文字，粘进来还是原来的段落结构；
 * 反过来，把所有换行都变成段落会产生一堆碎块。
 *
 * @param {string} text
 * @returns {Array<object>} JSONContent 数组
 */
function plainTextToNodes(text) {
    const src = String(text == null ? '' : text).replace(/\r\n?/g, '\n');
    if (src === '') return [];

    return src
        .split(/\n{2,}/)
        .map((block) => block.replace(/\n+$/, ''))
        .filter((block) => block !== '')
        .map((block) => {
            const content = [];
            block.split('\n').forEach((line, index) => {
                if (index > 0) content.push({ type: 'hardBreak' });
                if (line !== '') content.push({ type: 'text', text: line });
            });
            return content.length ? { type: 'paragraph', content } : { type: 'paragraph' };
        });
}

/**
 * 净化粘贴进来的 HTML
 *
 * 为什么不用直接用 ProseMirror 默认粘贴：默认只按 schema 过滤，会
 *   - 把 `<style>`/`<script>` 的**文本内容**当正文插进来（Word/网页复制常见）
 *   - 保留 class/style 里的垃圾（虽然 schema 会丢，但先剥掉更可控）
 *   - 无法告知用户"丢了多少格式"
 *
 * @param {string} html
 * @returns {{html: HTMLElement, removed: number}} removed = 被丢弃/降级的数量
 */
function sanitizePastedHtml(html) {
    const doc = new window.DOMParser().parseFromString(String(html), 'text/html');
    let removed = 0;

    const walk = (node) => {
        // 复制一份再遍历：遍历中会删/移节点
        const children = Array.from(node.childNodes);

        for (const child of children) {
            if (child.nodeType === 8) { // 注释
                child.remove();
                continue;
            }
            if (child.nodeType !== 1) continue;

            const tag = child.tagName.toLowerCase();

            if (PASTE_DROP_TAGS.has(tag)) {
                child.remove();
                removed += 1;
                continue;
            }

            if (!PASTE_KEEP_TAGS.has(tag)) {
                // 拆壳：保留内容、丢掉标签本身（font / span / div / center / o:p …）
                walk(child);
                while (child.firstChild) child.parentNode.insertBefore(child.firstChild, child);
                child.remove();
                // 字号/颜色/字体基本都挂在被拆掉的标签或其 style 上，记一次"有损"
                removed += 1;
                continue;
            }

            const allowed = PASTE_KEEP_ATTRS[tag] || [];
            for (const attr of Array.from(child.attributes)) {
                const name = attr.name.toLowerCase();
                if (!allowed.includes(name)) {
                    child.removeAttribute(attr.name);
                    if (name === 'style' || name === 'class') removed += 1;
                    continue;
                }
                if ((name === 'href' || name === 'src') && !isSafePasteUrl(attr.value)) {
                    child.removeAttribute(attr.name);
                    removed += 1;
                }
            }

            // code 只留 language-*（CodeBlockLowlight 靠它识别围栏语言）
            if (tag === 'code') {
                const cls = child.getAttribute('class') || '';
                const lang = cls.split(/\s+/).find((c) => /^language-[\w+#-]+$/i.test(c));
                if (lang) child.setAttribute('class', lang);
                else child.removeAttribute('class');
            }

            walk(child);
        }
    };

    walk(doc.body);
    return { html: doc.body, removed };
}

/** Markdown 缩进：2 空格（与 .editorconfig 一致，也让嵌套列表往返结果稳定） */
const MD_INDENTATION = { style: 'space', size: 2 };

// ============================================================================
// 查找替换：高亮装饰 + 匹配计算
// ============================================================================

/** 查找插件 key（UI 层通过 dispatch meta 更新高亮） */
const FIND_PLUGIN_KEY = new PluginKey('wiseeditorFind');

/**
 * 把匹配区间画成 inline 装饰
 *
 * 用装饰而不是直接改文档：查找只是"临时标记"，绝不能进撤销栈 ——
 * 用户按一次 Ctrl+F 再按 Ctrl+Z 不该出现任何内容变化。
 *
 * @param {import('@tiptap/pm/model').Node} doc
 * @param {Array<{from:number,to:number}>} ranges
 * @param {number} currentIndex
 * @returns {DecorationSet}
 */
function buildFindDecorations(doc, ranges, currentIndex) {
    const decos = ranges.map((range, index) => Decoration.inline(range.from, range.to, {
        class: index === currentIndex ? 'ws-find-hit is-current' : 'ws-find-hit',
    }));
    return DecorationSet.create(doc, decos);
}

/**
 * 文档内查找
 *
 * 只匹配**单个文本节点内部**的子串：跨格式块（比如「加**粗**体」里的"加粗体"）
 * 不算命中。这是刻意的 —— 跨节点匹配要么改写文档结构，要么给出让人
 * 无法解释的选区；主流编辑器（Word 之外）在富文本里也不这么做。
 *
 * @param {import('@tiptap/pm/model').Node} doc
 * @param {string} query
 * @param {boolean} caseSensitive
 * @returns {Array<{from:number,to:number}>}
 */
function collectMatches(doc, query, caseSensitive) {
    if (!query) return [];

    const needle = caseSensitive ? query : query.toLowerCase();
    const out = [];

    doc.descendants((node, pos) => {
        if (!node.isText || !node.text) return;
        const haystack = caseSensitive ? node.text : node.text.toLowerCase();

        let index = haystack.indexOf(needle);
        while (index > -1) {
            out.push({ from: pos + index, to: pos + index + query.length });
            // +needle.length 而不是 +1：避免 "aa" 在 "aaa" 里匹配出重叠的两处
            index = haystack.indexOf(needle, index + needle.length);
        }
    });

    return out;
}

/** 查找高亮扩展（永远装配：查找与内容格式无关） */
const FindHighlight = Extension.create({
    name: 'wiseeditorFind',

    addProseMirrorPlugins() {
        return [new Plugin({
            key: FIND_PLUGIN_KEY,
            state: {
                init: () => DecorationSet.empty,
                apply(tr, previous) {
                    const meta = tr.getMeta(FIND_PLUGIN_KEY);
                    if (meta) {
                        return buildFindDecorations(tr.doc, meta.ranges || [], meta.current);
                    }
                    // 文档变了：旧坐标可能已经失效，直接清空，由 UI 层重新 search
                    if (tr.docChanged) return DecorationSet.empty;
                    return previous;
                },
            },
            props: {
                decorations(state) {
                    return FIND_PLUGIN_KEY.getState(state);
                },
            },
        })];
    },
});

/**
 * 组装扩展集（编辑器实例与 headless renderMarkdown 共用）
 *
 * 必须共用而不是各装一套：同一段 Markdown 若在"编辑器"与"渲染"里被两套扩展
 * 解析，结构就可能不同（h5/h6、表格、图片各有差异）——那正是旧实现
 * （左栏引擎 A、右栏 ws-markdown 引擎 B）最难排查的那类问题。
 *
 * @param {object} [opts]
 * @param {string} [opts.format]      - 'html' | 'markdown'（决定标题层级范围）
 * @param {string} [opts.placeholder] - 占位文本（headless 渲染传空）
 * @param {number|string} [opts.limit] - 字符上限（0/null = 不限制）
 * @returns {Array} 扩展实例数组
 */
function buildExtensions(opts = {}) {
    const format = opts.format === 'markdown' ? 'markdown' : 'html';
    const placeholder = opts.placeholder || '';
    const limit = Number(opts.limit);

    return [
        StarterKit.configure({
            /**
             * 标题层级按格式分叉：
             *   HTML 字段（富文本）→ h2~h4：h1 留给页面标题，避免文章内出现多个 h1
             *   Markdown 字段       → h1~h6：Markdown 里 `# 标题` 是一级公民，
             *     把 h1 从 schema 里去掉会让用户的 `# 标题` 在保存时被降级成 h2
             *     （甚至被丢掉）——那是静默的数据损坏，不是"排版约束"
             */
            heading: { levels: format === 'markdown' ? [1, 2, 3, 4, 5, 6] : [2, 3, 4] },
            link: {
                // 编辑态点击链接不应跳转；自动识别 URL 文本为链接
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                protocols: ['http', 'https', 'ftp', 'mailto', 'tel'],
                HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
            },
            // 代码块由下方的 CodeBlockLowlight 接管（带语言选择与高亮），
            // 因此关掉 StarterKit 自带的那一个，避免同 schema 内重复注册
            codeBlock: false,
            // 末尾始终保留一个空段落，方便在表格/代码块后面继续输入
            trailingNode: {},
        }),
        // 代码块 + 语法高亮：lowlight 只是把代码切成带 class 的 span，
        // 配色交给 wiseeditor.css 的 .hljs-* 规则（亮/暗各一套）
        CodeBlockLowlight.configure({
            lowlight,
            HTMLAttributes: { class: 'wiseeditor-codeblock' },
        }),
        // 任务列表（GFM `- [ ]`）：Markdown 与 HTML 两个方向由 TaskItem 自带的
        // markdown 支持处理，往返已是 `- [ ]` / `- [x]`
        TaskList,
        TaskItem.configure({ nested: true }),
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
        /**
         * 段落/标题对齐：**只给 HTML 字段**
         *
         * 原因很硬：Markdown 没有段落对齐语法，开了以后用户在界面上点了
         * "居中"，保存时会被序列化静默丢掉 —— 那就是"看着生效、存完就没"。
         * Markdown 字段的表格列对齐走 tableCell 的 `align` 属性（GFM 的
         * `|:---:|` 能表达），与这里无关。
         */
        ...(format === 'html'
            ? [TextAlign.configure({ types: ['heading', 'paragraph'] })]
            : []),
        /**
         * 字体 / 字号 / 颜色 / 高亮：同样**只给 HTML 字段**
         *
         * 这四项在 Markdown 里都没有语法（GFM 连下划线都没有），
         * 装配到 Markdown 字段就是"点了看着生效、保存后静默丢失"。
         *
         * 它们共用同一个 `textStyle` mark，所以序列化出来是一条
         * `<span style="color:…;font-family:…;font-size:…">`，
         * 而不是三层嵌套 span。
         */
        ...(format === 'html'
            ? [
                TextStyle,
                Color,
                // 单色高亮：背景色由工具栏的预设色板给，不做自由取色
                Highlight.configure({ multicolor: true }),
                FontFamily,
                FontSize,
            ]
            : []),
        // 查找高亮：与内容格式无关，两线都装
        FindHighlight,
        Placeholder.configure({ placeholder }),
        // 字符上限由后端 `data-options.capacity_limit` 下发；null = 不限制。
        // 配了 limit 后引擎会过滤超限事务（真正的"写不进去"），状态栏只负责展示。
        CharacterCount.configure({ limit: limit > 0 ? limit : null }),
        // 官方 Markdown 扩展：给编辑器挂上 getMarkdown() 与
        // `setContent(md, { contentType: 'markdown' })`。
        Markdown.configure({ indentation: MD_INDENTATION }),
    ];
}

/**
 * 创建 TipTap 编辑器实例
 *
 * @param {HTMLElement} element - 挂载点（由适配器提供的 body 元素）
 * @param {object} options
 * @param {string} [options.content]     - 初始内容（HTML 或 Markdown，见 format）
 * @param {string} [options.format]      - 'html'（默认）| 'markdown'
 * @param {boolean} [options.editable]   - 是否可编辑（只读预览实例传 false）
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
    /** 内容格式：markdown = 值以 Markdown 纯文本进/出（字段存 Markdown） */
    const format = options.format === 'markdown' ? 'markdown' : 'html';

    const editorOptions = {
        element,
        content: options.content || '',
        editable: options.editable !== false,
        autofocus: options.autofocus === true,
        extensions: buildExtensions({ format, placeholder, limit: options.limit }),
        editorProps: {
            attributes: {
                class: 'wiseeditor-content ProseMirror',
                'data-placeholder': placeholder,
            },
            /**
             * 粘贴图片交给 UI 层处理（上传 + 占位图），引擎只负责"要不要拦"
             *
             * 返回 true 表示已处理，ProseMirror 不再走默认粘贴（默认对文件无效，
             * 会插入一个空片段）。
             */
            handlePaste: (view, event) => {
                const dt = event.clipboardData;

                /**
                 * 0) 粘贴为纯文本（Ctrl/Cmd + Shift + V）
                 *
                 * 走 `text/plain`，**不解析 HTML**：从网页/Word 复制过来时
                 * 用户明确要求"只要文字"，此时任何格式保留都是干扰；
                 * 更重要的是代码样例里的 `<div>` 不会被当成标签吃掉。
                 */
                if (event.shiftKey && (event.ctrlKey || event.metaKey)) {
                    const text = dt && typeof dt.getData === 'function' ? dt.getData('text/plain') : '';
                    event.preventDefault();
                    insertPlainText(text);
                    return true;
                }

                // 1) 图片文件：交给 UI 层上传（占位图 → 替换成真实地址）
                const files = imageFilesFrom(dt);
                if (files.length && typeof options.onPasteFiles === 'function') {
                    event.preventDefault();
                    options.onPasteFiles(files, event);
                    return true;
                }

                // 2) 富文本（text/html）：净化后交给引擎自己的 HTML 解析器。
                //    Markdown 字段不需要特殊处理 —— 文档落地后 getMarkdown() 负责序列化，
                //    这条链路正是"粘贴富文本 → Markdown"的全部实现。
                const html = dt && typeof dt.getData === 'function' ? dt.getData('text/html') : '';
                if (!html) return false; // 纯文本交给默认（绝不当 HTML 解析，否则代码样例会被吃掉）

                const cleaned = sanitizePastedHtml(html);
                if (!cleaned.html || cleaned.html.childNodes.length === 0) return false;

                event.preventDefault();
                try {
                    const slice = PMDOMParser.fromSchema(view.state.schema).parseSlice(cleaned.html);
                    if (!slice || slice.size === 0) return false;
                    view.dispatch(view.state.tr.replaceSelection(slice).scrollIntoView());
                } catch (err) {
                    console.warn('[TipTap] 粘贴内容解析失败，已忽略: %o', err);
                    return true; // 已 preventDefault，别再让默认逻辑插一遍
                }

                if (cleaned.removed > 0 && typeof options.onPasteSanitized === 'function') {
                    options.onPasteSanitized({ removed: cleaned.removed });
                }
                return true;
            },
            /**
             * 拖拽图片：先算出落点位置再交给 UI 层
             *
             * moved=true 表示编辑器内部拖动已有节点，不拦。
             */
            handleDrop: (view, event, slice, moved) => {
                if (moved) return false;
                const files = imageFilesFrom(event.dataTransfer);
                if (!files.length || typeof options.onDropFiles !== 'function') return false;
                event.preventDefault();

                let pos = null;
                try {
                    const coords = view.posAtCoords({ left: event.clientX, top: event.clientY });
                    if (coords) pos = coords.pos;
                } catch (err) {
                    // 落点在编辑器外/坐标异常：退回当前选区
                }

                options.onDropFiles(files, pos, event);
                return true;
            },
        },
        onUpdate: () => {
            const value = getValue();
            for (const cb of updateCallbacks) {
                try {
                    cb(value);
                } catch (err) {
                    console.warn('[TipTap] onUpdate 回调异常: %o', err);
                }
            }
        },
    };

    // 只有 markdown 才显式声明 contentType：HTML 字段保持原有行为
    // （默认按 HTML 解析字符串），避免多传一个选项改变老页面的解析路径。
    if (format === 'markdown') {
        editorOptions.contentType = 'markdown';
    }

    const editor = new Editor(editorOptions);

    /**
     * 插入纯文本（保留换行与分段，不解析任何标签）
     *
     * 不能用 `insertContent(string)`：TipTap 对字符串按 **HTML** 解析，
     * 粘贴一段 `const a = 1 < 2` 会被吃掉一半。这里显式构造 JSONContent。
     *
     * @param {string} text
     */
    function insertPlainText(text) {
        const nodes = plainTextToNodes(text);
        if (!nodes.length) return;
        try {
            editor.chain().focus().insertContent(nodes).run();
        } catch (err) {
            console.warn('[TipTap] 纯文本粘贴失败: %o', err);
        }
    }

    function getHTML() {
        const html = (editor.getHTML() || '').trim();
        return EMPTY_HTML.has(html) ? '' : html;
    }

    function getMarkdown() {
        try {
            return editor.getMarkdown() || '';
        } catch (err) {
            console.warn('[TipTap] Markdown 序列化失败: %o', err);
            return '';
        }
    }

    /** 按 format 取"字段值"：HTML 字段给 HTML，Markdown 字段给 Markdown */
    function getValue() {
        return format === 'markdown' ? getMarkdown() : getHTML();
    }

    return {
        engine: 'tiptap',
        version: VERSION,
        /** 'html' | 'markdown'：UI 层据此决定读哪个取值口 */
        format,
        editor,

        getHTML,
        getMarkdown,
        /** 当前字段值（HTML 字段 → HTML，Markdown 字段 → Markdown） */
        getValue,
        getText: () => editor.getText() || '',

        /** 文档 JSON（预览实例/外部集成可直接复用，避免二次解析） */
        getJSON: () => editor.getJSON(),

        /**
         * 文档里的标题（大纲用）
         *
         * pos 是"光标可落进去的位置"（标题节点内首字符前），
         * 直接喂给 setTextSelection 就能跳过去 —— 别把 node 的 pos 原样传出去，
         * 那会落在标题节点边界上，ProseMirror 会自己修正到上一个块末尾。
         *
         * @returns {Array<{level:number, text:string, pos:number}>}
         */
        getHeadings: () => {
            const out = [];
            editor.state.doc.descendants((node, pos) => {
                if (node.type.name !== 'heading') return;
                const text = (node.textContent || '').trim();
                out.push({ level: Number(node.attrs.level) || 1, text, pos: pos + 1 });
            });
            return out;
        },

        /** 把光标移到指定位置（大纲跳转；pos 来自 getHeadings） */
        focusAt: (pos) => {
            const target = Math.max(1, Math.min(Number(pos) || 1, editor.state.doc.content.size));
            return editor.chain().focus().setTextSelection(target).scrollIntoView().run();
        },

        /** 当前选区的起止位置（气泡菜单定位用） */
        getSelectionRange: () => {
            const { from, to, empty } = editor.state.selection;
            return { from, to, empty };
        },

        /** 选区在视口中的矩形（气泡菜单定位用；拿不到返回 null） */
        getSelectionRect: () => {
            const { from, to, empty } = editor.state.selection;
            if (empty) return null;
            try {
                const start = editor.view.coordsAtPos(from);
                const end = editor.view.coordsAtPos(to);
                const left = Math.min(start.left, end.left);
                const right = Math.max(start.right, end.right);
                const top = Math.min(start.top, end.top);
                const bottom = Math.max(start.bottom, end.bottom);
                if (!Number.isFinite(left) || !Number.isFinite(top)) return null;
                return { left, right, top, bottom, width: right - left, height: bottom - top };
            } catch (err) {
                return null;
            }
        },

        /** 选区所在的滚动容器（气泡菜单跟随滚动用） */
        getScrollElement: () => editor.view.dom,

        /**
         * 查找替换
         *
         * 不放进 COMMANDS：它有状态（当前匹配序号），每次调用还要回传结果，
         * 而 COMMANDS 的约定是 (editor, arg) => boolean。
         *
         * 用法：
         *   inst.find.search('关键字')          → { count, index }
         *   inst.find.next() / prev()          → { count, index }
         *   inst.find.replaceCurrent('替换文本') → { count, index }
         *   inst.find.replaceAll('替换文本')     → { count, index }
         *   inst.find.clear()
         */
        find: (() => {
            /** @type {{query:string, caseSensitive:boolean, ranges:Array, index:number}} */
            const state = { query: '', caseSensitive: false, ranges: [], index: -1 };

            const paint = () => {
                try {
                    editor.view.dispatch(editor.state.tr.setMeta(FIND_PLUGIN_KEY, {
                        ranges: state.ranges,
                        current: state.index,
                    }));
                } catch (e) { /* 视图已销毁时忽略 */ }
            };

            /** 选中当前匹配并滚动到可视区 */
            const reveal = () => {
                const hit = state.ranges[state.index];
                if (!hit) return;
                editor.chain().focus().setTextSelection({ from: hit.from, to: hit.to }).scrollIntoView().run();
            };

            const snapshot = () => ({ count: state.ranges.length, index: state.index });

            const recompute = (keepIndex) => {
                state.ranges = collectMatches(editor.state.doc, state.query, state.caseSensitive);
                if (state.ranges.length === 0) {
                    state.index = -1;
                } else if (keepIndex) {
                    state.index = Math.min(Math.max(0, state.index), state.ranges.length - 1);
                } else {
                    state.index = 0;
                }
                paint();
            };

            /** 在匹配之间移动（delta = ±1），首尾循环 */
            const move = (delta) => {
                if (!state.ranges.length) return snapshot();
                const size = state.ranges.length;
                state.index = ((state.index + delta) % size + size) % size;
                paint();
                reveal();
                return snapshot();
            };

            return {
                search: (query, opts = {}) => {
                    state.query = String(query == null ? '' : query);
                    state.caseSensitive = opts.caseSensitive === true;
                    state.index = 0;
                    recompute(false);
                    if (state.index >= 0) reveal();
                    return snapshot();
                },

                next: () => move(1),

                prev: () => move(-1),

                current: () => snapshot(),

                /**
                 * 重算匹配但不移动光标（文档变化后由 UI 层调用）
                 *
                 * 不能复用 search()：那会把光标拽回第一个匹配，
                 * 用户正在第三个匹配处打字时会被打断。
                 */
                refresh: () => {
                    if (!state.query) return snapshot();
                    recompute(true);
                    return snapshot();
                },

                replaceCurrent: (text) => {
                    const hit = state.ranges[state.index];
                    if (!hit) return snapshot();
                    const keep = state.index;
                    editor.chain().focus().insertContentAt({ from: hit.from, to: hit.to }, String(text || '')).run();
                    state.index = keep;
                    recompute(true);
                    return snapshot();
                },

                replaceAll: (text) => {
                    if (!state.ranges.length) return snapshot();
                    // 从后往前替换：前面的坐标不受影响，不用反复 mapping
                    const ranges = state.ranges.slice().reverse();
                    let chain = editor.chain().focus();
                    ranges.forEach((hit) => {
                        chain = chain.insertContentAt({ from: hit.from, to: hit.to }, String(text || ''));
                    });
                    chain.run();
                    state.index = 0;
                    recompute(false);
                    return snapshot();
                },

                clear: () => {
                    state.query = '';
                    state.ranges = [];
                    state.index = -1;
                    paint();
                },
            };
        })(),

        /** emitUpdate 默认 true：与自研引擎一致，灌入初值也会通知一次 */
        setHTML: (html) => {
            editor.commands.setContent(html || '');
        },

        /**
         * 写入 Markdown
         *
         * 走 Markdown 扩展覆写后的 `setContent(contentType:'markdown')`，
         * 由它 parse 成 ProseMirror JSON 再替换文档 —— **不要**先转 HTML
         * 再 setHTML：那一步会经过 DOMPurify/浏览器解析，Markdown 语义
         * （尤其是表格、围栏代码块）会失真。
         */
        setMarkdown: (markdown) => {
            editor.commands.setContent(markdown == null ? '' : String(markdown), {
                contentType: 'markdown',
            });
        },

        /** 写入文档 JSON（预览实例同步用，省掉一次 Markdown 解析） */
        setJSON: (json) => {
            editor.commands.setContent(json || { type: 'doc', content: [] });
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

/**
 * Headless 渲染：Markdown → HTML 片段（不创建 Editor、不挂 DOM）
 *
 * 供"只需要渲染、不需要编辑"的地方使用（前台预览、ws-markdown 的引擎优先路径）。
 * 与编辑器共用 buildExtensions()，所以**同一段 Markdown 的渲染结果与编辑态一致**。
 *
 * 代价说明：每次调用都会 new 一个 MarkdownManager + schema（约 1~2ms/次）。
 * 高频场景（长列表实时渲染）请自行缓存；本函数刻意不内置缓存 ——
 * 缓存 key 该用什么（内容哈希？字段 id？）只有调用方知道。
 *
 * @param {string} markdown
 * @returns {string} HTML 片段（不含 .ws-md 之类包装）
 */
function renderMarkdown(markdown) {
    const extensions = buildExtensions({ format: 'markdown' });
    const manager = new MarkdownManager({
        indentation: MD_INDENTATION,
        extensions,
    });

    const json = manager.parse(markdown == null ? '' : String(markdown));
    const schema = getSchema(extensions);
    const node = schema.nodeFromJSON(json);
    const fragment = DOMSerializer.fromSchema(schema).serializeFragment(node.content);
    const box = document.createElement('div');
    box.appendChild(fragment);

    return box.innerHTML;
}

const TipTap = { version: VERSION, create, renderMarkdown, Editor };

// 适配器通过 window.TipTap 取用；同时保留 ESM 默认导出，便于直接 import
if (typeof globalThis !== 'undefined') {
    globalThis.TipTap = TipTap;
}

export default TipTap;
export { create, renderMarkdown, VERSION as version, Editor };
