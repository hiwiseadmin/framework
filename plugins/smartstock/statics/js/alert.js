/**
 * alert.js — 预警管理（ESM 控制器，auto-dispatch）
 *
 * 规则管理：ruleIndex / ruleForm（独立页，提交 alert/saveRule）
 * 日志管理：logIndex（标记已读 / 批量已读）
 * HTTP 统一走 wise-util.js 的 http（successCode=0，自动 toast 错误）。
 */

import { http, $, toast, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const BASE = '/plugin/smartstock/alert';
const URL = {
    ruleList: BASE + '/getRuleList',
    saveRule: BASE + '/saveRule',
    deleteRule: BASE + '/deleteRule',
    toggleRule: BASE + '/toggleRule',
    ruleDetail: BASE + '/getRuleDetail',
    logList: BASE + '/getLogList',
    markRead: BASE + '/markRead',
    batchRead: BASE + '/batchRead',
};

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

const Controller = {
    /** 预警规则列表 */
    ruleIndex() {
        const table = createTable({
            url: URL.ruleList,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'product_name', title: '商品', width: 160 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                {
                    field: 'alert_type_text', title: '类型', width: 100,
                    formatter(v) { return badge(v || '-', 'warning'); },
                },
                { field: 'threshold', title: '阈值', width: 100, align: 'right' },
                {
                    field: 'status_text', title: '状态', width: 70, align: 'center',
                    formatter(v, row) { return badge(v || '-', Number(row.status) === 1 ? 'success' : 'secondary'); },
                },
                {
                    field: 'operate', title: '操作', width: 180, align: 'center',
                    formatter(value, row) {
                        const btns = [
                            { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                            { action: 'toggle', title: Number(row.status) === 1 ? '禁用' : '启用', icon: 'bi bi-power', class: Number(row.status) === 1 ? 'btn-outline-warning' : 'btn-outline-success' },
                            { action: 'delete', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                        ];
                        return operateButtons(btns, row);
                    },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    alert_type: $('#search-alert-type').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add', name: 'add', title: '新增规则', icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary mx-1', click() { window.location.href = BASE + '/ruleForm'; },
                },
                {
                    id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1', click() { refresh(table); },
                },
            ],
            onClick(action, row) {
                if (action === 'edit') window.location.href = BASE + '/ruleForm?id=' + row.id;
                else if (action === 'delete') deleteRule(row);
                else if (action === 'toggle') toggleRule(row);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-alert-type').val('');
            $('#search-status').val('');
            refresh(table);
        });

        async function deleteRule(row) {
            const ok = await confirm('确认删除规则「' + (row.product_name || 'ID#' + row.id) + '」？');
            if (!ok) return;
            const res = await submit({ url: URL.deleteRule, data: { id: row.id }, message: '删除成功' });
            if (res) refresh(table);
        }

        async function toggleRule(row) {
            const next = Number(row.status) === 1 ? 0 : 1;
            const res = await submit({ url: URL.toggleRule, data: { id: row.id, status: next }, message: '操作成功' });
            if (res) refresh(table);
        }
    },

    /** 预警规则表单（新增/编辑，独立页） */
    ruleForm() {
        const id = parseInt(new URLSearchParams(location.search).get('id'), 10) || 0;
        const $form = $('#alert-rule-form');

        $('#form-title').text(id ? '编辑预警规则' : '新增预警规则');

        $form.on('submit', async function (e) {
            e.preventDefault();
            const data = {
                id,
                product_id: $('#form-product-id').val() || '',
                warehouse_id: $('#form-warehouse-id').val() || '',
                alert_type: $form.find('[name="alert_type"]').val(),
                threshold: $form.find('[name="threshold"]').val(),
                status: $form.find('[name="status"]').val(),
            };
            const res = await submit({ url: URL.saveRule, data, message: '保存成功' });
            if (res) window.location.href = BASE + '/ruleIndex';
        });
    },

    /** 预警记录列表 */
    logIndex() {
        const table = createTable({
            url: URL.logList,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'product_name', title: '商品', width: 160 },
                { field: 'warehouse_name', title: '仓库', width: 140 },
                {
                    field: 'alert_type_text', title: '类型', width: 100,
                    formatter(v) { return badge(v || '-', 'warning'); },
                },
                { field: 'message', title: '预警内容', width: 280 },
                {
                    field: 'is_read_text', title: '状态', width: 70, align: 'center',
                    formatter(v, row) { return badge(v || '-', Number(row.is_read) === 1 ? 'secondary' : 'danger'); },
                },
                { field: 'create_time', title: '触发时间', width: 150 },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    alert_type: $('#search-alert-type').val() || '',
                    is_read: $('#search-is-read').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-batch-read', name: 'batchRead', title: '批量已读', icon: 'bi bi-check2-all',
                    class: 'btn btn-sm btn-success mx-1', click() { batchRead(table); },
                },
                {
                    id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1', click() { refresh(table); },
                },
            ],
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });

        async function batchRead(tbl) {
            const sel = $('#table').bootstrapTable('getSelections');
            if (!sel.length) { toast('请先勾选要标记的日志', 'warning'); return; }
            const ids = sel.map((r) => r.id);
            const res = await submit({ url: URL.batchRead, data: { ids }, message: '操作成功' });
            if (res) refresh(tbl);
        }
    },
};

export default Controller;
