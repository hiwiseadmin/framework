/**
 * menu.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
// 弹窗/提示统一走 Wise.ui（UIS v1.1 §4）：iframe 页面取父窗口实例，弹层才落在可视层。
const WS_UI = (window.parent && window.parent.Wise && window.parent.Wise.ui) || window.Wise.ui;
const dialog = WS_UI.dialog;

/**
 * 权限判断（ident 与 auth_permissions.ident 精确一致）
 *
 * 权限快照未就绪时 can() 返回 false（fail-closed）：宁可少一个按钮，
 * 也不要出现「按钮点得到、请求 403」的死交互。
 */
function canPerm(ident) {
    return !!(window.Wise && window.Wise.permission && window.Wise.permission.can(ident));
}

/** 是否有权查看回收站（后端 recycleList() 存在时才可能渲染） */
function canRecycle() {
    return canPerm('admin.menu.recycle_list');
}

const Controller = {
        index() {
            // 初始化表格
            const table = new Table({
                idTable: 'table',
                url: '/admin/menu/getList',
                showColumns: true,
                // sidePagination: 'client', // 改为客户端分页
                pagination: false,
                dataDataField: 'data.list', // 假设返回的数据字段为 menus
                treeColumn: 2,          // 树形网格的列索引，从 0 开始计数
                treeShowField: 'name', // 显示树状结构的字段，设置后将自动启用树形网格
                idField: 'id', // 树状结构的 ID 字段
                parentIdField: 'parent_id', // 树状结构的父节点字段
                extend: {
                    preview_url: '/admin/menu/preview',
                    add_url: '/admin/menu/add',
                    edit_url: '/admin/menu/edit',
                    del_url: '/admin/menu/delete',
                    multi_url: '/admin/menu/multi',
                    dragsort_url: '/admin/menu/dragsort'
                },
                // UIS v1（P1-5 golden sample）：语义名单，样式/顺序/分组由内核决定。
                // export 已移除：内核没有 .btn-export 处理器，导出走 bootstrap-table 自带下拉。
                toolbarBtn:['add','preview','edit','del','refresh','import',
                    // 回收站：控制器声明了 recycleList() 才存在该端点，权限节点见
                    // database/migrations/*_add_menu_recycle_permissions.php
                    ...(canRecycle() ? [{
                        id: 'menuRecycle',
                        name: 'menuRecycle',
                        title: '回收站',
                        icon: 'bi bi-recycle',
                        variant: 'outline-secondary',
                        group: 'actions',
                        click() {
                            Controller.openRecycle();
                        }
                    }] : []),
                    {
                        id: 'syncMenu',
                        name: 'syncMenu',
                        title: '同步声明菜单',
                        icon: 'bi bi-cloud-arrow-down',
                        // 不再写 class：由 variant 派生（D4：唯一尺寸 btn-sm）
                        variant: 'outline-secondary',
                        group: 'actions',
                        click() {
                            dialog.confirm(
                                '将按扩展点声明（ExtPoints::MENU_ITEM）同步后台菜单：已存在的菜单默认保留后台改动，仅补建缺失项与恢复软删项。是否继续？',
                                {title: '同步声明菜单'}
                            ).then(function (ok) {
                                if (!ok) return;
                                $.post('/admin/menu/sync', {}, function (res) {
                                    if (res.code === 0) {
                                        WS_UI.toast.success(res.msg);
                                        $('#table').bootstrapTable('refresh');
                                    } else {
                                        WS_UI.toast.error(res.msg);
                                    }
                                }, 'json');
                            });
                        }
                    }],
                columns: [{
                    field: 'ck',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle'
                },{
                    field: 'id',
                    title: 'ID',
                    width: '60',
                    sortable: true
                }, {
                    field: 'name',
                    title: '名称',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'icon',
                    title: '图标',
                    formatter: 'icon',
                    defaultIcon: 'bi bi-circle',
                    align: 'center',
                    width: '40'
                }, {
                    field: 'path',
                    title: '路径',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'component',
                    title: '组件',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'type',
                    title: '类型',
                    formatter: 'normal',
                    searchList: {
                        'dir': '目录',
                        'menu': '菜单',
                        'button': '按钮'
                    },
                    align: 'left',
                    width: '100'
                }, {
                    field: 'status',
                    title: '状态',
                    url: '/admin/menu/status',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    width: '100'
                }, {
                    field: 'sort',
                    title: '排序',
                    url: '/admin/menu/sort',
                    formatter: 'input',
                    align: 'center',
                    width: '80'
                }, {
                    field: 'operate',
                    buttons: ['dragsort',
                        {name:'add', disable(value, row, button) {return row.type == 'button'; }},
                        'preview','edit','del'],
                    title: '操作',
                    width: '200',
                    formatter: 'operate'
                }]
            });
            // 树状结构展开第一层
            $('#table').on('post-body.bs.table',function(){
                var $roots = $('tr[class^="treegrid-"]:not([class*="treegrid-parent-"])');
                if ($roots.length > 0 && $.fn.treegrid && $.fn.treegrid.defaults) {
                    $roots.treegrid('expand');
                }
            });
        },

        // ==================== 回收站 ====================

        /** 打开回收站弹层（首次创建表格，之后仅刷新） */
        async openRecycle() {
            const modalEl = Controller.ensureRecycleModal();
            const Modal = window.bootstrap && window.bootstrap.Modal;
            const modal = Modal ? Modal.getOrCreateInstance(modalEl) : null;

            if (modal) modal.show();

            if (Controller._recycleTable) {
                window.Wise.table.refresh(Controller._recycleTable);
                return;
            }

            const canRestore = canPerm('admin.menu.recycle_restore');
            const canForceDelete = canPerm('admin.menu.recycle_force_delete');

            const toolbarBtn = [];
            if (canRestore) {
                toolbarBtn.push({
                    name: 'recycle-restore',
                    title: '还原',
                    icon: 'bi bi-arrow-counterclockwise',
                    variant: 'outline-primary',
                    group: 'actions',
                    order: 10,
                    click() {
                        Controller.recycleRestore(Controller.recycleSelectedIds());
                    }
                });
            }
            if (canForceDelete) {
                toolbarBtn.push({
                    name: 'recycle-force-delete',
                    title: '彻底删除',
                    icon: 'bi bi-trash3',
                    variant: 'outline-danger',
                    group: 'actions',
                    order: 20,
                    click() {
                        Controller.recycleForceDelete(Controller.recycleSelectedIds());
                    }
                });
            }
            toolbarBtn.push('refresh');

            // 操作列按钮按权限裁剪
            const rowButtons = [];
            if (canRestore) {
                rowButtons.push({ action: 'restore', title: '还原', icon: 'bi bi-arrow-counterclockwise', class: 'btn-outline-primary' });
            }
            if (canForceDelete) {
                rowButtons.push({ action: 'force-delete', title: '彻底删除', icon: 'bi bi-trash3', class: 'btn-outline-danger' });
            }

            Controller._recycleTable = await window.Wise.table.create({
                idTable: 'menuRecycleTable',
                toolbar: '#menuRecycleToolbar',
                url: '/admin/menu/recycleList',
                dataDataField: 'data.list',
                dataTotalField: 'data.total',
                pageSize: 20,
                checkboxHeader: true,
                maintainSelected: true,
                showColumns: true,
                queryParams(params) {
                    const limit = params.limit > 0 ? params.limit : 20;
                    return {
                        page: Math.floor((params.offset || 0) / limit) + 1,
                        limit
                    };
                },
                toolbarBtn,
                columns: [{
                    field: 'ck',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle',
                    width: '40'
                }, {
                    field: 'id',
                    title: 'ID',
                    width: '70'
                }, {
                    field: 'name',
                    title: '名称',
                    align: 'left'
                }, {
                    field: 'path',
                    title: '路径',
                    align: 'left'
                }, {
                    field: 'type',
                    title: '类型',
                    width: '90',
                    formatter(value) {
                        return { dir: '目录', menu: '菜单', button: '按钮' }[value] || value || '-';
                    }
                }, {
                    field: 'delete_time',
                    title: '删除时间',
                    width: '170'
                }, {
                    title: '操作',
                    width: '110',
                    formatter: rowButtons.length
                        ? (_v, row) => window.Wise.table.operateButtons(rowButtons, row)
                        : () => '-'
                }],
                onClick(action, row) {
                    if (action === 'restore') {
                        Controller.recycleRestore([row.id]);
                    } else if (action === 'force-delete') {
                        Controller.recycleForceDelete([row.id]);
                    }
                }
            });
        },

        /** 回收站弹层 DOM（懒创建，避免给所有页面加静态标记） */
        ensureRecycleModal() {
            let modalEl = document.getElementById('menuRecycleModal');
            if (modalEl) return modalEl;

            modalEl = document.createElement('div');
            modalEl.className = 'modal fade';
            modalEl.id = 'menuRecycleModal';
            modalEl.tabIndex = -1;
            modalEl.innerHTML = `
                <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content ws-modal">
                        <div class="modal-header">
                            <h5 class="modal-title">菜单回收站</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                        </div>
                        <div class="modal-body">
                            <div id="menuRecycleToolbar" class="ws-toolbar mb-2"></div>
                            <table id="menuRecycleTable"></table>
                        </div>
                        <div class="modal-footer">
                            <span class="text-secondary small me-auto">还原后菜单回到原层级；父级仍在回收站时请先还原父级</span>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">关闭</button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(modalEl);

            return modalEl;
        },

        /** 回收站当前勾选的主键 */
        recycleSelectedIds() {
            const inst = Controller._recycleTable;
            const ids = inst && typeof inst.getSelectedIds === 'function' ? inst.getSelectedIds() : [];
            return (ids || []).filter((id) => id !== null && id !== undefined && id !== '');
        },

        /** 回收站统一 POST（返回 res，失败抛 Error(res.msg)） */
        recyclePost(url, data) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url,
                    type: 'POST',
                    data,
                    dataType: 'json',
                    success(res) {
                        if (res && res.code === 0) {
                            resolve(res);
                        } else {
                            reject(new Error((res && res.msg) || '操作失败'));
                        }
                    },
                    error() {
                        reject(new Error('网络错误或权限不足'));
                    }
                });
            });
        },

        /** 还原（单个 / 批量） */
        async recycleRestore(ids) {
            if (!ids || !ids.length) {
                WS_UI.toast.error('请选择要还原的菜单');
                return;
            }

            try {
                const res = await Controller.recyclePost('/admin/menu/recycleRestore', { ids });
                WS_UI.toast.success(res.msg || '已还原');
                Controller.refreshRecycle();
                $('#table').bootstrapTable('refresh');
            } catch (e) {
                WS_UI.toast.error(e.message);
            }
        },

        /** 彻底删除（先签发一次性确认令牌，再提交） */
        async recycleForceDelete(ids) {
            if (!ids || !ids.length) {
                WS_UI.toast.error('请选择要彻底删除的菜单');
                return;
            }

            const confirmed = await dialog.confirm('彻底删除后不可恢复，确定继续？', { title: '彻底删除' });
            if (!confirmed) return;

            try {
                const tokenRes = await Controller.recyclePost('/admin/menu/recycleToken', {});
                const token = (tokenRes && tokenRes.data && tokenRes.data.confirm_token) || '';
                if (!token) throw new Error('确认令牌签发失败');

                const res = await Controller.recyclePost('/admin/menu/recycleForceDelete', {
                    ids,
                    confirm_token: token
                });
                WS_UI.toast.success(res.msg || '已彻底删除');
                Controller.refreshRecycle();
            } catch (e) {
                WS_UI.toast.error(e.message);
            }
        },

        /** 刷新回收站表格（表格未创建时忽略） */
        refreshRecycle() {
            if (Controller._recycleTable) {
                window.Wise.table.refresh(Controller._recycleTable);
            }
        },

        show(){

        },
        add() {
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {
                    // console.log(form, e, data);
                }
            });

            $('#icon').on('change', function () {
                const icon = $(this).val();
                $('#iconPreview').html(`<i class="${icon}"></i>`);
            });
        },
        edit() {
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {
                    // console.log(form, e, data);
                }
            });

            $('#icon').on('change', function () {
                const icon = $(this).val();
                $('#iconPreview').html(`<i class="${icon}"></i>`);
            });
        }
    };

export default Controller;
