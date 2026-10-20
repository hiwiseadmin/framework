/**
 * product.js — 商品管理（ESM 控制器，auto-dispatch）
 *
 * 列表与新增/编辑共用本页；分类/仓库下拉由服务端渲染，单位下拉惰性加载。
 * 编辑以操作列整行 row 预填。HTTP 统一走 wise-util.js 的 http。
 */

import { http, $, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/product/getList',
    save: '/plugin/smartstock/product/save',
    del:  '/plugin/smartstock/product/delete',
    units: '/plugin/smartstock/unit/getList',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

let unitsLoaded = false;

const Controller = {
    index() {
        const $modal = $('#product-modal');
        const $form  = $('#product-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // 规则写在模板的 data-validation 上；window.WsFormValidator 是组件官方挂载的全局
        const validator = new window.WsFormValidator('product-form', {
            liveValidation: true,
            showIcons: true,
        });

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'name', title: '商品名称' },
                { field: 'sku_code', title: 'SKU 编码', width: 140 },
                {
                    field: 'category_name', title: '分类', width: 120,
                    formatter(value, row) { return row.category_name || ('#' + row.category_id); },
                },
                {
                    field: 'unit_name', title: '单位', width: 90,
                    formatter(value, row) { return row.unit_name || ('#' + row.unit_id); },
                },
                { field: 'selling_price', title: '售价', width: 110 },
                {
                    field: 'status', title: '状态', width: 90, align: 'center',
                    formatter(value, row) {
                        return badge(row.status_text || '-', Number(row.status) === 1 ? 'success' : 'secondary');
                    },
                },
                { field: 'create_time', title: '创建时间', width: 150 },
                {
                    field: 'operate', title: '操作', width: 150, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    category_id: $('#search-category-id').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add', name: 'add', title: '新增商品', icon: 'bi bi-plus-lg',
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
            $('#search-keyword').val('');
            $('#search-category-id').val('');
            $('#search-status').val('');
            refresh(table);
        });

        async function ensureUnits() {
            if (unitsLoaded) return;
            try {
                const res = await http.get(URL.units, { pageNumber: 1, pageSize: 999 });
                const rows = res && res.rows ? res.rows : [];
                rows.forEach((u) => $('#form-unit-id').append('<option value="' + u.id + '">' + u.name + '</option>'));
                unitsLoaded = true;
            } catch (e) { /* 错误由全局处理器提示 */ }
        }

        async function openModal(row) {
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑商品' : '新增商品');
            await ensureUnits();
            if (row) {
                $('#form-name').val(row.name || '');
                $('#form-sku-code').val(row.sku_code || '');
                $('#form-category-id').val(row.category_id || 0);
                $('#form-unit-id').val(row.unit_id || 0);
                $('#form-warehouse-id').val(row.warehouse_id || 0);
                $('#form-barcode').val(row.barcode || '');
                $('#form-spec-summary').val(row.spec_summary || '');
                $('#form-cost-price').val(row.cost_price || '');
                $('#form-selling-price').val(row.selling_price || '');
                $('#form-expiry-date').val(row.expiry_date || '');
                $('#form-image').val(row.image || '');
                $('#form-status').val(row.status != null ? row.status : 1);
                $('#form-description').val(row.description || '');
            } else {
                $('#form-status').val(1);
            }
            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除商品「' + (row.name || '') + '」吗？');
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
