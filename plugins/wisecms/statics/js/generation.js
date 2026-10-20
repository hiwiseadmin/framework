/**
 * AI 生成页面控制器（UIUX-P0-26 列表 + P0-37 自然语言结果卡片 + P0-38 批量结果表 + P0-39 取消）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/generation`
 * （`GenerationController` → snake_case `generation`）。三个动作（index/natural/batch）共用本模块。
 *
 * 对应控制器动作：
 *   generation/index   → 列表（标准 paginate 契约；**后端读 `page`+`limit`**）
 *   generation/natural → 自然语言生成（**同步**，返回生成结果）
 *   generation/batch   → 批量生成（**同步**，返回 `{task_id,total,results}`）
 *   generation/task    → 任务快照（只读）
 *   generation/cancel  → 取消（仅对 pending/running 有效）
 *
 * ⚠️ 一个「看起来异步、实际同步」的关键事实（§1.1㉕）：
 *   `GenerationEngine::generateNatural()` / `generateBatch()` 都是**同步**实现 ——
 *   `createTask()`（status=running）→ **同步循环/单次调 AI** → `finishTask()`（status=completed）
 *   → 直接返回结果。**没有任务队列、没有 task_id 可轮询的进度端点**。
 *
 *   后果：
 *   1. **「进度条 + 轮询」做不了**：`task` 接口虽然存在，但拿到的几乎永远是 `completed`。
 *      → 与 spider / rebuild / 死链同款决策：**不做假进度条**，提交后进全局 loading，
 *        完成后用后端返回的结果直接渲染（natural 结果卡片 / batch 结果表）。
 *   2. **`cancel` 对同步任务无实际意义**：任务在请求返回前就已 `completed`；
 *      cancel 只对**异常残留**的 `running`/`pending` 任务有效（例如 AI 调用抛异常导致
 *      任务卡在 running、批量请求中途 PHP 超时等）→ 列表里**仅这些状态显示取消按钮**。
 *   3. **失败会残留 `running`**：`executeWithAI()` 抛异常时 `finishTask()` 不会执行，
 *      任务不会被置 `failed` —— 因此 `running` 徽章实际含义是「执行中 / 异常残留」二选一。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsEscape, cmsOperateButtons,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('generation/index'),
    natural: cmsUrl('generation/natural'),
    batch: cmsUrl('generation/batch'),
    task: cmsUrl('generation/task'),
    cancel: cmsUrl('generation/cancel'),
    contentEdit: cmsUrl('content/edit'),
};

const MODE_MAP = {
    natural: '自然语言', template: '模板', batch: '批量', rewrite: '改写', continue: '续写',
};
const STATUS_MAP = {
    pending: '待处理', running: '运行中', completed: '已完成', cancelled: '已取消', failed: '失败',
};
const STATUS_CLS = {
    pending: 'warning', running: 'info', completed: 'success', cancelled: 'secondary', failed: 'danger',
};

/** 提交后拿完整信封（`interceptor:false` → resolve `{code,msg,data}`）+ 全局 loading 防重复提交 */
function postSync(url, data) {
    const wise = window.Wise || {};
    if (!(wise.util && wise.util.http && typeof wise.util.http.post === 'function')) {
        cmsToast('请求组件未就绪', 'error');
        return Promise.resolve(null);
    }
    return wise.util.http.post(url, data, { interceptor: false, loading: true });
}

/** 模式徽章 */
function modeCell(value) {
    const v = String(value || '');
    return '<span class="badge bg-light text-dark">' + cmsEscape(MODE_MAP[v] || v || '—') + '</span>';
}

/** 状态徽章（running 是「执行中 / 异常残留」二选一） */
function statusCell(value) {
    const v = String(value || '');
    const cls = STATUS_CLS[v] || 'light';
    const text = STATUS_MAP[v] || v || '—';
    const extra = v === 'running' ? ' title="执行中或异常残留（同步任务失败不会置 failed）"' : '';
    return '<span class="badge bg-' + cls + '"' + extra + '>' + cmsEscape(text) + '</span>';
}

/** 进度：只有批量任务有 total_count；natural 等单篇任务 total_count=0 → 显示 — */
function progressCell(value, row) {
    const total = Number(row.total_count) || 0;
    if (total <= 0) return '<span class="text-muted">—</span>';
    const done = Number(row.completed_count) || 0;
    return '<span>' + done + ' / ' + total + '</span>';
}

/** 任务详情：读 `task` 接口，弹层展示关键字段（不再裸 JSON） */
function showDetail(row) {
    const wise = window.Wise || {};
    const http = wise.util && wise.util.http;
    if (!http || typeof http.get !== 'function') {
        cmsToast('请求组件未就绪', 'error');
        return;
    }

    http.get(URL.task, { id: row.id }, { interceptor: true, loading: true })
        .then((payload) => {
            if (!payload) {
                cmsToast('任务不存在', 'warning');
                return;
            }
            const params = (() => {
                try { return JSON.parse(payload.params || '{}'); } catch (e) { return {}; }
            })();
            const progress = (() => {
                try { return JSON.parse(payload.progress || '{}'); } catch (e) { return {}; }
            })();

            const rows = [
                ['ID', payload.id],
                ['任务名称', payload.name],
                ['模式', MODE_MAP[payload.mode] || payload.mode],
                ['状态', STATUS_MAP[payload.status] || payload.status],
                ['进度', (Number(payload.total_count) > 0)
                    ? (payload.completed_count + ' / ' + payload.total_count)
                    : '—'],
                ['开始时间', payload.start_time || '—'],
                ['完成时间', payload.finish_time || '—'],
            ];
            const paramsText = Object.keys(params).length
                ? JSON.stringify(params, null, 2)
                : '';
            const progressText = (progress && Object.keys(progress).length)
                ? JSON.stringify(progress, null, 2)
                : '';

            let html = '<table class="table table-sm mb-2">'
                + rows.map((r) => '<tr><td class="text-muted text-nowrap pe-3">' + cmsEscape(r[0])
                    + '</td><td>' + cmsEscape(String(r[1] === null || r[1] === undefined ? '' : r[1]))
                    + '</td></tr>').join('')
                + '</table>';

            if (payload.error) {
                html += '<div class="alert alert-danger py-2 mb-2"><b>错误：</b>'
                    + cmsEscape(String(payload.error)) + '</div>';
            }
            if (paramsText) {
                html += '<div class="small text-muted mb-1">生成参数</div>'
                    + '<pre class="small bg-light p-2">' + cmsEscape(paramsText) + '</pre>';
            }
            if (progressText) {
                html += '<div class="small text-muted mb-1">进度快照</div>'
                    + '<pre class="small bg-light p-2">' + cmsEscape(progressText) + '</pre>';
            }

            wise.ui.dialog.open(html, '生成任务详情', { area: ['640px', '560px'], resize: true });
        })
        .catch(() => cmsToast('获取任务详情失败', 'error'));
}

/** 取消任务（仅 running/pending 显示；清理异常残留） */
function cancelTask(row) {
    const wise = window.Wise || {};
    return wise.ui.dialog.confirm(
        '确定取消该任务？<br><span class="small text-muted">'
        + '同步生成任务在提交时即已完成，此操作主要用于清理「运行中 / 待处理」的异常残留记录。</span>',
        { title: '取消生成任务' }
    ).then((ok) => {
        if (!ok) return undefined;
        return postSync(URL.cancel, { id: row.id }).then((body) => {
            if (body && body.code === 0) {
                cmsToast('任务已取消', 'success');
                cmsRefresh(listRef());
            } else {
                cmsToast((body && body.msg) || '取消失败', 'error');
            }
        });
    });
}

/** 列表引用（供取消回调刷新） */
let listRef = () => null;

export default {
    /** 生成工作台列表（P0-26 + P0-39） */
    index() {
        const list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端读 page+limit（默认 15）→ 不固定页长
            pageSize: 15,

            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', formatter: 'normal' },
                { field: 'name', title: '任务名称', width: 200, align: 'left', formatter: 'normal' },
                { field: 'mode', title: '模式', width: 100, align: 'center', formatter: modeCell },
                { field: 'total_count', title: '进度', width: 100, align: 'center', formatter: progressCell },
                { field: 'status', title: '状态', width: 90, align: 'center', formatter: statusCell },
                { field: 'create_time', title: '创建时间', width: 165, align: 'center', visible: false, formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 150,
                    align: 'center',
                    formatter: (value, row) => cmsOperateButtons([
                        { action: 'view', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-secondary' },
                        {
                            action: 'cancel',
                            title: '取消',
                            icon: 'bi bi-x-circle',
                            class: 'btn-outline-warning',
                            show: (r) => r.status === 'running' || r.status === 'pending',
                        },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-gen-natural',
                    name: 'natural',
                    title: '自然语言生成',
                    icon: 'bi bi-magic',
                    class: 'btn btn-sm btn-primary',
                    click: () => { window.location.href = URL.natural; },
                },
                {
                    id: 'btn-gen-batch',
                    name: 'batch',
                    title: '批量生成',
                    icon: 'bi bi-collection',
                    class: 'btn btn-sm btn-success',
                    click: () => { window.location.href = URL.batch; },
                },
                {
                    id: 'btn-refresh-generation',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'view') return showDetail(row);
                if (action === 'cancel') return cancelTask(row);
                return undefined;
            },
        });

        listRef = () => list;
        return list;
    },

    /** 自然语言生成（P0-37：结果卡片替换裸 JSON） */
    natural() {
        const $form = $('#naturalForm');
        if (!$form.length) return;

        const $result = $('#resultBox');
        const $topic = $form.find('input[name="topic"]');

        function renderSuccess(data) {
            const title = cmsEscape(String(data.title || ''));
            $result.html(
                '<div class="card border-success">'
                + '<div class="card-body">'
                + '<div class="text-success fw-bold mb-1">'
                + '<i class="bi bi-check-circle me-1"></i>已生成「' + title + '」</div>'
                + '<div class="small text-muted mb-2">内容已按审核规则入库为草稿，可前往编辑或发布。</div>'
                + '<a href="' + URL.contentEdit + '?id=' + encodeURIComponent(data.content_id)
                + '" class="btn btn-sm btn-primary me-1"><i class="bi bi-pencil me-1"></i>去编辑 / 发布</a>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-gen-again">'
                + '<i class="bi bi-arrow-repeat me-1"></i>重新生成</button>'
                + '</div></div>'
            );
            $('#btn-gen-again').on('click', () => {
                $result.empty();
                $topic.trigger('focus');
            });
        }

        function renderEmpty(data) {
            const note = cmsEscape(String((data && data.note) || 'AI 生成结果'));
            $result.html(
                '<div class="alert alert-warning mb-0">'
                + '<i class="bi bi-exclamation-triangle me-1"></i>'
                + '未生成内容：' + note + '。请确认已安装并启用 <code>wise-agent</code> 插件后重试。'
                + '</div>'
            );
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const topic = String($topic.val() || '').trim();
            if (!topic) {
                cmsToast('请填写主题', 'warning');
                $topic.trigger('focus');
                return;
            }

            $result.empty();
            const body = await postSync(URL.natural, new FormData(this));
            if (!body) return;

            if (body.code === 0 && body.data && body.data.content_id) {
                renderSuccess(body.data);
            } else if (body.code === 0) {
                renderEmpty(body.data);
            } else {
                $result.html('<div class="alert alert-danger mb-0">'
                    + cmsEscape((body && body.msg) || '生成失败') + '</div>');
            }
        });
    },

    /** 批量生成（P0-38：结果表替换裸 JSON） */
    batch() {
        const $form = $('#batchForm');
        if (!$form.length) return;

        const $result = $('#resultBox');
        const $keywords = $form.find('textarea[name="keywords"]');

        function renderResults(data) {
            const results = Array.isArray(data && data.results) ? data.results : [];
            const okList = results.filter((r) => r && r.content_id);
            const failList = results.filter((r) => r && r.error);

            let html = '<div class="card">'
                + '<div class="card-header">生成结果'
                + '<span class="badge bg-success ms-1">成功 ' + okList.length + '</span> '
                + '<span class="badge bg-danger">失败 ' + failList.length + '</span>'
                + '</div><div class="card-body p-0"><table class="table table-sm table-hover mb-0">'
                + '<thead><tr><th style="width:48px">#</th><th>关键词 / 标题</th><th style="width:150px">操作</th></tr></thead><tbody>';

            if (!results.length) {
                html += '<tr><td colspan="3" class="text-center text-muted py-3">无结果</td></tr>';
            } else {
                results.forEach((r, i) => {
                    html += '<tr>';
                    html += '<td class="text-muted">' + (i + 1) + '</td>';
                    if (r && r.content_id) {
                        html += '<td>' + cmsEscape(String(r.title || r.keyword || '')) + '</td>';
                        html += '<td><a href="' + URL.contentEdit + '?id=' + encodeURIComponent(r.content_id)
                            + '" class="btn btn-xs btn-outline-primary">去编辑</a></td>';
                    } else {
                        html += '<td><span class="text-muted">' + cmsEscape(String(r.keyword || ''))
                            + '</span></td>';
                        html += '<td><span class="badge bg-danger" title="'
                            + cmsEscape(String(r.error || '')) + '">失败</span></td>';
                    }
                    html += '</tr>';
                });
            }

            html += '</tbody></table></div></div>';

            // 失败明细（可折叠展示失败原因，不做假重试入口 —— 无重试接口）
            if (failList.length) {
                html += '<div class="small text-muted mt-2">'
                    + '<i class="bi bi-exclamation-circle me-1"></i>'
                    + failList.length + ' 条失败：'
                    + failList.map((r) => cmsEscape(String(r.keyword || ''))).join('、')
                    + '。可修改关键词后重新提交。</div>';
            }

            $result.html(html);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            const keywords = String($keywords.val() || '').trim();
            if (!keywords) {
                cmsToast('请填写关键词（每行一个）', 'warning');
                $keywords.trigger('focus');
                return;
            }

            $result.empty();
            const body = await postSync(URL.batch, new FormData(this));
            if (!body) return;

            if (body.code === 0) {
                renderResults(body.data || {});
            } else {
                $result.html('<div class="alert alert-danger mb-0">'
                    + cmsEscape((body && body.msg) || '批量生成失败') + '</div>');
            }
        });
    },
};
