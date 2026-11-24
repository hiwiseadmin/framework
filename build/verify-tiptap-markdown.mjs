/**
 * ============================================================
 * WiseEditor - @tiptap/markdown 往返保真度验证（spike → 回归）
 * ============================================================
 *
 * 用法：
 *   node build/verify-tiptap-markdown.mjs
 *   node build/verify-tiptap-markdown.mjs --verbose   # 打印每条用例的产出
 *
 * ── 为什么需要它 ────────────────────────────────────────────
 * Markdown 字段改成「TipTap 引擎 + 官方 @tiptap/markdown」之后，用户正文的
 * 存取链路变成：
 *
 *     数据库文本 --parse--> ProseMirror JSON --serialize--> 数据库文本
 *
 * 这条链路**必须**在无浏览器环境下可验证：一旦某个扩展的 parseMarkdown /
 * renderMarkdown 没注册上，序列化会把节点整段丢掉（`renderNodeToMarkdown`
 * 找不到 handler 直接 return ''），表现为「保存后正文静默消失」——这是
 * 最难在页面上发现的一类 bug。本脚本用与线上完全相同的扩展集，在 Node 里
 * 跑一遍 parse/serialize，把「静默丢内容」挡在提交前。
 *
 * ── 为什么不用浏览器 ────────────────────────────────────────
 * MarkdownManager 只依赖 marked + prosemirror schema，不需要 DOM。
 * 唯一例外是 Markdown 里的**内联 HTML**：`parseHTMLToken()` 依赖
 * `window.DOMParser`，Node 下会退化为「原样当文本」。因此本脚本的语料
 * 刻意只覆盖纯 Markdown 语法，HTML 混排交给浏览器用例（verify-editor.mjs）。
 *
 * ── 判定口径 ────────────────────────────────────────────────
 *   1. 结构保真：parse 出的 JSON 必须非空，且关键节点类型齐全
 *   2. 往返稳定：md → json → md' → json' → md'' 满足 md' === md''
 *      （一次归一化可以接受，二次还漂移就是真 bug）
 *   3. 关键标记：序列化结果必须包含预期的语法片段（如 `**x**`）
 *
 * @module verify-tiptap-markdown
 */

import { MarkdownManager } from '@tiptap/markdown';
import { StarterKit } from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { lowlight } from './editor/lowlight-languages.js';

const VERBOSE = process.argv.includes('--verbose');

/**
 * 扩展集必须与 build/editor/tiptap-entry.js 的 create() 保持一致
 *
 * 这里刻意**不**引入 UI 侧的交互选项（placeholder / limit），只保留
 * 影响 schema 与 markdown 注册的部分 —— 两者不一致时本脚本的结论就失效。
 */
function buildExtensions() {
  return [
    StarterKit.configure({
      // Markdown 字段允许 h1~h6（与 tiptap-entry.js 的 format='markdown' 分支一致）；
      // 富文本字段才收窄到 h2~h4
      heading: { levels: [1, 2, 3, 4, 5, 6] },
      link: {
        openOnClick: false,
        autolink: true,
        linkOnPaste: true,
        protocols: ['http', 'https', 'ftp', 'mailto', 'tel'],
        HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
      },
      // 与引擎一致：代码块交给 CodeBlockLowlight（带语言与高亮）
      codeBlock: false,
      trailingNode: {},
    }),
    CodeBlockLowlight.configure({
      lowlight,
      HTMLAttributes: { class: 'wiseeditor-codeblock' },
    }),
    TaskList,
    TaskItem.configure({ nested: true }),
    Image.configure({ inline: false, allowBase64: false, HTMLAttributes: { loading: 'lazy' } }),
    TableKit.configure({
      table: {
        resizable: true,
        lastColumnResizable: false,
        allowTableNodeSelection: true,
        HTMLAttributes: { class: 'wiseeditor-table' },
      },
    }),
    Placeholder.configure({ placeholder: '' }),
    CharacterCount.configure({ limit: null }),
  ];
}

/** @type {Array<{name: string, md: string, expect?: string[], types?: string[]}>} */
const CASES = [
  {
    name: '标题（h2/h3）',
    md: '## 二级标题\n\n### 三级标题\n',
    expect: ['## 二级标题', '### 三级标题'],
    types: ['heading'],
  },
  {
    // 深水用例：富文本 schema 只开 h2~h4，Markdown 字段必须开到 h1~h6，
    // 否则用户的 `# 标题` 会在"存一次"之后被静默降级
    name: '标题（h1/h5 不被降级）',
    md: '# 一级标题\n\n##### 五级标题\n',
    expect: ['# 一级标题', '##### 五级标题'],
    types: ['heading'],
  },
  {
    name: '段落 + 行内标记',
    md: '这段有 **加粗**、*斜体*、~~删除线~~ 和 `code`。\n',
    expect: ['**加粗**', '*斜体*', '~~删除线~~', '`code`'],
    types: ['paragraph', 'text'],
  },
  {
    name: '链接',
    md: '见 [示例站点](https://example.com/a?b=1)。\n',
    expect: ['[示例站点](https://example.com/a?b=1)'],
    types: ['paragraph'],
  },
  {
    name: '图片',
    md: '![占位图](/statics/a.png)\n',
    expect: ['![占位图](/statics/a.png)'],
    types: ['image'],
  },
  {
    name: '无序列表（含嵌套）',
    md: '- 一级 A\n- 一级 B\n  - 二级 B1\n  - 二级 B2\n',
    expect: ['一级 A', '二级 B1'],
    types: ['bulletList', 'listItem'],
  },
  {
    name: '有序列表',
    md: '1. 第一步\n2. 第二步\n3. 第三步\n',
    expect: ['第一步', '第三步'],
    types: ['orderedList', 'listItem'],
  },
  {
    name: '引用块',
    md: '> 引用第一行\n>\n> 引用第二行\n',
    expect: ['引用第一行', '引用第二行'],
    types: ['blockquote'],
  },
  {
    name: '围栏代码块（带语言）',
    md: '```js\nconst a = 1 < 2;\nconsole.log("**不该加粗**");\n```\n',
    expect: ['```js', 'const a = 1 < 2;', 'console.log'],
    types: ['codeBlock'],
  },
  {
    // 列对齐存在 tableCell.attrs.align，序列化必须还原成 GFM 的定界行，
    // 否则"居中/右对齐"这类排版信息存一次就没了
    name: '表格列对齐（:---: ↔ align）',
    md: '| 左 | 中 | 右 |\n|:---|:---:|---:|\n| 1 | 2 | 3 |\n',
    expect: [':---', ':---:', '---:'],
    types: ['table', 'tableCell'],
  },
  {
    // 任务列表的 checked 存在 taskItem.attrs 里，序列化必须还原成 [ ] / [x]，
    // 否则用户勾选的进度会在保存后全部丢失
    name: '任务列表（- [ ] / - [x]）',
    md: '- [ ] 待办项\n- [x] 已完成项\n',
    expect: ['- [ ] 待办项', '- [x] 已完成项'],
    types: ['taskList', 'taskItem'],
  },
  {
    name: '任务列表（嵌套）',
    md: '- [ ] 父项\n  - [x] 子项\n',
    expect: ['- [ ] 父项', '[x] 子项'],
    types: ['taskList', 'taskItem'],
  },
  {
    name: '分割线',
    md: '上文\n\n---\n\n下文\n',
    expect: ['---'],
    types: ['horizontalRule'],
  },
  {
    name: '表格（GFM）',
    md: '| 列 A | 列 B |\n| --- | --- |\n| 1 | 2 |\n',
    expect: ['列 A', '列 B'],
    types: ['table'],
  },
  {
    name: '硬换行',
    md: '第一行\\\n第二行\n',
    expect: ['第一行', '第二行'],
    types: ['paragraph'],
  },
  {
    name: '需要转义的裸字符',
    md: '计算 1 * 2 = 2，路径 a_b_c，方括号 [x] 与波浪号 ~y~。\n',
    expect: ['1 \\* 2 = 2', 'a\\_b\\_c'],
    types: ['paragraph'],
  },
  {
    name: '中文与英文混排',
    md: '## 快速开始 Quick Start\n\n使用 `npm i` 安装，然后运行 **dev** 模式。\n',
    expect: ['## 快速开始 Quick Start', '`npm i`', '**dev**'],
    types: ['heading', 'paragraph'],
  },
  {
    name: '空文档',
    md: '',
    expect: [],
    types: [],
  },
];

/** 收集 JSON 里出现过的节点类型（只收 block/inline 顶层，够用） */
function collectTypes(node, out = new Set()) {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collectTypes(n, out));
    return out;
  }
  if (node.type) out.add(node.type);
  if (node.content) collectTypes(node.content, out);
  return out;
}

function normalize(md) {
  return String(md == null ? '' : md).replace(/\r\n/g, '\n');
}

function main() {
  const extensions = buildExtensions();
  const manager = new MarkdownManager({
    indentation: { style: 'space', size: 2 },
    extensions,
  });

  const failures = [];

  for (const kase of CASES) {
    const input = normalize(kase.md);
    const problems = [];

    let json;
    try {
      json = manager.parse(input);
    } catch (err) {
      failures.push({ name: kase.name, problems: ['parse 抛异常: ' + err.message] });
      continue;
    }

    const types = collectTypes(json);
    for (const t of kase.types || []) {
      if (!types.has(t)) problems.push('缺失节点类型 ' + t + '（实际: ' + [...types].join(',') + '）');
    }

    if (!input.trim() && (json.content || []).length > 0) {
      problems.push('空输入解析出 ' + json.content.length + ' 个节点');
    }

    const md1 = normalize(manager.serialize(json));
    for (const needle of kase.expect || []) {
      if (md1.indexOf(needle) === -1) problems.push('序列化缺少片段 ' + JSON.stringify(needle));
    }

    // 往返稳定性：二次解析+序列化必须与第一次完全一致
    let md2 = '';
    try {
      md2 = normalize(manager.serialize(manager.parse(md1)));
    } catch (err) {
      problems.push('二次往返抛异常: ' + err.message);
    }
    if (md2 !== md1) {
      problems.push('往返不稳定:\n  1st=' + JSON.stringify(md1) + '\n  2nd=' + JSON.stringify(md2));
    }

    if (VERBOSE || problems.length) {
      console.log('  input =', JSON.stringify(input));
      console.log('  md1   =', JSON.stringify(md1));
      if (md2 !== md1) console.log('  md2   =', JSON.stringify(md2));
    }

    if (problems.length) failures.push({ name: kase.name, problems });
  }

  const total = CASES.length;
  const failed = failures.length;

  if (failed) {
    console.error('\n[markdown] 失败 ' + failed + '/' + total + '：');
    for (const f of failures) {
      console.error('  ✗ ' + f.name);
      f.problems.forEach((p) => console.error('      - ' + p));
    }
    process.exit(1);
  }

  console.log('[markdown] 全部通过：' + total + ' 条往返用例（parse ↔ serialize）');
}

main();
