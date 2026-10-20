/**
 * statCards.js -- 统计卡片通用组件（ESM）
 *
 * 用法：
 *   import statCards from './statCards';
 *   statCards.init({
 *       apiBase: '/api/stats',
 *       container: '#statcards',
 *       cards: [
 *           { field: 'total_products', label: '商品总数', icon: 'bi-box', color: '#667eea' },
 *       ],
 *       quickCards: [
 *           { icon: 'bi-box-arrow-in-down', label: '采购入库', href: '/in' },
 *       ],
 *       refresh: 30000,
 *   });
 */
import { http, $ } from './util.js';

'use strict';

const _prevValues = {};  // trend 比较缓存

/**
 * 初始化
 *
 * @param {Object} options
 * @param {string} options.apiBase    数据接口 URL（必填）
 * @param {string} [options.container] 卡片容器选择器，默认 '#statcards'
 * @param {Array}  options.cards      卡片配置 [{field, label, icon?, color?, format?, fixed?}]
 * @param {Array}  [options.quickCards] 快捷入口 [{icon, label, href, color?}]
 * @param {number} [options.refresh]   自动刷新间隔 ms，0=不自动
 * @param {Function} [options.onLoad]  数据加载后回调 (data)
 */
function init(options) {
    options = $.extend({
        container: '#statcards',
        refresh: 0,
        cards: [],
        quickCards: [],
        onLoad: null,
    }, options);

    var $container = $(options.container);

    // 1) 注入卡片 DOM
    renderCardDOM($container, options.cards, options.quickCards);

    // 2) 加载数据
    // http 已完成 code 校验与非 0 提示，这里只处理成功分支
    function load() {
        http.get(options.apiBase, {})
            .then(function (data) {
                if (!data) {
                    return;
                }
                updateCards(options.cards, data);
                if (typeof options.onLoad === 'function') {
                    options.onLoad(data);
                }
            })
            .catch(function () { /* 静默：错误已由 http 全局处理 */ });
    }

    load();

    // 3) 轮询
    if (options.refresh > 0) {
        setInterval(load, options.refresh);
    }
}

/**
 * 注入卡片 HTML
 */
function renderCardDOM($container, cards, quickCards) {
    var html = '';

    // 统计卡片网格
    if (cards.length > 0) {
        html += '<div class="smartstock-stats">';
        cards.forEach(function (c) {
            var iconHtml = c.icon
                ? '<div class="stat-icon" style="background:linear-gradient(135deg,' + (c.color || '#667eea') + ' 0%,' + lighten(c.color || '#667eea') + ' 100%);"><i class="bi ' + c.icon + '"></i></div>'
                : '';
            html += '<div class="stat-card">' + iconHtml +
                '<div class="stat-info">' +
                    '<div class="stat-value" id="statcard-' + c.field + '"><span class="statcard-loading">--</span></div>' +
                    '<div class="stat-label">' + (c.label || c.field) + '</div>' +
                '</div>' +
            '</div>';
        });
        html += '</div>';
    }

    // 快捷入口
    if (quickCards.length > 0) {
        html += '<div class="row g-3 mb-3">';
        quickCards.forEach(function (q) {
            html += '<div class="col-md-' + (q.width || 3) + '">' +
                '<a href="' + (q.href || '#') + '" class="card border-0 shadow-sm text-decoration-none statcard-quick">' +
                    '<div class="card-body text-center py-4">' +
                        '<i class="bi ' + (q.icon || 'bi-box') + ' display-6 text-' + (q.color || 'primary') + ' mb-2"></i>' +
                        '<h6 class="text-dark">' + (q.label || '') + '</h6>' +
                    '</div>' +
                '</a>' +
            '</div>';
        });
        html += '</div>';
    }

    $container.html(html);
}

/**
 * 更新卡片数值
 */
function updateCards(cards, data) {
    cards.forEach(function (c) {
        var value = data[c.field];
        if (value === undefined || value === null) {
            value = '--';
        }
        var formatted = formatValue(value, c.format || 'number');

        var html = String(formatted);

        // trend 箭头
        if (c.trend && typeof value === 'number') {
            var prev = _prevValues[c.field];
            if (prev !== undefined && prev !== null) {
                if (value > prev) {
                    html += ' <span class="statcard-trend-up">&#9650;</span>';
                } else if (value < prev) {
                    html += ' <span class="statcard-trend-down">&#9660;</span>';
                } else {
                    html += ' <span class="statcard-trend-flat">&#8213;</span>';
                }
            }
            _prevValues[c.field] = value;
        }

        var $el = $('#statcard-' + c.field);
        if ($el.length) {
            $el.html(html).removeClass('statcard-loading');
        }
    });
}

/**
 * 格式化数值
 */
function formatValue(value, format) {
    if (value === '--') return value;
    switch (format) {
        case 'currency':
            return '¥' + Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2 });
        case 'percent':
            return Number(value).toFixed(1) + '%';
        case 'integer':
            return Math.round(value).toLocaleString('zh-CN');
        default:
            if (typeof value === 'number') {
                return value.toLocaleString('zh-CN');
            }
            return String(value);
    }
}

/**
 * 简单颜色变亮（用于渐变终点）
 */
function lighten(hex) {
    if (!hex || hex === 'primary' || hex === 'danger' || hex === 'warning' || hex === 'success') {
        return hex + 'aa';
    }
    try {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        r = Math.min(255, r + 60);
        g = Math.min(255, g + 60);
        b = Math.min(255, b + 60);
        return '#' + [r, g, b].map(function(v) {
            return v.toString(16).padStart(2, '0');
        }).join('');
    } catch(e) {
        return '#cccccc';
    }
}

export default { init: init };
