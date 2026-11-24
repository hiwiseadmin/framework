/**
 * 下载中心（P0-1b）
 *
 * 数据源：/admin/download/getList（async_tasks 中 handler=export:list 且 created_by=本人）
 * 下载：GET /admin/download/file?id=…（后端校验归属与过期）
 */
const STATUS_BADGE = {
    pending: 'secondary',
    running: 'info',
    success: 'success',
    failed: 'danger',
    canceled: 'warning',
    stale: 'dark',
};

/** 状态徽标 */
function statusFormatter(_value, row) {
    const color = STATUS_BADGE[row.status] || 'secondary';
    return `<span class="badge bg-${color}">${row.status_text || row.status}</span>`;
}

/** 进度列：仅执行中显示百分比 */
function progressFormatter(value, row) {
    if (row.status === 'running') {
        return `${value || 0}%`;
    }
    if (row.status === 'failed' && row.error) {
        return `<span class="text-danger" title="${row.error}">失败</span>`;
    }
    return '-';
}

/** 操作列 */
function operateFormatter(_value, row) {
    const buttons = [];

    if (row.can_download) {
        buttons.push({ action: 'download', title: '下载', icon: 'bi bi-download', class: 'btn-outline-primary' });
    }

    buttons.push({ action: 'del', title: '删除', icon: 'bi bi-trash3', class: 'btn-outline-danger' });

    return window.Wise.table.operateButtons(buttons, row);
}

const Controller = {
    async index() {
        const canClear = !!(window.Wise
            && window.Wise.permission
            && window.Wise.permission.can('admin.download.clear'));

        const toolbarBtn = ['refresh'];
        if (canClear) {
            toolbarBtn.push({
                name: 'clear-expired',
                title: '清理过期',
                icon: 'bi bi-trash3',
                variant: 'outline-secondary',
                group: 'tools',
                order: 45,
                click() {
                    window.Wise.ui.action.execute({
                        url: '/admin/download/clear',
                        method: 'POST',
                        confirmMessage: '确定清理本人已过期的导出文件？',
                        successMsg: '清理完成',
                        target: null,
                    }).then(() => window.Wise.table.refresh(instance));
                },
            });
        }

        const instance = await window.Wise.table.create({
            idTable: 'table',
            url: '/admin/download/getList',
            dataDataField: 'data.list',
            dataTotalField: 'data.total',
            pageSize: 20,
            showColumns: true,
            toolbarBtn,
            queryParams(params) {
                const limit = params.limit > 0 ? params.limit : 20;
                const statusEl = document.getElementById('filter-status');

                return {
                    page: Math.floor((params.offset || 0) / limit) + 1,
                    limit,
                    status: statusEl ? statusEl.value : '',
                };
            },
            columns: [
                { field: 'file', title: '文件名', formatter: (v) => v || '<span class="text-muted">-</span>' },
                { field: 'size_text', title: '大小', width: '100' },
                { field: 'rows', title: '行数', width: '100' },
                { field: 'status_text', title: '状态', width: '110', formatter: statusFormatter },
                { field: 'progress', title: '进度', width: '110', formatter: progressFormatter },
                { field: 'create_time', title: '提交时间', width: '170' },
                {
                    field: 'expire_at',
                    title: '过期时间',
                    width: '170',
                    formatter: (v, row) => (row.expired
                        ? '<span class="text-danger">已过期</span>'
                        : (v || '-')),
                },
                { title: '操作', width: '130', formatter: operateFormatter },
            ],
            onResponse(res) {
                // 有「等待中 / 执行中」任务时继续轮询，完成后自动停在最新状态
                if (window.__wsDownloadPoll) {
                    window.__wsDownloadPoll(instance);
                }
                return res;
            },
            onClick(action, row) {
                if (action === 'download') {
                    window.location.href = '/admin/download/file?id=' + encodeURIComponent(row.id);
                    return;
                }

                if (action === 'del') {
                    window.Wise.ui.action.execute({
                        url: '/admin/download/delete',
                        method: 'POST',
                        data: { id: row.id },
                        confirmMessage: '删除后文件不可恢复，确定删除？',
                        successMsg: '已删除',
                        target: null,
                    }).then(() => window.Wise.table.refresh(instance));
                }
            },
        });

        // 筛选条：查询 / 重置
        const submit = document.querySelector('.ws-filter-submit');
        if (submit) {
            submit.addEventListener('click', () => window.Wise.table.refresh(instance));
        }

        const reset = document.querySelector('.ws-filter-reset');
        if (reset) {
            reset.addEventListener('click', () => {
                const statusEl = document.getElementById('filter-status');
                if (statusEl) statusEl.value = '';
                window.Wise.table.refresh(instance);
            });
        }

        return instance;
    },
};

export default Controller;
