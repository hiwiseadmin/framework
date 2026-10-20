/**
 * inventory_log.js — 库存流水查询（ESM 控制器，auto-dispatch，只读列表）
 * HTTP 统一走 wise-util.js 的 http。
 */

import { http, $ } from './common/util.js';
import { createTable, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/inventory_log/getList',
};

const Controller = {
    index() {
        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'product_sku', title: 'SKU 编码', width: 130 },
                { field: 'product_name', title: '商品名称', width: 180 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                { field: 'change_type_text', title: '变动类型', width: 100 },
                { field: 'quantity_change', title: '变动数量', width: 90, align: 'right' },
                { field: 'stock_after', title: '变动后库存', width: 100, align: 'right' },
                { field: 'create_time', title: '变动时间', width: 150 },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    warehouse_id: $('#search-warehouse').val() || '',
                    change_type: $('#search-change-type').val() || '',
                    start_date: $('#search-start-date').val() || '',
                    end_date: $('#search-end-date').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1', click() { refresh(table); },
                },
            ],
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-warehouse').val('');
            $('#search-change-type').val('');
            $('#search-start-date').val('');
            $('#search-end-date').val('');
            refresh(table);
        });
    },
};

export default Controller;
