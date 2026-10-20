/**
 * prop.js — 道具配置管理
 */
import { $, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/match3game/prop/getList',
    save: '/plugin/match3game/prop/save',
    del:  '/plugin/match3game/prop/delete',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

export default {
    index() {
        const $modal = $('#prop-modal');
        const $form  = $('#prop-form');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center' },
                { field: 'code', title: '编码', width: 150 },
                { field: 'name', title: '名称' },
                { field: 'kind_text', title: '类型', width: 110 },
                { field: 'per_battle_limit', title: '单局上限', width: 90, align: 'center' },
                {
                    field: 'operate', title: '操作', width: 150, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() { return { keyword: $('#search-keyword').val() || '' }; },
            toolbarBtn: [
                { id: 'btn-add', name: 'add', title: '新增道具', icon: 'bi bi-plus-lg', class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); } },
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
            $('#form-title').text(row ? '编辑道具' : '新增道具');
            $('#form-status').val(1);
            if (row) {
                $('#form-code').val(row.code || '');
                $('#form-name').val(row.name || '');
                $('#form-kind').val(row.kind || 2);
                $('#form-effect').val(JSON.stringify(row.effect || {}, null, 2));
                $('#form-price').val(JSON.stringify(row.price || {}, null, 2));
                $('#form-ad-unit-id').val(row.ad_unit_id || '');
                $('#form-per-battle-limit').val(row.per_battle_limit || 0);
                $('#form-status').val(row.status != null ? row.status : 1);
            }
            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除道具「' + (row.name || '') + '」吗？');
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
