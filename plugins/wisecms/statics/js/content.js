/**
 * 内容管理页面控制器（UIUX-P0-10，含 UIUX-P0-27 批量移动）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/content`
 * （`ContentController` → snake_case `content`）。
 *
 * 对应控制器动作：
 *   content/index        → 列表（标准 paginate 契约，读 `catid/status/keyword/order/page/limit`）
 *   content/create|edit  → 整页表单（本页只跳转）
 *   content/delete       → 软删（进回收站）
 *   content/batchDelete  → 批量软删（`ids` 数组）
 *   content/moveCategory → 批量移动（`ids` 数组 + `catid`）
 *   content/toggleStatus → 状态流转（目标值语义，受审核规则约束）
 *   content/toggleFlags  → 置顶 / 推荐（UIUX-P0-46，入参名即列字段名）
 *   content/previewToken → 签发预览令牌
 *   category/tree        → 左侧栏目树（UIUX-P0-47）
 *
 * ⚠️ 两处易错点（均已按内核实现核实）：
 *   1. 列 formatter 收到的 value **已被 bootstrap-table 转义**
 *      （`getItemField(row, field, options.escape, col.escape)` 先转义，再交给 formatter，
 *      见 bundle 内 initRow 实现）—— 因此 formatter 里**不要**再 escape 一次，
 *      否则 `&` 会渲染成可见的 `&amp;`；
 *   2. `sortable: true` 只开在 `ContentService::getList` 的排序白名单内
 *      （`id|create_time|update_time|publish_time|view_count|listorder`），
 *      否则点了排序后端静默忽略 = 死交互。
 *
 * 未实现：导出（`content/export` 属 UIUX-P1-22，需后端配合流式/异步导出）。
 * 本页**不放置**导出按钮 —— 宁缺一个入口，不放一个点了没反应的按钮。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsDelete, cmsBatch, cmsRefresh, cmsToast, cmsConfirm, cmsAction,
    cmsCategoryTree, cmsFormDialog, cmsEscape, cmsBindRowToggles, cmsOperateButtons,
    cmsCopy, cmsBindRowContextMenu, cmsBindShortcuts, cmsOnSiteSwitched,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('content/index'),
    create: cmsUrl('content/create'),
    edit: cmsUrl('content/edit'),
    del: cmsUrl('content/delete'),
    batchDel: cmsUrl('content/batchDelete'),
    move: cmsUrl('content/moveCategory'),
    toggle: cmsUrl('content/toggleStatus'),
    flags: cmsUrl('content/toggleFlags'),
    versions: cmsUrl('content/versions'),
    versionDiff: cmsUrl('content/versionDiff'),
    rollback: cmsUrl('content/rollback'),
    versionDelete: cmsUrl('content/versionDelete'),
    preview: cmsUrl('content/previewToken'),
    autosave: cmsUrl('content/autosave'),
    export: cmsUrl('content/export'),
    customFields: cmsUrl('content/customFields'),
    seoAudit: cmsUrl('seo/audit'),
    quality: cmsUrl('content/quality'),
    linkOptions: cmsUrl('content/linkOptions'),
    tree: cmsUrl('category/tree'),
    workflow: cmsUrl('workflow/panel'),
    catCreate: cmsUrl('category/create'),
    catEdit: cmsUrl('category/edit'),
    catDel: cmsUrl('category/delete'),
};

const STATUS_MAP = { 0: '草稿', 1: '待审核', 2: '已发布', 3: '已归档' };

/** 与 `ContentService::getList()` 的 `$allowedOrders` 严格一致（顺序保持同步，便于比对） */
const SORTABLE = ['id', 'create_time', 'update_time', 'publish_time', 'view_count', 'listorder'];

/** P1-05：SEO 检测结果渲染（得分 + 等级 + 问题项明细），插入 `#seo-score-panel` */
function renderSeoScore(d) {
    const score = Number(d.total_score) || 0;
    const level = d.level || '—';
    const issues = Array.isArray(d.issues) ? d.issues : [];

    const levelCls = { A: 'success', B: 'info', C: 'warning', D: 'warning', F: 'danger' }[level] || 'secondary';

    let issuesHtml;
    if (!issues.length) {
        issuesHtml = '<div class="text-success small"><i class="bi bi-check-circle me-1"></i>未发现问题</div>';
    } else {
        issuesHtml = '<ul class="list-group list-group-flush small mt-2">' + issues.map((it) => (
            '<li class="list-group-item px-0 d-flex">'
            + '<span class="badge bg-warning text-dark me-2 align-self-start">' + cmsEscape(it.name || it.check || '') + '</span>'
            + '<span>' + cmsEscape(it.message || '') + '</span>'
            + '</li>'
        )).join('') + '</ul>';
    }

    return '<div class="d-flex align-items-center mb-2">'
        + '<span class="badge bg-' + levelCls + ' fs-6 me-2">' + cmsEscape(level) + '</span>'
        + '<span class="fs-4 fw-bold">' + score + '</span>'
        + '<span class="text-muted small ms-1">/ 100</span>'
        + '</div>'
        + '<div class="text-muted small mb-1">问题项（' + issues.length + '）</div>'
        + issuesHtml;
}

/**
 * P2-02：质量评分渲染（总分 + 等级 + 四维度进度条 + 改进建议），插入 `#quality-score-panel`
 *
 * 维度与满分、中文名均由后端 `QualityScorer::score()` 下发（含各 Dimension 的 `getName()`），
 * 前端不维护「英文键 → 中文名」映射，避免两边不同步。
 */
function renderQualityScore(d) {
    const score = Number(d.total_score) || 0;
    const level = d.level || '—';
    const dims = (d && typeof d.dimensions === 'object' && d.dimensions) || {};
    const suggestions = Array.isArray(d.suggestions) ? d.suggestions : [];

    const levelCls = { A: 'success', B: 'info', C: 'warning', D: 'warning', F: 'danger' }[level] || 'secondary';
    const barCls = (pct) => (pct >= 80 ? 'bg-success' : (pct >= 50 ? 'bg-info' : 'bg-warning'));

    let dimHtml = '';
    Object.keys(dims).forEach((key) => {
        const dim = dims[key] || {};
        const got = Number(dim.score) || 0;
        const max = Number(dim.max) || 0;
        const pct = max > 0 ? Math.round((got / max) * 100) : 0;
        dimHtml += '<div class="d-flex align-items-center mb-1 small">'
            + '<span class="text-muted" style="width:110px">' + cmsEscape(dim.name || key) + '</span>'
            + '<div class="progress flex-grow-1 me-2" style="height:12px">'
            + '<div class="progress-bar ' + barCls(pct) + '" style="width:' + pct + '%"></div>'
            + '</div>'
            + '<span style="width:60px" class="text-end">' + got + ' / ' + max + '</span>'
            + '</div>';
    });

    let suggestHtml;
    if (!suggestions.length) {
        suggestHtml = '<div class="text-success small mt-2"><i class="bi bi-check-circle me-1"></i>暂无改进建议</div>';
    } else {
        suggestHtml = '<ul class="list-group list-group-flush small mt-2">' + suggestions.map((s) => (
            '<li class="list-group-item px-0">'
            + '<i class="bi bi-lightbulb me-1 text-warning"></i>' + cmsEscape(String(s))
            + '</li>'
        )).join('') + '</ul>';
    }

    return '<div class="d-flex align-items-center mb-2">'
        + '<span class="badge bg-' + levelCls + ' fs-6 me-2">' + cmsEscape(level) + '</span>'
        + '<span class="fs-4 fw-bold">' + score + '</span>'
        + '<span class="text-muted small ms-1">/ 100</span>'
        + (d.can_publish === false ? '<span class="badge bg-danger-subtle text-danger ms-2">未达发布阈值</span>' : '')
        + '</div>'
        + dimHtml
        + suggestHtml;
}

const OPERATE = [
    // 颜色只承载"危险/非危险"两级（UIUX-P1-04）：
    // 之前 5 个按钮用了 4 种色（primary/info/secondary/danger），同一行像一排彩色图钉，
    // 且 info 同时表示"版本""流程"，颜色不承载任何可学习的信息。
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'history', title: '版本历史', icon: 'bi bi-clock-history', class: 'btn-outline-secondary' },
    { action: 'workflow', title: '流程', icon: 'bi bi-diagram-2', class: 'btn-outline-secondary' },
    { action: 'preview', title: '预览', icon: 'bi bi-eye', class: 'btn-outline-secondary' },
    { action: 'del', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

/** 初始筛选来自 URL：栏目页「内容」按钮带 `?catid=`，菜单「待审核内容」带 `?status=` */
const qs = new URLSearchParams(window.location.search);
let currentCatid = parseInt(qs.get('catid'), 10) || 0;

/** 状态列：4 个取值不能用布尔开关表达，用行内下拉（change 时提交目标值） */
function statusCell(value, row) {
    let options = '';
    Object.keys(STATUS_MAP).forEach((key) => {
        options += '<option value="' + key + '"' + (String(value) === key ? ' selected' : '') + '>'
            + STATUS_MAP[key] + '</option>';
    });
    return '<select class="form-select form-select-sm cms-status-select"'
        + ' data-id="' + row.id + '" data-prev="' + value + '"'
        + ' style="min-width:6.5rem">' + options + '</select>';
}

/** 切换到指定 Tab（`#contentTabs a[href="#tab-x"]` 由 Bootstrap data API 绑定，直接触发 click） */
function showTab(tabId) {
    const el = document.querySelector('#contentTabs a[href="#' + tabId + '"]');
    if (el) el.click();
}

/**
 * 内容表单页公共初始化（UIUX-P1-01）：create / edit 共用。
 *
 * 提供：① 粘性操作条（保存草稿/发布 → 设 `status` select 后提交）；② 右侧锚点 ↔ Tab 双向联动；
 * ③ 离开保护（有未保存变更时 `beforeunload` 提示）；④ 跨 Tab 校验 required（自动定位到对应分区）。
 *
 * @param {Object} [opts]
 * @param {Function} [opts.onSubmit] 提交前钩子（如 create 的 model_id 校验），返回 false 取消提交
 */
/* ============================================================================
 * UIUX-P1-06：正文编辑器（wiseeditor）辅助
 * ========================================================================== */

/** 正文值承载元素（编辑器产出的隐藏域与回退 textarea **同名同 id**，故两者通用） */
function $bodyHolder() {
    return $('#editor_content_input');
}

/** 取正文编辑器实例（未挂载 / 未启用编辑器时返回 null） */
function bodyEditorInstance() {
    const we = window.WiseEditor;
    return (we && typeof we.get === 'function') ? we.get('editor_content') : null;
}

/** 纯文本字数：去标签 + 去空白（与后端 `mb_strlen(strip_tags())` 口径接近） */
function bodyTextLength(html) {
    return String(html == null ? '' : html).replace(/<[^>]*>/g, '').replace(/\s+/g, '').length;
}

/**
 * 往正文插入 HTML 片段
 *
 * @returns {'cursor'|'append'|'failed'} 实际插入方式（用于如实提示，不静默失败）
 */
function insertIntoBody(html) {
    const inst = bodyEditorInstance();

    if (inst) {
        const internal = typeof inst.getInternal === 'function' ? inst.getInternal() : null;
        // TipTap：走官方命令，在**光标处**插入
        if (internal && typeof internal.chain === 'function') {
            try {
                internal.chain().focus().insertContent(html).run();
                return 'cursor';
            } catch (e) { /* 落到追加分支 */ }
        }

        // 旧版 contenteditable 引擎：无插入 API → 追加（不假装插在光标处）
        if (typeof inst.getContent === 'function' && typeof inst.setContent === 'function') {
            const merged = String(inst.getContent() || '') + html;
            inst.setContent(merged);
            $bodyHolder().val(merged).trigger('input');
            return 'append';
        }
    }

    const $holder = $bodyHolder();
    if ($holder.length) {
        $holder.val(String($holder.val() || '') + html).trigger('input');
        return 'append';
    }

    return 'failed';
}

/**
 * 内联链接选择器
 *
 * 数据源 `content/linkOptions`：只出**本站已发布**内容，URL 由 `UrlService` 生成（绝对 URL）。
 * 复用后台既有弹层组件（`Wise.ui.dialog.open`），不新造弹层。
 */
function openInternalLinkPicker(onPick) {
    const wise = window.Wise || {};
    const http = wise.util && wise.util.http;

    if (!(wise.ui && wise.ui.dialog) || !http || typeof http.get !== 'function') {
        cmsToast('弹层或请求组件未就绪', 'error');
        return;
    }

    const bodyHtml = '<div class="mb-2">'
        + '<input type="text" class="form-control form-control-sm" id="cms-link-kw"'
        + ' placeholder="按标题搜索已发布内容（回车搜索；留空为最近发布）" autocomplete="off">'
        + '</div>'
        + '<div class="list-group" id="cms-link-list" style="max-height:320px;overflow:auto"></div>';

    const index = wise.ui.dialog.open(bodyHtml, '插入内联链接', {
        area: ['560px', '460px'],
        btn: ['关闭'],
        success(layero) {
            const $list = layero.find('#cms-link-list');

            const render = (items) => {
                if (!items.length) {
                    $list.html('<div class="text-muted small p-2">没有匹配的已发布内容。</div>');
                    return;
                }
                $list.html(items.map((item) => (
                    '<a href="javascript:;" class="list-group-item list-group-item-action py-2"'
                    + ' data-url="' + cmsEscape(item.url) + '" data-title="' + cmsEscape(item.title) + '">'
                    + '<div class="small">' + cmsEscape(item.title || '（无标题）') + '</div>'
                    + '<div class="text-muted small text-truncate">' + cmsEscape(item.url) + '</div>'
                    + '</a>'
                )).join(''));
            };

            const load = (kw) => {
                $list.html('<div class="text-muted small p-2">加载中…</div>');
                // 内核 http 默认 interceptor:true → resolve 的就是 data（{items:[...]}）
                http.get(URL.linkOptions, { keyword: kw, limit: 10 })
                    .then((d) => render(((d || {}).items) || []))
                    .catch(() => $list.html('<div class="text-danger small p-2">加载失败，请重试。</div>'));
            };

            layero.find('#cms-link-kw').on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    load($(this).val());
                }
            });

            $list.on('click', 'a[data-url]', function () {
                const title = String($(this).attr('data-title') || '');
                const url   = String($(this).attr('data-url') || '');
                onPick('<a href="' + cmsEscape(url) + '">' + cmsEscape(title) + '</a>');
                wise.ui.dialog.close(index);
            });

            load('');
        },
    });
}

/**
 * 正文字数统计
 *
 * 编辑器写回的是隐藏域的 `value`（**程序化赋值不冒泡 input 事件**）→
 * 必须挂在编辑器 `onUpdate` 上，并主动 `trigger('input')`，
 * 否则既有的「计脏（离开保护）/ 自动草稿」会看不到正文修改（P1-03 / P1-04）。
 */
function initBodyWordCount() {
    const $counter = $('#body-word-count');
    const $holder  = $bodyHolder();
    if (!$counter.length || !$holder.length) {
        return;
    }

    const render = () => {
        const inst = bodyEditorInstance();
        const html = (inst && typeof inst.getContent === 'function') ? inst.getContent() : $holder.val();
        $counter.text('字数 ' + bodyTextLength(html));
    };

    // 编辑器 init 与页面控制器同在 DOMContentLoaded 之后执行，顺序不保证 → 短暂轮询等挂载
    let tries = 0;
    const bind = () => {
        const inst = bodyEditorInstance();
        if (inst && typeof inst.onUpdate === 'function') {
            inst.onUpdate(() => {
                render();
                $holder.trigger('input');
            });
            render();
            return;
        }
        if (tries++ < 20) {
            setTimeout(bind, 150);
            return;
        }
        render();   // 未挂载：只依赖 textarea 的 input 事件
    };

    $holder.on('input', render);
    render();
    bind();
}

/** 正文编辑器相关交互（字数 + 内链插入）——create / edit 共用 */
function initBodyEditorUi() {
    if (!$bodyHolder().length && !$('#body-word-count').length) {
        return;
    }

    initBodyWordCount();

    $('#btn-insert-internal-link').on('click', () => {
        openInternalLinkPicker((html) => {
            const where = insertIntoBody(html);
            if (where === 'failed') {
                cmsToast('未找到正文输入区，无法插入', 'error');
                return;
            }
            cmsToast(
                where === 'cursor' ? '已在光标处插入内链' : '已追加到正文末尾（当前编辑器不支持光标插入）',
                where === 'cursor' ? 'success' : 'warning'
            );
        });
    });
}

function initContentForm(opts) {
    const cfg = opts || {};
    const $form = $('#contentForm');
    if (!$form.length) return null;

    let dirty = false;

    // ⓪ UIUX-P1-06：正文编辑器（字数统计 + 内链插入）；未启用编辑器时自动退化为普通 textarea
    initBodyEditorUi();

    // ① 粘性操作条：设置状态后提交（保存草稿=0 / 发布=2），状态 select 是唯一状态来源
    $form.find('[data-submit-status]').on('click', function () {
        const status = $(this).attr('data-submit-status');
        $form.find('select[name="status"]').val(status);
        $form.trigger('submit');
    });

    // ② 右侧锚点 → 切换 Tab
    $('.cms-anchor').on('click', function () {
        showTab($(this).attr('data-tab'));
    });

    // ② Tab 切换 → 高亮对应锚点
    $('#contentTabs a[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        const href = $(this).attr('href') || ''; // 形如 #tab-basic
        $('.cms-anchor').removeClass('active');
        $('.cms-anchor[data-tab="' + href.slice(1) + '"]').addClass('active');
    });

    // ② 首屏同步一次：首 Tab 是服务端渲染的 active，不会触发 shown.bs.tab，
    //    不同步的话刚进页面时 7 个锚点全是"未选中"，用户看不出当前在哪个分区（UIUX-P0-53）。
    (function initAnchorState() {
        const $active = $('#contentTabs a[data-bs-toggle="tab"].active').first();
        const tabId = ($active.attr('href') || '').replace('#', '');
        if (!tabId) return;
        $('.cms-anchor').removeClass('active');
        $('.cms-anchor[data-tab="' + tabId + '"]').addClass('active');
    }());

    // ③ 离开保护：有未保存变更时提示
    $form.on('input change', 'input, textarea, select', function () {
        dirty = true;

        // 用户开始修正时立即撤掉错误态（否则红框会一直挂着，直到下次提交）
        const $el = $(this);
        if (!$el.hasClass('is-invalid')) return;
        $el.removeClass('is-invalid');
        const $wrap = $el.closest('.cms-field-invalid').first();
        $wrap.removeClass('cms-field-invalid').find('.cms-field-error').remove();
    });
    window.addEventListener('beforeunload', (e) => {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    });
    $form.on('submit', () => { dirty = false; });

    // P1-04：返回跳转拦截（dirty 时先确认，避免只依赖浏览器原生 beforeunload 提示）
    $('.cms-back').on('click', function (e) {
        if (!dirty) return;
        const href = $(this).attr('href') || '';
        e.preventDefault();
        cmsConfirm('有未保存的修改，确定离开？', '离开确认').then((ok) => {
            if (ok) window.location.href = href;
        });
    });

    // ④ 跨 Tab 校验 required（Bootstrap tab 隐藏分区的 required 不被浏览器原生校验覆盖）
    $form.on('submit', function (e) {
        if (typeof cfg.onSubmit === 'function' && cfg.onSubmit() === false) {
            e.preventDefault();
            return;
        }

        // 逐项校验：红框 + 文案 + Tab 红点 + 聚焦到第一个错误字段。
        // 旧实现只加 `is-invalid`（模板里没有 .invalid-feedback），用户看到的是
        // 「某个看不见的 Tab 里有个红框，没有文字，光标也不动」——评审 §9.2。
        const invalidFields = [];
        $form.find('[required]').each(function () {
            const $el = $(this);
            const $wrap = $el.closest('.mb-3, .form-group, [class*="col-"]').first();
            const empty = String($el.val() || '').trim() === '';

            $el.removeClass('is-invalid');
            $wrap.removeClass('cms-field-invalid');
            $wrap.find('.cms-field-error').remove();

            if (!empty) return;

            // 框架会给必填 label 追加 `<span class="required-indicator sr-only"> (required)</span>`，
            // 取文案时一并剥掉，否则提示会变成「请填写「标题 * (required)」」。
            const label = ($wrap.find('label').first().text() || $el.attr('name') || '')
                .replace(/\(required\)/gi, '')
                .replace(/\*/g, '')
                .replace(/\s+/g, ' ')
                .trim();

            invalidFields.push({ el: $el, pane: $el.closest('.tab-pane'), label: label });

            $el.addClass('is-invalid');
            $wrap.addClass('cms-field-invalid');
            if (label) {
                $('<div class="cms-field-error">请填写「' + cmsEscape(label) + '」</div>').appendTo($wrap);
            }
        });

        // Tab 红点：哪个分区有错，一眼可见
        $('#contentTabs .nav-link').removeClass('cms-tab-error');
        invalidFields.forEach((f) => {
            if (f.pane.length) {
                $('#contentTabs .nav-link[href="#' + f.pane.attr('id') + '"]').addClass('cms-tab-error');
            }
        });

        if (invalidFields.length) {
            e.preventDefault();

            const first = invalidFields[0];
            if (first.pane.length) showTab(first.pane.attr('id'));

            // 切 Tab 后再聚焦（Bootstrap 切 Tab 有动画，延一帧更稳）
            setTimeout(() => {
                first.el.trigger('focus');
                const node = first.el.get(0);
                if (node && typeof node.scrollIntoView === 'function') {
                    node.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            }, 60);

            cmsToast('还有 ' + invalidFields.length + ' 项必填未完成：'
                + invalidFields.map((f) => f.label).join('、'), 'warning');
        }
    });

    // ⑤ P1-03：自动草稿（每 60s + 失焦静默保存；成功无 toast，网络失败一次性警告）
    const http = window.Wise && window.Wise.util && window.Wise.util.http;
    const $savedAt = $('#autosave-status');
    if (http && $savedAt.length) {
        let saving = false;
        let warned = false;
        let $idField = $form.find('input[name="id"]');

        function doAutosave() {
            if (saving || !dirty) return; // 无未保存变更不触发
            saving = true;

            http.post(URL.autosave, new FormData($form[0]), { interceptor: false, loading: false })
                .then((body) => {
                    if (body && body.code === 0) {
                        const d = body.data || {};
                        if (d.id) {
                            if (!$idField.length) {
                                $idField = $('<input type="hidden" name="id">').appendTo($form);
                            }
                            $idField.val(d.id);
                            // 创建页 autosave 已建草稿 → 后续正式提交改走 edit（避免重复建内容）
                            $form.attr('action', URL.edit);
                        }
                        if (d.saved_at) {
                            $savedAt.text('最后保存 ' + String(d.saved_at).slice(11, 19));
                        }
                        dirty = false; // 已保存，重置脏标记
                        warned = false;
                    } else if (!warned) {
                        warned = true;
                        cmsToast('自动保存失败：' + ((body && body.msg) || '未知错误'), 'warning');
                    }
                })
                .catch(() => {
                    if (!warned) {
                        warned = true;
                        cmsToast('自动保存失败（网络异常），请稍后手动保存', 'warning');
                    }
                })
                .finally(() => { saving = false; });
        }

        const timer = setInterval(doAutosave, 60000);
        $form.on('blur', 'input, textarea, select', doAutosave);
        window.addEventListener('beforeunload', () => clearInterval(timer));
    }
}

export default {
    async index() {
        let list = null;
        let tree = null;
        // P1-09：当前栏目筛选的显示名（chip 展示用；onSelect 传入 node.name）
        let currentCatName = '';

        // URL 上的 status 预置到筛选条（菜单「待审核内容」直接可用）
        const initStatus = qs.get('status');
        if (initStatus !== null && initStatus !== '') {
            $('#filter-status').val(initStatus);
        }

        /** P1-09：渲染「上游上下文 chip」（当前栏目筛选 + 清除回到全量） */
        function renderChip() {
            const $chip = $('#cms-context-chip');
            if (!$chip.length) return;

            if (currentCatid > 0) {
                $chip.html(
                    '<span class="badge bg-primary me-2"><i class="bi bi-diagram-3 me-1"></i>栏目：'
                    + cmsEscape(currentCatName || ('#' + currentCatid)) + '</span>'
                    + '<a href="javascript:;" class="small text-muted" id="chip-clear" title="清除栏目筛选，回到全部">'
                    + '<i class="bi bi-x-circle me-1"></i>清除筛选</a>'
                ).show();
                $('#chip-clear').on('click', () => {
                    currentCatid = 0;
                    currentCatName = '';
                    if (tree) tree.select(0);
                    renderChip();
                    cmsRefresh(list);
                });
            } else {
                $chip.empty().hide();
            }
        }

        function selectedIds() {
            const ids = window.Wise.table.getSelectedIds('#table');
            return Array.isArray(ids) ? ids : [];
        }

        /** P1-22：导出 CSV（选中优先，否则按当前筛选条件全量导出） */
        function exportContent() {
            const ids = selectedIds();
            const qs = new URLSearchParams();
            if (ids.length) {
                qs.set('ids', ids.join(','));
            } else {
                if (currentCatid) qs.set('catid', String(currentCatid));
                const status = $('#filter-status').val();
                if (status !== null && status !== undefined && status !== '') qs.set('status', String(status));
                const keyword = ($('#filter-keyword').val() || '').trim();
                if (keyword) qs.set('keyword', keyword);
            }
            const q = qs.toString();
            window.location.href = URL.export + (q ? ('?' + q) : '');
        }

        /** 批量移动（UIUX-P0-27：替换原先「暂未开放」的假实现）；P1-12 右键单行移动传 `idsOverride` */
        function batchMove(idsOverride) {
            const ids = idsOverride || selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要移动的内容', 'warning');
                return;
            }
            if (!tree || !tree.rows || !tree.rows.length) {
                cmsToast('栏目数据未就绪，请稍后重试', 'warning');
                return;
            }

            // 用 level 做缩进，拉平后的 rows 已带 level（CategoryService::flatten）
            const options = {};
            tree.rows.forEach((row) => {
                options[row.id] = '　'.repeat(Math.max(0, parseInt(row.level, 10) || 0)) + row.name;
            });

            cmsFormDialog({
                title: '批量移动栏目',
                area: ['480px', '380px'],
                intro: '<div class="alert alert-info py-2 mb-3">'
                    + '将把选中的 <b>' + ids.length + '</b> 篇内容移动到目标栏目。</div>',
                fields: [
                    {
                        name: 'catid',
                        label: '目标栏目',
                        type: 'select',
                        required: true,
                        options: options,
                        placeholder: '请选择目标栏目',
                        messages: { required: '请选择目标栏目' },
                    },
                ],
                url: URL.move,
                successMsg: '已移动 ' + ids.length + ' 篇内容',
                onSubmit: (payload) => ({ ids: ids, catid: payload.catid }),
                onSuccess: () => cmsRefresh(list),
            });
        }

        /** 批量删除（进回收站，L2：回显影响面） */
        function batchDelete() {
            const ids = selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要删除的内容', 'warning');
                return;
            }
            return cmsBatch({
                url: URL.batchDel,
                ids: ids,
                confirmMessage: '将把选中的 ' + ids.length + ' 篇内容移入回收站（可在回收站恢复），确定继续？',
                successMsg: '已移入回收站',
                refresh: list,
            });
        }

        /** 预览：签发令牌后新窗口打开 */
        function preview(id) {
            const http = window.Wise.util.http;
            http.post(URL.preview, { id: id }).then((res) => {
                const d = (res && res.data) || {};
                if (d.url) {
                    window.open(d.url, '_blank');
                    cmsToast('预览链接已打开' + (d.expires_at ? '（' + d.expires_at + ' 前有效）' : ''), 'success');
                } else {
                    cmsToast('未获取到预览链接', 'warning');
                }
            }).catch(() => { /* 拦截器已提示 */ });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            pageSize: 15,
            sortName: 'id',
            sortOrder: 'desc',
            // 批量：后端有 batchDelete / moveCategory 成对接口（阶段 0 结论④）
            checkboxHeader: true,

            columns: [
                // ⚠️ 必须显式声明勾选列：bootstrap-table 只在**存在 `checkbox: true` 列**时渲染复选框
                // （bundle 内 initHeader：`t.checkbox && (...checkboxHeader && btSelectAll...)`），
                // 内核也不会自动注入。只写 `checkboxHeader: true` 的效果是「没有任何勾选框」→
                // 批量按钮永远提示「请先勾选」，而 `getSelectedIds()` 读的是 `getSelections()`。
                { checkbox: true },
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true, formatter: 'normal' },
                {
                    field: 'title',
                    title: '标题',
                    align: 'left',
                    // value 已转义，直接拼接；不要二次转义
                    formatter: (value, row) => '<a href="' + URL.edit + '?id=' + row.id + '">'
                        + (value || '') + '</a>',
                },
                { field: 'category.name', title: '栏目', width: 130, align: 'left', formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 125,
                    align: 'center',
                    formatter: statusCell,
                    clickToSelect: false,
                },
                {
                    field: 'is_top',
                    title: '置顶',
                    width: 70,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.flags,
                },
                {
                    field: 'is_recommend',
                    title: '推荐',
                    width: 70,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.flags,
                },
                { field: 'seo_score', title: 'SEO', width: 110, align: 'center', formatter: 'progress' },
                // P2-02：质量分（`content.quality_score`，由 `content/quality` 检测写入）
                { field: 'quality_score', title: '质量', width: 110, align: 'center', formatter: 'progress' },
                {
                    field: 'publish_time',
                    title: '发布时间',
                    width: 155,
                    align: 'center',
                    sortable: true,
                    // ⚠️ 不要用 `timeAgo`：内核内置 moment 子集的 duration() 只实现了
                    // asDays()/hours()/minutes()/seconds()，而 timeAgo 会调 duration.years()
                    // 与 duration.months()（enhanced-table.js:2500-2505）→ 对任何非空时间
                    // 直接抛 TypeError，整行渲染失败。`datetime` 只用 .format()，安全。
                    formatter: 'datetime',
                },
                {
                    // 默认隐藏：列表默认只关心发布时间，创建时间留给列选择器按需打开
                    field: 'create_time',
                    title: '创建时间',
                    width: 155,
                    align: 'center',
                    sortable: true,
                    visible: false,
                    formatter: 'datetime',
                },
                {
                    field: 'view_count',
                    title: '浏览',
                    width: 90,
                    align: 'right',
                    sortable: true,
                    formatter: 'numberAbbr',
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 200,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            query(params) {
                const query = {};

                if (currentCatid) query.catid = currentCatid;

                const status = $('#filter-status').val();
                if (status !== null && status !== undefined && status !== '') query.status = status;

                const keyword = ($('#filter-keyword').val() || '').trim();
                if (keyword) query.keyword = keyword;

                // 服务端排序：仅白名单列，其余列未开 sortable（点了后端会忽略）
                if (params && params.sort && SORTABLE.indexOf(params.sort) !== -1) {
                    query.order = params.sort + ' ' + (params.order || 'desc');
                }

                return query;
            },

            toolbarBtn: [
                {
                    id: 'btn-create-content',
                    name: 'add',
                    title: '发布内容',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-success',
                    click: () => { window.location.href = URL.create; },
                },
                {
                    id: 'btn-batch-move',
                    name: 'multi',
                    title: '批量移动',
                    icon: 'bi bi-arrows-move',
                    class: 'btn btn-sm btn-outline-info',
                    click: () => batchMove(),
                },
                {
                    id: 'btn-batch-delete',
                    name: 'del',
                    title: '批量删除',
                    icon: 'bi bi-trash',
                    class: 'btn btn-sm btn-outline-danger',
                    click: () => batchDelete(),
                },
                {
                    id: 'btn-export-content',
                    name: 'export',
                    title: '导出',
                    icon: 'bi bi-download',
                    class: 'btn btn-sm btn-outline-success',
                    click: () => exportContent(),
                },
                {
                    id: 'btn-refresh-content',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    window.location.href = URL.edit + '?id=' + row.id;
                    return undefined;
                }
                if (action === 'history') {
                    window.location.href = URL.versions + '?id=' + row.id;
                    return undefined;
                }
                if (action === 'workflow') {
                    // UIUX-P0-34：工作流面板（iframe 弹层）。标题经 URL 传入（status 接口不返回标题）
                    window.Wise.ui.dialog.open(
                        URL.workflow + '?id=' + encodeURIComponent(row.id)
                            + '&title=' + encodeURIComponent(row.title || ''),
                        '工作流', { area: ['560px', '640px'], resize: true }
                    );
                    return undefined;
                }
                if (action === 'preview') return preview(row.id);
                if (action === 'del') {
                    return cmsDelete({
                        url: URL.del,
                        data: { id: row.id },
                        refresh: list,
                        // ⚠️ 确认框按 HTML 渲染，而 row 是**未转义原始数据**（见文件头说明）→ 必须转义
                        confirmMessage: '确定删除内容「' + cmsEscape(row.title || '') + '」？将移至回收站，可恢复。',
                        successMsg: '已移至回收站',
                    });
                }
                return undefined;
            },
        });

        // ---- 置顶 / 推荐开关 ----
        // ⚠️ 必须显式绑定：内核 `formatter: 'toggle'` **只渲染不提交**（全库无处理器，
        // 见 `cms-admin.js::cmsBindRowToggles` 的说明）。此前未绑定 → 开关是死控件：
        // 点一下只是本地勾选，刷新后又回到原值。
        // 提交 `{id, is_top|is_recommend}` 到 `content/toggleFlags`（P0-46 按列名取值）。
        cmsBindRowToggles({
            url: URL.flags,
            refresh: list,
            successMsg: '已更新',
        });

        // ---- P1-12：行右键菜单（与操作列一致 + 复制 ID / 移动） ----
        cmsBindRowContextMenu('#table', list, (row) => [
            { id: 'edit', label: '编辑', icon: 'bi bi-pencil', action: () => { window.location.href = URL.edit + '?id=' + row.id; } },
            { id: 'preview', label: '预览', icon: 'bi bi-eye', action: () => preview(row.id) },
            { id: 'copyId', label: '复制 ID', icon: 'bi bi-clipboard', action: async () => {
                const ok = await cmsCopy(String(row.id));
                cmsToast(ok ? 'ID 已复制' : '复制失败，请手动复制：' + row.id, ok ? 'success' : 'warning');
            } },
            { id: 'move', label: '移动到栏目', icon: 'bi bi-arrows-move', action: () => batchMove([row.id]) },
            { id: 'history', label: '版本历史', icon: 'bi bi-clock-history', action: () => { window.location.href = URL.versions + '?id=' + row.id; } },
            { id: 'workflow', label: '工作流', icon: 'bi bi-diagram-2', action: () => {
                window.Wise.ui.dialog.open(
                    URL.workflow + '?id=' + encodeURIComponent(row.id)
                        + '&title=' + encodeURIComponent(row.title || ''),
                    '工作流', { area: ['560px', '640px'], resize: true }
                );
            } },
            { divider: true },
            { id: 'del', label: '删除', icon: 'bi bi-trash', action: () => cmsDelete({
                url: URL.del,
                data: { id: row.id },
                refresh: list,
                confirmMessage: '确定删除内容「' + cmsEscape(row.title || '') + '」？将移至回收站，可恢复。',
                successMsg: '已移至回收站',
            }) },
        ]);

        // ---- P1-11：键盘快捷键（`/` 聚焦搜索、`n` 新建、`r` 刷新） ----
        cmsBindShortcuts({
            search: () => { $('#filter-keyword').trigger('focus'); },
            create: () => { window.location.href = URL.create; },
            refresh: () => cmsRefresh(list),
        });

        // ---- P1-14：站点切换无刷新 —— 局部刷新表格 + 栏目树，并清掉栏目筛选 ----
        cmsOnSiteSwitched(() => {
            cmsRefresh(list);
            if (tree) tree.reload();
            // 站点变了，栏目树也变；清空当前栏目筛选与 chip（回到全量）
            currentCatid = 0;
            currentCatName = '';
            renderChip();
        });

        // ---- 行内状态下拉：提交目标值，失败回退（避免"显示成功实则没变"） ----
        $('#table').on('change', '.cms-status-select', function () {
            const $select = $(this);
            const id = parseInt($select.data('id'), 10);
            const prev = $select.data('prev');
            const next = parseInt($select.val(), 10);

            if (String(prev) === String(next)) return;

            window.Wise.ui.action.execute({
                method: 'POST',
                url: URL.toggle,
                data: { id: id, status: next },
                successMsg: '状态已更新为「' + (STATUS_MAP[next] || next) + '」',
            }).then((res) => {
                if (res && res.success) {
                    cmsRefresh(list);
                } else {
                    // 失败原因（如「先审后发」下非待审内容不能直接发布）已由内核 toast
                    $select.val(prev);
                }
            });
        });

        // ---- 筛选条 ----
        $('#btn-search-content').on('click', () => cmsRefresh(list));
        $('#filter-status').on('change', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                cmsRefresh(list);
            }
        });
        $('#btn-reset-content').on('click', () => {
            $('#filter-keyword').val('');
            $('#filter-status').val('');
            currentCatid = 0;
            currentCatName = '';
            if (tree) tree.select(0);
            renderChip();
            cmsRefresh(list);
        });

        // ---- 左侧栏目树（与列表联动，P1-09 补 chip；P1-10 加搜索/展开记忆/右键） ----
        tree = await cmsCategoryTree('#cmsCategoryTree', {
            url: URL.tree,
            current: currentCatid,
            onSelect(catid, node) {
                if (String(catid) === String(currentCatid)) return;
                currentCatid = catid;
                currentCatName = node ? node.name : '';
                renderChip();
                cmsRefresh(list);
            },
            // P1-10：搜索定位 + 展开态记忆 + 右键菜单（新增子栏目/编辑/删除）
            search: true,
            persist: 'wisecms.cat.tree.expanded',
            contextMenu: {
                addChild(node) {
                    window.location.href = URL.catCreate + '?parent_id=' + encodeURIComponent(node.id);
                },
                edit(node) {
                    window.location.href = URL.catEdit + '?id=' + encodeURIComponent(node.id);
                },
                remove(node) {
                    cmsConfirm(
                        '确定删除栏目「' + cmsEscape(node.name || '') + '」？<br>'
                        + '<span class="text-danger">删除后进入回收站（栏目软删），其下内容不受影响。</span>',
                        '删除栏目'
                    ).then((ok) => {
                        if (!ok) return;
                        return cmsAction({
                            url: URL.catDel,
                            data: { id: node.id },
                            successMsg: '栏目已删除',
                        }).then((res) => {
                            if (!res || !res.success) return;
                            if (tree) tree.reload();
                            cmsRefresh(list);
                            // 删除的是当前筛选栏目 → 清 chip 回到全量
                            if (String(node.id) === String(currentCatid)) {
                                currentCatid = 0;
                                currentCatName = '';
                                renderChip();
                            }
                        });
                    });
                },
            },
        });

        // URL 带 catid 进入时（栏目页「内容」按钮），初始化 chip 显示当前栏目名
        if (currentCatid > 0 && tree && Array.isArray(tree.tree)) {
            const stack = tree.tree.slice();
            while (stack.length) {
                const n = stack.shift();
                if (String(n.id) === String(currentCatid)) {
                    currentCatName = n.name || '';
                    break;
                }
                if (Array.isArray(n.children)) stack.push(...n.children);
            }
        }
        renderChip();

        return list;
    },

    /** 编辑页（UIUX-P1-01 Tab 分区 + P0-34 第二接入点「流程」+「预览」） */
    edit() {
        const $form = $('#contentForm');
        if (!$form.length) return;

        const contentId = parseInt($form.find('input[name="id"]').val(), 10) || 0;
        const title = String($form.find('input[name="title"]').val() || '');

        // Tab / 锚点 / 粘性操作条 / 离开保护 / 跨 Tab 校验
        initContentForm();

        $('#btn-workflow').on('click', () => {
            if (!contentId) {
                cmsToast('缺少内容 ID', 'warning');
                return;
            }
            window.Wise.ui.dialog.open(
                URL.workflow + '?id=' + encodeURIComponent(contentId)
                    + '&title=' + encodeURIComponent(title),
                '工作流', { area: ['560px', '640px'], resize: true }
            );
        });

        $('#btn-preview').on('click', () => {
            if (!contentId) {
                cmsToast('缺少内容 ID', 'warning');
                return;
            }
            // interceptor:false → 拿完整信封 {code,msg,data}（与列表页 preview 的取数口径区分，
            // 后者走 interceptor 默认值，此处显式还原原 edit 页 ajaxPost 的信封语义）
            window.Wise.util.http.post(URL.preview, { id: contentId }, { interceptor: false, loading: true })
                .then((body) => {
                    const d = (body && body.code === 0 && body.data) || {};
                    if (d.url) {
                        window.open(d.url, '_blank');
                        cmsToast('预览链接已打开', 'success');
                    } else {
                        cmsToast('未获取到预览链接', 'warning');
                    }
                })
                .catch(() => cmsToast('预览请求失败，请稍后重试', 'error'));
        });

        // P1-11：Cmd/Ctrl+S 保存（触发表单原生提交，走离开保护 / 跨 Tab 校验同一条路径）
        cmsBindShortcuts({
            save: () => { $form.trigger('submit'); },
        });

        // P1-05：SEO 实时评分（编辑页有 content_id 才可检测；create 页无 → 不放此面板）
        $('#btn-seo-audit').on('click', function () {
            if (!contentId) {
                cmsToast('保存后才能检测 SEO 评分', 'warning');
                return;
            }
            const wise = window.Wise || {};
            if (!(wise.util && wise.util.http && typeof wise.util.http.get === 'function')) {
                cmsToast('请求组件未就绪', 'error');
                return;
            }
            const $panel = $('#seo-score-panel');
            // 内核 http 默认 interceptor:true → resolve 的就是 data（{total_score, level, issues}）
            wise.util.http.get(URL.seoAudit, { id: contentId })
                .then((d) => { $panel.html(renderSeoScore(d || {})); })
                .catch(() => cmsToast('SEO 检测失败，请稍后重试', 'error'));
        });

        // P2-02：内容质量评分（与 SEO 面板同款：编辑页有 content_id 才可检测，create 页提示先保存）
        $('#btn-quality-audit').on('click', function () {
            if (!contentId) {
                cmsToast('保存后才能检测质量评分', 'warning');
                return;
            }
            const wise = window.Wise || {};
            if (!(wise.util && wise.util.http && typeof wise.util.http.get === 'function')) {
                cmsToast('请求组件未就绪', 'error');
                return;
            }
            const $panel = $('#quality-score-panel');
            // 内核 http 默认 interceptor:true → resolve 的就是 data
            // （{total_score, level, can_publish, dimensions, suggestions}）
            wise.util.http.get(URL.quality, { id: contentId })
                .then((d) => { $panel.html(renderQualityScore(d || {})); })
                .catch(() => cmsToast('质量评分失败，请稍后重试', 'error'));
        });
    },

    /** 发布页（UIUX-P1-01 Tab 分区 + P1-02 切换模型 AJAX 局部重载字段区） */
    create() {
        const $form = $('#contentForm');
        if (!$form.length) return;

        // Tab / 锚点 / 粘性操作条 / 离开保护 / 跨 Tab 校验
        initContentForm();

        // P1-02：按 `customForm`（与 create 页模板结构一致）渲染字段区；
        // `field.form` 是后端生成的 HTML（FieldFactory，与模板 `{$field.form|raw}` 同源）→ 直插，
        // `name`/`tips` 走 cmsEscape。
        function renderCustomFields(customForm) {
            const base = (customForm && customForm.base) || {};
            const senior = (customForm && customForm.senior) || {};
            const all = Object.values(base).concat(Object.values(senior));
            if (!all.length) {
                return '<div class="text-muted text-center py-4">'
                    + '该模型没有自定义字段（可在「基本信息」切换内容模型）</div>';
            }
            return all.map((field) => {
                const label = cmsEscape(field.name || '')
                    + (field.is_required ? '<span class="text-danger">*</span>' : '');
                const tips = field.tips
                    ? ('<div class="form-text">' + cmsEscape(field.tips) + '</div>')
                    : '';
                return '<div class="mb-3"><label class="form-label">' + label + '</label>'
                    + (field.form || '') + tips + '</div>';
            }).join('');
        }

        // 切换内容模型：AJAX 局部重载 `#tab-fields`，系统字段（标题/栏目/正文等）不丢
        $('#modelSelect').on('change', function () {
            const modelId = String(this.value || '');
            const wise = window.Wise || {};
            const http = wise.util && wise.util.http;
            const $fields = $('#tab-fields');
            if (!http || !$fields.length) return;

            http.get(URL.customFields, { model_id: modelId })
                .then((res) => {
                    const d = (res && res.data) || res || {};
                    $fields.html(renderCustomFields(d));
                })
                .catch(() => cmsToast('自定义字段加载失败，请稍后重试', 'error'));
        });
    },

    /**
     * 版本历史页（UIUX-P1-07 对比改抽屉；UIUX-P0-41 列表改 cmsTable）
     *
     * 回滚 / 删快照成功后**不再整页 reload**：
     *   · 列表 → `cmsRefresh(list)` 定向刷新；
     *   · 页头「最新版本 / 保留条数」→ 列表接口本就回传 `current_version` / `keep`，
     *     在 `responseHandler` 里同步（该钩子每次取数必然执行）；
     *   · 乐观锁基线 `data-base-update-time` → 用回滚响应里的 `update_time` 就地更新
     *     （回滚会写新版本记录 → update_time 必变，不更新则**二次回滚必被服务端拒绝**）。
     *
     * ⚠️ 根容器**只能**用 `data-cms-page` 找，**不能**用 id：AdminLTE 的 addLandmarks()
     * 会把页面第一个 `.app-main`（页面无 `<main>` 时）改写为 `id="main"`，原
     * `#versionsPage` 选择器因而不匹配 → 本方法整段早退（历史缺陷，2026-10-13 实测发现）。
     */
    versions() {
        const $page = $('[data-cms-page="versions"]');
        if (!$page.length) return;

        const contentId = parseInt($page.attr('data-id'), 10) || 0;

        /** 乐观锁基线：提交时现取现用（可能已被上一次回滚就地更新） */
        function baseUpdateTime() {
            return $page.attr('data-base-update-time') || '';
        }

        /** 页头元信息随列表刷新同步 */
        function syncMeta(data) {
            const d = data || {};
            const latest = parseInt(d.current_version, 10) || 0;
            const keep = parseInt(d.keep, 10) || 0;
            $('#verLatest').text(latest > 0 ? 'v' + latest : '暂无');
            $('#verKeep').text(keep > 0 ? keep : '不限');
        }

        /** 版本对比 → 抽屉（原为页内 #diffPanel 展开） */
        function showDiff(versionId) {
            window.Wise.util.http.get(URL.versionDiff, { id: contentId, version_id: versionId })
                .then((data) => {
                    renderDiffDrawer(data || {});
                })
                .catch(() => cmsToast('对比请求失败', 'error'));
        }

        function renderDiffDrawer(data) {
            const fields = Array.isArray(data.fields) ? data.fields : [];
            const stats = data.stats || {};

            let fieldsHtml = '';
            if (!fields.length) {
                fieldsHtml = '<tr><td colspan="3" class="text-center text-muted">除正文外无字段差异</td></tr>';
            } else {
                fieldsHtml = fields.map((f) => '<tr><td>' + cmsEscape(f.label)
                    + '</td><td>' + cmsEscape(f.old) + '</td><td>' + cmsEscape(f.new) + '</td></tr>').join('');
            }

            const baseLabel = (data.base && data.base.type === 'current')
                ? '当前内容'
                : ('版本 v' + ((data.base && data.base.version) || '?'));
            const targetLabel = '版本 v' + ((data.target && data.target.version) || '?');

            const html = '<div>'
                + (data.degraded
                    ? '<div class="alert alert-warning py-2">文档过大，已降级为整体替换展示（不逐行对比）。</div>'
                    : '')
                + '<div class="text-muted small mb-2">新增 ' + (stats.added || 0)
                    + ' 行 · 删除 ' + (stats.removed || 0) + ' 行 · 未变 ' + (stats.unchanged || 0) + ' 行</div>'
                + '<div class="table-responsive mb-2"><table class="table table-sm table-bordered">'
                + '<thead><tr><th style="width:140px">字段</th><th>' + cmsEscape(baseLabel)
                + '</th><th>' + cmsEscape(targetLabel) + '</th></tr></thead>'
                + '<tbody>' + fieldsHtml + '</tbody></table></div>'
                + '<div class="diff-view">' + (data.hunks_html || '') + '</div>'
                + '</div>';

            window.Wise.ui.dialog.open(html, targetLabel + ' ↔ ' + baseLabel, {
                area: ['900px', '680px'],
                resize: true,
            });
        }

        /** 回滚（L2 + 乐观锁基线透传）→ 定向刷新，不整页 reload */
        function rollbackVersion(versionId, versionNo, list) {
            cmsConfirm(
                '确定回滚到 v' + versionNo + '？<br><span class="small text-muted">'
                + '回滚会生成一条新版本记录，因此可再次回退。</span>',
                '回滚确认'
            ).then((ok) => {
                if (!ok) return;
                return cmsAction({
                    url: URL.rollback,
                    data: { id: contentId, version_id: versionId, base_update_time: baseUpdateTime() },
                    successMsg: '已回滚到 v' + versionNo,
                }).then((res) => {
                    if (!(res && res.success)) return;
                    const nextBase = res.data && res.data.update_time;
                    if (nextBase) $page.attr('data-base-update-time', nextBase);
                    cmsRefresh(list);
                });
            });
        }

        /** 删除版本快照 → 定向刷新，不整页 reload */
        function deleteVersion(versionId, versionNo, list) {
            cmsConfirm(
                '确定删除 v' + versionNo + ' 的快照？<br><span class="small text-muted">该操作不可恢复。</span>',
                '删除版本快照'
            ).then((ok) => {
                if (!ok) return;
                return cmsAction({
                    url: URL.versionDelete,
                    data: { id: contentId, version_id: versionId },
                    successMsg: '快照已删除',
                }).then((res) => {
                    if (!(res && res.success)) return;
                    cmsRefresh(list);
                });
            });
        }

        const list = cmsTable({
            url: URL.versions,
            pageSize: 20,
            // 版本列表的响应形状不同于标准分页：行数据在 data.data，
            // 另带 total / current_version / keep（→ syncMeta 就地更新页头）
            responseHandler(res) {
                const d = (res && res.data) || {};
                syncMeta(d);
                return {
                    rows: Array.isArray(d.data) ? d.data : [],
                    total: Number(d.total) || 0,
                };
            },
            // 列表接口按内容 id 过滤（分页参数由 cmsTable 统一映射为 page/limit）
            query() {
                return { id: contentId };
            },
            columns: [
                {
                    field: 'version',
                    title: '版本',
                    width: 130,
                    formatter: (value, row) => 'v' + (value == null ? '' : value)
                        + (row.is_latest ? ' <span class="badge bg-success">当前</span>' : '')
                        + (row.broken ? ' <span class="badge bg-danger">损坏</span>' : ''),
                },
                { field: 'title', title: '标题', formatter: (value) => value || '-' },
                { field: 'word_count', title: '字数', width: 90, align: 'right' },
                {
                    field: 'size',
                    title: '大小',
                    width: 100,
                    align: 'right',
                    formatter: (value) => (Number(value) > 0 ? (Number(value) / 1024).toFixed(1) + ' KB' : '-'),
                },
                { field: 'create_user', title: '操作人', width: 120, formatter: (value) => value || '-' },
                {
                    field: 'create_time',
                    title: '时间',
                    width: 170,
                    formatter: (value) => String(value == null ? '' : value).substring(0, 19),
                },
                { field: 'remark', title: '备注', width: 160 },
                {
                    field: 'operate',
                    title: '操作',
                    width: 200,
                    align: 'center',
                    // 损坏快照无法回滚（内核 operateButtons 无 visible 谓词 → 用 cmsOperateButtons 先过滤）
                    formatter: (value, row) => cmsOperateButtons([
                        { action: 'diff', title: '对比该版本与当前内容', icon: 'bi bi-file-diff', class: 'btn-info', label: '对比' },
                        { action: 'rollback', title: '回滚到该版本', icon: 'bi bi-arrow-counterclockwise', class: 'btn-warning', label: '回滚', show: (r) => !r.broken },
                        { action: 'del-version', title: '删除该版本快照', icon: 'bi bi-trash', class: 'btn-danger', label: '删除' },
                    ], row),
                },
            ],
            toolbarBtn: [
                {
                    id: 'btn-version-refresh',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],
            onClick(action, row) {
                if (action === 'diff') return showDiff(row.id);
                if (action === 'rollback') return rollbackVersion(row.id, row.version, list);
                if (action === 'del-version') return deleteVersion(row.id, row.version, list);
                return undefined;
            },
        });
    },
};
