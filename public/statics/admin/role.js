/**
 * role.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const layer = window.parent.Layer || window.Layer;  // 在 iframe 中使用父框架的 Layer 实例，弹窗才能正确渲染
const FormValidator = window.FormValidator;

'use strict';

var OK_CODE = 0;  // 后端 success() 统一返回 code: 0

var state = {
    currentRoleId: null,
    currentRole: null,
    rolePermissions: {},    // {roleId: {direct:[], all:[], is_inherit: bool, parent_id: int}}
    roles: [],
    permissionTree: [],
    expandedGroups: {},
    matrixData: null,
    showInheritChain: false   // 「查看继承链」按钮的选中态：选中后切换角色自动刷新
};

const Controller = {
    index() {
        loadRoleList();
        bindEvents();
        syncInheritChainButton();

        // 如果 URL 带了 role_id 参数，自动选中
        var urlParams = new URLSearchParams(window.location.search);
        var presetRoleId = urlParams.get('role_id');
        if (presetRoleId) {
            selectRole(parseInt(presetRoleId));
        }
    },

    add() {
        new FormValidator('wsForm', {
            liveValidation: true,
            showIcons: true,
            autoSubmit: true,
            onSubmit(form, e, data) {}
        });
        initPermissionTree([]);
    },

    edit() {
        new FormValidator('wsForm', {
            liveValidation: true,
            showIcons: true,
            autoSubmit: true,
            onSubmit(form, e, data) {}
        });

        // 获取已有权限ID并初始化树
        var existingIds = ($('#perm-ids-input').val() || '').split(',').filter(Boolean).map(Number);
        initPermissionTree(existingIds);
    }
};

// =========== 角色列表加载 ===========
function loadRoleList() {
    $.ajax({
        url: '/admin/role/getList',
        data: { page: 1, limit: 200 },
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                state.roles = res.data.list || [];
                renderRoleList(state.roles);
            }
        },
        error() {
            layer.toast({content: '加载角色列表失败', type: 'danger'});
        }
    });
}

function renderRoleList(roles, filter) {
    var $list = $('#role-list');
    if (!roles || roles.length === 0) {
        $list.html('<div class="text-center text-secondary py-4"><small>暂无角色</small></div>');
        return;
    }

    var keyword = (filter || '').toLowerCase();
    var html = '';
    roles.forEach(function (role) {
        if (keyword && role.name.toLowerCase().indexOf(keyword) === -1
            && (role.ident || '').toLowerCase().indexOf(keyword) === -1) {
            return;
        }
        var isActive = state.currentRoleId === role.id;
        var iconBg = role.ident === 'super_admin' || role.ident === 'admin'
            ? 'bg-danger' : 'bg-primary';
        var levelPadding = (role.level || 0) * 16;
        html += '<div class="role-item' + (isActive ? ' active' : '') + '" '
            + 'data-role-id="' + role.id + '" '
            + 'style="padding-left:' + (16 + levelPadding) + 'px">'
            + '<div class="role-icon ' + iconBg + ' text-white">'
            + '<i class="bi ' + (role.ident === 'super_admin' || role.ident === 'admin' ? 'bi-shield-lock' : 'bi-person') + '"></i>'
            + '</div>'
            + '<div class="role-info">'
            + '<div class="role-name text-truncate">' + escHtml(role.name) + '</div>'
            + '<div class="role-meta">'
            + '<span>' + escHtml(role.ident || '') + '</span>'
            + '<span class="role-badge bg-secondary text-white">' + (role.admin_count || 0) + '人</span>'
            + '</div>'
            + '</div>'
            + '<i class="bi bi-chevron-right text-secondary opacity-50" style="font-size:11px;"></i>'
            + '</div>';
    });

    if (!html) {
        $list.html('<div class="text-center text-secondary py-4"><small>无匹配角色</small></div>');
    } else {
        $list.html(html);
    }
}

// =========== 角色选中 ===========
function selectRole(roleId) {
    state.currentRoleId = roleId;

    // 更新左侧高亮
    $('.role-item').removeClass('active');
    $('.role-item[data-role-id="' + roleId + '"]').addClass('active');

    // 加载矩阵
    loadPermissionMatrix(roleId);

    // 加载角色详情
    loadRoleDetail(roleId);

    // 更新头部
    updateCurrentHeader(roleId);

    // 显示详情面板
    $('#role-detail-panel').addClass('show');

    // 继承链处于「选中」状态时，切换角色自动刷新，无需再点按钮
    if (state.showInheritChain) {
        loadInheritanceChain(roleId);
    } else {
        $('#inherit-chain-display').empty();
    }
}

/** 同步「查看继承链」按钮的选中态与提示文案 */
function syncInheritChainButton() {
    var $btn = $('#btn-inherit-chain');

    $btn.toggleClass('active', state.showInheritChain)
        .attr('aria-pressed', state.showInheritChain ? 'true' : 'false')
        .attr('title', state.showInheritChain ? '隐藏继承链' : '查看继承链');
}

function updateCurrentHeader(roleId) {
    var role = findRoleById(roleId);
    if (role) {
        state.currentRole = role;
        $('#role-current-name').text(role.name);
        $('#role-current-ident').text('(' + (role.ident || '') + ')');

        if (role.parent_id > 0) {
            var parent = findRoleById(role.parent_id);
            if (parent) {
                $('#role-current-inherit').removeClass('d-none').text('继承自: ' + parent.name);
            } else {
                $('#role-current-inherit').addClass('d-none');
            }
        } else {
            $('#role-current-inherit').addClass('d-none');
        }

        $('#role-current-header').addClass('show');
        $('#matrix-empty').hide();
        $('#matrix-table-wrapper').show();
    }
}

function findRoleById(id) {
    for (var i = 0; i < state.roles.length; i++) {
        if (state.roles[i].id === id) return state.roles[i];
    }
    return null;
}

// =========== 权限矩阵加载 ===========
function loadPermissionMatrix(roleId) {
    var filter = $('#perm-type-filter').val() || '';
    var keyword = $('#perm-search').val() || '';

    $.ajax({
        url: '/admin/role/getPermissionMatrix',
        data: { role_id: roleId, group: filter, keyword: keyword },
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                state.matrixData = res.data;
                state.rolePermissions = res.data.rolePermissions || {};
                state.permissionTree = res.data.permissions || [];
                renderMatrix(state.matrixData);
            }
        },
        error() {
            layer.toast({content: '加载权限矩阵失败', type: 'danger'});
        }
    });
}

function renderMatrix(data) {
    if (!data || !data.permissions || data.permissions.length === 0) {
        $('#matrix-table-wrapper').hide();
        $('#matrix-empty').show().html(
            '<i class="bi bi-inbox"></i><span>暂无权限数据</span>'
        );
        return;
    }

    var roles = data.roles || [];
    var permissions = data.permissions;
    var rolePerms = data.rolePermissions || {};
    var inheritedFrom = data.inheritedFrom || {};

    // 构建 thead
    var theadHtml = '<tr>';
    theadHtml += '<th class="perm-col">权限项</th>';
    roles.forEach(function (role) {
        var isTarget = state.currentRoleId === role.id;
        theadHtml += '<th style="' + (isTarget ? 'background:rgba(var(--bs-primary-rgb),0.08);' : '') + '">'
            + '<div style="font-size:12px;">' + escHtml(role.name) + '</div>'
            + '<div style="font-size:10px;color:var(--bs-secondary-color);">' + escHtml(role.ident) + '</div>'
            + '</th>';
    });
    theadHtml += '</tr>';
    $('#perm-matrix-table thead').html(theadHtml);

    // 构建 tbody
    var tbodyHtml = '';
    var currentPerms = state.currentRoleId ? (rolePerms[state.currentRoleId] || {direct:[], all:[]}) : {direct:[], all:[]};

    // 遍历权限树
    function walkPermTree(nodes, level) {
        nodes.forEach(function (node) {
            var hasChildren = node.childlist && node.childlist.length > 0;
            var groupId = 'perm-group-' + node.id;

            if (hasChildren) {
                // 分组行
                var isExpanded = state.expandedGroups[groupId] !== false;
                tbodyHtml += '<tr class="matrix-group-row" data-group-id="' + groupId + '">';
                tbodyHtml += '<td class="perm-col">';
                tbodyHtml += '<span class="group-toggle" data-group="' + groupId + '">'
                    + '<i class="bi ' + (isExpanded ? 'bi-chevron-down' : 'bi-chevron-right') + '"></i>'
                    + '</span>';
                tbodyHtml += '<strong>' + escHtml(node.name) + '</strong>';
                tbodyHtml += '<span class="text-secondary" style="font-size:11px;margin-left:6px;">(' + (node.childlist.length) + '项)</span>';
                tbodyHtml += '<span class="group-header-actions">';
                if (state.currentRoleId) {
                    tbodyHtml += '<button class="btn btn-xs btn-outline-success w-100 group-action" data-group-ids="' + getChildrenIds(node) + '" data-action="grant" title="全选此分组">全选</button>';
                    tbodyHtml += '<button class="btn btn-xs btn-outline-danger w-100 group-action" data-group-ids="' + getChildrenIds(node) + '" data-action="revoke" title="取消此分组">取消</button>';
                }
                tbodyHtml += '</span>';
                tbodyHtml += '</td>';
                // 每个角色的单元格（分组行可全选/取消）
                roles.forEach(function (role) {
                    tbodyHtml += '<td style="background:var(--bs-tertiary-bg);"><div class="matrix-cell" style="display:flex;align-items:center;justify-content:center;">'
                        + (state.currentRoleId === role.id
                            ? '<button class="btn btn-xs btn-link p-0 w-100 group-action" data-group-ids="' + getChildrenIds(node) + '" data-action="grant" title="全选"><i class="bi bi-check-all"></i></button>'
                            : '')
                        + '</div></td>';
                });
                tbodyHtml += '</tr>';

                if (isExpanded) {
                    walkPermTree(node.childlist, level + 1);
                }
            } else {
                // 权限行
                tbodyHtml += '<tr class="perm-row" data-perm-id="' + node.id + '" data-perm-type="' + (node.type || '') + '">';
                tbodyHtml += '<td class="perm-col tree-indent-' + Math.min(level, 4) + '">';
                tbodyHtml += '<span>' + escHtml(node.name) + '</span>';
                var typeLabel = node.type === 'menu' ? '菜单' : node.type === 'api' ? 'API' : '';
                var typeColor = node.type === 'menu' ? 'bg-info' : node.type === 'api' ? 'bg-warning text-dark' : '';
                if (typeLabel) {
                    tbodyHtml += '<span class="perm-type-badge ' + typeColor + '">' + typeLabel + '</span>';
                }
                tbodyHtml += '<div style="font-size:10px;color:var(--bs-secondary-color);">' + escHtml(node.ident || '') + '</div>';
                tbodyHtml += '</td>';

                // 每个角色列
                roles.forEach(function (role) {
                    var rp = rolePerms[role.id] || {direct:[], all:[]};
                    var isDirect = rp.direct.indexOf(node.id) >= 0;
                    var isInherited = !isDirect && rp.all.indexOf(node.id) >= 0;
                    var isFrom = '';
                    if (inheritedFrom[node.id] && role.id === state.currentRoleId) {
                        isFrom = ' title="继承自: ' + escHtml(inheritedFrom[node.id]) + '"';
                    }
                    var cellClass = 'perm-none';
                    var icon = '';
                    if (isDirect) {
                        cellClass = 'perm-direct';
                        icon = '<i class="bi bi-check-circle-fill cell-icon"></i>';
                    } else if (isInherited && (role.is_inherit || rp.is_inherit)) {
                        cellClass = 'perm-inherited';
                        icon = '<i class="bi bi-check-circle cell-icon i-inherited"></i>';
                    } else if (isInherited) {
                        cellClass = 'perm-direct';
                        icon = '<i class="bi bi-check-circle-fill cell-icon"></i>';
                    }
                    var isTarget = state.currentRoleId === role.id;
                    var clickable = isTarget ? ' data-clickable="true"' : '';
                    tbodyHtml += '<td style="' + (isTarget ? 'background:rgba(var(--bs-primary-rgb),0.02);' : '') + '">';
                    tbodyHtml += '<div class="matrix-cell ' + cellClass + '" '
                        + 'data-role-id="' + role.id + '" data-perm-id="' + node.id + '" '
                        + isFrom + clickable + '>'
                        + icon
                        + '</div>';
                    tbodyHtml += '</td>';
                });

                tbodyHtml += '</tr>';
            }
        });
    }

    walkPermTree(permissions, 0);
    $('#perm-matrix-table tbody').html(tbodyHtml);

    // 更新统计
    if (state.currentRoleId && currentPerms) {
        $('#stat-direct').text(currentPerms.direct.length);
        $('#stat-inherited').text(currentPerms.all.length - currentPerms.direct.length);
    }
}

function getChildrenIds(node) {
    var ids = [];
    function collect(n) {
        if (n.childlist && n.childlist.length > 0) {
            n.childlist.forEach(function(c) { collect(c); });
        } else {
            ids.push(n.id);
        }
    }
    collect(node);
    return ids.join(',');
}

// =========== 角色详情 ===========
function loadRoleDetail(roleId) {
    $.ajax({
        url: '/admin/role/getDetail',
        data: { role_id: roleId },
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                var d = res.data;
                $('#stat-direct').text(d.direct_count || 0);
                $('#stat-inherited').text(d.inherited_count || 0);
                $('#stat-users').text(d.user_count || 0);

                renderConflictDisplay(d.conflicts);
                renderMemberList(d.members);
            }
        }
    });
}

function renderConflictDisplay(conflicts) {
    var $box = $('#conflict-display');
    if (!conflicts || conflicts.length === 0) {
        $box.empty();
        return;
    }
    var names = conflicts.map(function(c) { return c.name; }).join(', ');
    $box.html('<span class="badge bg-warning text-dark">互斥: ' + escHtml(names) + '</span>');
}

function renderMemberList(members) {
    var $box = $('#member-list-container');
    if (!members || members.length === 0) {
        $box.html('<div class="member-empty">暂无成员</div>');
        return;
    }
    var html = '<small class="text-secondary d-block mb-1">成员列表（' + members.length + '人）</small><div class="member-list">';
    members.forEach(function (m) {
        var avatarHtml = m.avatar
            ? '<img class="avatar" src="' + escHtml(m.avatar) + '" alt="">'
            : '<span class="avatar"><i class="bi bi-person"></i></span>';
        var statusMark = m.status == 1 ? '' : ' <small class="text-secondary">(已禁用)</small>';
        html += '<span class="member-chip" title="' + escHtml(m.username || '') + '">'
            + avatarHtml
            + '<span>' + escHtml(m.nickname || m.username || '') + statusMark + '</span>'
            + '</span>';
    });
    html += '</div>';
    $box.html(html);
}

// =========== 事件绑定 ===========
function bindEvents() {
    // 搜索角色
    var searchTimer;
    $('#role-search').on('input', function () {
        clearTimeout(searchTimer);
        var keyword = $(this).val();
        searchTimer = setTimeout(function () {
            renderRoleList(state.roles, keyword);
        }, 200);
    });

    // 点击角色行选中
    $(document).on('click', '.role-item', function () {
        var roleId = parseInt($(this).data('role-id'));
        if (roleId) {
            selectRole(roleId);
        }
    });

    // 清除角色选择
    $('#btn-clear-role').on('click', function () {
        clearRoleSelection();
    });

    // 权限单元格点击 - 仅当前选中角色列可点击
    $(document).on('click', '.matrix-cell[data-clickable="true"]', function () {
        var roleId = parseInt($(this).data('role-id'));
        var permId = parseInt($(this).data('perm-id'));
        var $cell = $(this);

        if (roleId !== state.currentRoleId) return;

        togglePermission(roleId, permId, $cell);
    });

    // 分组展开/折叠
    $(document).on('click', '.group-toggle', function () {
        var groupId = $(this).data('group');
        if (state.expandedGroups[groupId] === false) {
            state.expandedGroups[groupId] = true;
        } else {
            state.expandedGroups[groupId] = false;
        }
        renderMatrix(state.matrixData);
    });

    // 展开/折叠全部
    $('#btn-expand-all').on('click', function () {
        state.expandedGroups = {};
        renderMatrix(state.matrixData);
    });

    $('#btn-collapse-all').on('click', function () {
        // 收集所有分组ID
        $('.matrix-group-row').each(function () {
            var gid = $(this).data('group-id');
            if (gid) state.expandedGroups[gid] = false;
        });
        renderMatrix(state.matrixData);
    });

    // 分组操作按钮 - 全选/取消
    $(document).on('click', '.group-action', function (e) {
        e.stopPropagation();
        var ids = ($(this).data('group-ids') || '').toString().split(',').filter(Boolean).map(Number);
        var action = $(this).data('action');
        if (ids.length > 0 && state.currentRoleId) {
            batchAssign(state.currentRoleId, ids, action);
        }
    });

    // 搜索权限
    var permSearchTimer;
    $('#perm-search').on('input', function () {
        clearTimeout(permSearchTimer);
        permSearchTimer = setTimeout(function () {
            if (state.currentRoleId) {
                loadPermissionMatrix(state.currentRoleId);
            }
        }, 300);
    });

    // 权限类型筛选
    $('#perm-type-filter').on('change', function () {
        if (state.currentRoleId) {
            loadPermissionMatrix(state.currentRoleId);
        }
    });

    // 编辑角色按钮
    $('#btn-edit-role').on('click', function () {
        if (state.currentRoleId) {
            var url = '/admin/role/edit?id=' + state.currentRoleId;
            layer.open({
                type: 2,
                title: '编辑角色',
                content: url,
                area: ['700px', '600px'],
                btn: ['保存', '取消'],
                yes: function (index, layero) {
                    submitRoleForm(index);
                    return false;  // 阻止默认关闭
                },
                end() {
                    loadRoleList();
                    if (state.currentRoleId) {
                        loadPermissionMatrix(state.currentRoleId);
                    }
                }
            });
        }
    });

    // 继承链按钮：切换选中态；选中时展示并随角色切换自动刷新
    $('#btn-inherit-chain').on('click', function () {
        if (!state.currentRoleId) {
            layer.toast({content: '请先选择角色', type: 'warning'});
            return;
        }

        state.showInheritChain = !state.showInheritChain;
        syncInheritChainButton();

        if (state.showInheritChain) {
            loadInheritanceChain(state.currentRoleId);
        } else {
            $('#inherit-chain-display').empty();
        }
    });

    // 删除角色按钮
    $('#btn-delete-role').on('click', function () {
        if (state.currentRoleId) {
            deleteRole(state.currentRoleId);
        }
    });

    // 收起详情面板
    $('#btn-detail-close').on('click', function () {
        $('#role-detail-panel').removeClass('show');
        $('#inherit-chain-display').empty();
    });

    // 新建角色
    $('#btn-add-role').on('click', function () {
        layer.open({
            type: 2,
            title: '新建角色',
            content: '/admin/role/add',
            area: ['700px', '600px'],
            btn: ['保存', '取消'],
            yes: function (index, layero) {
                submitRoleForm(index);
                return false;  // 阻止默认关闭
            },
            end() {
                loadRoleList();
            }
        });
    });
}

// ============ 表单提交 ============
// 通过 AJAX 提交 iframe 中的表单，成功后关闭弹窗并刷新列表
function submitRoleForm(layerIndex) {
    // role 页面通常运行在标签页 iframe 中，而 layer 实例在父窗口（window.parent.Layer）：
    // 此时在本文档 document.getElementById 查不到弹层的 iframe（它在父文档里），
    // 旧实现会走到「找不到就 close」，表现为点保存后弹窗消失但数据没保存、也没有提示。
    // getChildFrame 由 layer 在它自己的窗口内查询，两种运行形态都适用。
    var $form = (typeof layer.getChildFrame === 'function')
        ? layer.getChildFrame('#wsForm', layerIndex)
        : $();

    if (!$form || !$form.length) {
        // 兜底：layer 与页面同窗口（直接访问子页面）时按 id 查找
        var iframe = document.getElementById('ws-layer-iframe-' + layerIndex);
        if (iframe) {
            var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            var form = iframeDoc.querySelector('#wsForm');
            if (form) {
                $form = $(form);
            }
        }
    }

    if (!$form || !$form.length) {
        layer.toast({content: '未找到表单，请刷新页面后重试', type: 'danger', time: 3000});
        return;
    }

    var formData = $form.serialize();
    var action = $form.attr('action') || $form.prop('action');
    $.ajax({
        url: action,
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function (res) {
            if (res.code === OK_CODE) {
                layer.toast({content: res.msg || '保存成功', type: 'success', time: 2000});
                layer.close(layerIndex);
            } else {
                layer.toast({content: res.msg || '保存失败', type: 'danger', time: 3000});
            }
        },
        error: function (xhr) {
            var msg = '网络错误，请重试';
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.msg) msg = r.msg;
            } catch (e) {}
            layer.toast({content: msg, type: 'danger', time: 3000});
        }
    });
}

// =========== 权限切换 ===========
function togglePermission(roleId, permId, $cell) {
    var currentState = $cell.hasClass('perm-direct') ? 'granted' : 'none';

    // 乐观更新 UI
    if (currentState === 'granted') {
        $cell.removeClass('perm-direct').addClass('perm-none').html('');
    } else {
        $cell.removeClass('perm-none perm-inherited').addClass('perm-direct')
            .html('<i class="bi bi-check-circle-fill cell-icon"></i>');
    }

    $.ajax({
        url: '/admin/role/togglePermission',
        type: 'POST',
        data: { role_id: roleId, permission_id: permId },
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                layer.toast({content: res.data.status === 'granted' ? '已分配' : '已撤销', type: 'success', time: 1000});
                // 刷新内部数据
                loadPermissionMatrix(roleId);
                // 更新左侧详情
                loadRoleDetail(roleId);
            } else {
                // 回滚 UI
                if (currentState === 'granted') {
                    $cell.addClass('perm-direct').removeClass('perm-none')
                        .html('<i class="bi bi-check-circle-fill cell-icon"></i>');
                } else {
                    $cell.removeClass('perm-direct').addClass('perm-none').html('');
                }
                layer.toast({content: res.msg || '操作失败', type: 'danger'});
            }
        },
        error() {
            // 回滚
            if (currentState === 'granted') {
                $cell.addClass('perm-direct').removeClass('perm-none')
                    .html('<i class="bi bi-check-circle-fill cell-icon"></i>');
            } else {
                $cell.removeClass('perm-direct').addClass('perm-none').html('');
            }
            layer.toast({content: '网络错误', type: 'danger'});
        }
    });
}

// =========== 批量分配 ===========
function batchAssign(roleId, permIds, action) {
    $.ajax({
        url: '/admin/role/batchAssign',
        type: 'POST',
        data: { role_id: roleId, permission_ids: permIds, action: action === 'grant' ? 'assign' : 'revoke' },
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                layer.toast({content: action === 'grant' ? '已批量分配' : '已批量撤销', type: 'success', time: 1000});
                loadPermissionMatrix(roleId);
                loadRoleDetail(roleId);
            } else {
                layer.toast({content: res.msg || '操作失败', type: 'danger'});
            }
        },
        error() {
            layer.toast({content: '网络错误', type: 'danger'});
        }
    });
}

// =========== 继承链 ===========
function loadInheritanceChain(roleId) {
    $.ajax({
        url: '/admin/role/getInheritanceChain',
        data: { role_id: roleId },
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                var chain = res.data.chain || [];
                var html = '<div class="mt-2">';
                html += '<small class="text-secondary">权限继承链：</small>';
                html += '<div class="d-flex align-items-center gap-1 flex-wrap mt-1">';
                for (var i = chain.length - 1; i >= 0; i--) {
                    var item = chain[i];
                    var isLast = i === 0;
                    html += '<span class="badge ' + (isLast ? 'bg-primary' : 'bg-secondary') + '">'
                        + escHtml(item.name) + ' (' + item.perm_count + '项)'
                        + '</span>';
                    if (i > 0) {
                        html += '<i class="bi bi-arrow-right text-secondary" style="font-size:10px;"></i>';
                    }
                }
                html += '</div></div>';
                $('#inherit-chain-display').html(html);
                $('#role-detail-panel').addClass('show');
            }
        }
    });
}

// =========== 清除角色选择 ===========
function clearRoleSelection() {
    state.currentRoleId = null;
    state.currentRole = null;
    $('#role-current-header').removeClass('show');
    $('#role-detail-panel').removeClass('show');
    $('#matrix-table-wrapper').hide();
    $('#matrix-empty').show().html(
        '<i class="bi bi-hand-index-thumb"></i><span>请从左侧选择一个角色开始配置权限</span>'
    );
    $('#conflict-display').empty();
    $('#member-list-container').empty();
    $('#inherit-chain-display').empty();
    $('.role-item').removeClass('active');
}

// =========== 删除角色 ===========
function deleteRole(roleId) {
    var role = findRoleById(roleId);
    var name = role ? role.name : '';
    var count = role ? (role.admin_count || 0) : 0;

    var msg = name ? ('确定要删除角色「' + name + '」吗？') : '确定要删除该角色吗？';
    if (count > 0) {
        msg += '（该角色下还有 ' + count + ' 名成员，删除后这些成员将失去该角色）';
    }

    function doDelete() {
        $.ajax({
            url: '/admin/role/delete',
            type: 'POST',
            data: { id: roleId },
            dataType: 'json',
            success(res) {
                if (res.code === OK_CODE) {
                    layer.toast({content: '删除成功', type: 'success', time: 1500});
                    clearRoleSelection();
                    loadRoleList();
                } else {
                    layer.toast({content: res.msg || '删除失败', type: 'danger', time: 3000});
                }
            },
            error() {
                layer.toast({content: '网络错误', type: 'danger'});
            }
        });
    }

    if (layer.confirm) {
        layer.confirm(msg, { icon: 'danger', title: '删除角色', btn: ['删除', '取消'] }, function (isConfirm) {
            if (isConfirm) doDelete();
        });
    } else {
        if (window.confirm(msg)) doDelete();
    }
}

// =========== 工具函数 ===========
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// =========== 权限树面板（编辑/添加页） ===========
var permTreeState = {
    selectedIds: [],
    treeData: [],
    expanded: {}
};

function initPermissionTree(existingIds) {
    permTreeState.selectedIds = existingIds || [];

    $.ajax({
        url: '/admin/permission/getList?type=treearray',
        dataType: 'json',
        success(res) {
            if (res.code === OK_CODE) {
                permTreeState.treeData = res.data.list || [];
                // 清掉「加载中...」占位，避免它与树内容叠加
                $('#perm-tree-container').empty();
                renderPermTree(permTreeState.treeData);
                updatePermStats();
            }
        }
    });

    bindPermTreeEvents();
    initRbac2Constraints();
}

function renderPermTree(nodes, container, level) {
    container = container || $('#perm-tree-container');
    level = level || 0;

    if (!container.length) return;
    var keyword = ($('#perm-tree-search').val() || '').toLowerCase();

    if (!nodes || nodes.length === 0) return;

    nodes.forEach(function (node) {
        var hasChildren = node.childlist && node.childlist.length > 0;
        var nodeId = 'perm-node-' + node.id;
        var isChecked = permTreeState.selectedIds.indexOf(node.id) >= 0;
        var isExpanded = permTreeState.expanded[nodeId] !== false;
        // 虚拟节点 = 菜单目录（dir）分组：仅用于还原菜单层级，不可勾选、不参与授权
        var isVirtual = !!node.virtual;

        var matchKeyword = !keyword || (node.name || '').toLowerCase().indexOf(keyword) >= 0
            || (node.ident || '').toLowerCase().indexOf(keyword) >= 0;
        var childrenMatch = false;
        if (!matchKeyword && hasChildren) {
            childrenMatch = hasMatchingChildren(node.childlist, keyword);
        }

        if (keyword && !matchKeyword && !childrenMatch) return;

        var itemHtml = '';
        itemHtml += '<div class="perm-tree-item' + (hasChildren ? ' has-children' : '')
            + (isVirtual ? ' perm-virtual' : '') + '"'
            + ' data-node-id="' + nodeId + '" data-perm-id="' + node.id + '"'
            + ' style="padding-left:' + (12 + level * 20) + 'px">';

        if (hasChildren) {
            itemHtml += '<span class="tree-toggle" data-node="' + nodeId + '">'
                + '<i class="bi ' + (isExpanded ? 'bi-chevron-down' : 'bi-chevron-right') + '"></i>'
                + '</span>';
        } else {
            itemHtml += '<span class="tree-toggle" style="visibility:hidden;">'
                + '<i class="bi bi-dot"></i>'
                + '</span>';
        }

        if (isVirtual) {
            itemHtml += '<span class="perm-label perm-group-label">'
                + escHtml(node.name)
                + '<span class="badge bg-secondary" style="font-size:10px;margin-left:6px;">分组</span>'
                + '</span>';
        } else {
            itemHtml += '<input class="form-check-input" type="checkbox" value="' + node.id + '"'
                + (isChecked ? ' checked' : '')
                + ' id="cb-' + nodeId + '">';

            itemHtml += '<label class="perm-label" for="cb-' + nodeId + '">';
            itemHtml += escHtml(node.name || node.ident);
            var typeBadge = '';
            if (node.type === 'menu') typeBadge = '<span class="badge bg-info text-white" style="font-size:10px;margin-left:4px;">菜单</span>';
            else if (node.type === 'api') typeBadge = '<span class="badge bg-warning text-dark" style="font-size:10px;margin-left:4px;">API</span>';
            itemHtml += typeBadge;
            itemHtml += '</label>';
        }

        itemHtml += '</div>';

        container.append(itemHtml);

        if (hasChildren) {
            var childContainer = $('<div class="perm-tree-children' + (isExpanded ? ' show' : '') + '"'
                + ' data-parent="' + nodeId + '"></div>');
            container.append(childContainer);
            renderPermTree(node.childlist, childContainer, level + 1);
        }
    });
}

function hasMatchingChildren(nodes, keyword) {
    if (!nodes) return false;
    for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        if ((n.name || '').toLowerCase().indexOf(keyword) >= 0
            || (n.ident || '').toLowerCase().indexOf(keyword) >= 0) {
            return true;
        }
        if (n.childlist && hasMatchingChildren(n.childlist, keyword)) return true;
    }
    return false;
}

function updatePermStats() {
    var totalAll = 0;
    function countLeaves(nodes) {
        if (!nodes) return;
        nodes.forEach(function (n) {
            if (n.childlist && n.childlist.length > 0) {
                countLeaves(n.childlist);
            } else {
                totalAll++;
            }
        });
    }
    countLeaves(permTreeState.treeData);

    var permCount = $('#perm-tree-container input[type="checkbox"]').length;
    var selectedCount = permTreeState.selectedIds.length;

    $('#perm-total').text(totalAll || permCount);
    $('#perm-selected').text(selectedCount);
    $('#s-direct').text(selectedCount);
    $('#s-unselected').text(Math.max(0, (totalAll || permCount) - selectedCount));
    $('#s-inherited').text('0');
    $('#perm-ids-input').val(permTreeState.selectedIds.join(','));
}

function bindPermTreeEvents() {
    $('#perm-tree-search').off('input.pt').on('input.pt', function () {
        $('#perm-tree-container').empty();
        renderPermTree(permTreeState.treeData);
    });

    $(document).off('click.ptToggle', '.tree-toggle').on('click.ptToggle', '.tree-toggle', function () {
        var nodeId = $(this).data('node');
        var $children = $('.perm-tree-children[data-parent="' + nodeId + '"]');
        $children.toggleClass('show');
        permTreeState.expanded[nodeId] = $children.hasClass('show');
        $(this).find('i').toggleClass('bi-chevron-right bi-chevron-down');
    });

    $(document).off('change.ptCheck', '#perm-tree-container .form-check-input')
        .on('change.ptCheck', '#perm-tree-container .form-check-input', function () {
            var permId = parseInt($(this).val());
            if ($(this).is(':checked')) {
                if (permTreeState.selectedIds.indexOf(permId) === -1) {
                    permTreeState.selectedIds.push(permId);
                }
            } else {
                permTreeState.selectedIds = permTreeState.selectedIds.filter(function (id) {
                    return id !== permId;
                });
            }
            updatePermStats();
        });

    $('#btn-select-all').off('click.pt').on('click.pt', function () {
        var allCheckboxes = $('#perm-tree-container input[type="checkbox"]');
        permTreeState.selectedIds = [];
        allCheckboxes.each(function () {
            $(this).prop('checked', true);
            permTreeState.selectedIds.push(parseInt($(this).val()));
        });
        updatePermStats();
    });

    $('#btn-deselect-all').off('click.pt').on('click.pt', function () {
        $('#perm-tree-container input[type="checkbox"]').prop('checked', false);
        permTreeState.selectedIds = [];
        updatePermStats();
    });

    $('#btn-expand-tree').off('click.pt').on('click.pt', function () {
        $('.perm-tree-children').addClass('show');
        $('.tree-toggle i').removeClass('bi-chevron-right').addClass('bi-chevron-down');
    });

    $('#btn-collapse-tree').off('click.pt').on('click.pt', function () {
        $('.perm-tree-children').removeClass('show');
        $('.tree-toggle i').removeClass('bi-chevron-down').addClass('bi-chevron-right');
        permTreeState.expanded = {};
    });

    $('#btn-copy-perms').off('click.pt').on('click.pt', function () {
        $.ajax({
            url: '/admin/role/getAllRoles',
            dataType: 'json',
            success(res) {
                if (res.code === OK_CODE) {
                    var roles = res.data || [];
                    var selectHtml = '<option value="">选择源角色...</option>';
                    var currentRoleId = $('input[name="id"]').val();
                    roles.forEach(function (r) {
                        if (r.id != currentRoleId) {
                            selectHtml += '<option value="' + r.id + '">' + r.name + '</option>';
                        }
                    });
                    $('#copy-from-role').html(selectHtml);
                    new bootstrap.Modal('#copyPermsModal').show();
                }
            }
        });
    });

    $('#btn-confirm-copy').off('click.pt').on('click.pt', function () {
        var sourceRoleId = $('#copy-from-role').val();
        if (!sourceRoleId) return;
        $.ajax({
            url: '/admin/role/getPermissionMatrix',
            data: { role_id: sourceRoleId },
            dataType: 'json',
            success(res) {
                if (res.code === OK_CODE) {
                    var rp = res.data.rolePermissions[sourceRoleId] || {};
                    var directIds = rp.direct || [];
                    permTreeState.selectedIds = directIds.slice();
                    $('#perm-tree-container input[type="checkbox"]').each(function () {
                        var pid = parseInt($(this).val());
                        $(this).prop('checked', permTreeState.selectedIds.indexOf(pid) >= 0);
                    });
                    updatePermStats();
                    bootstrap.Modal.getInstance('#copyPermsModal').hide();
                }
            }
        });
    });
}

function initRbac2Constraints() {
    $('#btn-toggle-constraints').off('click.rb2').on('click.rb2', function () {
        var $body = $('#rbac2-body');
        $body.slideToggle(200);
        $(this).find('i').toggleClass('bi-chevron-down bi-chevron-up');
    });

    var currentRoleId = $('input[name="id"]').val();
    var mutexSlugs = [];
    var slugNameMap = {};

    // 预填充已保存的互斥角色（隐藏域存逗号分隔 slug）
    ($('#mutex-role-ids').val() || '').split(',').forEach(function (slug) {
        slug = slug.trim();
        if (slug && mutexSlugs.indexOf(slug) === -1) {
            mutexSlugs.push(slug);
        }
    });

    function renderBadges() {
        var $list = $('#mutex-role-list');
        $list.empty();
        mutexSlugs.forEach(function (slug) {
            var name = slugNameMap[slug] || slug;
            $list.append(
                '<span class="badge bg-danger constraint-mutex-badge" data-role-slug="' + escHtml(slug) + '">'
                + escHtml(name) + '<i class="bi bi-x" style="cursor:pointer;margin-left:4px;"></i></span>'
            );
        });
    }

    $.ajax({
        url: '/admin/role/getAllRoles',
        dataType: 'json',
        success(res) {
            if (res.code !== OK_CODE) return;
            var roles = res.data || [];
            var options = '';
            roles.forEach(function (r) {
                slugNameMap[r.slug] = r.name;
                if (r.id != currentRoleId && mutexSlugs.indexOf(r.slug) === -1) {
                    options += '<option value="' + escHtml(r.slug) + '">' + escHtml(r.name) + '</option>';
                }
            });
            $('#mutex-role-select').html('<option value="">选择互斥角色...</option>' + options);
            renderBadges();
        }
    });

    $('#btn-add-mutex').off('click.rb2').on('click.rb2', function () {
        var slug = $('#mutex-role-select').val();
        if (!slug || mutexSlugs.indexOf(slug) >= 0) return;
        mutexSlugs.push(slug);
        $('#mutex-role-ids').val(mutexSlugs.join(','));
        renderBadges();
        $('#mutex-role-select option[value="' + slug + '"]').remove();
        $('#mutex-role-select').val('');
    });

    $(document).off('click.rmMutex', '.constraint-mutex-badge .bi-x')
        .on('click.rmMutex', '.constraint-mutex-badge .bi-x', function () {
            var $badge = $(this).closest('.constraint-mutex-badge');
            var slug = $badge.data('role-slug');
            mutexSlugs = mutexSlugs.filter(function (s) { return s !== slug; });
            $('#mutex-role-ids').val(mutexSlugs.join(','));
            $badge.remove();
            // 回填下拉选项
            var name = slugNameMap[slug] || slug;
            $('#mutex-role-select').append('<option value="' + escHtml(slug) + '">' + escHtml(name) + '</option>');
        });
}

export default Controller;
