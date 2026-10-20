/**
 * 发布日历（UIUX-P0-35 月历 + 推荐时段 + P0-36 拖拽改期）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/schedule`
 * （`ScheduleController` → snake_case `schedule`）。
 *
 * 对应控制器动作：
 *   schedule/calendar → 月内排期事件（**纯数组**，无分页）
 *   schedule/suggest  → 推荐发布时间（按栏目，无历史数据时回退经验时段）
 *   schedule/batch    → 批量排期（POST `ids[]` + `scheduled_time`，复用 pending 行）
 *
 * ⚠️ 四个已核实的事实：
 *   1. **无日历库** → 自实现月历（固定 7 列网格），不引入 fullcalendar 依赖。
 *   2. **`calendar` 硬编码站点 1**（`Schedule::calendar` 调 `getCalendar(1, ...)`）→ 多站点下
 *      本页**只看站点 1** 的排期，已登记为缺口（与 redirect checkDead 同类），未在本页改。
 *   3. **拖拽改期走 `batch`**（无 `moveSchedule` 路由）：`batchSchedule` 内部对同一 content
 *      **复用** pending 行（P1-C），故传 `ids=[content_id]` + 新时间即可改期，不会产生重复排期。
 *   4. **冲突提示来自 `suggest`**：`available=false` 的推荐时段即「该栏目 ±30/60 分钟已有排期」，
 *      直接标红展示，不做二次计算。
 *
 * @see statics/js/cms-admin.js
 */

import { cmsUrl, cmsToast, cmsConfirm, cmsEscape } from './cms-admin.js';

const URL = {
    calendar: cmsUrl('schedule/calendar'),
    suggest: cmsUrl('schedule/suggest'),
    batch: cmsUrl('schedule/batch'),
};

function httpGet(url, params) {
    const wise = window.Wise || {};
    return wise.util.http.get(url, params, { interceptor: true, loading: true });
}

function httpPost(url, data) {
    const wise = window.Wise || {};
    return wise.util.http.post(url, data, { interceptor: false, loading: true });
}

function pad(n) { return String(n).padStart(2, '0'); }

/** 月内事件按「日」分组（key = YYYY-MM-DD） */
function groupByDay(events) {
    const map = {};
    (events || []).forEach((e) => {
        const day = String(e.start || '').slice(0, 10);
        if (!day) return;
        (map[day] = map[day] || []).push(e);
    });
    return map;
}

export default {
    async calendar() {
        const $root = $('#scheduleCalendar');
        if (!$root.length) return;

        let year = new Date().getFullYear();
        let month = new Date().getMonth(); // 0-based
        let selectedDay = ''; // YYYY-MM-DD
        let events = [];
        let byDay = {};

        function monthKey() { return year + '-' + pad(month + 1); }

        async function loadMonth() {
            const start = year + '-' + pad(month + 1) + '-01';
            const last = new Date(year, month + 1, 0).getDate();
            const end = year + '-' + pad(month + 1) + '-' + pad(last);

            try {
                const data = await httpGet(URL.calendar, { start: start, end: end });
                events = Array.isArray(data) ? data : [];
            } catch (e) {
                events = [];
                cmsToast('加载日历失败', 'error');
            }
            byDay = groupByDay(events);

            if (!selectedDay) selectedDay = year + '-' + pad(month + 1) + '-01';
            renderCalendar();
            renderDayPanel();
        }

        function renderCalendar() {
            $('#schedule-month-label').text(year + ' 年 ' + (month + 1) + ' 月');

            const first = new Date(year, month, 1).getDay(); // 0=周日
            const days = new Date(year, month + 1, 0).getDate();
            const today = new Date();

            let html = '<div class="row g-1 text-center fw-bold text-muted small mb-1">';
            ['日', '一', '二', '三', '四', '五', '六'].forEach((w) => {
                html += '<div class="col">' + w + '</div>';
            });
            html += '</div>';

            let cell = 0;
            html += '<div class="row g-1">';
            for (let i = 0; i < first; i++) {
                html += '<div class="col cms-cal-cell"></div>';
                cell++;
            }
            for (let d = 1; d <= days; d++) {
                const dayKey = year + '-' + pad(month + 1) + '-' + pad(d);
                const isToday = (today.getFullYear() === year && today.getMonth() === month && today.getDate() === d);
                const isSelected = dayKey === selectedDay;
                const items = byDay[dayKey] || [];

                html += '<div class="col cms-cal-cell" data-day="' + dayKey + '">'
                    + '<div class="border rounded p-1 h-100 ' + (isSelected ? 'border-primary' : '') + '">'
                    + '<div class="d-flex justify-content-between align-items-center small">'
                    + '<span class="' + (isToday ? 'badge bg-primary' : 'text-muted') + '">' + d + '</span>'
                    + (items.length ? '<span class="badge bg-light text-dark">' + items.length + '</span>' : '')
                    + '</div>'
                    + '<div class="cms-cal-events">'
                    + items.slice(0, 3).map((e) => '<div class="cms-cal-event small text-truncate" '
                        + 'draggable="true" data-id="' + e.content_id + '" data-time="'
                        + cmsEscape(String(e.start || '').slice(11, 16)) + '" title="'
                        + cmsEscape(String(e.title || '')) + ' ' + cmsEscape(String(e.start || '').slice(11, 16)) + '">'
                        + '<i class="bi bi-clock me-1"></i>' + cmsEscape(String(e.start || '').slice(11, 16))
                        + ' ' + cmsEscape(String(e.title || '')) + '</div>').join('')
                    + (items.length > 3 ? '<div class="small text-muted">+' + (items.length - 3) + ' 条</div>' : '')
                    + '</div></div></div>';
                cell++;
            }
            while (cell % 7 !== 0) {
                html += '<div class="col cms-cal-cell"></div>';
                cell++;
            }
            html += '</div>';

            $('#schedule-grid').html(html);
            bindCalendarEvents();
        }

        function bindCalendarEvents() {
            $('#schedule-grid .cms-cal-cell[data-day]').on('click', function () {
                selectedDay = $(this).attr('data-day');
                renderCalendar();
                renderDayPanel();
            });

            // 拖拽：事件条目 → 目标日期格子
            $('#schedule-grid .cms-cal-event').on('dragstart', function (e) {
                const ev = e.originalEvent || e;
                ev.dataTransfer.setData('text/plain', JSON.stringify({
                    content_id: $(this).attr('data-id'),
                    time: $(this).attr('data-time'),
                }));
                ev.dataTransfer.effectAllowed = 'move';
            });
            $('#schedule-grid .cms-cal-cell[data-day]').on('dragover', function (e) {
                const ev = e.originalEvent || e;
                ev.preventDefault();
                ev.dataTransfer.dropEffect = 'move';
            });
            $('#schedule-grid .cms-cal-cell[data-day]').on('drop', async function (e) {
                const ev = e.originalEvent || e;
                ev.preventDefault();
                const day = $(this).attr('data-day');
                let payload = null;
                try { payload = JSON.parse(ev.dataTransfer.getData('text/plain')); } catch (err) { return; }
                if (!payload || !payload.content_id) return;

                const time = String(payload.time || '09:00');
                await moveSchedule(payload.content_id, day + ' ' + time + ':00', day);
            });
        }

        async function moveSchedule(contentId, scheduledTime, day) {
            const ok = await cmsConfirm(
                '将内容 #' + contentId + ' 的排期改为 <b>' + cmsEscape(scheduledTime) + '</b>？<br>'
                + '<span class="small text-muted">同一内容只保留一条待发布排期，原排期会被替换。</span>',
                '改期确认'
            );
            if (!ok) return;

            try {
                const body = await httpPost(URL.batch, { 'ids[]': [contentId], scheduled_time: scheduledTime });
                if (body && body.code === 0) {
                    cmsToast(body.msg || '排期已更新', 'success');
                    await loadMonth();
                } else {
                    cmsToast((body && body.msg) || '改期失败', 'error');
                }
            } catch (err) {
                cmsToast('改期请求失败，请稍后重试', 'error');
            }
        }

        async function renderDayPanel() {
            const items = byDay[selectedDay] || [];
            const $list = $('#schedule-day-list');
            if (!items.length) {
                $list.html('<div class="text-muted small">当日无待发布内容</div>');
            } else {
                $list.html(items.map((e) => '<div class="d-flex justify-content-between small border-bottom py-1">'
                    + '<span class="text-truncate" title="' + cmsEscape(String(e.title || '')) + '">'
                    + cmsEscape(String(e.title || '')) + '</span>'
                    + '<span class="text-muted text-nowrap ms-2">' + cmsEscape(String(e.start || '').slice(11, 16)) + '</span>'
                    + '</div>').join(''));
            }
            $('#schedule-day-label').text(selectedDay);

            // 推荐时段（冲突标红）
            await loadSuggest();
        }

        async function loadSuggest() {
            const $box = $('#schedule-suggest');
            try {
                const data = await httpGet(URL.suggest, { catid: 0 });
                const list = Array.isArray(data) ? data : [];
                if (!list.length) {
                    $box.html('<div class="text-muted small">暂无推荐时段</div>');
                    return;
                }
                $box.html(list.map((s) => {
                    const time = String(s.time || '').slice(11, 16);
                    const busy = !s.available;
                    return '<div class="d-flex justify-content-between align-items-center small border-bottom py-1">'
                        + '<span class="' + (busy ? 'text-danger' : '') + '">' + cmsEscape(time)
                        + (busy ? ' <i class="bi bi-exclamation-circle" title="该时段附近已有 ' + (s.conflict_count || 0) + ' 条排期"></i>' : '')
                        + '</span>'
                        + '<span class="badge ' + (busy ? 'bg-danger' : 'bg-success') + '">'
                        + (busy ? '有冲突' : '可用') + '</span></div>';
                }).join(''));
            } catch (e) {
                $box.html('<div class="text-muted small">推荐时段加载失败</div>');
            }
        }

        $('#schedule-prev').on('click', () => {
            month -= 1;
            if (month < 0) { month = 11; year -= 1; }
            selectedDay = year + '-' + pad(month + 1) + '-01';
            loadMonth();
        });
        $('#schedule-next').on('click', () => {
            month += 1;
            if (month > 11) { month = 0; year += 1; }
            selectedDay = year + '-' + pad(month + 1) + '-01';
            loadMonth();
        });
        $('#schedule-today').on('click', () => {
            const now = new Date();
            year = now.getFullYear();
            month = now.getMonth();
            selectedDay = year + '-' + pad(month + 1) + '-' + pad(now.getDate());
            loadMonth();
        });

        await loadMonth();
    },
};
