/**
 * 模型管理页面控制器（UIUX-P0-17 + UIUX-P0-28 模型导入）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/model`
 * （`ModelController` → snake_case `model`）。
 *
 * 对应控制器动作：
 *   model/index        → 列表（**layui 形状**：`data = {code, data:[], count, msg}` → shape 'model'）
 *   model/create|edit  → 整页表单（本页只跳转；模型表单字段多，不塞抽屉）
 *   model/delete       → **DDL 高危**（DROP TABLE），L3 输入确认词
 *   model/toggleStatus → 启禁（行内开关，幂等目标值）
 *   model/export       → GET 下载（`?id=N`）
 *   model/import       → 上传 JSON 建表（multipart，`file` 字段）
 *   modelField/index   → 进入该模型的字段管理（`?model_id=N`）
 *
 * ⚠️ `onClick(action, row)` 里的 row 是**未经转义**的原始数据
 *    （`wise-table.js:310` 直接取 `bootstrapTable('getData')`），
 *    拼进弹层/HTML 前必须自己 `cmsEscape` —— 这是与列 formatter 相反的一侧。
 *
 * 未实现：拖拽调整模型顺序（后端 `Model` 无 sort 接口，不配 `dragsort_url` 以免静默失效）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsConfirm,
    cmsDangerConfirm, cmsEscape, cmsBindRowToggles, cmsFormPage,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('model/index'),
    create: cmsUrl('model/create'),
    edit: cmsUrl('model/edit'),
    del: cmsUrl('model/delete'),
    toggle: cmsUrl('model/toggleStatus'),
    exportOne: cmsUrl('model/export'),
    importModel: cmsUrl('model/import'),
    fields: cmsUrl('modelField/index'),
};

// 颜色只承载"主操作 / 危险"两级（UIUX-P1-04）
const OPERATE = [
    { action: 'fields', title: '字段管理', icon: 'bi bi-input-cursor-text', class: 'btn-outline-secondary' },
    { action: 'edit', title: '编辑模型', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'export', title: '导出模型', icon: 'bi bi-download', class: 'btn-outline-secondary' },
    { action: 'del', title: '删除模型（高危）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

export default {
    index() {
        let list = null;

        /** L3：删除模型 = DROP TABLE，需逐字输入模型标识 */
        async function removeModel(row) {
            const isSystem = String(row.is_system) === '1';
            const ok = await cmsDangerConfirm({
                title: '删除模型（高危，不可恢复）',
                word: row.name,
                confirmText: '确认删除',
                area: ['560px', '400px'],
                html: '<div class="alert alert-danger py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将执行 <b>DROP TABLE</b>：模型 <b>' + cmsEscape(row.title || '')
                    + '</b>（标识 <code>' + cmsEscape(row.name || '') + '</code>）'
                    + '及其数据表中的内容数据将<b>不可恢复</b>。'
                    + (isSystem ? '<br><b>注意：这是系统模型</b>，删除后相关功能会失效。' : '')
                    + '</div>',
            });
            if (!ok) return;

            await cmsAction({
                url: URL.del,
                data: { id: row.id },
                successMsg: '模型已删除',
                refresh: list,
            });
        }

        /** P0-28：导入模型（上传 JSON → 确认 → 建表 DDL） */
        function bindImport() {
            $('#model-import-file').on('change', async function () {
                const input = this;
                const file = input.files && input.files[0];
                if (!file) return;

                const ok = await cmsConfirm(
                    '将按文件「' + cmsEscape(file.name) + '」中的定义创建模型与字段'
                    + '（会执行建表 DDL），确定继续？',
                    '导入模型确认'
                );
                if (!ok) { input.value = ''; return; }

                const formData = new FormData();
                formData.append('file', file);

                const http = window.Wise.util.http;
                const loading = window.Wise.ui.loading;
                if (loading) loading.show();

                try {
                    const res = await http.post(URL.importModel, formData);
                    cmsToast((res && res.msg) || '模型导入成功', 'success');
                    cmsRefresh(list);
                } catch (e) {
                    // 业务失败的原因已由内核拦截器统一 toast（如「文件格式错误」）
                } finally {
                    if (loading) loading.hide();
                    input.value = '';
                }
            });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'model', // Model::index 自造了 layui 信封，必须走 responseHandler
            pageSize: 15,
            // 批量：后端无批量接口（阶段 0 结论④）→ 不开 checkboxHeader
            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                { field: 'name', title: '模型标识', width: 150, align: 'left', formatter: 'normal' },
                { field: 'title', title: '模型名称', align: 'left', formatter: 'normal' },
                { field: 'table_name', title: '数据表', width: 200, align: 'left', formatter: 'normal' },
                {
                    field: 'is_system',
                    title: '类型',
                    width: 110,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '自定义模型', 1: '系统模型' },
                    custom: { 0: 'secondary', 1: 'info' },
                },
                { field: 'sort', title: '排序', width: 80, align: 'center', formatter: 'normal' },
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
                    field: 'operate',
                    title: '操作',
                    width: 220,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-model',
                    name: 'add',
                    title: '添加模型',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => { window.location.href = URL.create; },
                },
                {
                    id: 'btn-import-model',
                    name: 'import',
                    title: '导入模型',
                    icon: 'bi bi-upload',
                    class: 'btn btn-sm btn-outline-success',
                    click: () => $('#model-import-file').trigger('click'),
                },
                {
                    id: 'btn-refresh-model',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'fields') {
                    window.location.href = URL.fields + '?model_id=' + encodeURIComponent(row.id);
                    return undefined;
                }
                if (action === 'edit') {
                    window.location.href = URL.edit + '?id=' + encodeURIComponent(row.id);
                    return undefined;
                }
                if (action === 'export') {
                    // GET 下载：后端返回 Content-Disposition，直接赋值 href 即可，不跳页面
                    window.location.href = URL.exportOne + '?id=' + encodeURIComponent(row.id);
                    return undefined;
                }
                if (action === 'del') return removeModel(row);
                return undefined;
            },
        });

        bindImport();

        // 状态开关（启用 / 禁用）：提交 {id, status} 到 model/toggleStatus（目标值语义），
        // 失败自动回滚勾选。⚠️ 必须显式绑定：内核 `formatter: 'toggle'` 只渲染不提交
        // （全库无处理器，见 §1.1⑳ 与 `cms-admin.js::cmsBindRowToggles`）——此前未绑定，
        // 这个开关实际是死控件。
        cmsBindRowToggles({
            url: URL.toggle,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '模型已启用' : '模型已禁用'),
        });

        return list;
    },

    /**
     * 新增模型表单（整页）。
     *
     * 此前未导出该方法 → 内核分发时打印「缺少方法 create」；
     * 现将提交收进 `cmsFormPage`：AJAX 提交 + 成功 toast + 回列表，
     * 避免原生 POST 后整页刷新丢失站点上下文。
     */
    create() {
        return cmsFormPage({
            form: '#modelForm',
            url: URL.create,
            successMsg: '模型已创建',
            redirect: cmsUrl('model/index'),
        });
    },

    edit() {
        return cmsFormPage({
            form: '#modelForm',
            url: URL.edit,
            successMsg: '模型已保存',
            redirect: cmsUrl('model/index'),
        });
    },
};
