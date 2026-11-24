/**
 * ws-* 组件统一入口
 *
 * 目的：让插件开发者无需在模板里手动引入任何 ws-* 脚本。所有组件随
 * wise-bundle.js 一次性加载，从根上消除"漏加载 → typeof 探测失败 → 静默降级"
 * 这类问题（历史上 ws-popmenu 就因为模板没引而长期静默失效，无人察觉）。
 *
 * 全局命名统一：每个组件在自身模块尾部挂载标准全局 window.Ws*（如 WsLayer、
 * WsHintInput、WsLinkageSelect 等）；此处同时以 Ws* 命名导出。组件的历史
 * 别名（如 window.wsLayer / window.Layer / window.HintInput / window.LinkageSelect）
 * 在各组件内保留，保证存量代码零改动。
 *
 * 依赖顺序：ws-form-validator 内部已显式 `import Layer from '../ws-layer/ws-layer.js'`，
 * 由打包器保证求值顺序；此处的排列顺序仅为可读性。
 *
 * @module ws
 */

import WsLayer from '../../libs/ws-layer/ws-layer.js';
import WsSelect from '../../libs/ws-select/ws-select.js';
import WsUpload from '../../libs/ws-upload/ws-upload.js';
import WsFormValidator from '../../libs/ws-form-validator/ws-form-validator.js';
import WsColorPicker from '../../libs/ws-color-picker/ws-color-picker.js';
import WsDatePicker from '../../libs/ws-date-picker/ws-date-picker.js';
import WsHintInput from '../../libs/ws-hint-input/ws-hint-input.js';
import WsLinkageSelect from '../../libs/ws-linkage-select/ws-linkage-select.js';
import WsPopMenu from '../../libs/ws-popmenu/ws-popmenu.js';
import WsMarkdown from '../../libs/ws-markdown/ws-markdown.js';
import { resetControls } from '../../libs/ws-common/ws-common.js';

/**
 * 复位登记表：选择器 → 实例 key（jQuery `.data()` 的 key）。
 *
 * 只登记**已实现 `reset(silent)`** 的组件；未登记的组件在 resetControls 里
 * 会被 `typeof inst.reset !== 'function'` 直接跳过，不会误伤。
 * 新增组件实现 reset 后在这里补一行即可，业务层无需改动。
 */
const RESET_REGISTRY = {
    '[data-role="wsdatepicker"]': 'wsDatePicker',
    '.ws-select-container': 'wsSelect',
};

/**
 * 复位容器内所有 ws-* 组件到服务端渲染时的默认值。
 *
 * 替换「业务层按组件 API 逐个回填」的写法：`form.reset()` 不会更新组件显示态，
 * 复位细节只有组件自己知道，统一从这里下发。
 *
 * @param {Document|HTMLElement|string} [root=document] 扫描根（列表页筛选用 `#ws-filter`）
 * @param {Object} [options]
 * @param {boolean} [options.silent=true] 静默复位：不派发 change，避免触发「变更即查」
 * @returns {number} 实际复位的组件数
 */
export function resetAll(root = document, options = {}) {
    return resetControls(root, RESET_REGISTRY, options);
}

/**
 * 一次性初始化所有支持声明式的 ws-* 组件。
 *
 * 为什么需要它：各组件自己的 `$(function(){})` 只覆盖「首屏解析到的那批 DOM」。
 * 弹窗（`Wise.ui.dialog.form` 的 `spec.url`）、列表动态行、局部刷新出来的节点
 * 都是后插入的，**没有任何组件会再扫它们一遍**，表现为「组件不初始化、值提交不上」。
 *
 * 调用点（3 处，缺一处就有一类场景失效）：
 * 1. `wise-bootstrap.js` 的 `ws:core:ready` —— 首屏
 * 2. `wise-ui.js` 弹窗内容注入后          —— 弹窗
 * 3. `wise-layer` / 表格渲染后            —— 动态节点
 *
 * 幂等：各组件内部按 `data-role` + 实例标记去重，重复调用安全。
 *
 * @param {Document|HTMLElement|string} [root=document] 扫描根节点
 * @returns {Object<string, number>} 各组件实际初始化数量（便于排查"为什么没生效"）
 */
export function autoInitializeAll(root = document) {
    const components = {
        select: WsSelect,
        datePicker: WsDatePicker,
        hintInput: WsHintInput,
        upload: WsUpload,
        colorPicker: WsColorPicker,
        linkageSelect: WsLinkageSelect,
        popMenu: WsPopMenu,
    };

    const result = {};

    Object.keys(components).forEach((key) => {
        const C = components[key];
        if (!C || typeof C.autoInitialize !== 'function') return;
        try {
            result[key] = C.autoInitialize(root);
        } catch (err) {
            console.error('[ws] ' + key + ' 初始化异常', err);
            result[key] = -1;
        }
    });

    return result;
}

export {
    WsLayer,
    WsSelect,
    WsUpload,
    WsFormValidator,
    WsColorPicker,
    WsDatePicker,
    WsHintInput,
    WsLinkageSelect,
    WsPopMenu,
    WsMarkdown,
};

export default {
    WsLayer,
    WsSelect,
    WsUpload,
    WsFormValidator,
    WsColorPicker,
    WsDatePicker,
    WsHintInput,
    WsLinkageSelect,
    WsPopMenu,
    WsMarkdown,
    autoInitializeAll,
    resetAll,
};
