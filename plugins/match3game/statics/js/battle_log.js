/**
 * battle_log.js — 对局日志查询
 */
import { $ } from './common/util.js';
import { createTable, refresh } from './common/table.js';

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

export default {
    index() {
        const table = createTable({
            url: '/plugin/match3game/battle_log/getList',
            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'user_id', title: '用户ID', width: 90, align: 'center' },
                { field: 'level_code', title: '关卡', width: 100 },
                {
                    field: 'outcome', title: '结果', width: 80, align: 'center',
                    formatter(value, row) {
                        const map = { win: ['胜利', 'success'], lose: ['失败', 'danger'], quit: ['放弃', 'secondary'] };
                        const t = map[value] || ['-', 'secondary'];
                        return badge(t[0], t[1]);
                    },
                },
                { field: 'score', title: '得分', width: 90, align: 'center' },
                { field: 'moves_used', title: '步数', width: 70, align: 'center' },
                { field: 'started_at', title: '开始时间', width: 160 },
                { field: 'finished_at', title: '结束时间', width: 160 },
            ],
            query() {
                return {
                    user_id: $('#search-user-id').val() || 0,
                    level_code: $('#search-level-code').val() || '',
                    outcome: $('#search-outcome').val() || '',
                };
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-user-id').val('');
            $('#search-level-code').val('');
            $('#search-outcome').val('');
            refresh(table);
        });
    },
};
