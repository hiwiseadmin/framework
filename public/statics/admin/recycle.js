/**
 * 回收站（P0-2A）
 *
 * 交互约定：
 *   - 还原：无需确认（可再删回来），批量执行；
 *   - 彻底删除 / 清空：先向 issueToken 签发一次性确认令牌，再携带令牌提交，
 *     后端校验（DoD 硬性），前端弹窗只是第一道提醒。
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

/** 签发确认令牌并执行破坏性动作 */
async function withConfirmToken(scene, model, action) {
    const data = await request('/admin/recycle/issueToken', { scene, model });
    const token = (data && data.confirm_token) || '';

    if (!token) {
        throw new Error('确认令牌签发失败');
    }

    return action(token);
}

const Controller = {
    async index() {
        const $ = window.jQuery;
        const state = { model: '', table: null };

        // 加载模型下拉
        try {
            const models = await request('/admin/recycle/getModels');
            const select = document.getElementById('filter-model');
            select.innerHTML = '<option value="">全部模块</option>';
            (models || []).forEach((m) => {
                const opt = document.createElement('option');
                opt.value = m.model;
                opt.textContent = m.group ? `${m.group} / ${m.model}` : m.model;
                select.appendChild(opt);
            });

            if (models && models.length) {
                select.value = models[0].model;
                state.model = models[0].model;
            }
        } catch (e) {
            // 加载失败保持空下拉
        }

        // 表格
        state.table = await window.Wise.table.create({
            idTable: 'table',
            url: '/admin/recycle/getList',
            dataDataField: 'data.list',
            dataTotalField: 'data.total',
            pageSize: 20,
            checkboxHeader: true,
            maintainSelected: true,
            showColumns: true,
            toolbarBtn: [
                {
                    name: 'restore',
                    title: '还原',
                    icon: 'bi bi-arrow-counterclockwise',
                    variant: 'outline-primary',
                    group: 'actions',
                    order: 20,
                    click: () => Controller.batchRestore(state),
                },
                {
                    name: 'force-delete',
                    title: '彻底删除',
                    icon: 'bi bi-trash3',
                    variant: 'outline-danger',
                    group: 'actions',
                    order: 30,
                    click: () => Controller.batchForceDelete(state),
                },
                {
                    name: 'clear',
                    title: '清空该模块',
                    icon: 'bi bi-bucket',
                    variant: 'outline-danger',
                    group: 'actions',
                    order: 40,
                    click: () => Controller.clearModel(state),
                },
                'refresh',
            ],
            queryParams(params) {
                const limit = params.limit > 0 ? params.limit : 20;
                const model = document.getElementById('filter-model');
                const keyword = document.getElementById('filter-keyword');

                return {
                    page: Math.floor((params.offset || 0) / limit) + 1,
                    limit,
                    model: model ? model.value : '',
                    keyword: keyword ? keyword.value : '',
                };
            },
            columns: [
                { field: 'title', title: '标题' },
                { field: 'delete_time', title: '删除时间', width: '170' },
                {
                    title: '操作',
                    width: '150',
                    formatter: (_v, row) => window.Wise.table.operateButtons([
                        { action: 'restore', title: '还原', icon: 'bi bi-arrow-counterclockwise', class: 'btn-outline-primary' },
                        { action: 'force-delete', title: '彻底删除', icon: 'bi bi-trash3', class: 'btn-outline-danger' },
                    ], row),
                },
            ],
            onClick(action, row) {
                if (action === 'restore') {
                    Controller.restore(state, [row.id]);
                } else if (action === 'force-delete') {
                    Controller.forceDelete(state, [row.id]);
                }
            },
        });

        // 筛选
        const submit = document.querySelector('.ws-filter-submit');
        if (submit) {
            submit.addEventListener('click', () => {
                state.model = $('#filter-model').val() || '';
                window.Wise.table.refresh(state.table);
            });
        }

        const reset = document.querySelector('.ws-filter-reset');
        if (reset) {
            reset.addEventListener('click', () => {
                $('#filter-model').val('');
                $('#filter-keyword').val('');
                state.model = '';
                window.Wise.table.refresh(state.table);
            });
        }

        return state.table;
    },

    selectedIds(state) {
        const inst = state.table;
        if (!inst || typeof inst.getSelectedIds !== 'function') return [];
        return inst.getSelectedIds().filter((id) => id != null && id !== '');
    },

    currentModel() {
        const el = document.getElementById('filter-model');
        return el ? el.value : '';
    },

    async restore(state, ids) {
        const model = state.model || Controller.currentModel();
        if (!model) {
            window.Wise.ui.toast.error('请先选择模块');
            return;
        }
        if (!ids.length) {
            window.Wise.ui.toast.error('请选择要还原的记录');
            return;
        }

        try {
            const res = await request('/admin/recycle/restore', { model, ids });
            window.Wise.ui.toast.success(res && res.message ? res.message : '已还原');
            window.Wise.table.refresh(state.table);
        } catch (e) {
            window.Wise.ui.toast.error(e.message);
        }
    },

    async batchRestore(state) {
        await Controller.restore(state, Controller.selectedIds(state));
    },

    async forceDelete(state, ids) {
        const model = state.model || Controller.currentModel();
        if (!model) {
            window.Wise.ui.toast.error('请先选择模块');
            return;
        }
        if (!ids.length) {
            window.Wise.ui.toast.error('请选择要彻底删除的记录');
            return;
        }

        const confirmed = await window.Wise.ui.dialog.confirm('彻底删除后不可恢复，确定继续？');
        if (!confirmed) return;

        try {
            await withConfirmToken('recycle.force_delete', model, (token) => request('/admin/recycle/forceDelete', { model, ids, confirm_token: token }));
            window.Wise.ui.toast.success('已彻底删除');
            window.Wise.table.refresh(state.table);
        } catch (e) {
            window.Wise.ui.toast.error(e.message);
        }
    },

    async batchForceDelete(state) {
        await Controller.forceDelete(state, Controller.selectedIds(state));
    },

    async clearModel(state) {
        const model = state.model || Controller.currentModel();
        if (!model) {
            window.Wise.ui.toast.error('请先选择要清空的模块');
            return;
        }

        const confirmed = await window.Wise.ui.dialog.confirm(`确定清空「${model}」的所有已删除记录？此操作不可恢复`);
        if (!confirmed) return;

        try {
            await withConfirmToken('recycle.clear', model, (token) => request('/admin/recycle/clear', { model, confirm_token: token }));
            window.Wise.ui.toast.success('已清空');
            window.Wise.table.refresh(state.table);
        } catch (e) {
            window.Wise.ui.toast.error(e.message);
        }
    },
};

export default Controller;
