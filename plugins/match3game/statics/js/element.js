/**
 * element.js — 元素字典管理
 */
import { $, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/match3game/element/getList',
    save: '/plugin/match3game/element/save',
    del:  '/plugin/match3game/element/delete',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

export default {
    index() {
        const $modal = $('#element-modal');
        const $form  = $('#element-form');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center' },
                { field: 'code', title: '编码', width: 120 },
                { field: 'name', title: '名称' },
                { field: 'kind_text', title: '类型', width: 110 },
                { field: 'color', title: '配色', width: 100 },
                { field: 'weight', title: '权重', width: 80, align: 'center' },
                { field: 'sort_order', title: '排序', width: 70, align: 'center' },
                {
                    field: 'operate', title: '操作', width: 150, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() { return { keyword: $('#search-keyword').val() || '' }; },
            toolbarBtn: [
                { id: 'btn-add', name: 'add', title: '新增元素', icon: 'bi bi-plus-lg', class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); } },
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row) {
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => { $('#search-keyword').val(''); refresh(table); });

        function openModal(row) {
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑元素' : '新增元素');
            $('#form-status').val(1);
            if (row) {
                $('#form-code').val(row.code || '');
                $('#form-name').val(row.name || '');
                $('#form-kind').val(row.kind || 1);
                $('#form-color').val(row.color || '');
                $('#form-weight').val(row.weight || 0);
                $('#form-clear-rule').val(row.clear_rule || '');
                $('#form-sort-order').val(row.sort_order || 0);
                $('#form-status').val(row.status != null ? row.status : 1);
            }
            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除元素「' + (row.name || '') + '」吗？');
            if (!ok) return;
            const res = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (res) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const data = {};
            $form.find('input, select, textarea').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });
            const res = await submit({ url: URL.save, data, message: (Number($('#form-id').val()) > 0 ? '修改成功' : '添加成功') });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};
