/**
 * 内容模板管理页面控制器（UIUX-P2-03）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/template`
 * （`TemplateController` → snake_case `template`）。**列表页与表单页共用本模块**。
 *
 * 对应控制器动作：
 *   template/index           → 列表（标准 paginate 契约；支持 type / status / keyword 筛选）
 *   template/create          → 表单 + 提交
 *   template/edit            → 表单 + 提交（**同时承担状态开关**：只提交 {id, status}）
 *   template/delete          → **硬删除**（模板表无软删）
 *   template/installDefaults → 安装内置默认模板（幂等）
 *
 * ⚠️ 五处已核实的事实：
 *   1. **模板全站共享**：`wisecms_template` 无 `site_id` 列 → 列表**不跟随后台站点选择器**
 *      （与「广告管理」同口径），页面已显式说明。
 *   2. **状态开关复用 `template/edit`**：`Template::edit` 收到单字段 `{status}` 即部分更新
 *      （`collect()` 按「传了哪些键就更新哪些」处理）。
 *   3. **无软删列** → `cmsDelete` **不传恢复地址**，退化为「确认 → 执行 → toast」，
 *      不显示撤销按钮（撤销必然失败，属假交互）。
 *   4. **`presets` / `seo_defaults` 列表里是 JSON 原文**（模型未声明 `$json` 类型）→
 *      本模块自行解析出键数量用于展示；解析失败降级为 `—`，不影响列表渲染。
 *   5. **JSON 合法性由后端判**（前端不重复实现）：非法 JSON 会以
 *      `「预设内容」不是合法 JSON：…` 通过 toast 回显（内核 `http.interceptor` 把
 *      信封 `msg` 抛成 Error.message，见 wise-ui.js:813-817）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsAction, cmsDelete,
    cmsEscape, cmsDecodeEntities, cmsBindRowToggles,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('template/index'),
    create: cmsUrl('template/create'),
    edit: cmsUrl('template/edit'),
    del: cmsUrl('template/delete'),
    installDefaults: cmsUrl('template/installDefaults'),
};

/** 与后端 `TemplateService::TYPE_LABELS` 同步（列表徽章用） */
const TYPE_LABELS = { article: '文章', page: '页面', product: '产品', custom: '自定义' };

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del', title: '删除（不可恢复）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

/** 当前筛选条件（空值由 `cmsTable` 的 queryParams 丢弃，不会发出空参数） */
function currentFilter() {
    return {
        type: $('#filter-type').val() || '',
        // 注意用 val() 原样取值：`'0'`（禁用）是有效筛选，不能被判空
        status: $('#filter-status').val(),
        keyword: ($('#filter-keyword').val() || '').trim(),
    };
}

/**
 * JSON 原文 → 键数量（非法/空 → 0）
 *
 * ⚠️ 必须先 `cmsDecodeEntities()`：内核渲染表格时会对单元格值做 HTML 转义，
 * 直接 `JSON.parse` 会因 `&quot;` 而必然失败 —— 表现为「明明配了却恒显示 —」。
 */
function jsonKeyCount(raw) {
    if (!raw) {
        return 0;
    }

    const text = typeof raw === 'string' ? cmsDecodeEntities(raw) : raw;

    try {
        const obj = typeof text === 'string' ? JSON.parse(text) : text;
        return (obj && typeof obj === 'object') ? Object.keys(obj).length : 0;
    } catch (e) {
        return 0;
    }
}

/** 键数量徽章（0 项显示占位，避免"看起来配了其实没配"） */
function countBadge(raw) {
    const count = jsonKeyCount(raw);
    return count > 0
        ? '<span class="badge bg-light text-dark border">' + count + ' 项</span>'
        : '<span class="text-muted small">—</span>';
}

/** 表单页提交（create / edit 共用） */
function bindForm(defaultMsg) {
    const $form = $('#templateForm');
    if (!$form.length) {
        return;
    }

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsTemplateFormDone === 'function') {
                window.parent.cmsTemplateFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        const res = await cmsAction({
            url: $form.attr('action'),
            data: new FormData(this),
            successMsg: defaultMsg,
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-template-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口 */
    index() {
        let list = null;

        /** 打开模板表单（iframe 弹层，与单页/广告同一套父子回调约定） */
        function openForm(url, title) {
            const index = window.Wise.ui.dialog.open(url, title, { area: ['840px', '700px'], resize: true });

            window.cmsTemplateFormDone = function () {
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
            shape: 'standard',
            query: currentFilter,

            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', visible: false, formatter: 'normal' },
                {
                    field: 'name',
                    title: '模板名称',
                    width: 160,
                    align: 'left',
                    formatter: (value, row) => '<a href="javascript:;" data-action="edit" data-id="'
                        + cmsEscape(row.id) + '" title="点击编辑">' + (value || '') + '</a>'
                        + (Number(row.is_system) === 1
                            ? ' <span class="badge bg-secondary ms-1" title="由「安装默认模板」装入">内置</span>'
                            : ''),
                },
                {
                    field: 'alias',
                    title: '标识',
                    width: 140,
                    align: 'left',
                    formatter: (value) => '<code>' + cmsEscape(value || '') + '</code>',
                },
                {
                    field: 'type',
                    title: '类型',
                    width: 80,
                    align: 'center',
                    formatter: (value) => '<span class="badge bg-light text-dark">'
                        + cmsEscape(TYPE_LABELS[value] || value || '') + '</span>',
                },
                { field: 'category', title: '分类', width: 90, align: 'left', formatter: 'normal' },
                { field: 'presets', title: '预设内容', width: 90, align: 'center', formatter: (value) => countBadge(value) },
                { field: 'seo_defaults', title: 'SEO 默认值', width: 100, align: 'center', formatter: (value) => countBadge(value) },
                { field: 'sort', title: '排序', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.edit,
                    labels: { on: '启用', off: '禁用' },
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 110,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-template',
                    name: 'add',
                    title: '添加模板',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => openForm(URL.create, '添加内容模板'),
                },
                {
                    id: 'btn-install-defaults',
                    name: 'install',
                    title: '安装默认模板',
                    icon: 'bi bi-download',
                    class: 'btn btn-sm btn-outline-primary',
                    click: () => cmsAction({
                        method: 'POST',
                        url: URL.installDefaults,
                        data: {},
                        // 幂等（已存在的 alias 跳过）→ 按 L2 处理：确认框 + 回显影响面
                        confirmMessage: '将装入 4 个内置内容模板（新闻稿 / 教程 / FAQ / 关于我们）。'
                            + '<br>已存在同标识的模板会<b>自动跳过</b>，不会覆盖你改过的内容。确定继续？',
                        successMsg: '默认模板已处理',
                        refresh: list,
                    }),
                },
                {
                    id: 'btn-refresh-template',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    return openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑内容模板');
                }
                if (action === 'del') {
                    const isSystem = Number(row.is_system) === 1;
                    return cmsDelete({
                        url: URL.del,
                        data: { id: row.id },
                        refresh: list,
                        // 模板表无软删 → 真删除且**无回收站**；确认框按 HTML 渲染，故转义 row 值
                        confirmMessage: '确定永久删除模板「' + cmsEscape(row.name || '') + '」？'
                            + '内容模板<b>没有回收站</b>，删除后<b>无法恢复</b>。'
                            + (isSystem
                                ? '<br><span class="text-warning">这是内置模板，可稍后用「安装默认模板」重新装回。</span>'
                                : ''),
                        successMsg: '模板已删除',
                    });
                }
                return undefined;
            },
        });

        // 状态开关（启用 / 禁用）：提交 {id, status} 到 template/edit，失败自动回滚
        cmsBindRowToggles({
            url: URL.edit,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '已启用' : '已禁用'),
        });

        // 筛选交互（条件变化即局部刷新，不整页跳转）
        $('#btn-search-template').on('click', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', function (e) {
            if (e.key === 'Enter') {
                cmsRefresh(list);
            }
        });
        $('#filter-type, #filter-status').on('change', () => cmsRefresh(list));
        $('#btn-reset-template').on('click', () => {
            $('#filter-type').val('');
            $('#filter-status').val('');
            $('#filter-keyword').val('');
            cmsRefresh(list);
        });

        return list;
    },

    /** 添加模板表单页 */
    create() {
        bindForm('模板创建成功');
    },

    /** 编辑模板表单页 */
    edit() {
        bindForm('更新成功');
    },
};
