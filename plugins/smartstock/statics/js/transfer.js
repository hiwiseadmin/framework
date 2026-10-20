/**
 * transfer.js — 调拨单管理（ESM 控制器，auto-dispatch）
 * 列表 + 新增/编辑模态框（含明细行）+ 工作流操作。详情走独立页面 /transfer/detail。
 * HTTP 统一走 wise-util.js 的 http。
 */

import { http, $, toast, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/transfer/getList',
    save: '/plugin/smartstock/transfer/save',
    detail: '/plugin/smartstock/transfer/detail',
    transitions: '/plugin/smartstock/transfer/getTransitions',
    apply: '/plugin/smartstock/transfer/applyTransition',
    products: '/plugin/smartstock/product/getList',
};

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}
function statusType(s) {
    return { 0: 'secondary', 1: 'info', 2: 'warning', 3: 'success', 4: 'danger' }[s] || 'secondary';
}
let productsLoaded = false;
let wfLayerIndex = null;

const Controller = {
    index() {
        const $modal = $('#transfer-modal');
        const $form  = $('#transfer-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // 字段无 name（值由 JS 组装），规则必须用 setRules 动态注册。
        // 「调出/调入不能相同」是跨字段规则，用 onCustomRules 表达。
        const validator = new window.WsFormValidator('transfer-form', {
            liveValidation: true,
            onCustomRules: {
                differentWarehouse(value) {
                    return value !== $('#form-from-warehouse').val();
                },
            },
        });
        validator.setRules('#form-from', 'required', { messages: { required: '请选择调出仓库' } });
        validator.setRules('#form-to', 'required|differentWarehouse', {
            messages: {
                required: '请选择调入仓库',
                differentWarehouse: '调出与调入仓库不能相同',
            },
        });

        // 联动：调出仓库变化时重校验调入（它可能刚从"与调出相同"变为合法）
        $('#form-from-warehouse').on('change', () => validator.validateField($('#form-to-warehouse')[0]));
        const $tbody = $('#items-tbody');
        let itemSeq = 0;

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'order_no', title: '调拨单号', width: 160 },
                { field: 'from_warehouse_name', title: '调出仓库', width: 140 },
                { field: 'to_warehouse_name', title: '调入仓库', width: 140 },
                { field: 'total_quantity', title: '总数量', width: 90, align: 'right' },
                {
                    field: 'status_text', title: '状态', width: 80, align: 'center',
                    formatter(value, row) { return badge(row.status_text || '-', statusType(row.status)); },
                },
                { field: 'create_time', title: '创建时间', width: 150 },
                {
                    field: 'operate', title: '操作', width: 170, align: 'center',
                    formatter(value, row) {
                        const btns = [{ action: 'detail', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-info' }];
                        if (Number(row.status) === 0) {
                            btns.push({ action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' });
                            btns.push({ action: 'flow', title: '流程', icon: 'bi bi-arrow-right-circle', class: 'btn-outline-success' });
                        }
                        return operateButtons(btns, row);
                    },
                },
            ],
            query() {
                return {
                    order_no: $('#search-order-no').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add', name: 'add', title: '新增调拨单', icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary mx-1', click() { openModal(0); },
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
                else if (action === 'edit') openModal(row.id, row);
                else if (action === 'flow') runWorkflow(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-order-no').val('');
            $('#search-status').val('');
            refresh(table);
        });

        async function ensureProducts() {
            if (productsLoaded) return;
            try {
                const res = await http.get(URL.products, { pageNumber: 1, pageSize: 999 });
                const rows = res && res.rows ? res.rows : [];
                let html = '';
                rows.forEach((p) => { html += '<option value="' + p.id + '">' + p.name + '</option>'; });
                window.__transferProductOptions = html;
                productsLoaded = true;
            } catch (e) { /* 全局提示 */ }
        }

        function addItemRow(productId) {
            itemSeq++;
            const $row = $('<tr class="item-row">');
            $row.append('<td class="item-seq">' + itemSeq + '</td>');
            $row.append('<td><select class="form-select form-select-sm item-product">' + (window.__transferProductOptions || '') + '</select></td>');
            $row.append('<td><input type="number" class="form-control form-control-sm item-quantity" value="1" min="1"></td>');
            $row.append('<td><input type="text" class="form-control form-control-sm item-remark" placeholder="备注"></td>');
            $row.append('<td><button type="button" class="btn btn-xs btn-outline-danger btn-remove-item"><i class="bi bi-trash"></i></button></td>');
            $tbody.append($row);
            if (productId) $row.find('.item-product').val(productId);
        }

        $('#btn-add-item').on('click', () => addItemRow());
        $tbody.on('click', '.btn-remove-item', function () {
            $(this).closest('tr').remove();
            renumber();
        });
        function renumber() {
            $tbody.find('tr.item-row').each(function (i) { $(this).find('.item-seq').text(i + 1); });
            itemSeq = $tbody.find('tr.item-row').length;
        }

        async function openModal(id, row) {
            await ensureProducts();
            $form[0].reset();
            $tbody.empty();
            itemSeq = 0;
            $('#form-id').val(id || 0);
            $('#form-title').text(id ? '编辑调拨单' : '新增调拨单');
            if (row) {
                $('#form-from-warehouse').val(row.from_warehouse_id || '');
                $('#form-to-warehouse').val(row.to_warehouse_id || '');
                $('#form-date').val(row.order_date || '');
                $('#form-remark').val(row.remark || '');
            } else {
                $('#form-date').val('');
                $('#form-remark').val('');
            }
            addItemRow();
            $modal.modal('show');
        }

        $form.on('submit', async function (e) {
            e.preventDefault();

            // 字段级校验交由 WsFormValidator（错误内联到字段，不再弹 toast）
            if (validator.validateForm() !== true) return;

            const from_warehouse_id = $('#form-from-warehouse').val();
            const to_warehouse_id = $('#form-to-warehouse').val();

            const items = [];
            $tbody.find('tr.item-row').each(function () {
                const $r = $(this);
                const product_id = $r.find('.item-product').val();
                const quantity = parseInt($r.find('.item-quantity').val(), 10) || 0;
                if (product_id && quantity > 0) {
                    items.push({ product_id, quantity, remark: $r.find('.item-remark').val() || '' });
                }
            });
            if (!items.length) { toast('请至少添加一条明细', 'warning'); return; }

            const data = {
                id: $('#form-id').val(),
                from_warehouse_id,
                to_warehouse_id,
                order_date: $('#form-date').val() || '',
                remark: $('#form-remark').val() || '',
                items,
            };
            const res = await submit({ url: URL.save, data, message: '保存成功' });
            if (res) { $modal.modal('hide'); refresh(table); }
        });

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
            const sel = tbl && tbl.bootstrapTable ? tbl.bootstrapTable('getSelections') : $('#table').bootstrapTable('getSelections');
            if (!sel || !sel.length) { toast('请先在列表勾选一条单据', 'warning'); return; }
            runWorkflow(sel[0]);
        }
    },

    /** 详情页（服务端渲染，无需额外逻辑） */
    detail() {},
};

export default Controller;
