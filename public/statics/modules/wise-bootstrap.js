/**
 * Wise.bootstrap — 框架入口
 *
 * 执行顺序：
 * 1. 读取 PHP 注入的 window.__WS_CONFIG__
 * 2. 初始化 Wise.core 配置
 * 3. 加载内核模块（util/ui/plugin/adapter）
 * 4. 动态 import 页面控制器并执行对应方法
 *
 * @module wise-bootstrap
 * @version 1.0.0
 */

// ws-* 组件必须最先求值：wise-ui.js 等模块通过 window.wsXxx 探测组件可用性，
// 放在首位可保证其模块求值时全局已挂载，不依赖"调用时"才就绪的时序假设。
// 组件随内核一起打包，插件开发者无需在模板里手动引入任何组件脚本。
import * as wsComponents from './ws/index.js';

// 框架级全局工具（原 statics/js/{hotkey,clipboard,broadcast,cookie}/*.js，UMD → ESM 并入主包）。
// 放在静态 import 图里的意义：求值一定早于动态加载的业务模块（wise-framework 在模块顶层就取
// window.HotKey / window.Broadcast，wise-search 取 window.HotKey）与页面控制器，彻底消除
// 「模板漏引 → window.X 缺失 → new HotKey() 抛 TypeError / Wise.ui 静默降级」。
// 四个模块在求值末尾仍挂 window.HotKey / Clipboard / Broadcast / Cookie，存量代码与插件零改动。
import HotKey from '../libs/hotkey/hotkey.js';
import Clipboard from '../libs/clipboard/clipboard.js';
import Broadcast from '../libs/broadcast/broadcast.js';
import Cookie from '../libs/cookie/cookie.js';

import { config, logger, eventBus, registry } from './wise-core.js';
import * as util from './wise-util.js';
import * as ui from './wise-ui.js';
import * as dom from './wise-dom.js';
import { plugin } from './wise-plugin.js';
import { theme } from './wise-theme.js';
// 列表页筛选条的参数层（UIS v1 §6.5）：`.ws-filter-bar` ↔ bootstrap-table queryParams
import { crud } from './crud-page.js';

// ============================================================
// 组装 Wise 全局对象
// ============================================================
window.Wise = {
    version: '1.0.0',
    config,
    logger,
    eventBus,
    util,
    ui,
    dom,
    plugin,
    // 静态资源 URL 版本化：动态 import / 动态插标签必须走它（见 util.asset 注释）
    asset: util.asset,
    ws: wsComponents,
    // 框架级全局工具类。注意与高层封装区分：ui.hotkey / ui.clipboard 是门面（自动处理
    // 命名空间、降级、提示），这四个是底层实现本身，等价于 window.HotKey / window.Clipboard /
    // window.Broadcast / window.Cookie。util.cookie 是 Cookie 的薄封装（旧签名）。
    hotkey: HotKey,
    clipboard: Clipboard,
    broadcast: Broadcast,
    cookie: Cookie,
    // 业务模块占位（后续按需加载）
    table: null,
    navtabs: null,
    nav: null,
    permission: null,
    upload: null,
    grid: null,
    search: null,
    slot: null,
    // 列表页筛选条参数层：Wise.crud.filterParams('#ws-filter') / bindFilterBar() / autoBind()
    crud,
    theme,
};

const W = window.Wise;

// 导出供其他 ESM 模块使用
export { config, logger, eventBus, util, ui, dom, plugin, crud, theme };

// ============================================================
// 初始化
// ============================================================
const WS_CONFIG = window.__WS_CONFIG__ || {};

// 合并 PHP 注入的配置
config.merge(WS_CONFIG.site || {});
config.merge({
    modulename: WS_CONFIG.modulename || '',
    controllername: WS_CONFIG.controllername || WS_CONFIG.controller || '',
    actionname: WS_CONFIG.action || '',
    moduleurl: WS_CONFIG.moduleurl || '',
    upload: WS_CONFIG.upload || {},
    // 全局资源版本号（服务端 AssetVersion）：所有动态 import 的 ?_v= 取自这里
    assetVersion: WS_CONFIG.assetVersion || '',
});

// ============================================================
// 主题初始化
// 必须早于所有业务模块：主题决定 CSS 变量取值，
// 后续模块（表格/图表）渲染时才能拿到正确的颜色上下文。
// 置于 config.merge 之后，确保 moduleurl 可用（服务端回写需要）。
// ============================================================
theme.init();
registry.register('theme', theme);

// 开发模式启用日志
if (WS_CONFIG.site?.debug) {
    logger.setLevel('debug');
    logger.info('core', `Wise v${W.version} 已就绪（调试模式）`);
} else {
    logger.setLevel('warn');
    logger.info('core', `Wise v${W.version} 已就绪`);
}

// ============================================================
// 加载向后兼容适配层
// ============================================================
import('./wise-adapter.js').catch(() => {
    logger.warn('core', '适配层加载失败（可忽略）');
});

// ============================================================
// 预加载业务模块（异步，不阻塞控制器加载）
// ============================================================
async function _loadBusinessModules() {
    // load 必须写成字面量形式的 import()：打包器只能静态分析字面量路径，
    // 若沿用 import(mod.path) 这类间接写法，模块将退化为运行时请求，打包失效。
    // 未打包时它仍是按需的动态 import，已打包时 esbuild 内联为惰性求值闭包，
    // 两种模式下语义一致（容错、并行、惰性求值均保留）。
    const mods = [
        { name: 'permission', target: 'permission', load: () => import('./wise-permission.js') },
        { name: 'upload', target: 'upload', load: () => import('./wise-upload.js') },
        { name: 'navtabs', target: 'navtabs', load: () => import('./wise-navtabs.js') },
        // nav 依赖 navtabs（打开标签）与 framework（兜底开标签/tab meta），
        // 但都只在**调用时**访问，故顺序只需满足「装配早于页面控制器」即可。
        { name: 'nav', target: 'nav', load: () => import('./wise-navmenu.js') },
        { name: 'framework', target: 'framework', load: () => import('./wise-framework.js') },
        { name: 'table', target: 'table', load: () => import('./wise-table.js') },
        { name: 'grid', target: 'grid', load: () => import('./wise-grid.js') },
        { name: 'search', target: 'search', load: () => import('./wise-search.js') },
        { name: 'slot', target: 'slot', load: () => import('./wise-slot.js') },
    ];

    // 并行发起下载与求值：业务模块之间无静态 import 依赖，
    // 串行 await 会让 N 个模块产生 N 次串行往返（RTT 叠加）。
    // 它们均为纯声明式模块（顶层仅读取 window.jQuery，已由 defer 脚本保证就绪），
    // 因此并发求值不会破坏初始化顺序。
    const settled = await Promise.allSettled(mods.map((mod) => mod.load()));

    // 装配仍严格按 mods 数组顺序执行，保持 navtabs（注入 eventBus）→ search（init）等既有顺序语义
    settled.forEach((result, index) => {
        const mod = mods[index];

        if (result.status === 'rejected') {
            logger.warn('core', `业务模块加载失败: ${mod.name}`, result.reason?.message ?? result.reason);
            return;
        }

        try {
            const m = result.value;
            // 实例解析优先级：命名导出（与 target 同名）→ 默认导出。
            // 两者都缺失说明导出名与 target 不匹配，此时必须告警：
            // 若静默降级为命名空间对象，window.Wise[target] 会变成 { Xxx: {...} }，实例方法全部丢失。
            let instance = m[mod.target] ?? m.default;
            if (!instance) {
                logger.warn('core', `业务模块 ${mod.name} 缺少可用导出（需 export ${mod.target} 或 export default）`);
                instance = m;
            }
            window.Wise[mod.target] = instance;
            registry.register(mod.target, instance);

            // Navtabs 特殊处理：注入 eventBus + 暴露 window.navtab
            if (mod.name === 'navtabs' && instance) {
                instance._eventBus = eventBus;
                window.navtab = instance;
            }

            // Search 特殊处理：业务模块加载完成后初始化全局搜索（绑定按钮 / Ctrl+K / 输入框事件）
            if (mod.name === 'search' && instance && typeof instance.init === 'function') {
                instance.init();
            }

            logger.info('core', `业务模块已加载: ${mod.name}`);
        } catch (err) {
            logger.warn('core', `业务模块装配失败: ${mod.name}`, err.message);
        }
    });
}

// ============================================================
// 动态加载后台前端功能模块（由后端注入 window.__WS_FEATURE_MODULES__ 决定）
// 各模块随各自包发布（如 AI 助手随 wise-agent），框架不硬编码任何路径/模块名。
//
// 顺序契约：
//   数组顺序 = 后端 ExtPoints::ADMIN_FRONTEND_MODULE 的 priority 升序
//   （priority 越小越靠前）。模块自行创建 DOM（如顶栏入口），所以**挂载顺序
//   就是数组顺序** —— 为此把「并行 import（速度）」与「按序 init（顺序）」
//   拆成两步：import 全部并行完成后，再按数组序串行调用 init()，
//   避免"谁先下载完谁先挂 DOM"造成的顺序不确定。
//
// 模块约定：导出实例（mod[key] ?? mod.default）若提供 init()，会在第二步被
//   调用一次；init() 应尽快返回（同步完成挂载），耗时的准备工作请模块内部并发。
// ============================================================
async function _loadFeatureModules() {
    const modules = window.__WS_FEATURE_MODULES__ || [];

    // Step 1：并行 import（失败/无效项记为 null，不影响其它模块的位置）
    //   注册（W / registry）在 import 完成时立即执行，保持与改造前一致：
    //   任一模块 init() 之前，所有已到达的模块都已登记。
    const loaded = await Promise.all(modules.map(async (m) => {
        if (!m || !m.url) return null;
        const key = m.key || 'module';
        try {
            // util.asset 追加全局 ?_v=（服务端在 AdminTrait 已加过一次，这里是二次保险：
            // 兼容第三方直接改写 __WS_FEATURE_MODULES__ 的场合，已带 _v= 时原样返回）
            const mod = await import(/* @vite-ignore */ util.asset(m.url));
            const instance = mod[key] ?? mod.default;
            if (!instance) {
                logger.warn('core', `功能模块缺少可用导出: ${key}`);
                return null;
            }
            W[key] = instance;
            registry.register(key, instance);
            return { key, instance };
        } catch (err) {
            logger.warn('core', `功能模块加载失败: ${key}`, err?.message ?? err);
            return null;
        }
    }));

    // Step 2：严格按数组序（= priority 升序）串行 init()
    for (const item of loaded) {
        if (!item) continue;
        try {
            if (typeof item.instance.init === 'function') {
                await item.instance.init();
            }
            logger.info('core', `功能模块已加载: ${item.key}`);
        } catch (err) {
            logger.warn('core', `功能模块初始化失败: ${item.key}`, err?.message ?? err);
        }
    }
}

// ============================================================
// 加载页面控制器（顺序：ESM 业务模块 → 控制器）
// ============================================================
const controllerJs = WS_CONFIG.js;
const action = WS_CONFIG.action || 'index';

/**
 * 页面控制器的缓存版本号
 *
 * 优先级：
 *   1. WS_CONFIG.jsv          —— 控制器文件自身的 mtime（AdminTrait 注入，最精确）
 *   2. WS_CONFIG.site.version —— 站点版本（debug 下 Environment 会按静态资源 mtime 提升）
 *
 * 动态 import 的 URL 不带版本号时，浏览器会按启发式缓存复用旧文件，
 * 表现为「文件已更新、页面仍执行旧逻辑」（例如修好的表单提交仍按老代码跑）。
 */
function _controllerVersion() {
    const siteVersion = WS_CONFIG.site && WS_CONFIG.site.version ? WS_CONFIG.site.version : '';
    const version = WS_CONFIG.jsv || siteVersion || WS_CONFIG.version || '';

    return version === '' || version === null || version === undefined ? '' : String(version);
}

/**
 * 给动态 import 路径追加版本参数
 *
 * 两段版本各司其职：
 *   v=<文件 mtime>  —— 文件变化即失效，部署后不必记得点清理；
 *   _v=<全局版本>   —— 后台「组件缓存」按钮自增，兜住「文件变了但 mtime 没变」
 *                      以及需要强制全量失效的场合（由 util.asset 统一追加）。
 */
function _versionedPath(jsPath) {
    const version = _controllerVersion();
    let path = jsPath;

    if (version) {
        path += (path.includes('?') ? '&' : '?') + 'v=' + encodeURIComponent(version);
    }

    return util.asset(path);
}

async function _loadController() {
    // Step 1: 等待 ESM 业务模块就绪（navtabs 等需要注入 eventBus）+ 动态功能模块
    await Promise.all([_loadBusinessModules(), _loadFeatureModules()]);

    // Step 1.5: 权限就绪后再执行页面控制器
    //   页面脚本里的按钮显隐统一走 Wise.permission.can()；权限集合按角色固定，
    //   首次进站拉一次并缓存在 sessionStorage（同标签所有页面/iframe 复用）。
    //   失败不阻塞页面：can() 在未就绪时返回 false（fail-closed），按钮不显示。
    if (W.permission && typeof W.permission.ensure === 'function') {
        try {
            await W.permission.ensure();
        } catch (err) {
            logger.warn('core', '权限初始化失败，按钮按无权限处理', err?.message ?? err);
        }
    }

    if (controllerJs) {
        const jsPath = controllerJs.endsWith('.js') ? controllerJs : controllerJs + '.js';
        const modulePath = new URL(_versionedPath(jsPath), import.meta.url).href;

        try {
            const module = await import(modulePath);
            const Controller = module.default || module;
            if (Controller && typeof Controller[action] === 'function') {
                logger.info('core', `加载控制器: ${controllerJs}#${action}`);

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => {
                        Controller[action]();
                        eventBus.emit('ws:core:ready');
                    });
                } else {
                    Controller[action]();
                    eventBus.emit('ws:core:ready');
                }
            } else {
                logger.warn('core', `控制器 ${controllerJs} 缺少方法 ${action}`);
                eventBus.emit('ws:core:ready');
            }
        } catch (err) {
            logger.error('core', `控制器加载失败: ${controllerJs}`, err);
            eventBus.emit('ws:core:ready');
        }
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => eventBus.emit('ws:core:ready'));
        } else {
            eventBus.emit('ws:core:ready');
        }
    }
}

_loadController();

// ============================================================
// 声明式组件统一初始化
//
// 各组件自身的 `$(function(){})` 只覆盖首屏 DOM。页面控制器执行完、
// `ws:core:ready` 之后，可能有组件是由控制器动态插入的（或控制器希望
// 覆盖默认初始化参数），此处再统扫一次。幂等，重复调用无副作用。
// ============================================================
eventBus.on('ws:core:ready', () => {
    if (typeof wsComponents.autoInitializeAll !== 'function') return;

    try {
        const stats = wsComponents.autoInitializeAll(document);

        // 只在确有初始化发生时打日志，避免每页刷屏
        const total = Object.keys(stats).reduce((sum, k) => sum + Math.max(0, stats[k] || 0), 0);
        if (total > 0) {
            logger.info('ws', '声明式组件已初始化', stats);
        }
    } catch (err) {
        logger.warn('ws', '声明式组件初始化失败', err && err.message);
    }
});

// ============================================================
// 插槽渲染：在核心就绪后自动渲染页面插槽
// ============================================================
eventBus.on('ws:core:ready', async () => {
    if (W.slot && typeof W.slot.renderAll === 'function') {
        try {
            const page = WS_CONFIG.controller || 'dashboard';
            await W.slot.init(page);
            await W.slot.renderAll();
        } catch (err) {
            logger.warn('slot', '插槽渲染失败', err.message);
        }
    }
});

// 暴露到全局（调试用）
window.Wise = W;
