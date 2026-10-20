/**
 * Webhook 页面控制器（UIUX-P0-23 列表 + P0-40 表单）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/webhook`
 * （`WebhookController` → snake_case `webhook`）。
 *
 * 对应控制器动作：
 *   webhook/index  → 列表（标准 paginate 契约；**固定页长 15**，无筛选/搜索参数）
 *   webhook/create → 表单 + 提交（POST 返回 JSON）
 *   webhook/delete → **硬删除**（表无软删、无回收站）
 *
 * 后端**没有**的能力（宁缺勿假）：
 *   · **无 `edit`** → 列表**不放编辑按钮**：要改地址/密钥只能删了重建；
 *   · **无 `toggleStatus`** → 状态列是**只读徽章**；
 *   · **无投递日志接口** → 「最近状态」取自订阅表自身的 `last_status` / `retry_count`
 *     （明细在 wise-webhook 的 `webhook_sends` 表里），不假装有日志页。
 *
 * ⚠️ 四个已核实的事实：
 *   1. **订阅表是全站共享**（`wisecms_webhook` 无 `site_id`）→ 页面显式说明"不随站点选择器变化"。
 *   2. **实际会被触发的业务事件只有两个**：`content.published`、`comment.created`
 *      （`WebhookService::trigger()` 的全部调用点；原表单的 placeholder 写的是
 *      `content.created,content.updated` —— **这两个事件不存在**，属误导性提示）。
 *   3. **投递依赖 `wise-webhook` 包**：`WebhookService::trigger()` 首行
 *      `if (!class_exists(Webhook::class)) return;` → 未安装时**静默不投递**。
 *   4. `webhook_mode = queue` 时只入队，**必须部署 `php think queue:work`**，否则永远不投递。
 *      模式与依赖是否就绪由模板以 `data-*` 下发（服务端已能判定），前端据此给出警示。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsConfirm, cmsAction, cmsEscape, cmsCopy,
    cmsBindRowToggles,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('webhook/index'),
    create: cmsUrl('webhook/create'),
    edit: cmsUrl('webhook/edit'),
    toggle: cmsUrl('webhook/toggleStatus'),
    test: cmsUrl('webhook/test'),
    del: cmsUrl('webhook/delete'),
};

/** 插件当前**实际会触发**的事件（与 `WebhookService::trigger()` 的调用点一一对应） */
const KNOWN_EVENTS = [
    { name: 'content.published', label: '内容发布' },
    { name: 'comment.created', label: '新评论' },
];

/**
 * 事件徽章
 *
 * 不用内核 `tags` formatter：它按 `,` 切分但**不 trim**（`enhanced-table.js:2454`），
 * 而 `events` 允许 `"a, b"` 这种带空格的写法 → tag 会渲染成 ` content.published`。
 */
function eventsCell(value) {
    const items = String(value || '').split(',').map((s) => s.trim()).filter(Boolean);
    if (!items.length) return '<span class="text-muted">未配置</span>';

    return items.map((e) => '<span class="badge bg-light text-dark me-1">' + cmsEscape(e) + '</span>').join('');
}

/** 最近投递状态：NULL = 未投递（含队列模式）/ 0 = 失败 / 2xx = 成功 / 其余 = HTTP 码 */
function lastStatusCell(value) {
    if (value === null || value === undefined || value === '') {
        return '<span class="text-muted" title="尚未投递，或队列模式下结果未知">未投递</span>';
    }

    const code = parseInt(value, 10);
    if (code === 0) return '<span class="badge bg-danger">失败</span>';
    if (code >= 200 && code < 300) return '<span class="badge bg-success">成功 ' + code + '</span>';

    return '<span class="badge bg-warning text-dark">HTTP ' + code + '</span>';
}

/** 生成 HMAC 密钥（32 位十六进制） */
function randomSecret() {
    const bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    }

    return Array.prototype.map.call(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** 表单页（create / edit 共用；`defaultMsg` 区分成功提示） */
function bindForm(defaultMsg) {
    const $form = $('#webhookForm');
    if (!$form.length) return;

    const $secret = $form.find('input[name="secret"]');

    // ── 事件：勾选框 + 自定义补充 → 合并进隐藏字段 `events` ──
    function collectEvents() {
        const picked = [];
        $('#eventChecks input[type="checkbox"]').each(function () {
            if ($(this).prop('checked')) picked.push($(this).attr('data-event'));
        });

        const extra = String($('#eventsExtra').val() || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);

        // 去重（勾选 + 手填可能重复）
        return picked.concat(extra).filter((v, i, arr) => arr.indexOf(v) === i);
    }

    // ── 密钥：显示/隐藏、生成、复制 ──
    $('#btn-secret-generate').on('click', () => {
        $secret.attr('type', 'text').val(randomSecret());
        cmsToast('已生成新密钥，保存后生效', 'success');
    });

    $('#btn-secret-copy').on('click', () => {
        const value = String($secret.val() || '');
        if (value === '') {
            cmsToast('密钥为空', 'warning');
            return;
        }
        cmsCopy(value).then((ok) => cmsToast(ok ? '密钥已复制' : '复制失败，请手动选择复制', ok ? 'success' : 'warning'));
    });

    $('#btn-secret-toggle').on('click', function () {
        const $btn = $(this);
        const hidden = $secret.attr('type') === 'password';
        $secret.attr('type', hidden ? 'text' : 'password');
        $btn.find('i').attr('class', hidden ? 'bi bi-eye-slash' : 'bi bi-eye');
    });

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsWebhookFormDone === 'function') {
                window.parent.cmsWebhookFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();

        const events = collectEvents();
        if (!events.length) {
            cmsToast('请至少选择一个触发事件', 'warning');
            return;
        }
        $('#eventsValue').val(events.join(','));

        const res = await cmsAction({
            url: $form.attr('action'),
            data: new FormData(this),
            successMsg: defaultMsg || '保存成功',
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-webhook-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口 */
    index() {
        let list = null;

        /** 复制回调地址（方便贴到对端平台配置里） */
        function copyUrl(row) {
            return cmsCopy(row.url || '').then((ok) => {
                cmsToast(ok ? '回调地址已复制' : '复制失败，请手动复制', ok ? 'success' : 'warning');
            });
        }

        /** P1-16：测试投递（发 `test` 事件，回显状态码 + 耗时） */
        function test(row) {
            const wise = window.Wise || {};
            if (!(wise.util && wise.util.http && typeof wise.util.http.post === 'function')) {
                cmsToast('请求组件未就绪', 'error');
                return undefined;
            }
            // interceptor:false → 完整信封 {code,msg,data}；loading:true → 全局遮罩
            return wise.util.http.post(URL.test, { id: row.id }, { interceptor: false, loading: true })
                .then((body) => {
                    if (body && body.code === 0) {
                        const d = body.data || {};
                        const code = Number(d.code) || 0;
                        const elapsed = Number(d.elapsed_ms) || 0;
                        const ok = Boolean(d.success) || (code >= 200 && code < 300);
                        cmsToast(
                            (ok ? '测试成功：' : '测试失败：') + 'HTTP ' + code + '，耗时 ' + elapsed + ' ms',
                            ok ? 'success' : 'error'
                        );
                    } else {
                        cmsToast((body && body.msg) || '测试投递失败', 'error');
                    }
                })
                .catch(() => cmsToast('测试请求失败，请稍后重试', 'error'));
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(15)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', formatter: 'normal' },
                { field: 'name', title: '名称', width: 150, align: 'left', formatter: 'normal' },
                {
                    field: 'url',
                    title: '回调地址',
                    align: 'left',
                    formatter: (value) => (value
                        ? '<span class="d-inline-block text-truncate" style="max-width:320px" title="'
                            + value + '"><code>' + value + '</code></span>'
                        : '<span class="text-muted">—</span>'),
                },
                { field: 'events', title: '订阅事件', align: 'left', formatter: eventsCell },
                {
                    // 后端**不下发** secret（只给 has_secret），故这里只能显示"是否已设置"
                    field: 'has_secret',
                    title: '密钥',
                    width: 90,
                    align: 'center',
                    formatter: (value) => (value
                        ? '<span class="badge bg-success">已设置</span>'
                        : '<span class="badge bg-secondary">未设置</span>'),
                },
                { field: 'last_status', title: '最近投递', width: 110, align: 'center', formatter: lastStatusCell },
                { field: 'retry_count', title: '重试次数', width: 90, align: 'center', formatter: 'normal' },
                {
                    // P1-16：toggleStatus 已补齐 → 行内开关
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.toggle,
                    labels: { on: '启用', off: '禁用' },
                },
                { field: 'create_time', title: '创建时间', width: 165, align: 'center', visible: false, formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 240,
                    align: 'center',
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                        { action: 'test', title: '测试发送', icon: 'bi bi-send', class: 'btn-outline-info' },
                        { action: 'copy', title: '复制回调地址', icon: 'bi bi-clipboard', class: 'btn-outline-secondary' },
                        { action: 'del', title: '删除（无回收站）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-webhook',
                    name: 'add',
                    title: '添加 Webhook',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => {
                        const index = window.Wise.ui.dialog.open(URL.create, '添加 Webhook', {
                            area: ['860px', '700px'],
                            resize: true,
                        });
                        window.cmsWebhookFormDone = function () {
                            try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                            cmsRefresh(list);
                        };
                    },
                },
                {
                    id: 'btn-refresh-webhook',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    const index = window.Wise.ui.dialog.open(
                        URL.edit + '?id=' + encodeURIComponent(row.id),
                        '编辑 Webhook',
                        { area: ['860px', '700px'], resize: true }
                    );
                    window.cmsWebhookFormDone = function () {
                        try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                        cmsRefresh(list);
                    };
                    return undefined;
                }

                if (action === 'test') return test(row);

                if (action === 'copy') return copyUrl(row);

                if (action === 'del') {
                    return cmsConfirm(
                        '确定删除 Webhook「' + cmsEscape(row.name || '') + '」？<br>'
                        + '删除后该地址<b>不再收到任何事件</b>，且没有回收站。',
                        '删除 Webhook'
                    ).then((ok) => (ok
                        ? cmsAction({
                            url: URL.del,
                            data: { id: row.id },
                            refresh: list,
                            successMsg: 'Webhook 已删除',
                        })
                        : undefined));
                }
                return undefined;
            },
        });

        // P1-16：状态开关（内核 toggle 处理器已内置，此处兜底绑定）
        cmsBindRowToggles({
            url: URL.toggle,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '已启用' : '已禁用'),
        });

        return list;
    },

    /** 添加 Webhook 表单页 */
    create() {
        bindForm('Webhook 创建成功');
    },

    /** 编辑 Webhook 表单页（P1-16，回显事件勾选） */
    edit() {
        bindForm('Webhook 更新成功');

        // 回显事件勾选：已知事件勾选 checkbox，其余进「其他事件」输入框
        const raw = String($('#webhookForm').attr('data-events') || '');
        const events = raw.split(',').map((s) => s.trim()).filter(Boolean);
        const known = new Set(KNOWN_EVENTS.map((e) => e.name));
        const extra = [];

        events.forEach((ev) => {
            if (known.has(ev)) {
                $('#eventChecks input[data-event="' + ev + '"]').prop('checked', true);
            } else {
                extra.push(ev);
            }
        });
        $('#eventsExtra').val(extra.join(', '));
    },
};

export { KNOWN_EVENTS };
