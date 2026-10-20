/**
 * feedback.js — 反馈管理（ESM 控制器）
 *
 * P0 交互升级：
 *   1. 接通后端早已实现、但长期没有入口的 `feedback/batch`
 *      批量接口（批量已处理 / 批量忽略 / 批量删除）；
 *   2. 「处理状态」并入筛选表单，与类型/日期等条件一起查询（不再单独抽屉 Tab）；
 *   3. 反馈内容列截断显示、鼠标悬浮查看全文（全文仍在处理弹窗中完整展示）；
 *   4. 支持从概览工作台带参进入（?processed=0）。
 */

import { http, $, toast, submit, confirm } from './common/util.js';
import { createTable, operateButtons, refresh, selectedIds, bindListState } from './common/table.js';

const URL = {
    list:    '/plugin/wisedocs/feedback/getList',
    detail:  '/plugin/wisedocs/feedback/detail',
    process: '/plugin/wisedocs/feedback/process',
    del:     '/plugin/wisedocs/feedback/delete',
    batch:   '/plugin/wisedocs/feedback/batch',
};

const OPERATE = [
    { action: 'process', title: '处理', icon: 'bi bi-check2-circle', class: 'btn-outline-primary' },
    { action: 'del',     title: '删除', icon: 'bi bi-trash',        class: 'btn-outline-danger' },
];

/** 批量动作 → 文案（与 Feedback::batch 的 action 取值一致） */
const BATCH_LABEL = {
    process: '批量标记为已处理',
    ignore:  '批量忽略',
    delete:  '批量删除',
};

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

function escapeHtml(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

const Controller = {
    index() {
        const $modal = $('#feedback-process-modal');
        const $form  = $('#feedback-process-form');

        // 从概览等工作台带参进入：?processed=0（待处理）
        const initProcessed = new URLSearchParams(window.location.search).get('processed') || '';

        // 列表筛选状态：恢复上次会话的筛选；URL 参数（如概览 ?processed=0）优先
        bindListState('feedback', $('#search-form'));
        if (initProcessed !== '') {
            $('#search-processed').val(initProcessed);
        }

        const table = createTable({
            url: URL.list,
            // 批量选择：与 Feedback::batch / ::delete 的 ids 入参配套
            checkboxHeader: true,
            columns: [
                { field: 'ck', checkbox: true, align: 'center', valign: 'middle' },
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'document_title', title: '文档' },
                {
                    field: 'type',
                    title: '类型',
                    width: 90,
                    align: 'center',
                    formatter(value, row) {
                        const map = { 1: 'success', 0: 'danger', 2: 'info' };
                        return badge(row.type_text, map[Number(row.type)] || 'secondary');
                    },
                },
                {
                    field: 'comment_short',
                    title: '反馈内容',
                    formatter(value, row) {
                        const full = String(row.comment || value || '');
                        if (full === '') {
                            return '<span class="text-muted">—</span>';
                        }

                        const short = full.length > 60 ? full.slice(0, 60) + '…' : full;

                        return '<span title="' + escapeHtml(full) + '">' + escapeHtml(short) + '</span>';
                    },
                },
                {
                    field: 'processed',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter(value, row) {
                        const map = { 1: 'success', 2: 'secondary', 0: 'warning' };
                        return badge(row.processed_text, map[Number(row.processed)] || 'warning');
                    },
                },
                { field: 'create_time', title: '提交时间', width: 160 },
                {
                    field: 'operate',
                    title: '操作',
                    width: 150,
                    align: 'center',
                    formatter(value, row) {
                        return operateButtons(OPERATE, row);
                    },
                },
            ],
            query() {
                return {
                    document_id: $('#search-document').val() || '',
                    type: $('#search-type').val() || '',
                    processed: $('#search-processed').val() || '',
                    start_date: $('#search-start').val() || '',
                    end_date: $('#search-end').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-batch-process',
                    name: 'batchProcess',
                    title: '批量已处理',
                    icon: 'bi bi-check2-all',
                    class: 'btn btn-sm btn-primary mx-1',
                    click() { batch('process'); },
                },
                {
                    id: 'btn-batch-ignore',
                    name: 'batchIgnore',
                    title: '批量忽略',
                    icon: 'bi bi-slash-circle',
                    class: 'btn btn-sm btn-outline-secondary mx-1',
                    click() { batch('ignore'); },
                },
                {
                    id: 'btn-batch-del',
                    name: 'batchDel',
                    title: '批量删除',
                    icon: 'bi bi-trash3',
                    class: 'btn btn-sm btn-danger mx-1',
                    click() { batch('delete'); },
                },
                {
                    id: 'btn-refresh',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1',
                    click() { refresh(table); },
                },
            ],
            onClick(action, row) {
                if (action === 'process') return openProcess(row);
                if (action === 'del') return remove(row);
            },
        });

        // ---- 筛选 ----
        $('#search-form').on('submit', function (e) {
            e.preventDefault();
            refresh(table);
        });

        $('#btn-reset').on('click', function () {
            $('#search-document').val('');
            $('#search-type').val('');
            $('#search-processed').val('');
            $('#search-start').val('');
            $('#search-end').val('');
            refresh(table);
        });

        // 处理状态改动后立即查询，保持与原先快捷筛选一致的即时反馈
        $('#search-processed').on('change', function () {
            refresh(table);
        });

        // ---- 单条处理（弹窗内展示全文 + 备注）----
        async function openProcess(row) {
            try {
                const detail = await http.get(URL.detail, { id: row.id });
                $('#process-id').val(detail.id);
                $('#process-comment').text(detail.comment || '（无内容）');
                $('#process-status').val(Number(detail.processed) === 2 ? 2 : 1);
                $('#process-remark').val(detail.remark || '');
            } catch (e) {
                return;
            }
            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除这条反馈吗？删除后不可恢复。');
            if (!ok) return;
            const done = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (done) refresh(table);
        }

        /**
         * 批量操作（接通 Feedback::batch）
         * @param {'process'|'ignore'|'delete'} action
         */
        async function batch(action) {
            const ids = selectedIds('#table');
            if (!ids.length) {
                toast('请先勾选要操作的反馈', 'warning');
                return;
            }

            const ok = await confirm(
                '确定要' + (BATCH_LABEL[action] || '操作') + '选中的 ' + ids.length + ' 条反馈吗？'
            );
            if (!ok) return;

            const done = await submit({ url: URL.batch, data: { ids, action }, message: '操作成功' });
            if (done) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();

            const done = await submit({
                url: URL.process,
                data: {
                    id: Number($('#process-id').val()) || 0,
                    status: Number($('#process-status').val()) || 1,
                    remark: $('#process-remark').val() || '',
                },
                message: '处理成功',
            });

            if (done) {
                $modal.modal('hide');
                refresh(table);
            }
        });
    },
};

export default Controller;
