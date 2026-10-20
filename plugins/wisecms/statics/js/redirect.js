/**
 * URL 重定向页面控制器（UIUX-P0-20）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/redirect`
 * （`RedirectController` → snake_case `redirect`）。列表页与表单页共用本模块。
 *
 * 对应控制器动作：
 *   redirect/index     → 列表（标准 paginate 契约；**固定页长 20**，无筛选/搜索参数）
 *   redirect/create    → 表单 + 提交（POST 返回 JSON）
 *   redirect/import    → CSV 上传（multipart `file`）→ 返回成功条数
 *   redirect/delete    → **硬删除**（表无软删、无回收站）
 *   redirect/checkDead → 死链检测（**同步**全表扫描）
 *
 * 后端没有的能力（宁缺勿假）：
 *   · **无 `edit` / `toggleStatus`** → 行操作只有「删除」；状态列是**只读徽章**，
 *     要改状态或目标地址只能删了重建（模板里的编辑入口本就不存在）。
 *   · 无搜索/筛选参数 → 不放搜索框。
 *   · CSV 导入**没有预览接口**（后端直接逐行写库并跳过非法行）→ 不做"导入预览表"，
 *     只在确认框里如实说明"非法行会被跳过"。
 *
 * ⚠️ 三处已核实的事实：
 *   1. **列表原本跨站混合**：`Redirect::index` 全表查询（重定向表有 site_id、
 *      唯一键是 `(site_id, source_url)`）→ 已改为按「后台当前站点」过滤，删除也加了归属校验。
 *   2. **死链检测原本硬编码扫站点 1**：`checkDeadLinks(1)` → 已改为当前站点。
 *   3. 两个长任务（重建类）都是**同步**请求、没有进度接口 → 只给 loading 态 + 禁用按钮，
 *      **不做假进度条**（`P1-13` 的目标是等后端提供任务化接口后再上真进度）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsConfirm, cmsAction,
    cmsDangerConfirm, cmsEscape, cmsBindRowToggles,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('redirect/index'),
    create: cmsUrl('redirect/create'),
    edit: cmsUrl('redirect/edit'),
    toggle: cmsUrl('redirect/toggleStatus'),
    import: cmsUrl('redirect/import'),
    del: cmsUrl('redirect/delete'),
    checkDead: cmsUrl('redirect/checkDead'),
};

/** L3 确认词（死链检测会对外发起大量 HEAD 请求） */
const CHECK_WORD = 'CHECK';

const STATUS_MAP = { 1: '启用', 0: '禁用' };

/** 长文本单元格：截断 + tooltip（value 已由内核转义，放入 title 属性同样安全） */
function truncCell(value) {
    if (!value) return '<span class="text-muted">—</span>';

    return '<span class="d-inline-block text-truncate" style="max-width:320px" title="'
        + value + '">' + value + '</span>';
}

export default {
    /** 列表页入口 */
    index() {
        let list = null;

        /** CSV 导入：隐藏 file input + FormData（与 P0-28 模型导入同一套做法） */
        function bindImport() {
            $('#redirect-import-file').on('change', async function () {
                const input = this;
                const file = input.files && input.files[0];
                if (!file) return;

                const ok = await cmsConfirm(
                    '将从「' + cmsEscape(file.name) + '」导入重定向规则。'
                    + '<br><span class="text-muted">格式：来源路径,目标URL[,状态码]；'
                    + '来源重复或目标非法的行会被<b>跳过</b>（明细写入系统日志），'
                    + '所以导入条数可能少于文件行数。</span>',
                    'CSV 导入确认'
                );
                if (!ok) { input.value = ''; return; }

                const formData = new FormData();
                formData.append('file', file);

                const http = window.Wise && window.Wise.util ? window.Wise.util.http : null;
                if (!http) { cmsToast('请求组件未就绪', 'error'); input.value = ''; return; }

                try {
                    // interceptor:false → 拿到完整信封，才能显示后端的具体条数提示
                    const body = await http.post(URL.import, formData, { interceptor: false, loading: true });
                    if (body && body.code === 0) {
                        cmsToast(body.msg || '导入完成', 'success');
                        cmsRefresh(list);
                    } else {
                        cmsToast((body && body.msg) || '导入失败', 'error');
                    }
                } catch (e) {
                    cmsToast('导入请求失败，请稍后重试', 'error');
                } finally {
                    input.value = '';
                }
            });
        }

        /** 死链检测结果弹层（后端本就返回 list，原实现只 toast 了条数，把明细丢了） */
        function showDeadResult(dead) {
            const rows = dead.map((item) => '<tr>'
                + '<td class="text-center">' + cmsEscape(item.id) + '</td>'
                + '<td class="text-break">' + cmsEscape(item.title) + '</td>'
                + '<td class="text-break"><code>' + cmsEscape(item.url) + '</code></td>'
                + '<td class="text-nowrap text-danger">' + cmsEscape(item.status) + '</td>'
                + '</tr>').join('');

            const wise = window.Wise || {};
            if (!(wise.ui && wise.ui.dialog)) {
                cmsToast('检测到 ' + dead.length + ' 条死链（弹层组件未就绪，明细见系统日志）', 'warning');
                return;
            }

            wise.ui.dialog.open(
                '<div class="table-responsive" style="max-height:52vh;overflow:auto">'
                + '<table class="table table-sm table-hover mb-0"><thead class="table-light"><tr>'
                + '<th style="width:80px" class="text-center">内容ID</th>'
                + '<th>标题</th><th>URL</th><th style="width:160px">响应</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>',
                '死链检测结果（' + dead.length + ' 条）',
                {
                    area: ['900px', '600px'],
                    btn: ['关闭'],
                    yes: (index) => wise.ui.dialog.close(index),
                }
            );
        }

        /** L3：死链检测（同步全表扫描，无进度接口） */
        async function checkDead() {
            const ok = await cmsDangerConfirm({
                title: '死链检测（对外发起请求）',
                word: CHECK_WORD,
                confirmText: '开始检测',
                area: ['580px', '440px'],
                html: '<div class="alert alert-warning py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将对<b>当前站点</b>的全部已发布内容逐条发起 HEAD 请求'
                    + '（每条 5 秒超时；站外目标会先过 SSRF 校验，内网地址直接跳过）。'
                    + '</div>'
                    + '<div class="small text-muted">'
                    + '⚠️ 同步执行、后端无进度接口 → 不显示进度条；内容量大时会较久，请勿重复提交。'
                    + '</div>',
            });
            if (!ok) return;

            const $btn = $('#btn-check-dead');
            $btn.prop('disabled', true);

            const http = window.Wise && window.Wise.util ? window.Wise.util.http : null;
            if (!http) { cmsToast('请求组件未就绪', 'error'); $btn.prop('disabled', false); return; }

            try {
                const body = await http.post(URL.checkDead, {}, { interceptor: false, loading: true });
                if (!body || body.code !== 0) {
                    cmsToast((body && body.msg) || '死链检测失败', 'error');
                    return;
                }

                const data = body.data || {};
                const dead = Array.isArray(data.list) ? data.list : [];

                if (dead.length === 0) {
                    cmsToast('未检测到死链', 'success');
                    return;
                }
                showDeadResult(dead);
            } catch (e) {
                cmsToast('检测请求失败，请稍后重试', 'error');
            } finally {
                $btn.prop('disabled', false);
            }
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(20)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'source_url',
                    title: '来源路径',
                    align: 'left',
                    formatter: (value) => '<code>' + truncCell(value) + '</code>',
                },
                { field: 'target_url', title: '目标地址', align: 'left', formatter: truncCell },
                {
                    field: 'type',
                    title: '类型',
                    width: 100,
                    align: 'center',
                    formatter: (value) => (Number(value) === 302
                        ? '<span class="badge bg-warning text-dark">302 临时</span>'
                        : '<span class="badge bg-success">301 永久</span>'),
                },
                { field: 'hit_count', title: '命中次数', width: 100, align: 'center', formatter: 'normal' },
                {
                    // P1-17：toggleStatus 已补齐 → 行内开关
                    field: 'status',
                    title: '状态',
                    width: 90,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.toggle,
                    labels: { on: '启用', off: '禁用' },
                },
                { field: 'create_time', title: '创建时间', width: 165, align: 'center', formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 150,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                        { action: 'del', title: '删除（无回收站）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-redirect',
                    name: 'add',
                    title: '添加重定向',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => {
                        const index = window.Wise.ui.dialog.open(URL.create, '添加重定向', {
                            area: ['760px', '520px'],
                            resize: true,
                        });
                        window.cmsRedirectFormDone = function () {
                            try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                            cmsRefresh(list);
                        };
                    },
                },
                {
                    id: 'btn-import-redirect',
                    name: 'import',
                    title: '导入 CSV',
                    icon: 'bi bi-upload',
                    class: 'btn btn-sm btn-outline-success',
                    click: () => $('#redirect-import-file').trigger('click'),
                },
                {
                    id: 'btn-check-dead',
                    name: 'check',
                    title: '检测死链',
                    icon: 'bi bi-broadcast',
                    class: 'btn btn-sm btn-warning',
                    click: () => checkDead(),
                },
                {
                    id: 'btn-refresh-redirect',
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
                        '编辑重定向',
                        { area: ['760px', '520px'], resize: true }
                    );
                    window.cmsRedirectFormDone = function () {
                        try { window.Wise.ui.dialog.close(index); } catch (e) { /* 已关闭 */ }
                        cmsRefresh(list);
                    };
                    return undefined;
                }

                if (action === 'del') {
                    // 硬删除（无软删）→ 明确确认，不用带"撤销"语义的 cmsDelete
                    return cmsConfirm(
                        '确定删除重定向 <code>' + cmsEscape(row.source_url || '') + '</code>？'
                        + '<br>删除后该路径不再跳转，<b>没有回收站</b>。',
                        '删除重定向'
                    ).then((ok) => (ok
                        ? cmsAction({
                            url: URL.del,
                            data: { id: row.id },
                            refresh: list,
                            successMsg: '已删除',
                        })
                        : undefined));
                }
                return undefined;
            },
        });

        // P1-17：状态开关（内核 toggle 处理器已内置，此处兜底绑定）
        cmsBindRowToggles({
            url: URL.toggle,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '已启用' : '已禁用'),
        });

        bindImport();

        return list;
    },

    /** 添加重定向表单页 */
    create() {
        bindForm('重定向创建成功');
    },

    /** 编辑重定向表单页（P1-17） */
    edit() {
        bindForm('重定向更新成功');
    },
};

/** 表单页提交（create / edit 共用） */
function bindForm(defaultMsg) {
    const $form = $('#redirectForm');
    if (!$form.length) return;

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsRedirectFormDone === 'function') {
                window.parent.cmsRedirectFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        const res = await cmsAction({
            url: $form.attr('action'),
            data: new FormData(this),
            successMsg: defaultMsg,
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-redirect-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}
