/**
 * group_battle.js — 群擂台管理（Phase 5 · 05 §A7.3）
 *
 * 周期每周一刷新；全群统一 seed；群总分 = 内 Top5 累加；群间排名每日 02:00 重算。
 * 合规：group_id 只存加密标识（openGId），页面不提供填写群名入口。
 */
import { http, $, confirm, toast } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list:    '/plugin/match3game/group_battle/getList',
    members: '/plugin/match3game/group_battle/members',
    recalc:  '/plugin/match3game/group_battle/recalc',
};

const OPERATE = [
    { action: 'members', title: '成员明细', icon: 'bi bi-list-ul', class: 'btn-outline-primary' },
];

export default {
    index() {
        const $modal = $('#members-modal');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'period_id', title: '周期', width: 110 },
                { field: 'group_id', title: '加密群标识', width: 260 },
                { field: 'total_score', title: '群总分（Top5）', width: 130, align: 'right' },
                { field: 'member_cnt', title: '参与人数', width: 90, align: 'center' },
                {
                    field: 'rank', title: '群间排名', width: 100, align: 'center',
                    formatter: (v) => (Number(v) > 0 ? '<span class="badge bg-primary">#' + v + '</span>' : '-'),
                },
                { field: 'start_at', title: '开始', width: 160 },
                { field: 'end_at', title: '结束', width: 160 },
                {
                    field: 'operate', title: '操作', width: 120, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return {
                    period_id: $('#search-period').val() || '',
                    group_id:  $('#search-group').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row) {
                if (action === 'members') openMembers(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-period').val('');
            $('#search-group').val('');
            refresh(table);
        });

        // ---------- 重算排名 ----------
        $('#btn-recalc').on('click', async () => {
            const periodId = $('#search-period').val() || '';
            const ok = await confirm('重算 ' + (periodId || '本期') + ' 的所有群总分与群间排名？');
            if (!ok) return;

            try {
                const d = await http.post(URL.recalc, { period_id: periodId });
                toast('重算完成：' + ((d && d.groupCount) || 0) + ' 个群', 'success');
                refresh(table);
            } catch (e) {
                // http 层已提示
            }
        });

        // ---------- 成员明细 ----------
        async function openMembers(row) {
            $('#members-body').html('<div class="text-muted small">加载中…</div>');
            $modal.modal('show');

            let d = {};
            try {
                d = (await http.get(URL.members, {
                    group_id: row.group_id,
                    period_id: row.period_id,
                })) || {};
            } catch (e) {
                $('#members-body').html('<div class="text-danger small">加载失败</div>');
                return;
            }

            const members = d.members || [];
            if (!members.length) {
                $('#members-body').html('<div class="text-muted small">本期暂无成绩</div>');
                return;
            }

            const topN = Number(d.topN || 5);
            let html = '<div class="small mb-2">群总分（Top' + topN + ' 累加）：<b>' + (d.totalScore || 0)
                + '</b>，共 ' + members.length + ' 人</div>';
            html += '<table class="table table-sm table-hover mb-0"><thead><tr>'
                + '<th>#</th><th>用户</th><th class="text-end">最高分</th><th class="text-center">是否计入</th>'
                + '<th class="text-center">当日次数</th><th>更新时间</th></tr></thead><tbody>';

            members.forEach((m, i) => {
                html += '<tr>'
                    + '<td>' + (i + 1) + '</td>'
                    + '<td>' + m.user_id + '</td>'
                    + '<td class="text-end">' + m.score + '</td>'
                    + '<td class="text-center">' + (i < topN
                        ? '<span class="badge bg-success">计入</span>'
                        : '<span class="badge bg-secondary">不计</span>') + '</td>'
                    + '<td class="text-center">' + (m.play_count || 0) + '</td>'
                    + '<td class="small text-muted">' + (m.update_time || '-') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table>';

            $('#members-body').html(html);
        }
    },
};
