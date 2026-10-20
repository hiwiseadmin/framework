/**
 * space.js — 空间管理（ESM 控制器，由 wise-bootstrap 自动加载并派发方法）
 *
 * P0 交互升级：
 *   1. 开启勾选 + 批量删除（后端 `space/delete` 早已接受 ids 数组，此前无入口）；
 *   2. 「进入前台」快捷入口（按当前空间打开帮助中心）；
 *   3. 列表空态统一（见 common/table.js）。
 */

import { $, toast, submit, confirm } from './common/util.js';
import { createTable, operateButtons, refresh, selectedIds, bindListState } from './common/table.js';

const URL = {
    list: '/plugin/wisedocs/space/getList',
    save: '/plugin/wisedocs/space/save',
    del:  '/plugin/wisedocs/space/delete',
};

/** 前台帮助中心（带空间参数） */
const FRONT_URL = '/plugin/wisedocs/index/index';

const OPERATE = [
    { action: 'view', title: '进入前台', icon: 'bi bi-box-arrow-up-right', class: 'btn-outline-secondary' },
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

const Controller = {
    index() {
        const $modal = $('#space-modal');
        const $form  = $('#space-form');

        bindListState('space', $('#search-form'));

        const table = createTable({
            url: URL.list,
            // 批量选择：与 Space::delete 的 ids 入参配套
            checkboxHeader: true,
            columns: [
                { field: 'ck', checkbox: true, align: 'center', valign: 'middle' },
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'name', title: '空间名称' },
                { field: 'code', title: '编码', width: 140 },
                { field: 'domain', title: '独立域名' },
                { field: 'sort_order', title: '排序', width: 80, align: 'center' },
                { field: 'document_count', title: '文档数', width: 80, align: 'center' },
                { field: 'category_count', title: '分类数', width: 80, align: 'center' },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/plugin/wisedocs/space/status',
                },
                { field: 'create_time', title: '创建时间', width: 160 },
                {
                    field: 'operate',
                    title: '操作',
                    width: 200,
                    align: 'center',
                    formatter(value, row) {
                        return operateButtons(OPERATE, row);
                    },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add',
                    name: 'add',
                    title: '新增空间',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary mx-1',
                    click() { openModal(null); },
                },
                {
                    id: 'btn-batch-del',
                    name: 'batchDel',
                    title: '批量删除',
                    icon: 'bi bi-trash3',
                    class: 'btn btn-sm btn-danger mx-1',
                    click() { batchRemove(); },
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
                if (action === 'view') {
                    window.open(FRONT_URL + '?project=' + encodeURIComponent(row.id), '_blank', 'noopener');
                    return;
                }
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        const validator = new window.WsFormValidator('space-form', {
            liveValidation: true,
            showIcons: true,
        });

        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-status').val('');
            refresh(table);
        });

        function openModal(row) {
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑空间' : '新增空间');

            if (row) {
                $('#form-name').val(row.name || '');
                $('#form-code').val(row.code || '');
                $('#form-domain').val(row.domain || '');
                $('#form-theme').val(row.theme || 'default');
                $('#form-sort').val(row.sort_order || 0);
                $('#form-status').val(row.status != null ? row.status : 1);
                $('#form-description').val(row.description || '');
            } else {
                $('#form-theme').val('default');
                $('#form-sort').val(0);
                $('#form-status').val(1);
            }

            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm(
                '确定要删除空间「' + (row.name || '') + '」吗？空间下存在分类或文档时会被拒绝。'
            );
            if (!ok) return;
            const done = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (done) refresh(table);
        }

        /** 批量删除（空间下有分类/文档时由服务端整体拒绝） */
        async function batchRemove() {
            const ids = selectedIds('#table');
            if (!ids.length) {
                toast('请先勾选要删除的空间', 'warning');
                return;
            }

            const ok = await confirm(
                '确定要删除选中的 ' + ids.length + ' 个空间吗？空间下存在分类或文档时会被拒绝。'
            );
            if (!ok) return;

            const done = await submit({ url: URL.del, data: { ids }, message: '删除成功' });
            if (done) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();

            // 校验交由 WsFormValidator 统一负责（错误内联到字段，不再弹 toast）
            if (validator.validateForm() !== true) return;

            const data = {};
            $form.find('input, select, textarea').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });

            const done = await submit({
                url: URL.save,
                data,
                message: (Number($('#form-id').val()) > 0 ? '修改成功' : '添加成功'),
            });
            if (done) {
                $modal.modal('hide');
                refresh(table);
            }
        });
    },
};

export default Controller;
