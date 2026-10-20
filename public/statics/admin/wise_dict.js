/**
 * wise_dict.js — 数据字典管理
 */
const $ = window.jQuery;
const layer = window.parent.Layer || window.Layer;

'use strict';

var module  = window.__WS_CONFIG__ || {};
var baseUrl = '/' + module.modulename + '/' + module.controller;

var currentDictCode   = '';
var currentItemPage   = 1;
var currentItemLimit  = 15;
var currentItemTotal  = 0;
var selectedItemIds   = [];

function toast(msg, type) {
    layer.toast({ content: msg, type: type || 'success' });
}

function post(url, data, cb) {
    Wise.util.http.post(url, data, { interceptor: false }).then(function (res) {
        if (res.code === 0) cb(res); else toast(res.msg || '操作失败', 'danger');
    }).catch(() => {});
}

function get(url, params, cb) {
    $.ajax({ url: url, data: params || {}, dataType: 'json', success(res) {
        if (res.code === 0) cb(res); else toast(res.msg || '请求失败', 'danger');
    }});
}

function htmlEncode(s) {
    return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : '';
}

// ==================== 字典类型列表 ====================
function loadDictTypes(keyword) {
    var params = { page: 1, limit: 200 };
    if (keyword) params.name = keyword;

    get(baseUrl + '/list', params, function (res) {
        var list = res.data.list || [];
        var $container = $('#dictTypeList');

        // 获取各项数量统计
        get(baseUrl + '/typeCounts', {}, function (cr) {
            var counts = cr.data || {};
            renderTypeList(list, counts, $container, keyword);
        });
    });
}

function renderTypeList(list, counts, $container, keyword) {
    if (list.length === 0) {
        $container.html(
            '<div class="text-center py-4 text-secondary small">' +
            '<i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem"></i>' +
            (keyword ? '未找到匹配的字典类型' : '暂无字典类型') +
            '</div>'
        );
        return;
    }
    var html = '';
    list.forEach(function (dt) {
        var active = dt.dict_code === currentDictCode ? ' active' : '';
        var treeIcon = dt.is_tree == 1 ? ' <span class="tree-icon"><i class="bi bi-diagram-3-fill"></i></span>' : '';
        var countBadge = (counts[dt.dict_code] > 0) ? (' <span class="item-count">' + counts[dt.dict_code] + '</span>') : '';
        var statusDot = dt.status == 1 ? '<span class="text-success">&#9679;</span> ' : '<span class="text-secondary">&#9679;</span> ';
        html += '<a href="#" class="list-group-item list-group-item-action' + active + '" data-code="' + htmlEncode(dt.dict_code) + '">' +
            '<span>' + statusDot + htmlEncode(dt.name) + treeIcon + countBadge + '</span>' +
            '<span class="d-flex align-items-center gap-2">' +
                '<small class="text-secondary">' + htmlEncode(dt.dict_code) + '</small>' +
                '<span class="type-actions">' +
                    '<button class="btn-type-toggle" data-code="' + htmlEncode(dt.dict_code) + '" data-status="' + dt.status + '" title="' + (dt.status == 1 ? '禁用' : '启用') + '">' +
                        '<i class="bi ' + (dt.status == 1 ? 'bi-pause-circle' : 'bi-play-circle') + '"></i></button>' +
                    '<button class="btn-type-delete" data-code="' + htmlEncode(dt.dict_code) + '" data-name="' + htmlEncode(dt.name) + '" title="删除"><i class="bi bi-trash"></i></button>' +
                '</span>' +
            '</span></a>';
    });
    $container.html(html);
}

// 点击选择字典类型
$(document).on('click', '#dictTypeList .list-group-item', function (e) {
    // 如果点击的是 action 按钮，不触发选择
    if ($(e.target).closest('.type-actions').length) return;
    e.preventDefault();
    var code = $(this).data('code');
    selectDictType(code);
});

function selectDictType(code) {
    currentDictCode = code;
    currentItemPage = 1;
    selectedItemIds = [];
    loadDictTypes();
    $('#dictItemToolbar').removeClass('d-none');
    loadDictItems();
    get(baseUrl + '/detail', { dict_code: code }, function (res) {
        $('#dictRightName').text(res.data.name || code);
        $('#dictRightCode').text(res.data.dict_code);
    });
}

// 双击编辑字典类型
$(document).on('dblclick', '#dictTypeList .list-group-item', function () {
    if ($(event.target).closest('.type-actions').length) return;
    var code = $(this).data('code');
    get(baseUrl + '/detail', { dict_code: code }, function (res) {
        var d = res.data;
        $('#dtOrig').val(d.dict_code);
        $('#dtCode').val(d.dict_code).prop('readonly', true);
        $('#dtName').val(d.name);
        $('#dtDesc').val(d.description || '');
        $('#dtIsTree').prop('checked', d.is_tree == 1);
        $('#dtStatus').prop('checked', d.status == 1);
        $('#dictTypeModal').modal('show');
    });
});

// 字典类型搜索
var typeSearchTimer = null;
$('#dictTypeSearch').on('input', function () {
    clearTimeout(typeSearchTimer);
    var kw = $(this).val().trim();
    typeSearchTimer = setTimeout(function () {
        loadDictTypes(kw || null);
    }, 300);
});

// 字典类型删除
$(document).on('click', '.btn-type-delete', function (e) {
    e.stopPropagation();
    e.preventDefault();
    var code = $(this).data('code');
    var name = $(this).data('name');
    layer.confirm(
        '<div class="text-center"><i class="bi bi-exclamation-triangle text-warning d-block mb-2" style="font-size:2rem"></i>' +
        '<strong>确定删除字典类型「' + htmlEncode(name) + '」？</strong><br>' +
        '<small class="text-secondary">此操作将同时删除该类型下所有字典项，不可恢复！</small></div>',
        function () {
            post(baseUrl + '/delete', { dict_code: code }, function () {
                toast('删除成功');
                if (currentDictCode === code) {
                    currentDictCode = '';
                    $('#dictItemBody').html('<div class="wise-dict-right-empty"><i class="bi bi-arrow-left-circle" style="font-size:2rem;margin-bottom:0.5rem"></i>请选择左侧字典类型</div>');
                    $('#dictItemToolbar').addClass('d-none');
                    $('#dictItemPager').addClass('d-none');
                }
                loadDictTypes();
            });
        }
    );
});

// 字典类型状态切换
$(document).on('click', '.btn-type-toggle', function (e) {
    e.stopPropagation();
    e.preventDefault();
    var code = $(this).data('code');
    post(baseUrl + '/toggleStatus', { dict_code: code }, function () {
        toast('状态已切换');
        loadDictTypes();
    });
});

// ==================== 字典项 ====================
function loadDictItems(page) {
    if (!currentDictCode) return;
    page = page || currentItemPage;
    get(baseUrl + '/items', {
        dict_code: currentDictCode,
        page: page,
        limit: currentItemLimit
    }, function (res) {
        var items = res.data.list || [];
        currentItemTotal = res.data.total || items.length;
        currentItemPage = page;
        selectedItemIds = [];

        if (items.length === 0) {
            $('#dictItemBody').html(
                '<div class="wise-dict-right-empty">' +
                '<i class="bi bi-inbox" style="font-size:1.5rem;margin-bottom:0.5rem"></i>' +
                '<span>暂无字典项</span>' +
                '</div>'
            );
            $('#dictItemPager').addClass('d-none');
            return;
        }

        var headerHtml = '<div class="wise-di-row wise-di-head">' +
            '<span><input type="checkbox" id="diSelectAll" title="全选/取消全选本页"></span>' +
            '<code class="small text-secondary">项值</code>' +
            '<span class="text-secondary">显示标签</span>' +
            '<span class="small text-secondary">父级</span>' +
            '<span class="text-secondary">状态</span>' +
            '<span class="text-secondary">操作</span>' +
            '</div>';
        var html = headerHtml;
        items.forEach(function (item) {
            var indentHtml = '';
            if (item.parent_id > 0) {
                indentHtml = '<span style="display:inline-block;width:' + (20) + 'px"></span>';
            }
            html += '<div class="wise-di-row">' +
                '<span><input type="checkbox" class="di-check" data-id="' + item.id + '"> <span class="text-secondary small">' + item.id + '</span></span>' +
                '<code class="small">' + indentHtml + htmlEncode(item.item_value || '') + '</code>' +
                '<span>' + htmlEncode(item.item_label || '') + '</span>' +
                '<span class="small text-secondary">#' + (item.parent_id || 0) + '</span>' +
                '<span>' + (item.status == 1 ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">禁用</span>') + '</span>' +
                '<div class="di-actions">' +
                    '<button class="btn-edit" data-id="' + item.id + '" title="编辑"><i class="bi bi-pencil"></i></button>' +
                    '<button class="btn-del" data-id="' + item.id + '" data-label="' + htmlEncode(item.item_label || '') + '" title="删除"><i class="bi bi-trash"></i></button>' +
                '</div>' +
                '</div>';
        });
        $('#dictItemBody').html(html);

        // 渲染分页
        renderPager(currentItemTotal, page, currentItemLimit);
    });
}

function renderPager(total, page, limit) {
    var totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) {
        $('#dictItemPager').addClass('d-none');
        return;
    }
    var html = '<div class="d-flex justify-content-between align-items-center px-2">';
    html += '<small class="text-secondary">共 ' + total + ' 条，第 ' + page + '/' + totalPages + ' 页</small>';
    html += '<div class="btn-group btn-group-sm">';
    html += '<button class="btn btn-outline-secondary btn-pager" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '><i class="bi bi-chevron-left"></i></button>';
    // 页码按钮
    var start = Math.max(1, page - 2);
    var end = Math.min(totalPages, page + 2);
    if (start > 1) {
        html += '<button class="btn btn-outline-secondary btn-pager" data-page="1">1</button>';
        if (start > 2) html += '<button class="btn btn-outline-secondary" disabled>...</button>';
    }
    for (var i = start; i <= end; i++) {
        html += '<button class="btn btn-pager ' + (i === page ? 'btn-primary' : 'btn-outline-secondary') + '" data-page="' + i + '">' + i + '</button>';
    }
    if (end < totalPages) {
        if (end < totalPages - 1) html += '<button class="btn btn-outline-secondary" disabled>...</button>';
        html += '<button class="btn btn-outline-secondary btn-pager" data-page="' + totalPages + '">' + totalPages + '</button>';
    }
    html += '<button class="btn btn-outline-secondary btn-pager" data-page="' + (page + 1) + '" ' + (page >= totalPages ? 'disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';
    html += '</div></div>';
    $('#dictItemPager').html(html).removeClass('d-none');
}

// 分页点击
$(document).on('click', '.btn-pager:not([disabled])', function () {
    var page = parseInt($(this).data('page'));
    if (page > 0) {
        currentItemPage = page;
        loadDictItems(page);
    }
});

// 单个复选框
$(document).on('change', '.di-check', function () {
    var id = parseInt($(this).data('id'));
    if ($(this).is(':checked')) {
        if (selectedItemIds.indexOf(id) === -1) selectedItemIds.push(id);
    } else {
        var idx = selectedItemIds.indexOf(id);
        if (idx > -1) selectedItemIds.splice(idx, 1);
    }
    updateBatchButtons();
    syncSelectAll();
});

// 全选/取消全选（当前页）
$(document).on('change', '#diSelectAll', function () {
    var checked = $(this).is(':checked');
    $('.di-check').each(function () {
        $(this).prop('checked', checked);
        var id = parseInt($(this).data('id'));
        var idx = selectedItemIds.indexOf(id);
        if (checked && idx === -1) selectedItemIds.push(id);
        else if (!checked && idx > -1) selectedItemIds.splice(idx, 1);
    });
    updateBatchButtons();
});

// 同步全选框状态（全选/半选/不选）
function syncSelectAll() {
    var total = $('.di-check').length;
    var checkedCount = $('.di-check:checked').length;
    $('#diSelectAll').prop('checked', total > 0 && total === checkedCount);
    $('#diSelectAll').prop('indeterminate', checkedCount > 0 && checkedCount < total);
}

function updateBatchButtons() {
    var hasItems = selectedItemIds.length > 0;
    $('#btnBatchDeleteItem, #btnBatchEnable, #btnBatchDisable').prop('disabled', !hasItems);
    if (hasItems) {
        $('#btnBatchDeleteItem').html('<i class="bi bi-trash"></i> 批量删除(' + selectedItemIds.length + ')');
        $('#btnBatchEnable').html('<i class="bi bi-check-circle"></i> 批量启用(' + selectedItemIds.length + ')');
        $('#btnBatchDisable').html('<i class="bi bi-slash-circle"></i> 批量禁用(' + selectedItemIds.length + ')');
    } else {
        $('#btnBatchDeleteItem').html('<i class="bi bi-trash"></i> 批量删除');
        $('#btnBatchEnable').html('<i class="bi bi-check-circle"></i> 批量启用');
        $('#btnBatchDisable').html('<i class="bi bi-slash-circle"></i> 批量禁用');
    }
}

// 批量启用
$('#btnBatchEnable').click(function () {
    if (selectedItemIds.length === 0) { toast('请先选择字典项', 'warning'); return; }
    post(baseUrl + '/itemBatchStatus', { ids: selectedItemIds, status: 1 }, function () {
        toast('已批量启用 ' + selectedItemIds.length + ' 项');
        loadDictItems(currentItemPage);
    });
});

// 批量禁用
$('#btnBatchDisable').click(function () {
    if (selectedItemIds.length === 0) { toast('请先选择字典项', 'warning'); return; }
    post(baseUrl + '/itemBatchStatus', { ids: selectedItemIds, status: 0 }, function () {
        toast('已批量禁用 ' + selectedItemIds.length + ' 项');
        loadDictItems(currentItemPage);
    });
});

// 批量删除
$('#btnBatchDeleteItem').click(function () {
    if (selectedItemIds.length === 0) { toast('请先选择字典项', 'warning'); return; }
    layer.confirm('确定批量删除 ' + selectedItemIds.length + ' 个字典项吗？此操作不可恢复！', function () {
        post(baseUrl + '/itemDeleteBatch', { ids: selectedItemIds }, function () {
            toast('批量删除成功');
            loadDictItems(currentItemPage);
        });
    });
});

// 新增字典项
$('#btnAddDictItem').click(function () {
    if (!currentDictCode) { toast('请先选择字典类型', 'warning'); return; }
    $('#dictItemForm')[0].reset();
    $('#diId').val('');
    $('#diDictCode').val(currentDictCode);
    $('#diSort').val('0');
    $('#diStatus').val('1');
    loadParentOptions(currentDictCode, 0);
    $('#dictItemModal').modal('show');
});

// 编辑字典项（通过 API 获取详情而非从 DOM 提取）
$(document).on('click', '.di-actions .btn-edit', function () {
    var id = $(this).data('id');
    get(baseUrl + '/itemDetail', { id: id }, function (res) {
        var item = res.data;
        $('#diId').val(item.id);
        $('#diDictCode').val(currentDictCode);
        $('#diValue').val(item.item_value || '');
        $('#diLabel').val(item.item_label || '');
        $('#diSort').val(item.sort || 0);
        $('#diStatus').val(item.status || 1);
        $('#diRemark').val(item.remark || '');
        loadParentOptions(currentDictCode, item.id);
        setTimeout(function () {
            $('#diParent').val(item.parent_id || 0);
        }, 300);
        $('#dictItemModal').modal('show');
    });
});

// 删除字典项
$(document).on('click', '.di-actions .btn-del', function () {
    var id = $(this).data('id');
    var label = $(this).data('label');
    layer.confirm('确定删除字典项 <strong>' + htmlEncode(label) + '</strong> 吗？', function () {
        post(baseUrl + '/itemDelete', { id: id }, function () {
            toast('删除成功');
            loadDictItems(currentItemPage);
        });
    });
});

function loadParentOptions(dictCode, excludeId) {
    get(baseUrl + '/items', { dict_code: dictCode, page: 1, limit: 200 }, function (res) {
        var items = res.data.list || [];
        var $sel = $('#diParent');
        $sel.html('<option value="0">顶级</option>');
        items.forEach(function (item) {
            if (item.id != excludeId) {
                $sel.append('<option value="' + item.id + '">' + htmlEncode(item.item_label) + ' (ID:' + item.id + ')</option>');
            }
        });
    });
}

// ==================== 表单处理 ====================
$('#dictTypeForm').submit(function (e) {
    e.preventDefault();
    var data = {
        dict_code_orig: $('#dtOrig').val(),
        dict_code: $('#dtCode').val(),
        name: $('#dtName').val(),
        description: $('#dtDesc').val(),
        is_tree: $('#dtIsTree').is(':checked') ? 1 : 0,
        status: $('#dtStatus').is(':checked') ? 1 : 0
    };
    if (!data.dict_code || !data.name) { toast('请填写必要字段', 'warning'); return; }
    post(baseUrl + '/save', data, function (res) {
        $('#dictTypeModal').modal('hide');
        toast(res.msg || '保存成功');
        loadDictTypes();
    });
});

$('#dictItemForm').submit(function (e) {
    e.preventDefault();
    var data = {
        id: $('#diId').val(),
        dict_code: $('#diDictCode').val() || currentDictCode,
        item_value: $('#diValue').val(),
        item_label: $('#diLabel').val(),
        parent_id: parseInt($('#diParent').val()) || 0,
        sort: parseInt($('#diSort').val()) || 0,
        status: parseInt($('#diStatus').val()),
        remark: $('#diRemark').val()
    };
    if (!data.item_value || !data.item_label) { toast('请填写项值和标签', 'warning'); return; }
    post(baseUrl + '/itemSave', data, function (res) {
        $('#dictItemModal').modal('hide');
        toast(res.msg || '保存成功');
        loadDictItems(currentItemPage);
    });
});

$('#btnAddDictType').click(function () {
    $('#dictTypeForm')[0].reset();
    $('#dtOrig').val('');
    $('#dtCode').prop('readonly', false);
    $('#dictTypeModal').modal('show');
});

$('#btnRefreshDictCache').click(function () {
    post('/admin/wiseConfig/clearDictCache', {}, function (res) {
        toast(res.msg || '缓存已刷新');
    });
});

// ==================== 初始化 ====================
loadDictTypes();

export default { index() {} };
