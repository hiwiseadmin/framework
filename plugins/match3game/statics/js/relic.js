/**
 * relic.js — 遗物管理（Phase 3 · 05 §B5）
 *
 * 遗物 = 对 Level Schema 的一次 patch。本页提供 CRUD + patch JSON 编辑 + 「试跑」。
 */
import { http, $, confirm, submit, toast } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list:  '/plugin/match3game/relic/getList',
    save:  '/plugin/match3game/relic/save',
    del:   '/plugin/match3game/relic/delete',
    check: '/plugin/match3game/relic/check',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

/** 稀有度 → 徽章样式 */
const RARITY_BADGE = {
    common: 'bg-secondary',
    rare:   'bg-primary',
    epic:   'bg-warning text-dark',
    cursed: 'bg-danger',
};

export default {
    index() {
        const $modal = $('#relic-modal');
        const $form  = $('#relic-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // patch 是 JSON 文本，用 onCustomRules 注册 validJson —— 组件无内置 json 规则
        const validator = new window.WsFormValidator('relic-form', {
            liveValidation: true,
            onCustomRules: {
                validJson(value) {
                    if (!value) return true;   // 空值交由 required 判定
                    try { JSON.parse(value); return true; } catch (err) { return false; }
                },
            },
        });

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'relic_id', title: 'ID 编码', width: 150 },
                { field: 'name', title: '名称', width: 110 },
                {
                    field: 'rarity', title: '稀有度', width: 90, align: 'center',
                    formatter(v) {
                        const cls = RARITY_BADGE[v] || 'bg-secondary';
                        return '<span class="badge ' + cls + '">' + (v || '-') + '</span>';
                    },
                },
                { field: 'category', title: '分类', width: 90, align: 'center' },
                { field: 'desc', title: '描述' },
                { field: 'patch_count', title: 'patch', width: 70, align: 'center' },
                { field: 'weight', title: '权重', width: 70, align: 'center' },
                { field: 'unlock_cost', title: '解锁', width: 70, align: 'center' },
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
                    rarity: $('#search-rarity').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-add', name: 'add', title: '新增遗物', icon: 'bi bi-plus-lg', class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); } },
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row) {
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => { $('#search-keyword').val(''); $('#search-rarity').val(''); refresh(table); });

        // ---------- 试跑 patch ----------
        $('#btn-relic-check').on('click', async function () {
            let patch;
            try {
                patch = JSON.parse($('#relic-form-patch').val());
            } catch (e) {
                toast('patch JSON 格式非法', 'error');
                return;
            }

            $('#relic-check-result').html('<div class="text-muted small">试跑中…</div>');

            try {
                const d = (await http.post(URL.check, {
                    patch: JSON.stringify(patch),
                    level_code: $('#relic-check-code').val() || '',
                })) || {};

                if (d.valid) {
                    const skipped = (d.skipped || []).length;
                    $('#relic-check-result').html(
                        '<div class="alert alert-success py-2 mb-0">' +
                        '试跑通过：生效 ' + d.appliedCount + ' 条' +
                        (skipped ? '，跳过 ' + skipped + ' 条' : '') +
                        '<br><small>步数 ' + d.moves + '；指纹 <code>' + d.fingerprint + '</code></small>' +
                        (skipped ? '<br><small class="text-warning">跳过项：' +
                            (d.skipped || []).map((s) => (s.reason || '')).join('；') + '</small>' : '') +
                        '</div>'
                    );
                } else {
                    $('#relic-check-result').html(
                        '<div class="alert alert-danger py-2 mb-0"><b>试跑失败：</b><ul class="mb-0">' +
                        (d.errors || []).map((e) => '<li>' + e + '</li>').join('') +
                        '</ul></div>'
                    );
                }
            } catch (e) {
                $('#relic-check-result').empty();
            }
        });

        // ---------- 表单 ----------
        function openModal(row) {
            $form[0].reset();
            $('#relic-check-result').empty();
            $('#relic-form-id').val(row ? row.id : 0);
            $('#relic-form-title').text(row ? '编辑遗物' : '新增遗物');

            if (row) {
                $('#relic-form-relic-id').val(row.relic_id || '');
                $('#relic-form-name').val(row.name || '');
                $('#relic-form-rarity').val(row.rarity || 'common');
                $('#relic-form-category').val(row.category || 'rule');
                $('#relic-form-icon').val(row.icon || '');
                $('#relic-form-desc').val(row.desc || '');
                $('#relic-form-patch').val(JSON.stringify(row.patch || [], null, 2));
                $('#relic-form-weight').val(row.weight != null ? row.weight : 100);
                $('#relic-form-unlock-cost').val(row.unlock_cost != null ? row.unlock_cost : 0);
                $('#relic-form-enabled').val(row.enabled != null ? row.enabled : 1);
            } else {
                $('#relic-form-rarity').val('common');
                $('#relic-form-category').val('rule');
                $('#relic-form-weight').val(100);
                $('#relic-form-unlock-cost').val(0);
                $('#relic-form-enabled').val(1);
                $('#relic-form-patch').val(JSON.stringify([
                    { op: 'add', path: '/rules/moves', value: 5, mode: 'accumulate' },
                ], null, 2));
            }

            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除遗物「' + (row.name || '') + '」吗？');
            if (!ok) return;
            const res = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (res) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();

            // 字段级校验交由 WsFormValidator（错误内联到字段）
            if (validator.validateForm() !== true) return;

            const data = {};
            $form.find('input, select, textarea').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });

            const res = await submit({
                url: URL.save,
                data,
                message: (Number($('#relic-form-id').val()) > 0 ? '修改成功' : '添加成功'),
            });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};
