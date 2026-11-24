/**
 * async_task.js — 任务中心（ESM 页面控制器）
 *
 * 约定（与内核保持一致）：
 *   - 只取 window.Wise.* 全局，**不 import** /statics/modules/*（否则是第二份实例）
 *   - 请求统一 `{ interceptor: false }` 并自行判 `code === 0`：
 *     内核 `ui.action.execute()` 在拦截器未启用时会把业务失败也当成功弹 toast
 *     （内核注释已明确该坑），故这里显式判断，反馈可预期
 *   - 无 alert / 无内联 onclick（CSP 要求），按钮走 data-action + 表格事件代理
 *
 * 后端：app\admin\controller\AsyncTask（/admin/async_task/*）
 */

const $ = window.jQuery;

/** 状态 → Bootstrap 徽标 */
const STATUS_META = {
    pending: { cls: 'secondary', text: '等待中' },
    running: { cls: 'primary', text: '执行中' },
    paused: { cls: 'warning', text: '已暂停' },
    success: { cls: 'success', text: '成功' },
    failed: { cls: 'danger', text: '失败' },
    canceled: { cls: 'dark', text: '已取消' },
    stale: { cls: 'danger', text: '已卡死' },
};

/** 轮询间隔（毫秒） */
const POLL_INTERVAL = 3000;

let pollTimer = null;

// ============================================================
// 工具
// ============================================================

function wise() {
    return window.Wise || {};
}

function ui() {
    return wise().ui || {};
}

function toast() {
    const u = ui();
    return u.toast || { success() {}, error() {}, info() {}, warning() {} };
}

function esc(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function truncate(text, max) {
    const str = String(text === null || text === undefined ? '' : text);
    return str.length > max ? str.slice(0, max) + '…' : str;
}

/** 构建查询串（正确序列化数组为 `key[]=`） */
function buildQuery(params) {
    const parts = [];

    Object.keys(params || {}).forEach((key) => {
        const value = params[key];

        if (Array.isArray(value)) {
            value.forEach((item) => {
                parts.push(`${encodeURIComponent(key + '[]')}=${encodeURIComponent(item)}`);
            });
            return;
        }

        if (value !== undefined && value !== null && value !== '') {
            parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
        }
    });

    return parts.join('&');
}

/** 归一化响应为 { code, msg, data } */
function unwrap(res) {
    if (res && typeof res === 'object' && Object.prototype.hasOwnProperty.call(res, 'code')) {
        return res;
    }
    return { code: 0, msg: '', data: res };
}

function httpClient() {
    const util = wise().util || {};
    return util.http || null;
}

/**
 * 发起请求并统一判错
 *
 * @returns {Promise<{ok:boolean, data:any, msg:string}>}
 */
async function request(method, url, data) {
    const http = httpClient();

    if (!http) {
        throw new Error('内核 HTTP 客户端不可用，请刷新页面');
    }

    const opts = { interceptor: false };
    const verb = String(method || 'GET').toUpperCase();

    let body;
    if (verb === 'GET') {
        const query = buildQuery(data);
        body = await http.get(query ? `${url}?${query}` : url, undefined, opts);
    } else if (verb === 'DELETE') {
        const query = buildQuery(data);
        body = await http.delete(query ? `${url}?${query}` : url, opts);
    } else {
        body = await http.post(url, data || {}, opts);
    }

    const res = unwrap(body);

    return { ok: Number(res.code) === 0, data: res.data, msg: res.msg || '' };
}

// ============================================================
// 列渲染
// ============================================================

function renderStatus(row) {
    const meta = STATUS_META[row.status] || { cls: 'secondary', text: row.status_text || row.status || '-' };
    return `<span class="badge text-bg-${esc(meta.cls)}">${esc(meta.text)}</span>`;
}

function renderProgress(row) {
    const total = Number(row.total) || 0;
    const done = Number(row.done) || 0;

    if (row.status === 'success') {
        return '<div class="progress" style="height:8px;min-width:80px"><div class="progress-bar bg-success" style="width:100%"></div></div>';
    }

    if (total <= 0) {
        return row.status === 'running'
            ? '<span class="text-primary small">进行中…</span>'
            : '<span class="text-muted">—</span>';
    }

    const percent = Math.max(0, Math.min(100, Number(row.progress_percent) || 0));

    return (
        `<div class="progress" style="height:8px;min-width:80px">` +
        `<div class="progress-bar" style="width:${percent}%"></div></div>` +
        `<small class="text-muted">${done}/${total}（${percent}%）</small>`
    );
}

function renderMessage(row) {
    if (row.error) {
        return `<span class="text-danger" title="${esc(row.error)}">${esc(truncate(row.error, 60))}</span>`;
    }
    return esc(truncate(row.message || '', 60));
}

function renderOperate(row) {
    const tableApi = wise().table || {};
    const buttons = [
        { action: 'detail', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-info' },
    ];

    if (row.active && !row.cancel_requested) {
        buttons.push({ action: 'cancel', title: '取消', icon: 'bi bi-x-circle', class: 'btn-outline-warning' });
    }

    if (row.finished && row.status !== 'success') {
        buttons.push({ action: 'retry', title: '重试', icon: 'bi bi-arrow-repeat', class: 'btn-outline-primary' });
    }

    if (row.finished) {
        buttons.push({ action: 'del', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' });
    }

    if (typeof tableApi.operateButtons === 'function') {
        return tableApi.operateButtons(buttons, row);
    }

    return '';
}

// ============================================================
// 队列健康
// ============================================================

function badge(label, value, cls) {
    return `<span class="badge text-bg-${cls} me-2 mb-1">${esc(label)}：${esc(value)}</span>`;
}

function renderHealth(data) {
    const $box = $('#async-task-health');
    if (!$box.length) return;

    if (!data) {
        $box.html('<span class="text-muted small">队列健康数据不可用</span>');
        return;
    }

    const tasks = data.tasks || {};
    const queue = data.queue || {};
    const monitor = data.monitor || {};

    const parts = [];

    parts.push('<div class="mb-1 fw-semibold small text-muted">任务统计</div>');
    parts.push(badge('总计', tasks.total || 0, 'secondary'));
    parts.push(badge('等待中', tasks.pending || 0, 'secondary'));
    parts.push(badge('执行中', tasks.running || 0, 'primary'));
    parts.push(badge('成功', tasks.success || 0, 'success'));
    parts.push(badge('失败', tasks.failed || 0, 'danger'));
    parts.push(badge('已取消', tasks.canceled || 0, 'dark'));
    parts.push(badge('已卡死', tasks.stale || 0, 'danger'));

    parts.push('<div class="mt-2 mb-1 fw-semibold small text-muted">队列积压</div>');
    parts.push(badge('驱动', queue.connection || '-', 'info'));
    parts.push(badge('待处理', queue.pending || 0, 'info'));
    parts.push(badge('延迟', queue.delayed || 0, 'info'));
    parts.push(badge('执行中(保留)', queue.reserved || 0, 'info'));
    parts.push(badge('失败任务', queue.failed || 0, 'warning'));
    parts.push(badge('已注册 Handler', queue.handlers || 0, 'secondary'));

    parts.push('<div class="mt-2 mb-1 fw-semibold small text-muted">队列进程快照</div>');
    if (monitor.available) {
        parts.push(badge('listen', monitor.listen_running ? '运行中' : '未运行', monitor.listen_running ? 'success' : 'secondary'));
        parts.push(badge('work', monitor.work_running ? '运行中' : '未运行', monitor.work_running ? 'success' : 'secondary'));
        parts.push(badge('采集时间', monitor.last_check_text || '-', 'secondary'));
    } else {
        parts.push('<span class="text-muted small">快照未生成：请执行 <code>php think async-task health</code>（进程探测仅允许在 CLI 运行）</span>');
    }

    $box.html(parts.join(''));
}

async function loadHealth() {
    try {
        const res = await request('GET', '/admin/async_task/health');
        renderHealth(res.ok ? res.data : null);
    } catch (e) {
        renderHealth(null);
    }
}

// ============================================================
// 操作
// ============================================================

async function confirmDialog(message, title) {
    const dialog = ui().dialog;

    if (dialog && typeof dialog.confirm === 'function') {
        return dialog.confirm(message, { title: title || '确认操作' });
    }

    return window.confirm(message);
}

async function showDetail(row) {
    const res = await request('GET', `/admin/async_task/detail?id=${encodeURIComponent(row.id)}`);

    if (!res.ok) {
        toast().error(res.msg || '获取详情失败');
        return;
    }

    const task = res.data || {};
    const lines = [
        ['任务号', task.task_no],
        ['名称', task.name],
        ['回调标识', task.handler],
        ['队列', `${task.connection || '-'} / ${task.queue || '-'}`],
        ['状态', task.status_text],
        ['进度', task.progress_percent < 0 ? '进行中（总数未知）' : `${task.progress_percent}%（${task.done}/${task.total}）`],
        ['执行进程 PID', task.pid || '-'],
        ['创建时间', task.create_time || '-'],
        ['开始时间', task.started_at || '-'],
        ['结束时间', task.finished_at || '-'],
        ['心跳时间', task.heartbeat_at || '-'],
        ['消息', task.message || '-'],
        ['错误', task.error || '-'],
        ['载荷', JSON.stringify(task.payload || {}, null, 2)],
        ['结果', task.result ? JSON.stringify(task.result, null, 2) : '-'],
    ];

    const html = lines
        .map(([label, value]) => (
            `<div class="mb-2"><div class="text-muted small">${esc(label)}</div>` +
            `<pre class="mb-0 small bg-light p-2" style="white-space:pre-wrap;word-break:break-all;">${esc(value)}</pre></div>`
        ))
        .join('');

    const dialog = ui().dialog;

    if (dialog && typeof dialog.open === 'function') {
        dialog.open(`<div style="max-height:60vh;overflow:auto;">${html}</div>`, `任务详情 #${task.task_no || row.id}`);
        return;
    }

    toast().info(`任务 ${task.task_no}：${task.status_text}`);
}

async function doCancel(row) {
    const confirmed = await confirmDialog(`确定取消任务「${row.name || row.handler}」？任务将在下一个检查点停止。`, '取消任务');
    if (!confirmed) return;

    const res = await request('POST', '/admin/async_task/cancel', { id: row.id });

    if (res.ok) {
        toast().success(res.msg || '已提交取消请求');
        refresh();
    } else {
        toast().error(res.msg || '取消失败');
    }
}

async function doRetry(row) {
    const confirmed = await confirmDialog('确定以相同载荷重新投递该任务？', '重试任务');
    if (!confirmed) return;

    const res = await request('POST', '/admin/async_task/retry', { id: row.id });

    if (res.ok) {
        toast().success(res.msg || '已重新投递');
        refresh();
    } else {
        toast().error(res.msg || '重试失败');
    }
}

async function doDelete(row) {
    const confirmed = await confirmDialog('确定删除该任务记录？（不影响队列中已结束的消息）', '删除任务记录');
    if (!confirmed) return;

    const res = await request('DELETE', '/admin/async_task/delete', { ids: [row.id] });

    if (res.ok) {
        toast().success(res.msg || '删除成功');
        refresh();
    } else {
        toast().error(res.msg || '删除失败');
    }
}

async function doClear() {
    const confirmed = await confirmDialog('确定清理 7 天前已结束的任务记录？', '清理历史任务');
    if (!confirmed) return;

    const res = await request('POST', '/admin/async_task/clear', { days: 7 });

    if (res.ok) {
        toast().success(res.msg || '清理完成');
        refresh();
    } else {
        toast().error(res.msg || '清理失败');
    }
}

// ============================================================
// 页面控制器
// ============================================================

let listInstance = null;

function refresh() {
    const tableApi = wise().table;

    if (tableApi && typeof tableApi.refresh === 'function' && listInstance) {
        tableApi.refresh(listInstance);
    }
}

function schedulePoll(rows) {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    const hasActive = (rows || []).some((row) => row && row.active);

    if (!hasActive) {
        return;
    }

    pollTimer = setTimeout(() => {
        pollTimer = null;
        refresh();
    }, POLL_INTERVAL);
}

const Controller = {
    async index() {
        const tableApi = wise().table;

        if (!tableApi || typeof tableApi.create !== 'function') {
            toast().error('表格内核未就绪，请刷新页面重试');
            return;
        }

        loadHealth();

        listInstance = await tableApi.create({
            idTable: 'table',
            // 小写动作名：与权限节点声明保持一致（见迁移内注释）
            url: '/admin/async_task/getlist',
            pageSize: 20,
            sortName: 'id',
            sortOrder: 'desc',
            // admin 端约定：data.list + data.total + data.page（插件端才是 data.rows）
            dataDataField: 'data.list',
            dataTotalField: 'data.total',
            dataPageNumber: 'data.page',
            showColumns: true,
            checkboxHeader: false,

            // 自定义 queryParams（内核仅在未提供 queryParams 时才套用它自己的适配）
            queryParams(params) {
                const limit = Number(params.limit) > 0 ? Number(params.limit) : 20;

                return {
                    page: Math.floor((params.offset || 0) / limit) + 1,
                    limit: limit,
                    status: $('#filter-status').val() || '',
                    keyword: ($('#filter-keyword').val() || '').trim(),
                    sort: params.sort,
                    order: params.order,
                };
            },

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'task_no', title: '任务号', width: 200 },
                { field: 'name', title: '名称', width: 180 },
                { field: 'handler', title: '回调标识', width: 160 },
                { field: 'status', title: '状态', width: 90, align: 'center', formatter: (v, row) => renderStatus(row) },
                { field: 'progress', title: '进度', width: 170, formatter: (v, row) => renderProgress(row) },
                { field: 'message', title: '消息', formatter: (v, row) => renderMessage(row) },
                { field: 'create_time', title: '创建时间', width: 160 },
                { field: 'operate', title: '操作', width: 150, align: 'center', formatter: (v, row) => renderOperate(row) },
            ],

            toolbarBtn: [
                {
                    id: 'btn-async-task-refresh',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-info mx-1',
                    click: () => {
                        loadHealth();
                        refresh();
                    },
                },
                {
                    id: 'btn-async-task-health',
                    name: 'async-task-health',
                    title: '刷新队列健康',
                    icon: 'bi bi-heart-pulse',
                    class: 'btn btn-sm btn-outline-secondary mx-1',
                    click: () => loadHealth(),
                },
                {
                    id: 'btn-async-task-clear',
                    name: 'async-task-clear',
                    title: '清理历史任务',
                    icon: 'bi bi-trash3',
                    class: 'btn btn-sm btn-outline-danger mx-1',
                    click: () => doClear(),
                },
            ],

            onClick(action, row) {
                if (action === 'detail') return showDetail(row);
                if (action === 'cancel') return doCancel(row);
                if (action === 'retry') return doRetry(row);
                if (action === 'del') return doDelete(row);
            },

            onResponse(res) {
                const rows = (res && res.data && res.data.list) || [];
                schedulePoll(rows);
            },
        });

        if (!listInstance) {
            toast().error('表格初始化失败，请刷新页面重试');
            return;
        }

        // 筛选控件变更即刷新（输入框防抖）
        let keywordTimer = null;
        $('#filter-status').on('change', () => refresh());
        $('#filter-keyword').on('input', () => {
            if (keywordTimer) clearTimeout(keywordTimer);
            keywordTimer = setTimeout(() => refresh(), 400);
        });

        // .ws-filter-bar 契约（UIS v1.1 §3.5）：显式「查询 / 重置」，表单绝不整页提交
        $('#ws-filter').on('submit', (e) => {
            e.preventDefault();
            refresh();
        });
        $('#ws-filter .ws-filter-submit').on('click', () => refresh());
        $('#ws-filter .ws-filter-reset').on('click', () => {
            $('#filter-status').val('');
            $('#filter-keyword').val('');
            refresh();
        });
    },
};

export default Controller;
