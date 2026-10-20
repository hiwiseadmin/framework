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
 *   2. 降级路径：vendor bundle 缺失时必须自动回退到内置 contenteditable 引擎
 *   3. 示例页冒烟：遍历 plugins/wiseeditor/example/ 下的三个「线上形态」示例页，
 *      在「/statics/** → public/」的真实上线 URL 布局下打开，验证模块图完整、零 404
 *      （用例 1/2 的站点根是 plugins/wiseeditor/statics，走的是短路径，
 *        覆盖不到「插件静态资源是否已发布」这一环）
 *   4. 直开版冒烟：以**真实 file:// URL** 打开 example/standalone.html，
 *      零服务、零 CDP 注入环境依赖，验证「双击 html 就能测」这条路径本身没坏。
 *      用例 1~3 全部建立在 HTTP + 站点绝对路径 + ES module 上，覆盖不到
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
const PUBLISHED_ENTRY = path.join(PUBLISHED_STATICS, 'plugins', 'wiseeditor', 'js', 'wiseeditor-init.js');

// 示例页源码目录（开发期资产，不入 dist、不进 public/）。
// 页面里写的是站点绝对路径 /statics/...，因此本目录必须另挂一个站点根才能本地打开。
const EXAMPLE_DIR = path.join(ROOT, 'plugins', 'wiseeditor', 'example');
const EXAMPLE_URL_PREFIX = '/example';

const EXAMPLE_PAGES = [
  { slug: 'example-richtext', file: 'index.html', label: '示例页 · 富文本', deep: true, tipTap: true },
  { slug: 'example-markdown', file: 'markdown.html', label: '示例页 · Markdown', deep: false, tipTap: false },
  { slug: 'example-api', file: 'api.html', label: '示例页 · 实例 API', deep: false, tipTap: true },
];

// 直开版：唯一一个「双击 html 就能跑」的页面，靠真实 file:// 验证（用例 4）。
// 它用的是经典脚本 + 相对路径，因此**不能**塞进上面的 EXAMPLE_PAGES 走 HTTP——
// 那套站点根布局（/statics/** → public/）与 file:// 的相对路径不是一回事。
const STANDALONE_FILE = 'standalone.html';
const STANDALONE_BUNDLE = path.join(EXAMPLE_DIR, 'dist', 'wiseeditor.example.js');

const PORT = Number((process.argv.find((a) => a.startsWith('--port=')) || '').split('=')[1] || 8899);
const CDP_PORT = PORT + 1;

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
<script type="module" src="/js/wiseeditor-init.js"></script>
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

/** 用例 1：真实引擎闭环 */
const CASE_MAIN = {
  name: '真实 TipTap 引擎闭环',
  html: FIXTURE_HTML(
    'VERIFY',
    EDITOR_BLOCK() + EDITOR_BLOCK({ id: 'editor_ro', name: 'ro', readonly: true, value: '&lt;p&gt;Read only&lt;/p&gt;' }),
    'runner-main.js'
  ),
};

/** 用例 2：vendor bundle 缺失 → 降级 */
const CASE_FALLBACK = {
  name: 'vendor bundle 缺失时的降级路径',
  html: FIXTURE_HTML('VERIFY', EDITOR_BLOCK({ placeholder: '请输入内容' }), 'runner-fallback.js'),
};

/** 用例 3 的标题（逐页展开，页面清单见 EXAMPLE_PAGES） */
const CASE_PUBLISH_NAME = '示例页冒烟（/statics/** 真实发布布局 + /example/**）';

const RUNNER_MAIN = String.raw`
var results = [], logs = [];
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
  eq('工具栏按钮数 = 20', btns.length, 20);
  record('含 table / clearFormat 按钮', keys.indexOf('table') > -1 && keys.indexOf('clearFormat') > -1, keys.join(','));

  var ed = inst.getInternal().editor;
  ed.commands.selectAll();
  await raf();
  container.querySelector('button[data-key="bold"]').click();
  await raf();
  match('加粗命令生效', inst.getContent(), /<strong>/);
  record('加粗按钮激活态', container.querySelector('button[data-key="bold"]').classList.contains('is-active'));
  has('加粗后表单同步', hidden.value, '<strong>');

  ed.commands.setTextSelection(1);
  container.querySelector('button[data-key="h2"]').click();
  await raf();
  match('二级标题命令生效', inst.getContent(), /<h2>/);
  record('h2 按钮激活态', container.querySelector('button[data-key="h2"]').classList.contains('is-active'));
  record('正文按钮未激活', !container.querySelector('button[data-key="paragraph"]').classList.contains('is-active'));
  container.querySelector('button[data-key="paragraph"]').click();
  await raf();
  match('切回正文生效', inst.getContent(), /<p>/);

  var undoBtn = container.querySelector('button[data-key="undo"]');
  var redoBtn = container.querySelector('button[data-key="redo"]');
  record('undo 可用', undoBtn.disabled === false);
  undoBtn.click();
  await raf();
  record('撤销后 redo 变可用', redoBtn.disabled === false);

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
  record('表格工具条按钮数 = 9', !!tablebar && tablebar.childElementCount === 9, tablebar ? tablebar.childElementCount : 'missing');
  record('光标在表格内 → 工具条可见', tablebar.hidden === false);
  var addRow = tablebar.querySelector('button[data-table-cmd="addRowAfter"]');
  record('表格操作按钮可用', addRow.disabled === false);
  addRow.click();
  await raf();
  record('addRowAfter 生效', (inst.getContent().match(/<tr/g) || []).length >= 3);
  tablebar.querySelector('button[data-table-cmd="deleteTable"]').click();
  await raf();
  record('deleteTable 生效', inst.getContent().indexOf('<table') === -1);
  record('离开表格后工具条隐藏', tablebar.hidden === true);

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

  // 销毁
  window.WiseEditor.destroy('editor_content');
  await raf();
  record('destroy 清空容器', container.innerHTML === '');
  record('destroy 后 get 返回 undefined', window.WiseEditor.get('editor_content') === undefined);
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
  eq('降级工具栏按钮数 = 20', container.querySelectorAll('.wiseeditor-toolbar button[data-key]').length, 20);

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
  eq('isDirty 为空时为 false', inst.isDirty(), false);

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
  // wiseeditor-init 的 DOMContentLoaded boot，因此一律先 waitFor 再断言。
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
    // 反向断言：只用 milkdown 的页面不该为 TipTap 白拉 432 KB —— 锁住引擎按键加载
    record('未用 TipTap 的页面不加载其 bundle', !window.TipTap);
  }

  if (!first) { record('示例页至少有一个可用实例', false, '全部超时'); return; }

  // Markdown 分栏预览：右栏必须是渲染后的 HTML，不能是 Markdown 原文。
  // 这一条刻意放在 deep 早退之前 —— 有预览栏的是 markdown.html（浅层页），
  // 而"预览退化成纯文本"正是历史上漏掉的那个静默故障。
  var mdPreview = document.querySelector('.wiseeditor-md-preview');
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
  eq('工具栏按钮数 = 20', btns.length, 20);

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
  record('表格工具条按钮数 = 9', !!tablebar && tablebar.childElementCount === 9, tablebar ? tablebar.childElementCount : 'missing');

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
  eq('适配器已注册 milkdown', window.WiseEditor.adapters.has('milkdown'), true);

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
  eq('工具栏按钮数 = 20', container.querySelectorAll('.wiseeditor-toolbar button[data-key]').length, 20);

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

  // ── Markdown 走分栏降级（页面未注入 window.Milkdown），字段是 textarea ──
  var mdIns = window.WiseEditor.get('editor_body');
  if (mdIns) {
    var ta = document.getElementById('editor_body_textarea');
    var mdPreview = document.getElementById('editor_body').querySelector('.wiseeditor-md-preview');
    record('Markdown 分栏编辑器已渲染', !!mdPreview);
    has('Markdown 字段初始值保留', ta.value, '# 标题');

    // 预览栏：渲染器必须解析得到（示例页没有框架内核，靠组件自己的 window.WsMarkdown）
    record('Markdown 渲染器可解析（window.WsMarkdown）', !!window.WsMarkdown);
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
  memory.set('/__verify/runner-main.js', RUNNER_MAIN);
  memory.set('/__verify/runner-fallback.js', RUNNER_FALLBACK);
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

async function runCase(chromePath, slug, url, screenshot, inject) {
  const profile = path.join(OUT_DIR, 'chrome-profile-' + slug);
  fs.rmSync(profile, { recursive: true, force: true });

  const child = spawn(chromePath, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    '--disable-extensions', '--hide-scrollbars', '--window-size=1200,1000',
    `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${profile}`, url,
  ], { stdio: ['ignore', 'pipe', 'pipe'] });

  let stderr = '';
  child.stderr.on('data', (d) => { stderr += d.toString(); });

  try {
    // 等 DevTools 端口就绪
    let page = null;
    const devtoolsDeadline = Date.now() + 25000;
    while (Date.now() < devtoolsDeadline && !page) {
      try {
        const res = await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`);
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
      const typeAssign = inject.classic ? '' : "s.type='module';";
      await cdp.eval(
        `(function(){window.__VERIFY=${JSON.stringify(inject.options || {})};` +
        `var s=document.createElement('script');${typeAssign}s.src=${JSON.stringify(inject.src)};` +
        `(document.body||document.documentElement).appendChild(s);return 1;})()`
      );
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
  return payload.failed === 0 && payload.total > 0;
}

async function main() {
  const rel = (p) => path.relative(ROOT, p).replace(/\\/g, '/');

  // ── 开发服务器模式：只起静态服务，不开 Chrome、不跑断言 ──────────────
  // 示例页用的是站点绝对路径 /statics/...，必须同时挂两个站点根才能本地打开。
  if (process.argv.includes('--serve')) {
    await startServer({ main: CASE_MAIN, fallback: CASE_FALLBACK });
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

  const server = await startServer({ main: CASE_MAIN, fallback: CASE_FALLBACK });
  let ok = true;

  try {
    console.log('\n[1/4] ' + CASE_MAIN.name);
    const main = await runCase(chromePath, 'main', `http://127.0.0.1:${PORT}/__verify/main.html`, true);
    ok = report(main) && ok;

    console.log('\n[2/4] ' + CASE_FALLBACK.name + '（临时改名 vendor bundle）');
    fs.renameSync(BUNDLE, BUNDLE_BAK);
    try {
      const fb = await runCase(chromePath, 'fallback', `http://127.0.0.1:${PORT}/__verify/fallback.html`, false);
      ok = report(fb) && ok;
    } finally {
      fs.renameSync(BUNDLE_BAK, BUNDLE);
      console.log('  (vendor bundle 已恢复)');
    }

    console.log('\n[3/4] ' + CASE_PUBLISH_NAME);
    if (!fs.existsSync(PUBLISHED_ENTRY)) {
      console.log('  [SKIP] 未找到 public/statics/plugins/wiseeditor/js/wiseeditor-init.js');
      console.log('         → 插件静态资源尚未发布。执行 npm run build:editor（构建完会自动同步），');
      console.log('           或 php think plugin:enable wiseeditor，');
      console.log('           或把 plugins/wiseeditor/statics/ 合并复制到 public/statics/plugins/wiseeditor/');
    } else {
      const missBefore = server.misses.length;

      for (let i = 0; i < EXAMPLE_PAGES.length; i++) {
        const p = EXAMPLE_PAGES[i];
        console.log(`\n[3/4.${i + 1}] ${p.label}（${EXAMPLE_URL_PREFIX}/${p.file}）`);
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
    console.log('\n[4/4] ' + CASE_STANDALONE_NAME);
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
      for (const name of fs.readdirSync(OUT_DIR)) {
        if (name.startsWith('chrome-profile-')) {
          fs.rmSync(path.join(OUT_DIR, name), { recursive: true, force: true });
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
