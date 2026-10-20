/**
 * warehouse.js — 仓库管理（ESM 控制器，auto-dispatch）
 *
 * 列表与新增/编辑共用本页，编辑以操作列整行 row 预填。
 * HTTP 统一走 wise-util.js 的 http（successCode=0，自动 toast 错误）。
 */

import { http, $, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/warehouse/getList',
    save: '/plugin/smartstock/warehouse/save',
    del:  '/plugin/smartstock/warehouse/delete',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

const Controller = {
    index() {
        const $modal = $('#warehouse-modal');
        const $form  = $('#warehouse-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // 规则写在模板的 data-validation 上；window.WsFormValidator 是组件官方挂载的全局
        const validator = new window.WsFormValidator('warehouse-form', {
            liveValidation: true,
            showIcons: true,
        });

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'code', title: '仓库编码', width: 120 },
                { field: 'name', title: '仓库名称' },
                { field: 'contact', title: '联系人', width: 110 },
                { field: 'phone', title: '联系电话', width: 140 },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter(value, row) {
                        return badge(row.status_text || '-', Number(row.status) === 1 ? 'success' : 'secondary');
                    },
                },
                { field: 'create_time', title: '创建时间', width: 150 },
                {
                    field: 'operate',
                    title: '操作',
                    width: 150,
                    align: 'center',
                    formatter(value, row) {
                        return operateButtons(OPERATE, row);
                    },
                },
            ],
            query() {
                return {
                    name: $('#search-name').val() || '',
                    code: $('#search-code').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add', name: 'add', title: '新增仓库', icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); },
                },
                {
                    id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1', click() { refresh(table); },
                },
            ],
            onClick(action, row) {
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-name').val('');
            $('#search-code').val('');
            $('#search-status').val('');
            refresh(table);
        });

        function openModal(row) {
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑仓库' : '新增仓库');
            if (row) {
                $('#form-code').val(row.code || '');
                $('#form-name').val(row.name || '');
                $('#form-address').val(row.address || '');
                $('#form-contact').val(row.contact || '');
                $('#form-phone').val(row.phone || '');
                $('#form-sort').val(row.sort_order || 0);
                $('#form-status').val(row.status != null ? row.status : 1);
                $('#form-remark').val(row.remark || '');
            } else {
                $('#form-sort').val(0);
                $('#form-status').val(1);
            }
            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除仓库「' + (row.name || '') + '」吗？若有库存数据可能无法删除。');
            if (!ok) return;
            const res = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (res) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();

            // 校验交由 WsFormValidator 统一负责（错误内联到字段，不再弹 toast）
            if (validator.validateForm() !== true) return;

            const data = {};
            $form.find('input, select, textarea').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });
            const res = await submit({
                url: URL.save,
                data,
                message: (Number($('#form-id').val()) > 0 ? '修改成功' : '添加成功'),
            });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};

export default Controller;
