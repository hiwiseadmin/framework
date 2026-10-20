/**
 * stats.js — 数据统计页（ESM 控制器）
 *
 * 批次 4：
 *   - 接通闲置的 `stats/trend` 接口：浏览趋势由前端 AJAX 拉取（不再服务端直出）；
 *   - 趋势柱状图（chartjs，仓库已有，零新依赖）；
 *   - 日期区间快捷选择（今天 / 近 7 天 / 近 30 天 / 自定义）。
 *
 * 其余区块（总览 / 热门文档 / 分类统计）仍由服务端直出，日期切换走表单 GET 全量刷新，
 * 保证四个区块的统计区间始终一致。
 */

import { http } from './common/util.js';

const URL = {
    trend: '/plugin/wisedocs/stats/trend',
};

let trendChart = null;

const Controller = {
    index() {
        bindRangeChips();
        loadTrend();
    },

    /** 单文档统计页无需前端初始化 */
    document() {},
};

/** 本地时区安全的 YYYY-MM-DD 格式化（避免 toISOString 的 UTC 偏移跨天） */
function fmtDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
}

/** 快捷日期区间：设值后提交表单（GET 全量刷新，保持四个区块区间一致） */
function bindRangeChips() {
    document.querySelectorAll('[data-range]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const range = btn.getAttribute('data-range');
            const startEl = document.getElementById('stats-start');
            const endEl = document.getElementById('stats-end');
            const form = document.getElementById('stats-form');
            if (!startEl || !endEl || !form) return;

            const today = new Date();

            if (range === 'today') {
                startEl.value = fmtDate(today);
                endEl.value = fmtDate(today);
            } else if (range === '7d') {
                const s = new Date(today);
                s.setDate(s.getDate() - 6);
                startEl.value = fmtDate(s);
                endEl.value = fmtDate(today);
            } else if (range === '30d') {
                const s = new Date(today);
                s.setDate(s.getDate() - 29);
                startEl.value = fmtDate(s);
                endEl.value = fmtDate(today);
            } else if (range === 'custom') {
                startEl.focus();
                return;
            }

            form.submit();
        });
    });
}

/** 拉取趋势数据并渲染图表 + 明细表 */
async function loadTrend() {
    const card = document.getElementById('trend-card');
    if (!card) return;

    const start = card.getAttribute('data-start-date');
    const end = card.getAttribute('data-end-date');

    let result;
    try {
        result = await http.get(URL.trend, { start_date: start, end_date: end });
    } catch (e) {
        renderTrendTable([]);
        return;
    }

    const rows = (result && result.data) || [];
    renderTrendChart(rows, start, end);
    renderTrendTable(rows);
}

/** 柱状图：浏览 / 有帮助 / 无帮助 按天 */
function renderTrendChart(rows, start, end) {
    const range = document.getElementById('trend-range');
    if (range) {
        range.textContent = start && end ? (start + ' ~ ' + end) : '';
    }

    const canvas = document.getElementById('trend-chart');
    if (!canvas) return;

    if (typeof window.Chart === 'undefined') {
        canvas.parentElement.innerHTML =
            '<div class="text-center text-muted py-5">图表组件未加载，请查看下方明细表</div>';
        return;
    }

    const labels = rows.map((r) => r.date);
    const views = rows.map((r) => Number(r.views) || 0);
    const helpful = rows.map((r) => Number(r.helpful) || 0);
    const unhelpful = rows.map((r) => Number(r.unhelpful) || 0);

    if (trendChart) {
        trendChart.destroy();
        trendChart = null;
    }

    trendChart = new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: '浏览', data: views, backgroundColor: 'rgba(13,110,253,.65)', borderColor: 'rgba(13,110,253,1)', borderWidth: 1 },
                { label: '有帮助', data: helpful, backgroundColor: 'rgba(25,135,84,.6)', borderColor: 'rgba(25,135,84,1)', borderWidth: 1 },
                { label: '无帮助', data: unhelpful, backgroundColor: 'rgba(220,53,69,.6)', borderColor: 'rgba(220,53,69,1)', borderWidth: 1 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });
}

/** 趋势明细表 */
function renderTrendTable(rows) {
    const tbody = document.getElementById('trend-tbody');
    if (!tbody) return;

    if (!rows || !rows.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">暂无数据</td></tr>';
        return;
    }

    tbody.innerHTML = rows
        .map((r) =>
            '<tr>' +
            '<td>' + (r.date || '') + '</td>' +
            '<td>' + (Number(r.views) || 0) + '</td>' +
            '<td class="text-success">' + (Number(r.helpful) || 0) + '</td>' +
            '<td class="text-danger">' + (Number(r.unhelpful) || 0) + '</td>' +
            '</tr>'
        )
        .join('');
}

export default Controller;
