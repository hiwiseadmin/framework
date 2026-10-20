/**
 * category.js — 分类管理（ESM 控制器）
 *
 * 树形表格（treeShowField/idField/parentIdField）+ 同级拖拽排序（category/sort）
 * + 行右键菜单（新增子分类 / 编辑 / 展开折叠 / 删除）。
 *
 * 父级分类下拉通过 AJAX（category/tree）加载；编辑时整行数据预填。
 */

import { http, $, submit, confirm, toast } from './common/util.js';
import { createTable, operateButtons, refresh, bindListState } from './common/table.js';

const URL = {
    list: '/plugin/wisedocs/category/getList',
    tree: '/plugin/wisedocs/category/tree',
    save: '/plugin/wisedocs/category/save',
    sort: '/plugin/wisedocs/category/sort',
    del:  '/plugin/wisedocs/category/delete',
};

const OPERATE = [
    { action: 'addChild', title: '新增子分类', icon: 'bi bi-plus-square', class: 'btn-outline-secondary' },
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

const Controller = {
    index() {
        const $modal = $('#category-modal');
        const $form  = $('#category-form');

        bindListState('category', $('#search-form'));

        const table = createTable({
            url: URL.list,
            // 树形表格：全量返回、不分页，列索引对齐（0 复选框 / 1 id / 2 name）
            pagination: false,
            checkboxHeader: true,
            treeShowField: 'name',
            idField: 'id',
            parentIdField: 'parent_id',
            treeColumn: 2,
            initialState: 'expanded',
            extend: {
                dragsort_url: URL.sort,
            },
            columns: [
                { field: 'ck', checkbox: true, align: 'center', valign: 'middle' },
                { field: 'id', title: 'ID', width: 70, align: 'center' },
                {
                    field: 'name',
                    title: '分类名称',
                    formatter(value, row) {
                        const icon = row.icon
                            ? '<i class="' + (row.icon || '') + ' me-1"></i>'
                            : '<i class="bi bi-folder me-1"></i>';
                        return icon + (value || '');
                    },
                },
                { field: 'slug', title: '标识', width: 160 },
                { field: 'sort', title: '排序', width: 70, align: 'center' },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/plugin/wisedocs/category/status',
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 220,
                    align: 'center',
                    formatter(value, row) {
                        // 拖拽句柄必须为 <a class="btn-dragsort">（jQuery UI sortable 的 handle 选择器）
                        return (
                            '<a href="javascript:;" class="btn btn-xs btn-outline-secondary btn-dragsort me-1" ' +
                            'title="拖动排序（同级）"><i class="bi bi-arrows-expand-vertical"></i></a>' +
                            operateButtons(OPERATE, row)
                        );
                    },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    project_id: $('#search-project').val() || '',
                    status: $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add',
                    name: 'add',
                    title: '新增分类',
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
                if (action === 'addChild') return openModal(null, { parent_id: row.id, project_id: row.project_id });
                if (action === 'edit') return openModal(row);
                if (action === 'del') return remove(row);
            },
        });

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        const validator = new window.WsFormValidator('category-form', {
            liveValidation: true,
            showIcons: true,
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-project').val('');
            $('#search-status').val('');
            refresh(table);
        });

        // ---- 行右键菜单（新建子分类 / 编辑 / 展开折叠 / 删除）----
        $('#table').on('contextmenu', 'tbody tr', function (e) {
            e.preventDefault();

            const $tr = $(this);
            const index = parseInt($tr.attr('data-index'), 10);
            const rows = $('#table').bootstrapTable('getData') || [];
            const row = rows[index];
            if (!row) return;

            const hasChildren = rows.some((r) => Number(r.parent_id) === Number(row.id));
            const isExpanded = $tr.hasClass('treegrid-expanded');

            const items = [
                { id: 'add-child', label: '新增子分类', icon: 'bi bi-plus-circle', action: () => openModal(null, { parent_id: row.id, project_id: row.project_id }) },
                { id: 'edit', label: '编辑', icon: 'bi bi-pencil', action: () => openModal(row) },
            ];

            if (hasChildren) {
                items.push(
                    { id: 'div1', divider: true },
                    {
                        id: 'toggle',
                        label: isExpanded ? '折叠' : '展开',
                        icon: isExpanded ? 'bi bi-arrows-collapse' : 'bi bi-arrows-expand',
                        action: () => $tr.treegrid('toggle'),
                    }
                );
            }

            items.push(
                { id: 'div2', divider: true },
                { id: 'del', label: '删除', icon: 'bi bi-trash', action: () => remove(row) }
            );

            const ui = window.Wise && window.Wise.ui;
            if (ui && ui.contextMenu) {
                ui.contextMenu.show(items, { x: e.clientX, y: e.clientY });
            }
        });

        // 父级分类下拉（按当前空间加载）
        let treeLoadedFor = null;

        /**
         * 加载（或强制重载）分类树
         * @param {boolean} [force] 忽略缓存强制重载
         */
        async function ensureTree(force) {
            const projectId = Number($('#form-project-id').val()) || 0;
            if (!force && treeLoadedFor === projectId) return;
            try {
                const res = await http.get(URL.tree, { project_id: projectId });
                // 保留「顶级分类」占位项，其余重建
                $('#form-parent-id').find('option').not('[value="0"]').remove();
                renderOptions(res || [], 0);
                treeLoadedFor = projectId;
            } catch (e) {
                // 失败时保持未加载状态，下次打开弹窗会重试（不静默缓存失败结果）
                treeLoadedFor = null;
            }
        }

        // 切换「所属空间」后按新空间重载树
        $('#form-project-id').on('change', () => { ensureTree(true); });

        function renderOptions(tree, level) {
            (tree || []).forEach((item) => {
                const prefix = level > 0 ? '├'.repeat(level) + ' ' : '';
                $('#form-parent-id').append('<option value="' + item.id + '">' + prefix + item.name + '</option>');
                if (item.children && item.children.length) {
                    renderOptions(item.children, level + 1);
                }
            });
        }

        /**
         * 打开新增 / 编辑弹窗
         * @param {Object|null} row    编辑时传行数据
         * @param {Object} [preset]    新增时的预填（如右键「新增子分类」预置 parent_id / project_id）
         */
        async function openModal(row, preset) {
            preset = preset || {};
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑分类' : '新增分类');

            if (row) {
                $('#form-name').val(row.name || '');
                $('#form-slug').val(row.slug || '');
                $('#form-icon').val(row.icon || '');
                $('#form-sort').val(row.sort_order || 0);
                $('#form-status').val(row.status != null ? row.status : 1);
                $('#form-description').val(row.description || '');
                $('#form-project-id').val(row.project_id || 0);
            } else {
                $('#form-sort').val(0);
                $('#form-status').val(1);
                $('#form-project-id').val(preset.project_id || 0);
            }

            await ensureTree();
            $('#form-parent-id').val(row ? (row.parent_id || 0) : (preset.parent_id || 0));

            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除分类「' + (row.name || '') + '」吗？');
            if (!ok) return;
            const done = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (done) {
                treeLoadedFor = null;
                refresh(table);
            }
        }

        /**
         * 批量删除（勾选行 → 二次确认 → 批量接口）
         *
         * 后端对「存在子分类 / 分类下有文档」的项会整体拒绝并给出原因，
         * 因此这里不做前置校验，直接交由服务端裁决。
         */
        async function batchRemove() {
            const ids = window.Wise.table.getSelectedIds('#table') || [];
            if (!ids.length) {
                toast('请先勾选要删除的分类', 'warning');
                return;
            }

            const ok = await confirm('确定要删除选中的 ' + ids.length + ' 个分类吗？');
            if (!ok) return;

            const done = await submit({ url: URL.del, data: { ids }, message: '删除成功' });
            if (done) {
                treeLoadedFor = null;
                refresh(table);
            }
        }

        $form.on('submit', async function (e) {
            e.preventDefault();

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
                treeLoadedFor = null;
                refresh(table);
            }
        });
    },
};

export default Controller;
