/**
 * season.js — 赛季管理（Phase 5 · 05 §A7.1）
 *
 * 赛季周期 2 周；段位 6 档；负分不降段。支持新建/编辑 + 一键结算切换。
 */
import { http, $, confirm, submit, toast } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list:   '/plugin/match3game/season/getList',
    save:   '/plugin/match3game/season/save',
    detail: '/plugin/match3game/season/detail',
    switch: '/plugin/match3game/season/switchSeason',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
];

const STATUS_BADGE = {
    0: 'bg-secondary',
    1: 'bg-success',
    2: 'bg-dark',
};

export default {
    index() {
        const $modal = $('#season-modal');
        const $form  = $('#season-form');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'season_id', title: '赛季 ID', width: 110 },
                { field: 'name', title: '名称' },
                { field: 'start_at', title: '开始', width: 160 },
                { field: 'end_at', title: '结束', width: 160 },
                {
                    field: 'status_text', title: '状态', width: 90, align: 'center',
                    formatter(v, row) {
                        const cls = STATUS_BADGE[Number(row.status)] || 'bg-secondary';
                        return '<span class="badge ' + cls + '">' + (v || '-') + '</span>';
                    },
                },
                { field: 'player_count', title: '参与人数', width: 90, align: 'center' },
                { field: 'reward_tiers', title: '奖励档位', width: 90, align: 'center' },
                {
                    field: 'operate', title: '操作', width: 100, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return { status: $('#search-status').val() || '' };
            },
            toolbarBtn: [
                { id: 'btn-add', name: 'add', title: '新建赛季', icon: 'bi bi-plus-lg', class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); } },
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row) {
                if (action === 'edit') openModal(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => { $('#search-status').val(''); refresh(table); });

        // ---------- 结算并切换赛季 ----------
        $('#btn-switch').on('click', async () => {
            const ok = await confirm('将结算所有已到期赛季，并开启当前周期赛季。确定继续？');
            if (!ok) return;

            try {
                const d = await http.post(URL.switch, {});
                toast('已结算 ' + ((d && d.settled) || '无') + '，当前 ' + ((d && d.opened) || ''), 'success');
                refresh(table);
            } catch (e) {
                // http 层已提示
            }
        });

        // ---------- 表单 ----------
        async function openModal(row) {
            $form[0].reset();
            $('#season-form-id').val(row ? row.id : 0);
            $('#season-form-title').text(row ? '编辑赛季' : '新建赛季');

            if (row) {
                let d = row;
                try {
                    d = (await http.get(URL.detail, { season_id: row.season_id })) || row;
                } catch (e) { /* 退回列表行 */ }

                $('#season-form-season-id').val(d.season_id || '');
                $('#season-form-name').val(d.name || '');
                $('#season-form-start').val((d.start_at || '').replace(' ', 'T').slice(0, 16));
                $('#season-form-end').val((d.end_at || '').replace(' ', 'T').slice(0, 16));
                $('#season-form-status').val(d.status != null ? d.status : 1);
                $('#season-form-reward').val(JSON.stringify(d.reward_conf || {}, null, 2));
            } else {
                $('#season-form-status').val(1);
                $('#season-form-reward').val(JSON.stringify({
                    bronze:   { coin: 500,   props: {},                       cosmetics: [] },
                    silver:   { coin: 1000,  props: { 'prop.hammer': 2 },      cosmetics: [] },
                    gold:     { coin: 2000,  props: { 'prop.bomb': 2 },        cosmetics: ['avatar_frame_gold'] },
                    platinum: { coin: 3500,  props: { 'prop.add_moves': 1 },   cosmetics: ['title_platinum'] },
                    diamond:  { coin: 6000,  props: {},                       cosmetics: ['skin_diamond'] },
                    king:     { coin: 10000, props: {},                       cosmetics: ['avatar_frame_king', 'broadcast'] },
                }, null, 2));
            }

            $modal.modal('show');
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const data = {};
            $form.find('input, select, textarea').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });

            if (!data.season_id) { toast('赛季 ID 不能为空', 'error'); return; }
            try {
                JSON.parse(data.reward_conf || '{}');
            } catch (err) {
                toast('reward_conf 不是合法 JSON', 'error');
                return;
            }
            // datetime-local → 数据库格式
            if (data.start_at) data.start_at = data.start_at.replace('T', ' ') + ':00';
            if (data.end_at) data.end_at = data.end_at.replace('T', ' ') + ':00';

            const res = await submit({
                url: URL.save,
                data,
                message: Number($('#season-form-id').val()) > 0 ? '修改成功' : '添加成功',
            });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};
