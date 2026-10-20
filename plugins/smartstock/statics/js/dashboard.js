/**
 * dashboard.js — 仪表盘（ESM 控制器，auto-dispatch）
 *
 * 统计卡片（statCards）+ 出入库趋势图 + 仓库库存分布图（ECharts）。
 * HTTP 统一走 wise-util.js 的 http（successCode=0，自动 toast 错误）。
 */

import { $ } from './common/util.js';
import statCards from './common/statCards.js';

let trendChart = null;
let pieChart = null;

function initCharts() {
    if (typeof echarts === 'undefined') return;
    const td = document.getElementById('chart-trend');
    const pd = document.getElementById('chart-warehouse');
    if (td) trendChart = echarts.init(td);
    if (pd) pieChart = echarts.init(pd);
    window.addEventListener('resize', () => {
        if (trendChart) trendChart.resize();
        if (pieChart) pieChart.resize();
    });
}

function renderTrend(trend) {
    if (!trendChart) return;
    const list = trend || [];
    const dates = list.map((d) => d.date);
    const inQty = list.map((d) => Number(d.in_quantity || 0));
    const outQty = list.map((d) => Number(d.out_quantity || 0));
    trendChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['入库', '出库'] },
        grid: { left: 45, right: 20, top: 40, bottom: 30 },
        xAxis: { type: 'category', boundaryGap: false, data: dates },
        yAxis: { type: 'value' },
        series: [
            { name: '入库', type: 'line', smooth: true, areaStyle: {}, data: inQty },
            { name: '出库', type: 'line', smooth: true, areaStyle: {}, data: outQty },
        ],
    });
}

function renderPie(distribution) {
    if (!pieChart) return;
    const data = (distribution || []).map((d) => ({
        name: d.warehouse_name,
        value: Number(d.stock_value || 0),
    }));
    pieChart.setOption({
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        legend: { bottom: 0 },
        series: [{ type: 'pie', radius: ['40%', '70%'], data }],
    });
}

const Controller = {
    index() {
        initCharts();
        statCards.init({
            apiBase: '/plugin/smartstock/dashboard/getStats',
            container: '#statcards',
            cards: [
                { field: 'total_products', label: '商品总数', icon: 'bi-box-seam', color: '#4e73df' },
                { field: 'total_warehouses', label: '仓库总数', icon: 'bi-building', color: '#1cc88a' },
                { field: 'total_stock', label: '库存总量', icon: 'bi-layers', color: '#36b9cc' },
                { field: 'low_stock_count', label: '低库存预警', icon: 'bi-exclamation-triangle', color: '#f6c23e' },
                { field: 'pending_inbound', label: '待入库', icon: 'bi-box-arrow-in-down', color: '#858796' },
                { field: 'pending_outbound', label: '待出库', icon: 'bi-box-arrow-up', color: '#e74a3b' },
            ],
            quickCards: [
                { icon: 'bi-arrow-down-circle', label: '今日入库', href: '/plugin/smartstock/stock_in/index' },
                { icon: 'bi-arrow-up-circle', label: '今日出库', href: '/plugin/smartstock/stock_out/index' },
                { icon: 'bi-calendar-check', label: '本月入库', href: '/plugin/smartstock/report/in_out' },
                { icon: 'bi-calendar-event', label: '本月出库', href: '/plugin/smartstock/report/in_out' },
            ],
            refresh: 30000,
            onLoad(data) {
                renderTrend(data.trend || []);
                renderPie(data.warehouse_distribution || []);
            },
        });
    },
};

export default Controller;
