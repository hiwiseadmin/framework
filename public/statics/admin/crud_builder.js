/**
 * crud_builder.js — 后台 CRUD 构建器（ESM 模块）
 *
 * 与 CLI `wise:crud` 共用同一套服务：
 *   plan    → CrudPlanBuilder（读表 + 推导，含 crud_map.php 声明）
 *   preview → CrudGenerator（纯函数，不落盘）
 *   generate→ CrudWriter（唯一有副作用）
 *
 * 因此「页面看到的判定」与「命令行 dry-run」必然一致。
 *
 * 安全：服务端返回的表注释、字段注释、代码内容一律经 esc() 转义后再插入 DOM，
 * 避免数据库里的注释成为存储型 XSS 载体。
 */
const $ = window.jQuery;
const layer = window.parent.Layer || window.Layer;

'use strict';

const cfg = window.__WS_CONFIG__ || {};
const baseUrl = '/' + (cfg.modulename || 'admin') + '/' + (cfg.controller || 'crud_builder');

/** DOM 文本转义：所有服务端字符串都必须经过它 */
function esc(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toast(msg, type) {
    if (layer && layer.toast) {
        layer.toast({ content: msg, type: type || 'success' });
        return;
    }
    if (type === 'danger') {
        window.alert(msg);
    }
}

/** CSRF：优先 cookie（与 change_log.js / recycle.js 一致），退回 meta */
function csrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/);

    if (m) {
        try {
            return decodeURIComponent(m[1]);
        } catch (e) {
            return m[1];
        }
    }

    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') : '';
}

function request(url, data, method) {
    return new Promise(function (resolve, reject) {
        $.ajax({
            url: baseUrl + url,
            type: method || 'GET',
            data: data || {},
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
            success: function (res) {
                if (res && res.code === 0) {
                    resolve(res.data || {});
                } else {
                    reject(new Error((res && res.msg) || '请求失败'));
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.msg) || ('请求失败（HTTP ' + xhr.status + '）');
                reject(new Error(msg));
            }
        });
    });
}

// ==================== 状态 ====================
const state = {
    tables: [],
    table: '',
    plan: null,
    stats: null,
    overrides: {},   // 字段 → 组件类型（本次显式修改）
    relations: {},   // 字段 → "表:值列:显示列"
    dirty: false,
    artifacts: [],
    activeFile: 0,
    keyword: '',
    onlyUndeclared: false
};

// ==================== 渲染：表清单 ====================
function filteredTables() {
    const kw = state.keyword.trim().toLowerCase();

    return state.tables.filter(function (row) {
        if (state.onlyUndeclared && row.declared) {
            return false;
        }
        if (kw === '') {
            return true;
        }

        return row.name.toLowerCase().indexOf(kw) >= 0 || String(row.comment || '').toLowerCase().indexOf(kw) >= 0;
    });
}

function renderTables() {
    const rows = filteredTables();
    const $box = $('#cb-tables');

    $('#cb-table-count').text(rows.length + ' / ' + state.tables.length);

    if (rows.length === 0) {
        $box.html('<div class="ws-cb-empty">没有匹配的数据表<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="cb-clear-filter">清除筛选</button></div></div>');
        return;
    }

    let html = '';

    rows.forEach(function (row) {
        const active = row.name === state.table ? ' active' : '';
        html += '<div class="ws-cb-table-item' + active + '" data-table="' + esc(row.name) + '" title="' + esc(row.name + ' ' + (row.comment || '')) + '">'
            + (row.declared ? '<span class="declared" title="已有映射声明"></span>' : '<span style="width:6px;flex:0 0 6px"></span>')
            + '<span class="name">' + esc(row.name) + '</span>'
            + '<span class="cmt">' + esc(row.comment || '') + '</span>'
            + '</div>';
    });

    $box.html(html);
}

function loadTables() {
    $('#cb-tables').html('<div class="ws-cb-skeleton"></div><div class="ws-cb-skeleton"></div><div class="ws-cb-skeleton"></div>');

    return request('/tables').then(function (data) {
        state.tables = data.tables || [];
        renderTables();

        if (state.table === '' && state.tables.length > 0) {
            // 默认选中第一张未声明的表，减少一次点击
            const first = state.tables.filter(function (r) { return !r.declared; })[0] || state.tables[0];
            selectTable(first.name);
        }

        return data;
    }).catch(function (err) {
        $('#cb-tables').html('<div class="ws-cb-error">' + esc(err.message) + '</div>');
    });
}

// ==================== 请求：判定计划 ====================
function collectPayload() {
    const fields = [];
    const relations = [];

    Object.keys(state.overrides).forEach(function (name) {
        if (state.overrides[name]) {
            fields.push(name + ':' + state.overrides[name]);
        }
    });

    Object.keys(state.relations).forEach(function (name) {
        const spec = String(state.relations[name] || '').trim();
        if (spec !== '') {
            relations.push(name + ':' + spec);
        }
    });

    return {
        table: state.table,
        field: fields,
        relation: relations
    };
}

function selectTable(name) {
    if (state.dirty && state.table && !window.confirm('当前表有未保存的映射修改，切换后将丢失。继续？')) {
        return;
    }

    state.table = name;
    state.overrides = {};
    state.relations = {};
    state.dirty = false;
    state.plan = null;

    renderTables();
    $('#cb-current-table').text(name);
    $('#cb-current-title').text('');
    $('#cb-stats').html('');
    $('#cb-fields').html('<div class="ws-cb-skeleton"></div><div class="ws-cb-skeleton"></div><div class="ws-cb-skeleton"></div><div class="ws-cb-skeleton"></div>');

    loadPlan();
}

function loadPlan() {
    if (state.table === '') {
        return;
    }

    request('/plan', collectPayload()).then(function (data) {
        state.plan = data.plan;
        state.stats = data.stats;
        renderPlan();
    }).catch(function (err) {
        $('#cb-fields').html('<div class="ws-cb-error">' + esc(err.message) + '</div>');
    });
}

// ==================== 渲染：字段映射表 ====================
const COMPONENTS = [
    ['text', '文本'], ['textarea', '多行文本'], ['editor', '富文本'], ['number', '数字'],
    ['select', '下拉'], ['radio', '单选'], ['checkbox', '多选'], ['switch', '开关'],
    ['datetime', '日期时间'], ['date', '日期'], ['time', '时间'], ['color', '颜色'],
    ['email', '邮箱'], ['password', '密码'], ['image', '图片'], ['file', '文件'], ['avatar', '头像']
];

function componentOptions(current) {
    let html = '';
    let matched = false;

    COMPONENTS.forEach(function (pair) {
        const selected = pair[0] === current ? ' selected' : '';
        if (pair[0] === current) {
            matched = true;
        }
        html += '<option value="' + esc(pair[0]) + '"' + selected + '>' + esc(pair[1]) + ' · ' + esc(pair[0]) + '</option>';
    });

    // 声明里可能出现自定义类型：保留原值，避免被静默改成 text
    if (!matched && current) {
        html = '<option value="' + esc(current) + '" selected>' + esc(current) + '</option>' + html;
    }

    return html;
}

function renderStats(stats) {
    const items = [
        ['声明', stats.declared, 'bg-primary'],
        ['推断', stats.heuristic, 'bg-info'],
        ['兜底', stats.fallback, 'bg-secondary'],
        ['跳过', stats.skipped, 'bg-dark'],
        ['待确认', stats.warnings, stats.warnings > 0 ? 'bg-warning text-dark' : 'bg-success']
    ];

    $('#cb-stats').html(items.map(function (item) {
        return '<span class="badge ' + item[2] + '">' + esc(item[0]) + ' ' + esc(item[1]) + '</span>';
    }).join(''));
}

function renderPlan() {
    if (!state.plan) {
        return;
    }

    const plan = state.plan;
    const meta = plan.meta || {};

    $('#cb-current-table').text(plan.table);
    $('#cb-current-title').text(plan.title + (plan.plugin ? ' · 插件 ' + plan.plugin : ''));
    renderStats(state.stats || {});

    let html = '<table class="table table-sm table-hover align-middle">'
        + '<thead><tr>'
        + '<th style="width:34px"></th>'
        + '<th>字段</th>'
        + '<th>DB 类型</th>'
        + '<th style="width:180px">组件</th>'
        + '<th>判定依据</th>'
        + '<th style="width:220px">选项 / 关联</th>'
        + '</tr></thead><tbody>';

    (plan.fields || []).forEach(function (field) {
        const declared = !!field.declared;
        const badge = declared
            ? '<span class="badge ws-cb-badge-declared">声明</span>'
            : '<span class="badge ws-cb-badge-guess"><span class="dot"></span>推断</span>';

        const current = state.overrides[field.name] || field.type;
        const relation = state.relations[field.name] || '';
        const rel = (field.props && field.props.relation) ? field.props.relation : null;

        let optionCell = '';

        if (relation !== '' || rel || current === 'select') {
            const placeholder = rel ? (rel.table + ':' + rel.value + ':' + rel.label) : '表:值列:显示列';
            optionCell = '<input type="text" class="form-control form-control-sm cb-relation" data-field="' + esc(field.name) + '"'
                + ' placeholder="' + esc(placeholder) + '" value="' + esc(relation) + '">';
        } else if (field.options && Object.keys(field.options).length > 0) {
            optionCell = '<span class="small text-muted">' + esc(Object.keys(field.options).length) + ' 项</span>';
        }

        const skip = field.skip_form
            ? '<span class="badge bg-dark">跳过</span>'
            : '';

        html += '<tr data-field="' + esc(field.name) + '" class="' + (state.overrides[field.name] ? 'ws-cb-row-dirty' : '') + '">'
            + '<td>' + (field.name === 'id' || field.is_primary ? '<i class="bi bi-key-fill text-warning" title="主键"></i>' : '') + '</td>'
            + '<td class="col-name">' + esc(field.name) + '</td>'
            + '<td class="col-dbtype">' + esc(field.column_type) + '</td>'
            + '<td><select class="form-select form-select-sm cb-component" data-field="' + esc(field.name) + '"'
            + (field.skip_form ? ' disabled' : '') + '>' + componentOptions(current) + '</select></td>'
            + '<td>' + badge + ' ' + skip + ' <span class="ws-cb-rule">' + esc(field.rule) + '</span>'
            + (field.warnings && field.warnings.length ? '<div class="ws-cb-rule text-warning-emphasis">' + esc(field.warnings.join('；')) + '</div>' : '')
            + '</td>'
            + '<td>' + optionCell + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';

    if (meta.map_applied) {
        html += '<div class="px-3 py-2 small text-muted border-top">'
            + '<i class="bi bi-file-earmark-code me-1"></i>本表已有声明：'
            + esc((meta.map_declared || []).join(', ') || '—')
            + ((meta.map_relations || []).length ? ' · 关联 ' + esc(meta.map_relations.join(', ')) : '')
            + '</div>';
    }

    $('#cb-fields').html(html);
}

// ==================== 预览 ====================
function renderArtifacts() {
    const files = state.artifacts;

    $('#cb-artifact-count').text(files.length);

    if (files.length === 0) {
        $('#cb-files').html('<div class="ws-cb-empty">无产物</div>');
        $('#cb-code').text('');
        return;
    }

    let html = '';

    files.forEach(function (file, index) {
        const badge = file.status === 'new'
            ? '<span class="badge bg-success">新增</span>'
            : (file.status === 'overwrite'
                ? '<span class="badge bg-warning text-dark">覆盖</span>'
                : '<span class="badge bg-danger">模板缺失</span>');

        html += '<div class="ws-cb-file-item' + (index === state.activeFile ? ' active' : '') + '" data-index="' + index + '">'
            + badge + ' <span class="ms-1">' + esc(file.path) + '</span>'
            + '</div>';
    });

    $('#cb-files').html(html);
    renderCode();
}

function renderCode() {
    const file = state.artifacts[state.activeFile];

    if (!file) {
        $('#cb-code').text('');

        return;
    }

    $('#cb-code').text(file.content || '');
}

function openDrawer() {
    $('#cb-drawer').addClass('open').attr('aria-hidden', 'false');
}

function closeDrawer(force) {
    if (!force && state.dirty && !window.confirm('映射修改尚未保存，确定关闭？')) {
        return;
    }
    $('#cb-drawer').removeClass('open').attr('aria-hidden', 'true');
}

function preview() {
    if (state.table === '') {
        toast('请先选择数据表', 'warning');

        return;
    }

    $('#cb-files').html('<div class="ws-cb-skeleton"></div><div class="ws-cb-skeleton"></div>');
    $('#cb-code').text('生成中…');
    openDrawer();

    request('/preview', collectPayload(), 'POST').then(function (data) {
        state.artifacts = data.artifacts || [];
        state.activeFile = 0;
        state.stats = data.stats || state.stats;
        renderStats(state.stats || {});
        renderArtifacts();
    }).catch(function (err) {
        $('#cb-files').html('<div class="ws-cb-error">' + esc(err.message) + '</div>');
        $('#cb-code').text('');
    });
}

// ==================== 落盘 ====================
function generate() {
    const payload = collectPayload();
    payload.force = $('#cb-force').is(':checked') ? 1 : 0;

    $('#cb-confirm').prop('disabled', true).text('生成中…');

    request('/generate', payload, 'POST').then(function (data) {
        toast('生成完成：新增 ' + (data.written || []).length + '，跳过 ' + (data.skipped || []).length);
        state.dirty = false;
        closeDrawer(true);
        loadTables();
    }).catch(function (err) {
        toast(err.message, 'danger');
    }).then(function () {
        $('#cb-confirm').prop('disabled', false).text('确认生成');
    });
}

function saveMap() {
    const payload = collectPayload();

    if ((payload.field || []).length === 0 && (payload.relation || []).length === 0) {
        toast('本次没有显式修改，无需保存映射', 'warning');

        return;
    }

    request('/save_map', payload, 'POST').then(function (data) {
        if (data.status === 'snippet') {
            // 手写声明文件：不覆盖，给出片段
            const html = '<pre class="small" style="white-space:pre-wrap">' + esc(data.snippet) + '</pre>';
            if (layer && layer.open) {
                layer.open({ type: 1, title: '请手工合并到 config/crud_map.php', content: html, area: ['640px', '420px'] });
            } else {
                window.alert(data.snippet);
            }

            return;
        }

        toast(data.message || '映射已保存');
        state.dirty = false;
        loadTables();
    }).catch(function (err) {
        toast(err.message, 'danger');
    });
}

// ==================== 事件绑定 ====================
function bindEvents() {
    // 表清单（事件委托：内容会重绘）
    $(document).on('click', '.ws-cb-table-item', function () {
        selectTable(String($(this).data('table')));
    });

    $(document).on('click', '#cb-clear-filter', function () {
        state.keyword = '';
        $('#cb-search').val('');
        renderTables();
    });

    $('#cb-search').on('input', function () {
        state.keyword = String($(this).val() || '');
        renderTables();
    });

    $('#cb-only-undeclared').on('change', function () {
        state.onlyUndeclared = $(this).is(':checked');
        renderTables();
    });

    $('#cb-rescan').on('click', function () {
        loadTables();
    });

    $('#cb-export').on('click', function () {
        const payload = collectPayload();
        const lines = [];

        (payload.field || []).forEach(function (f) { lines.push('--field=' + f); });
        (payload.relation || []).forEach(function (r) { lines.push('--relation=' + r); });

        if (lines.length === 0) {
            toast('当前没有显式映射可导出', 'warning');

            return;
        }

        const command = 'php think wise:crud ' + state.table + ' ' + lines.join(' ') + ' --save-map';
        const html = '<pre class="small" style="white-space:pre-wrap">' + esc(command) + '</pre>';
        if (layer && layer.open) {
            layer.open({ type: 1, title: '命令行等价写法', content: html, area: ['640px', '260px'] });
        } else {
            window.alert(command);
        }
    });

    // 组件切换：只改内存态，预览时才提交
    $(document).on('change', '.cb-component', function () {
        const field = String($(this).data('field'));
        const value = String($(this).val() || '');

        state.overrides[field] = value;
        state.dirty = true;
        $(this).closest('tr').addClass('ws-cb-row-dirty');

        // 切换为非关联类组件时清掉关联声明，避免「选了文本还带着关联」
        if (value !== 'select') {
            delete state.relations[field];
        }

        loadPlan();
    });

    $(document).on('input', '.cb-relation', function () {
        const field = String($(this).data('field'));
        const value = String($(this).val() || '').trim();

        if (value === '') {
            delete state.relations[field];
        } else {
            state.relations[field] = value;
        }

        state.dirty = true;
        $(this).closest('tr').addClass('ws-cb-row-dirty');
    });

    $('#cb-preview').on('click', preview);
    $('#cb-close').on('click', function () { closeDrawer(false); });

    $(document).on('click', '.ws-cb-file-item', function () {
        state.activeFile = Number($(this).data('index')) || 0;
        renderArtifacts();
    });

    $('#cb-confirm').on('click', generate);
    $('#cb-save-map').on('click', saveMap);

    // 键盘优先：/ 聚焦搜索、Ctrl+Enter 预览、Esc 关闭抽屉
    $(document).on('keydown', function (e) {
        const tag = (e.target && e.target.tagName) || '';

        if (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
            e.preventDefault();
            $('#cb-search').trigger('focus');

            return;
        }

        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            preview();

            return;
        }

        if (e.key === 'Escape' && $('#cb-drawer').hasClass('open')) {
            closeDrawer(false);
        }
    });
}

// ==================== 入口 ====================
const Controller = {
    index() {
        bindEvents();
        loadTables();
    }
};

export default Controller;
