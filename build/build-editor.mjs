/**
 * WiseEditor 编辑器构建脚本（esbuild）
 *
 * 两个互不相干的产物，一次构建：
 *
 * ┌─ ① 引擎 bundle ────────────────────────────────────────────┐
 * │ 入口：build/editor/tiptap-entry.js                          │
 * │ 输出：plugins/wiseeditor/statics/js/vendor/tiptap.bundle.js │
 * │ 形态：ES module（页面用 type="module" 引；适配器动态 import） │
 * └────────────────────────────────────────────────────────────┘
 * ┌─ ② 示例页「直开版」bundle（见下方「为什么还要一份」）─────┐
 * │ 入口：build/editor/example-entry.js                         │
 * │ 输出：plugins/wiseeditor/example/dist/wiseeditor.example.js │
 * │ 形态：classic（IIFE）自包含单文件（含引擎），双击 html 即可  │
 * └────────────────────────────────────────────────────────────┘
 *
 * 用法：
 *   npm run build:editor
 *   node build/build-editor.mjs --dev       只出调试版
 *   node build/build-editor.mjs --no-sync   只构建，不往 public/ 同步
 *
 * ── 为什么还要 ② 一份 ───────────────────────────────────────
 * `plugins/wiseeditor/example/` 下的示例页原本只能靠
 * `npm run serve:editor` 起的静态服务打开 —— 因为它引的是站点绝对路径
 * `/statics/...`，且用的是 ES module。`file://` 下这两个前提都不成立
 * （绝对路径会落到磁盘根目录、模块脚本会被 CORS 拦掉）。
 * ② 把**同一个入口**（wiseeditor-init.js）连同适配器链打成 classic 单文件，
 * 于是示例页可以用相对路径 + 普通 script 标签，双击即可测。
 * 它落在 `example/` 下，不在 `statics/` 之下，因此不会被发布到 public/。
 *
 * ── 为什么单独一个脚本 ──────────────────────────────────────
 * build/build-js.mjs 打的是后台内核 wise-bundle（jQuery/Bootstrap 走 window 全局，
 * 输出必须落在 statics 直接子目录）。编辑器的 TipTap bundle 与之无关：
 * 依赖全部来自 npm、产物自包含、输出落在插件 statics 下，因此独立成脚本，
 * 互不干扰，也便于单独重建。
 *
 * ── 产物落点与「发布」这一步 ────────────────────────────────
 * 构建产物写在 plugins/wiseeditor/statics/js/vendor/，而浏览器读的是
 * /statics/plugins/wiseeditor/js/vendor/ —— 两者之间隔着一道发布（复制）。
 * 框架侧负责这件事的是 AssetManager::copyAssets()，但它**只在插件
 * 安装/启用/升级时触发**，没有独立的同步命令；改完前端不重新启用插件，
 * 线上就会静默沿用旧副本（全新插件未启用则是整目录 404）。
 * 为免踩这个坑，本脚本构建完会自动做一次等价复制（合并覆盖、不删多余项），
 * 跳过备份与点文件；不需要时用 --no-sync 关掉。
 *
 * ── 产物约定 ────────────────────────────────────────────────
 *   tiptap.bundle.js      生产版：压缩 + linked sourcemap。后台默认加载这一份。
 *                         **bundle 与 .map 都要入库**（见 statics/js/vendor/.gitignore）。
 *   tiptap.bundle.dev.js  调试版：不压缩 + linked sourcemap，仅本地排障用，不入库。
 *
 * ── sourcemap 为什么必须自包含 ──────────────────────────────
 * `src/.gitattributes` 与 `public/statics/.gitignore` 都规定「statics 下的构建产物与
 * sourcemap 随包发布」，即 map 必须进版本库、随 dist 分发。但本 bundle 与内核
 * wise-bundle 的形态不同，照抄会失效：
 *   内核  = sourcesContent:false + 把 wise-*.js 源码一起发布，
 *           DevTools 按 map 里 sources 的相对路径到同目录取源码；
 *   本包  = sources 共 367 项，366 项指向 node_modules/@tiptap/**、prosemirror-*，
 *           而 node_modules 不入库 → 线上 DevTools 拉不到源文件，map 等于废纸。
 * 因此这里用 sourcesContent:true，把源码内嵌进 map：自包含、不依赖任何外部文件，
 * 本地调试 / 线上排障 / 开源后使用者三条路径行为一致。
 * 代价：生产版 map 从约 702 KB 涨到约 2.2 MB。这是能真正还原源码的唯一形态，值得。
 * 另外生产版用 linked（写 //# sourceMappingURL=），DevTools 打开时才按需拉取，
 * 不打开不会产生额外请求。
 *
 * ── 安全约束（改动前务必阅读）──────────────────────────────
 * 1. 后台 CSP 为 script-src 'self'：产物必须自托管，禁止 CDN 与内联脚本，
 *    因此这里是"打包成单文件"而不是"运行时按需拉取依赖"。
 * 2. 所有 @tiptap/* 必须锁定同一版本线（package.json 用 --save-exact）。
 *    自 3.30 起 StarterKit 会把自己内嵌的扩展钉到自身确切版本，
 *    版本漂移会导致仓库里出现两份 @tiptap/core。
 * 3. prosemirror-model 只能有一份，且 >= 1.25.12；prosemirror-view >= 1.42.5。
 *    粘贴场景的 XSS 修复在 view 1.42.3 落地，但它依赖 model 1.25.12 的属性校验，
 *    一旦 model 出现旧的第二份副本，该修复会被静默关掉。
 *    esbuild 单文件打包天然只保留一份，这里额外做一次检查并告警。
 */

import * as esbuild from 'esbuild';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const ENTRY = path.join(__dirname, 'editor', 'tiptap-entry.js');
const PLUGIN_STATICS = path.join(ROOT, 'plugins', 'wiseeditor', 'statics');
const OUT_DIR = path.join(PLUGIN_STATICS, 'js', 'vendor');
const PUBLIC_STATICS = path.join(ROOT, 'public', 'statics', 'plugins', 'wiseeditor');

// ② 示例页「直开版」：落点在 example/ 下（开发期资产，不进 public/）
const EXAMPLE_ENTRY = path.join(__dirname, 'editor', 'example-entry.js');
const EXAMPLE_OUT_DIR = path.join(ROOT, 'plugins', 'wiseeditor', 'example', 'dist');
const EXAMPLE_OUT = path.join(EXAMPLE_OUT_DIR, 'wiseeditor.example.js');

const isDev = process.argv.includes('--dev');
const noSync = process.argv.includes('--no-sync');

const OUTPUTS = [
    {
        dev: false,
        label: '生产版',
        file: path.join(OUT_DIR, 'tiptap.bundle.js'),
        minify: true,
        // linked：写入 //# sourceMappingURL=，DevTools 打开时按需拉取。
        // 与内核 wise-bundle 的 external 不同 —— 那套靠"同目录放着源码"，
        // 而本源码在 node_modules（不入库），只能靠 map 自带 sourcesContent。
        sourcemap: 'linked',
    },
    {
        dev: true,
        label: '调试版',
        file: path.join(OUT_DIR, 'tiptap.bundle.dev.js'),
        minify: false,
        sourcemap: 'linked',
    },
];

/** 读取 node_modules 中某包的实际版本（找不到返回 null） */
function readVersion(name, from = ROOT) {
    const pkg = path.join(from, 'node_modules', name, 'package.json');
    if (!fs.existsSync(pkg)) return null;
    try {
        return JSON.parse(fs.readFileSync(pkg, 'utf8')).version ?? null;
    } catch {
        return null;
    }
}

/** 语义化版本比较，只处理 major.minor.patch */
function gte(version, floor) {
    if (!version) return false;
    const a = String(version).split('.').map(Number);
    const b = String(floor).split('.').map(Number);
    for (let i = 0; i < 3; i++) {
        const x = a[i] ?? 0;
        const y = b[i] ?? 0;
        if (x !== y) return x > y;
    }
    return true;
}

/** 递归查找重复的 prosemirror-model / prosemirror-view 副本 */
function findDuplicateCopies(name) {
    const hits = [];
    const walk = (dir, depth) => {
        if (depth > 6) return;
        let entries = [];
        try {
            entries = fs.readdirSync(dir, { withFileTypes: true });
        } catch {
            return;
        }
        for (const e of entries) {
            if (!e.isDirectory()) continue;
            if (e.name === name) {
                hits.push(path.join(dir, e.name));
                continue;
            }
            if (e.name === 'node_modules') walk(path.join(dir, e.name), depth + 1);
            else if (depth > 0 && e.name.startsWith('@')) walk(path.join(dir, e.name), depth + 1);
            else if (depth > 0) {
                const nested = path.join(dir, e.name, 'node_modules');
                if (fs.existsSync(nested)) walk(nested, depth + 1);
            }
        }
    };
    walk(path.join(ROOT, 'node_modules'), 0);
    return hits;
}

/**
 * 校验 ProseMirror 依赖：单副本 + 版本下限。
 * 返回是否通过；失败时打印明细但不阻断构建（交由人工判断）。
 */
function verifyProsemirror() {
    const checks = [
        { name: 'prosemirror-view', floor: '1.42.5' },
        { name: 'prosemirror-model', floor: '1.25.12' },
    ];

    let ok = true;
    for (const c of checks) {
        const version = readVersion(c.name);
        const copies = findDuplicateCopies(c.name);
        const versionOk = gte(version, c.floor);
        const single = copies.length <= 1;

        if (!versionOk || !single) {
            ok = false;
        }

        const flag = versionOk && single ? 'OK ' : 'WARN';
        console.log(`[editor] ${flag} ${c.name}@${version ?? '缺失'} (要求 >= ${c.floor}) 副本数=${copies.length}`);
        if (!single) {
            for (const p of copies) console.log(`         - ${path.relative(ROOT, p)}`);
        }
    }

    if (!ok) {
        console.warn('[editor] 警告：ProseMirror 版本不满足要求，粘贴相关的 XSS 修复可能失效。');
        console.warn('         执行 npm dedupe，或在 package.json 增加 overrides 后重新安装。');
    }
    return ok;
}

function humanSize(bytes) {
    return `${(bytes / 1024).toFixed(1)} KB`;
}

function relative(file) {
    // metafile.inputs 的键是「相对当前工作目录」的路径，先还原成绝对路径再相对 ROOT，
    // 否则换个目录执行本脚本就会得到 ../.. 之类的诡异路径。
    return path.relative(ROOT, path.resolve(ROOT, file)).replace(/\\/g, '/');
}

/** 同步时要跳过的条目：备份（{名}.YYYYMMDD.N）与点文件（.gitignore / .DS_Store 等） */
function shouldSkipEntry(name) {
    return /\.\d{8}\.\d+$/.test(name) || name.startsWith('.');
}

/**
 * 把 plugins/wiseeditor/statics/ 镜像到 public/statics/plugins/wiseeditor/。
 *
 * 语义刻意与框架 AssetManager::copyAssets() 对齐：**合并复制**、覆盖同名文件、
 * **不删除**目标里的多余项（增量、幂等），因此手工发布与插件启用两条路径结果一致。
 * 另外跳过备份与点文件 —— AssetManager 是全量复制，会把 `*.20261011.1`
 * 这类备份一起带进公开目录，手工这一步顺手挡掉。
 *
 * @returns {{copied: number, skipped: string[]}}
 */
function syncToPublic() {
    let copied = 0;
    const skipped = [];

    const walk = (src, dst) => {
        fs.mkdirSync(dst, { recursive: true });
        for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
            if (shouldSkipEntry(entry.name)) {
                skipped.push(path.relative(PLUGIN_STATICS, path.join(src, entry.name)).replace(/\\/g, '/'));
                continue;
            }
            const from = path.join(src, entry.name);
            const to = path.join(dst, entry.name);
            if (entry.isDirectory()) walk(from, to);
            else {
                fs.copyFileSync(from, to);
                copied++;
            }
        }
    };

    walk(PLUGIN_STATICS, PUBLIC_STATICS);
    return { copied, skipped };
}

/**
 * 构建示例页「直开版」单文件（classic / IIFE，**自包含**）。
 *
 * ── 为什么必须自包含 ────────────────────────────────────────
 * ① 的产物是 **ES module**（结尾带 `export{...}`），file:// 下既不能被
 * `<script>` 经典加载、也不能被 `import()` 动态加载 —— 只能整体打进本产物。
 * 好在引擎侧本来就是"导入即挂 `globalThis.TipTap`"的设计，内联后
 * `loadVendor()` 的短路分支照样命中，行为与线上一致。
 * 代价：约 460 KB（含压缩后的引擎）。这是「双击即用」唯一可行的形态。
 *
 * ── 两个关键选项 ────────────────────────────────────────────
 * 1. `format: 'iife'` —— `file://` 下只有经典脚本能加载，ES module 会被 CORS 拦掉。
 * 2. `define: { 'import.meta.url': 'location.href' }` ——
 *    wiseeditor.js 顶层有 `new URL('./wiseeditor-tiptap.js', import.meta.url)`，
 *    而 iife 下 esbuild 会把 import.meta 置空 → `new URL(x, undefined)` 直接抛
 *    TypeError，整个入口当场崩。给个合法基址即可规避。
 *    该分支在本产物里是死代码：两个适配器都由入口（wiseeditor-init.js）预注册，
 *    `ensureAdapter()` 永远命中缓存。
 *
 * ── 落点与发布 ──────────────────────────────────────────────
 * 产物落在 `example/dist/`，而 syncToPublic() 只走 `statics/`，
 * 因此它既不会被同步到 public/、也不会被 AssetManager 发布。
 * 又因为 example/ 整体在 .gitattributes 里 export-ignore，它也不会进分发包。
 *
 * ── 它是生成物，改完适配器必须重建 ──────────────────────────
 * 与 ① 同一个规则：改了 wiseeditor*.js 却没重跑 `npm run build:editor`，
 * 直开版页面就会静默沿用旧副本（verify-editor 用例 4 只能验证"没坏"，
 * 验不出"是不是最新"）。本产物**入库**，是为了 clone 下来就能双击测试。
 */
async function buildExampleBundle() {
    if (!fs.existsSync(EXAMPLE_ENTRY)) {
        console.error(`[editor] 示例页入口不存在: ${EXAMPLE_ENTRY}`);
        process.exit(1);
    }

    fs.mkdirSync(EXAMPLE_OUT_DIR, { recursive: true });

    await esbuild.build({
        entryPoints: [EXAMPLE_ENTRY],
        outfile: EXAMPLE_OUT,
        bundle: true,
        format: 'iife',
        platform: 'browser',
        target: ['es2020'],
        charset: 'utf8',
        minify: true,
        legalComments: 'none',
        define: { 'import.meta.url': 'location.href' },
        logLevel: 'warning',
    });

    console.log(`[editor] 示例页直开版 ${relative(EXAMPLE_OUT)} ${humanSize(fs.statSync(EXAMPLE_OUT).size)}`);
}

async function main() {
    if (!fs.existsSync(ENTRY)) {
        console.error(`[editor] 入口不存在: ${ENTRY}`);
        process.exit(1);
    }

    fs.mkdirSync(OUT_DIR, { recursive: true });

    verifyProsemirror();

    const targets = isDev ? OUTPUTS.filter((t) => t.dev) : OUTPUTS;

    for (const cfg of targets) {
        const result = await esbuild.build({
            entryPoints: [ENTRY],
            outfile: cfg.file,
            bundle: true,
            format: 'esm',
            platform: 'browser',
            target: ['es2020'],
            charset: 'utf8',
            minify: cfg.minify,
            sourcemap: cfg.sourcemap,
            // 必须 true：sources 指向不入库的 node_modules，不内嵌源码则线上解析不了。
            // 详见文件头「sourcemap 为什么必须自包含」。
            sourcesContent: true,
            legalComments: 'none',
            logLevel: 'info',
            metafile: true,
        });

        console.log(`[editor] ${cfg.label} ${relative(cfg.file)} ${humanSize(fs.statSync(cfg.file).size)}`);

        if (cfg.dev) continue;

        // 注意：部分 esbuild 构建（如 rolldown 系分发）不会在 metafile.inputs 里
        // 产出 bytesInOutput，此时退回用输入文件自身体积（meta.bytes）排序，
        // 保证体积报告不会静默变成空表。
        const inputs = Object.entries(result.metafile.inputs)
            .map(([file, meta]) => ({ file: relative(file), bytes: meta.bytesInOutput ?? meta.bytes ?? 0 }))
            .filter((x) => x.bytes > 0)
            .sort((a, b) => b.bytes - a.bytes);
        const total = inputs.reduce((sum, x) => sum + x.bytes, 0);
        console.log('');
        console.log('[editor] 体积 TOP 8：');
        for (const item of inputs.slice(0, 8)) {
            console.log(`  ${item.file.padEnd(56)} ${humanSize(item.bytes).padStart(9)}  ${((item.bytes / total) * 100).toFixed(1)}%`);
        }
        console.log('');
    }

    // ② 示例页「直开版」：与 ① 无关，只构建、不发布（它本就不该进 public/）
    console.log('');
    await buildExampleBundle();

    // 构建完立刻发布，避免"改了不生效"（见文件头「产物落点与发布这一步」）
    if (noSync) {
        console.log('[editor] 已跳过 public/ 同步（--no-sync）：浏览器读到的仍是上一次发布的副本');
        return;
    }

    const { copied, skipped } = syncToPublic();
    console.log(`[editor] 已同步 ${relative(PUBLIC_STATICS)}/：${copied} 个文件`
        + (skipped.length ? `，跳过备份/点文件 ${skipped.length} 个（${skipped.join(', ')}）` : ''));
    console.log('[editor] 等价于插件 install/enable 时的 AssetManager::copyAssets()；'
        + '发布到别的机器时仍需走一次插件启用。');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
