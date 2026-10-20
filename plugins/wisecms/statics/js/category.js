/**
 * 栏目管理页面控制器（UIUX-P0-11）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/category`
 * （`CategoryController` → snake_case `category`）。
 *
 * 对应控制器动作：
 *   category/tree          → 全状态栏目树（`{rows, tree}`，UIUX-P0-47）
 *   category/create|edit   → 表单页（iframe 弹层，本页只负责打开与回调刷新）
 *   category/delete        → 删除（有子栏目/有内容时后端拒绝，错误直接 toast）
 *   category/toggleStatus  → 状态切换（行内开关）
 *
 * 树表要点（已按内核可用配置核对，参考 `public/statics/admin/menu.js`）：
 *   · `treeShowField` 必须等于某个**真实列 field**，否则 bootstrap-table 的 treegrid
 *     扩展不会置 `treeEnable`，整个树能力静默失效；
 *   · `treeColumn` 是**列数组里的下标**（menu.js 带复选框时为 2；本页 P1-19 加批量勾选后同为 2）；
 *   · 根节点判定靠 `parent_id` 为假值，本插件返回 int 0，成立；
 *   · `pagination: false` —— 树按页切分会被切断。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsDelete, cmsRefresh, cmsEscape, cmsBindRowToggles, cmsBatch, cmsToast, cmsFormPage,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('category/tree'),
    create: cmsUrl('category/create'),
    edit: cmsUrl('category/edit'),
    del: cmsUrl('category/delete'),
    toggle: cmsUrl('category/toggleStatus'),
    content: cmsUrl('content/index'),
    batchToggle: cmsUrl('category/batchToggle'),
};

// 颜色只承载"主操作 / 危险"两级（UIUX-P1-04）
const OPERATE = [
    { action: 'child', title: '新增子栏目', icon: 'bi bi-node-plus', class: 'btn-outline-secondary' },
    { action: 'edit', title: '编辑栏目', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'content', title: '查看该栏目内容', icon: 'bi bi-file-earmark-text', class: 'btn-outline-secondary' },
    { action: 'del', title: '删除栏目', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

export default {
    /**
     * 列表页入口（与 action 同名，由 wise-bootstrap 调用）
     */
    index() {
        let list = null;

        function selectedIds() {
            const ids = window.Wise.table.getSelectedIds('#table');
            return Array.isArray(ids) ? ids : [];
        }

        /** 批量启用 / 禁用栏目（P1-19，目标值语义 + 站点过滤） */
        function batchToggleStatus(status) {
            const ids = selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要操作的栏目', 'warning');
                return;
            }
            const enable = String(status) === '1';
            return cmsBatch({
                url: URL.batchToggle,
                ids: ids,
                data: { status: enable ? 1 : 0 },
                confirmMessage: '将' + (enable ? '启用' : '禁用') + '选中的 ' + ids.length + ' 个栏目，确定继续？',
                successMsg: enable ? '已启用选中的栏目' : '已禁用选中的栏目',
                refresh: list,
            });
        }

        /**
         * 打开栏目表单（iframe 弹层）。
         *
         * 表单页保存成功后调用 `window.parent.cmsCategoryFormDone()`（见
         * `view/admin/category/create.html` 的 afterCategorySaved）—— 这样只刷新
         * 当前表格，不再整页 reload（UIUX-P0-41 / P0-06 的目标）。
         */
        function openForm(url, title) {
            const index = window.Wise.ui.dialog.open(url, title, { area: ['900px', '680px'], resize: true });

            window.cmsCategoryFormDone = function () {
                try {
                    window.Wise.ui.dialog.close(index);
                } catch (e) {
                    // 弹层可能已被用户手动关闭，忽略
                }
                cmsRefresh(list);
            };

            return index;
        }

        list = cmsTable({
            url: URL.list,
            shape: 'adminTree',
            // 树列 = 列数组下标 2（0=checkbox，1=ID，2=栏目名称）
            treeShowField: 'name',
            treeColumn: 2,
            idField: 'id',
            parentIdField: 'parent_id',
            initialState: 'expanded',
            showColumns: true,
            // 批量：后端有 batchToggle（P1-19）→ 开勾选
            checkboxHeader: true,

            columns: [
                { checkbox: true },
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                { field: 'name', title: '栏目名称', formatter: 'normal', align: 'left' },
                { field: 'alias', title: '别名', width: 140, formatter: 'normal', align: 'left' },
                {
                    field: 'type',
                    title: '类型',
                    width: 100,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '内部栏目', 1: '单页', 2: '外链' },
                    custom: { 0: 'secondary', 1: 'info', 2: 'warning' },
                },
                { field: 'model_name', title: '绑定模型', width: 130, formatter: 'normal', align: 'left' },
                {
                    field: 'is_nav',
                    title: '导航',
                    width: 90,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '不显示', 1: '显示' },
                    custom: { 0: 'secondary', 1: 'primary' },
                },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.toggle,
                },
                {
                    // 行内排序：失焦提交 `{id, sort, sort_field}` → category/edit
                    // （`Category::edit` 的白名单本就含 sort，无需新增接口）
                    field: 'sort',
                    title: '排序',
                    width: 90,
                    align: 'center',
                    formatter: 'input',
                    url: URL.edit,
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 200,
                    align: 'center',
                    formatter: (v, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-category',
                    name: 'add',
                    title: '新增顶级栏目',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => openForm(URL.create, '添加栏目'),
                },
                {
                    id: 'btn-batch-enable-category',
                    name: 'batchEnable',
                    title: '批量启用',
                    icon: 'bi bi-check-lg',
                    class: 'btn btn-sm btn-outline-success',
                    click: () => batchToggleStatus('1'),
                },
                {
                    id: 'btn-batch-disable-category',
                    name: 'batchDisable',
                    title: '批量禁用',
                    icon: 'bi bi-x-lg',
                    class: 'btn btn-sm btn-outline-warning',
                    click: () => batchToggleStatus('0'),
                },
                {
                    id: 'btn-refresh-category',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'child') {
                    return openForm(URL.create + '?parent_id=' + encodeURIComponent(row.id), '添加子栏目');
                }
                if (action === 'edit') {
                    return openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑栏目');
                }
                if (action === 'content') {
                    // 跳到内容管理并带上栏目筛选（P0-10 落地后该参数即生效）
                    window.location.href = URL.content + '?catid=' + encodeURIComponent(row.id);
                    return undefined;
                }
                if (action === 'del') {
                    return cmsDelete({
                        url: URL.del,
                        data: { id: row.id },
                        refresh: list,
                        // ⚠️ 确认框按 HTML 渲染，而 row 是未转义原始数据 → 必须转义
                        confirmMessage: '确定删除栏目「' + cmsEscape(row.name || '') + '」？'
                            + '（有子栏目或栏目下仍有内容时会被拒绝）',
                        successMsg: '栏目已删除',
                    });
                }
                return undefined;
            },
        });

        // 状态开关（启用 / 禁用）：提交 {id, status} 到 category/toggleStatus（目标值语义，
        // 见 §1.1⑬），失败自动回滚勾选。⚠️ 必须显式绑定：内核 `formatter: 'toggle'`
        // 只渲染不提交（全库无处理器，见 §1.1⑳）——此前未绑定，这个开关实际是死控件。
        cmsBindRowToggles({
            url: URL.toggle,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '栏目已启用' : '栏目已禁用'),
        });

        return list;
    },

    /**
     * 新增栏目表单（iframe 弹层内的一页）。
     *
     * P0 修复：此前这两个 action 没有导出方法，内核分发时只打印
     * 「控制器 ../plugins/wisecms/js/category 缺少方法 create」，
     * 且**不关闭 loading 遮罩** → 弹层永久转圈、表单完全不可用。
     * 现在提交逻辑收进 `cmsFormPage`（AJAX → 关层 → 刷新父列表）。
     */
    create() {
        return cmsFormPage({
            form: '#categoryForm',
            url: URL.create,
            successMsg: '栏目已创建',
            parentHook: 'cmsCategoryFormDone',
            redirect: cmsUrl('category/index'),
        });
    },

    edit() {
        return cmsFormPage({
            form: '#categoryForm',
            url: URL.edit,
            successMsg: '栏目已保存',
            parentHook: 'cmsCategoryFormDone',
            redirect: cmsUrl('category/index'),
        });
    },
};
