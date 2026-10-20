/**
 * WiseAdmin 前端资源构建脚本（esbuild）
 *
 * 入口：public/statics/modules/wise-bootstrap.js     -> wise-bundle.js / wise-bundle.min.js
 *       public/statics/modules/ws/ws-components.css  -> wise-bundle.css / wise-bundle.min.css
 *
 * 用法：
 *   npm run build              构建调试版 + 生产版（默认）
 *   npm run dev                只构建调试版
 *   npm run watch              只监听调试版（增量构建，JS 与 CSS 同时监听）
 *   node build/build-js.mjs --collapse=safe
 *                              指定 HTML 空白折叠档位（none|safe|full），覆盖默认
 *
 * ── 双产物约定 ────────────────────────────────────────────
 *   wise-bundle.js      调试版：不压缩，末尾带 //# sourceMappingURL，便于断点排障。
 *   wise-bundle.min.js  生产版：压缩，sourcemap 用 external（生成 .map 但不写注释），
 *                       浏览器不会去请求 .map，生产环境无需再手工删注释或删文件。
 *   CSS 同理：wise-bundle.css / wise-bundle.min.css。
 *   视图按 app.debug 选择加载哪一份，见 app/middleware/Environment.php。
 *
 * ── HTML 空白折叠（--collapse）────────────────────────────
 *   esbuild 只压缩代码，不处理模板字符串里的 HTML 缩进与换行。本脚本通过 onLoad
 *   插件在打包前折叠这些空白，三档：
 *     none  不处理。调试版默认，保持可读，sourcemap 行号精确。
 *     safe  只删换行前后的水平空白，换行数量不变 -> sourcemap 行号零偏移。生产版默认。
 *     full  连同换行一起删 -> 体积最小，但会累积改变行号，map 与源文件整体错位，
 *           仅在完全不在意线上定位时启用。
 *   风险提示：full 会移除标签之间的空白。若模板里的 inline 元素依赖空格产生间距
 *   （如 <i class="bi"></i> <span>文字</span>），该间距会消失，需要时用 class 补边距。
 *   含 <pre> / <textarea> 的模板已整体跳过，其内部空白不会被折叠。
 *
 * ── 改动前务必阅读的约束 ──────────────────────────────────
 * 1. jQuery / Bootstrap 走 window 全局，不作为模块依赖打包——ws-* 组件是 jQuery 插件，
 *    一旦 import 第二份 jQuery 实例，插件注册与调用会落在不同实例上而全部失效。
 * 2. 不使用 splitting：wise-bootstrap.js 依赖动态 import() 的内联语义
 *    （已打包时 esbuild 内联为惰性求值闭包），开启 splitting 会改变该行为。
 * 3. JS 与 CSS 分开构建：组件 JS 刻意不 import CSS。浏览器原生 ESM 不支持导入 CSS
 *    （服务端返回 text/css 而非 JS 模块类型），一旦 import，demo.html 这类
 *    "直连源码"的场景会整体加载失败。样式改由 ws-components.css 统一 @import，
 *    因此 base.html 必须显式 link wise-bundle.css。
 * 4. 输出文件必须位于 statics 的直接子目录（modules/、css/）。
 *    wise-bootstrap.js 通过 new URL(jsPath, import.meta.url) 解析页面控制器路径，
 *    bundle 位置变化会让 '../admin/xxx' 解析到错误目录。
 */

import * as esbuild from 'esbuild';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const STATICS_DIR = path.join(ROOT, 'public', 'statics');
const MODULES_DIR = path.join(STATICS_DIR, 'modules');
const LIBS_DIR = path.join(STATICS_DIR, 'libs');
const CSS_DIR = path.join(STATICS_DIR, 'css');

const argv = process.argv.slice(2);
const args = new Set(argv);
const isWatch = args.has('--watch');
const isDev = args.has('--dev') || isWatch;

const COLLAPSE_MODES = new Set(['none', 'safe', 'full']);
const collapseArg = argv.find((a) => a.startsWith('--collapse='))?.split('=')[1];
if (collapseArg && !COLLAPSE_MODES.has(collapseArg)) {
    console.error(`[build] --collapse 取值只能是 none|safe|full，收到: ${collapseArg}`);
    process.exit(1);
}

const JS_ENTRY = path.join(MODULES_DIR, 'wise-bootstrap.js');
const CSS_ENTRY = path.join(MODULES_DIR, 'ws', 'ws-components.css');

// sourcemap:
//   'linked'   生成 .map 并在产物末尾追加 //# sourceMappingURL（调试版）
//   'external' 生成 .map 但不追加注释，浏览器不会请求 map（生产版）
const JS_OUTPUTS = [
    {
        dev: true,
        label: '调试版',
        file: path.join(MODULES_DIR, 'wise-bundle.js'),
        minify: false,
        sourcemap: 'linked',
        collapse: 'none',
    },
    {
        dev: false,
        label: '生产版',
        file: path.join(MODULES_DIR, 'wise-bundle.min.js'),
        minify: true,
        sourcemap: 'external',
        // 实测（生产版 JS）：none 287.1 KB / safe 275.0 KB / full 274.5 KB。
        // 换行本身只占 0.5 KB，full 却会让 sourcemap 行号整体错位，故默认 safe。
        collapse: 'safe',
    },
];

const CSS_OUTPUTS = [
    {
        dev: true,
        label: '调试版',
        file: path.join(CSS_DIR, 'wise-bundle.css'),
        minify: false,
        sourcemap: 'linked',
    },
    {
        dev: false,
        label: '生产版',
        file: path.join(CSS_DIR, 'wise-bundle.min.css'),
        minify: true,
        sourcemap: 'external',
    },
];

for (const [label, file] of [['JS 入口', JS_ENTRY], ['CSS 入口', CSS_ENTRY]]) {
    if (!fs.existsSync(file)) {
        console.error(`[build] ${label}不存在: ${file}`);
        process.exit(1);
    }
}

// ── HTML 空白折叠 ────────────────────────────────────────────

/** 从 start（反引号位置）读取完整模板字符串，返回原文与结束下标。${} 内原样保留。 */
function readTemplate(code, start) {
    const parts = ['`'];
    let j = start + 1;

    while (j < code.length) {
        const c = code[j];

        if (c === '\\') {
            parts.push(c, code[j + 1] ?? '');
            j += 2;
            continue;
        }

        if (c === '$' && code[j + 1] === '{') {
            parts.push('${');
            j += 2;
            let depth = 1;
            while (j < code.length && depth > 0) {
                const d = code[j];
                if (d === '\\') {
                    parts.push(d, code[j + 1] ?? '');
                    j += 2;
                    continue;
                }
                if (d === '`') {
                    const nested = readTemplate(code, j);
                    parts.push(nested.text);
                    j = nested.end;
                    continue;
                }
                if (d === '"' || d === "'") {
                    const quoted = readQuoted(code, j, d);
                    parts.push(quoted.text);
                    j = quoted.end;
                    continue;
                }
                if (d === '{') depth++;
                if (d === '}') {
                    depth--;
                    if (depth === 0) {
                        parts.push('}');
                        j++;
                        break;
                    }
                }
                parts.push(d);
                j++;
            }
            continue;
        }

        if (c === '`') {
            parts.push('`');
            j++;
            break;
        }

        parts.push(c);
        j++;
    }

    return { text: parts.join(''), end: j };
}

/** 从 start（引号位置）读取普通字符串，返回原文与结束下标 */
function readQuoted(code, start, quote) {
    let j = start + 1;
    while (j < code.length) {
        if (code[j] === '\\') {
            j += 2;
            continue;
        }
        if (code[j] === quote) {
            j++;
            break;
        }
        if (code[j] === '\n') break; // 未闭合，交给 esbuild 报错
        j++;
    }
    return { text: code.slice(start, j), end: j };
}

/** 对单个模板字符串做空白折叠 */
function transformTemplate(tpl, mode) {
    // 只对含 HTML 标签的模板生效，避免影响日志文本、CSS 片段等
    if (!/<[a-zA-Z!/]/.test(tpl)) return tpl;
    // <pre> / <textarea> 内的空白有语义，整体跳过
    if (/<pre\b/i.test(tpl) || /<textarea\b/i.test(tpl)) return tpl;

    if (mode === 'safe') {
        // 保留换行数量，只删换行前后的水平空白 -> sourcemap 行号零偏移
        return tpl.replace(/[ \t]+(\r?\n)/g, '$1').replace(/(\r?\n)[ \t]+/g, '$1');
    }
    // full：连同换行一起删
    return tpl.replace(/[ \t]*\r?\n[ \t]*/g, '');
}

/** 折叠源码中所有模板字符串里的 HTML 空白 */
function collapseHtmlWhitespace(code, mode) {
    if (mode === 'none' || !code.includes('`')) return code;

    let out = '';
    let i = 0;
    const n = code.length;

    while (i < n) {
        const c = code[i];

        // 注释：原样拷贝，避免其中的反引号干扰扫描
        if (c === '/' && code[i + 1] === '/') {
            const stop = code.indexOf('\n', i);
            const end = stop === -1 ? n : stop;
            out += code.slice(i, end);
            i = end;
            continue;
        }
        if (c === '/' && code[i + 1] === '*') {
            const stop = code.indexOf('*/', i + 2);
            const end = stop === -1 ? n : stop + 2;
            out += code.slice(i, end);
            i = end;
            continue;
        }

        // 普通字符串与模板字符串
        if (c === '"' || c === "'") {
            const quoted = readQuoted(code, i, c);
            out += quoted.text;
            i = quoted.end;
            continue;
        }
        if (c === '`') {
            const tpl = readTemplate(code, i);
            out += transformTemplate(tpl.text, mode);
            i = tpl.end;
            continue;
        }

        out += c;
        i++;
    }

    return out;
}

/** esbuild 插件：打包前折叠模板字符串里的 HTML 空白，保证 sourcemap 由 esbuild 统一生成 */
function collapsePlugin(mode) {
    return {
        name: 'wise-collapse-html',
        setup(build) {
            build.onLoad({ filter: /\.js$/ }, async (entry) => {
                const source = await fs.promises.readFile(entry.path, 'utf8');
                return { contents: collapseHtmlWhitespace(source, mode), loader: 'js' };
            });
        },
    };
}

// ── 构建 ──────────────────────────────────────────────────────

function jsOptions(cfg) {
    const mode = collapseArg ?? cfg.collapse;
    return {
        entryPoints: [JS_ENTRY],
        outfile: cfg.file,
        bundle: true,
        format: 'esm',
        platform: 'browser',
        target: ['es2020'],
        charset: 'utf8',
        minify: cfg.minify,
        sourcemap: cfg.sourcemap,
        // 不内嵌原始源码：wise-*.js 源码与产物同包发布（见 public/statics/.gitignore），
        // DevTools 会按 sources 里的相对路径到同目录取源文件，map 体积可减半。
        // 若将来改为"只发 bundle"的精简包，此处必须改回 true，否则映射不到源码。
        sourcesContent: false,
        legalComments: 'none',
        logLevel: 'info',
        metafile: true,
        plugins: mode === 'none' ? [] : [collapsePlugin(mode)],
    };
}

function cssOptions(cfg) {
    return {
        entryPoints: [CSS_ENTRY],
        outfile: cfg.file,
        bundle: true,
        minify: cfg.minify,
        sourcemap: cfg.sourcemap,
        sourcesContent: false,
        logLevel: 'warning',
    };
}

/**
 * 校验 libs 下各 ws-* 组件的样式文件是否都已在 ws-components.css 登记。
 *
 * 这是必要的：组件 JS 不 import CSS，登记是样式进入产物的唯一途径，
 * 漏登记不会报错，只会静默丢样式——属于最难排查的一类问题。
 */
function verifyCssRegistry() {
    const registry = fs.readFileSync(CSS_ENTRY, 'utf8');
    const missing = [];

    for (const dir of fs.readdirSync(LIBS_DIR)) {
        if (!dir.startsWith('ws-')) continue;
        if (!fs.existsSync(path.join(LIBS_DIR, dir, `${dir}.css`))) continue;
        const importPath = `../../libs/${dir}/${dir}.css`;
        if (!registry.includes(importPath)) missing.push(dir);
    }

    if (missing.length > 0) {
        console.warn('[build] 警告：以下组件样式未在 ws-components.css 登记，不会进入 wise-bundle.css：');
        for (const name of missing) console.warn(`         - ${name}`);
    }
    return missing.length === 0;
}

/** 人类可读的体积 */
function humanSize(bytes) {
    return `${(bytes / 1024).toFixed(1)} KB`;
}

function relative(file) {
    return path.relative(ROOT, file).replace(/\\/g, '/');
}

/** 打印产物体积与 JS 模块归因 */
function report(results) {
    console.log('');
    console.log(`[build] 构建完成（${isDev ? '仅调试版' : '调试版 + 生产版'}）`);
    for (const { label, file } of results) {
        const size = fs.existsSync(file) ? humanSize(fs.statSync(file).size) : '缺失';
        console.log(`  ${label.padEnd(4)} ${relative(file).padEnd(34)} ${size}`);
    }

    const prod = results.find((r) => r.metafile);
    if (!prod?.metafile) return;

    const inputs = Object.entries(prod.metafile.inputs)
        .map(([file, meta]) => ({
            file: relative(file),
            bytes: meta.bytesInOutput,
        }))
        .filter((x) => x.bytes > 0)
        .sort((a, b) => b.bytes - a.bytes);

    const total = inputs.reduce((sum, x) => sum + x.bytes, 0);
    console.log('');
    console.log('  生产版 JS 体积 TOP 10：');
    for (const item of inputs.slice(0, 10)) {
        const pct = ((item.bytes / total) * 100).toFixed(1);
        console.log(`    ${item.file.padEnd(48)} ${humanSize(item.bytes).padStart(9)}  ${pct}%`);
    }
    console.log('');
}

async function main() {
    verifyCssRegistry();

    const jsTargets = isDev ? JS_OUTPUTS.filter((t) => t.dev) : JS_OUTPUTS;
    const cssTargets = isDev ? CSS_OUTPUTS.filter((t) => t.dev) : CSS_OUTPUTS;

    if (isWatch) {
        const jsCtx = await esbuild.context(jsOptions(jsTargets[0]));
        const cssCtx = await esbuild.context(cssOptions(cssTargets[0]));
        await Promise.all([jsCtx.watch(), cssCtx.watch()]);
        console.log('[build] 监听中：');
        console.log(`  ${relative(JS_ENTRY)} -> ${relative(jsTargets[0].file)}`);
        console.log(`  ${relative(CSS_ENTRY)} -> ${relative(cssTargets[0].file)}`);
        console.log('[build] Ctrl+C 退出');
        return;
    }

    const results = [];

    for (const cfg of jsTargets) {
        const result = await esbuild.build(jsOptions(cfg));
        results.push({ label: `JS ${cfg.label}`, file: cfg.file, metafile: cfg.dev ? null : result.metafile });
    }
    for (const cfg of cssTargets) {
        await esbuild.build(cssOptions(cfg));
        results.push({ label: `CSS ${cfg.label}`, file: cfg.file, metafile: null });
    }

    report(results);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
