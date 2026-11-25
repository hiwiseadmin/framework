/**
 * WiseCMS 编辑器字段 —— 浏览器端载荷采集与提交键核验（零依赖）
 * ============================================================================
 *
 * 与 build/verify-cms-field.php 的分工：
 *   .php   —— **服务端**链路：净化 → 落库 → 读回 → 重开（喂的是 PHP 手写夹具）
 *   本脚本 —— **浏览器**链路：把 PHP 渲染出的**真实字段表单**挂上真实 TipTap，
 *             setContent 一份富文本，读回**隐藏域的真实提交值**，落成 payload.json，
 *             再由 .php 的 `chain --payload=payload.json` 复用。
 *
 * 为什么需要它：服务端夹具再像，也不是引擎真实吐出的 HTML。TipTap 的表格会自己
 * 补 `colgroup`/`col[style]`、代码块会带 `class`、链接会补 `rel` —— 这些恰恰是
 * 净化白名单最容易漏掉的部分。只有让真实引擎产出、再让净化器吃下去，
 * 「零剥损」才算验过。
 *
 * 站点布局与线上一致：站点根 = src/，`/statics/**` → `public/statics/**`
 * 因此本脚本顺带验证「插件静态资源是否已发布」这一环（零 404 为硬断言）。
 *
 * 用法：
 *   php  build/verify-cms-field.php render --state=wired     # 先产出 form-wired.html
 *   node build/verify-cms-field.mjs                          # 采集 payload.json
 *   php  build/verify-cms-field.php chain                    # 用真实载荷跑完整链路
 *
 * 选项：
 *   --port=8966   换端口（CDP 用 port+1）
 *   --keep        保留截图与 chrome profile
 */

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

// 站点根：public/ 是线上站点根；已发布的插件资源在 public/statics/plugins/{ident}/
const PUBLIC_DIR = path.join(ROOT, 'public');
const PUBLISHED_STATICS = path.join(PUBLIC_DIR, 'statics');
const PUBLISHED_INIT = path.join(PUBLISHED_STATICS, 'plugins', 'wiseeditor', 'js', 'wiseeditor.bundle.js');

// PHP 侧渲染产物所在目录（两边共用）
const OUT_DIR = path.join(ROOT, '.verify-out', 'cms');
const FORM_HTML = path.join(OUT_DIR, 'form-wired.html');
const PAYLOAD_JSON = path.join(OUT_DIR, 'payload.json');

const PORT = Number((process.argv.find((a) => a.startsWith('--port=')) || '').split('=')[1] || 8966);
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
// 浏览器端 runner
// ============================================================================

/**
 * 载荷种子：刻意覆盖净化白名单里最易漏的结构。
 * 引擎会把它规范化后再序列化 —— 读回的是**引擎真实输出**，不是这份种子。
 */
const RICH_SEED = [
  '<h2>端到端核验标题</h2>',
  '<p>正文 <strong>粗体</strong> <em>斜体</em> <s>删除线</s> <code>inline()</code></p>',
  '<ul><li><p>无序项 A</p></li><li><p>无序项 B</p></li></ul>',
  '<ol><li><p>有序项 1</p></li></ol>',
  '<blockquote><p>引用文本</p></blockquote>',
  '<pre><code>echo "code block";</code></pre>',
  '<hr>',
  '<table><tbody>',
  '<tr><th colspan="2"><p>表头</p></th></tr>',
  '<tr><td style="text-align: left"><p>甲</p></td><td style="text-align: center"><p>乙</p></td></tr>',
  '</tbody></table>',
  '<p><a href="https://example.com">外部链接</a></p>',
  '<p><img src="/statics/uploads/demo.png" alt="示例图" title="提示"></p>',
].join('');

const RUNNER = String.raw`
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
  // ── 字段表单结构（PHP 侧 FormService 渲染的产物）──
  var container = document.getElementById('editor_content');
  record('字段表单含编辑器容器 #editor_content', !!container);
  record('容器声明 data-editor="wiseeditor"', !!container && container.getAttribute('data-editor') === 'wiseeditor');
  record('容器声明 data-mode="richtext"', !!container && container.getAttribute('data-mode') === 'richtext');
  eq('容器 data-name = content', container && container.getAttribute('data-name'), 'content');

  var hidden = document.getElementById('editor_content_input');
  record('隐藏域 #editor_content_input 存在', !!hidden);
  eq('隐藏域 name = content（表单提交键）', hidden && hidden.name, 'content');
  eq('隐藏域 type = hidden', hidden && hidden.type, 'hidden');

  // ── 真实引擎挂载 ──
  var inst = await waitFor(function () { return window.WiseEditor && window.WiseEditor.get('editor_content'); });
  if (!inst) { record('TipTap 实例挂载成功', false, '超时 —— 检查 /statics/plugins/wiseeditor 是否已发布'); return; }
  record('TipTap 实例挂载成功', true);
  eq('engine = tiptap', inst.engine, 'tiptap');
  eq('window.TipTap 已挂载', typeof window.TipTap, 'object');
  eq('TipTap 版本 = 3.31.4', window.TipTap && window.TipTap.version, '3.31.4');
  eq('适配器暴露版本', inst.version, '3.31.4');

  var pm = container.querySelector('.wiseeditor-pane .ProseMirror');
  record('ProseMirror 已挂载（真实编辑区）', !!pm);
  eq('工具栏按钮数 = 20', container.querySelectorAll('.wiseeditor-toolbar button[data-key]').length, 20);

  // ── 灌入富文本，读回引擎真实输出 ──
  inst.setContent(window.__RICH_SEED || '');
  await raf();

  var fromEditor = inst.getContent();
  record('引擎产出非空', fromEditor.length > 0, fromEditor.length + ' 字节');

  // 结构保真：这些是「服务端手写夹具」与「引擎真实输出」的差异所在
  has('产出含表格', fromEditor, '<table');
  has('产出含 colgroup（引擎表格自带）', fromEditor, '<colgroup');
  has('产出含 col（列定义）', fromEditor, '<col');
  match('col 带 style（列宽）', fromEditor, /<col[^>]*style=/);
  match('td/th 带 align style', fromEditor, /text-align:\s*(left|center|right)/);
  match('表头 colspan 保留', fromEditor, /colspan="2"/);
  has('产出含分割线', fromEditor, '<hr');
  has('产出含引用', fromEditor, '<blockquote');
  has('产出含代码块', fromEditor, '<pre');
  has('产出含列表', fromEditor, '<li');
  has('产出含加粗', fromEditor, '<strong');
  match('产出链接带 href', fromEditor, /<a[^>]+href="https:\/\/example\.com"/);
  match('产出图片带 src', fromEditor, /<img[^>]+src="\/statics\/uploads\/demo\.png"/);

  // ── 提交键：隐藏域必须与编辑器一致 ──
  eq('隐藏域与编辑器内容一致（表单同步）', hidden.value, fromEditor);

  // 表单编码往返：value 必须能原样经 URLSearchParams 编解码（模拟真实提交）
  var fd = new FormData();
  fd.append('content', hidden.value);
  var encRoundTrip = fd.get('content') === hidden.value;
  record('表单编码往返一致（FormData）', encRoundTrip);
  var uspRoundTrip = new URLSearchParams([['content', hidden.value]]).get('content') === hidden.value;
  record('表单编码往返一致（URLSearchParams）', uspRoundTrip);

  // 提交结构：真实提交时会被服务端读到的键值
  window.__CMS_SUBMIT = {
    engine: inst.engine,
    version: inst.version,
    tipTapVersion: window.TipTap && window.TipTap.version,
    field: hidden.name,
    inputId: hidden.id,
    content: hidden.value,
    content_length: hidden.value.length,
    editor_matches_hidden: hidden.value === fromEditor,
    encoding_roundtrip: encRoundTrip && uspRoundTrip,
  };
  record('提交载荷已生成', hidden.value.length > 0, hidden.value.length + ' 字节');
}

async function main() {
  try { await run(); }
  catch (err) { record('核验脚本异常', false, (err && err.stack) || String(err)); }
  var failed = results.filter(function (r) { return !r.ok; });
  var holder = document.getElementById('result');
  if (!holder) {
    holder = document.createElement('pre');
    holder.id = 'result';
    holder.style.display = 'none';
    document.body.appendChild(holder);
  }
  holder.textContent = JSON.stringify({
    total: results.length,
    passed: results.length - failed.length,
    failed: failed.length,
    results: results,
    submit: window.__CMS_SUBMIT || null,
    logs: logs,
  });
  document.title = failed.length === 0 ? 'VERIFY_OK' : 'VERIFY_FAIL';
}
main();
`;

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
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.ttf': 'font/ttf',
};

function startServer(formHtml) {
  const memory = new Map();
  memory.set('/form.html', formHtml);
  memory.set('/__verify/runner-cms.js', RUNNER);

  /** 所有未命中的请求（用于「零 404」断言） */
  const misses = [];

  const server = http.createServer((req, res) => {
    const url = (req.url || '/').split('?')[0];
    if (url === '/favicon.ico') { res.writeHead(204).end(); return; }
    if (url === '/') { res.writeHead(302, { location: '/form.html' }).end(); return; }
    if (memory.has(url)) {
      res.writeHead(200, { 'content-type': MIME[path.extname(url)] || 'text/plain; charset=utf-8' }).end(memory.get(url));
      return;
    }
    // 站点根 = public/：/statics/** → public/statics/**（与线上一致）
    let base = PUBLIC_DIR;
    let rel = url;
    if (url.startsWith('/statics/')) {
      base = PUBLISHED_STATICS;
      rel = url.slice('/statics'.length);
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

async function openPage(chromePath, url, keep) {
  const profile = path.join(ROOT, '.verify-out', 'chrome-profile-cms');
  fs.rmSync(profile, { recursive: true, force: true });

  const child = spawn(chromePath, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    '--disable-extensions', '--hide-scrollbars', '--window-size=1200,1000',
    `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${profile}`, url,
  ], { stdio: ['ignore', 'pipe', 'pipe'] });

  let stderr = '';
  child.stderr.on('data', (d) => { stderr += d.toString(); });

  let page = null;
  const deadline = Date.now() + 25000;
  while (Date.now() < deadline && !page) {
    try {
      const res = await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`);
      if (res.ok) page = (await res.json()).find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
    } catch { /* not up yet */ }
    if (!page) await sleep(200);
  }
  if (!page) {
    try { child.kill(); } catch { /* ignore */ }
    throw new Error('DevTools 端口未就绪。' + stderr.slice(0, 400));
  }

  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', () => reject(new Error('WebSocket 连接失败')), { once: true });
  });

  const cdp = new Cdp(ws);
  await cdp.send('Runtime.enable');
  await cdp.send('Page.enable');

  // 注入种子 + runner（form.html 里不带 runner；连上 CDP 后动态挂模块）
  await cdp.eval(
    `(function(){window.__RICH_SEED=${JSON.stringify(RICH_SEED)};` +
    `var s=document.createElement('script');s.type='module';s.src='/__verify/runner-cms.js';` +
    `(document.body||document.documentElement).appendChild(s);return 1;})()`
  );

  return {
    cdp,
    close: async () => {
      try { child.kill(); } catch { /* ignore */ }
      await sleep(400);
      if (!keep) fs.rmSync(profile, { recursive: true, force: true });
    },
  };
}

// ============================================================================
// main
// ============================================================================

function report(payload) {
  const failed = (payload.results || []).filter((r) => !r.ok);
  const label = payload.failed === 0 && payload.total > 0 ? 'PASS' : 'FAIL';
  console.log(`  [${label}] ${payload.passed}/${payload.total} 断言通过`);
  for (const f of failed) console.log(`         ✗ ${f.name} :: ${f.detail}`);
  const noisy = (payload.browserConsole || []).filter((c) => c.type === 'error' || c.type === 'exception');
  for (const c of noisy.slice(0, 5)) console.log(`         [console.${c.type}] ${c.text}`);
  return payload.failed === 0 && payload.total > 0;
}

async function main() {
  const keep = process.argv.includes('--keep');
  fs.mkdirSync(OUT_DIR, { recursive: true });

  if (!fs.existsSync(FORM_HTML)) {
    console.error('[verify-cms-field] 缺少字段表单 ' + path.relative(ROOT, FORM_HTML));
    console.error('  请先执行：php build/verify-cms-field.php render --state=wired');
    process.exit(2);
  }
  if (!fs.existsSync(PUBLISHED_INIT)) {
    console.error('[verify-cms-field] 缺少已发布的编辑器资源 ' + path.relative(ROOT, PUBLISHED_INIT));
    console.error('  → 插件静态资源尚未发布。执行 npm run build:editor 或 php think plugin:enable wiseeditor');
    process.exit(2);
  }

  const chromePath = findChrome();
  if (!chromePath) {
    console.error('[verify-cms-field] 找不到 Chrome/Edge。可用 CHROME_PATH 环境变量指定。');
    process.exit(2);
  }

  console.log(`[verify-cms-field] Chrome: ${chromePath}`);
  console.log(`[verify-cms-field] 静态服务 http://127.0.0.1:${PORT}  （/statics/** → public/statics）`);

  const server = await startServer(fs.readFileSync(FORM_HTML, 'utf8'));
  const missBefore = server.misses.length;
  let ok = true;
  let page = null;

  try {
    console.log('\n[1/1] 真实字段表单 + 真实 TipTap 引擎 → 采集提交载荷');
    page = await openPage(chromePath, `http://127.0.0.1:${PORT}/form.html`, keep);

    let title = '';
    const deadline = Date.now() + 60000;
    while (Date.now() < deadline) {
      try { title = await page.cdp.eval('document.title'); } catch { /* navigation in flight */ }
      if (title === 'VERIFY_OK' || title === 'VERIFY_FAIL') break;
      await sleep(250);
    }

    let payload;
    try {
      payload = JSON.parse(await page.cdp.eval("document.getElementById('result').textContent"));
    } catch (e) {
      payload = { total: 0, passed: 0, failed: -1, results: [], logs: ['result 解析失败: ' + e.message] };
    }
    payload.title = title;
    payload.browserConsole = page.cdp.console;
    ok = report(payload) && ok;

    // 截图（目视核对工具栏 / 表格 / 链接）
    try {
      const shot = await page.cdp.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(OUT_DIR, 'browser-cms.png'), Buffer.from(shot.data, 'base64'));
    } catch { /* best-effort */ }

    // ── 落 payload.json 供 PHP chain 复用 ──
    const submit = payload.submit;
    if (submit && submit.content) {
      const out = {
        source: 'browser',
        captured_at: new Date().toISOString(),
        engine: submit.engine,
        version: submit.version,
        tipTapVersion: submit.tipTapVersion,
        field: submit.field,
        inputId: submit.inputId,
        content: submit.content,
        content_length: submit.content_length,
        hidden_matches_editor: submit.editor_matches_hidden,
        encoding_roundtrip: submit.encoding_roundtrip,
      };
      fs.writeFileSync(PAYLOAD_JSON, JSON.stringify(out, null, 2));
      console.log(`\n  [PASS] 载荷已落盘 ${path.relative(ROOT, PAYLOAD_JSON)}（${submit.content_length} 字节）`);
      console.log(`         字段键=${submit.field}  引擎=${submit.engine}@${submit.version}`);
    } else {
      console.log('\n  [FAIL] 未能采集提交载荷（runner 未产出 window.__CMS_SUBMIT）');
      ok = false;
    }

    // ── 零 404：编辑器资源必须全部命中（硬断言）──
    const miss = server.misses.slice(missBefore);
    const editorMiss = miss.filter((u) => u.includes('/plugins/wiseeditor/'));
    const zero = editorMiss.length === 0;
    console.log(`\n  [${zero ? 'PASS' : 'FAIL'}] 编辑器静态资源零 404（未命中 ${editorMiss.length} 个）`
      + (zero ? '' : ' :: ' + editorMiss.join(', ')));
    if (miss.length > editorMiss.length) {
      const other = miss.filter((u) => !u.includes('/plugins/wiseeditor/'));
      console.log(`  [INFO] 其他未命中 ${other.length} 个：${other.slice(0, 8).join(', ')}`);
    }
    ok = zero && ok;
  } finally {
    if (page) await page.close();
    server.close();
  }

  console.log('\n[verify-cms-field] ' + (ok ? '全部通过 ✓' : '存在失败项 ✗'));
  console.log('[verify-cms-field] 下一步：php build/verify-cms-field.php chain');
  process.exit(ok ? 0 : 1);
}

main().catch((err) => {
  console.error('[verify-cms-field] ' + (err && err.stack ? err.stack : err));
  process.exit(1);
});
