/**
 * event.js — 事件配置（Phase 4 补齐 · 05 §B4 事件节点）
 *
 * 事件 = 文案 + 2~4 选项，每选项带 effects（效果字典见 service/rogue/RunEffects.php）。
 * 保存前校验结构；「试跑」把全部选项的 effects 真实跑一遍 RunEffects 看产物。
 */
import { http, $, confirm, submit, toast } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list:  '/plugin/match3game/event/getList',
    save:  '/plugin/match3game/event/save',
    del:   '/plugin/match3game/event/delete',
    check: '/plugin/match3game/event/check',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

/** 新事件的 options 模板 */
const DEMO_OPTIONS = [
    {
        id: 'accept',
        label: '接受',
        desc: '获得 60 灵玉，但失去 1 次机会',
        risk: true,
        effects: [{ kind: 'coin', value: 60 }, { kind: 'life', value: -1 }],
    },
    {
        id: 'refuse',
        label: '婉拒',
        desc: '什么也不发生',
        risk: false,
        effects: [{ kind: 'coin', value: 15 }],
    },
];

export default {
    index() {
        const $modal = $('#event-modal');
        const $form  = $('#event-form');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'event_id', title: '事件 ID', width: 150 },
                { field: 'title', title: '标题', width: 140 },
                { field: 'desc', title: '描述' },
                { field: 'opt_count', title: '选项', width: 70, align: 'center' },
                {
                    field: 'risk_count', title: '风险项', width: 80, align: 'center',
                    formatter: (v) => (Number(v) > 0
                        ? '<span class="badge bg-warning text-dark">' + v + '</span>'
                        : '<span class="badge bg-secondary">0</span>'),
                },
                { field: 'weight', title: '权重', width: 70, align: 'center' },
                { field: 'min_layer', title: '最早层', width: 80, align: 'center' },
                {
                    field: 'enabled', title: '状态', width: 70, align: 'center',
                    formatter: (v) => (Number(v) === 1
                        ? '<span class="badge bg-success">启用</span>'
                        : '<span class="badge bg-secondary">停用</span>'),
                },
                {
                    field: 'operate', title: '操作', width: 150, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    enabled: $('#search-enabled').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-add', name: 'add', title: '新增事件', icon: 'bi bi-plus-lg', class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); } },
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row) {
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-enabled').val('');
            refresh(table);
        });

        // ---------- 试跑 effects ----------
        $('#btn-event-check').on('click', async function () {
            let options;
            try {
                options = JSON.parse($('#event-form-options').val());
            } catch (e) {
                toast('options JSON 格式非法', 'error');
                return;
            }
            if (!Array.isArray(options)) {
                toast('options 必须是数组', 'error');
                return;
            }

            // 把所有选项的 effects 汇总起来一次跑完（预览整份配置的实际效果）
            const effects = [];
            options.forEach((o) => {
                (o.effects || []).forEach((e) => effects.push(e));
            });

            $('#event-check-result').html('<div class="text-muted small">试跑中…</div>');

            try {
                const d = (await http.post(URL.check, { effects: JSON.stringify(effects) })) || {};

                if (d.valid) {
                    $('#event-check-result').html(
                        '<div class="alert alert-success py-2 mb-0">'
                        + '全部效果合法；试跑后：灵玉 <b>' + d.after.coins + '</b>／机会 <b>' + d.after.lives + '</b>'
                        + '<br><small>效果文案：' + (d.preview || []).join('；') + '</small>'
                        + '<br><small>实际应用：' + ((d.applied || []).join('；') || '无') + '</small>'
                        + ((d.failed || []).length
                            ? '<br><small class="text-warning">未能生效：' + d.failed.join('；') + '</small>' : '')
                        + '</div>'
                    );
                } else {
                    $('#event-check-result').html(
                        '<div class="alert alert-danger py-2 mb-0"><b>校验失败：</b><ul class="mb-0">'
                        + (d.errors || []).map((e) => '<li>' + e + '</li>').join('')
                        + '</ul></div>'
                    );
                }
            } catch (e) {
                $('#event-check-result').empty();
            }
        });

        // ---------- 表单 ----------
        function openModal(row) {
            $form[0].reset();
            $('#event-check-result').empty();
            $('#event-form-id').val(row ? row.id : 0);
            $('#event-form-title').text(row ? '编辑事件' : '新增事件');

            if (row) {
                $('#event-form-event-id').val(row.event_id || '');
                $('#event-form-title-input').val(row.title || '');
                $('#event-form-desc').val(row.desc || '');
                $('#event-form-weight').val(row.weight != null ? row.weight : 100);
                $('#event-form-min-layer').val(row.min_layer != null ? row.min_layer : 1);
                $('#event-form-enabled').val(row.enabled != null ? row.enabled : 1);
                $('#event-form-options').val(JSON.stringify(row.options || [], null, 2));
            } else {
                $('#event-form-weight').val(100);
                $('#event-form-min-layer').val(1);
                $('#event-form-enabled').val(1);
                $('#event-form-options').val(JSON.stringify(DEMO_OPTIONS, null, 2));
            }

            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除事件「' + (row.title || '') + '」吗？');
            if (!ok) return;
            const res = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (res) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const data = {};
            $form.find('input, select, textarea').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });

            try {
                JSON.parse(data.options || '[]');
            } catch (err) {
                toast('options 不是合法 JSON', 'error');
                return;
            }

            const res = await submit({
                url: URL.save,
                data,
                message: Number($('#event-form-id').val()) > 0 ? '修改成功' : '添加成功',
            });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};
