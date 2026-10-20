/**
 * permission.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const layer = window.parent.Layer || window.Layer;

const OK_CODE = 0;

/** 同步结果里的菜单名等来自服务端，展示前统一转义 */
function escSync(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[m];
    });
}

/**
 * 构建「同步权限」结果摘要
 * 数据结构见 Permission::updateFromMenuActionApi 的返回（menus/packages/drift）
 */
function buildSyncSummary(data) {
    var lines = [];

    if (data.menus) {
        var m = data.menus;
        lines.push('<div><strong>菜单/按钮</strong>：扫描 ' + m.scanned + '，同步 ' + m.synced
            + '，跳过目录 ' + m.skipped + '，失败 ' + m.failed + '</div>');
        if (m.errors && m.errors.length) {
            lines.push('<div class="text-danger small mt-1">' + m.errors.map(escSync).join('<br>') + '</div>');
        }
    }

    if (data.packages) {
        var p = data.packages;
        lines.push('<div class="mt-1"><strong>扩展包</strong>：新增 ' + p.created + '，更新 ' + p.updated
            + '，删除 ' + p.deleted + '，提供者 ' + p.providers + '</div>');
        if (p.errors && p.errors.length) {
            lines.push('<div class="text-danger small mt-1">' + p.errors.map(escSync).join('<br>') + '</div>');
        }
    }

    if (data.drift) {
        var d = data.drift;
        if (!d.orphan_count && !d.unbound_count) {
            lines.push('<div class="mt-1 text-success"><strong>结构检查</strong>：菜单与权限节点一致</div>');
        } else {
            lines.push('<div class="mt-1"><strong>结构检查</strong>：'
                + d.orphan_count + ' 个权限无对应菜单，' + d.unbound_count + ' 个菜单未生成权限</div>');
            if (d.orphan_samples && d.orphan_samples.length) {
                lines.push('<div class="small text-secondary">无菜单：' + d.orphan_samples.map(escSync).join('、') + '…</div>');
            }
            if (d.unbound_samples && d.unbound_samples.length) {
                lines.push('<div class="small text-secondary">未生成：' + d.unbound_samples.map(escSync).join('、') + '…</div>');
            }
        }
    }

    return '<div class="text-start" style="line-height:1.9">' + lines.join('') + '</div>';
}

const Controller = {
        index() {
            const table = new Table({
                idTable: 'table',
                url: '/admin/permission/getList?with_roles=1',
                pagination: false,
                treeColumn: 2,
                treeShowField: 'name',
                idField: 'id',
                parentIdField: 'parent_id',
                extend: {
                    preview_url: '/admin/permission/preview',
                    add_url: '/admin/permission/add',
                    edit_url: '/admin/permission/edit',
                    del_url: '/admin/permission/delete',
                    multi_url: '/admin/permission/multi'
                },
                toolbarBtn: ['add', 'del',
                    {id:'updatePermission', name:'updatePermission', title:'同步权限', icon:'bi bi-arrow-repeat', class:'btn btn-sm btn-primary mx-1', data:[{key:'bs-toggle', value:'modal'}, {key:'bs-target', value:'#updatePermissionModal'}]},
                    {id:'dropdown', name:'changeStatus', title:'状态', icon:'bi bi-check-circle', disabled:true, class:'btn btn-sm btn-secondary btn-disabled mx-1',
                        items: [{
                            value: '1',
                            icon: 'bi bi-check2',
                            event: 'changeStatus',
                            title: '启用'
                        }, {
                            value: '0',
                            icon: 'bi bi-x-lg',
                            event: 'changeStatus',
                            title: '禁用'
                        }]
                    }],
                columns: [{
                    field: 'state',
                    id: 'state',
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
                    title: '权限名称',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'ident',
                    title: '权限标识',
                    formatter: 'normal'
                }, {
                    field: 'method',
                    title: '请求方法',
                    formatter: 'normal',
                    searchList: {
                        '*': 'ALL',
                        'GET': 'GET',
                        'POST': 'POST',
                        'PUT': 'PUT',
                        'DELETE': 'DELETE',
                        'ALL': 'ALL'
                    }
                }, {
                    field: 'type',
                    title: '权限类型',
                    formatter: 'normal',
                    searchList: {
                        'menu': '菜单',
                        'api': 'API接口',
                        'custom': '自定义'
                    }
                }, {
                    field: 'ref_count',
                    title: '引用角色',
                    formatter(value, row) {
                        var roles = row.referenced_roles || [];
                        if (roles.length === 0) {
                            return '<span class="badge bg-light text-dark">未引用</span>';
                        }
                        var names = roles.map(function(r) { return r.name; }).join(', ');
                        return '<span class="badge bg-info text-white cursor-pointer role-ref-badge" '
                            + 'data-perm-id="' + row.id + '" '
                            + 'title="' + names + '">' + roles.length + ' 个角色</span>';
                    }
                }, {
                    field: 'status',
                    title: '状态',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/admin/permission/status'
                }, {
                    field: 'operate',
                    buttons: ['preview', 'add', 'edit', 'del'],
                    title: '操作',
                    formatter: 'operate'
                }]
            });

            // 树状结构展开第一层
            $('#table').on('post-body.bs.table', function () {
                var $roots = $('tr[class^="treegrid-"]:not([class*="treegrid-parent-"])');
                if ($roots.length > 0 && $.fn.treegrid && $.fn.treegrid.defaults) {
                    $roots.treegrid('expand');
                }
            });

            // 点击引用角色弹出浮层
            $(document).on('click', '.role-ref-badge', function (e) {
                e.stopPropagation();
                var permId = $(this).data('perm-id');
                showReferencedRoles(permId, $(this));
            });

            // 同步权限提交
            $('#updatePermissionForm').on('submit', function (e) {
                e.preventDefault();

                // 显式取开关值，不依赖表单序列化（旧实现干脆没传 data，
                // 服务端只能拿到空 body → 误判为「未选择任何同步项」）。
                var menus    = $('#menus').prop('checked') ? 1 : 0;
                var packages = $('#packages').prop('checked') ? 1 : 0;

                if (!menus && !packages) {
                    layer.toast({content: '请至少选择一项同步内容', type: 'warning'});
                    return;
                }

                var action = $(this)[0].action;
                layer.loading({icon: 2, content: '同步权限中...'});
                $.ajax({
                    url: action,
                    type: 'post',
                    data: { menus: menus, packages: packages },
                    dataType: 'json',
                    success(res) {
                        layer.closeAll('loading');
                        if (res.code === OK_CODE) {
                            var modalEl = document.getElementById('updatePermissionModal');
                            var modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
                            if (modal) modal.hide();

                            layer.alert(buildSyncSummary(res.data || {}), {title: '同步结果', icon: 'success'});
                            table.refresh();
                        } else {
                            layer.toast({content: res.msg || '同步失败', type: 'danger'});
                        }
                    },
                    error() {
                        layer.closeAll('loading');
                        layer.toast({content: '同步失败', type: 'danger'});
                    }
                });
            });

            // 状态切换
            $('[data-event="changeStatus"]').on('click', function (e) {
                e.preventDefault();
                var ids = table.getSelectedIds();
                var status = $(this).data('value');
                $.ajax({
                    url: '/admin/permission/status',
                    type: 'post',
                    dataType: 'json',
                    data: { ids: ids, status: status },
                    success(res) {
                        if (res.code === OK_CODE) {
                            layer.toast({content: res.msg || '切换成功', type: 'success'});
                            table.refresh();
                        } else {
                            layer.toast({content: res.msg || '切换失败', type: 'danger'});
                        }
                    },
                    error() {
                        layer.toast({content: '切换失败', type: 'danger'});
                    }
                });
            });
        },

        add() {
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {}
            });
        },

        edit() {
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {}
            });
        }
    };

    // =========== 引用角色浮层 ===========
    function showReferencedRoles(permId, $el) {
        $.ajax({
            url: '/admin/permission/getReferencedRoles',
            data: { id: permId },
            dataType: 'json',
            success(res) {
                if (res.code === OK_CODE) {
                    var d = res.data;
                    var directRoles = d.direct_roles || [];
                    var inheritingRoles = d.inheriting_roles || [];

                    var html = '<div style="max-height:300px;overflow-y:auto;padding:8px;">';
                    html += '<h6 class="mb-2">直接分配角色 (' + directRoles.length + ')</h6>';
                    if (directRoles.length === 0) {
                        html += '<p class="text-secondary small mb-2">无直接分配角色</p>';
                    } else {
                        directRoles.forEach(function (r) {
                            html += '<div class="mb-1">'
                                + '<a href="javascript:void(0)" class="role-link" data-role-id="' + r.id + '">'
                                + '<span class="badge bg-primary">' + r.name + '</span> ' + r.ident
                                + '</a>'
                                + '</div>';
                        });
                    }
                    if (inheritingRoles.length > 0) {
                        html += '<h6 class="mb-2 mt-3">继承获得角色 (' + inheritingRoles.length + ')</h6>';
                        inheritingRoles.forEach(function (r) {
                            html += '<div class="mb-1">'
                                + '<span class="badge bg-warning text-dark">' + r.name + '</span> ' + r.ident
                                + '</div>';
                        });
                    }
                    html += '</div>';

                    layer.open({
                        type: 1,
                        title: '权限「' + (d.permission ? d.permission.name : '') + '」被引用',
                        content: html,
                        area: ['400px', 'auto']
                    });

                    // 点击角色链接跳转到角色矩阵页
                    $(document).on('click', '.role-link', function () {
                        var roleId = $(this).data('role-id');
                        window.open('/admin/role/index?role_id=' + roleId, '_blank');
                    });
                }
            },
            error() {
                layer.toast({content: '加载失败', type: 'danger'});
            }
        });
    }

export default Controller;
