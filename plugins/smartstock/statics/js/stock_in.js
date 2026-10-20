/**
 * stock_in.js — 入库单管理（ESM 控制器，auto-dispatch）
 *
 * 列表 + 新增/编辑模态框（含动态明细行）+ 工作流操作（getTransitions/applyTransition）。
 * 详情仍走独立页面 /stock_in/detail（已存在 action）。
 * HTTP 统一走 wise-util.js 的 http（successCode=0，自动 toast 错误）。
 */

import { http, $, toast, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/stock_in/getList',
    save: '/plugin/smartstock/stock_in/save',
    detail: '/plugin/smartstock/stock_in/detail',
    transitions: '/plugin/smartstock/stock_in/getTransitions',
    apply: '/plugin/smartstock/stock_in/applyTransition',
    products: '/plugin/smartstock/product/getList',
};

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

function statusType(s) {
    return { 0: 'secondary', 1: 'info', 2: 'success', 3: 'danger' }[s] || 'secondary';
}

let productsLoaded = false;
let wfLayerIndex = null;

const Controller = {
    index() {
        const $modal = $('#stockin-modal');
        const $form  = $('#stockin-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // 本表单的字段只有 id、没有 name（值由 JS 组装），规则必须用 setRules 动态注册 ——
        // 模板上的 data-validation 依赖 name 定位，这里不会被识别。
        const validator = new window.WsFormValidator('stockin-form', { liveValidation: true });
        validator.setRules('#form-warehouse', 'required', { messages: { required: '请选择仓库' } });
        validator.setRules('#form-type', 'required', { messages: { required: '请选择类型' } });
        const $tbody = $('#items-tbody');
        let itemSeq = 0;

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'order_no', title: '入库单号', width: 160 },
                { field: 'type_text', title: '类型', width: 100 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                { field: 'total_quantity', title: '总数量', width: 90, align: 'right' },
                { field: 'total_amount', title: '总金额', width: 110, align: 'right', formatter(v) { return '￥' + (v || 0); } },
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
                    type: $('#search-type').val() || '',
                    warehouse_id: $('#search-warehouse').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add', name: 'add', title: '新增入库单', icon: 'bi bi-plus-lg',
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
            $('#search-type').val('');
            $('#search-warehouse').val('');
            $('#search-status').val('');
            refresh(table);
        });

        // 明细商品下拉（仅加载一次）
        async function ensureProducts() {
            if (productsLoaded) return;
            try {
                const res = await http.get(URL.products, { pageNumber: 1, pageSize: 999 });
                const rows = res && res.rows ? res.rows : [];
                let html = '';
                rows.forEach((p) => { html += '<option value="' + p.id + '">' + p.name + '</option>'; });
                window.__stockInProductOptions = html;
                productsLoaded = true;
            } catch (e) { /* 全局提示 */ }
        }

        function addItemRow(productId) {
            itemSeq++;
            const $row = $('<tr class="item-row">');
            $row.append('<td class="item-seq">' + itemSeq + '</td>');
            $row.append('<td><select class="form-select form-select-sm item-product">' + (window.__stockInProductOptions || '') + '</select></td>');
            $row.append('<td><input type="number" class="form-control form-control-sm item-quantity" value="1" min="1"></td>');
            $row.append('<td><input type="number" class="form-control form-control-sm item-price" value="0" min="0" step="0.01"></td>');
            $row.append('<td class="text-end item-amount">0.00</td>');
            $row.append('<td><input type="text" class="form-control form-control-sm item-remark" placeholder="备注"></td>');
            $row.append('<td><button type="button" class="btn btn-xs btn-outline-danger btn-remove-item"><i class="bi bi-trash"></i></button></td>');
            $tbody.append($row);
            $row.find('.item-quantity, .item-price').on('input', () => updateAmount($row));
            if (productId) $row.find('.item-product').val(productId);
        }

        function updateAmount($row) {
            const q = parseFloat($row.find('.item-quantity').val()) || 0;
            const p = parseFloat($row.find('.item-price').val()) || 0;
            $row.find('.item-amount').text((q * p).toFixed(2));
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
            $('#form-title').text(id ? '编辑入库单' : '新增入库单');
            if (row) {
                $('#form-warehouse').val(row.warehouse_id || '');
                $('#form-type').val(row.type || '');
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

            const warehouse_id = $('#form-warehouse').val();
            const type = $('#form-type').val();

            const items = [];
            $tbody.find('tr.item-row').each(function () {
                const $r = $(this);
                const product_id = $r.find('.item-product').val();
                const quantity = parseInt($r.find('.item-quantity').val(), 10) || 0;
                if (product_id && quantity > 0) {
                    items.push({
                        product_id,
                        quantity,
                        price: parseFloat($r.find('.item-price').val()) || 0,
                        remark: $r.find('.item-remark').val() || '',
                    });
                }
            });
            if (!items.length) { toast('请至少添加一条明细', 'warning'); return; }

            const data = {
                id: $('#form-id').val(),
                warehouse_id,
                type,
                order_date: $('#form-date').val() || '',
                remark: $('#form-remark').val() || '',
                items,
            };
            const res = await submit({ url: URL.save, data, message: '保存成功' });
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

    /** 详情页（服务端渲染，无需额外逻辑） */
    detail() {},
};

export default Controller;
