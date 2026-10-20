/**
 * 标签管理页面控制器（UIUX-P0-14，作为 cms-admin.js 的首个落地样板）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/tag`
 * （`TagController` → snake_case `tag`），无需在模板里引脚本。
 *
 * 对应控制器动作：
 *   index  → 列表（AJAX 分支返回标准 paginate 契约，读 page/limit）
 *   create → 新增（只接受 name，站点由服务端注入）
 *   edit   → 编辑（name + seo_title / seo_keywords / seo_description，带站点归属校验）
 *   delete → 删除（带站点归属校验）
 *
 * @see statics/js/cms-admin.js
 */

import { cmsTable, cmsUrl, cmsDelete, cmsFormDialog, cmsRefresh, cmsBatch, cmsToast } from './cms-admin.js';

const URL = {
    list: cmsUrl('tag/index'),
    create: cmsUrl('tag/create'),
    edit: cmsUrl('tag/edit'),
    del: cmsUrl('tag/delete'),
    batchDel: cmsUrl('tag/batchDelete'),
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

/** 新增：后端只接受 name */
const CREATE_FIELDS = [
    {
        name: 'name',
        label: '标签名',
        type: 'text',
        required: true,
        validation: 'required|maxLength:50',
        placeholder: '如：人工智能',
        messages: { required: '请填写标签名', maxLength: '标签名最多 50 个字符' },
        help: '同一站点内标签名唯一，重复创建会直接复用已有标签',
    },
];

/** 编辑：controller 接受 name + 三项 SEO 字段 */
function editFields(row) {
    return [
        {
            name: 'name',
            label: '标签名',
            type: 'text',
            required: true,
            validation: 'required|maxLength:50',
            value: row.name || '',
            messages: { required: '请填写标签名', maxLength: '标签名最多 50 个字符' },
        },
        { name: 'seo_title', label: 'SEO 标题', type: 'text', value: row.seo_title || '' },
        { name: 'seo_keywords', label: 'SEO 关键词', type: 'text', value: row.seo_keywords || '', help: '多个关键词用英文逗号分隔' },
        { name: 'seo_description', label: 'SEO 描述', type: 'textarea', rows: 3, value: row.seo_description || '' },
    ];
}

function openForm(row, list) {
    const isEdit = !!row;

    return cmsFormDialog({
        title: isEdit ? '编辑标签' : '添加标签',
        url: isEdit ? URL.edit : URL.create,
        area: isEdit ? ['560px', '600px'] : ['480px', '340px'],
        fields: isEdit ? editFields(row) : CREATE_FIELDS,
        successMsg: isEdit ? '标签已更新' : '标签已创建',
        onSubmit(payload) {
            // 编辑需带 id；站点归属由服务端校验，前端不传 site_id
            if (isEdit) payload.id = row.id;
            return payload;
        },
        onSuccess() {
            cmsRefresh(list);
        },
    });
}

export default {
    /**
     * 列表页入口（与 action 同名，由 wise-bootstrap 调用）
     */
    index() {
        function selectedIds() {
            const ids = window.Wise.table.getSelectedIds('#table');
            return Array.isArray(ids) ? ids : [];
        }

        const list = cmsTable({
            url: URL.list,
            shape: 'standard', // Tag::index 走标准 paginate 契约
            pageSize: 20,
            // 批量：后端有 batchDelete（P1-19）→ 开勾选（必须同时声明 { checkbox: true } 列）
            checkboxHeader: true,
            // 后端固定按 count desc 排序且不接受排序参数 → 列不开 sortable
            columns: [
                { checkbox: true },
                { field: 'id', title: 'ID', width: 80, align: 'center' },
                { field: 'name', title: '标签名' },
                { field: 'alias', title: 'URL 别名', width: 200 },
                { field: 'count', title: '引用数', width: 100, align: 'right', formatter: 'numberAbbr' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 140,
                    align: 'center',
                    formatter: (v, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            query() {
                return { keyword: ($('#filter-keyword').val() || '').trim() };
            },

            toolbarBtn: [
                {
                    id: 'btn-add-tag',
                    name: 'add',
                    title: '添加标签',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => openForm(null, list),
                },
                {
                    id: 'btn-batch-del-tag',
                    name: 'batchDelete',
                    title: '批量删除',
                    icon: 'bi bi-trash',
                    class: 'btn btn-sm btn-outline-danger',
                    click: () => {
                        const ids = selectedIds();
                        if (!ids.length) {
                            cmsToast('请先勾选要删除的标签', 'warning');
                            return;
                        }
                        return cmsBatch({
                            url: URL.batchDel,
                            ids: ids,
                            confirmMessage: '将删除选中的 ' + ids.length + ' 个标签（被内容引用的标签会解除关联），确定继续？',
                            successMsg: '已删除选中的标签',
                            refresh: list,
                        });
                    },
                },
                {
                    id: 'btn-refresh-tag',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') return openForm(row, list);

                if (action === 'del') {
                    return cmsDelete({
                        url: URL.del,
                        data: { id: row.id },
                        refresh: list,
                        confirmMessage: '确定删除标签「' + (row.name || '') + '」？',
                        successMsg: '标签已删除',
                    });
                }
                return undefined;
            },
        });

        // 筛选条交互：搜索按钮 / 回车 / 重置
        $('#btn-search').on('click', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                cmsRefresh(list);
            }
        });
        $('#btn-reset').on('click', () => {
            $('#filter-keyword').val('');
            cmsRefresh(list);
        });

        return list;
    },
};
