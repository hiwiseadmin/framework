/**
 * WiseEditor 编辑器引擎闭环验证（零依赖）
 *
 * 用法：
 *   node build/verify-editor.mjs              # 跑全部用例
 *   node build/verify-editor.mjs --keep       # 保留截图（默认写入 .verify-out/）
 *   node build/verify-editor.mjs --port=8899  # 换端口
 *
 * ── 为什么需要它 ────────────────────────────────────────────
 * 编辑器由三块拼起来：vendor bundle（引擎）、wiseeditor-tiptap.js（UI 适配器）、
 * wiseeditor.css（样式）。任何一块改坏了，静态检查都看不出来，只有真跑一遍
 * 才知道工具条按钮还灵不灵、表单字段还同步不同步、表格还出不出得来。
 * 这个脚本用本机 Chrome 的真实 DOM 跑一遍最小闭环，替代手工点 demo.html。
 *
 * ── 为什么零依赖 ────────────────────────────────────────────
 * 不引入 playwright/puppeteer（都是几百 MB + 额外安装步骤）。这里只用
 *   - node 内置 http 提供静态服务
 *   - 本机 Chrome/Edge 的 --remote-debugging-port + node 内置 WebSocket 走 CDP
 * 因此 CI 与同事机器上都能直接跑。
 *
 * ── 覆盖范围 ────────────────────────────────────────────────
 *   1. 真实引擎：挂载 / 版本 / 命令 / 激活态 / 表格 / 源码切换 / 只读 /
 *      setContent 往返 / 表单同步 / 暗色可读性 / destroy
 *   2. Markdown 字段：三视图（所见即所得 / 分栏 / 源码）/ 同源预览只读实例 /
 *      源码视图下工具栏改文本 / 表单字段始终是 Markdown / 表格与围栏代码块往返
 *   3. 降级路径：vendor bundle 缺失时必须自动回退到内置 contenteditable 引擎
 *      （Markdown 字段例外：退到源码编辑器，绝不退到 contenteditable）
 *   4. 示例页冒烟：遍历 plugins/wiseeditor/example/ 下的三个「线上形态」示例页，
 *      在「/statics/** → public/」的真实上线 URL 布局下打开，验证模块图完整、零 404
 *      （用例 1/2 的站点根是 plugins/wiseeditor/statics，走的是短路径，
 *        覆盖不到「插件静态资源是否已发布」这一环）
 *   5. 直开版冒烟：以**真实 file:// URL** 打开 example/standalone.html，
 *      零服务、零 CDP 注入环境依赖，验证「双击 html 就能测」这条路径本身没坏。
 *      用例 1~4 全部建立在 HTTP + 站点绝对路径 + ES module 上，覆盖不到
 *      file:// 独有的两个拦路虎 —— 站点绝对路径会落到磁盘根、模块脚本会被 CORS 拦掉。
 *
 * ── 顺带当开发服务器用 ──────────────────────────────────────
 *   node build/verify-editor.mjs --serve
 *   同时在 /statics/**（→ public/）与 /example/**（→ 插件 example/）两个站点根上
 *   提供静态服务，浏览器里可直接看示例页 —— 布局与线上一致，所以示例页能打开
 *   就等于「静态资源已正确发布」。
 *
 *   注意：standalone.html（直开版）**不能**从这个服务器打开 —— 它用的是相对路径，
 *   与 /statics/** 的站点根布局不是一回事。它就该双击打开（或 file:// URL）。
 */

import http from 'node:http';
import fs from 'node:fs';
import crypto from 'node:crypto';
import net from 'node:net';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const STATICS = path.join(ROOT, 'plugins', 'wiseeditor', 'statics');
const BUNDLE = path.join(STATICS, 'js', 'vendor', 'tiptap.bundle.js');
const BUNDLE_BAK = BUNDLE + '.verify-bak';
const OUT_DIR = path.join(ROOT, '.verify-out');

// 真实发布布局：public/ 是站点根，插件静态资源由 AssetManager 合并复制到
// public/statics/plugins/{ident}/，页面引用 /statics/plugins/{ident}/...
const PUBLIC_DIR = path.join(ROOT, 'public');
const PUBLISHED_STATICS = path.join(PUBLIC_DIR, 'statics');
const PUBLISHED_ENTRY = path.join(PUBLISHED_STATICS, 'plugins', 'wiseeditor', 'js', 'wiseeditor.bundle.js');

// 示例页源码目录（开发期资产，不入 dist、不进 public/）。
// 页面里写的是站点绝对路径 /statics/...，因此本目录必须另挂一个站点根才能本地打开。
const EXAMPLE_DIR = path.join(ROOT, 'plugins', 'wiseeditor', 'example');
const EXAMPLE_URL_PREFIX = '/example';

const EXAMPLE_PAGES = [
  { slug: 'example-richtext', file: 'index.html', label: '示例页 · 富文本', deep: true, tipTap: true },
  // Markdown 页也用 TipTap 引擎（@tiptap/markdown），不再是"另一套引擎"。
  // 这一项保留 tipTap 标志，是因为它确实断言 window.TipTap 是否挂载。
  { slug: 'example-markdown', file: 'markdown.html', label: '示例页 · Markdown', deep: false, tipTap: true },
  { slug: 'example-api', file: 'api.html', label: '示例页 · 实例 API', deep: false, tipTap: true },
];

// 直开版：唯一一个「双击 html 就能跑」的页面，靠真实 file:// 验证（用例 4）。
// 它用的是经典脚本 + 相对路径，因此**不能**塞进上面的 EXAMPLE_PAGES 走 HTTP——
// 那套站点根布局（/statics/** → public/）与 file:// 的相对路径不是一回事。
const STANDALONE_FILE = 'standalone.html';
const STANDALONE_BUNDLE = path.join(EXAMPLE_DIR, 'dist', 'wiseeditor.example.js');

const PORT = Number((process.argv.find((a) => a.startsWith('--port=')) || '').split('=')[1] || 8899);
// DevTools 端口不再写死：每次用例用系统分配的临时端口（见 findFreePort），
// 避免上一次运行残留的 Chrome 占着端口导致 `bind() EADDRINUSE`。

const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  'C:/Users/fei21/AppData/Local/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
].filter(Boolean);

// ============================================================================
// 测试夹具
// ============================================================================

const FIXTURE_HTML = (title, body, runner) => `<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
<meta charset="utf-8">
<title>${title}</title>
<link rel="stylesheet" href="/css/wiseeditor.css">
</head>
<body>
${body}
<pre id="result">pending</pre>
<script type="module" src="/js/wiseeditor.bundle.js"></script>
<script type="module" src="/__verify/${runner}"></script>
</body>
</html>`;

/** 与 TipTapDriver::renderHtml() 输出结构一致的容器 + 隐藏域 */
const EDITOR_BLOCK = ({ id = 'editor_content', name = 'content', readonly = false, placeholder = '', value } = {}) => `
<div id="${id}" class="wiseeditor-container" style="height:300px"
     data-editor="wiseeditor" data-editor-type="tiptap" data-mode="richtext"
     data-name="${name}" data-upload-url="/admin/attachment/upload"
     ${readonly ? 'data-readonly="true"' : ''}
     ${placeholder ? `data-placeholder="${placeholder}"` : ''}></div>
<input type="hidden" name="${name}" id="${id}_input"
       value="${value ?? '&lt;p&gt;Hello &lt;strong&gt;world&lt;/strong&gt;&lt;/p&gt;'}">`;

/**
 * 用例 1 追加容器：验证 data-options 描述符驱动
 *
 * toolbar 只要 bold / image，且能力位关掉 upload —— 期望 image 被过滤，
 * 只渲染 1 个按钮（bold）+ 1 个分隔符。这条用例锁住「后端配置 → 前端工具栏」链路。
 */
const DESC_BLOCK = `
<div id="editor_desc" class="wiseeditor-container" style="height:200px"
     data-editor="wiseeditor" data-editor-type="tiptap" data-mode="richtext"
     data-name="desc"
     data-options="{&quot;toolbar&quot;:[&quot;bold&quot;,&quot;|&quot;,&quot;image&quot;,&quot;source&quot;],&quot;capabilities&quot;:{&quot;upload&quot;:false,&quot;source&quot;:false},&quot;capacity_limit&quot;:10}"></div>
<input type="hidden" name="desc" id="editor_desc_input" value="&lt;p&gt;desc&lt;/p&gt;">`;

/** 用例 1：真实引擎闭环 */
const CASE_MAIN = {
  name: '真实 TipTap 引擎闭环',
  html: FIXTURE_HTML(
    'VERIFY',
    EDITOR_BLOCK() + EDITOR_BLOCK({ id: 'editor_ro', name: 'ro', readonly: true, value: '&lt;p&gt;Read only&lt;/p&gt;' }) + DESC_BLOCK,
    'runner-main.js'
  ),
};

/**
 * 用例 2 追加容器：Markdown 字段
 *
 * 这个容器的存在意义是**防数据损坏**：没有引擎时，Markdown 字段必须把
 * 表单字段（textarea）露出来给人改，绝不能退到 contenteditable —— 否则
 * Markdown 原文会被读成 innerHTML，保存下去就变成 HTML，字段格式被静默改写。
 */
const MD_FALLBACK_BLOCK = `
<div id="editor_md_fb" class="wiseeditor-container wiseeditor-markdown" style="height:200px"
     data-editor="wiseeditor" data-editor-type="tiptap" data-mode="markdown"
     data-name="md_fb" data-upload-url="/api/attachment/upload"
     data-options="{&quot;format&quot;:&quot;markdown&quot;,&quot;view&quot;:&quot;wysiwyg&quot;}"></div>
<textarea name="md_fb" id="editor_md_fb_textarea" style="display:none;"># 降级标题

- 降级列表</textarea>`;

/** 用例 2：vendor bundle 缺失 → 降级 */
const CASE_FALLBACK = {
  name: 'vendor bundle 缺失时的降级路径',
  html: FIXTURE_HTML(
    'VERIFY',
    EDITOR_BLOCK({ placeholder: '请输入内容' }) + MD_FALLBACK_BLOCK,
    'runner-fallback.js'
  ),
};

/**
 * Markdown 用例的夹具（与 MarkdownDriver::renderHtml 输出结构一致）
 *
 * 三个关键点：
 *   - data-editor-type 仍是 tiptap（同一个引擎），靠 data-options.format=markdown 区分
 *   - 字段是 <textarea id="..._textarea">（存 Markdown 原文，不是隐藏域）
 *   - view=split：源码视图要带同引擎只读预览
 */
const MD_OPTIONS = JSON.stringify({
  format: 'markdown',
  view: 'split',
  // 故意要了 alignLeft/alignCenter：Markdown 字段必须把它们过滤掉（不可能存下来）
  toolbar: ['heading', 'bold', '|', 'alignLeft', 'alignCenter', '|',
    'fontFamily', 'fontSize', 'textColor', 'highlight', '|',
    'bulletList', 'taskList',
    'codeBlock', 'link', 'image', 'attach', 'table', '|', 'undo', 'redo', '|', 'outline', 'source', 'fullscreen'],
  capabilities: { format: true, table: true, code: true, upload: true, source: true, fullscreen: true },
  capacity_limit: null,
  upload: { url: '/api/attachment/upload', max_size: 5242880, allowed_ext: 'jpg,png' },
});

const MD_BLOCK = `
<div class="wiseeditor-wrapper">
  <div id="editor_md" class="wiseeditor-container wiseeditor-markdown" style="height:360px"
       data-editor="wiseeditor" data-editor-type="tiptap" data-mode="markdown"
       data-name="md" data-upload-url="/api/attachment/upload"
       data-options="${MD_OPTIONS.replace(/"/g, '&quot;')}"></div>
  <textarea name="md" id="editor_md_textarea" style="display:none;">## 已有标题

正文 **加粗** 段落。

- 列表 A
- 列表 B</textarea>
</div>`;

const CASE_MARKDOWN = {
  name: 'Markdown 字段：三视图 + 同源预览',
  html: FIXTURE_HTML('VERIFY', MD_BLOCK, 'runner-markdown.js'),
};

/** 用例 3 的标题（逐页展开，页面清单见 EXAMPLE_PAGES） */
const CASE_PUBLISH_NAME = '示例页冒烟（/statics/** 真实发布布局 + /example/**）';

const RUNNER_MAIN = String.raw`
var results = [], logs = [], warns = [];
(function () {
  var orig = console.warn;
  console.warn = function () {
    warns.push([].slice.call(arguments).map(String).join(' ').slice(0, 200));
    orig.apply(console, arguments);
  };
})();
function record(name, ok, detail) {
  results.push({ name: name, ok: !!ok, detail: detail === undefined ? null : String(detail).slice(0, 300) });
}
function eq(n, a, b) { record(n, a === b, 'actual=' + JSON.stringify(a) + ' expected=' + JSON.stringify(b)); }
function has(n, hay, needle) { var s = String(hay || ''); record(n, s.indexOf(needle) > -1, s.slice(0, 200)); }
function match(n, hay, re) { var s = String(hay || ''); record(n, re.test(s), s.slice(0, 200)); }

function raf() { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function waitFor(fn, timeout) {
  var t = timeout || 8000, start = performance.now();
  return (function loop() {
    if (performance.now() - start > t) return Promise.resolve(null);
    var v = null;
    try { v = fn(); } catch (e) { v = null; }
    if (v) return Promise.resolve(v);
    return sleep(50).then(loop);
  })();
}

async function run() {
  var container = document.getElementById('editor_content');
  var inst = await waitFor(function () { return window.WiseEditor && window.WiseEditor.get('editor_content'); });
  if (!inst) { record('编辑器实例创建成功', false, '超时'); return; }
  record('编辑器实例创建成功', true);

  eq('window.TipTap 已挂载', typeof window.TipTap, 'object');
  eq('TipTap 版本 = 3.31.4', window.TipTap && window.TipTap.version, '3.31.4');
  eq('engine = tiptap', inst.engine, 'tiptap');
  eq('适配器暴露 bundle 版本', inst.version, '3.31.4');

  var hidden = document.getElementById('editor_content_input');
  var pm = container.querySelector('.wiseeditor-pane .ProseMirror');
  record('ProseMirror 位于 .wiseeditor-pane 内', !!pm, pm ? pm.className : 'missing');
  has('初始内容渲染', inst.getContent(), '<strong>world</strong>');
  has('初始内容同步隐藏域', hidden.value, '<strong>world</strong>');

  var btns = [].slice.call(container.querySelectorAll('.wiseeditor-toolbar button[data-key]'));
  var keys = btns.map(function (b) { return b.dataset.key; });
  var descKeys = (inst.toolbarKeys || []).filter(function (k) { return k !== '|'; });
  eq('工具栏按钮数 = 描述符长度', btns.length, descKeys.length);
  record('工具栏按钮数 >= 15', btns.length >= 15, 'count=' + btns.length);
  record('按钮与描述符一一对应', descKeys.every(function (k) { return keys.indexOf(k) > -1; }), keys.join(','));
  record('含 table / clearFormat 按钮', keys.indexOf('table') > -1 && keys.indexOf('clearFormat') > -1, keys.join(','));

  var ed = inst.getInternal().editor;
  ed.commands.selectAll();
  await raf();
  container.querySelector('button[data-key="bold"]').click();
  await raf();
  match('加粗命令生效', inst.getContent(), /<strong>/);
  record('加粗按钮激活态', container.querySelector('button[data-key="bold"]').classList.contains('is-active'));

  // ── a11y：图标按钮必须对读屏可读、状态可查、键盘可达 ──────────
  var barEl = container.querySelector('.wiseeditor-toolbar');
  record('a11y：工具栏 role=toolbar + aria-label',
    barEl.getAttribute('role') === 'toolbar' && !!barEl.getAttribute('aria-label'),
    barEl.getAttribute('aria-label'));

  var boldBtnA11y = container.querySelector('button[data-key="bold"]');
  record('a11y：图标按钮有 aria-label（等于 title）',
    boldBtnA11y.getAttribute('aria-label') === boldBtnA11y.title,
    boldBtnA11y.getAttribute('aria-label') + ' / ' + boldBtnA11y.title);
  eq('a11y：可切换按钮有 aria-pressed', boldBtnA11y.getAttribute('aria-pressed'), 'true');

  // 方向键在工具栏内移动焦点（否则要按 20 多次 Tab 才能到正文）
  var firstBtn = container.querySelector('.wiseeditor-toolbar button[data-key]');
  firstBtn.focus();
  barEl.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
  await raf();
  record('a11y：工具栏支持左右方向键移动焦点',
    document.activeElement !== firstBtn
    && !!document.activeElement.closest('.wiseeditor-toolbar'),
    document.activeElement ? (document.activeElement.dataset.key || document.activeElement.tagName) : 'none');

  // Alt+F10：从正文任意位置一键跳到工具栏（Word / Google Docs 同款）
  if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
  container.dispatchEvent(new KeyboardEvent('keydown', { key: 'F10', altKey: true, bubbles: true, cancelable: true }));
  await raf();
  record('a11y：Alt+F10 把焦点送进工具栏',
    !!document.activeElement.closest('.wiseeditor-toolbar'),
    document.activeElement ? (document.activeElement.dataset.key || document.activeElement.tagName) : 'none');

  has('加粗后表单同步', hidden.value, '<strong>');

  // ── 段落格式下拉（原来 h2/h3/h4/paragraph 四个按钮，现在合并成一个）──
  ed.commands.setTextSelection(1);
  var headingBtn = container.querySelector('button[data-key="heading"]');
  record('工具栏有段落格式下拉', !!headingBtn);

  if (headingBtn) {
    headingBtn.click();
    await raf();
    var hMenu = document.querySelector('.wiseeditor-menu');
    record('下拉：菜单已打开', !!hMenu);

    var h2Item = hMenu && hMenu.querySelector('.wiseeditor-menu__item[data-value="2"]');
    record('下拉：含 H2 选项', !!h2Item);
    if (h2Item) {
      h2Item.click();
      await raf();
    }
    match('二级标题命令生效', inst.getContent(), /<h2>/);
    eq('下拉：按钮显示当前块类型', headingBtn.querySelector('[data-role="label"]').textContent, 'H2');

    // 富文本不应给出 H1（h1 留给页面标题）
    headingBtn.click();
    await raf();
    var hMenu2 = document.querySelector('.wiseeditor-menu');
    record('下拉：富文本不提供 H1', !!(hMenu2 && !hMenu2.querySelector('.wiseeditor-menu__item[data-value="1"]')));

    var pItem = hMenu2 && hMenu2.querySelector('.wiseeditor-menu__item[data-value="0"]');
    record('下拉：含「正文」选项', !!pItem);
    if (pItem) {
      pItem.click();
      await raf();
    }
    match('切回正文生效', inst.getContent(), /<p>/);
    eq('下拉：正文显示为「正文」', headingBtn.querySelector('[data-role="label"]').textContent, '正文');
  }

  // ── 字体 / 字号 / 颜色 / 高亮（只给 HTML 字段）──
  /**
   * 打开某个下拉并选中指定值
   *
   * @param {string} key  工具栏 key
   * @param {string} value 菜单项 data-value
   * @returns {boolean} 是否成功点到
   */
  function pickFromDropdown(key, value) {
    var trigger = container.querySelector('button[data-key="' + key + '"]');
    if (!trigger) return false;
    trigger.click();

    var menu = document.querySelector('.wiseeditor-menu');
    if (!menu) return false;

    // 不能用属性选择器：字体值里带双引号（"Microsoft YaHei", sans-serif），
    // 拼进选择器会解析失败。直接遍历比 dataset 更稳。
    var hit = null;
    var items = menu.querySelectorAll('.wiseeditor-menu__item');
    for (var i = 0; i < items.length; i += 1) {
      if (items[i].dataset.value === value) { hit = items[i]; break; }
    }
    if (!hit) return false;

    hit.click();
    return true;
  }

  inst.setContent('<p>样式测试</p>');
  await raf();
  ed.commands.selectAll();

  record('工具栏有字体下拉', !!container.querySelector('button[data-key="fontFamily"]'));
  record('工具栏有字号下拉', !!container.querySelector('button[data-key="fontSize"]'));
  record('工具栏有文字颜色下拉', !!container.querySelector('button[data-key="textColor"]'));
  record('工具栏有高亮下拉', !!container.querySelector('button[data-key="highlight"]'));

  if (pickFromDropdown('fontFamily', '"Microsoft YaHei", sans-serif')) {
    await raf();
    // 属性值里的引号在 HTML 序列化时会变成实体，正则要同时接受两种写法
    match('字体：写入 font-family', inst.getContent(),
      /font-family:\s*(?:&quot;|")?Microsoft YaHei/);
  }

  ed.commands.selectAll();
  if (pickFromDropdown('fontSize', '18px')) {
    await raf();
    match('字号：写入 font-size', inst.getContent(), /font-size:\s*18px/);
  }

  ed.commands.selectAll();
  if (pickFromDropdown('textColor', '#d93025')) {
    await raf();
    match('颜色：写入 color', inst.getContent(), /color:\s*rgb\(217,\s*48,\s*37\)/);
  }

  ed.commands.selectAll();
  if (pickFromDropdown('highlight', '#fff3a3')) {
    await raf();
    const hlHtml = inst.getContent();
    record('高亮：输出 mark 标签', hlHtml.indexOf('<mark') > -1, hlHtml.slice(0, 200));
  }

  // 字体/字号/颜色共用同一条 textStyle → 只应有一个 span（不是三层嵌套）
  var styleHtml = inst.getContent();
  eq('字体/字号/颜色合并到同一个 span',
    (styleHtml.match(/<span/g) || []).length, 1);

  var undoBtn = container.querySelector('button[data-key="undo"]');
  var redoBtn = container.querySelector('button[data-key="redo"]');
  record('undo 可用', undoBtn.disabled === false);
  undoBtn.click();
  await raf();
  record('撤销后 redo 变可用', redoBtn.disabled === false);

  // ── 查找替换 ────────────────────────────────────────────────
  inst.setContent('<p>alpha beta alpha</p><p>gamma alpha</p>');
  await raf();

  var findBar = container.querySelector('.wiseeditor-find');
  record('查找面板默认隐藏', !!findBar && findBar.hidden === true);

  container.dispatchEvent(new KeyboardEvent('keydown', {
    key: 'f', ctrlKey: true, bubbles: true, cancelable: true,
  }));
  await raf();
  record('Ctrl+F 打开查找面板', !!findBar && findBar.hidden === false);

  var findQuery = findBar.querySelector('[data-role="query"]');
  findQuery.value = 'alpha';
  findQuery.dispatchEvent(new Event('input', { bubbles: true }));
  await raf();

  eq('查找：命中数正确', findBar.querySelector('[data-role="count"]').textContent, '1/3');
  eq('查找：命中处加高亮装饰', container.querySelectorAll('.ws-find-hit').length, 3);
  eq('查找：只有一处是"当前命中"',
    container.querySelectorAll('.ws-find-hit.is-current').length, 1);

  findBar.querySelector('[data-role="next"]').click();
  await raf();
  eq('查找：下一个 → 2/3', findBar.querySelector('[data-role="count"]').textContent, '2/3');

  // 替换成不含查询词的文本：否则"不区分大小写"下 ALPHA 仍会命中 alpha，
  // 计数就不会归零（这不是 bug，是搜索大小写语义）
  findBar.querySelector('[data-role="replace"]').value = 'OMEGA';
  findBar.querySelector('[data-role="replace-one"]').click();
  await raf();
  has('替换：当前命中被替换', inst.getContent(), 'OMEGA');
  has('替换：其余命中保留', inst.getContent(), 'alpha');

  findBar.querySelector('[data-role="replace-all"]').click();
  await raf();
  record('全部替换：不再有小写 alpha',
    inst.getContent().indexOf('alpha') === -1, inst.getContent().slice(0, 160));
  eq('全部替换：命中数归零', findBar.querySelector('[data-role="count"]').textContent, '0/0');

  findBar.dispatchEvent(new KeyboardEvent('keydown', {
    key: 'Escape', bubbles: true, cancelable: true,
  }));
  await raf();
  record('Esc 关闭查找面板', findBar.hidden === true);
  eq('关闭后高亮清空', container.querySelectorAll('.ws-find-hit').length, 0);

  // 表格
  inst.setContent('<p>before</p>');
  await raf();
  ed.commands.setTextSelection(1);
  inst.getInternal().run('table', { rows: 3, cols: 3, withHeaderRow: true });
  await raf();
  var withTable = inst.getContent();
  has('表格插入成功', withTable, '<table');
  has('输出含 colgroup', withTable, '<colgroup');
  has('输出含 col', withTable, '<col');
  match('col 带 style', withTable, /<col[^>]*style=/);

  var tablebar = container.querySelector('.wiseeditor-tablebar');
  // 9 个原有操作 + toggleHeaderRow + 3 个单元格对齐
  record('表格工具条按钮数 = 13', !!tablebar && tablebar.childElementCount === 13, tablebar ? tablebar.childElementCount : 'missing');
  record('光标在表格内 → 工具条可见', tablebar.hidden === false);
  var addRow = tablebar.querySelector('button[data-table-cmd="addRowAfter"]');
  record('表格操作按钮可用', addRow.disabled === false);
  addRow.click();
  await raf();
  record('addRowAfter 生效', (inst.getContent().match(/<tr/g) || []).length >= 3);

  // 单元格对齐（走 tableCell.align ↔ GFM |:---:|，不是行内 style 硬写）
  var cellCenter = tablebar.querySelector('button[data-table-cmd="cellAlign"][data-table-arg="center"]');
  record('表格工具条有单元格居中按钮', !!cellCenter);
  if (cellCenter) {
    ed.commands.setTextSelection(1);
    await raf();
    cellCenter.click();
    await raf();
    has('单元格居中写入 style', inst.getContent(), 'text-align: center');
  }

  // 首行表头切换
  var headerBtn = tablebar.querySelector('button[data-table-cmd="toggleHeaderRow"]');
  record('表格工具条有表头切换按钮', !!headerBtn);
  if (headerBtn) {
    ed.commands.setTextSelection(1);
    await raf();
    headerBtn.click();
    await raf();
    record('toggleHeaderRow 生效（首行变 th 或退回 td）',
      inst.getContent().indexOf('<th') > -1 || inst.getContent().indexOf('<td') > -1,
      inst.getContent().slice(0, 160));
  }

  tablebar.querySelector('button[data-table-cmd="deleteTable"]').click();
  await raf();
  record('deleteTable 生效', inst.getContent().indexOf('<table') === -1);
  record('离开表格后工具条隐藏', tablebar.hidden === true);

  // ---- 段落对齐（HTML 字段专属；Markdown 字段不给这个按钮）----
  var alignCenterBtn = container.querySelector('.wiseeditor-toolbar button[data-key="alignCenter"]');
  record('富文本工具栏有居中按钮', !!alignCenterBtn);
  if (alignCenterBtn) {
    inst.setContent('<p>对齐测试</p>');
    await raf();
    ed.commands.selectAll();
    alignCenterBtn.click();
    await raf();
    has('居中：输出带 text-align', inst.getContent(), 'text-align: center');
    record('居中：按钮进入激活态', alignCenterBtn.classList.contains('is-active'),
      alignCenterBtn.className);

    // 再点一次左侧：应回到左对齐（Style 属性被清掉而不是叠加）
    container.querySelector('button[data-key="alignLeft"]').click();
    await raf();
    has('左对齐：改回 text-align: left', inst.getContent(), 'text-align: left');
  }

  // ---- 链接弹窗（1.2）----
  inst.setContent('<p>link target</p>');
  await raf();
  ed.commands.selectAll();
  await raf();
  container.querySelector('button[data-key="link"]').click();
  await raf();

  var linkPop = document.querySelector('.wiseeditor-popover');
  record('link：弹窗打开', !!linkPop);
  if (linkPop) {
    eq('link：预填选中文字', linkPop.querySelector('[data-role="text"]').value, 'link target');
    record('link：无已有链接时隐藏「移除链接」',
      linkPop.querySelector('[data-act="remove"]').style.display === 'none');

    // ── 回归：弹层里的输入框必须能被鼠标点进去 ──────────────
    // 曾经的 bug：为保住编辑器选区，给整个弹层挂了 mousedown.preventDefault()，
    // 结果输入框点了不聚焦（只能靠 Tab 切焦点）。正确做法是只拦按钮类按压。
    var textEv = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
    linkPop.querySelector('[data-role="text"]').dispatchEvent(textEv);
    record('link：输入框 mousedown 未被拦截（可点击切换焦点）',
      textEv.defaultPrevented === false, 'defaultPrevented=' + textEv.defaultPrevented);

    var okEv = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
    linkPop.querySelector('[data-act="ok"]').dispatchEvent(okEv);
    record('link：按钮 mousedown 仍被拦截（保住编辑器选区）',
      okEv.defaultPrevented === true, 'defaultPrevented=' + okEv.defaultPrevented);

    // 非法协议：拦下并保留弹层
    var hrefInput = linkPop.querySelector('[data-role="href"]');
    hrefInput.value = 'javascript:alert(1)';
    hrefInput.dispatchEvent(new Event('input', { bubbles: true }));
    linkPop.querySelector('[data-act="ok"]').click();
    await raf();
    record('link：非法协议被拒（弹层保持打开）', !!document.querySelector('.wiseeditor-popover'));
    has('link：给出协议提示',
      document.querySelector('.wiseeditor-popover [data-role="error"]').textContent, '仅支持');

    // 裸域名自动补 https 并写入
    hrefInput = document.querySelector('.wiseeditor-popover [data-role="href"]');
    hrefInput.value = 'example.com';
    hrefInput.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('.wiseeditor-popover [data-act="ok"]').click();
    await raf();

    record('link：提交后弹层关闭', !document.querySelector('.wiseeditor-popover'));
    match('link：写入内容（裸域名补 https）', inst.getContent(), /<a[^>]+href="https:\/\/example\.com"/);
    has('link：选中文字仍在', inst.getContent(), 'link target');

    // 光标落在链接内再打开 → 可移除
    ed.commands.selectAll();
    await raf();
    container.querySelector('button[data-key="link"]').click();
    await raf();
    var linkPop2 = document.querySelector('.wiseeditor-popover');
    record('link：已有链接时显示「移除链接」',
      !!linkPop2 && linkPop2.querySelector('[data-act="remove"]').style.display !== 'none');

    if (linkPop2) {
      linkPop2.querySelector('[data-act="remove"]').click();
      await raf();
      record('link：移除后不再有 a 标签', inst.getContent().indexOf('<a') === -1);
    }

    // 新窗口打开：target=_blank + rel 含 noopener
    inst.setContent('<p>blank link</p>');
    await raf();
    ed.commands.selectAll();
    await raf();
    container.querySelector('button[data-key="link"]').click();
    await raf();
    var linkPop3 = document.querySelector('.wiseeditor-popover');
    if (linkPop3) {
      var href3 = linkPop3.querySelector('[data-role="href"]');
      href3.value = 'https://a.test';
      href3.dispatchEvent(new Event('input', { bubbles: true }));
      linkPop3.querySelector('[data-role="blank"]').checked = true;
      linkPop3.querySelector('[data-act="ok"]').click();
      await raf();
      match('link：新窗口写入 target=_blank', inst.getContent(), /<a[^>]+target="_blank"/);
      match('link：新窗口带 noopener', inst.getContent(), /rel="[^"]*noopener/);
    } else {
      record('link：新窗口用例弹层打开', false, 'missing');
    }
  }

  // setContent 往返
  var complex = '<h2>标题</h2><p>正文 <strong>粗</strong><em>斜</em><u>下划</u><s>删</s></p>'
    + '<ul><li><p>项 A</p></li></ul><ol><li><p>项 1</p></li></ol>'
    + '<blockquote><p>引用</p></blockquote><pre class="wiseeditor-codeblock"><code>code()</code></pre><hr>'
    + '<p><a href="https://example.com" rel="noopener noreferrer nofollow">链接</a></p>'
    + '<p><img src="/a.png" alt="图" loading="lazy"></p>'
    + '<table><tbody><tr><td style="text-align: center"><p>中心</p></td><td colspan="2"><p>跨列</p></td></tr></tbody></table>';
  inst.setContent(complex);
  await raf();
  var round = inst.getContent();
  has('往返：标题', round, '<h2>标题</h2>');
  has('往返：加粗', round, '<strong>粗</strong>');
  has('往返：引用', round, '<blockquote>');
  has('往返：代码块 class', round, 'wiseeditor-codeblock');
  has('往返：分割线', round, '<hr>');
  match('往返：链接 href', round, /<a[^>]+href="https:\/\/example\.com"/);
  match('往返：图片属性', round, /<img[^>]+src="\/a\.png"/);
  match('往返：表格 align style', round, /text-align:\s*center/);
  match('往返：colspan', round, /colspan="2"/);
  eq('setContent 后隐藏域一致', hidden.value, round);

  // 源码模式
  var sourceBtn = container.querySelector('button[data-key="source"]');
  var source = container.querySelector('.wiseeditor-source');
  var pane = container.querySelector('.wiseeditor-pane');
  sourceBtn.click();
  await raf();
  record('源码模式：textarea 显示', source.hidden === false);
  record('源码模式：内容区隐藏', pane.hidden === true);
  has('源码模式：内容一致', source.value, '<h2>标题</h2>');
  source.value = '<p>from source</p>';
  source.dispatchEvent(new Event('input', { bubbles: true }));
  await raf();
  has('源码输入即时同步表单', hidden.value, 'from source');
  sourceBtn.click();
  await raf();
  record('回切富文本：内容区显示', pane.hidden === false);
  has('源码内容灌回编辑器', inst.getContent(), 'from source');
  record('源码按钮取消激活', !sourceBtn.classList.contains('is-active'));

  // 只读
  var roInst = await waitFor(function () { return window.WiseEditor.get('editor_ro'); });
  if (roInst) {
    eq('只读实例仍用真实引擎', roInst.engine, 'tiptap');
    var roContainer = document.getElementById('editor_ro');
    record('只读不渲染工具栏', !roContainer.querySelector('.wiseeditor-toolbar'));
    record('只读不可编辑', roInst.getInternal().editor.isEditable === false);
    has('只读内容渲染', roInst.getContent(), 'Read only');
  } else {
    record('只读实例创建成功', false, '超时');
  }

  // 暗色 / 亮色
  var html = document.documentElement;
  html.setAttribute('data-bs-theme', 'light');
  await raf();
  var lightColor = getComputedStyle(pm).color;
  var lightBg = getComputedStyle(container).backgroundColor;
  html.setAttribute('data-bs-theme', 'dark');
  await raf();
  var darkColor = getComputedStyle(pm).color;
  var darkBg = getComputedStyle(container).backgroundColor;
  function nums(c) { return (c.match(/\d+/g) || []).slice(0, 3).map(Number); }
  function isLight(c) { return nums(c).every(function (n) { return n > 200; }); }
  function isDark(c) { return nums(c).every(function (n) { return n < 90; }); }
  record('亮色：深字浅底', isDark(lightColor) && isLight(lightBg), 'color=' + lightColor + ' bg=' + lightBg);
  record('暗色：浅字深底', isLight(darkColor) && isDark(darkBg), 'color=' + darkColor + ' bg=' + darkBg);
  html.setAttribute('data-bs-theme', 'light');

  // ---- data-options 描述符驱动（后端配置 → 前端工具栏）----
  var descInst = await waitFor(function () { return window.WiseEditor.get('editor_desc'); });
  if (descInst) {
    var descContainer = document.getElementById('editor_desc');
    var descBtns = [].slice.call(descContainer.querySelectorAll('.wiseeditor-toolbar button[data-key]'));
    eq('data-options：按钮数按描述符渲染（image 被能力位过滤）', descBtns.length, 1);
    eq('data-options：按钮 key 正确', descBtns.map(function (b) { return b.dataset.key; }).join(','), 'bold');
    eq('data-options：toolbarKeys 记录过滤结果', (descInst.toolbarKeys || []).join(','), 'bold,|');
    eq('data-options：能力位隐藏 image 按钮',
      descContainer.querySelectorAll('button[data-key="image"]').length, 0);
    eq('data-options：能力位隐藏 source 按钮',
      descContainer.querySelectorAll('button[data-key="source"]').length, 0);
    has('data-options：内容正常渲染', descInst.getContent(), 'desc');
  } else {
    record('data-options 容器挂载', false, '超时');
  }

  // ---- 状态栏（1.5）----
  inst.setContent('<p>abcde</p>');
  await raf();
  await raf();

  var barEl = container.querySelector('.wiseeditor-statusbar');
  record('状态栏：已渲染', !!barEl);
  if (barEl) {
    has('状态栏：显示字数', barEl.textContent, '字数');
    eq('状态栏：字符数随内容更新',
      barEl.querySelector('[data-role="chars"]').textContent, '字符 5');
    record('状态栏：未配上限时隐藏上限', barEl.querySelector('[data-role="limit"]').hidden === true);
  }

  if (descInst) {
    descInst.setContent('<p>123456789</p>');
    await raf();
    await raf();

    var descBar = document.getElementById('editor_desc').querySelector('.wiseeditor-statusbar');
    var descLimit = descBar.querySelector('[data-role="limit"]');
    has('状态栏：配了上限时显示 x / N', descLimit.textContent, '/ 10');
    record('状态栏：使用率 ≥90% 标记 is-warn',
      descLimit.classList.contains('is-warn'), descLimit.className);

    descInst.setContent('<p>123456789012345</p>');
    await raf();
    await raf();
    record('状态栏：引擎按上限阻断超限写入',
      descInst.getContent().replace(/<[^>]+>/g, '').length <= 10,
      descInst.getContent());
  }

  // ---- 弹层基类（Popover）----
  try {
    var pop = await import('/js/wiseeditor-popover.js');

    var anchorEl = document.createElement('button');
    anchorEl.type = 'button';
    anchorEl.textContent = 'anchor';
    // 贴右下角：下方空间不足，应触发向上翻转 + 右侧夹取
    anchorEl.style.cssText = 'position:fixed;right:8px;bottom:8px;z-index:1090';
    document.body.appendChild(anchorEl);

    pop.openPopover({
      anchor: anchorEl,
      content: '<button type="button" id="pop_a">A</button><button type="button" id="pop_b">B</button>',
      className: 'verify-pop',
    });
    await raf();

    var popEl = document.querySelector('.wiseeditor-popover');
    record('popover：插入 document.body', !!popEl && popEl.parentElement === document.body);

    var pr = popEl.getBoundingClientRect();
    record('popover：定位在视口内（含边缘翻转）',
      pr.top >= 0 && pr.left >= 0 && pr.bottom <= window.innerHeight + 1 && pr.right <= window.innerWidth + 1,
      'rect=' + [pr.top, pr.left, pr.bottom, pr.right].map(Math.round).join(','));
    record('popover：贴底时翻转到锚点上方', pr.bottom <= anchorEl.getBoundingClientRect().top + 1,
      'popBottom=' + Math.round(pr.bottom) + ' anchorTop=' + Math.round(anchorEl.getBoundingClientRect().top));
    record('popover：初始焦点在面板内', popEl.contains(document.activeElement),
      document.activeElement && document.activeElement.id);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await raf();
    record('popover：Esc 关闭', !document.querySelector('.wiseeditor-popover'));
    record('popover：焦点归还锚点', document.activeElement === anchorEl);

    // 点击外部关闭
    pop.openPopover({ anchor: anchorEl, content: '<button type="button">X</button>' });
    await raf();
    var outside = document.createElement('div');
    document.body.appendChild(outside);
    outside.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    await raf();
    record('popover：点击外部关闭', !document.querySelector('.wiseeditor-popover'));
    outside.remove();

    // 单例：连续打开只保留最后一个
    pop.openPopover({ anchor: anchorEl, content: '<button type="button">1</button>' });
    pop.openPopover({ anchor: anchorEl, content: '<button type="button">2</button>' });
    await raf();
    eq('popover：单例（同时只存在一个）', document.querySelectorAll('.wiseeditor-popover').length, 1);
    pop.closePopover();

    anchorEl.remove();
  } catch (err) {
    record('popover 用例执行', false, (err && err.message) || String(err));
  }

  // ---- 全屏（1.6）----
  try {
    var fsBtn = container.querySelector('button[data-key="fullscreen"]');
    record('全屏：工具栏有按钮', !!fsBtn);

    if (fsBtn) {
      fsBtn.click();
      await raf();

      record('全屏：容器进入 is-fullscreen', container.classList.contains('is-fullscreen'));
      record('全屏：页面滚动被锁', document.body.style.overflow === 'hidden');
      record('全屏：按钮切到激活态', fsBtn.classList.contains('is-active'));

      var fsRect = container.getBoundingClientRect();
      record('全屏：铺满视口（覆盖内联 height）',
        Math.abs(fsRect.width - window.innerWidth) < 2 && Math.abs(fsRect.height - window.innerHeight) < 2,
        Math.round(fsRect.width) + 'x' + Math.round(fsRect.height));

      // 弹层开着时 Esc 只关弹层，不退出全屏
      if (pop && typeof pop.openPopover === 'function') {
        pop.openPopover({ anchor: fsBtn, content: '<button type="button">x</button>' });
        await raf();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await raf();
        record('全屏：Esc 优先关弹层',
          !document.querySelector('.wiseeditor-popover') && container.classList.contains('is-fullscreen'));
      }

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      await raf();

      record('全屏：Esc 退出', !container.classList.contains('is-fullscreen'));
      record('全屏：滚动锁已恢复', document.body.style.overflow === '');
      record('全屏：按钮回到非激活态', !fsBtn.classList.contains('is-active'));
    }
  } catch (err) {
    record('全屏用例执行', false, (err && err.message) || String(err));
  }

  // ---- 源码回切校验（1.7）----
  try {
    var srcBtn = container.querySelector('button[data-key="source"]');
    var srcArea = container.querySelector('.wiseeditor-source');

    record('源码：按钮与输入区存在', !!srcBtn && !!srcArea);

    if (srcBtn && srcArea) {
      // 保证处于指定状态：拒绝回切时不会改变状态，后续步骤必须显式对齐
      async function ensureSource(on) {
        if ((srcArea.hidden === false) !== on) {
          srcBtn.click();
          await raf();
        }
      }

      await ensureSource(true);
      record('源码：已切到源码态', srcArea.hidden === false);

      // 空源码 → 拒绝回切
      srcArea.value = '';
      srcBtn.click();
      await raf();
      record('源码：空内容拒绝回切', srcArea.hidden === false);

      // 末尾停在标签中间（粘贴被截断）→ 拒绝
      srcArea.value = '<p>abc<div class="x"';
      srcBtn.click();
      await raf();
      record('源码：未闭合标签拒绝回切', srcArea.hidden === false);

      // 纯文本 → 自动包段落
      srcArea.value = 'hello\n\nworld';
      srcBtn.click();
      await raf();
      record('源码：纯文本可回切', srcArea.hidden === true);
      has('源码：第一段已生成', inst.getContent(), '<p>hello</p>');
      has('源码：第二段已生成', inst.getContent(), '<p>world</p>');

      // 合法 HTML 正常往返
      await ensureSource(true);
      srcArea.value = '<p>back to rich</p>';
      srcBtn.click();
      await raf();
      record('源码：合法 HTML 回到富文本', srcArea.hidden === true);
      has('源码：内容已更新', inst.getContent(), 'back to rich');
    }
  } catch (err) {
    record('源码校验用例执行', false, (err && err.message) || String(err));
  }

  // ---- 上传 v2（预校验 / 错误映射 / 进度 / 并发）----
  var RealXHR = window.XMLHttpRequest;
  try {
    var upMod = await import('/js/wiseeditor-upload.js');

    // 假 XHR：可控状态码/响应体，并统计并发峰值
    function fakeXhr(responder) {
      var stat = { sends: 0, inflight: 0, maxInflight: 0 };

      function Fake() {
        this.upload = {};
        this.status = 0;
        this.responseText = '';
      }
      Fake.prototype.open = function () {};
      Fake.prototype.setRequestHeader = function () {};
      Fake.prototype.send = function () {
        stat.sends += 1;
        stat.inflight += 1;
        stat.maxInflight = Math.max(stat.maxInflight, stat.inflight);

        var self = this;
        setTimeout(function () {
          stat.inflight -= 1;
          if (self.upload.onprogress) {
            self.upload.onprogress({ lengthComputable: true, loaded: 50, total: 100 });
          }
          var r = responder(stat.sends) || {};
          if (r.abort) {
            if (self.onabort) self.onabort();
            return;
          }
          self.status = r.status || 200;
          self.responseText = r.body || '';
          if (self.onload) self.onload();
        }, 15);
      };
      Fake.prototype.abort = function () {
        stat.inflight -= 1;
        if (this.onabort) this.onabort();
      };

      return { Fake: Fake, stat: stat };
    }

    function mkFile(size, name) {
      return new File([new Uint8Array(size)], name, { type: 'image/png' });
    }

    var OK_BODY = '{"code":0,"data":{"url":"/u/a.png","id":7,"size":10}}';

    // 1) 预校验：超限不发请求
    var f1 = fakeXhr(function () { return { status: 200, body: OK_BODY }; });
    window.XMLHttpRequest = f1.Fake;

    var tooLarge = null;
    try { await upMod.uploadImage(mkFile(1024, 'a.png'), { maxSize: 512 }); } catch (e) { tooLarge = e; }
    eq('upload：超限被拦截（code）', tooLarge && tooLarge.code, 'too_large');
    eq('upload：超限不发请求', f1.stat.sends, 0);

    // 2) 类型不符
    var f2 = fakeXhr(function () { return { status: 200, body: OK_BODY }; });
    window.XMLHttpRequest = f2.Fake;

    var badExt = null;
    try { await upMod.uploadImage(mkFile(10, 'a.exe'), { allowedExt: 'jpg,png' }); } catch (e) { badExt = e; }
    eq('upload：类型不符被拦截（code）', badExt && badExt.code, 'bad_ext');
    eq('upload：类型不符不发请求', f2.stat.sends, 0);

    // 3) 429 映射
    var f3 = fakeXhr(function () { return { status: 429, body: '' }; });
    window.XMLHttpRequest = f3.Fake;

    var rateErr = null;
    try { await upMod.uploadImage(mkFile(10, 'a.png')); } catch (e) { rateErr = e; }
    eq('upload：429 → rate_limited', rateErr && rateErr.code, 'rate_limited');
    record('upload：429 提示可读', !!rateErr && rateErr.message.indexOf('频繁') > -1, rateErr && rateErr.message);

    // 4) 成功 + 进度
    var f4 = fakeXhr(function () { return { status: 200, body: OK_BODY }; });
    window.XMLHttpRequest = f4.Fake;

    var progresses = [];
    var okUrl = await upMod.uploadImage(mkFile(10, 'ok.png'), {
      onProgress: function (p) { progresses.push(p); },
    });
    eq('upload：成功返回 url', okUrl, '/u/a.png');
    record('upload：进度回调收到 50%', progresses.length > 0 && progresses[0] === 50, progresses.join(','));

    // 5) 服务端业务错误 msg 透出
    var f5 = fakeXhr(function () { return { status: 200, body: '{"code":1,"msg":"磁盘已满"}' }; });
    window.XMLHttpRequest = f5.Fake;

    var srvErr = null;
    try { await upMod.uploadImage(mkFile(10, 'a.png')); } catch (e) { srvErr = e; }
    eq('upload：服务端 msg 透出', srvErr && srvErr.message, '磁盘已满');

    // 6) 并发闸门 ≤ 3
    var f6 = fakeXhr(function () { return { status: 200, body: OK_BODY }; });
    window.XMLHttpRequest = f6.Fake;

    var batch = [];
    for (var bi = 0; bi < 6; bi += 1) {
      batch.push(upMod.uploadImage(mkFile(10, 'b' + bi + '.png')));
    }
    await Promise.all(batch);
    record('upload：并发峰值不超过 3', f6.stat.maxInflight <= 3, 'max=' + f6.stat.maxInflight);
    eq('upload：批次全部完成', f6.stat.sends, 6);
  } catch (err) {
    record('upload 用例执行', false, (err && err.message) || String(err));
  } finally {
    window.XMLHttpRequest = RealXHR;
  }

  // ---- 上传 v2：多选 / 进度 / 取消 / 能力位请求头 ----
  var v2PrevXhr = window.XMLHttpRequest;
  try {
    var v2Stat = { sends: 0, aborts: 0, headers: [] };
    var v2Pending = [];

    function V2Xhr() {
      this.upload = {};
      this.status = 0;
      this.responseText = '';
      this.headers = {};
    }
    V2Xhr.prototype.open = function () {};
    V2Xhr.prototype.setRequestHeader = function (name, value) {
      this.headers[name] = value;
      v2Stat.headers.push([name, value]);
    };
    V2Xhr.prototype.send = function () {
      v2Stat.sends += 1;
      v2Pending.push(this);
    };
    V2Xhr.prototype.abort = function () {
      v2Stat.aborts += 1;
      if (this.onabort) this.onabort();
    };
    window.XMLHttpRequest = V2Xhr;

    var fileInput = container.querySelector('input[type="file"]');
    eq('上传 v2：文件框支持多选', !!(fileInput && fileInput.multiple), true);

    inst.setContent('');
    await raf();

    var pick = new DataTransfer();
    pick.items.add(new File([new Uint8Array(16)], 'a.png', { type: 'image/png' }));
    pick.items.add(new File([new Uint8Array(16)], 'b.png', { type: 'image/png' }));

    fileInput.files = pick.files;
    fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    await raf();

    eq('上传 v2：多文件各插一个占位图', container.querySelectorAll('.ProseMirror img').length, 2);
    eq('上传 v2：两个请求都已发出', v2Stat.sends, 2);

    var capHeader = v2Stat.headers.filter(function (h) { return h[0] === 'X-Wise-Editor-Capability'; })[0];
    eq('上传 v2：请求带能力位头（服务端二次校验依据）', capHeader && capHeader[1], 'upload');

    var busy = container.querySelector('.wiseeditor-statusbar__busy');
    record('上传 v2：状态栏显示上传进度',
      !!busy && !busy.hidden && busy.textContent.indexOf('上传中') > -1,
      busy ? busy.textContent : 'missing');

    if (v2Pending[0] && v2Pending[0].upload.onprogress) {
      v2Pending[0].upload.onprogress({ lengthComputable: true, loaded: 50, total: 100 });
    }
    await raf();
    record('上传 v2：进度百分比写进状态栏',
      !!busy && busy.textContent.indexOf('%') > -1, busy ? busy.textContent : 'missing');

    var cancelBtn = container.querySelector('.wiseeditor-statusbar__cancel');
    record('上传 v2：状态栏提供取消按钮', !!cancelBtn && !cancelBtn.hidden);
    if (cancelBtn) cancelBtn.click();
    await raf();
    await raf();

    eq('上传 v2：取消会中止进行中的请求', v2Stat.aborts, 2);
    eq('上传 v2：取消后占位图被移除', container.querySelectorAll('.ProseMirror img').length, 0);
    record('上传 v2：取消后状态栏收起', !!busy && busy.hidden === true, busy ? busy.hidden : 'missing');
    record('上传 v2：取消不算失败（不弹错误）',
      !warns.some(function (w) { return w.indexOf('图片上传失败') > -1; }), warns.join(' | ').slice(0, 200));
  } finally {
    window.XMLHttpRequest = v2PrevXhr;
  }

  // ---- 附件插入（非图片文件 → 正文里的链接）----
  var attachBtn = container.querySelector('.wiseeditor-toolbar button[data-key="attach"]');
  record('工具栏有附件按钮', !!attachBtn);

  var fileInputs = container.querySelectorAll('input[type="file"]');
  record('附件用独立文件框（不限类型）',
    fileInputs.length >= 2 && !fileInputs[1].accept,
    'count=' + fileInputs.length + ' accept=' + (fileInputs[1] ? (fileInputs[1].accept || '(空)') : 'n/a'));

  if (attachBtn && fileInputs[1]) {
    var atPrevXhr = window.XMLHttpRequest;
    var atReq = null;

    try {
      function AtXhr() {
        this.upload = {};
        this.status = 0;
        this.responseText = '';
        this.headers = {};
      }
      AtXhr.prototype.open = function () {};
      AtXhr.prototype.setRequestHeader = function (k, v) { this.headers[k] = v; };
      AtXhr.prototype.send = function () { atReq = this; };
      AtXhr.prototype.abort = function () { if (this.onabort) this.onabort(); };
      window.XMLHttpRequest = AtXhr;

      inst.setContent('<p>正文</p>');
      await raf();
      ed.commands.setTextSelection(3);

      var attachFile = new File([new Uint8Array(8)], '说明.pdf', { type: 'application/pdf' });
      var attachDt = new DataTransfer();
      attachDt.items.add(attachFile);
      fileInputs[1].files = attachDt.files;
      fileInputs[1].dispatchEvent(new Event('change', { bubbles: true }));
      await raf();

      record('附件：上传已发起', !!atReq);
      eq('附件：请求带 attach 能力位头（与图片上传区分）',
        atReq && atReq.headers['X-Wise-Editor-Capability'], 'attach');

      // 手动让上传成功返回
      atReq.status = 200;
      atReq.responseText = JSON.stringify({
        code: 0,
        data: { id: 9, url: 'https://cdn.test/files/shuoming.pdf', size: 8 },
      });
      atReq.onload();
      await raf();
      await raf();

      var atHtml = inst.getContent();
      has('附件：正文插入文件名文字', atHtml, '说明.pdf');
      match('附件：文字带上上传地址', atHtml, /href="https:\/\/cdn\.test\/files\/shuoming\.pdf"/);
      record('附件：链接带 noopener', atHtml.indexOf('noopener') > -1, atHtml.slice(0, 200));
      record('附件：没有插入图片节点', atHtml.indexOf('<img') === -1, atHtml.slice(0, 160));
    } finally {
      window.XMLHttpRequest = atPrevXhr;
    }
  }

  // ---- 图片粘贴 / 拖拽（1.4）----
  var prevXhr = window.XMLHttpRequest;
  try {
    // 手动放行的假 XHR：send 只入队，由用例决定何时回包 —— 否则占位图
    // 会在断言前就被替换掉（20ms 回包 vs 一帧 rAF 的竞态）。
    var pendingXhr = [];

    function ManualXhr() {
      this.upload = {};
      this.status = 0;
      this.responseText = '';
    }
    ManualXhr.prototype.open = function () {};
    ManualXhr.prototype.setRequestHeader = function () {};
    ManualXhr.prototype.send = function () { pendingXhr.push(this); };
    ManualXhr.prototype.abort = function () { if (this.onabort) this.onabort(); };
    ManualXhr.prototype.respond = function (status, body) {
      this.status = status;
      this.responseText = body;
      if (this.onload) this.onload();
    };

    function settle(status, body) {
      var xhr = pendingXhr.shift();
      if (!xhr) return false;
      xhr.respond(status, body);
      return true;
    }

    function imageFile(name) {
      return new File([new Uint8Array(8)], name, { type: 'image/png' });
    }

    function tick(ms) {
      return new Promise((resolve) => setTimeout(resolve, ms || 10));
    }

    var pmDom = container.querySelector('.ProseMirror');
    window.XMLHttpRequest = ManualXhr;

    // 1) 粘贴：先占位（blob），回包后替换
    inst.setContent('<p>paste here</p>');
    await raf();
    ed.commands.focus('end');

    var dt = new DataTransfer();
    dt.items.add(imageFile('paste.png'));
    pmDom.dispatchEvent(new ClipboardEvent('paste', {
      clipboardData: dt, bubbles: true, cancelable: true,
    }));
    await tick();

    eq('paste：请求已发出', pendingXhr.length, 1);
    record('paste：立即插入占位图', inst.getContent().indexOf('blob:') > -1,
      inst.getContent().slice(0, 120));

    settle(200, '{"code":0,"data":{"url":"/u/paste.png"}}');
    await tick(30);

    has('paste：占位图替换为最终地址', inst.getContent(), '/u/paste.png');
    record('paste：占位图已清除', inst.getContent().indexOf('blob:') === -1);

    // 2) 粘贴失败：占位图被移除
    pendingXhr.length = 0;
    inst.setContent('<p>fail here</p>');
    await raf();
    ed.commands.focus('end');

    var dt2 = new DataTransfer();
    dt2.items.add(imageFile('bad.png'));
    pmDom.dispatchEvent(new ClipboardEvent('paste', {
      clipboardData: dt2, bubbles: true, cancelable: true,
    }));
    await tick();

    settle(500, '');
    await tick(30);

    record('paste：失败后移除占位图',
      inst.getContent().indexOf('blob:') === -1 && inst.getContent().indexOf('<img') === -1,
      inst.getContent().slice(0, 120));

    // 3) 拖拽：落点插入
    pendingXhr.length = 0;
    inst.setContent('<p>drop here</p>');
    await raf();
    ed.commands.focus('end');

    var dt3 = new DataTransfer();
    dt3.items.add(imageFile('drop.png'));
    var rect = pmDom.getBoundingClientRect();
    pmDom.dispatchEvent(new DragEvent('drop', {
      dataTransfer: dt3,
      bubbles: true,
      cancelable: true,
      clientX: Math.round(rect.left + 8),
      clientY: Math.round(rect.top + 8),
    }));
    await tick();

    eq('drop：请求已发出', pendingXhr.length, 1);
    settle(200, '{"code":0,"data":{"url":"/u/drop.png"}}');
    await tick(30);

    has('drop：图片插入到内容中', inst.getContent(), '/u/drop.png');
  } catch (err) {
    record('paste/drop 用例执行', false, (err && err.message) || String(err));
  } finally {
    window.XMLHttpRequest = prevXhr;
  }

  // 销毁
  window.WiseEditor.destroy('editor_content');
  await raf();
  record('destroy 清空容器', container.innerHTML === '');
  record('destroy 后 get 返回 undefined', window.WiseEditor.get('editor_content') === undefined);
  record('无未注册工具栏按钮告警',
    !warns.some(function (w) { return w.indexOf('未注册的工具栏按钮') > -1; }),
    warns.join(' | ').slice(0, 240));
}

async function main() {
  try { await run(); }
  catch (err) { record('验证脚本异常', false, (err && err.stack) || String(err)); }
  var failed = results.filter(function (r) { return !r.ok; });
  document.getElementById('result').textContent = JSON.stringify({
    total: results.length, passed: results.length - failed.length, failed: failed.length,
    results: results, logs: logs
  });
  document.title = failed.length === 0 ? 'VERIFY_OK' : 'VERIFY_FAIL';
}
main();
`;

const RUNNER_FALLBACK = String.raw`
var results = [], logs = [];
function record(name, ok, detail) {
  results.push({ name: name, ok: !!ok, detail: detail === undefined ? null : String(detail).slice(0, 260) });
}
function eq(n, a, b) { record(n, a === b, 'actual=' + JSON.stringify(a) + ' expected=' + JSON.stringify(b)); }
function has(n, hay, needle) { var s = String(hay || ''); record(n, s.indexOf(needle) > -1, s.slice(0, 200)); }
function raf() { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function waitFor(fn, timeout) {
  var t = timeout || 8000, start = performance.now();
  return (function loop() {
    if (performance.now() - start > t) return Promise.resolve(null);
    var v = null;
    try { v = fn(); } catch (e) { v = null; }
    if (v) return Promise.resolve(v);
    return sleep(50).then(loop);
  })();
}

async function run() {
  var origWarn = console.warn;
  console.warn = function () {
    logs.push('warn: ' + [].slice.call(arguments).map(String).join(' ').slice(0, 240));
    origWarn.apply(console, arguments);
  };

  var container = document.getElementById('editor_content');
  var inst = await waitFor(function () { return window.WiseEditor && window.WiseEditor.get('editor_content'); });
  if (!inst) { record('降级实例创建成功', false, '超时'); return; }
  record('降级实例创建成功', true);

  eq('engine 已降级为 native', inst.engine, 'native');
  record('window.TipTap 未被挂载', typeof window.TipTap === 'undefined');
  record('出现降级告警', logs.some(function (l) { return l.indexOf('退化为内置引擎') > -1; }), logs.join(' | ').slice(0, 240));

  var editable = container.querySelector('.wiseeditor-content[contenteditable="true"]');
  record('contenteditable 内容区存在', !!editable);
  eq('占位符已下发', editable && editable.getAttribute('data-placeholder'), '请输入内容');

  var hidden = document.getElementById('editor_content_input');
  has('表单字段同步初始值', hidden.value, '<strong>world</strong>');
  var fbDesc = (inst.toolbarKeys || []).filter(function (k) { return k !== '|'; });
  eq('降级工具栏按钮数 = 描述符长度', container.querySelectorAll('.wiseeditor-toolbar button[data-key]').length, fbDesc.length);

  inst.focus();
  document.execCommand('selectAll', false, null);
  container.querySelector('button[data-key="bold"]').click();
  await raf();
  record('降级加粗命令生效', /<(b|strong)[^>]*>/i.test(inst.getContent()));

  var sourceBtn = container.querySelector('button[data-key="source"]');
  var source = container.querySelector('.wiseeditor-source');
  sourceBtn.click();
  await raf();
  record('降级源码模式：textarea 显示', source.hidden === false);
  has('降级源码模式：内容写入 textarea', source.value, 'world');
  sourceBtn.click();
  await raf();
  record('降级源码模式：已回切', source.hidden === true);

  inst.setContent('<p>契约测试</p>');
  await raf();
  has('setContent 生效', inst.getContent(), '契约测试');
  has('setContent 同步表单', hidden.value, '契约测试');

  inst.setContent('<p><br></p>');
  await raf();
  eq('空内容归一化为空串', inst.getContent(), '');
  eq('空内容 isEmpty 为 true', inst.isEmpty(), true);
  // 契约 v2：isDirty = 与初始快照比较（不是"是否非空"）
  record('清空后相对基线 isDirty=true', inst.isDirty() === true, 'actual=' + inst.isDirty());
  inst.setContent('<p>Hello <strong>world</strong></p>');
  await raf();
  record('恢复基线内容后 isDirty=false', inst.isDirty() === false, 'actual=' + inst.isDirty());

  // 链接弹窗（降级引擎：range + insertHTML）
  inst.setContent('<p>fb link</p>');
  await raf();
  inst.focus();
  document.execCommand('selectAll', false, null);
  container.querySelector('button[data-key="link"]').click();
  await raf();

  var fbLinkPop = document.querySelector('.wiseeditor-popover');
  record('降级：链接弹窗打开', !!fbLinkPop);
  if (fbLinkPop) {
    var fbHref = fbLinkPop.querySelector('[data-role="href"]');
    fbHref.value = 'https://example.org';
    fbHref.dispatchEvent(new Event('input', { bubbles: true }));
    fbLinkPop.querySelector('[data-act="ok"]').click();
    await raf();
    has('降级：链接写入内容', inst.getContent(), 'https://example.org');
  }

  // ── Markdown 字段的降级：露出表单字段本身，绝不退 richtext ─────
  var mdBox = document.getElementById('editor_md_fb');
  var mdField = document.getElementById('editor_md_fb_textarea');
  var mdInst = await waitFor(function () { return window.WiseEditor && window.WiseEditor.get('editor_md_fb'); });
  record('降级：Markdown 实例创建成功', !!mdInst);
  if (mdInst) {
    eq('降级：Markdown 格式仍是 markdown', mdInst.format, 'markdown');
    eq('降级：Markdown 走纯文本通道（engine=plain）', mdInst.engine, 'plain');
    record('降级：Markdown 容器内没有 contenteditable',
      !mdBox.querySelector('[contenteditable="true"]'), mdBox.innerHTML.slice(0, 120));
    record('降级：给出"资源加载失败"提示', !!mdBox.querySelector('.wiseeditor-plain-md-tip'));
    record('降级：表单字段已露出（不是 display:none）',
      !mdField.hidden && getComputedStyle(mdField).display !== 'none',
      'hidden=' + mdField.hidden + ' display=' + getComputedStyle(mdField).display);
    has('降级：Markdown 原文未被解析改写成 HTML', mdInst.getContent(), '# 降级标题');
    record('降级：Markdown 值里不含 HTML 标签',
      mdInst.getContent().indexOf('<h1') === -1, mdInst.getContent().slice(0, 160));

    // 露出来的字段必须"改得动、提得走"：改完直接就是提交值
    mdField.value = '# 降级标题\n\n改过一版';
    mdField.dispatchEvent(new Event('input', { bubbles: true }));
    await raf();
    has('降级：字段输入即同步实例', mdInst.getContent(), '改过一版');

    mdInst.setContent('# 回写内容');
    await raf();
    eq('降级：setContent 写回表单字段', mdField.value, '# 回写内容');

    window.WiseEditor.destroy('editor_md_fb');
    await raf();
    record('降级：Markdown destroy 清空容器', mdBox.innerHTML === '');
    record('降级：destroy 后表单字段仍在且被隐藏（不丢提交值）',
      !!document.getElementById('editor_md_fb_textarea')
      && getComputedStyle(document.getElementById('editor_md_fb_textarea')).display === 'none',
      getComputedStyle(document.getElementById('editor_md_fb_textarea')).display);
  }

  window.WiseEditor.destroy('editor_content');
  await raf();
  record('降级 destroy 清空容器', container.innerHTML === '');
}

async function main() {
  try { await run(); }
  catch (err) { record('验证脚本异常', false, (err && err.stack) || String(err)); }
  var failed = results.filter(function (r) { return !r.ok; });
  document.getElementById('result').textContent = JSON.stringify({
    total: results.length, passed: results.length - failed.length, failed: failed.length,
    results: results, logs: logs
  });
  document.title = failed.length === 0 ? 'VERIFY_OK' : 'VERIFY_FAIL';
}
main();
`;

/**
 * Markdown 用例的 runner：三视图 + 同源预览 + 表单同步 + 往返保真
 *
 * 这一套断言锁住的是「Markdown 字段的值始终是 Markdown 纯文本」：
 * 只要哪一步把 HTML 写回字段（例如误用了 setHTML、或降级到 contenteditable），
 * 下面「表单字段是 Markdown」与「往返保真」两条就会红。
 */
const RUNNER_MARKDOWN = String.raw`
var results = [], warns = [], infos = [];
(function () {
  var orig = console.warn;
  console.warn = function () {
    warns.push([].slice.call(arguments).map(String).join(' ').slice(0, 200));
    orig.apply(console, arguments);
  };
  // 示例页没有框架内核（无 window.Wise.ui.toast），有损粘贴提示会落到 console.info；
  // 抓下来才能断言"提示确实发了"，否则那条链路可以是死代码而用例全绿
  var origInfo = console.info;
  console.info = function () {
    infos.push([].slice.call(arguments).map(String).join(' ').slice(0, 200));
    origInfo.apply(console, arguments);
  };
})();
function record(name, ok, detail) {
  results.push({ name: name, ok: !!ok, detail: detail === undefined ? null : String(detail).slice(0, 300) });
}
function eq(n, a, b) { record(n, a === b, 'actual=' + JSON.stringify(a) + ' expected=' + JSON.stringify(b)); }
function has(n, hay, needle) { var s = String(hay || ''); record(n, s.indexOf(needle) > -1, s.slice(0, 220)); }
function raf() { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function waitFor(fn, timeout) {
  var t = timeout || 10000, start = performance.now();
  return (function loop() {
    if (performance.now() - start > t) return Promise.resolve(null);
    var v = null;
    try { v = fn(); } catch (e) { v = null; }
    if (v) return Promise.resolve(v);
    return sleep(50).then(loop);
  })();
}

async function run() {
  var container = document.getElementById('editor_md');
  var field = document.getElementById('editor_md_textarea');
  var inst = await waitFor(function () { return window.WiseEditor && window.WiseEditor.get('editor_md'); });
  if (!inst) { record('Markdown 编辑器实例创建成功', false, '超时'); return; }
  record('Markdown 编辑器实例创建成功', true);

  eq('engine = tiptap', inst.engine, 'tiptap');
  eq('format = markdown', inst.format, 'markdown');
  eq('getFormat() = markdown', inst.getFormat(), 'markdown');
  eq('适配器暴露 bundle 版本', inst.version, '3.31.4');

  // ── 初值：Markdown → 文档树 ────────────────────────────────
  var md = inst.getContent();
  has('初值按 Markdown 解析为标题', md, '## 已有标题');
  has('初值保留行内加粗意图', md, '加粗');
  has('初值保留列表项', md, '- 列表 A');

  var pane = container.querySelector('.wiseeditor-pane');
  var pm = pane && pane.querySelector('.ProseMirror');
  record('ProseMirror 已挂载', !!pm, pm ? pm.className : 'missing');
  record('标题渲染为 <h2>', !!(pm && pm.querySelector('h2')), pm ? pm.innerHTML.slice(0, 160) : '');
  record('列表渲染为 <ul><li>', !!(pm && pm.querySelector('ul li')));
  record('加粗渲染为 <strong>', !!(pm && pm.querySelector('strong')));
  has('初始值同步回表单 textarea', field.value, '## 已有标题');

  // ── 源码视图 + 同源预览 ────────────────────────────────────
  inst.setView('source');
  await raf();

  var source = container.querySelector('.wiseeditor-source');
  var preview = container.querySelector('.wiseeditor-preview');
  record('源码视图：textarea 可见', !!source && !source.hidden);
  record('源码视图：编辑器面板隐藏', !!pane && pane.hidden);
  record('split 视图：预览面板可见', !!preview && !preview.hidden);
  has('源码视图：textarea 内容是 Markdown', source && source.value, '## 已有标题');

  var ppm = preview && preview.querySelector('.ProseMirror');
  record('预览由同引擎实例渲染', !!ppm, ppm ? ppm.className : 'missing');
  record('预览渲染出标题', !!(ppm && ppm.querySelector('h2')), ppm ? ppm.innerHTML.slice(0, 160) : '');
  record('预览渲染出列表', !!(ppm && ppm.querySelector('ul li')));
  record('预览实例只读', !!ppm && ppm.getAttribute('contenteditable') === 'false',
    ppm ? ppm.getAttribute('contenteditable') : 'missing');

  // ── 亮/暗主题：预览框必须跟着切换（回归：暗色下预览栏是一块白）──
  // 示例页没有 bootstrap.css，--bs-* 变量全是 undefined，
  // 所以这里同时验"变量缺失时仍能跟随 data-bs-theme"
  var themeRoot = document.documentElement;
  var prevLightBg = getComputedStyle(preview).backgroundColor;
  eq('亮色：预览框底色 = 浅灰', prevLightBg, 'rgb(248, 249, 250)');

  themeRoot.setAttribute('data-bs-theme', 'dark');
  await raf();
  var prevDarkBg = getComputedStyle(preview).backgroundColor;
  var prevDarkFg = getComputedStyle(ppm).color;
  record('暗色：预览框底色随之改变（不再是浅灰）', prevDarkBg !== prevLightBg,
    'light=' + prevLightBg + ' dark=' + prevDarkBg);
  record('暗色：预览正文转为浅色', prevDarkFg.indexOf('255, 255, 255') > -1, prevDarkFg);

  themeRoot.setAttribute('data-bs-theme', 'light');
  await raf();
  eq('切回亮色：预览框底色复原', getComputedStyle(preview).backgroundColor, prevLightBg);

  // ── 引擎 URL 版本化（回归：升级后浏览器继续跑旧 bundle）──────
  var vendor = window.__WS_EDITOR_VENDOR__ || {};
  record('引擎 bundle 以内容哈希加载',
    /tiptap\.bundle\.js\?v=[0-9a-f]{12}/.test(String(vendor.url || '')), vendor.url || 'missing');

  // 清单里的 hash 必须与页面实际请求的一致（否则等于没版本化）
  var manifest = null;
  try {
    var mfRes = await fetch('/statics/plugins/wiseeditor/js/vendor/tiptap.bundle.manifest.json', { cache: 'no-cache' });
    manifest = await mfRes.json();
  } catch (e) {
    manifest = null;
  }
  record('版本清单可读', !!manifest && !!manifest.hash, manifest ? manifest.hash : 'missing');
  record('加载 URL 的版本号 = 清单哈希',
    !!manifest && String(vendor.url || '').indexOf('?v=' + manifest.hash) > -1,
    'url=' + vendor.url + ' manifest=' + (manifest && manifest.hash));

  // ── headless 渲染（前台预览 / ws-markdown 引擎优先路径要用）────
  record('bundle 暴露 renderMarkdown', typeof window.TipTap.renderMarkdown === 'function');
  if (window.TipTap && typeof window.TipTap.renderMarkdown === 'function') {
    var hHtml = window.TipTap.renderMarkdown('## 无题\n\n| A | B |\n| --- | --- |\n| 1 | 2 |');
    record('headless：渲染出 h2', hHtml.indexOf('<h2') > -1, hHtml.slice(0, 160));
    record('headless：渲染出表格', hHtml.indexOf('<table') > -1, hHtml.slice(0, 160));
    record('headless：不留 Markdown 语法', hHtml.indexOf('## ') === -1, hHtml.slice(0, 160));
    eq('headless：空输入返回空串', window.TipTap.renderMarkdown(''), '');
  }

  // ── 源码视图下工具栏改文本（不是隐藏的文档树）──────────────
  var boldBtn = container.querySelector('.wiseeditor-toolbar button[data-key="bold"]');
  record('工具栏有加粗按钮', !!boldBtn);
  if (boldBtn && source) {
    source.focus();
    source.setSelectionRange(source.value.length, source.value.length);
    // 用编辑命令写入而不是直接改 .value：后者会**清空浏览器的撤销栈**，
    // 等于先把要测的东西破坏掉（这也正是本用例要防的那个坑）
    document.execCommand('insertText', false, '\n\n待加粗的词');
    await raf();

    source.setSelectionRange(source.value.length - 5, source.value.length);
    boldBtn.click();
    await raf();

    has('源码视图：加粗按钮包裹选区', source.value, '**待加粗的词**');
    has('源码视图：表单字段跟随源码', field.value, '**待加粗的词**');

    // ── 撤销 / 重做（回归：工具栏操作必须进浏览器撤销栈）──────────
    // 曾经的 bug：工具栏用 setRangeText 改文本，不进撤销栈，于是
    // "手打能撤销、点了加粗就再也撤不回去"。
    var undoBtn = container.querySelector('.wiseeditor-toolbar button[data-key="undo"]');
    var redoBtn = container.querySelector('.wiseeditor-toolbar button[data-key="redo"]');
    record('工具栏有撤销/重做按钮', !!undoBtn && !!redoBtn);
    if (undoBtn && redoBtn) {
      undoBtn.click();
      await raf();
      // 注意用完整片段匹配：夹具正文本身就有 **加粗**，不能只看有没有 **
      record('源码视图：撤销能回退工具栏操作',
        source.value.indexOf('**待加粗的词**') === -1, source.value.slice(-40));
      has('源码视图：撤销后正文还在', source.value, '待加粗的词');

      redoBtn.click();
      await raf();
      has('源码视图：重做恢复加粗标记', source.value, '**待加粗的词**');
    }
  }

  // ── 分栏滚动同步 ────────────────────────────────────────────
  // 造一段够长的内容把两栏撑到可滚动，验完再恢复原文（后面的断言依赖它）
  var savedMd = source.value;
  var longMd = '';
  for (var li = 0; li < 40; li += 1) {
    longMd += '## 段落 ' + li + '\n\n这是第 ' + li + ' 段正文，用来把内容撑到可以滚动。\n\n';
  }
  source.value = longMd;
  source.dispatchEvent(new Event('input', { bubbles: true }));
  await sleep(200);

  var pScroll = preview.querySelector('.ProseMirror');
  record('分栏：预览已撑满可滚动',
    !!pScroll && pScroll.scrollHeight > pScroll.clientHeight + 20,
    pScroll ? (pScroll.scrollHeight + ' / ' + pScroll.clientHeight) : 'missing');

  if (pScroll && pScroll.scrollHeight > pScroll.clientHeight + 20) {
    var srcMax = Math.max(1, source.scrollHeight - source.clientHeight);

    // 源码 → 预览
    source.scrollTop = Math.round(srcMax * 0.6);
    source.dispatchEvent(new Event('scroll'));
    await sleep(150);
    var pMax = Math.max(1, pScroll.scrollHeight - pScroll.clientHeight);
    var pRatio = pScroll.scrollTop / pMax;
    record('分栏：滚动源码 → 预览跟随（比例≈0.6）', pRatio > 0.35 && pRatio < 0.85,
      'previewRatio=' + pRatio.toFixed(2) + ' top=' + pScroll.scrollTop);

    // 预览 → 源码（反向，且不能被锁死的方向吞掉）
    source.scrollTop = 0;
    source.dispatchEvent(new Event('scroll'));
    await sleep(150);
    pScroll.scrollTop = Math.round(pMax * 0.25);
    pScroll.dispatchEvent(new Event('scroll'));
    await sleep(150);
    var sRatio = source.scrollTop / srcMax;
    record('分栏：滚动预览 → 源码跟随（比例≈0.25）', sRatio > 0.1 && sRatio < 0.5,
      'sourceRatio=' + sRatio.toFixed(2) + ' top=' + source.scrollTop);
  }

  // 恢复原文并等预览重渲染，后续断言还要用
  source.value = savedMd;
  source.dispatchEvent(new Event('input', { bubbles: true }));
  await sleep(200);

  await sleep(120);
  record('预览随源码刷新（同源）', !!(ppm && ppm.textContent.indexOf('待加粗的词') > -1),
    ppm ? ppm.textContent.slice(0, 160) : 'missing');

  // ── 切回编辑器 ─────────────────────────────────────────────
  inst.setView('wysiwyg');
  await raf();

  record('切回编辑器：面板可见', !!pane && !pane.hidden);
  record('切回编辑器：源码隐藏', !!source && source.hidden);
  record('切回编辑器：预览隐藏', !!preview && preview.hidden);
  record('切回编辑器：源码内容进入文档',
    !!(pm && pm.textContent.indexOf('待加粗的词') > -1),
    pm ? pm.textContent.slice(0, 160) : 'missing');

  // ── 所见即所得编辑：输出必须仍是 Markdown ────────────────────
  // 这是换引擎的核心价值：用户在富文本界面点"加粗"，落库的是 **…**，
  // 而不是 <strong>…</strong>。这一条一旦红，说明字段已经变成 HTML。
  inst.setContent('普通段落');
  await raf();

  // 引擎本体从 getInstance() 取（对外实例只暴露契约方法，不暴露 editor）
  var rawInst = typeof inst.getInstance === 'function' ? inst.getInstance() : null;
  var rawEditor = rawInst && rawInst.editor ? rawInst.editor : null;
  record('可通过 getInstance() 取到引擎本体', !!rawEditor);

  if (rawEditor) {
    rawEditor.chain().focus().selectAll().toggleBold().run();
    await raf();

    var boldMd = inst.getContent();
    has('所见即所得：加粗输出 **…**', boldMd, '**普通段落**');
    record('所见即所得：输出里没有 HTML 标签',
      boldMd.indexOf('<strong') === -1, boldMd.slice(0, 160));
    has('所见即所得：表单字段同步为 Markdown', field.value, '**普通段落**');

    // 编辑器视图的撤销走引擎自己的 history，与源码视图不是一条路径，单独验
    var wUndo = container.querySelector('.wiseeditor-toolbar button[data-key="undo"]');
    var wRedo = container.querySelector('.wiseeditor-toolbar button[data-key="redo"]');
    record('编辑器视图：工具栏有撤销/重做按钮', !!wUndo && !!wRedo);
    if (wUndo && wRedo) {
      wUndo.click();
      await raf();
      record('编辑器视图：撤销回退加粗',
        inst.getContent().indexOf('**普通段落**') === -1, inst.getContent().slice(0, 60));
      has('编辑器视图：撤销后正文还在', inst.getContent(), '普通段落');

      wRedo.click();
      await raf();
      has('编辑器视图：重做恢复加粗', inst.getContent(), '**普通段落**');
    }
  }

  // ── 往返保真：Markdown → 文档 → Markdown ────────────────────
  inst.setContent('| 列 A | 列 B |\n| --- | --- |\n| 1 | 2 |');
  await raf();
  var tableMd = inst.getContent();
  has('表格往返保留表头', tableMd, '| 列 A | 列 B |');
  record('表格往返没有变成 HTML 表格',
    tableMd.toLowerCase().indexOf('<table') === -1, tableMd.slice(0, 200));

  // 列对齐：GFM 的 |:---:| 与 tableCell.attrs.align 互转，两种格式都能存
  // （注意：runner 是 String.raw 模板，注释里不能出现反引号）
  inst.setContent('| 左 | 中 | 右 |\n|:---|:---:|---:|\n| 1 | 2 | 3 |');
  await raf();
  var alignMd = inst.getContent();
  has('表格列对齐：左对齐往返保留', alignMd, ':---');
  has('表格列对齐：居中往返保留', alignMd, ':---:');
  has('表格列对齐：右对齐往返保留', alignMd, '---:');
  record('表格列对齐：Markdown 里没有 HTML style',
    alignMd.indexOf('text-align') === -1, alignMd.slice(0, 200));

  // 段落对齐 / 字体 / 字号 / 颜色 / 高亮在 Markdown 里无法表达：
  // 按钮必须不渲染（而不是"点了没效果、保存后消失"）
  record('Markdown 工具栏不渲染段落对齐按钮',
    !container.querySelector('.wiseeditor-toolbar button[data-key^="align"]'));
  record('Markdown 工具栏不渲染字体/字号/颜色/高亮',
    !container.querySelector('.wiseeditor-toolbar button[data-key="fontFamily"]')
    && !container.querySelector('.wiseeditor-toolbar button[data-key="fontSize"]')
    && !container.querySelector('.wiseeditor-toolbar button[data-key="textColor"]')
    && !container.querySelector('.wiseeditor-toolbar button[data-key="highlight"]'));
  // 段落格式下拉两线都要（Markdown h1~h6，富文本 h2~h4）
  record('Markdown 工具栏保留段落格式下拉',
    !!container.querySelector('.wiseeditor-toolbar button[data-key="heading"]'));

  // ── 阶段 3：大纲 ────────────────────────────────────────────
  inst.setContent('## 第一章\n\n正文\n\n### 1.1 小节\n\n正文\n\n## 第二章');
  await raf();

  var outlineBtn = container.querySelector('.wiseeditor-toolbar button[data-key="outline"]');
  record('工具栏有大纲按钮', !!outlineBtn);
  if (outlineBtn) {
    outlineBtn.click();
    await raf();

    var outline = container.querySelector('.wiseeditor-outline');
    record('大纲面板打开', !!outline && !outline.hidden);
    record('a11y：大纲按钮标出展开态（aria-expanded）',
      outlineBtn.getAttribute('aria-expanded') === 'true',
      outlineBtn.getAttribute('aria-expanded'));
    record('a11y：大纲面板可被读屏识别（navigation + label）',
      outline.getAttribute('role') === 'navigation' && !!outline.getAttribute('aria-label'),
      outline.getAttribute('aria-label'));
    var items = outline ? outline.querySelectorAll('.wiseeditor-outline__item') : [];
    eq('大纲条目数 = 3', items.length, 3);
    has('大纲条目文本正确', items[0] && items[0].textContent, '第一章');
    record('大纲按层级缩进（h3 → --lvl=1）',
      !!items[1] && getComputedStyle(items[1]).getPropertyValue('--lvl').trim() === '1',
      items[1] ? getComputedStyle(items[1]).getPropertyValue('--lvl') : 'missing');

    var beforePos = rawEditor.state.selection.from;
    items[2].click();
    await raf();
    record('大纲点击后光标跳到对应标题',
      rawEditor.state.selection.from !== beforePos,
      'from=' + rawEditor.state.selection.from + ' before=' + beforePos);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await raf();
    record('Esc 关闭大纲', outline.hidden === true);
  }

  // ── 阶段 3：气泡菜单 ────────────────────────────────────────
  if (rawEditor) {
    inst.setContent('气泡菜单测试文本');
    await raf();
    rawEditor.chain().focus().setTextSelection({ from: 1, to: 5 }).run();
    await raf();
    await raf();

    var bubble = document.querySelector('.wiseeditor-bubble');
    record('选中文字后气泡出现', !!bubble && !bubble.hidden,
      bubble ? ('hidden=' + bubble.hidden) : 'missing');

    var bubbleBold = bubble && bubble.querySelector('button[data-key="bold"]');
    record('气泡含加粗按钮', !!bubbleBold);
    record('a11y：气泡按钮带 aria-label', !!bubbleBold && !!bubbleBold.getAttribute('aria-label'),
      bubbleBold ? bubbleBold.getAttribute('aria-label') : 'missing');
    if (bubbleBold) {
      bubbleBold.click();
      await raf();
      has('气泡加粗生效（输出 **…**）', inst.getContent(), '**气泡菜单**');
      record('点气泡按钮不丢选区', rawEditor.state.selection.empty === false,
        'empty=' + rawEditor.state.selection.empty);
      record('a11y：气泡按钮激活态写入 aria-pressed',
        bubbleBold.getAttribute('aria-pressed') === 'true',
        bubbleBold.getAttribute('aria-pressed'));
    }

    // 选区收成光标 → 气泡消失
    rawEditor.chain().focus().setTextSelection(1).run();
    await raf();
    await raf();
    record('取消选区后气泡消失', !bubble || bubble.hidden === true,
      bubble ? ('hidden=' + bubble.hidden) : 'missing');
  }

  // ── 附件插入：Markdown 字段必须落成 [文件名](地址) ────────────
  var mdAttachBtn = container.querySelector('.wiseeditor-toolbar button[data-key="attach"]');
  record('Markdown 工具栏有附件按钮', !!mdAttachBtn);

  var mdFiles = container.querySelectorAll('input[type="file"]');
  record('Markdown 附件：容器内有两个文件框（图片 / 附件）',
    mdFiles.length >= 2, 'count=' + mdFiles.length);
  if (mdAttachBtn && mdFiles[1]) {
    var mdPrevXhr = window.XMLHttpRequest;
    var mdReq = null;

    try {
      function MdXhr() {
        this.upload = {};
        this.status = 0;
        this.responseText = '';
        this.headers = {};
      }
      MdXhr.prototype.open = function () {};
      MdXhr.prototype.setRequestHeader = function (k, v) { this.headers[k] = v; };
      MdXhr.prototype.send = function () { mdReq = this; };
      MdXhr.prototype.abort = function () { if (this.onabort) this.onabort(); };
      window.XMLHttpRequest = MdXhr;

      inst.setContent('');
      await raf();
      rawEditor.chain().focus().run();

      var mdFile = new File([new Uint8Array(8)], '规格书.pdf', { type: 'application/pdf' });
      // 用类数组直接覆盖 files：DataTransfer 的 files 是 live FileList，
      // 赋值 + 下面的 value='' 会让它变成空表，读不到文件（表现为"change 触发了但不上传"）。
      // 适配器只读 files[0] / files.length，类数组足够，且不受 value 清空影响。
      Object.defineProperty(mdFiles[1], 'files', {
        value: { 0: mdFile, length: 1, item: function (i) { return i === 0 ? mdFile : null; } },
        configurable: true,
      });
      mdFiles[1].dispatchEvent(new Event('change', { bubbles: true }));
      await raf();

      eq('Markdown 附件：能力位头是 attach',
        mdReq && mdReq.headers['X-Wise-Editor-Capability'], 'attach');

      mdReq.status = 200;
      mdReq.responseText = JSON.stringify({
        code: 0,
        data: { id: 10, url: 'https://cdn.test/files/spec.pdf', size: 8 },
      });
      mdReq.onload();
      await raf();
      await raf();

      var mdAttach = inst.getContent();
      has('Markdown 附件：落成 [文件名](地址)', mdAttach, '[规格书.pdf](https://cdn.test/files/spec.pdf)');
      record('Markdown 附件：不是 HTML 标签',
        mdAttach.indexOf('<a ') === -1, mdAttach.slice(0, 160));
    } finally {
      window.XMLHttpRequest = mdPrevXhr;
    }
  }

  // ── 查找替换（与内容格式无关，Markdown 字段同样可用）──────────
  inst.setContent('## 搜索章节\n\nalpha 一段 alpha');
  await raf();

  container.dispatchEvent(new KeyboardEvent('keydown', {
    key: 'f', ctrlKey: true, bubbles: true, cancelable: true,
  }));
  await raf();

  var mdFind = container.querySelector('.wiseeditor-find');
  record('Markdown：Ctrl+F 打开查找面板', !!mdFind && mdFind.hidden === false);

  if (mdFind) {
    var mdQuery = mdFind.querySelector('[data-role="query"]');
    mdQuery.value = 'alpha';
    mdQuery.dispatchEvent(new Event('input', { bubbles: true }));
    await raf();
    eq('Markdown：命中 2 处', mdFind.querySelector('[data-role="count"]').textContent, '1/2');

    mdFind.querySelector('[data-role="replace"]').value = 'beta';
    mdFind.querySelector('[data-role="replace-all"]').click();
    await raf();

    var mdReplaced = inst.getContent();
    has('Markdown：全部替换生效', mdReplaced, 'beta');
    record('Markdown：替换后仍是 Markdown（没有 HTML 标签）',
      mdReplaced.indexOf('<p') === -1, mdReplaced.slice(0, 160));

    mdFind.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await raf();
    record('Markdown：Esc 关闭查找面板', mdFind.hidden === true);
  }

  // 反引号不能直接写进 String.raw 模板（会提前结束模板），拼出来即可
  var fence = '\u0060\u0060\u0060';
  inst.setContent(fence + 'js\nconst a = 1;\n' + fence);
  await raf();
  has('围栏代码块往返保留语言', inst.getContent(), fence + 'js');
  record('围栏代码块往返没有变成 <pre>',
    inst.getContent().indexOf('<pre') === -1, inst.getContent().slice(0, 160));

  // ── 阶段 3：代码高亮（CodeBlockLowlight）────────────────────
  // 断言的是"真的切成了 token"，不是"代码块还在" —— 后者不装高亮也是绿的
  var hlToken = container.querySelector(
    '.ProseMirror pre .hljs-keyword, .ProseMirror pre .hljs-string, .ProseMirror pre .hljs-number');
  record('代码高亮：关键字切成 hljs token', !!hlToken,
    hlToken ? hlToken.className + '=' + hlToken.textContent : 'missing');
  record('代码高亮：代码块仍带语言标记',
    !!container.querySelector('.ProseMirror pre[data-language="js"]')
    || !!container.querySelector('.ProseMirror pre code.language-js'),
    container.querySelector('.ProseMirror pre') ? container.querySelector('.ProseMirror pre').outerHTML.slice(0, 160) : 'missing');

  // ── 阶段 3：任务列表（TaskList / TaskItem）──────────────────
  var taskBtn = container.querySelector('.wiseeditor-toolbar button[data-key="taskList"]');
  record('工具栏有任务列表按钮', !!taskBtn);
  if (taskBtn) {
    inst.setContent('第一项');
    await raf();
    taskBtn.click();
    await raf();

    has('任务列表：工具栏切换后输出 - [ ] 语法', inst.getContent(), '- [ ]');
    record('任务列表：DOM 渲染出勾选框',
      !!container.querySelector('.ProseMirror ul[data-type="taskList"] input[type="checkbox"]'),
      container.querySelector('.ProseMirror')
        ? container.querySelector('.ProseMirror').innerHTML.slice(0, 200) : 'missing');

    // 往返：勾选状态存在 attrs 里，最容易在序列化时丢
    inst.setContent('- [ ] 待办\n- [x] 完成');
    await raf();
    var backTask = inst.getContent();
    has('任务列表：往返保留未完成项', backTask, '- [ ] 待办');
    has('任务列表：往返保留已完成项', backTask, '- [x] 完成');
    record('任务列表：值里没有 HTML 标签', backTask.indexOf('<ul') === -1, backTask.slice(0, 160));
    record('任务列表：已完成项渲染为 data-checked',
      !!container.querySelector('.ProseMirror li[data-checked="true"]'));
  }

  // ── 阶段 3：粘贴富文本 → Markdown ───────────────────────────
  /**
   * 造一次合成粘贴事件
   *
   * 真实粘贴无法在自动化里触发，但 TipTap 的 handlePaste 只依赖
   * event.clipboardData，所以用 DataTransfer + ClipboardEvent 即可覆盖整条链路。
   */
  function pasteWith(el, data, mods) {
    var dt = new DataTransfer();
    Object.keys(data).forEach(function (key) { dt.setData(key, data[key]); });
    var ev;
    try {
      ev = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: dt });
    } catch (e) {
      ev = new Event('paste', { bubbles: true, cancelable: true });
      ev.clipboardData = dt;
    }

    // 修饰键必须在实例上覆盖：ClipboardEventInit 不接收 ctrlKey/shiftKey
    // （构造函数会静默忽略），直接赋值又会被只读属性挡掉。
    if (mods) {
      Object.keys(mods).forEach(function (k) {
        try {
          Object.defineProperty(ev, k, { value: mods[k], configurable: true });
        } catch (e) { /* ignore */ }
      });
    }

    el.dispatchEvent(ev);
  }

  var pmDom = container.querySelector('.ProseMirror');
  if (pmDom) {
    inst.setContent('');
    await raf();
    pasteWith(pmDom, {
      'text/html': '<meta charset="utf-8"><style>p{color:red}</style>'
        + '<h2>粘来的标题</h2>'
        + '<p><b>粗体</b>与<font color="red">彩色字</font>'
        + '<span style="font-size:20px">大字号</span></p>'
        + '<ul><li>列表一</li></ul>'
        + '<p><a href="https://example.com/a">链接</a></p>'
        + '<script>alert(1)</script>',
      'text/plain': '粘来的标题 粗体与彩色字大字号 列表一 链接',
    });
    await raf();

    var pastedMd = inst.getContent();
    has('粘贴富文本：标题转成 ## ', pastedMd, '## 粘来的标题');
    has('粘贴富文本：加粗转成 **…**', pastedMd, '**粗体**');
    has('粘贴富文本：列表转成 - ', pastedMd, '- 列表一');
    has('粘贴富文本：链接转成 [文字](地址)', pastedMd, '[链接](https://example.com/a)');
    record('粘贴富文本：<style>/<script> 内容没混进正文',
      pastedMd.indexOf('color:red') === -1 && pastedMd.indexOf('alert(1)') === -1,
      pastedMd.slice(0, 200));
    record('粘贴富文本：不支持的样式被剥掉（颜色/字号）',
      pastedMd.indexOf('font-size') === -1 && pastedMd.indexOf('color=') === -1,
      pastedMd.slice(0, 200));

    // 危险协议：链接整个剥掉，只留文字
    inst.setContent('');
    await raf();
    pasteWith(pmDom, { 'text/html': '<p><a href="javascript:alert(2)">危险链接</a></p>' });
    await raf();
    var dangerMd = inst.getContent();
    record('粘贴富文本：javascript: 协议被丢弃',
      dangerMd.indexOf('javascript') === -1, dangerMd.slice(0, 160));
    has('粘贴富文本：危险链接的正文仍保留', dangerMd, '危险链接');

    // 有损提示：只有我们的 sanitize 路径会发这条（证明不是走了 PM 默认粘贴）
    record('粘贴富文本：有损时给出精简提示',
      infos.some(function (s) { return s.indexOf('粘贴内容已按编辑器支持的格式精简') > -1; }),
      infos.join(' | ').slice(0, 240));

    // 纯文本粘贴：绝不当 HTML 解析（否则代码样例里的标签会被吃掉）
    inst.setContent('');
    await raf();
    pasteWith(pmDom, { 'text/plain': '<div>这不是 HTML</div>' });
    await raf();
    has('粘贴纯文本：原样进入，不被当 HTML 解析',
      pmDom.textContent, '<div>这不是 HTML</div>');

    // ── Ctrl+Shift+V：粘贴为纯文本（只要 text/plain，不解析 HTML）──
    inst.setContent('');
    await raf();
    pasteWith(pmDom, {
      'text/html': '<p><b>粗体</b>与<font color="red">彩色字</font></p>',
      'text/plain': 'const a = 1 < 2;\n第二行\n\n第二段',
    }, { ctrlKey: true, shiftKey: true });
    await raf();

    var plainText = pmDom.textContent;
    record('纯文本粘贴：标签按原文进入（没被当 HTML 解析）',
      plainText.indexOf('const a = 1 < 2;') > -1, plainText.slice(0, 120));
    record('纯文本粘贴：没有加粗/颜色等格式',
      inst.getContent().indexOf('<strong') === -1
      && inst.getContent().indexOf('color:') === -1,
      inst.getContent().slice(0, 160));
    // Markdown 字段里"两段"表现为中间一个空行，不能数 <p>
    record('纯文本粘贴：空行分出两段',
      /\n\s*\n/.test(inst.getContent()),
      JSON.stringify(inst.getContent()));
  }

  // ── 图片描述（alt）编辑：双击图片打开弹层 ──────────────────
  // 这里在 Markdown 字段里验：alt 必须往返成 ![描述](地址)，比 HTML 属性更严格
  inst.setContent('![旧描述](https://cdn.test/pic.png)');
  await raf();

  var imgEl = container.querySelector('.ProseMirror img');
  record('图片已渲染', !!imgEl);

  if (imgEl) {
    imgEl.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    await raf();

    var altPop = document.querySelector('.wiseeditor-popover');
    record('双击图片打开描述弹层', !!altPop);

    if (altPop) {
      var altInput = altPop.querySelector('[data-role="alt"]');
      record('描述弹层有 alt 输入框', !!altInput);
      eq('描述弹层预填当前 alt', altInput && altInput.value, '旧描述');

      if (altInput) {
        altInput.value = '后台登录页截图';
        altPop.querySelector('[data-act="ok"]').click();
        await raf();

        has('保存后 alt 更新（Markdown 图片语法）',
          inst.getContent(), '![后台登录页截图](https://cdn.test/pic.png)');
        record('保存后弹层关闭', !document.querySelector('.wiseeditor-popover'));
      }
    }
  }

  inst.setContent('');
  await raf();
  eq('清空后 getContent() 为空', inst.getContent(), '');
  eq('清空后表单字段为空', field.value, '');

  // ── 主题色跟随（回归：颜色写死成项目紫 #534ab7）──────────────
  // 断言的是"改 --bs-primary 后编辑器真的跟着变"，不是"用了 var(--bs-primary)"
  inst.setContent('- [ ] 勾选项\n\n> 引用');
  await raf();
  var cbBox = container.querySelector('.ProseMirror input[type="checkbox"]');
  var quoteEl = container.querySelector('.ProseMirror blockquote');

  eq('主题色：勾选框默认用 Bootstrap 主色',
    cbBox && getComputedStyle(cbBox).accentColor, 'rgb(13, 110, 253)');

  document.documentElement.style.setProperty('--bs-primary', '#ff0000');
  document.documentElement.style.setProperty('--bs-primary-rgb', '255, 0, 0');
  await raf();
  eq('主题色：改主色后勾选框跟随',
    cbBox && getComputedStyle(cbBox).accentColor, 'rgb(255, 0, 0)');
  eq('主题色：引用左边框跟随',
    quoteEl && getComputedStyle(quoteEl).borderLeftColor, 'rgb(255, 0, 0)');

  document.documentElement.style.removeProperty('--bs-primary');
  document.documentElement.style.removeProperty('--bs-primary-rgb');

  // ── 销毁 ──────────────────────────────────────────────────
  window.WiseEditor.destroy('editor_md');
  await raf();
  record('destroy 清空容器', container.innerHTML === '');
  record('destroy 后 get 返回 undefined', window.WiseEditor.get('editor_md') === undefined);
  record('无未注册工具栏按钮告警',
    !warns.some(function (w) { return w.indexOf('未注册的工具栏按钮') > -1; }),
    warns.join(' | ').slice(0, 240));
}

async function main() {
  try { await run(); }
  catch (err) { record('Markdown 验证脚本异常', false, (err && err.stack) || String(err)); }
  var failed = results.filter(function (r) { return !r.ok; });
  document.getElementById('result').textContent = JSON.stringify({
    total: results.length, passed: results.length - failed.length, failed: failed.length,
    results: results
  });
  document.title = failed.length === 0 ? 'VERIFY_OK' : 'VERIFY_FAIL';
}
main();
`;

/**
 * 用例 3 的 runner：跑在真实示例页上（页面本身不含 runner，连上 CDP 后动态注入）。
 *
 * 一套 runner 覆盖三种页面，靠 window.__VERIFY 传参：
 *   depth:  'deep' 时额外跑命令/表格/源码切换等交互断言（只对富文本示例页有意义）
 *   tipTap: 该页是否应该加载 TipTap bundle —— false 的页面反过来断言它**没**被加载，
 *           以此锁住两个驱动的引擎是按键加载的（不该为 Markdown 页白拉 432 KB）
 */
const RUNNER_PUBLISH = String.raw`
var results = [];
function record(name, ok, detail) {
  results.push({ name: name, ok: !!ok, detail: detail === undefined ? null : String(detail).slice(0, 260) });
}
function eq(n, a, b) { record(n, a === b, 'actual=' + JSON.stringify(a) + ' expected=' + JSON.stringify(b)); }
function has(n, hay, needle) { var s = String(hay || ''); record(n, s.indexOf(needle) > -1, s.slice(0, 200)); }
function raf() { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function waitFor(fn, timeout) {
  var t = timeout || 10000, start = performance.now();
  return (function loop() {
    if (performance.now() - start > t) return Promise.resolve(null);
    var v = null;
    try { v = fn(); } catch (e) { v = null; }
    if (v) return Promise.resolve(v);
    return sleep(50).then(loop);
  })();
}

async function run() {
  var cfg = window.__VERIFY || {};

  function getter(id) {
    return function () { return window.WiseEditor && window.WiseEditor.get(id); };
  }

  // 页面上所有 [data-editor="wiseeditor"] 容器都必须挂上实例。
  // 注意顺序：本 runner 是连上 CDP 后动态挂进去的，执行时机可能早于
  // 入口 bundle 的 DOMContentLoaded boot，因此一律先 waitFor 再断言。
  var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-editor="wiseeditor"]'));
  record('页面存在编辑器容器', nodes.length > 0, '数量=' + nodes.length);

  var first = null;
  for (var i = 0; i < nodes.length; i++) {
    var id = nodes[i].id;
    var ins = await waitFor(getter(id), 12000);
    record('容器 #' + id + ' 挂载实例', !!ins, ins ? 'ok' : '超时');
    if (ins) {
      record('容器 #' + id + ' 已渲染内容', nodes[i].childElementCount > 0, '子节点=' + nodes[i].childElementCount);
      if (!first) first = ins;
    }
  }

  record('模块入口已执行（window.WiseEditor 存在）', !!window.WiseEditor);

  if (cfg.tipTap) {
    eq('window.TipTap 已挂载', typeof window.TipTap, 'object');
    eq('TipTap 版本 = 3.31.4', window.TipTap && window.TipTap.version, '3.31.4');
    eq('engine = tiptap', first && first.engine, 'tiptap');
    eq('适配器暴露 bundle 版本', first && first.version, '3.31.4');
  } else {
    // 反向断言：不需要引擎的页面不该为 TipTap 白拉 ~500 KB —— 锁住引擎是懒加载的
    record('未用 TipTap 的页面不加载其 bundle', !window.TipTap);
  }

  if (!first) { record('示例页至少有一个可用实例', false, '全部超时'); return; }

  // Markdown 分栏预览：右栏必须是渲染后的 HTML，不能是 Markdown 原文。
  // 这一条刻意放在 deep 早退之前 —— 有预览栏的是 markdown.html（浅层页），
  // 而"预览退化成纯文本"正是历史上漏掉的那个静默故障。
  //
  // [hidden] 过滤不能省：富文本页面也会创建（隐藏的）预览容器占位，
  // 直接取第一个会拿到空壳，断言变成"空 innerHTML 是不是 HTML"。
  var mdPreview = document.querySelector('.wiseeditor-preview:not([hidden])');
  if (mdPreview) {
    var html = mdPreview.innerHTML || '';
    record('Markdown 预览栏渲染为 HTML（非原文）', /<(h1|h2|h3|p|strong|ul|blockquote)[\s>]/.test(html),
      html.slice(0, 140));
    record('Markdown 预览栏未残留 Markdown 语法', html.indexOf('# ') === -1, html.slice(0, 140));
  }

  if (cfg.depth !== 'deep') return;   // 浅层页到此为止，强度已够冒烟

  var inst = first;
  var container = document.getElementById('editor_content');
  var pm = container.querySelector('.wiseeditor-pane .ProseMirror');
  record('ProseMirror 已挂载', !!pm, pm ? pm.className : 'missing');
  record('ProseMirror 使用真实 view 实例', !!(inst.getInternal() && inst.getInternal().editor && inst.getInternal().editor.view));

  var hidden = document.getElementById('editor_content_input');
  has('初始内容渲染', inst.getContent(), '<strong>world</strong>');
  has('初始内容同步隐藏域', hidden.value, '<strong>world</strong>');

  var btns = container.querySelectorAll('.wiseeditor-toolbar button[data-key]');
  var pubDesc = ((inst && inst.toolbarKeys) || []).filter(function (k) { return k !== '|'; });
  eq('工具栏按钮数 = 描述符长度', btns.length, pubDesc.length);

  var roIns = window.WiseEditor.get('editor_ro');
  record('同页只读实例也挂载', !!roIns);
  if (roIns) {
    record('只读不渲染工具栏', !document.getElementById('editor_ro').querySelector('.wiseeditor-toolbar'));
    record('只读不可编辑', roIns.getInternal().editor.isEditable === false);
  }

  var ed = inst.getInternal().editor;
  ed.commands.selectAll();
  await raf();
  container.querySelector('button[data-key="bold"]').click();
  await raf();
  has('加粗命令生效', inst.getContent(), '<strong>');
  record('加粗按钮激活态', container.querySelector('button[data-key="bold"]').classList.contains('is-active'));

  inst.setContent('<p>before</p>');
  await raf();
  ed.commands.setTextSelection(1);
  inst.getInternal().run('table', { rows: 3, cols: 3, withHeaderRow: true });
  await raf();
  var t = inst.getContent();
  has('表格插入成功', t, '<table');
  has('表格输出含 colgroup', t, '<colgroup');
  var tablebar = container.querySelector('.wiseeditor-tablebar');
  // 9 个原有操作 + toggleHeaderRow + 3 个单元格对齐
  record('表格工具条按钮数 = 13', !!tablebar && tablebar.childElementCount === 13, tablebar ? tablebar.childElementCount : 'missing');

  var sourceBtn = container.querySelector('button[data-key="source"]');
  sourceBtn.click();
  await raf();
  record('源码模式：textarea 显示', container.querySelector('.wiseeditor-source').hidden === false);
  sourceBtn.click();
  await raf();

  // 收尾刻意保留一份完整内容而非 destroy —— destroy 已由用例 1 断言过，
  // 这里留下渲染结果是为了让截图能直接目视核对（工具栏 / 表格 / 链接）。
  inst.setContent('<h2>示例页</h2><p>正文 <strong>粗</strong> <em>斜</em> <u>下划</u></p>'
    + '<ul><li><p>项 A</p></li></ul>'
    + '<table><tbody><tr><td><p>单元格</p></td><td><p>B</p></td></tr></tbody></table>'
    + '<p><a href="https://example.com" rel="noopener noreferrer nofollow">链接</a></p>');
  await raf();
  has('示例页 setContent 生效', inst.getContent(), '示例页');
  has('示例页表格保留', inst.getContent(), '<table');
}

async function main() {
  try { await run(); }
  catch (err) { record('验证脚本异常', false, (err && err.stack) || String(err)); }
  var holder = document.getElementById('result');
  if (!holder) {
    holder = document.createElement('pre');
    holder.id = 'result';
    holder.style.display = 'none';
    document.body.appendChild(holder);
  }
  var failed = results.filter(function (r) { return !r.ok; });
  holder.textContent = JSON.stringify({
    total: results.length, passed: results.length - failed.length, failed: failed.length,
    results: results, logs: []
  });
  document.title = failed.length === 0 ? 'VERIFY_OK' : 'VERIFY_FAIL';
}
main();
`;

/**
 * 用例 4 的 runner：跑在**直开版**示例页上，走真实 `file://`。
 *
 * 与用例 3 的 runner 有两点不同：
 *   1. 它是 **classic 脚本**，因为 file:// 下 <script type="module"> 会被 CORS 拦掉 ——
 *      「注入的 runner 本身能不能跑起来」正好也是被测前提的一部分。
 *   2. 它不断言 404，改断言「模块脚本一个都不能有」「引擎必须是真的 TipTap」——
 *      后者若退化成 native，说明相对路径下的 vendor bundle 没加载成功。
 */
const RUNNER_STANDALONE = String.raw`
var results = [];
function record(name, ok, detail) {
  results.push({ name: name, ok: !!ok, detail: detail === undefined ? null : String(detail).slice(0, 260) });
}
function eq(n, a, b) { record(n, a === b, 'actual=' + JSON.stringify(a) + ' expected=' + JSON.stringify(b)); }
function has(n, hay, needle) { var s = String(hay || ''); record(n, s.indexOf(needle) > -1, s.slice(0, 200)); }
function raf() { return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); }); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function waitFor(fn, timeout) {
  var t = timeout || 12000, start = performance.now();
  return (function loop() {
    if (performance.now() - start > t) return Promise.resolve(null);
    var v = null;
    try { v = fn(); } catch (e) { v = null; }
    if (v) return Promise.resolve(v);
    return sleep(50).then(loop);
  })();
}

async function run() {
  // ── 前提：这真的是一次 file:// 直开 ──────────────────────────
  record('页面以 file:// 打开', location.protocol === 'file:', 'protocol=' + location.protocol);
  eq('页面无 type=module 脚本（直开的前提）', document.querySelectorAll('script[type="module"]').length, 0);

  // 资源必须是相对路径：只要还有 /statics/... 这种站点绝对路径，file:// 下必然 404
  var srcs = Array.prototype.map.call(document.scripts, function (s) { return s.getAttribute('src') || ''; });
  record('脚本一律用相对路径（无站点绝对路径）',
    srcs.every(function (s) { return s.indexOf('/statics/') !== 0; }), srcs.join(' | '));
  has('引了直开版入口 bundle', srcs.join('|'), 'dist/wiseeditor.example.js');
  // 排除本 runner 自己（它是验证时动态注入的、位于 .verify-out/ 下）
  var pageScripts = srcs.filter(function (s) { return s.indexOf('.verify-out') === -1; });
  eq('页面自带脚本数 = 2（入口 + 页面交互）', pageScripts.length, 2);
  var links = Array.prototype.map.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) { return l.getAttribute('href') || ''; });
  record('样式表一律用相对路径（无站点绝对路径）',
    links.every(function (h) { return h.indexOf('/statics/') !== 0; }), links.join(' | '));

  // ── 经典脚本链是否真的跑通 ──────────────────────────────────
  var inst = await waitFor(function () { return window.WiseEditor && window.WiseEditor.get('editor_content'); });
  if (!inst) { record('直开版入口执行成功（window.WiseEditor）', false, '超时'); return; }
  record('直开版入口执行成功（window.WiseEditor）', true);
  record('预加载的 vendor bundle 生效（window.TipTap）', typeof window.TipTap, 'object');
  eq('TipTap 版本 = 3.31.4', window.TipTap && window.TipTap.version, '3.31.4');
  eq('适配器已注册 tiptap', window.WiseEditor.adapters.has('tiptap'), true);
  // 反向断言：milkdown 别名已删除，不再注册（页面也不该再写 data-editor-type="milkdown"）
  record('适配器不再注册 milkdown', !window.WiseEditor.adapters.has('milkdown'));

  // ── 三个容器全部挂上 ────────────────────────────────────────
  var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-editor="wiseeditor"]'));
  eq('页面编辑器容器数 = 3', nodes.length, 3);
  var ids = ['editor_content', 'editor_ro', 'editor_body'];
  for (var i = 0; i < ids.length; i++) {
    var id = ids[i];
    var mounted = await waitFor(function () { return window.WiseEditor.get(id); });
    record('容器 #' + id + ' 挂载实例', !!mounted, mounted ? 'ok' : '超时');
  }

  // ── 真实引擎而非降级引擎：相对路径下的 vendor 必须被找到 ──────
  eq('富文本用真实 TipTap 引擎（非 native 降级）', inst.engine, 'tiptap');
  var container = document.getElementById('editor_content');
  record('ProseMirror 已挂载', !!container.querySelector('.wiseeditor-pane .ProseMirror'));
  var saDesc = (inst.toolbarKeys || []).filter(function (k) { return k !== '|'; });
  eq('工具栏按钮数 = 描述符长度', container.querySelectorAll('.wiseeditor-toolbar button[data-key]').length, saDesc.length);

  // ── 表单同步：改内容 → 隐藏域跟着变 ─────────────────────────
  var hidden = document.getElementById('editor_content_input');
  has('初始内容渲染', inst.getContent(), '<strong>world</strong>');
  has('初始内容同步隐藏域', hidden.value, '<strong>world</strong>');
  var ed = inst.getInternal().editor;
  ed.commands.selectAll();
  await raf();
  container.querySelector('button[data-key="bold"]').click();
  await raf();
  has('加粗命令生效', inst.getContent(), '<strong>');
  has('加粗后隐藏域同步', hidden.value, '<strong>');

  // ── 只读实例 ────────────────────────────────────────────────
  var roIns = window.WiseEditor.get('editor_ro');
  if (roIns) {
    record('只读不渲染工具栏', !document.getElementById('editor_ro').querySelector('.wiseeditor-toolbar'));
    record('只读不可编辑', roIns.getInternal().editor.isEditable === false);
  }

  // ── Markdown：源码 + 同引擎只读预览（容器声明的 view=split）──────
  var mdIns = window.WiseEditor.get('editor_body');
  if (mdIns) {
    var ta = document.getElementById('editor_body_textarea');
    var mdBox = document.getElementById('editor_body');
    // 预览是同一个引擎的只读实例（引擎不可用时不会走到这里，会露 textarea）
    var mdPreview = mdBox.querySelector('.wiseeditor-preview');
    record('Markdown 分栏预览已渲染', !!mdPreview);
    has('Markdown 字段初始值保留', ta.value, '# 标题');
    eq('Markdown 实例格式 = markdown', mdIns.format, 'markdown');
    eq('Markdown 走真实 TipTap 引擎（非 native 降级）', mdIns.engine, 'tiptap');
    record('初始内容已渲染为 HTML（非 Markdown 原文）',
      !!mdPreview && /<(h1|p|strong|ul|blockquote)[\s>]/.test(mdPreview.innerHTML), mdPreview ? mdPreview.innerHTML.slice(0, 140) : 'missing');

    mdIns.setContent('# 直开版\n\n改自 **setContent**');
    await raf();
    has('Markdown setContent 同步 textarea', ta.value, '直开版');
    has('Markdown 预览栏随 setContent 重渲染', mdPreview && mdPreview.innerHTML, '<h1>直开版</h1>');
    has('Markdown 预览栏渲染粗体', mdPreview && mdPreview.innerHTML, '<strong>setContent</strong>');
    record('Markdown 预览栏未残留 Markdown 语法', !!mdPreview && mdPreview.innerHTML.indexOf('# ') === -1,
      mdPreview ? mdPreview.innerHTML.slice(0, 140) : 'missing');
  }
}

async function main() {
  try { await run(); }
  catch (err) { record('验证脚本异常', false, (err && err.stack) || String(err)); }
  var holder = document.getElementById('result');
  if (!holder) {
    holder = document.createElement('pre');
    holder.id = 'result';
    holder.style.display = 'none';
    document.body.appendChild(holder);
  }
  var failed = results.filter(function (r) { return !r.ok; });
  holder.textContent = JSON.stringify({
    total: results.length, passed: results.length - failed.length, failed: failed.length,
    results: results, logs: []
  });
  document.title = failed.length === 0 ? 'VERIFY_OK' : 'VERIFY_FAIL';
}
main();
`;

const CASE_STANDALONE_NAME = '直开版示例页冒烟（真实 file://，零服务）';

// ============================================================================
// 静态服务
// ============================================================================

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  // 图标字体：MIME 给不对时浏览器会拒绝加载 woff2（bootstrap-icons 依赖）
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.ttf': 'font/ttf',
};

function startServer(cases) {
  const memory = new Map();
  memory.set('/__verify/main.html', cases.main.html);
  memory.set('/__verify/fallback.html', cases.fallback.html);
  memory.set('/__verify/markdown.html', cases.markdown.html);
  memory.set('/__verify/runner-main.js', RUNNER_MAIN);
  memory.set('/__verify/runner-fallback.js', RUNNER_FALLBACK);
  memory.set('/__verify/runner-markdown.js', RUNNER_MARKDOWN);
  memory.set('/statics/plugins/wiseeditor/__verify/runner-publish.js', RUNNER_PUBLISH);

  /** 记录所有未命中的请求，用于断言「示例页零 404」 */
  const misses = [];

  const server = http.createServer((req, res) => {
    const url = (req.url || '/').split('?')[0];
    if (url === '/favicon.ico') {
      // 浏览器自动请求，与应用资源无关，返回空响应且不计入 404 统计
      res.writeHead(204).end();
      return;
    }
    if (url === '/') {
      // --serve 时给人一个入口页
      res.writeHead(302, { location: EXAMPLE_URL_PREFIX + '/index.html' }).end();
      return;
    }
    if (memory.has(url)) {
      res.writeHead(200, { 'content-type': MIME[path.extname(url)] || 'text/plain; charset=utf-8' }).end(memory.get(url));
      return;
    }
    // 三种站点根：
    //   /statics/**  真实发布布局，根目录 public/          （用例 3 与 --serve）
    //   /example/**  示例页源码，根目录 plugins/wiseeditor/example（用例 3 与 --serve）
    //   其他         夹具短路径，根目录 plugins/wiseeditor/statics（用例 1/2）
    let base = STATICS;
    let rel = url;
    if (url.startsWith('/statics/')) {
      base = PUBLISHED_STATICS;
      rel = url.slice('/statics'.length);
    } else if (url === EXAMPLE_URL_PREFIX || url.startsWith(EXAMPLE_URL_PREFIX + '/')) {
      base = EXAMPLE_DIR;
      rel = url.slice(EXAMPLE_URL_PREFIX.length);
    }
    const abs = path.resolve(base, '.' + decodeURIComponent(rel));
    if (abs !== base && !abs.startsWith(base + path.sep)) {
      misses.push(url);
      res.writeHead(403).end('forbidden');
      return;
    }
    fs.readFile(abs, (err, buf) => {
      if (err) {
        misses.push(url);
        res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' }).end('404 ' + url);
        return;
      }
      res.writeHead(200, { 'content-type': MIME[path.extname(abs)] || 'application/octet-stream' }).end(buf);
    });
  });

  server.misses = misses;
  return new Promise((resolve) => server.listen(PORT, '127.0.0.1', () => resolve(server)));
}

// ============================================================================
// Chrome CDP
// ============================================================================

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function findChrome() {
  for (const p of CHROME_CANDIDATES) {
    try { if (fs.existsSync(p)) return p; } catch { /* ignore */ }
  }
  return null;
}

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.seq = 0;
    this.pending = new Map();
    this.console = [];
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) reject(new Error(JSON.stringify(msg.error)));
        else resolve(msg.result);
        return;
      }
      if (msg.method === 'Runtime.consoleAPICalled') {
        this.console.push({
          type: msg.params.type,
          text: (msg.params.args || []).map((a) => a.value ?? a.description ?? a.type).join(' ').slice(0, 240),
        });
      }
    });
  }
  send(method, params = {}) {
    const id = ++this.seq;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
  }
  async eval(expression) {
    const r = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.text);
    return r.result.value;
  }
}

/**
 * 取一个空闲端口
 *
 * 固定端口在"上一次运行残留 Chrome / 端口被占"时会直接失败
 * （`bind() EADDRINUSE`），让回归变成环境问题。交给系统分配最省事。
 *
 * @returns {Promise<number>}
 */
function findFreePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.once('error', reject);
    srv.once('listening', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
    srv.listen(0, '127.0.0.1');
  });
}

async function runCase(chromePath, slug, url, screenshot, inject) {
  const profile = path.join(OUT_DIR, 'chrome-profile-' + slug);
  // 某些环境对「目录内文件数 > 500 的批量删除」有守卫，清理失败不应阻断用例
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* 忽略，Chrome 会复用旧 profile */ }

  const cdpPort = await findFreePort();

  const child = spawn(chromePath, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    '--disable-extensions', '--hide-scrollbars', '--window-size=1200,1000',
    `--remote-debugging-port=${cdpPort}`, `--user-data-dir=${profile}`, url,
  ], { stdio: ['ignore', 'pipe', 'pipe'] });

  let stderr = '';
  child.stderr.on('data', (d) => { stderr += d.toString(); });

  try {
    // 等 DevTools 端口就绪
    let page = null;
    const devtoolsDeadline = Date.now() + 25000;
    while (Date.now() < devtoolsDeadline && !page) {
      try {
        const res = await fetch(`http://127.0.0.1:${cdpPort}/json/list`);
        if (res.ok) page = (await res.json()).find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      } catch { /* not up yet */ }
      if (!page) await sleep(200);
    }
    if (!page) throw new Error('DevTools 端口未就绪。' + stderr.slice(0, 400));

    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', () => reject(new Error('WebSocket 连接失败')), { once: true });
    });

    const cdp = new Cdp(ws);
    await cdp.send('Runtime.enable');
    await cdp.send('Page.enable');

    // 示例页里没有 runner；连上后主动挂一个脚本进去，并先把参数塞进 window.__VERIFY。
    // 不必担心执行顺序：runner 内部用 waitFor 等 window.WiseEditor 就绪。
    // inject.classic=true 时按经典脚本注入 —— file:// 下模块脚本会被 CORS 拦掉。
    if (inject) {
      const norm = (u) => {
        try { return decodeURI(String(u)); } catch { return String(u); }
      };
      const wantHref = norm(url);

      /**
       * 目标文档是否已经就绪
       *
       * 只看 readyState 不够：Chrome 刚启动时目标可能还停在 about:blank，
       * 而 about:blank 的 readyState 也是 'complete' —— 此时注入的脚本会
       * 随导航一起消失（表现为 0/0）。必须同时核对 location.href。
       */
      const pageReady = async () => {
        try {
          const s = await cdp.eval('({ ready: document.readyState, href: location.href })');
          return !!s && s.ready !== 'loading' && norm(s.href) === wantHref;
        } catch {
          return false; // 导航中上下文被销毁
        }
      };

      const readyDeadline = Date.now() + 20000;
      while (Date.now() < readyDeadline && !(await pageReady())) {
        await sleep(100);
      }

      const typeAssign = inject.classic ? '' : "s.type='module';";
      const injectExpr =
        `(function(){window.__VERIFY=${JSON.stringify(inject.options || {})};` +
        `var s=document.createElement('script');${typeAssign}s.src=${JSON.stringify(inject.src)};` +
        `(document.body||document.documentElement).appendChild(s);return 1;})()`;

      // 注入后要「二次确认」：脚本可能已挂上但文档随即又导航（__VERIFY 消失）
      const injectDeadline = Date.now() + 20000;
      let injected = false;
      while (Date.now() < injectDeadline && !injected) {
        if (!(await pageReady())) {
          await sleep(150);
          continue;
        }
        try {
          await cdp.eval(injectExpr);
          await sleep(200);
          injected = !!(await cdp.eval('window.__VERIFY')) && (await pageReady());
        } catch { /* 导航中，重试 */ }
        if (!injected) await sleep(150);
      }
    }

    let title = '';
    const deadline = Date.now() + 60000;
    while (Date.now() < deadline) {
      try { title = await cdp.eval('document.title'); } catch { /* navigation in flight */ }
      if (title === 'VERIFY_OK' || title === 'VERIFY_FAIL') break;
      await sleep(250);
    }

    let payload;
    try {
      payload = JSON.parse(await cdp.eval("document.getElementById('result').textContent"));
    } catch (e) {
      payload = { total: 0, passed: 0, failed: -1, results: [], logs: ['result 解析失败: ' + e.message] };
    }
    payload.title = title;
    payload.browserConsole = cdp.console;

    if (screenshot) {
      try {
        const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(OUT_DIR, slug + '.png'), Buffer.from(shot.data, 'base64'));
      } catch { /* screenshot is best-effort */ }
    }
    return payload;
  } finally {
    try { child.kill(); } catch { /* ignore */ }
    await sleep(400);
  }
}

// ============================================================================
// main
// ============================================================================

function report(payload) {
  const failed = payload.results.filter((r) => !r.ok);
  const label = payload.failed === 0 ? 'PASS' : 'FAIL';
  console.log(`  [${label}] ${payload.passed}/${payload.total} 断言通过`);
  for (const f of failed) console.log(`         ✗ ${f.name} :: ${f.detail}`);
  const noisy = (payload.browserConsole || []).filter((c) => c.type === 'error' || c.type === 'exception');
  for (const c of noisy.slice(0, 5)) console.log(`         [console.${c.type}] ${c.text}`);
  if (payload.total === 0) {
    // 0/0 说明 runner 没跑完/没写入结果，这里补一条线索便于定位（标题 + 全部控制台输出）
    const all = (payload.browserConsole || []).slice(0, 8).map((c) => `${c.type}:${c.text}`).join(' | ');
    console.log(`         [diag] title=${JSON.stringify(payload.title)} total=0 console=[${all}]`);
  }
  return payload.failed === 0 && payload.total > 0;
}

async function main() {
  const rel = (p) => path.relative(ROOT, p).replace(/\\/g, '/');

  // ── 开发服务器模式：只起静态服务，不开 Chrome、不跑断言 ──────────────
  // 示例页用的是站点绝对路径 /statics/...，必须同时挂两个站点根才能本地打开。
  if (process.argv.includes('--serve')) {
    await startServer({ main: CASE_MAIN, fallback: CASE_FALLBACK, markdown: CASE_MARKDOWN });
    console.log(`[verify-editor] 示例页开发服务器 http://127.0.0.1:${PORT}   (Ctrl+C 退出)`);
    console.log('  /statics/**  → ' + rel(PUBLISHED_STATICS) + '   （已发布的运行时资源）');
    console.log('  /example/**  → ' + rel(EXAMPLE_DIR) + '   （示例页源码）');
    console.log('');
    for (const p of EXAMPLE_PAGES) {
      console.log(`  ${p.label.padEnd(16)} http://127.0.0.1:${PORT}${EXAMPLE_URL_PREFIX}/${p.file}`);
    }
    console.log(`  ${'直开版（双击）'.padEnd(16)} ${rel(path.join(EXAMPLE_DIR, STANDALONE_FILE))}`);
    console.log('    ↑ 相对路径 + 经典脚本，直接双击打开即可，无需本服务器');
    if (!fs.existsSync(PUBLISHED_ENTRY)) {
      console.log('\n  ⚠ public/ 下还没有已发布的插件资源，示例页会 404。请先执行 npm run build:editor。');
    }
    return;
  }

  const chromePath = findChrome();
  if (!chromePath) {
    console.error('[verify-editor] 找不到 Chrome/Edge。可用 CHROME_PATH 环境变量指定。');
    process.exit(2);
  }
  if (!fs.existsSync(BUNDLE)) {
    console.error('[verify-editor] 缺少构建产物 ' + path.relative(ROOT, BUNDLE) + '，请先执行 npm run build:editor');
    process.exit(2);
  }

  fs.mkdirSync(OUT_DIR, { recursive: true });
  console.log(`[verify-editor] Chrome: ${chromePath}`);
  console.log(`[verify-editor] 静态服务 http://127.0.0.1:${PORT}`);

  /**
   * 产物版本清单自检（不起浏览器，纯文件级）
   *
   * 版本化能不能生效，全看这份清单与 bundle 是否对得上：
   *   - 清单缺失 → 前端退回无版本 URL（等于没做版本化）
   *   - 哈希与文件不一致 → URL 不变，缓存照旧命中旧引擎
   * 这类问题在页面上完全看不出来，所以必须在构建后被显式断言一次。
   */
  function checkVendorManifest() {
      const bundle = path.join(PUBLISHED_STATICS, 'plugins', 'wiseeditor', 'js', 'vendor', 'tiptap.bundle.js');
      const manifestPath = path.join(path.dirname(bundle), 'tiptap.bundle.manifest.json');

      const fail = (msg) => {
          console.log(`  [FAIL] ${msg}`);
          return false;
      };

      if (!fs.existsSync(bundle)) return fail(`bundle 不存在：${bundle}`);
      if (!fs.existsSync(manifestPath)) return fail('版本清单不存在（先跑 npm run build:editor）');

      let manifest = null;
      try {
          manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
      } catch (err) {
          return fail(`版本清单不是合法 JSON：${err.message}`);
      }

      const expected = crypto.createHash('sha256').update(fs.readFileSync(bundle)).digest('hex').slice(0, 12);

      // ── 体积守卫接线断言 ──────────────────────────────────
      // 守卫只有在构建时被**调用**才有意义。改构建脚本时最容易误删的
      // 就是那一行调用（删了照样能构建，只是再没人拦体积），所以这里查一遍。
      const buildSrc = fs.readFileSync(path.join(ROOT, 'build', 'build-editor.mjs'), 'utf8');
      const budgetMatch = /const SIZE_BUDGET_KB = (\d+);/.exec(buildSrc);
      const budgetKb = budgetMatch ? Number(budgetMatch[1]) : 0;
      const actualKb = fs.statSync(bundle).size / 1024;

      const allOk = [
          [manifest.hash === expected, `清单 hash 与 bundle 内容一致（${manifest.hash}）`],
          [/^[0-9a-f]{12}$/.test(String(manifest.hash || '')), '清单 hash 为 12 位十六进制'],
          [manifest.file === 'tiptap.bundle.js', '清单指向 tiptap.bundle.js'],
          [Number(manifest.bytes) === fs.statSync(bundle).size, '清单体积与实际文件一致'],
          [budgetKb > 0 && /assertSizeBudget\(cfg\.file/.test(buildSrc),
              `构建脚本仍在调用体积守卫（预算 ${budgetKb || '?'} KB）`],
          [budgetKb === 0 || actualKb <= budgetKb,
              `产物在体积预算内（${actualKb.toFixed(1)} / ${budgetKb} KB）`],
      ];

      let ok = true;
      for (const [passed, label] of allOk) {
          console.log(`  [${passed ? 'PASS' : 'FAIL'}] ${label}`);
          if (!passed) ok = false;
      }
      return ok;
  }

  /**
   * 适配器单文件自检（纯文件级）
   *
   * 打包要解决的是"线上同时存在打包版和散装版"——所以断言两件事：
   *   1. 散装源模块**一个都不能**再出现在 public/（否则两份代码各自演化）
   *   2. bundle 里没有把 600 KB 的引擎内联进来（体积会暴露）
   */
  function checkAdapterBundle() {
      const dir = path.join(PUBLISHED_STATICS, 'plugins', 'wiseeditor', 'js');
      const bundle = path.join(dir, 'wiseeditor.bundle.js');
      const sources = [
          'wiseeditor-init.js', 'wiseeditor.js', 'wiseeditor-tiptap.js',
          'wiseeditor-popover.js', 'wiseeditor-statusbar.js', 'wiseeditor-upload.js',
      ];
      const leftovers = sources.filter((name) => fs.existsSync(path.join(dir, name)));

      if (!fs.existsSync(bundle)) {
          console.log('  [FAIL] 适配器 bundle 未发布（先跑 npm run build:editor）');
          return false;
      }

      const kb = fs.statSync(bundle).size / 1024;
      const allOk = [
          [leftovers.length === 0, `散装源模块未发布到 public/（${leftovers.join(', ') || '干净'}）`],
          [kb < 200, `适配器 bundle 未内联引擎（${kb.toFixed(1)} KB < 200 KB）`],
      ];

      let ok = true;
      for (const [passed, label] of allOk) {
          console.log(`  [${passed ? 'PASS' : 'FAIL'}] ${label}`);
          if (!passed) ok = false;
      }
      return ok;
  }

  console.log('\n[0/5] 产物自检（版本清单 + 适配器单文件）');
  const manifestOk = checkVendorManifest();
  const adapterOk = checkAdapterBundle();

  const server = await startServer({ main: CASE_MAIN, fallback: CASE_FALLBACK, markdown: CASE_MARKDOWN });
  let ok = manifestOk && adapterOk;

  try {
    console.log('\n[1/5] ' + CASE_MAIN.name);
    const main = await runCase(chromePath, 'main', `http://127.0.0.1:${PORT}/__verify/main.html`, true);
    ok = report(main) && ok;

    console.log('\n[2/5] ' + CASE_MARKDOWN.name);
    const md = await runCase(chromePath, 'markdown', `http://127.0.0.1:${PORT}/__verify/markdown.html`, true);
    ok = report(md) && ok;

    console.log('\n[3/5] ' + CASE_FALLBACK.name + '（临时改名 vendor bundle）');
    fs.renameSync(BUNDLE, BUNDLE_BAK);
    try {
      const fb = await runCase(chromePath, 'fallback', `http://127.0.0.1:${PORT}/__verify/fallback.html`, false);
      ok = report(fb) && ok;
    } finally {
      fs.renameSync(BUNDLE_BAK, BUNDLE);
      console.log('  (vendor bundle 已恢复)');
    }

    console.log('\n[4/5] ' + CASE_PUBLISH_NAME);
    if (!fs.existsSync(PUBLISHED_ENTRY)) {
      console.log('  [SKIP] 未找到 public/statics/plugins/wiseeditor/js/wiseeditor.bundle.js');
      console.log('         → 插件静态资源尚未发布。执行 npm run build:editor（构建完会自动同步），');
      console.log('           或 php think plugin:enable wiseeditor，');
      console.log('           或把 plugins/wiseeditor/statics/ 合并复制到 public/statics/plugins/wiseeditor/');
    } else {
      const missBefore = server.misses.length;

      for (let i = 0; i < EXAMPLE_PAGES.length; i++) {
        const p = EXAMPLE_PAGES[i];
        console.log(`\n[4/5.${i + 1}] ${p.label}（${EXAMPLE_URL_PREFIX}/${p.file}）`);
        const res = await runCase(
          chromePath, p.slug, `http://127.0.0.1:${PORT}${EXAMPLE_URL_PREFIX}/${p.file}`, true,
          {
            src: '/statics/plugins/wiseeditor/__verify/runner-publish.js',
            options: { depth: p.deep ? 'deep' : 'shallow', tipTap: p.tipTap },
          }
        );
        ok = report(res) && ok;
      }

      // 零 404 是全局断言：三个站点根混在一起，逐页统计意义不大
      const miss = server.misses.slice(missBefore);
      const zero = miss.length === 0;
      console.log(`\n  [${zero ? 'PASS' : 'FAIL'}] 示例页零 404（未命中请求 ${miss.length} 个）`
        + (zero ? '' : ' :: ' + miss.join(', ')));
      ok = zero && ok;
    }

    // ── 用例 4：直开版（真实 file://，不起服务）────────────────────────
    // 为什么必须单独一个用例：它验证的是「不依赖任何服务也能跑」，
    // 而用例 1~3 全部建立在 HTTP + 站点绝对路径 + ES module 之上，
    // 覆盖不到 file:// 独有的两个拦路虎（相对路径、经典脚本）。
    console.log('\n[5/5] ' + CASE_STANDALONE_NAME);
    const standalonePath = path.join(EXAMPLE_DIR, STANDALONE_FILE);
    if (!fs.existsSync(standalonePath)) {
      console.log(`  [SKIP] 未找到 ${rel(standalonePath)}`);
    } else if (!fs.existsSync(STANDALONE_BUNDLE)) {
      console.log(`  [SKIP] 未找到 ${rel(STANDALONE_BUNDLE)}，请先执行 npm run build:editor`);
    } else {
      // runner 必须先落盘：file:// 页面没法加载内存里的脚本体
      const runnerPath = path.join(OUT_DIR, 'runner-standalone.js');
      fs.writeFileSync(runnerPath, RUNNER_STANDALONE);
      const res = await runCase(chromePath, 'standalone-file', pathToFileURL(standalonePath).href, true, {
        src: pathToFileURL(runnerPath).href,
        classic: true,
      });
      ok = report(res) && ok;
    }
  } finally {
    server.close();
    if (!process.argv.includes('--keep')) {
      // 清掉所有 chrome-profile-*（用例数量会随示例页增减，不写死名字）
      // 清理失败只提示、不抛错：某些环境对大批量删除有守卫，不能因此判定用例失败。
      for (const name of fs.readdirSync(OUT_DIR)) {
        if (name.startsWith('chrome-profile-')) {
          try {
            fs.rmSync(path.join(OUT_DIR, name), { recursive: true, force: true });
          } catch (e) {
            console.log(`  (清理 ${name} 失败，可手动删除 .verify-out/${name})`);
          }
        }
      }
    }
  }

  console.log('\n[verify-editor] ' + (ok ? '全部通过 ✓' : '存在失败项 ✗'));
  process.exit(ok ? 0 : 1);
}

main().catch((err) => {
  console.error('[verify-editor] ' + (err && err.stack ? err.stack : err));
  process.exit(1);
});
