/**
 * URL 规则页面控制器（UIUX-P0-19）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/url_rule`
 * （`UrlRuleController` → snake_case `url_rule`）。列表页与表单页共用本模块。
 *
 * 对应控制器动作：
 *   urlRule/index        → 列表：**返回纯数组**（无 total/current_page 等分页键）→ 前端分页
 *   urlRule/presets      → 内置预设 + 可用占位符 + 伪静态开关状态
 *   urlRule/create|edit  → 表单 + 提交（POST 返回 JSON）
 *   urlRule/delete       → 硬删除（表无软删、无回收站）
 *   urlRule/toggleStatus → 启停（**目标值语义**，P0-19 一并修正）
 *   urlRule/rebuild      → **同步**重建全站别名索引
 *
 * ⚠️ 四处已核实的事实：
 *   1. **响应形状是纯数组**：`getRules()` 返回 `select()->toArray()` → 必须用 `shape: 'flat'`
 *      （内核 `standard` 预设的 `data.data`/`data.total` 在这里全是 undefined，表格会空白）。
 *   2. **全站通用规则（`site_id=0`）原本不可见**：服务层只做精确站点匹配，而
 *      `matchRules()` 对 site_id=0 在任何站点都放行 → 已改为 `whereIn([0, 当前站点])`。
 *   3. **`rebuild` 是同步请求，没有进度接口** → 不做假进度条：L3 确认后进入
 *      loading 态并禁用按钮，完成后用后端返回的分项计数提示（内核 `loading` + 自锁按钮）。
 *   4. `toggleStatus` 原为「取反」→ 与行内开关的目标值语义不符（连点会翻回去），已改为
 *      按目标值写入，见 §1.1⑬。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsConfirm, cmsAction,
    cmsDangerConfirm, cmsEscape, cmsBindRowToggles,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('urlRule/index'),
    create: cmsUrl('urlRule/create'),
    edit: cmsUrl('urlRule/edit'),
    del: cmsUrl('urlRule/delete'),
    toggle: cmsUrl('urlRule/toggleStatus'),
    rebuild: cmsUrl('urlRule/rebuild'),
    presets: cmsUrl('urlRule/presets'),
};

const TYPE_MAP = { content: '内容详情', category: '栏目列表', tag: '标签页', page: '单页' };
const SCOPE_MAP = { global: '全站', model: '按模型', category: '按栏目', entity: '按单条实体' };

/** L3 确认词（重建会改写全站 URL，规则写错影响面很大） */
const REBUILD_WORD = 'REBUILD';

/** 示例预览用的假数据（只为让「这条规则长什么样」一眼可读） */
const SAMPLE_TOKENS = {
    id: '123', catid: '5', model: '1', slug: 'hello-world', alias: 'about',
    tag: 'php', catdir: 'news', year: '2026', month: '10', day: '10', page: '2',
};

/** 取出模板里的占位符（去重） */
function tokensIn(pattern) {
    const out = [];
    String(pattern || '').replace(/\{([a-z_]+)\}/g, (m, t) => {
        if (out.indexOf(t) === -1) out.push(t);
        return m;
    });
    return out;
}

/** 用示例数据渲染模板，便于「一眼看懂生成的 URL」 */
function renderSample(pattern) {
    return String(pattern || '').replace(/\{([a-z_]+)\}/g, (m, t) => (
        SAMPLE_TOKENS[t] !== undefined ? SAMPLE_TOKENS[t] : m
    ));
}

/**
 * GET 并取 `data`
 *
 * 内核 `http.request` 默认 `interceptor: true`（`wise-util.js:253`）→ 成功时 resolve 的
 * 就是信封里的 `data`；业务失败会 reject（内核已 toast），故此处静默兜底。
 */
async function fetchData(url, params) {
    const wise = window.Wise || {};
    if (!(wise.util && wise.util.http && typeof wise.util.http.get === 'function')) {
        return null;
    }
    try {
        return await wise.util.http.get(url, params);
    } catch (e) {
        return null;
    }
}

/**
 * 表单页初始化：预设面板 + 占位符提示 + 未知占位符告警 + 示例预览
 */
async function initPatternPanel($form) {
    const meta = await fetchData(URL.presets);
    const tokens = (meta && Array.isArray(meta.tokens)) ? meta.tokens : [];
    const presets = (meta && meta.presets) || {};

    const $type = $form.find('select[name="type"]');
    const $pattern = $form.find('input[name="pattern"]');
    const $box = $('#presetBox');

    // 占位符清单来自后端（不把 token 列表硬编码进模板，避免两边不同步）
    $('#tokenHelp').html(tokens.length
        ? '可用占位符：' + tokens.map((t) => '<code>{' + cmsEscape(t) + '}</code>').join(' ')
        : '');

    function paintPreview() {
        const pattern = String($pattern.val() || '').trim();
        const $preview = $('#patternPreview');

        if (pattern === '') {
            $preview.addClass('d-none');
            return;
        }

        const unknown = tokensIn(pattern).filter((t) => tokens.indexOf(t) === -1);
        $preview.removeClass('d-none').html(
            (unknown.length
                ? '<div class="text-danger mb-1"><i class="bi bi-exclamation-triangle"></i> '
                    + '无法识别的占位符：' + unknown.map((t) => '<code>{' + cmsEscape(t) + '}</code>').join(' ')
                    + '（保存会被后端拒绝）</div>'
                : '')
            + '<div>示例：<code>' + cmsEscape(renderSample(pattern)) + '</code>'
            + '<span class="text-muted ms-2">（占位符用示例值代入；{page}=1 时该段会被去掉）</span></div>'
        );
    }

    function paintPresets() {
        const items = presets[$type.val()] || {};
        const html = Object.keys(items).map((p) => '<button type="button"'
            + ' class="btn btn-outline-secondary btn-sm me-1 mb-1 text-start"'
            + ' data-pattern="' + cmsEscape(p) + '">' + cmsEscape(items[p])
            + '<br><code class="small">' + cmsEscape(p) + '</code></button>').join('');

        $box.html(html || '<span class="text-muted small">该类型暂无内置预设，可直接手写模板</span>');
    }

    $type.on('change', paintPresets);
    $pattern.on('input', paintPreview);

    // 预设按钮走事件委托（模板零内联 onclick）
    $box.on('click', '[data-pattern]', function () {
        $pattern.val($(this).attr('data-pattern')).trigger('input');
    });

    paintPresets();
    paintPreview();
}

/**
 * 表单页提交（create / edit 共用）
 */
function bindForm(defaultMsg) {
    const $form = $('#urlRuleForm');
    if (!$form.length) return;

    initPatternPanel($form);

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsUrlRuleFormDone === 'function') {
                window.parent.cmsUrlRuleFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        const res = await cmsAction({
            url: $form.attr('action'),
            // FormData：保留 `type` 等下拉的原值形态，后端 saveRule() 自行做整数化与白名单
            data: new FormData(this),
            successMsg: defaultMsg,
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-url-rule-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口 */
    async index() {
        let list = null;

        // 顶部状态提示：伪静态是否开启（`presets.rewrite`），比写死的静态文案准确
        const meta = await fetchData(URL.presets);
        const $hint = $('#rule-rewrite-hint');
        if ($hint.length) {
            $hint.html(meta && meta.rewrite
                ? '<span class="badge bg-success">伪静态已开启</span> 前台按下方规则生成 URL；'
                    + '修改别名后旧链接会自动 301 到新链接。'
                : '<span class="badge bg-secondary">伪静态未开启</span> 前台当前走查询串形态'
                    + '（如 <code>?id=123</code>）；在「CMS 配置」里开启后，下方规则才会生效。');
        }

        /** 打开规则表单（iframe 弹层，与栏目/单页同一套父子回调约定） */
        function openForm(url, title) {
            const index = window.Wise.ui.dialog.open(url, title, { area: ['900px', '720px'], resize: true });

            window.cmsUrlRuleFormDone = function () {
                try {
                    window.Wise.ui.dialog.close(index);
                } catch (e) {
                    // 弹层可能已被手动关闭，忽略
                }
                cmsRefresh(list);
            };

            return index;
        }

        /** L3：重建全站别名索引（**同步**执行，无进度接口 → 只有 loading 态，不做假进度条） */
        async function rebuild() {
            const ok = await cmsDangerConfirm({
                title: '重建全站 URL 索引（不可撤销）',
                word: REBUILD_WORD,
                confirmText: '开始重建',
                area: ['580px', '440px'],
                html: '<div class="alert alert-warning py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将按当前规则<b>重新生成</b>内容 / 栏目 / 标签 / 单页的别名索引，'
                    + '旧 URL 会自动 301 到新 URL。规则写错会导致全站链接变化，请先核对上方规则。'
                    + '</div>'
                    + '<div class="small text-muted">'
                    + '⚠️ 该任务是<b>同步执行</b>的（后端未提供进度接口），'
                    + '执行期间页面不显示进度条 —— 请不要重复提交或关闭页面。'
                    + '</div>',
            });
            if (!ok) return;

            const $btn = $('#btn-rebuild-url-rule');
            $btn.prop('disabled', true);

            try {
                // 原生 POST（interceptor:false）：需要拿到信封里的 `msg` —— 它带分项计数
                const wise = window.Wise || {};
                if (!(wise.util && wise.util.http && typeof wise.util.http.post === 'function')) {
                    cmsToast('请求组件未就绪', 'error');
                    return;
                }
                const body = await wise.util.http.post(URL.rebuild, {}, { interceptor: false, loading: true });

                if (body && body.code === 0) {
                    cmsToast(body.msg || 'URL 索引重建完成', 'success');
                } else {
                    cmsToast((body && body.msg) || '重建失败，请查看系统日志', 'error');
                }
            } catch (e) {
                cmsToast('重建请求失败，请稍后重试', 'error');
            } finally {
                $btn.prop('disabled', false);
            }
        }

        list = cmsTable({
            url: URL.list,
            // 接口返回纯数组（无分页键）→ 前端分页；规则数量本就很少
            shape: 'flat',
            pageSize: 15,

            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', formatter: 'normal' },
                {
                    field: 'type',
                    title: '类型',
                    width: 100,
                    align: 'center',
                    formatter: (value) => '<span class="badge bg-light text-dark">'
                        + cmsEscape(TYPE_MAP[value] || value || '') + '</span>',
                },
                {
                    field: 'scope_type',
                    title: '作用域',
                    width: 130,
                    align: 'left',
                    formatter: (value, row) => cmsEscape(SCOPE_MAP[value] || value || '')
                        + (Number(row.scope_id) > 0 ? ' <code>#' + cmsEscape(row.scope_id) + '</code>' : ''),
                },
                {
                    field: 'site_id',
                    title: '生效站点',
                    width: 100,
                    align: 'center',
                    // 0 = 全站通用（任何站点都生效）；其余为精确站点
                    formatter: (value) => (Number(value) === 0
                        ? '<span class="badge bg-info">全站</span>'
                        : '本站 <code>#' + cmsEscape(value) + '</code>'),
                },
                {
                    field: 'pattern',
                    title: '模式模板',
                    align: 'left',
                    formatter: (value) => '<code class="text-break">' + (value || '') + '</code>',
                },
                { field: 'priority', title: '优先级', width: 80, align: 'center', formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.toggle,
                    labels: { on: '启用', off: '停用' },
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 140,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                        { action: 'del', title: '删除（无回收站）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            query() {
                const type = $('#filter-type').val();
                return type ? { type: type } : {};
            },

            toolbarBtn: [
                {
                    id: 'btn-add-url-rule',
                    name: 'add',
                    title: '添加规则',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => openForm(URL.create, '添加 URL 规则'),
                },
                {
                    id: 'btn-rebuild-url-rule',
                    name: 'rebuild',
                    title: '重建 URL 索引',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-warning',
                    click: () => rebuild(),
                },
                {
                    id: 'btn-refresh-url-rule',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    return openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑 URL 规则');
                }
                if (action === 'del') {
                    // 硬删除（表无软删）→ 走明确确认，不用带"撤销"语义的 cmsDelete
                    return cmsConfirm('确定删除该规则？<br>删除后立即失效，<b>没有回收站</b>。', '删除规则')
                        .then((ok) => (ok
                            ? cmsAction({
                                url: URL.del,
                                data: { id: row.id },
                                refresh: list,
                                successMsg: '规则已删除',
                            })
                            : undefined));
                }
                return undefined;
            },
        });

        // 启停开关：提交 {id, status} 目标值（失败自动回滚勾选）
        cmsBindRowToggles({
            url: URL.toggle,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '规则已启用' : '规则已停用'),
        });

        $('#filter-type').on('change', () => cmsRefresh(list));

        return list;
    },

    /** 添加规则表单页 */
    create() {
        bindForm('规则创建成功');
    },

    /** 编辑规则表单页 */
    edit() {
        bindForm('规则更新成功');
    },
};
