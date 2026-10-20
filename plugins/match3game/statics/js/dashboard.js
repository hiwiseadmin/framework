/**
 * dashboard.js — 运营概览
 */
import { http, $ } from './common/util.js';

function statCard(icon, color, label, value) {
    return (
        '<div class="col-md-3 col-sm-6">' +
        '<div class="card border-0 shadow-sm"><div class="card-body">' +
        '<div class="d-flex align-items-center">' +
        '<div class="flex-shrink-0"><i class="' + icon + ' fs-2 text-' + color + '"></i></div>' +
        '<div class="flex-grow-1 ms-3"><div class="text-muted small">' + label + '</div>' +
        '<div class="fs-4 fw-bold">' + value + '</div></div>' +
        '</div></div></div></div>'
    );
}

export default {
    async index() {
        try {
            const res = await http.get('/plugin/match3game/dashboard/getStats');
            const d = res || {};

            const cards = [
                statCard('bi bi-map', 'primary', '总关卡数', d.total_levels || 0),
                statCard('bi bi-people', 'info', '总玩家数', d.total_players || 0),
                statCard('bi bi-joystick', 'success', '今日对局', d.today_battles || 0),
                statCard('bi bi-trophy', 'warning', '今日通关率', (d.pass_rate || 0) + '%'),
                statCard('bi bi-gem', 'danger', '元素数', d.total_elements || 0),
                statCard('bi bi-magic', 'secondary', '道具数', d.total_props || 0),
            ];
            $('#statcards').html(cards.join(''));

            const trend = d.trend || [];
            const tbody = trend.map((t) => {
                const rate = t.total > 0 ? Math.round(t.passed / t.total * 100) : 0;
                return '<tr><td>' + t.date + '</td><td>' + t.total + '</td><td>' + t.passed + '</td><td>' + rate + '%</td></tr>';
            }).join('');
            $('#trend-table tbody').html(tbody);

            const dist = d.star_dist || {};
            $('#star-dist').html(
                '<div>一星：' + (dist[1] || 0) + '</div>' +
                '<div>二星：' + (dist[2] || 0) + '</div>' +
                '<div>三星：' + (dist[3] || 0) + '</div>'
            );
        } catch (e) {
            /* 错误由全局处理器提示 */
        }
    },
};
