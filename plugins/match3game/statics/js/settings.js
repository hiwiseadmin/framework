/**
 * settings.js — 玩法参数页控制器
 *
 * 该页内容全部由服务端渲染（config/match3.php 的只读快照），没有交互逻辑。
 *
 * 【为什么需要一个空控制器】插件页面的 JS 路径由框架统一注入：
 * `Extend\plugin\BaseController::setupJsPath()` 会写入
 * `js => '../plugins/<ident>/js/<controller>'`，
 * `wise-bootstrap.js` 再据此动态 `import()`。
 * 缺这个文件会在浏览器控制台留下
 * `403 Forbidden` + `[Wise:core] 控制器加载失败` 噪音（虽不影响页面功能）。
 *
 * 后续若给该页加交互（例如参数在线编辑），直接在此补充 `index()` 即可。
 */
export default {
    index() {
        // 只读展示，无需初始化
    },
};
