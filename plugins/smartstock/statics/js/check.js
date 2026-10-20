/**
 * check.js — 盘点管理（ESM 控制器，auto-dispatch）
 *
 * 列表 + 新建盘点计划模态框（提交 check/save）+ 执行盘点（独立页 check/execute）+ 工作流操作。
 * 详情走独立页面 check/detail（已存在 action）。
 * HTTP 统一走 wise-util.js 的 http（successCode=0，自动 toast 错误）。
 */

import { http, $, toast, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/check/getList',
    save: '/plugin/smartstock/check/save',
    detail: '/plugin/smartstock/check/detail',
    submitResult: '/plugin/smartstock/check/submitResult',
    transitions: '/plugin/smartstock/check/getTransitions',
    apply: '/plugin/smartstock/check/applyTransition',
};

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

function statusType(s) {
    return { 0: 'secondary', 1: 'info', 2: 'success', 3: 'danger' }[s] || 'secondary';
}

let wfLayerIndex = null;

const Controller = {
    /** 盘点单列表 + 新建盘点计划模态框 */
    index() {
        const $modal = $('#check-modal');
        const $form  = $('#check-form');

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'order_no', title: '盘点单号', width: 160 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                {
                    field: 'status_text', title: '状态', width: 90, align: 'center',
                    formatter(value, row) { return badge(row.status_text || '-', statusType(row.status)); },
                },
                { field: 'remark', title: '备注', width: 180 },
                { field: 'create_time', title: '创建时间', width: 150 },
                {
                    field: 'operate', title: '操作', width: 180, align: 'center',
                    formatter(value, row) {
                        const btns = [{ action: 'detail', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-info' }];
                        if (Number(row.status) === 0 || Number(row.status) === 1) {
                            btns.push({ action: 'execute', title: '执行盘点', icon: 'bi bi-pencil-square', class: 'btn-outline-success' });
                        }
                        btns.push({ action: 'flow', title: '流程', icon: 'bi bi-arrow-right-circle', class: 'btn-outline-warning' });
                        return operateButtons(btns, row);
                    },
                },
            ],
            query() {
                return {
                    order_no: $('#search-order-no').val() || '',
                    warehouse_id: $('#search-warehouse').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add', name: 'add', title: '新建盘点计划', icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary mx-1', click() { openModal(); },
                },
                {
                    id: 'btn-flow', name: 'flow', title: '流程操作', icon: 'bi bi-arrow-right-circle',
                    class: 'btn btn-sm btn-success mx-1', click() { flowFromSelection(table); },
                },
                {
                    id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1', click() { refresh(table); },
                },
            ],
            onClick(action, row) {
                if (action === 'detail') window.location.href = URL.detail + '?id=' + row.id;
                else if (action === 'execute') window.location.href = URL.detail.replace('/check/detail', '/check/execute') + '?id=' + row.id;
                else if (action === 'flow') runWorkflow(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-order-no').val('');
            $('#search-warehouse').val('');
            $('#search-status').val('');
            refresh(table);
        });

        function openModal() {
            $form[0].reset();
            $('#form-id').val(0);
            $modal.modal('show');
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const warehouse_id = $('#form-warehouse').val();
            if (!warehouse_id) { toast('请选择盘点仓库', 'warning'); return; }
            const data = {
                warehouse_id,
                order_date: $('#form-date').val() || '',
                remark: $('#form-remark').val() || '',
            };
            const res = await submit({ url: URL.save, data, message: '盘点计划创建成功' });
            if (res) { $modal.modal('hide'); refresh(table); }
        });

        // 工作流：按钮事件统一委托（Layer 内动态生成）
        $(document).off('click.wf').on('click.wf', '.wf-btn', async function () {
            const name = $(this).data('name');
            const id = $(this).data('id');
            const label = $(this).text();
            if (wfLayerIndex !== null) { window.Layer.close(wfLayerIndex); wfLayerIndex = null; }
            const ok = await confirm('确认执行「' + label + '」？');
            if (!ok) return;
            const r = await submit({ url: URL.apply, data: { id, transition: name }, message: '操作成功' });
            if (r) refresh(table);
        });

        async function runWorkflow(row) {
            let res;
            try {
                res = await http.get(URL.transitions, { id: row.id });
            } catch (e) { return; }
            const transitions = Array.isArray(res) ? res : [];
            if (!transitions.length) { toast('当前单据无可用流程操作', 'info'); return; }
            let html = '<div class="list-group">';
            transitions.forEach((t) => {
                html += '<button class="list-group-item list-group-item-action wf-btn" data-name="' + t.name + '" data-id="' + row.id + '">' + (t.label || t.name) + '</button>';
            });
            html += '</div>';
            wfLayerIndex = window.Layer.open({ title: '流程操作 · ' + row.order_no, content: html, area: ['300px', 'auto'] });
        }

        function flowFromSelection(tbl) {
            const sel = tbl.$el ? tbl.$el.bootstrapTable('getSelections') : $('#table').bootstrapTable('getSelections');
            if (!sel || !sel.length) { toast('请先在列表勾选一条单据', 'warning'); return; }
            runWorkflow(sel[0]);
        }
    },

    /** 执行盘点（独立页） */
    execute() {
        const id = parseInt(new URLSearchParams(location.search).get('id'), 10) || 0;
        if (!id) { toast('参数错误', 'error'); return; }

        function calcDiff($row) {
            const sys = parseFloat($row.find('.system-qty').text()) || 0;
            const act = parseFloat($row.find('.input-actual').val()) || 0;
            $row.find('.diff-qty').text(act - sys);
        }

        $('#check-items-tbody').on('input', '.input-actual', function () {
            calcDiff($(this).closest('tr.item-row'));
        });

        $('#btn-submit-result').on('click', async function () {
            const items = [];
            $('#check-items-tbody tr.item-row').each(function () {
                const $r = $(this);
                const product_id = parseInt($r.data('product-id'), 10) || 0;
                const actual = parseInt($r.find('.input-actual').val(), 10) || 0;
                if (product_id) items.push({ product_id, actual_quantity: actual });
            });
            if (!items.length) { toast('无盘点数据', 'warning'); return; }
            const res = await submit({
                url: URL.submitResult,
                data: { id, items },
                message: '实盘数据提交成功',
            });
            if (res) window.location.href = URL.detail + '?id=' + id;
        });
    },

    /** 详情页（服务端渲染，无需额外逻辑） */
    detail() {},
};

export default Controller;
