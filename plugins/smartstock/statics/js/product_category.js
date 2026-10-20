/**
 * product_category.js — 商品分类管理（ESM 控制器，auto-dispatch）
 *
 * 由 wise-bootstrap 依据 WS_CONFIG.controller + action 自动加载并派发方法，
 * 视图中不再编写任何内联脚本（除携带 nonce 的配置注入）。
 * HTTP 统一走 wise-util.js 的 http（successCode=0，自动 toast 错误）。
 *
 * 列表与「新增/编辑」共用本页：表单以模态框（#category-modal）呈现，
 * 编辑时直接以操作列返回的整行 row 预填，无需额外服务端详情接口。
 */

import { http, $, toast, confirm, submit } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list: '/plugin/smartstock/product_category/getList',
    tree: '/plugin/smartstock/product_category/tree',
    save: '/plugin/smartstock/product_category/save',
    del:  '/plugin/smartstock/product_category/delete',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

const Controller = {
    /** 分类列表 + 新增/编辑模态框 */
    index() {
        const $modal = $('#category-modal');
        const $form  = $('#category-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // 规则写在模板的 data-validation 上；window.WsFormValidator 是组件官方挂载的全局
        const validator = new window.WsFormValidator('category-form', {
            liveValidation: true,
            showIcons: true,
        });

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 80, align: 'center', sortable: true },
                { field: 'name', title: '分类名称' },
                { field: 'parent_id', title: '父级ID', width: 100, align: 'center' },
                { field: 'sort_order', title: '排序', width: 80, align: 'center' },
                {
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter(value, row) {
                        return badge(row.status_text || '-', Number(row.status) === 1 ? 'success' : 'secondary');
                    },
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
                return {
                    name: $('#search-name').val() || '',
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

        // 搜索栏
        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-name').val('');
            $('#search-status').val('');
            refresh(table);
        });

        // 父级分类下拉（仅加载一次）
        let treeLoaded = false;
        async function ensureTree() {
            if (treeLoaded) return;
            try {
                const res = await http.get(URL.tree, {});
                renderCategoryOptions(res || [], 0);
                treeLoaded = true;
            } catch (e) { /* 错误由 http 全局处理器提示 */ }
        }

        function renderCategoryOptions(tree, level) {
            (tree || []).forEach((item) => {
                const prefix = level > 0 ? '├'.repeat(level) + ' ' : '';
                $('#form-parent-id').append('<option value="' + item.id + '">' + prefix + item.name + '</option>');
                if (item.children && item.children.length) {
                    renderCategoryOptions(item.children, level + 1);
                }
            });
        }

        async function openModal(row) {
            $form[0].reset();
            $('#form-id').val(row ? row.id : 0);
            $('#form-title').text(row ? '编辑分类' : '新增分类');
            if (row) {
                $('#form-name').val(row.name || '');
                $('#form-parent-id').val(row.parent_id || 0);
                $('#form-sort').val(row.sort_order || 0);
                $('#form-status').val(row.status != null ? row.status : 1);
            } else {
                $('#form-parent-id').val(0);
                $('#form-sort').val(0);
                $('#form-status').val(1);
            }
            await ensureTree();
            $modal.modal('show');
        }

        async function remove(row) {
            const ok = await confirm('确定要删除分类「' + (row.name || '') + '」吗？');
            if (!ok) return;
            const res = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (res) refresh(table);
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
            const res = await submit({
                url: URL.save,
                data,
                message: (Number($('#form-id').val()) > 0 ? '修改成功' : '添加成功'),
            });
            if (res) {
                $modal.modal('hide');
                refresh(table);
            }
        });
    },
};

export default Controller;
