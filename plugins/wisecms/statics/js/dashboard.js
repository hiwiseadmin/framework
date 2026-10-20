/**
 * 数据看板页面控制器（UIUX-P2-01）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/dashboard`
 * （`DashboardController` → snake_case `dashboard`）。
 *
 * 页面契约（与 `controller/Dashboard.php` 对应）：
 *   · 四张统计卡 = `<a href>` 下钻链接（**服务端渲染**，本模块不参与）；
 *   · 待办清单 = 服务端渲染的数字 + 链接（切站后只更新数字）；
 *   · 发布趋势 = `#trendBox[data-trend]` 下发，本模块渲染；区间 7/30/90 走 AJAX 局部重取；
 *   · 热门内容 Top 10 = `#topContentsBox[data-rows]` 下发，本模块渲染。
 *
 * ⚠️ 三处已核实的事实：
 *   1. **不整页 reload**：区间切换与站点切换都只重取 `dashboard/index` 的 AJAX 分支，
 *      就地更新数字/图表/表格（P1-14 约定；`cms_context_bar` 切站后只派发事件不做 reload）。
 *   2. **data 属性读过即用**：模板引擎已对属性值做 HTML 转义 → 先 `cmsDecodeEntities()`
 *      再 `JSON.parse()`（否则 `&quot;` 会让解析必然失败，表现为「图表恒空」）。
 *   3. **`getTopContents()` 返回整行**（含 `content` 长文本）→ 服务端已精简为 4 列下发；
 *      本模块按 `id/title/view_count/publish_time` 渲染。
 *
 * @see statics/js/cms-admin.js
 */

import { cmsUrl, cmsEscape, cmsToast, cmsDecodeEntities, cmsOnSiteSwitched } from './cms-admin.js';

const URL = {
    dashboard: cmsUrl('dashboard/index'),
};

/** 内核 http 就绪检测 */
function http() {
    const wise = window.Wise || {};
    return (wise.util && wise.util.http) || null;
}

/** 读取 data 属性里的 JSON（先解一层 HTML 实体，见文件头说明） */
function readJsonAttr($el, attr) {
    if (!$el.length) {
        return [];
    }
    try {
        const raw = cmsDecodeEntities($el.attr(attr) || '[]');
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

/**
 * 渲染发布趋势柱状图
 *
 * 诚实性约定（UIUX-P2-01 修正）：
 *   · **0 篇不画柱子**。旧实现用 `Math.max(2, …)` + `min-height:2px`，导致「当天没发布」
 *     也有一根可见的柱子 —— 图在撒谎（评审 §2 P0-2）；
 *   · 高度按「区间峰值」归一，并在下方**显式写出峰值**，避免"1 篇也顶满 140px"被误读成爆量；
 *   · 区间内一条都没发时，直接给文字空态，而不是排一排不可见的柱子。
 *   · 90 天时柱子变细，间隙收到 1px；每根柱子的 `title` 给出「日期：篇数」。
 */
function renderTrend(trend) {
    const list = Array.isArray(trend) ? trend : [];
    if (!list.length) {
        return '<div class="text-muted small">暂无数据</div>';
    }

    let peak = 0;
    let total = 0;
    list.forEach((d) => {
        const n = Number(d && d.count) || 0;
        total += n;
        if (n > peak) {
            peak = n;
        }
    });

    const gap = list.length > 31 ? '1px' : '3px';
    const scale = peak > 0 ? peak : 1;

    const bars = list.map((d) => {
        const n = Number(d && d.count) || 0;
        // 0 篇 → 高度 0（不画），只保留占位，保证日期轴对齐
        const height = n > 0 ? Math.max(3, Math.round((n / scale) * 100)) : 0;
        const bar = n > 0
            ? '<div class="w-100" style="height:' + height + '%;'
                + 'background:var(--bs-primary);border-radius:3px 3px 0 0"></div>'
            : '';
        return '<div class="flex-grow-1 d-flex align-items-end" style="height:100%" title="'
            + cmsEscape(d.date || '') + '：' + n + ' 篇">' + bar + '</div>';
    }).join('');

    const summary = peak > 0
        ? '区间合计 <b>' + total + '</b> 篇 · 单日峰值 <b>' + peak + '</b> 篇'
        : '区间内<b>没有已发布内容</b>';

    return '<div class="d-flex align-items-end" style="height:140px;gap:' + gap + ';'
        + 'border-bottom:1px solid var(--bs-border-color)">' + bars + '</div>'
        + '<div class="d-flex justify-content-between text-muted small mt-2">'
        + '<span>' + cmsEscape((list[0] || {}).date || '') + '</span>'
        + '<span>' + summary + '</span>'
        + '<span>' + cmsEscape((list[list.length - 1] || {}).date || '') + '</span>'
        + '</div>';
}

/** 渲染 Top 10 表格行（无数据时给出明确空态，不留白） */
function renderTop(rows) {
    const list = Array.isArray(rows) ? rows : [];
    if (!list.length) {
        return '<tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr>';
    }

    return list.map((row) => {
        const id = Number(row.id) || 0;
        const editUrl = cmsUrl('content/edit', { id: id });
        // data-wise-nav：交给主框架开标签。编辑页不在菜单里 → 走「普通标签」分支，
        // 避免在看板 iframe 内跳走、把数据看板整页替换掉（标题由 data-wise-title 指定）。
        return '<tr>'
            + '<td class="text-center">' + id + '</td>'
            + '<td><a href="' + cmsEscape(editUrl) + '" data-wise-nav data-wise-title="编辑内容" title="编辑该内容">'
            + cmsEscape(row.title || '（无标题）') + '</a></td>'
            + '<td class="text-end">' + (Number(row.view_count) || 0) + '</td>'
            + '<td class="text-center">' + cmsEscape(row.publish_time || '—') + '</td>'
            + '</tr>';
    }).join('');
}

/** 用 AJAX 返回的统计值就地更新卡片数字（卡片本身是下钻链接，不重建） */
function updateCards(stats) {
    const map = {
        total: stats.total,
        published: stats.published,
        draft: stats.draft,
        views: stats.totalViews,
    };
    Object.keys(map).forEach((key) => {
        if (map[key] === undefined || map[key] === null) {
            return;
        }
        $('[data-card-key="' + key + '"] .js-card-value').text(Number(map[key]) || 0);
    });
}

/** 用 AJAX 返回的待办更新徽章（链接不变） */
function updateTodos(todos) {
    const list = Array.isArray(todos) ? todos : [];
    list.forEach((item) => {
        const $cell = $('[data-todo-key="' + String(item.key || '') + '"] .js-todo-count');
        if (!$cell.length) {
            return;
        }
        const count = Number(item.count) || 0;
        $cell.text(count)
            .toggleClass('bg-warning text-dark', count > 0)
            .toggleClass('bg-body-secondary text-secondary border', count === 0);
    });
}

export default {
    /**
     * 数据看板入口（与 action 同名，由 wise-bootstrap 调用）
     */
    index() {
        const $box = $('#trendBox');
        const $top = $('#topContentsBox');
        if (!$box.length) {
            return null;
        }

        // 首屏：趋势 + Top 10 都由本模块渲染（保证与后续局部刷新同一套实现，不会两边漂移）
        $box.html(renderTrend(readJsonAttr($box, 'data-trend')));
        if ($top.length) {
            $top.html(renderTop(readJsonAttr($top, 'data-rows')));
        }

        /**
         * 重取看板数据并就地更新（区间切换 / 站点切换共用）
         *
         * @param {number} days 趋势区间
         * @returns {Promise|null}
         */
        const reload = (days) => {
            const client = http();
            if (!client || typeof client.get !== 'function') {
                cmsToast('请求组件未就绪', 'error');
                return null;
            }

            // 内核 http 默认 interceptor:true → resolve 的就是 data
            return client.get(URL.dashboard, { days: days })
                .then((d) => {
                    const data = d || {};
                    // 同步区间：否则切区间后再切站点，会用**旧的** data-days 重取（区间被打回）
                    $box.attr('data-days', days)
                        .attr('data-trend', JSON.stringify(data.trend || []))
                        .html(renderTrend(data.trend || []));
                    if ($top.length) {
                        $top.attr('data-rows', JSON.stringify(data.topContents || [])).html(renderTop(data.topContents || []));
                    }
                    updateCards(data.stats || {});
                    updateTodos(data.todos || []);
                })
                .catch(() => cmsToast('看板数据加载失败，请稍后重试', 'error'));
        };

        // 区间切换（7 / 30 / 90 天）：只重取数据，不整页重载
        $('#trend-range').on('click', 'button[data-days]', function () {
            const $btn = $(this);
            const days = parseInt($btn.data('days'), 10) || 7;
            if ($btn.hasClass('active')) {
                return undefined;
            }

            $('#trend-range button').prop('disabled', true);
            const done = () => {
                $('#trend-range button').prop('disabled', false);
            };

            const promise = reload(days);
            if (!promise) {
                done();
                return undefined;
            }

            return promise.then(() => {
                $('#trend-range button').removeClass('active');
                $btn.addClass('active');
            }).then(done);
        });

        // 站点切换：卡片 / 待办 / 趋势 / Top10 全部跟着站点变了 → 就地重取（不 reload 整页）
        cmsOnSiteSwitched(() => {
            reload(parseInt($box.attr('data-days'), 10) || 7);
        });

        return null;
    },
};
