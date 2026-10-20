/**
 * tag.js — 标签管理（ESM 控制器）
 *
 * P0 交互升级：
 *   1. 开启勾选 + 批量删除（后端 `tag/delete` 早已接受 ids 数组，此前无入口）；
 *   2. 「合并标签」补二次确认（此前直接提交，误操作不可逆）；
 *   3. 列表空态统一（见 common/table.js）。
 */

import { http, $, toast, submit, confirm } from './common/util.js';
import { createTable, operateButtons, refresh, selectedIds, bindListState } from './common/table.js';

const URL = {
    list:    '/plugin/wisedocs/tag/getList',
    options: '/plugin/wisedocs/tag/options',
    save:    '/plugin/wisedocs/tag/save',
    del:     '/plugin/wisedocs/tag/delete',
    merge:   '/plugin/wisedocs/tag/merge',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

const Controller = {
    index() {
        const $modal = $('#tag-modal');
        const $form  = $('#tag-form');

        bindListState('tag', $('#search-form'));

        const table = createTable({
            url: URL.list,
            // 批量选择：与 Tag::delete 的 ids 入参配套
            checkboxHeader: true,
            columns: [
                { field: 'ck', checkbox: true, align: 'center', valign: 'middle' },
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'name', title: '标签名称' },
                { field: 'slug', title: '标识', width: 180 },
                {
                    field: 'count',
                    title: '引用数',
                    width: 100,
                    align: 'center',
                    formatter(value, row) {
                        const n = Number(row.count) || 0;
                        if (n <= 0) {
                            return '<span class="text-muted">0</span>';
                        }
                        return '<a href="/plugin/wisedocs/document/index?tag_id=' + row.id +
                            '" title="查看引用该标签的文档">' + n + '</a>';
                    },
                },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/plugin/wisedocs/tag/status',
                },
                { field: 'create_time', title: '创建时间', width: 160 },
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
                return { keyword: $('#search-keyword').val() || '' };
            },
            toolbarBtn: [
                {
                    id: 'btn-add',
                    name: 'add',
                    title: '新增标签',
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
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        const validator = new window.WsFormValidator('tag-form', {
            liveValidation: true,
            showIcons: true,
        });

        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            refresh(table);
        });

        function openModal(row) {
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑标签' : '新增标签');

            if (row) {
                $('#form-name').val(row.name || '');
                $('#form-slug').val(row.slug || '');
                $('#form-status').val(row.status != null ? row.status : 1);
            } else {
                $('#form-status').val(1);
            }

            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除标签「' + (row.name || '') + '」吗？');
            if (!ok) return;
            const done = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (done) refresh(table);
        }

        /** 批量删除（被文档引用的标签由服务端拒绝） */
        async function batchRemove() {
            const ids = selectedIds('#table');
            if (!ids.length) {
                toast('请先勾选要删除的标签', 'warning');
                return;
            }

            const ok = await confirm(
                '确定要删除选中的 ' + ids.length + ' 个标签吗？已被文档引用的标签会被拒绝。'
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

        // ---- 合并标签 ----
        async function loadMergeOptions() {
            const list = (await http.get(URL.options, {})) || [];
            const $src = $('#merge-source-ids').empty();
            const $tgt = $('#merge-target-id').empty();

            list.forEach((t) => {
                const label = t.name + '（' + (t.count || 0) + '）';
                $src.append('<option value="' + t.id + '">' + label + '</option>');
                $tgt.append('<option value="' + t.id + '">' + label + '</option>');
            });
        }

        $('#btn-merge').on('click', async () => {
            try {
                await loadMergeOptions();
            } catch (e) {
                return;
            }
            $('#tag-merge-modal').modal('show');
        });

        $('#btn-merge-submit').on('click', async () => {
            const sourceIds = ($('#merge-source-ids').val() || []).map(Number);
            const targetId = Number($('#merge-target-id').val()) || 0;

            if (!sourceIds.length) {
                toast('请选择要合并的源标签', 'warning');
                return;
            }
            if (!targetId) {
                toast('请选择目标标签', 'warning');
                return;
            }
            if (sourceIds.indexOf(targetId) !== -1) {
                toast('目标标签不能同时作为源标签', 'warning');
                return;
            }

            // 合并不可逆：源标签会被删除，关联文档转移到目标标签
            const ok = await confirm(
                '确定把选中的 ' + sourceIds.length + ' 个源标签合并到目标标签吗？\n' +
                '合并后源标签将被删除，其关联文档会全部转移到目标标签。'
            );
            if (!ok) return;

            const done = await submit({
                url: URL.merge,
                data: { source_ids: sourceIds, target_id: targetId },
                message: '合并成功',
            });
            if (done) {
                $('#tag-merge-modal').modal('hide');
                refresh(table);
            }
        });
    },
};

export default Controller;
