/**
 * cheat.js — 作弊复核（Phase 5 · 05 §A8.1 第 5 条）
 *
 * 来源 match3_pvp_player.is_cheat：0 正常 / 1 可疑 / 2 确认。
 * 复核：放行（误报） / 确认作弊。
 */
import { http, $, confirm, toast } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list:   '/plugin/match3game/cheat/getList',
    review: '/plugin/match3game/cheat/review',
};

const OPERATE = [
    { action: 'pass',    title: '放行',   icon: 'bi bi-check2-circle', class: 'btn-outline-success' },
    { action: 'confirm', title: '确认作弊', icon: 'bi bi-shield-x',      class: 'btn-outline-danger' },
];

const FLAG_BADGE = {
    0: ['bg-success', '正常'],
    1: ['bg-warning text-dark', '可疑'],
    2: ['bg-danger', '确认作弊'],
};

const REASON_TEXT = {
    board_mismatch:  '棋盘不一致',
    result_mismatch: '结果不一致',
    invalid_op:      '非法操作',
    invalid_checkpoint: 'checkpoint 非法',
    manual_review:   '人工复核',
};

export default {
    index() {
        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'battle_id', title: 'battleId', width: 110 },
                { field: 'user_id', title: '用户', width: 80, align: 'center' },
                { field: 'mode', title: '模式', width: 80, align: 'center' },
                { field: 'stage_code', title: '关卡', width: 110 },
                { field: 'score', title: '本人分', width: 90, align: 'right' },
                { field: 'opponent_score', title: '对手分', width: 90, align: 'right' },
                { field: 'score_gap', title: '分差', width: 90, align: 'right' },
                {
                    field: 'cheat_reason', title: '原因', width: 130,
                    formatter: (v) => (v ? (REASON_TEXT[v] || v) : '-'),
                },
                { field: 'replayed', title: '走重放', width: 100, align: 'center' },
                {
                    field: 'is_cheat', title: '标记', width: 110, align: 'center',
                    formatter(v) {
                        const [cls, text] = FLAG_BADGE[Number(v)] || ['bg-secondary', v];
                        return '<span class="badge ' + cls + '">' + text + '</span>';
                    },
                },
                { field: 'finished_at', title: '完成时间', width: 160 },
                {
                    field: 'operate', title: '复核', width: 170, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return {
                    is_cheat: $('#search-flag').val() || '',
                    reason:   $('#search-reason').val() || '',
                    user_id:  $('#search-user-id').val() || '',
                    battle_id: $('#search-battle-id').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row) {
                if (action === 'pass') return review(row, 0);
                if (action === 'confirm') return review(row, 2);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-flag,#search-reason,#search-user-id,#search-battle-id').val('');
            refresh(table);
        });

        async function review(row, flag) {
            const label = flag === 0 ? '放行（标记为误报）' : '确认为作弊';
            const ok = await confirm('确定对「' + row.battle_id + ' / 用户 ' + row.user_id + '」执行：' + label + '？');
            if (!ok) return;

            try {
                await http.post(URL.review, { ids: [row.id], is_cheat: flag });
                toast('复核完成', 'success');
                refresh(table);
            } catch (e) {
                // http 层已提示
            }
        }
    },
};
