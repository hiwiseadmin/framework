/**
 * ============================================================
 * 代码高亮语言集（lowlight / highlight.js）
 * ============================================================
 *
 * 只在这里维护语言列表 —— 引擎（tiptap-entry.js）与往返校验
 * （verify-tiptap-markdown.mjs）都从这里取，避免两处清单漂移。
 *
 * ── 为什么不用 lowlight/lib/common ──────────────────────────
 * `common` 是 190+ 种语言的合集（min 后约 300 KB），而后台正文实际会遇到
 * 的只有下面这十几种。bundle 已经因为 Markdown 引擎涨到 ~500 KB，
 * 再加 300 KB 换"能高亮 Haskell"不值得。
 *
 * ── 别名 ───────────────────────────────────────────────────
 * `js / ts / html / sh / py / yml / md` 等别名由 highlight.js 的语法包
 * 自带（见 grammar.aliases），所以作者写 ```js 或 ```javascript 都能认出来，
 * 不需要在这里额外映射。
 */

import { createLowlight } from 'lowlight';

import hljsBash from 'highlight.js/lib/languages/bash';
import hljsCss from 'highlight.js/lib/languages/css';
import hljsJavascript from 'highlight.js/lib/languages/javascript';
import hljsJson from 'highlight.js/lib/languages/json';
import hljsMarkdown from 'highlight.js/lib/languages/markdown';
import hljsPhp from 'highlight.js/lib/languages/php';
import hljsPython from 'highlight.js/lib/languages/python';
import hljsSql from 'highlight.js/lib/languages/sql';
import hljsTypeScript from 'highlight.js/lib/languages/typescript';
import hljsXml from 'highlight.js/lib/languages/xml';
import hljsYaml from 'highlight.js/lib/languages/yaml';

/** 已注册的语法（key = lowlight 里的语言名） */
export const HLJS_LANGUAGES = {
    bash: hljsBash,
    css: hljsCss,
    javascript: hljsJavascript,
    json: hljsJson,
    markdown: hljsMarkdown,
    php: hljsPhp,
    python: hljsPython,
    sql: hljsSql,
    typescript: hljsTypeScript,
    xml: hljsXml,
    yaml: hljsYaml,
};

/** 语言名清单（自检用：可断言"确实没打全部语法"） */
export const LANGUAGE_NAMES = Object.keys(HLJS_LANGUAGES);

/** 共享实例：全页只建一个，别在 create() 里每实例 new 一次 */
export const lowlight = createLowlight(HLJS_LANGUAGES);
