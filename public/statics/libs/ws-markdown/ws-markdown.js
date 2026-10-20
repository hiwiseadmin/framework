/**
 * ws-markdown — 框架级 Markdown 渲染组件（零依赖 ESM）
 *
 * 定位：框架共享能力，任何后台页面 / 插件 / 包都可直接使用，无需各自 vendor
 * 渲染库。随 wise-bundle.js 一起打包（见 modules/ws/index.js），因此
 * `Wise.ws.WsMarkdown` 在任何业务模块求值前就已可用，无加载时序问题。
 *
 * ── 三层 API ──────────────────────────────────────────────
 *   render(source, opts)      纯函数：Markdown 文本 → HTML 片段
 *   mount(el, source, opts)   渲染到元素 + 绑定复制按钮（幂等）
 *   stream(el, opts)          流式 writer：push(chunk) / end(finalText)
 *                             内部封装节流、未闭合围栏容错、贴底滚动
 *
 * ── 设计取向（安全 > 完整度）────────────────────────────────
 *   1. 「先转义、再解析」——原始文本先做 HTML 实体转义，渲染过程中只生成本模块
 *      自己产出的标签。因此任意来源内容里的 raw HTML（<script>、<img onerror>、
 *      <iframe> 等）在结构上不可能存活，无需再叠加 sanitizer，也不存在
 *      「sanitizer 配错一处即失守」的风险面。
 *   2. '>' 刻意不转义：'<' 与 '&' 已转义时无法构造任何标签或实体，
 *      保留原字符可让引用块（行首 '> '）解析保持自然。
 *   3. 链接 URL 走协议白名单（http / https / mailto / tel + 相对路径），
 *      javascript: / data: / vbscript: 等一律降级为纯文本。
 *      URL 先做实体解码与控制字符剥离后再判定，防 `java&#115;cript:` 之类绕过。
 *   4. 图片语法降级为链接渲染：内容可能来自模型或第三方接口，
 *      <img src> 会成为对外发出请求的追踪像素（泄露后台访问者 IP）。
 *   5. 支持子集：围栏代码块（语言标注 + 复制按钮）、行内代码、#~#### 标题、
 *      粗体 / 斜体 / 删除线、有序 / 无序列表（含嵌套）、引用、分割线、
 *      链接、GFM 表格、段落与软换行。
 *   6. 流式容错：未闭合的围栏代码块自动补齐，否则流式输出中途整段会先
 *      退化为字面量再「跳变」。
 *
 * 不使用第三方库的原因：CSP 为 script-src 'self'（禁止 CDN），且本仓库前端
 * 基调为零外部依赖；后台场景的 Markdown 子集明确、边界可控。
 *
 * @module ws-markdown
 * @version 1.0.0
 */

/** 根类名：样式全部收敛在 .ws-md 作用域内（见 ws-markdown.css） */
const CLASS_ROOT = 'ws-md';

const ESC_MAP = { '&': '&amp;', '<': '&lt;', '"': '&quot;', "'": '&#39;' };

/** 内联元素可直接反斜杠转义的字符 */
const ESCAPABLE = '\\`*_~[]()#>-+.!|';

/** 递归深度上限（引用块 / 嵌套列表），防病态输入造成的深递归 */
const MAX_DEPTH = 6;

const LIST_RE = /^( *)([-*+]|\d{1,9}[.)]) +(.*)$/;
const FENCE_OPEN_RE = /^ {0,3}(`{3,}|~{3,}) ?([A-Za-z0-9+#._-]{1,20})?/;
const HEADING_RE = /^ {0,3}(#{1,6}) +(.*)$/;
const BLOCKQUOTE_RE = /^ {0,3}> ?(.*)$/;

/** 默认选项 */
const DEFAULTS = {
    /** 代码块是否渲染「复制」按钮 */
    copyButton: true,
    /** 复制按钮文案 */
    copyLabel: '复制',
    /** 复制成功后的按钮文案 */
    copiedLabel: '已复制',
    /** 外链是否新窗口打开（内链 / 锚点始终当前窗口） */
    externalLinkTarget: true,
    /** stream() 的重渲染节流间隔（ms） */
    throttle: 80,
};

/**
 * 当前渲染选项
 *
 * 用模块级变量而非逐层传参：render 内部只有纯同步的块级/行内递归，
 * 不会重入公共入口（render / mount / stream 互不嵌套调用），
 * 单线程模型下不存在串扰。render() 用 try/finally 保证异常时也复原。
 */
let OPT = DEFAULTS;

/**
 * 归一化选项
 * @param {Object} [options]
 * @returns {Object}
 */
function resolveOptions(options) {
    return options ? Object.assign({}, DEFAULTS, options) : DEFAULTS;
}

/**
 * HTML 实体转义（不做 '>' 转义，见文件头说明）
 * @param {*} str
 * @returns {string}
 */
function escapeHTML(str) {
    return String(str == null ? '' : str).replace(/[&<"']/g, (c) => ESC_MAP[c]);
}

/**
 * 还原 HTML 实体（含数字实体），仅用于「判定」场景
 *
 * 判定后仍输出转义形态、不参与 HTML 拼接。之所以连数字实体一起还原：
 * `[x](java&#115;cript:alert(1))` 这类写法在属性里解码后并非合法 scheme（浏览器
 * 按相对路径处理，实际不可执行），但一律按「可疑」拦掉更稳，避免依赖浏览器差异。
 *
 * @param {string} str
 * @returns {string}
 */
function decodeEntities(str) {
    let out = String(str);
    // 反复解码至稳定：转义会把 '&' 变成 '&amp;'，一趟解码后会残留 '&#115;'，
    // 需第二趟才还原成 's' —— 正是协议绕过要防的那一类写法。三趟足够（至多两层嵌套）。
    for (let pass = 0; pass < 3; pass++) {
        const next = decodeOnePass(out);
        if (next === out) break;
        out = next;
    }
    return out;
}

/**
 * 单趟实体解码
 * @param {string} str
 * @returns {string}
 */
function decodeOnePass(str) {
    return str.replace(
        /&(?:#(\d{1,7})|#[xX]([0-9a-fA-F]{1,6})|(amp|lt|gt|quot|#39));/g,
        (match, dec, hex, named) => {
            if (dec !== undefined || hex !== undefined) {
                const code = dec !== undefined ? Number(dec) : parseInt(hex, 16);
                if (!Number.isFinite(code) || code < 0 || code > 0x10ffff) return match;
                try { return String.fromCodePoint(code); } catch (_) { return match; }
            }
            return named === 'amp' ? '&'
                : named === 'lt' ? '<'
                    : named === 'gt' ? '>'
                        : named === 'quot' ? '"' : "'";
        }
    );
}

/**
 * 是否为外部绝对链接（http/https/mailto/tel）——仅此类新窗口打开
 * @param {string} url
 * @returns {boolean}
 */
function isExternalUrl(url) {
    const probe = decodeEntities(url).replace(/[\u0000-\u0020\u007f]+/g, '');
    return /^(https?|mailto|tel):/i.test(probe);
}

/**
 * 链接协议白名单校验
 * @param {string} raw 已转义的 URL 原文
 * @returns {string} 通过校验时返回可安全写入 href 的字符串，否则返回 ''
 */
function safeUrl(raw) {
    const url = String(raw == null ? '' : raw).trim();
    if (!url) return '';
    // 剥离所有控制字符与空白后再判定协议，避免 `java\nscript:` 之类绕过
    const probe = decodeEntities(url).replace(/[\u0000-\u0020\u007f]+/g, '');
    if (probe.startsWith('//')) return '';                                    // 协议相对 → 指向外域
    if (/^[a-z][a-z0-9+.\-]*:/i.test(probe)) {
        return /^(https?|mailto|tel):/i.test(probe) ? url : '';                // 绝对 URL 仅放行白名单协议
    }
    return url;                                                               // 相对路径 / 锚点
}

/**
 * 查找闭合标记，要求标记前一个字符非空白（避免 `* a * b` 被误判为强调）
 * @param {string} text
 * @param {number} from
 * @param {string} token
 * @returns {number} 下标，未找到返回 -1
 */
function findClose(text, from, token) {
    let idx = text.indexOf(token, from);
    while (idx !== -1) {
        if (idx > from && !/\s/.test(text[idx - 1])) return idx;
        idx = text.indexOf(token, idx + 1);
    }
    return -1;
}

/**
 * 从 at 处的 '[' 起读取 `[label](url)`，支持一层配对括号
 * @param {string} text
 * @param {number} at
 * @returns {{label:string,url:string,end:number}|null}
 */
function readLink(text, at) {
    let depth = 0;
    let j = at;
    let label = '';

    for (; j < text.length; j++) {
        const ch = text[j];
        // 保留反斜杠本身，交由 inline() 统一处理转义，避免二次解析
        if (ch === '\\' && j + 1 < text.length) { label += ch + text[j + 1]; j++; continue; }
        if (ch === '[') { depth++; if (depth === 1) continue; }
        if (ch === ']') { depth--; if (depth === 0) break; }
        label += ch;
    }
    if (depth !== 0 || text[j] !== ']' || text[j + 1] !== '(') return null;

    let k = j + 2;
    let d = 1;
    let url = '';
    for (; k < text.length; k++) {
        const ch = text[k];
        if (ch === '\\') { url += text[k + 1] || ''; k++; continue; }
        if (ch === '(') d++;
        if (ch === ')') { d--; if (d === 0) break; }
        url += ch;
    }
    if (d !== 0) return null;

    return { label, url: url.trim().split(/\s+/)[0] || '', end: k + 1 };
}

/**
 * 行内元素渲染（单趟扫描，强调内容递归解析）
 * @param {string} text 已转义文本
 * @returns {string}
 */
function inline(text) {
    let out = '';
    let i = 0;
    const n = text.length;

    while (i < n) {
        const c = text[i];

        // 反斜杠转义
        if (c === '\\' && i + 1 < n && ESCAPABLE.includes(text[i + 1])) {
            out += text[i + 1];
            i += 2;
            continue;
        }

        // 软换行 → <br>（符合文档流阅读习惯）
        if (c === '\n') { out += '<br>'; i++; continue; }

        // 行内代码
        if (c === '`') {
            const run = /^`+/.exec(text.slice(i))[0].length;
            const close = text.indexOf('`'.repeat(run), i + run);
            if (close !== -1) {
                out += `<code>${text.slice(i + run, close)}</code>`;
                i = close + run;
                continue;
            }
            out += c; i++; continue;
        }

        // 图片 → 按链接渲染（防追踪像素）
        if (c === '!' && text[i + 1] === '[') {
            const link = readLink(text, i + 1);
            if (link) { out += renderLink(link); i = link.end; continue; }
            out += c; i++; continue;
        }

        // 链接
        if (c === '[') {
            const link = readLink(text, i);
            if (link) { out += renderLink(link); i = link.end; continue; }
            out += c; i++; continue;
        }

        // 强调：***x*** / **x** / __x__ / ~~x~~ 优先于 *x* / _x_
        // 单词内的下划线（如 snake_case）不作为强调起始标记
        const isMarker = c === '*' || c === '_' || c === '~';
        const intrawordUnderscore = c === '_' && i > 0 && /[\w]/.test(text[i - 1]);
        if (isMarker && !intrawordUnderscore && text[i + 1] === c) {
            const triple = c !== '~' && text[i + 2] === c;
            const width = triple ? 3 : 2;
            const close = findClose(text, i + width, c.repeat(width));
            const inner = close === -1 ? '' : text.slice(i + width, close);
            if (close !== -1 && inner && !/^\s/.test(inner) && !/\s$/.test(inner)) {
                const body = inline(inner);
                const tag = c === '~' ? 'del' : 'strong';
                out += triple ? `<strong><em>${body}</em></strong>` : `<${tag}>${body}</${tag}>`;
                i = close + width;
                continue;
            }
        }

        if ((c === '*' || c === '_') && !intrawordUnderscore) {
            const close = findClose(text, i + 1, c);
            const inner = close === -1 ? '' : text.slice(i + 1, close);
            if (close !== -1 && inner && !/^\s/.test(inner) && !/\s$/.test(inner)) {
                out += `<em>${inline(inner)}</em>`;
                i = close + 1;
                continue;
            }
        }

        out += c;
        i++;
    }

    return out;
}

/**
 * 渲染链接；协议不通过白名单时降级为纯文本标签
 * @param {{label:string,url:string}} link
 * @returns {string}
 */
function renderLink(link) {
    const label = inline(link.label || link.url) || link.url;
    const url = safeUrl(link.url);
    if (!url) return label;
    const attrs = (OPT.externalLinkTarget && isExternalUrl(url))
        ? ' target="_blank" rel="noopener noreferrer nofollow"'
        : '';
    return `<a href="${url}"${attrs}>${label}</a>`;
}

/**
 * 补齐未闭合的围栏代码块
 *
 * 对已结束的文本同样适用：若来源本身被截断（模型断流 / 接口限长）导致围栏
 * 不配平，补一个空闭合围栏比把剩余内容全部渲染成字面量更符合预期。
 *
 * @param {string} text
 * @returns {string}
 */
function closeOpenFences(text) {
    const openers = text.match(/^ {0,3}(`{3,}|~{3,})/gm) || [];
    if (openers.length % 2 === 0) return text;
    const marker = openers[openers.length - 1].trim();
    return text + (text.endsWith('\n') ? '' : '\n') + marker + '\n';
}

/**
 * 代码块 HTML（语言标签 + 复制按钮）
 *
 * 复制按钮的文案写进 data 属性，事件委托由 bindCopy() 统一处理 ——
 * 消费方无需关心按钮，也无需在重渲染时重新绑定。
 *
 * @param {string} code 已转义代码
 * @param {string} lang
 * @returns {string}
 */
function codeBlockHTML(code, lang) {
    const label = lang ? `<span class="ws-md-lang">${lang}</span>` : '';
    let bar = '';
    if (OPT.copyButton) {
        bar = '<button type="button" class="ws-md-copy" data-ws-md-copy="1"'
            + ` data-ws-md-copied="${escapeHTML(OPT.copiedLabel)}"`
            + ' title="复制代码" aria-label="复制代码">'
            + escapeHTML(OPT.copyLabel) + '</button>';
    }
    return `<div class="ws-md-codeblock"><div class="ws-md-codebar">${label}${bar}</div>`
        + `<pre><code>${code.replace(/\n$/, '')}</code></pre></div>`;
}

/**
 * 是否为分割线
 * @param {string} line
 * @returns {boolean}
 */
function isHr(line) {
    return /^ {0,3}(?:(?:\* *){3,}|(?:- *){3,}|(?:_ *){3,})$/.test(line);
}

/**
 * 是否为表格分隔行（| --- | :--: |）
 * @param {string} line
 * @returns {boolean}
 */
function isTableDelimiter(line) {
    if (!line || !line.includes('-')) return false;
    return /^ {0,3}\|? *:?-+:? *(?:\| *:?-+:? *)*\|? *$/.test(line);
}

/**
 * 拆分表格行
 * @param {string} line
 * @returns {string[]}
 */
function splitRow(line) {
    let s = line.trim();
    if (s.startsWith('|')) s = s.slice(1);
    if (s.endsWith('|')) s = s.slice(0, -1);
    return s.split('|');
}

/**
 * 行首缩进空格数
 * @param {string} line
 * @returns {number}
 */
function indentOf(line) {
    return /^ */.exec(line)[0].length;
}

/**
 * 解析 GFM 表格
 * @param {string[]} lines
 * @param {number} start
 * @returns {{html:string,next:number}|null}
 */
function parseTable(lines, start) {
    const head = splitRow(lines[start]);
    const delim = splitRow(lines[start + 1]);
    if (!head.length || !delim.length) return null;

    const aligns = delim.map((cell) => {
        const t = cell.trim();
        if (/^:-+:$/.test(t)) return 'center';
        if (/^-+:$/.test(t)) return 'right';
        if (/^:-+$/.test(t)) return 'left';
        return '';
    });

    const attr = (idx) => (aligns[idx] ? ` style="text-align:${aligns[idx]}"` : '');

    let html = '<div class="ws-md-tablewrap"><table><thead><tr>';
    head.forEach((cell, idx) => {
        html += `<th${attr(idx)}>${inline(cell.trim())}</th>`;
    });
    html += '</tr></thead><tbody>';

    let i = start + 2;
    for (; i < lines.length; i++) {
        const line = lines[i];
        if (!line.trim() || !line.includes('|')) break;
        const cells = splitRow(line);
        html += '<tr>';
        for (let c = 0; c < head.length; c++) {
            html += `<td${attr(c)}>${inline((cells[c] === undefined ? '' : cells[c]).trim())}</td>`;
        }
        html += '</tr>';
    }

    return { html: html + '</tbody></table></div>', next: i };
}

/**
 * 解析列表（含嵌套：条目续行保持缩进后递归解析）
 * @param {string[]} lines
 * @param {number} start
 * @param {number} depth
 * @returns {{html:string,next:number}}
 */
function parseList(lines, start, depth) {
    const first = LIST_RE.exec(lines[start]);
    const base = first[1].length;
    const ordered = /^\d/.test(first[2]);
    const contentIndent = base + first[2].length + 1;
    const items = [];
    let i = start;

    while (i < lines.length) {
        const line = lines[i];

        // 空行：仅当下一行仍属于本列表（缩进足够）时才继续
        if (!line.trim()) {
            const next = lines[i + 1] || '';
            if (next.trim() && indentOf(next) >= contentIndent) {
                if (items.length) items[items.length - 1].rest.push('');
                i++;
                continue;
            }
            break;
        }

        const m = LIST_RE.exec(line);
        if (m && m[1].length === base && /^\d/.test(m[2]) === ordered) {
            items.push({ text: m[3], rest: [] });
            i++;
            continue;
        }

        // 续行 / 嵌套（缩进不小于条目正文起始列）
        if (indentOf(line) >= contentIndent) {
            if (!items.length) break;
            items[items.length - 1].rest.push(line.slice(contentIndent));
            i++;
            continue;
        }

        break;
    }

    const tag = ordered ? 'ol' : 'ul';
    let html = `<${tag}>`;
    for (const item of items) {
        let inner = inline(item.text);
        const rest = item.rest.filter((l) => l.trim());
        if (rest.length) {
            if (LIST_RE.test(rest[0])) {
                inner += renderBlocks(rest, depth + 1);
            } else {
                inner += `<br>${inline(rest.join('\n'))}`;
            }
        }
        html += `<li>${inner}</li>`;
    }

    return { html: html + `</${tag}>`, next: i };
}

/**
 * 是否为「会让段落中断」的行首标记
 * @param {string} line
 * @returns {boolean}
 */
function startsBlock(line) {
    return !line.trim()
        || FENCE_OPEN_RE.test(line)
        || HEADING_RE.test(line)
        || BLOCKQUOTE_RE.test(line)
        || isHr(line)
        || LIST_RE.test(line)
        || isTableDelimiter(line);
}

/**
 * 块级解析
 * @param {string[]} lines 已转义文本按行拆分
 * @param {number} depth 递归深度
 * @returns {string}
 */
function renderBlocks(lines, depth) {
    if (depth > MAX_DEPTH) return `<p>${inline(lines.join('\n'))}</p>`;

    const out = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        if (!line.trim()) { i++; continue; }

        // 围栏代码块
        const fence = FENCE_OPEN_RE.exec(line);
        if (fence) {
            const marker = fence[1][0];
            const closeRe = new RegExp(`^ {0,3}${marker === '`' ? '`' : '~'}{${fence[1].length},}\\s*$`);
            const lang = fence[2] || '';
            const buf = [];
            let j = i + 1;
            for (; j < lines.length; j++) {
                if (closeRe.test(lines[j])) break;
                buf.push(lines[j]);
            }
            out.push(codeBlockHTML(buf.join('\n'), lang));
            i = j < lines.length ? j + 1 : j;
            continue;
        }

        // 标题（去掉尾部 closing #）
        const head = HEADING_RE.exec(line);
        if (head) {
            const level = head[1].length;
            out.push(`<h${level}>${inline(head[2].replace(/ +#+ *$/, ''))}</h${level}>`);
            i++;
            continue;
        }

        // 分割线
        if (isHr(line)) { out.push('<hr>'); i++; continue; }

        // 引用块（聚合连续 '>' 行后递归解析）
        if (BLOCKQUOTE_RE.test(line)) {
            const buf = [];
            let j = i;
            for (; j < lines.length; j++) {
                const m = BLOCKQUOTE_RE.exec(lines[j]);
                if (!m) break;
                buf.push(m[1]);
            }
            out.push(`<blockquote>${renderBlocks(buf, depth + 1)}</blockquote>`);
            i = j;
            continue;
        }

        // 表格（当前行 + 次行分隔行）
        if (line.includes('|') && isTableDelimiter(lines[i + 1] || '')) {
            const table = parseTable(lines, i);
            if (table) { out.push(table.html); i = table.next; continue; }
        }

        // 列表
        if (LIST_RE.test(line)) {
            const list = parseList(lines, i, depth);
            out.push(list.html);
            i = list.next;
            continue;
        }

        // 段落
        const buf = [line];
        let j = i + 1;
        for (; j < lines.length; j++) {
            if (startsBlock(lines[j])) break;
            buf.push(lines[j]);
        }
        out.push(`<p>${inline(buf.join('\n'))}</p>`);
        i = j;
    }

    return out.join('');
}

/**
 * 渲染 Markdown 为 HTML 片段
 *
 * 返回的 HTML 中所有动态内容均已转义 / 校验，可安全用于 innerHTML。
 *
 * @param {string} source 原始 Markdown（无需预转义，内部会转义）
 * @param {Object} [options] 见 DEFAULTS
 * @returns {string} HTML 片段
 */
function render(source, options) {
    const prev = OPT;
    OPT = resolveOptions(options);
    try {
        let text = String(source == null ? '' : source)
            .replace(/\r\n?/g, '\n')
            .replace(/\t/g, '    ');

        text = closeOpenFences(text);
        text = escapeHTML(text);

        return renderBlocks(text.split('\n'), 0);
    } finally {
        OPT = prev;
    }
}

/**
 * 复制文本到剪贴板（异步 API 不可用时回退 execCommand）
 * @param {string} text
 * @returns {Promise<boolean>}
 */
function copyText(text) {
    if (window.navigator.clipboard && window.isSecureContext) {
        try {
            return window.navigator.clipboard.writeText(text).then(() => true, () => execCopy(text));
        } catch (_) { /* 回退 */ }
    }
    return Promise.resolve(execCopy(text));
}

/**
 * execCommand 复制回退实现
 * @param {string} text
 * @returns {boolean}
 */
function execCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', 'readonly');
    ta.style.cssText = 'position:fixed;top:-1000px;left:-1000px;opacity:0;';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) { ok = false; }
    document.body.removeChild(ta);
    return ok;
}

/**
 * 绑定复制按钮（事件委托，幂等）
 *
 * 委托到容器而非按钮本身：内容在流式 / 重建时会被整段替换，按钮节点存活期
 * 不固定，委托可让消费方完全不必关心重渲染后的重新绑定。
 *
 * @param {HTMLElement} el
 */
function bindCopy(el) {
    if (el.__wsMdCopyBound) return;
    el.__wsMdCopyBound = true;

    el.addEventListener('click', (e) => {
        const target = e.target instanceof Element ? e.target : null;
        const btn = target ? target.closest('[data-ws-md-copy]') : null;
        if (!btn) return;

        // 同一事件可能被两处委托同时捕获（组件在 mount/stream 的目标上绑定，
        // 消费方如需渲染裸 HTML 字符串还会在外层容器上再绑一次），
        // 用事件级标记保证只处理一次，避免重复写剪贴板与文案闪动。
        if (e.__wsMdHandled) return;
        e.__wsMdHandled = true;

        const block = btn.closest('.ws-md-codeblock');
        const code = block ? block.querySelector('code') : null;
        // textContent 取回的是实体解码后的原始代码，正是需要复制的内容
        copyText(code ? code.textContent : '');

        if (btn.dataset.wsMdCopied2 === '1') return;
        const restore = btn.textContent;
        btn.dataset.wsMdCopied2 = '1';
        btn.textContent = btn.dataset.wsMdCopied || restore;
        setTimeout(() => {
            btn.textContent = restore;
            delete btn.dataset.wsMdCopied2;
        }, 1200);
    });
}

/**
 * 渲染到元素（幂等绑定复制按钮）
 *
 * @param {HTMLElement} el 容器（自身会加上 .ws-md）
 * @param {string} source Markdown 文本
 * @param {Object} [options] 见 DEFAULTS
 * @returns {HTMLElement|null}
 */
function mount(el, source, options) {
    if (!el) return null;
    el.classList.add(CLASS_ROOT);
    el.innerHTML = render(source, options);
    bindCopy(el);
    return el;
}

/**
 * 查找最近的纵向滚动容器
 * @param {HTMLElement} el
 * @returns {HTMLElement|null}
 */
function findScrollContainer(el) {
    let node = el.parentElement;
    while (node && node !== document.body && node !== document.documentElement) {
        const overflowY = window.getComputedStyle(node).overflowY;
        if (overflowY === 'auto' || overflowY === 'scroll') return node;
        node = node.parentElement;
    }
    return null;
}

/**
 * 创建流式渲染 writer
 *
 * 这是「组件」与「函数」的分水岭：流式 Markdown 有三个公共难题，
 * 不该由每个消费方各解一遍 ——
 *   1. 节流：全量渲染是 O(n)，逐 token 渲染退化为 O(n²)，长文本明显掉帧；
 *   2. 围栏容错：未闭合的代码围栏需补齐，否则中途整段退化为字面量再跳变；
 *   3. 贴底策略：用户上翻阅读时不应被反复拉回底部。
 *
 * 用法：
 *   const w = WsMarkdown.stream(bubbleEl);
 *   w.push('第一段'); w.push('第二段');
 *   w.end();                       // 终态渲染；可传完整文本校正
 *
 * @param {HTMLElement|Function} target 容器元素，或「返回容器元素」的函数。
 *        传函数用于「流式期间容器会被全量重建」的场景（消息列表 + 流式气泡共存）：
 *        每次绘制重新解析目标，避免持有已脱离文档的旧节点。
 * @param {Object} [options] 见 DEFAULTS，另支持 { scrollContainer }
 * @returns {{push:Function,set:Function,end:Function,destroy:Function,text:string}}
 */
function stream(target, options) {
    const opts = resolveOptions(options);
    const resolve = typeof target === 'function' ? target : () => target;
    const probe = resolve();
    // 滚动容器在流式期间不会被重建（它是气泡的祖先），故只在创建时解析一次
    const scroller = (options && options.scrollContainer)
        || (probe ? findScrollContainer(probe) : null);

    let buffer = '';
    let timer = 0;
    let lastAt = 0;

    const nearBottom = () => !scroller
        || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 48;

    const paint = () => {
        const el = resolve();
        if (!el) return;
        const stick = nearBottom();
        el.classList.add(CLASS_ROOT);
        el.innerHTML = render(buffer, opts);
        bindCopy(el);
        if (stick && scroller) scroller.scrollTop = scroller.scrollHeight;
    };

    const flush = () => {
        lastAt = Date.now();
        paint();
    };

    const schedule = () => {
        if (timer) return;
        const wait = Math.max(0, opts.throttle - (Date.now() - lastAt));
        timer = setTimeout(() => { timer = 0; flush(); }, wait);
    };

    const clear = () => {
        if (timer) { clearTimeout(timer); timer = 0; }
    };

    return {
        /**
         * 追加增量文本（节流渲染）
         * @param {string} chunk
         */
        push(chunk) {
            if (chunk) buffer += String(chunk);
            schedule();
        },

        /**
         * 直接设定全文并立即渲染
         * @param {string} text
         */
        set(text) {
            buffer = String(text == null ? '' : text);
            clear();
            flush();
        },

        /**
         * 结束流式：清理定时器并做一次终态渲染
         * @param {string} [finalText] 传入时以其为准（用于校正节流滞后）
         */
        end(finalText) {
            if (typeof finalText === 'string') buffer = finalText;
            clear();
            lastAt = 0;
            paint();
        },

        /** 中止并清理（不渲染） */
        destroy() {
            clear();
        },

        /** 当前已累积文本 */
        get text() {
            return buffer;
        },
    };
}

const WsMarkdown = { render, mount, stream, bindCopy, escapeHTML, closeOpenFences };

// 与其他 ws-* 组件保持一致：在自身模块尾部挂载标准全局名
if (typeof window !== 'undefined') {
    window.WsMarkdown = WsMarkdown;
}

export { render, mount, stream, bindCopy, escapeHTML, closeOpenFences };
export default WsMarkdown;
