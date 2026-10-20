/**
 * dashboard.js — 概览页（ESM 控制器）
 *
 * 概览页的数据全部由服务端渲染（卡片计数），无需前端逻辑。
 *
 * ⚠️ 本文件存在的意义：`plugin\BaseController::setupJsPath()` 会把
 * `wsconfig.js` 指向 `../plugins/{ident}/js/{controller}`，内核
 * `_loadController()` 必然去 import 它。若文件缺失，浏览器会拿到 403/404
 * 并在控制台报「控制器加载失败」——页面功能虽不受影响，但属噪声级缺陷。
 * 因此每个可访问的控制器都应有对应模块，无逻辑时留空实现即可。
 */

const Controller = {
    /** 概览页无需前端初始化 */
    index() {},
};

export default Controller;
