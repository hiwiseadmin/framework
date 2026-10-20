/**
 * 内容工作流面板（UIUX-P0-34）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/workflow`
 * （`WorkflowController` → snake_case `workflow`）。作为 iframe 弹层打开：
 * `workflow/panel.html?id={content_id}&title={标题}`，本模块 `panel()` 入口读取并渲染。
 *
 * 对应控制器动作：
 *   workflow/status     → 读工作流快照（当前状态 + 可用转换 + 历史）
 *   workflow/transition → 执行状态转换（POST id + action）
 *   workflow/available  → 仅可用转换列表（本面板未单独用，status 已含 transitions）
 *
 * ⚠️ 四个已核实的事实：
 *   1. **依赖 wise-workflow 包**：`ContentWorkflow::engine()` 在包缺失/表未迁移时返回 null，
 *      `status` 仍返回当前状态（`placeForStatus` 降级映射），但 `transitions`/`history` 为空，
 *      `transition` 会抛「工作流组件未安装」。→ 面板据此显示降级态，不假装可流转。
 *   2. **转换不支持备注**：`Workflow::transition` 只读 `id` + `action`，未读 remark/context。
 *      → P0-34 原文的「驳回/提交必填备注」**后端未实现**，面板不放备注输入框（放了也是死控件）。
 *        若需备注需后端补 `transition` 读 `remark` 并传入 `$context`（已登记，见 §1.1㉖）。
 *   3. **status 不返回内容标题** → 面板标题由调用方经 URL `title` 传入（内容列表/编辑页打开时带上）。
 *   4. **history 结构**：`workflow_history` 行（`transition_name`/`from_place`/`to_place`/`remark`/
 *      `user_id`/`create_time`），倒序。转换/place 的**中文名**前端硬编码一份（与 `ContentWorkflow`
 *      定义同步，后端不把定义下发给前端）。
 *
 * @see statics/js/cms-admin.js
 */

import { cmsUrl, cmsToast, cmsConfirm, cmsEscape } from './cms-admin.js';

const URL = {
    status: cmsUrl('workflow/status'),
    transition: cmsUrl('workflow/transition'),
};

/** 与 `ContentWorkflow::definition()` 的 transition 一一对应（后端不把定义下发前端） */
const TRANSITION_LABEL = {
    submit: '提交审核', approve: '审核通过', publish: '发布', reject: '驳回',
    resubmit: '重新提交', unpublish: '取消发布', archive: '归档', __init__: '初始化',
};
const PLACE_LABEL = {
    draft: '草稿', pending: '待审核', approved: '审核通过',
    published: '已发布', rejected: '已驳回', archived: '已归档',
};

function placeLabel(p) {
    return PLACE_LABEL[p] || p || '—';
}

function httpGet(url, params) {
    const wise = window.Wise || {};
    if (!(wise.util && wise.util.http && typeof wise.util.http.get === 'function')) {
        return Promise.reject(new Error('请求组件未就绪'));
    }
    return wise.util.http.get(url, params, { interceptor: true, loading: true });
}

function httpPost(url, data) {
    const wise = window.Wise || {};
    if (!(wise.util && wise.util.http && typeof wise.util.http.post === 'function')) {
        return Promise.reject(new Error('请求组件未就绪'));
    }
    return wise.util.http.post(url, data, { interceptor: false, loading: true });
}

export default {
    async panel() {
        const qs = new URLSearchParams(window.location.search);
        const contentId = parseInt(qs.get('id'), 10) || 0;
        const title = qs.get('title') || '';

        const $root = $('#workflowPanel');
        if (!$root.length) return;

        if (!contentId) {
            $root.html('<div class="alert alert-danger mb-0">缺少内容 ID，无法加载工作流。</div>');
            return;
        }

        /** 拉取并渲染快照 */
        async function render() {
            let snapshot;
            try {
                snapshot = await httpGet(URL.status, { id: contentId });
            } catch (e) {
                $root.html('<div class="alert alert-danger mb-0">加载工作流状态失败。</div>');
                return;
            }
            if (!snapshot) {
                $root.html('<div class="alert alert-warning mb-0">未获取到工作流状态。</div>');
                return;
            }

            const transitions = Array.isArray(snapshot.transitions) ? snapshot.transitions : [];
            const history = Array.isArray(snapshot.history) ? snapshot.history : [];

            // ── 头部：标题 + 当前状态 ──
            let html = '<div class="d-flex align-items-center justify-content-between mb-3">'
                + '<div><div class="fw-bold">' + cmsEscape(title || ('内容 #' + contentId)) + '</div>'
                + '<div class="small text-muted">ID ' + contentId + '</div></div>'
                + '<span class="badge bg-primary fs-6">'
                + cmsEscape(placeLabel(snapshot.current_state)) + '</span></div>';

            // ── 可用转换 ──
            html += '<div class="mb-3"><div class="text-muted small mb-2">可用操作</div>';
            if (!transitions.length) {
                html += '<div class="alert alert-light border py-2 mb-0 small">'
                    + '<i class="bi bi-info-circle me-1"></i>'
                    + (snapshot.status_label
                        ? '当前状态无可用转换（可能已到终态）。'
                        : '工作流组件未安装或未迁移，暂不支持状态流转。')
                    + '</div>';
            } else {
                html += '<div class="d-flex flex-wrap gap-2">';
                transitions.forEach((t) => {
                    const label = TRANSITION_LABEL[t.action] || t.label || t.action;
                    const danger = t.action === 'reject' ? 'btn-outline-danger' : 'btn-outline-primary';
                    html += '<button type="button" class="btn btn-sm ' + danger + ' cms-wf-transition"'
                        + ' data-action="' + cmsEscape(String(t.action)) + '">'
                        + cmsEscape(label) + '</button>';
                });
                html += '</div>';
            }
            html += '</div>';

            // ── 历史时间线 ──
            html += '<div><div class="text-muted small mb-2">流转历史</div>';
            if (!history.length) {
                html += '<div class="text-muted small">暂无流转记录</div>';
            } else {
                html += '<ul class="list-group list-group-flush">';
                history.forEach((h) => {
                    const name = TRANSITION_LABEL[h.transition_name] || h.transition_name || '—';
                    // P1-08：显示操作人（后端已补 user_name；缺失时降级为「用户 #id」）
                    const operator = h.user_name
                        ? '<span class="me-2"><i class="bi bi-person me-1"></i>' + cmsEscape(String(h.user_name)) + '</span>'
                        : ((h.user_id ? '<span class="me-2 text-muted">用户 #' + cmsEscape(String(h.user_id)) + '</span>' : ''));
                    const remark = h.remark ? ('<div class="small text-muted">' + cmsEscape(String(h.remark)) + '</div>') : '';
                    html += '<li class="list-group-item px-0">'
                        + '<div class="d-flex justify-content-between">'
                        + '<span>' + cmsEscape(name) + '</span>'
                        + '<span class="small text-muted">' + cmsEscape(String(h.create_time || '')) + '</span>'
                        + '</div>'
                        + '<div class="small text-muted">' + operator + cmsEscape(placeLabel(h.from_place))
                        + ' → ' + cmsEscape(placeLabel(h.to_place)) + '</div>'
                        + remark + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';

            $root.html(html);

            // ── 转换按钮绑定 ──
            $root.find('.cms-wf-transition').on('click', function () {
                const action = String($(this).attr('data-action'));
                const label = TRANSITION_LABEL[action] || action;
                cmsConfirm(
                    '确定执行「' + cmsEscape(label) + '」？<br>'
                    + '<span class="small text-muted">状态流转会写入工作流历史，并可能触发审核通知。</span>',
                    '确认' + label
                ).then((ok) => {
                    if (!ok) return;
                    httpPost(URL.transition, { id: contentId, action: action })
                        .then((body) => {
                            if (body && body.code === 0) {
                                cmsToast(body.msg || (label + '成功'), 'success');
                                render();
                            } else {
                                cmsToast((body && body.msg) || (label + '失败'), 'error');
                            }
                        })
                        .catch(() => cmsToast(label + '请求失败，请稍后重试', 'error'));
                });
            });
        }

        await render();
    },
};
