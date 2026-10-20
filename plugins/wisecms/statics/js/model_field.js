/**
 * 字段管理页面控制器（UIUX-P0-18 列表 + P0-29 拖拽排序 + P0-30 导出）
 *
 * ⚠️ 文件名必须是 **`model_field.js`**：框架按「控制器类名」推导加载路径
 * （`app/plugin/base/BaseController.php:47-53` → `getControllerShortName()` 做蛇形化，
 * `ModelField` → `model_field`），**与模板目录 `view/admin/field/` 无关**。
 * 取名 `field.js` 会让控制器永不加载，且**不报错**（只有 debug 日志里一行 controller 加载失败）。
 *
 * 对应控制器动作：
 *   modelField/index      → 字段列表（**无 count 的自定义信封** → shape 'modelField'），
 *                           必须带 `?model_id=N`，否则后端按 model_id=0 查（永远为空）
 *   modelField/create|edit→ iframe 弹层表单（保存后回调父页钩子）
 *   modelField/delete     → DDL 高危（DROP COLUMN），L3 输入确认词
 *   modelField/sort       → **拖拽排序**：POST `{sort: {字段ID: 序号}}`
 *   modelField/export     → GET 下载（`?modelId=N`，注意是 camelCase）
 *
 * ⚠️ 为什么不用 `extend.dragsort_url`：EnhancedTable 内建拖拽发的是
 *    `{ids:"1,2,3", changeid, pid, field:'sort', ...}`，而 `ModelField::sort` 读 `sort` 映射
 *    → 直连会**收到空数组却仍回「排序更新成功」**（静默假成功）。故改用 SortableJS 自组
 *    payload（详见任务清单 §1.1⑫）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsDangerConfirm, cmsEscape,
    cmsFetchText,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('modelField/index'),
    create: cmsUrl('modelField/create'),
    edit: cmsUrl('modelField/edit'),
    del: cmsUrl('modelField/delete'),
    sort: cmsUrl('modelField/sort'),
    exportFields: cmsUrl('modelField/export'),
    typeForm: cmsUrl('modelField/getAddForm'),
    models: cmsUrl('model/index'),
};

/** 与 `FieldFactory` 的 16 种字段类型对应；未知类型回退显示原始 ident（不编造） */
const TYPE_LABELS = {
    text: '单行文本',
    textarea: '多行文本',
    number: '整数',
    select: '下拉选择',
    radio: '单选按钮',
    checkbox: '多选框',
    toggle: '开关',
    date: '日期',
    datetime: '日期时间',
    color: '颜色',
    image: '单图上传',
    images: '多图上传',
    file: '文件上传',
    markdown: 'Markdown',
    tags: '标签输入',
    editor: '富文本',
};

const OPERATE = [
    { action: 'edit', title: '编辑字段', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del', title: '删除字段（高危）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

const qs = new URLSearchParams(window.location.search);
const modelId = parseInt(qs.get('model_id'), 10) || 0;

function typeLabel(value) {
    return TYPE_LABELS[value] || value || '';
}

export default {
    async index() {
        // 缺少 model_id 时不要静默查 model_id=0（结果永远为空，看起来像"坏了"）
        if (!modelId) {
            $('#cmsFieldBody').html(
                '<div class="alert alert-warning mb-0">'
                + '缺少 <code>model_id</code> 参数：请从「模型管理」列表点某个模型的「字段管理」进入。'
                + '</div>'
            );
            return null;
        }

        let list = null;

        // ---- 顶部模型信息（字段接口不返回模型，单独取一次） ----
        (async function fillModelName() {
            try {
                const res = await window.Wise.util.http.get(URL.models, { page: 1, limit: 100 });
                const rows = (((res || {}).data || {}).data) || [];
                const model = rows.filter((m) => String(m.id) === String(modelId))[0];
                if (!model) {
                    $('#cmsFieldModelName').text('（未找到该模型或无权访问）');
                    return;
                }
                $('#cmsFieldModelName').text(model.title || model.name || ('#' + modelId));
                $('#cmsFieldModelTable').text(model.table_name || '');
                $('#btn-add-field').prop('disabled', false);
            } catch (e) {
                $('#cmsFieldModelName').text('#' + modelId);
            }
        })();

        // ---- 表单弹层：保存后由 iframe 内页面回调 ----
        function openForm(url, title) {
            const index = window.Wise.ui.dialog.open(url, title, { area: ['900px', '720px'], resize: true });
            window.cmsFieldFormDone = function () {
                try {
                    window.Wise.ui.dialog.close(index);
                } catch (e) { /* 弹层可能已被手动关闭 */ }
                cmsRefresh(list);
            };
            return index;
        }

        /** L3：删除字段 = DROP COLUMN */
        async function removeField(row) {
            const ok = await cmsDangerConfirm({
                title: '删除字段（高危，不可恢复）',
                word: row.field,
                confirmText: '确认删除',
                area: ['560px', '400px'],
                html: '<div class="alert alert-danger py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将执行 <b>DROP COLUMN</b>：字段 <b>' + cmsEscape(row.name || '')
                    + '</b>（标识 <code>' + cmsEscape(row.field || '') + '</code>）'
                    + '在数据表中的该列与其中数据将<b>不可恢复</b>。'
                    + '</div>',
            });
            if (!ok) return;

            await cmsAction({
                url: URL.del,
                data: { id: row.id },
                successMsg: '字段已删除',
                refresh: list,
            });
        }

        /**
         * 拖拽排序（P0-29）：SortableJS 自组 `{sort: {id: 序号}}` 提交。
         * 每次表格重绘后重绑（bootstrap-table 可能替换 tbody 元素）。
         */
        function enableDragSort() {
            let boundBody = null;
            let instance = null;

            return function bind() {
                const tbody = document.querySelector('#table tbody');
                if (!tbody || tbody === boundBody) return;

                if (instance) {
                    try { instance.destroy(); } catch (e) { /* 忽略 */ }
                    instance = null;
                }

                if (typeof window.Sortable !== 'function') {
                    cmsToast('拖拽排序组件未加载，请改用「编辑字段」里的排序值', 'warning');
                    boundBody = tbody;
                    return;
                }

                boundBody = tbody;
                instance = window.Sortable.create(tbody, {
                    handle: '.cms-drag-handle',
                    animation: 150,
                    onEnd() {
                        const handles = Array.prototype.slice.call(tbody.querySelectorAll('.cms-drag-handle'));
                        const sort = {};
                        handles.forEach((el, i) => {
                            if (el.dataset.id) sort[el.dataset.id] = i;
                        });
                        if (!Object.keys(sort).length) return;

                        window.Wise.util.http.post(URL.sort, { sort: sort })
                            .then((res) => {
                                cmsToast((res && res.msg) || '排序已更新', 'success');
                                cmsRefresh(list);
                            })
                            .catch(() => {
                                // 失败原因已由拦截器 toast；重新拉取以回到服务端真实顺序
                                cmsRefresh(list);
                            });
                    },
                });
            };
        }

        list = cmsTable({
            url: URL.list,
            shape: 'modelField', // data = {code, data:[], msg}：无 count 且全量 → 前端分页
            pagination: false,   // 单模型字段数有限，全量展示更利于拖拽排序
            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'drag',
                    title: '拖拽',
                    width: 60,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => '<i class="bi bi-grip-vertical cms-drag-handle"'
                        + ' style="cursor:grab" title="拖动调整顺序" data-id="' + row.id + '"></i>',
                },
                { field: 'field', title: '字段标识', width: 150, align: 'left', formatter: 'normal' },
                { field: 'name', title: '字段名称', align: 'left', formatter: 'normal' },
                {
                    field: 'form_type',
                    title: '表单类型',
                    width: 130,
                    align: 'center',
                    formatter: 'status',
                    // status formatter 用 searchList 做文案映射；未知类型回退原始 ident
                    searchList: TYPE_LABELS,
                    custom: { text: 'secondary' },
                },
                { field: 'data_type', title: '数据类型', width: 110, align: 'left', formatter: 'normal' },
                { field: 'length', title: '长度', width: 90, align: 'center', formatter: 'normal', visible: false },
                {
                    field: 'is_required',
                    title: '必填',
                    width: 80,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '否', 1: '必填' },
                    custom: { 0: 'secondary', 1: 'warning' },
                },
                {
                    field: 'is_unique',
                    title: '唯一',
                    width: 80,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '否', 1: '唯一' },
                    custom: { 0: 'secondary', 1: 'info' },
                },
                {
                    field: 'is_search',
                    title: '可搜索',
                    width: 90,
                    align: 'center',
                    visible: false,
                    formatter: 'status',
                    searchList: { 0: '否', 1: '是' },
                    custom: { 0: 'secondary', 1: 'success' },
                },
                { field: 'sort_order', title: '排序', width: 80, align: 'center', formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    // ModelField 无 toggleStatus 接口 → 只读徽章（不做假开关）
                    formatter: 'status',
                    searchList: { 0: '禁用', 1: '启用' },
                    custom: { 0: 'secondary', 1: 'success' },
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 140,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            query() {
                // 字段列表按模型过滤；后端 from param('model_id')
                return { model_id: modelId };
            },

            toolbarBtn: [
                {
                    id: 'btn-add-field',
                    name: 'add',
                    title: '添加字段',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    disabled: true, // 模型信息加载完成前不可点（避免参数不完整）
                    click: () => openForm(URL.create + '?model_id=' + modelId, '添加字段'),
                },
                {
                    id: 'btn-export-fields',
                    name: 'export',
                    title: '导出字段',
                    icon: 'bi bi-download',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => { window.location.href = URL.exportFields + '?modelId=' + modelId; },
                },
                {
                    id: 'btn-back-model',
                    name: 'back',
                    title: '返回模型列表',
                    icon: 'bi bi-arrow-left',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => { window.location.href = URL.modelList; },
                },
                {
                    id: 'btn-refresh-field',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    return openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑字段');
                }
                if (action === 'del') {
                    // 系统字段由后端拒绝（is_system=1）；这里不隐藏按钮，让后端给出明确原因
                    return removeField(row);
                }
                return undefined;
            },
        });

        const bindDragSort = enableDragSort();
        $('#table').on('post-body.bs.table', bindDragSort);

        return list;
    },

    /**
     * 添加字段页（由 wise-bootstrap 按 action 名调用；与列表页共用本控制器）
     */
    create() {
        bindFieldForm({
            modelId: $('#fieldForm input[name="model_id"]').val(),
            typeSelector: '#field-form-type',
            configSelector: '#field-type-config',
            successMsg: '字段已创建',
        });
    },

    /**
     * 编辑字段页
     */
    edit() {
        bindFieldForm({
            modelId: $('#fieldForm').data('model-id'),
            typeSelector: null, // 编辑页的 form_type 是只读展示，不触发切换
            configSelector: '#field-type-config',
            currentType: $('#field-form-type').val(),
            successMsg: '字段已更新',
        });
    },
};

/* ============================================================================
 * 字段表单页（create / edit）公共逻辑 —— UIUX-P0-31
 * ========================================================================== */

/** 配置区的提示/加载/失败态统一入口 */
function setConfigHint($container, html) {
    $container.html('<div class="text-muted small py-2">' + html + '</div>');
}

/**
 * 加载字段类型的专属配置区。
 *
 * ⚠️ `modelField/getAddForm` 返回的是**裸 HTML**（不是 `{code,msg,data}` 信封），
 * 因此必须用 `cmsFetchText()`（`fetch().text()`）；走 `Wise.util.http` 会被当 JSON 解析而失败。
 *
 * ⚠️ 实测：16 种字段类型里**只有 `editor` 实现了 `AddForm.php`/`EditForm.php`**，
 * 其余 15 种 `FieldFactory::getAddFormHtml()` 直接返回空串。所以空响应不是错误，
 * 必须给出**明确文案**，否则用户看到空白区会以为页面坏了。
 */
async function loadTypeConfig(type, $container) {
    if (!$container.length) return;
    if (!type) {
        setConfigHint($container, '请先选择表单类型');
        return;
    }

    setConfigHint($container, '<span class="spinner-border spinner-border-sm me-2"></span>正在加载类型配置…');

    try {
        const html = await cmsFetchText(URL.typeForm, { field_type: type });
        if (html && html.trim() !== '') {
            $container.html(html);
            return;
        }
        setConfigHint(
            $container,
            '「' + (TYPE_LABELS[type] || type) + '」暂无专属配置项（后端未实现该类型的配置表单）。'
        );
    } catch (e) {
        setConfigHint($container, '类型配置加载失败：' + (e && e.message ? e.message : '未知错误'));
    }
}

/**
 * 字段表单页公共绑定：AJAX 提交 + 返回 + 类型配置区。
 *
 * @param {Object} spec
 * @param {string} [spec.modelId]        用于「返回」时带上 model_id
 * @param {string} [spec.typeSelector]   非空则在切换时重载配置区（create 页）
 * @param {string} [spec.configSelector]
 * @param {string} [spec.currentType]    edit 页当前类型（用于空态文案）
 * @param {string} [spec.successMsg]
 */
function bindFieldForm(spec) {
    const cfg = spec || {};
    const $form = $('#fieldForm');
    if (!$form.length) return;

    const backUrl = URL.list + (cfg.modelId ? '?model_id=' + encodeURIComponent(cfg.modelId) : '');

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsFieldFormDone === 'function') {
                window.parent.cmsFieldFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }
        window.location.href = backUrl;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        // 用 FormData 而非对象：原生处理 `setting[toolbar]` 这类嵌套命名的序列化
        // （自己拼对象要解析方括号，容易出错）；内核 http 对 FormData 直接作 body，
        // 不覆盖 Content-Type → multipart 正确，ThinkPHP 侧自动还原成嵌套数组。
        const res = await cmsAction({
            url: $form.attr('action'),
            data: new FormData(this),
            successMsg: cfg.successMsg || '保存成功',
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-field-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });

    const $config = $(cfg.configSelector || '#field-type-config');
    if (cfg.typeSelector) {
        const $type = $(cfg.typeSelector);
        $type.on('change', function () { loadTypeConfig($(this).val(), $config); });
        loadTypeConfig($type.val(), $config);
    } else if ($config.length && ($config.html() || '').trim() === '') {
        setConfigHint(
            $config,
            '「' + (TYPE_LABELS[cfg.currentType] || cfg.currentType || '') + '」暂无专属配置项（后端未实现配置表单）。'
        );
    }
}
