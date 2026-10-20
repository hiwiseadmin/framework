/**
 * player.js — 玩家查询
 */
import { http, $ } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const OPERATE = [
    { action: 'detail', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-primary' },
];

export default {
    index() {
        const table = createTable({
            url: '/plugin/match3game/player/getList',
            columns: [
                { field: 'user_id', title: '用户ID', width: 100, align: 'center' },
                { field: 'level_count', title: '已玩关卡', width: 100, align: 'center' },
                { field: 'passed_count', title: '通关数', width: 100, align: 'center' },
                { field: 'star_total', title: '总星数', width: 100, align: 'center' },
                { field: 'last_play_at', title: '最近游玩' },
                {
                    field: 'operate', title: '操作', width: 100, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() { return { user_id: $('#search-user-id').val() || 0 }; },
            onClick(action, row) {
                if (action === 'detail') return detail(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => { $('#search-user-id').val(''); refresh(table); });

        async function detail(row) {
            try {
                const res = await http.get('/plugin/match3game/player/detail', { user_id: row.user_id });
                const d = res || {};
                const inv = (d.inventory || []).map((i) => i.item_id + '×' + i.count).join('，');
                const prog = (d.progress || []).map((p) => p.level_code + '(' + p.best_stars + '星)').join('，');
                const battles = (d.battles || []).map((b) => b.level_code + ':' + (b.outcome || '-')).join('，');
                const content = '<b>背包：</b>' + (inv || '无') + '<br><b>关卡进度：</b>' + (prog || '无') +
                    '<br><b>最近对局：</b>' + (battles || '无');
                if (window.Layer && window.Layer.open) {
                    window.Layer.open({ type: 1, content: '<div style="padding:20px;max-width:600px">' + content + '</div>', title: '玩家 ' + row.user_id + ' 详情' });
                } else {
                    alert(content);
                }
            } catch (e) {
                /* 错误由全局处理器提示 */
            }
        }
    },
};
