/**
 * 变更记录（P0-2B 字段级 diff）
 *
 * 交互约定：
 *   - 列表按「一次保存」聚合（change_set），一行 = 某人某时改了某条记录的若干字段；
 *   - 时间线**只从操作列按钮进入**（不做整行点击，避免勾选/复制/误触弹出面板）
 *     → 下方时间线面板（按 change_set 倒序），字段级 旧值 → 新值，长文本走行级 diff；
 *   - 回滚：先签发一次性确认令牌，再携带令牌提交，后端校验（DoD 硬性）；
 *     回滚成功会再写一条 action=rollback 的变更记录，形成可追溯链。
 */

/** 读 CSRF Token */
function csrfToken() {
    const m = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]+)/);
    if (m) {
        try {
            return decodeURIComponent(m[1]);
        } catch (e) {
            return m[1];
        }
    }
    return '';
}

/** 统一请求（POST，JSON，返回 res.data） */
function request(url, data = {}) {
    return new Promise((resolve, reject) => {
        window.jQuery.ajax({
            url,
            type: 'POST',
            data,
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
            success(res) {
                if (res && res.code === 0) {
                    resolve(res.data || {});
                } else {
                    reject(new Error((res && res.msg) || '操作失败'));
                }
            },
            error() {
                reject(new Error('网络错误或权限不足'));
            },
        });
    });
}

/** HTML 转义（纯文本字段用；行级 diff HTML 由后端转义） */
function esc(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const ACTION_LABEL = {
    update: { text: '修改', class: 'text-bg-primary' },
    create: { text: '新增', class: 'text-bg-success' },
    rollback: { text: '回滚', class: 'text-bg-danger' },
};

function actionBadge(action) {
    const item = ACTION_LABEL[action] || { text: action, class: 'text-bg-secondary' };
    return `<span class="badge ${item.class}">${esc(item.text)}</span>`;
}

/** 单字段变更渲染 */
function renderChange(change) {
    const truncated = change.truncated
        ? '<span class="badge text-bg-warning ms-1" title="当时超过 4KB 已截断，该字段不可回滚">已截断</span>'
        : '';

    if (change.html) {
        return `<tr>
            <td class="text-nowrap">${esc(change.label)}${truncated}</td>
            <td colspan="2"><div class="diff-view">${change.html}</div></td>
        </tr>`;
    }

    const empty = '<span class="text-muted">（空）</span>';

    return `<tr>
        <td class="text-nowrap">${esc(change.label)}${truncated}</td>
        <td><div class="cl-value cl-change-old">${esc(change.old) || empty}</div></td>
        <td><div class="cl-value cl-change-new">${esc(change.new) || empty}</div></td>
    </tr>`;
}

const Controller = {
    async index() {
        const state = { table: null, current: null };

        // 模型下拉
        try {
            const models = await request('/admin/change_log/getModels');
            const select = document.getElementById('filter-model');
            select.innerHTML = '<option value="">全部模型</option>';
            (models || []).forEach((m) => {
                const opt = document.createElement('option');
                opt.value = m.model;
                opt.textContent = `${m.label}（${m.count}）`;
                select.appendChild(opt);
            });
        } catch (e) {
            // 保持空下拉
        }

        state.table = await window.Wise.table.create({
            idTable: 'table',
            url: '/admin/change_log/getList',
            dataDataField: 'data.list',
            dataTotalField: 'data.total',
            pageSize: 20,
            showColumns: true,
            toolbarBtn: ['refresh'],
            queryParams(params) {
                const limit = params.limit > 0 ? params.limit : 20;
                const pick = (id) => {
                    const el = document.getElementById(id);
                    return el ? el.value : '';
                };

                return {
                    page: Math.floor((params.offset || 0) / limit) + 1,
                    limit,
                    model: pick('filter-model'),
                    pk_value: pick('filter-pk'),
                    operator: pick('filter-operator'),
                    field: pick('filter-field'),
                };
            },
            columns: [
                { field: 'create_time', title: '变更时间', width: '220' },
                { field: 'model_label', title: '模型', width: '120' },
                {
                    field: 'title',
                    title: '记录',
                    formatter: (_v, row) => `${esc(row.title) || '<span class="text-muted">-</span>'} <span class="text-muted">#${esc(row.pk_value)}</span>`,
                },
                { field: 'fields', title: '变更字段' },
                { field: 'operator_name', title: '操作人', width: '120' },
                {
                    field: 'action',
                    title: '动作',
                    width: '90',
                    formatter: (value) => actionBadge(value),
                },
                {
                    title: '操作',
                    width: '120',
                    // 行点击已取消，这里是唯一入口 → 按钮带可见文字，避免只有一个图标难发现
                    formatter: (_v, row) => window.Wise.table.operateButtons([
                        { action: 'timeline', title: '查看变更时间线', icon: 'bi bi-clock-history', label: '时间线', class: 'btn-outline-primary' },
                    ], row),
                },
            ],
            onClick(action, row) {
                if (action !== 'timeline') {
                    return;
                }
                // 内核若回查不到行会退化成 {id}，此处兜底提示，避免按钮"点了没反应"
                if (!row || !row.model || !row.pk_value) {
                    window.Wise.ui.toast.error('未取到行数据，请刷新后重试');
                    return;
                }
                Controller.openTimeline(state, row.model, row.pk_value, row);
            },
        });

        // 注意：**不做整行点击展开**。列表行会被频繁点击（勾选、复制、误触），
        // 行级点击直接弹时间线误触率过高；只保留操作列的「时间线」按钮作为唯一入口。

        // 筛选
        const submit = document.querySelector('.ws-filter-submit');
        if (submit) {
            submit.addEventListener('click', () => {
                Controller.closeTimeline(state);
                window.Wise.table.refresh(state.table);
            });
        }
        const reset = document.querySelector('.ws-filter-reset');
        if (reset) {
            reset.addEventListener('click', () => {
                ['filter-model', 'filter-pk', 'filter-operator', 'filter-field'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                Controller.closeTimeline(state);
                window.Wise.table.refresh(state.table);
            });
        }

        const closeBtn = document.getElementById('timeline-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => Controller.closeTimeline(state));
        }

        // 供事件委托回滚使用
        window.__WS_CHANGE_LOG_STATE__ = state;

        return state.table;
    },

    async openTimeline(state, model, pkValue, row) {
        const panel = document.getElementById('timeline-panel');
        const body = document.getElementById('timeline-body');
        const title = document.getElementById('timeline-title');
        if (!panel || !body || !title) return;

        state.current = { model, pk_value: pkValue, row };
        panel.classList.remove('d-none');
        body.innerHTML = '<div class="text-muted">加载中…</div>';
        title.textContent = `变更时间线 · ${(row && row.model_label) || model} #${pkValue}`;

        try {
            const groups = await request('/admin/change_log/timeline', { model, pk_value: pkValue });
            body.innerHTML = Controller.renderTimeline(groups);
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (e) {
            body.innerHTML = `<div class="text-danger">${esc(e.message)}</div>`;
        }
    },

    renderTimeline(groups) {
        if (!groups || !groups.length) {
            return '<div class="text-muted">暂无变更记录</div>';
        }

        return `<ul class="cl-timeline">${groups.map((group) => {
            const rows = (group.changes || []).map(renderChange).join('');
            const rollbackBtn = group.action === 'rollback'
                ? ''
                : `<button type="button" class="btn btn-sm btn-outline-danger" data-rollback="${esc(group.change_set)}">回滚到此次之前</button>`;

            return `<li class="${group.action === 'rollback' ? 'is-rollback' : ''}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        ${actionBadge(group.action)}
                        <strong class="ms-2">${esc(group.operator_name) || 'system'}</strong>
                        <span class="text-muted ms-2">${esc(group.create_time)}</span>
                        ${group.route ? `<span class="text-muted ms-2">${esc(group.route)}</span>` : ''}
                    </div>
                    <div>${rollbackBtn}</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th style="width:140px">字段</th><th>变更前</th><th>变更后</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </li>`;
        }).join('')}</ul>`;
    },

    closeTimeline(state) {
        const panel = document.getElementById('timeline-panel');
        if (panel) panel.classList.add('d-none');
        state.current = null;
    },

    async rollback(state, changeSet) {
        const confirmed = await window.Wise.ui.dialog.confirm('回滚会把该次变更涉及的字段恢复为变更前的值，确定继续？');
        if (!confirmed) return;

        try {
            const data = await request('/admin/change_log/issueToken', { change_set: changeSet });
            const token = (data && data.confirm_token) || '';
            if (!token) {
                throw new Error('确认令牌签发失败');
            }

            await request('/admin/change_log/rollback', { change_set: changeSet, confirm_token: token });
            window.Wise.ui.toast.success('已回滚');

            if (state.current) {
                await Controller.openTimeline(state, state.current.model, state.current.pk_value, state.current.row);
            }
            window.Wise.table.refresh(state.table);
        } catch (e) {
            window.Wise.ui.toast.error(e.message);
        }
    },
};

// 时间线面板内的回滚按钮（事件委托，避免内联 onclick）
document.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-rollback]');
    if (!btn) return;

    event.preventDefault();
    const state = window.__WS_CHANGE_LOG_STATE__;
    if (!state) return;

    Controller.rollback(state, btn.getAttribute('data-rollback'));
});

export default Controller;
