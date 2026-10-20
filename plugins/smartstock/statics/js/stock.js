/**
 * stock.js — 库存查询（ESM 控制器，auto-dispatch）
 * 列表 + 手动调整库存模态框（提交到 stock/adjust）。HTTP 统一走 wise-util.js 的 http。
 */

import { http, $, toast, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/stock/getList',
    adjust: '/plugin/smartstock/stock/adjust',
    products: '/plugin/smartstock/product/getList',
};

const OPERATE = [
    { action: 'adjust', title: '调整库存', icon: 'bi bi-sliders', class: 'btn-outline-warning' },
];

let productsLoaded = false;

const Controller = {
    index() {
        const $modal = $('#adjust-modal');
        const $form  = $('#adjust-form');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'product_sku', title: 'SKU 编码', width: 130 },
                { field: 'product_name', title: '商品名称', width: 180 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                { field: 'quantity', title: '库存数量', width: 90, align: 'right' },
                { field: 'available_quantity', title: '可用数量', width: 110, align: 'right' },
                {
                    field: 'operate', title: '操作', width: 120, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    warehouse_id: $('#search-warehouse').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1', click() { refresh(table); },
                },
            ],
            onClick(action, row) {
                if (action === 'adjust') openModal(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-warehouse').val('');
            refresh(table);
        });

        async function ensureProducts() {
            if (productsLoaded) return;
            try {
                const res = await http.get(URL.products, { pageNumber: 1, pageSize: 999 });
                const rows = res && res.rows ? res.rows : [];
                let html = '';
                rows.forEach((p) => { html += '<option value="' + p.id + '">' + p.name + '</option>'; });
                window.__stockAdjProductOptions = html;
                productsLoaded = true;
            } catch (e) { /* 全局提示 */ }
        }

        async function openModal(row) {
            await ensureProducts();
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text('调整库存');
            if (row) {
                // 预填商品（若存在）
            }
            $modal.modal('show');
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const product_id = $('#form-product').val();
            const warehouse_id = $('#form-warehouse').val();
            const new_quantity = $('#form-quantity').val();
            const reason = $('#form-reason').val() || '';
            if (!product_id) { toast('请选择商品', 'warning'); return; }
            if (!warehouse_id) { toast('请选择仓库', 'warning'); return; }
            if (new_quantity === '' || Number(new_quantity) < 0) { toast('请输入不小于0的库存数量', 'warning'); return; }

            const data = { product_id, warehouse_id, new_quantity: Number(new_quantity), reason };
            const res = await submit({ url: URL.adjust, data, message: '库存调整成功' });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};

export default Controller;
