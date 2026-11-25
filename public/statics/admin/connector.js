/**
 * connector.js — 连接器配置管理（ESM 模块）
 *
 * 管理所有已注册的外部系统连接器，支持配置编辑和健康检查。
 * 由 wise-bootstrap.js 动态加载并调用 Controller.index()。
 */
const $ = window.jQuery;
const layer = window.parent.Layer || window.Layer;

'use strict';

var module  = window.__WS_CONFIG__ || {};
var baseUrl = '/' + module.modulename + '/' + module.controller;

function toast(msg, type) {
    layer.toast({ content: msg, type: type || 'success' });
}

function get(url, params, cb) {
    $.ajax({ url: url, data: params || {}, dataType: 'json', success(res) {
        if (res.code === 0) cb(res); else toast(res.msg || '请求失败', 'danger');
    }});
}

function post(url, data, cb) {
    Wise.util.http.post(url, data, { interceptor: false }).then(function (res) {
        if (res.code === 0) cb(res); else toast(res.msg || '操作失败', 'danger');
    }).catch(function () {});
}

function htmlEncode(s) {
    return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : '';
}

var currentConnector = '';

// ==================== 列表渲染 ====================
function loadList() {
    $('#connector-tbody').html('<tr><td colspan="5" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>');
    get(baseUrl + '/getList', {}, function (res) {
        var html = '';
        if (res.data.connectors.length === 0) {
            html = '<tr><td colspan="5" class="text-center text-muted py-4">暂无已注册的连接器</td></tr>';
        }
        $.each(res.data.connectors, function (i, conn) {
            var healthIcon = conn.healthy
                ? '<span class="badge bg-success">正常</span>'
                : '<span class="badge bg-danger">故障</span>';
            html += '<tr>';
            html += '<td><strong>' + conn.name + '</strong><br><small class="text-muted">' + conn.description + '</small></td>';
            html += '<td><span class="badge bg-info">' + conn.type_label + '</span></td>';
            html += '<td><code class="small">' + conn.id + '</code></td>';
            html += '<td>';
            html += '<button class="btn btn-sm btn-outline-primary me-1 config-btn" data-name="' + conn.id + '" data-title="' + htmlEncode(conn.name) + '"><i class="bi bi-gear"></i> 配置</button>';
            html += '<button class="btn btn-sm btn-outline-secondary health-btn" data-name="' + conn.id + '"><i class="bi bi-heart-pulse"></i> 检查</button>';
            html += '</td>';
            html += '<td id="health-' + conn.id + '">' + healthIcon + '</td>';
            html += '</tr>';
        });
        $('#connector-tbody').html(html);
    });
}

// ==================== 配置编辑 ====================
function openConfig(name, title) {
    currentConnector = name;
    $('#configTitle').text(title);
    get(baseUrl + '/config', { name: name }, function (res) {
        var html = '';
        if (res.data.schema.length === 0) {
            html = '<p class="text-muted">此连接器无可配置项</p>';
        }
        $.each(res.data.schema, function (i, field) {
            var value = res.data.config[field.key] || '';
            html += '<div class="mb-3">';
            html += '<label class="form-label">' + field.label + (field.required ? ' <span class="text-danger">*</span>' : '') + '</label>';
            html += '<input type="' + (field.type || 'text') + '" class="form-control" name="' + field.key + '" value="' + htmlEncode(value) + '">';
            html += '</div>';
        });
        $('#configBody').html(html);
        $('#configModal').modal('show');
    });
}

function saveConfig() {
    var config = {};
    $('#configBody input').each(function () {
        config[$(this).attr('name')] = $(this).val();
    });
    post(baseUrl + '/save', { name: currentConnector, config: config }, function (res) {
        $('#configModal').modal('hide');
        toast(res.msg || '配置已保存');
        loadList();
    });
}

// ==================== 健康检查 ====================
function healthCheck($btn, name) {
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    post(baseUrl + '/health', { name: name }, function (res) {
        $('#health-' + name).html(res.data.healthy
            ? '<span class="badge bg-success">正常</span>'
            : '<span class="badge bg-danger">故障</span>');
        $('.health-btn').prop('disabled', false).html('<i class="bi bi-heart-pulse"></i> 检查');
    });
}

// ==================== 事件绑定 ====================
function bindEvents() {
    $('#refresh').click(loadList);

    $(document).on('click', '.config-btn', function () {
        openConfig($(this).data('name'), $(this).data('title'));
    });

    $('#saveConfig').click(saveConfig);

    $(document).on('click', '.health-btn', function () {
        healthCheck($(this), $(this).data('name'));
    });
}

const Controller = {
    index() {
        bindEvents();
        loadList();
    }
};

export default Controller;
