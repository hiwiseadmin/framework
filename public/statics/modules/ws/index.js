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
};
