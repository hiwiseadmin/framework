# WiseEditor 示例（example/）

> 本目录是**开发期资产**：只在仓库里存在，**不随 composer dist 发布**（见下方"分发排除"）。
> 它不是运行时资源 —— 浏览器加载的永远是 `public/statics/plugins/wiseeditor/` 下的副本。

## 示例页

本目录有**两套**页面，用途不同，不要混着用：

| 文件 | 打开方式 | 用途 |
|------|----------|------|
| `standalone.html` | **双击**（`file://`） | 直开版：相对路径 + 经典脚本，无需任何服务，一页测全套 |
| `index.html` | 开发服务器 | 富文本（TipTap）。`TipTapDriver::renderHtml()` 的产出一字不差；含 `data-readonly` 只读实例 |
| `markdown.html` | 开发服务器 | Markdown（Milkdown）。同一套前端入口换 `data-editor-type` 即切驱动；字段是 `<textarea>` |
| `api.html` | 开发服务器 | 实例 API。逐个演示 `getContent / setContent / isDirty / focus / destroy` 契约 |

四个页面共用 `example.css`，页面脚本各自独立
（`standalone.js` / `example.js` / `markdown.js` / `api.js`），便于整段复制到自己的页面里改。

## 直开版：双击就能测（standalone.html）

想快速看一眼编辑器、又不想起服务时用它。

```bash
cd src
npm run build:editor          # 生成 example/dist/wiseeditor.example.js
# 然后直接双击 plugins/wiseeditor/example/standalone.html
```

它和上面三个页面跑的是**同一套代码**（入口都是 `wiseeditor-init.js`），
只改了三处让 `file://` 能跑：

| 差异 | 线上形态 | 直开版 |
|------|----------|--------|
| 资源路径 | `/statics/...`（站点绝对） | `../statics/...`、`../../../public/...`（相对） |
| 脚本形态 | `<script type="module">` | classic `<script>`（`file://` 下模块会被 CORS 拦掉） |
| 引擎 bundle | 动态 `import()` | 内联进单文件（它是 ESM，两种加载方式在 `file://` 下都不行） |

### Markdown 预览栏需要 ws-markdown

Markdown 编辑器的**右栏预览**靠 `WsMarkdown.render()`。后台页面由 `wise-bundle.js`
统一提供（`window.Wise.ws.WsMarkdown` + `wise-bundle.css` 里的 `.ws-md` 样式），
示例页没有内核，**必须自己引**，否则右栏只会输出未渲染的 Markdown 原文：

| 页面 | 引入方式 |
|------|----------|
| `markdown.html` | `<script type="module" src="/statics/libs/ws-markdown/ws-markdown.js">` + 同名 `.css` |
| `standalone.html` | 已内联进 `dist/wiseeditor.example.js` + 相对路径引 `.css` |

适配器对渲染器认**两个名字**：`window.Wise.ws.WsMarkdown`（内核）和
`window.WsMarkdown`（组件自己挂的标准全局）。两个都没有时会 `console.warn`
并退化为纯文本 —— 刻意不静默，因为"预览退化成原文"是看不出坏的那种坏。

因此直开版**不能**从开发服务器打开（相对路径与 `/statics/**` 站点根对不上），
三个「线上形态」页面也**不能**双击打开 —— 两套用法是互补的。

`example/dist/wiseeditor.example.js` 是生成物：改了 `plugins/wiseeditor/statics/js/*.js`
之后必须重跑 `npm run build:editor`，否则直开版用的是旧代码。

## 另外三个页面怎么打开

它们用的是**站点绝对路径** `/statics/...`（与后台模板 `base.html` 的 `__STATIC__` 一致），
所以不能直接双击打开，也不能只把 `example/` 当站点根。

仓库里现成的开发服务器已经处理了这件事：

```bash
cd src
npm run build:editor          # 先把产物同步到 public/statics/plugins/wiseeditor/
npm run serve:editor          # = node build/verify-editor.mjs --serve
# 然后浏览器打开它打印的 http://127.0.0.1:8899/example/index.html
```

它同时挂两个站点根，正好等于线上布局：

| URL 前缀 | 实际目录 |
|----------|----------|
| `/statics/**` | `src/public/statics/**`（已发布的运行时资源） |
| `/example/**` | `src/plugins/wiseeditor/example/**`（本目录） |

也就是说：**示例页能正常打开，本身就说明静态资源已正确发布**（否则 `wiseeditor-init.js` 会 404）。

## 上传按钮为什么不能真上传

示例页的 `data-upload-url="/admin/attachment/upload"` 指向后台真实接口，
但开发服务器只是个静态服务，没有 PHP。点"图片"会请求失败 —— 这是预期的，
要试上传请把示例片段放进真实后台页面。

## 分发排除（export-ignore）

`../.gitattributes` 里对本目录做了 `export-ignore`：

```gitattributes
/example/         export-ignore
```

因此 `git archive` / GitHub tarball / composer dist（Packagist 的 zip 就是它）里**不会有本目录**。

两个必须知道的边界：

1. 只对**分发包**生效。`composer install --prefer-source`（= git clone）与
   `path` 类型仓库拿到的仍是完整仓库，本目录照样存在。
2. 它管不到**发布**（`AssetManager::copyAssets()`）。不过 `example/` 不在
   `plugins/wiseeditor/statics/` 之下，而发布只复制 `statics/`，所以本目录天然不会被发到
   `public/` —— 两道关是独立的，别只依赖其中一道。

## dist/ 是生成物，但入库

`example/dist/wiseeditor.example.js`（约 450 KB，已内联引擎）**会入库**，理由与
`statics/js/vendor/tiptap.bundle.js` 一样：clone 下来就能双击 `standalone.html` 直接测，
不必先跑一次构建。代价是它是生成物 —— 改了 `../statics/js/*.js` 必须重跑
`npm run build:editor`，否则直开版静默沿用旧副本
（`npm run verify:editor` 的用例 4 只能验证它"没坏"，验不出"是不是最新"）。
