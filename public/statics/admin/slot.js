/**
 * slot.js — 插槽管理（ESM 模块）
 *
 * 展示系统预定义的插槽位置及各插槽内容数量。
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

var rendererIcons = {
    'grid': 'bi-grid-3x3-gap',
    'stack': 'bi-layers',
    'list': 'bi-list-ul',
    'tabs': 'bi-window-stack'
};

// ==================== 列表渲染 ====================
function loadList() {
    $('#slot-list').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div></div>');
    get(baseUrl + '/definitions', {}, function (res) {
        var html = '';

        $.each(res.data.definitions, function (i, def) {
            html += '<div class="card mb-3">';
            html += '<div class="card-header d-flex justify-content-between align-items-center">';
            html += '<div>';
            html += '<i class="bi ' + (rendererIcons[def.renderer] || 'bi-puzzle') + ' me-2"></i>';
            html += '<strong>' + def.title + '</strong>';
            html += ' <code class="ms-2 small">' + def.name + '</code>';
            html += '</div>';
            html += '<div>';
            html += '<span class="badge bg-secondary me-1">' + (def.page || 'global') + '</span>';
            html += '<span class="badge bg-info me-1">' + def.renderer + '</span>';
            html += '<span class="badge bg-' + (def.contents_count > 0 ? 'success' : 'secondary') + '">' + def.contents_count + ' 个内容</span>';
            html += '</div>';
            html += '</div>';
            html += '<div class="card-body small">';
            html += '<div class="row text-muted">';
            html += '<div class="col-md-4"><strong>渲染模式:</strong> ' + def.renderer + '</div>';
            html += '<div class="col-md-4"><strong>最大数量:</strong> ' + (def.maxItems > 0 ? def.maxItems + ' 个' : '不限') + '</div>';
            html += '<div class="col-md-4"><strong>所属页面:</strong> ' + (def.page || '全局') + '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        });
        $('#slot-list').html(html);
    });
}

// ==================== 事件绑定 ====================
function bindEvents() {
    $('#refresh').click(loadList);
}

const Controller = {
    index() {
        bindEvents();
        loadList();
    }
};

export default Controller;
