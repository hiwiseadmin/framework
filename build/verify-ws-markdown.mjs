/**
 * ============================================================
 * ws-markdown 双端一致性校验（JS 组件 ↔ PHP 直出）
 * ============================================================
 *
 * 用法：
 *   node build/verify-ws-markdown.mjs
 *   node build/verify-ws-markdown.mjs --verbose   # 打印每例的产出
 *
 * ── 为什么要它 ──────────────────────────────────────────────
 * 同一篇文章有两条渲染路径：
 *   前台（浏览器）→ public/statics/libs/ws-markdown/ws-markdown.js
 *   SEO 直出（PHP）→ plugins/wisedocs/service/MarkdownRenderer.php
 * 后者是前者的**一比一移植**。改了一边忘另一边，表现是「页面上正常、
 * 查看源代码/搜索引擎看到的是另一份内容」—— 不报错，也几乎没人会发现。
 *
 * 所以两边的判定口径必须是同一份语料：同一段 Markdown 渲染出来的
 * **结构**要一致（允许 JS 侧多出纯装饰性的包装，见 normalize）。
 *
 * ## 覆盖
 *   1. GFM 任务列表：未勾选 / 已勾选 / 混合 / 嵌套
 *   2. 任务项误判防线：`[x]` 后没有空白时**不算**任务项
 *   3. GFM 表格 + 列对齐（:-- / :-: / --:）
 *   4. 安全：转义后的 `<script>` 不得变成标签；`javascript:` 不得进 href
 *
 * @module verify-ws-markdown
 */

import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { render as renderJs } from '../public/statics/libs/ws-markdown/ws-markdown.js';

const VERBOSE = process.argv.includes('--verbose');
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PHP_RENDERER = path.join(__dirname, '..', 'plugins', 'wisedocs', 'service', 'MarkdownRenderer.php');

/**
 * 调 PHP 侧渲染
 *
 * @param {string} markdown
 * @returns {string}
 */
function renderPhp(markdown) {
    const code = [
        `require ${JSON.stringify(PHP_RENDERER)};`,
        'echo (new \\plugin\\wisedocs\\service\\MarkdownRenderer())',
        '    ->render(stream_get_contents(STDIN));',
    ].join('\n');

    return execFileSync('php', ['-r', code], { input: markdown, encoding: 'utf8' });
}

/**
 * 归一化：抹掉"纯装饰"的实现差异，只比较结构
 *
 *   - JS 侧表格外面多一层 `<div class="ws-md-tablewrap">`（横向滚动用）
 *   - 两侧换行/缩进可能不同
 *
 * 代码块不在比较范围内：JS 会插「复制」按钮和语言标签，那是**组件级**
 * 增强（PHP 直出不需要按钮），属于刻意差异。
 *
 * @param {string} html
 * @returns {string}
 */
function normalize(html) {
    return String(html)
        .replace(/<div class="ws-md-tablewrap">([\s\S]*?)<\/div>/g, '$1')
        .replace(/>\s+</g, '><')
        .trim();
}

/** @type {Array<{name:string, md:string, must?:string[], mustNot?:string[]}>} */
const CASES = [
    {
        name: '任务列表（未勾选 + 已勾选）',
        md: '- [ ] 待办项\n- [x] 已完成项',
        must: ['<ul class="ws-md-tasklist">', 'data-checked="true"', 'type="checkbox"', 'disabled'],
        mustNot: ['[ ]', '[x]'],
    },
    {
        name: '任务列表（混合普通条目）',
        md: '- [ ] 带勾选\n- 普通条目\n- [X] 大写 X',
        must: ['ws-md-tasklist', 'data-checked="true"'],
    },
    {
        name: '任务列表（嵌套）',
        md: '- [ ] 父项\n  - [x] 子项',
        must: ['ws-md-tasklist', 'data-checked="true"', 'ws-md-task__body'],
    },
    {
        name: '任务项误判防线（[x] 后无空白）',
        md: '- [x]abc 不是任务项',
        mustNot: ['ws-md-tasklist', 'type="checkbox"'],
    },
    {
        name: '表格列对齐',
        md: '| 左 | 中 | 右 |\n|:---|:---:|---:|\n| 1 | 2 | 3 |',
        must: ['text-align:left', 'text-align:center', 'text-align:right', '<table>'],
    },
    {
        name: '安全：脚本标签被转义',
        md: '- [ ] <script>alert(1)</script>',
        mustNot: ['<script'],
    },
    {
        name: '安全：javascript: 链接不进 href',
        md: '[点我](javascript:alert(1))',
        mustNot: ['href="javascript', 'javascript:alert(1)'],
    },
];

let fail = 0;
const verbose = (label, value) => {
    if (VERBOSE) console.log(`  · ${label}: ${JSON.stringify(value)}`);
};

console.log('[verify-ws-markdown] JS 组件 ↔ PHP 直出 一致性\n');

for (const kase of CASES) {
    const problems = [];

    let js = '';
    let php = '';
    try {
        js = renderJs(kase.md);
        php = renderPhp(kase.md);
    } catch (err) {
        problems.push('渲染抛异常: ' + err.message);
    }

    if (!problems.length) {
        verbose('JS ', js);
        verbose('PHP', php);

        // 1) 两端结构一致（归一化后逐字比较）
        if (normalize(js) !== normalize(php)) {
            problems.push(`两端输出不一致\n    JS : ${normalize(js)}\n    PHP: ${normalize(php)}`);
        }

        // 2) 期望出现的标记
        for (const needle of kase.must || []) {
            if (js.indexOf(needle) === -1) problems.push(`JS 缺少 ${needle}`);
            if (php.indexOf(needle) === -1) problems.push(`PHP 缺少 ${needle}`);
        }

        // 3) 不许出现的标记
        for (const needle of kase.mustNot || []) {
            if (js.indexOf(needle) !== -1) problems.push(`JS 出现了不该有的 ${needle}`);
            if (php.indexOf(needle) !== -1) problems.push(`PHP 出现了不该有的 ${needle}`);
        }
    }

    if (problems.length) {
        fail++;
        console.log(`[FAIL] ${kase.name}`);
        for (const p of problems) console.log('       ' + p);
    } else {
        console.log(`[PASS] ${kase.name}`);
    }
}

console.log('');
console.log(fail === 0
    ? `[ws-markdown] 全部通过：${CASES.length} 组语料 ✓`
    : `[ws-markdown] 失败 ${fail} 组 ✗`);
process.exit(fail === 0 ? 0 : 1);
