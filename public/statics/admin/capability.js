/**
 * capability.js — 能力市场管理（ESM 模块）
 *
 * 展示所有已注册的扩展点及其实现，支持启用/停用/切换活跃状态。
 * 由 wise-bootstrap.js 动态加载并调用 Controller.index()。
 *
 * 切换后采用「局部刷新」：仅本地更新受影响卡片的数据并重渲染该卡片，
 * 不再重新拉取整张列表，避免整页刷新体感。
 */
const $ = window.jQuery;
const layer = window.parent.Layer || window.Layer;

'use strict';

var module  = window.__WS_CONFIG__ || {};
var baseUrl = '/' + module.modulename + '/' + module.controller;

// 最近一次拉取的列表数据（用于局部刷新）
var state = null;

// 各能力卡片的折叠状态（持久化到 localStorage，参考 wise_config）
var collapsedPoints = {};
try { collapsedPoints = JSON.parse(localStorage.getItem('capability_collapsed') || '{}'); } catch (e) {}

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

var policyColors = {
    'single': 'info',
    'multi': 'success',
    'routed': 'warning'
};

// P4-4：来源类型颜色（官方 / 插件 / 宿主 / 内核 / 未识别）
var vendorColors = {
    'core': 'dark',
    'official': 'primary',
    'app': 'info',
    'plugin': 'success',
    'unknown': 'secondary'
};

// ==================== 列表渲染 ====================

/** 生成卡片 DOM 唯一 ID（point 含点号，需转义） */
function cardId(point) {
    return 'cap-card-' + String(point).replace(/[^a-zA-Z0-9_-]/g, '_');
}

/** 在本地数据中按 point 查找能力卡片 */
function findCap(point) {
    var found = null;
    $.each(state, function (i, cap) {
        if (cap.point === point) {
            found = cap;
            return false;
        }
    });
    return found;
}

/** 渲染单个能力卡片 HTML */
function renderCard(cap) {
    var hasItems = cap.count > 0;
    var collapsed = collapsedPoints[cap.point] ? ' collapsed' : '';
    var html = '<div class="card mb-3 cap-card' + collapsed + '" id="' + cardId(cap.point) + '">';
    html += '<div class="card-header d-flex justify-content-between align-items-center bg-body-tertiary cap-card-header" data-point="' + cap.point + '">';
    html += '<div class="d-flex align-items-center"><div class="cap-card-toggle" type="button" tabindex="-1"><i class="bi bi-chevron-down"></i></div><strong>' + cap.name + '</strong> <code class="ms-2 small">' + cap.point + '</code></div>';
    html += '<div><span class="badge bg-' + (policyColors[cap.policy] || 'secondary') + '">' + cap.policy_label + '</span>';
    html += ' <span class="badge bg-secondary">' + cap.count + ' 个实现</span></div>';
    html += '</div>';

    html += '<div class="cap-card-body">';
    if (hasItems) {
        html += '<div class="table-responsive"><table class="cap-impl-table table table-sm table-hover mb-0">';
        html += '<thead><tr><th>名称</th><th>实现类</th><th>来源</th><th>优先级</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        var currentVendor = null;
        $.each(cap.implementations, function (j, impl) {
            // P4-4：按来源分组（后端已按「官方 → 插件」排序，此处仅插入分组行）
            if (impl.vendor !== currentVendor) {
                currentVendor = impl.vendor;
                html += '<tr class="table-light"><td colspan="6" class="small">';
                html += '<strong>' + impl.vendor_label + '</strong>';
                if (impl.locked) {
                    html += ' <span class="badge bg-secondary" title="' + impl.locked_hint + '">不可卸载，可停用</span>';
                }
                html += '</td></tr>';
            }

            var statusBadge = impl.active
                ? '<span class="badge bg-success">活跃</span>'
                : '<span class="badge bg-secondary">候补</span>';
            if (impl.pending) {
                statusBadge += ' <span class="badge bg-warning text-dark">待确认上线</span>';
            }
            html += '<tr>';
            html += '<td><code>' + impl.name + '</code>'
                + (impl.locked ? ' <i class="bi bi-lock text-muted" title="' + impl.locked_hint + '"></i>' : '')
                + '</td>';
            html += '<td class="small text-muted">' + impl.class + '</td>';
            html += '<td><span class="badge bg-' + (vendorColors[impl.vendor_kind] || 'secondary') + '">' + impl.vendor_kind_label + '</span></td>';
            html += '<td>' + impl.priority + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td>';
            // P4-5：插件带来的会员功能需运营确认后才出现在会员中心
            if (impl.pending) {
                html += '<button class="btn btn-sm btn-primary confirm-btn me-1" data-name="' + impl.name + '">确认上线</button>';
            }
            if (cap.policy === 'single' && !impl.active) {
                html += '<button class="btn btn-sm btn-outline-primary toggle-btn" data-point="' + cap.point + '" data-name="' + impl.name + '" data-active="1">设为活跃</button>';
            }
            if (impl.active && cap.policy === 'single') {
                html += '<button class="btn btn-sm btn-outline-secondary toggle-btn" data-point="' + cap.point + '" data-name="' + impl.name + '" data-active="0">设为候补</button>';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';
    } else {
        html += '<div class="card-body text-muted small">暂无实现</div>';
    }
    html += '</div>';
    html += '</div>';
    return html;
}

/** 全量渲染（首次加载/手动刷新） */
function renderList() {
    var html = '';
    $.each(state, function (i, cap) {
        html += renderCard(cap);
    });
    $('#capability-list').html(html);
}

function loadList() {
    $('#capability-list').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div></div>');
    get(baseUrl + '/getList', {}, function (res) {
        state = res.data.capabilities;
        renderList();
    });
}

// ==================== 局部刷新 ====================

/**
 * 本地应用切换结果（与后端 ExtPoint::setActive 逻辑一致）：
 * - 设为活跃：single 策略下同扩展点其他实现全部候补
 * - 设为候补：single 策略下自动激活优先级最高的备用实现
 */
function applyToggle(point, name, active) {
    var cap = findCap(point);
    if (!cap) return;

    $.each(cap.implementations, function (j, impl) {
        if (impl.name === name) {
            impl.active = active;
        } else if (cap.policy === 'single' && active) {
            impl.active = false;
        }
    });

    if (cap.policy === 'single' && !active) {
        var sorted = cap.implementations.slice().sort(function (a, b) { return a.priority - b.priority; });
        for (var i = 0; i < sorted.length; i++) {
            if (sorted[i].name !== name) {
                sorted[i].active = true;
                break;
            }
        }
    }
}

/** 仅重渲染受影响的卡片 */
function refreshCard(point) {
    var cap = findCap(point);
    if (!cap) return;
    $('#' + cardId(point)).replaceWith(renderCard(cap));
}

// ==================== 事件绑定 ====================
function bindEvents() {
    $('#refresh').click(loadList);

    // 能力卡片折叠/展开（状态持久化到 localStorage，参考 wise_config）
    $(document).on('click', '.cap-card-header', function () {
        var point = $(this).data('point');
        var $card = $(this).closest('.cap-card');
        $card.toggleClass('collapsed');
        collapsedPoints[point] = $card.hasClass('collapsed');
        try { localStorage.setItem('capability_collapsed', JSON.stringify(collapsedPoints)); } catch (e) {}
    });

    // P4-5：确认插件功能上线（会员功能治理；官方功能不会出现该按钮）
    $(document).on('click', '.confirm-btn', function () {
        var btn = $(this);
        var name = btn.data('name');
        var point = btn.closest('.cap-card').find('.cap-card-header').data('point');

        post('/' + module.modulename + '/member/features/confirm', { names: name }, function (res) {
            toast(res.msg || '已确认上线');

            var cap = findCap(point);
            if (cap) {
                $.each(cap.implementations, function (j, impl) {
                    if (impl.name === name) { impl.pending = false; }
                });
                $.each(cap.vendors || [], function (k, v) { v.pending = false; });
                refreshCard(point);
            }
        });
    });

    $(document).on('click', '.toggle-btn', function () {
        var btn = $(this);
        var point = btn.data('point');
        var name = btn.data('name');
        var active = btn.data('active');
        post(baseUrl + '/toggle', {
            point: point,
            name: name,
            active: active
        }, function (res) {
            toast(res.msg || (active ? '已设为活跃' : '已设为候补'));
            applyToggle(point, name, active);
            refreshCard(point);
        });
    });
}

const Controller = {
    index() {
        bindEvents();
        loadList();
    }
};

export default Controller;
