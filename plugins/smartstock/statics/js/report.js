/**
 * report.js — 数据报表（ESM 控制器，auto-dispatch）
 *
 * inOut()    出入库统计报表（含汇总行）
 * turnover() 库存周转率分析报表
 * HTTP 统一走 wise-util.js 的 http（successCode=0）。
 */

import { http, $ } from './common/util.js';
import { createTable, refresh } from './common/table.js';

const BASE = '/plugin/smartstock/report';
const URL = {
    inOut: BASE + '/getInOutData',
    turnover: BASE + '/getTurnoverData',
};

function defaultRange() {
    const end = new Date();
    const start = new Date();
    start.setDate(1);
    const fmt = (d) => d.toISOString().slice(0, 10);
    return { start: fmt(start), end: fmt(end) };
}

const Controller = {
    /** 出入库统计报表 */
    inOut() {
        const range = defaultRange();
        $('#search-start-date').val(range.start);
        $('#search-end-date').val(range.end);

        const table = createTable({
            url: URL.inOut,
            columns: [
                { field: 'stat_date', title: '日期', width: 130 },
                { field: 'in_count', title: '入库笔数', width: 100, align: 'right' },
                { field: 'in_quantity', title: '入库数量', width: 110, align: 'right' },
                { field: 'in_amount', title: '入库金额', width: 120, align: 'right', formatter(v) { return Number(v || 0).toFixed(2); } },
                { field: 'out_count', title: '出库笔数', width: 100, align: 'right' },
                { field: 'out_quantity', title: '出库数量', width: 110, align: 'right' },
                { field: 'out_amount', title: '出库金额', width: 120, align: 'right', formatter(v) { return Number(v || 0).toFixed(2); } },
            ],
            query() {
                return {
                    date_start: $('#search-start-date').val() || '',
                    date_end: $('#search-end-date').val() || '',
                    warehouse_id: $('#search-warehouse').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onResponse(res) {
                const s = res && res.data && res.data.summary;
                if (s) renderSummary(s);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });

        function renderSummary(s) {
            $('#summary-in-count').text(s.in_count || 0);
            $('#summary-in-qty').text(s.in_quantity || 0);
            $('#summary-in-amt').text(Number(s.in_amount || 0).toFixed(2));
            $('#summary-out-count').text(s.out_count || 0);
            $('#summary-out-qty').text(s.out_quantity || 0);
            $('#summary-out-amt').text(Number(s.out_amount || 0).toFixed(2));
        }
    },

    /** 库存周转率分析报表 */
    turnover() {
        const range = defaultRange();
        $('#search-start-date').val(range.start);
        $('#search-end-date').val(range.end);

        const table = createTable({
            url: URL.turnover,
            columns: [
                { field: 'product_name', title: '商品', width: 180 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                { field: 'current_stock', title: '当前库存', width: 100, align: 'right' },
                { field: 'in_quantity', title: '入库数量', width: 100, align: 'right' },
                { field: 'out_quantity', title: '出库数量', width: 100, align: 'right' },
                { field: 'turnover_text', title: '周转率', width: 140, escape: false },
            ],
            query() {
                return {
                    date_start: $('#search-start-date').val() || '',
                    date_end: $('#search-end-date').val() || '',
                    warehouse_id: $('#search-warehouse').val() || '',
                    keyword: $('#search-keyword').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
    },
};

export default Controller;
