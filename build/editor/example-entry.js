/**
 * ============================================================
 * WiseEditor 示例页「直开版」入口（example-entry）
 * ============================================================
 *
 * 用途：把 `plugins/wiseeditor/example/` 下的示例页从「必须起静态服务」
 * 变成「浏览器双击即可打开」。
 *
 * ── 为什么要有这个入口 ──────────────────────────────────────
 * 示例页原本引的是 `wiseeditor-init.js`（ES module）。ES module 在
 * `file://` 下会被浏览器按 CORS 直接拦截（origin 为 null），
 * 所以双击 html 永远挂不上编辑器。这里把**同一个入口**（wiseeditor-init.js）
 * 连同它的依赖链（wiseeditor.js / wiseeditor-tiptap.js /
 * wiseeditor-upload.js / wiseeditor-statusbar.js）用 esbuild 打成
 * 一个 classic（IIFE）单文件 —— 经典脚本不受 file:// 限制。
 *
 * 因此本产物与线上行为完全一致：同一个入口、同一份适配器、同一套初始化流程，
 * 差别只在「怎么加载」（经典脚本 vs 模块脚本）。
 *
 * ── 引擎为什么必须内联 ──────────────────────────────────────
 * `vendor/tiptap.bundle.js`（build/build-editor.mjs 的 ① 号产物）是 **ES module**，
 * 结尾带 `export{...}`。file:// 下它既不能被 `<script>` 经典加载（语法直接报错），
 * 也不能被 `import()` 动态加载（CORS 拦掉）—— 所以只能一起打进本产物。
 * 好在引擎侧本来就是"导入即挂 `globalThis.TipTap`"的设计，内联后
 * `loadVendor()` 的短路分支照样命中，运行行为与线上完全一致。
 * 代价是本文件约 460 KB，换来「双击 html 即可测」。
 *
 * ── 产物落点 ────────────────────────────────────────────────
 *   plugins/wiseeditor/example/dist/wiseeditor.example.js
 *
 * 该目录不在 `statics/` 之下，因此既不会被 AssetManager::copyAssets()
 * 发布、也不会被 build-editor.mjs 的 syncToPublic() 同步进 public/。
 * 它是**开发期资产**（`example/` 整体已在 .gitattributes 里 export-ignore）。
 *
 * @see build/build-editor.mjs
 */

// 无 export：iife 格式的入口若带导出，esbuild 需要 globalName 才能安置，
// 而这里不需要对外暴露任何东西 —— wiseeditor.js 自己会挂 window.WiseEditor。
import '../../plugins/wiseeditor/statics/js/wiseeditor-init.js';

/**
 * 把引擎**内联**进直开版（file:// 下没法按 URL 再取一个文件）。
 *
 * 线上不需要这句：适配器里对引擎用的是变量 specifier（避免被打包器内联
 * 600 KB），运行时按 `import.meta.url` 取文件。这里用字面量 import 把它
 * 拽进来，导入即执行副作用并挂 `window.TipTap`，`loadVendor()` 会短路命中。
 */
import '../../plugins/wiseeditor/statics/js/vendor/tiptap.bundle.js';

/**
 * 顺带把 ws-markdown 一起打进来。
 *
 * 为什么：Markdown 编辑器的右栏预览靠 `WsMarkdown.render()`。后台页面由
 * wise-bundle.js 统一提供，而示例页没有内核 —— 不引它，预览栏就只会输出
 * 未渲染的 Markdown 原文（适配器会告警，但告警不等于能用）。
 *
 * 它是 ESM（带 export），file:// 下没法用 classic 脚本另引，只能内联。
 * 来源是宿主级共享组件 `public/statics/libs/ws-markdown/`，属于**开发期**
 * 借用：本产物只在 example/ 下、不入 dist、不进 public/。
 */
import WsMarkdown from '../../public/statics/libs/ws-markdown/ws-markdown.js';

// 显式挂全局，而不是依赖组件内部的副作用赋值 —— 这样即便打包器把
// 组件自身的 `window.WsMarkdown = ...` 判成可摇树，这里也拦得住。
if (typeof window !== 'undefined') {
    window.WsMarkdown = WsMarkdown;
}
