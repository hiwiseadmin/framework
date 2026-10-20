/**
 * document.js — 文档管理（ESM 控制器）
 *
 * 页面职责：
 *   index()  列表页：筛选 + 表格 + 发布/派生/删除 + 发布前差异确认；
 *   form()   新增/编辑独立页：由列表页以 ws-layer（iframe）载入，
 *            自己负责表单校验、类型切换、正文编辑器与提交。
 *
 * 四类内容（文章 / 代码 / 组件 / API）共用同一份表单，按 content_type 切换字段；
 * 编辑时通过 document/detail 拉取正文（列表接口不返回长文本）。
 *
 * 批次 2：内容生命周期
 *   - 「保存」= 已发布文档的改动进编辑稿（不覆盖线上）；「保存并发布」= 保存 + 发布（写快照）
 *   - 上线开关走框架的 toggle formatter（document/status），发布状态单独成一列
 *   - 发布前差异确认：diff 由后端给两份原文，前端算行级差异并渲染
 *   - 批量发布 / 批量转草稿
 */

import { http, $, toast, submit, confirm } from './common/util.js';
import { createTable, operateButtons, refresh, selectedIds, bindListState } from './common/table.js';

const URL = {
    list:    '/plugin/wisedocs/document/getList',
    form:    '/plugin/wisedocs/document/form',
    detail:  '/plugin/wisedocs/document/detail',
    save:    '/plugin/wisedocs/document/save',
    del:     '/plugin/wisedocs/document/delete',
    clone:   '/plugin/wisedocs/document/clone',
    publish: '/plugin/wisedocs/document/publish',
    batch:   '/plugin/wisedocs/document/batch',
    diff:    '/plugin/wisedocs/document/diff',
};

const TYPE = { ARTICLE: 1, CODE: 2, COMPONENT: 3, API: 4 };

/** 发布状态（与后端 Document::PUBLISH_STATE_* 对齐） */
const STATE = { DRAFT: 0, PUBLISHED: 1, PENDING: 2 };

/** 前台详情页地址（「预览」按钮跳转目标，新窗口打开） */
const VIEW_URL = '/plugin/wisedocs/index/detail';

/** 演示区域默认高度（与服务端 ComponentPreviewService::DEFAULT_HEIGHT 对齐） */
const DEFAULT_DEMO_HEIGHT = 320;

/* ------------------------------------------------------------------ *
 * 正文编辑器（wiseeditor 软集成）
 * ------------------------------------------------------------------ */

/**
 * 各编辑模式的容器 id、字段 id 与所在 slot
 *
 * - markdown / html：容器由后端 EditorManager 渲染（wiseeditor 缺失时渲染 textarea）
 * - code：纯文本通道，始终是 textarea，不接编辑器
 */
const CONTENT_EDITORS = {
    markdown: { container: 'editor_content_md', field: 'editor_content_md_textarea', slot: '#slot-content-md' },
    html:     { container: 'editor_content_rt', field: 'editor_content_rt_input',    slot: '#slot-content-rt' },
    code:     { container: null,                field: 'editor_content_code_textarea', slot: '#slot-content-code' },
};

/** 按内容类型 + 正文格式解析实际编辑模式 */
function resolveMode(contentType, format) {
    if (Number(contentType) === TYPE.CODE) {
        return 'code';
    }

    return String(format) === 'html' ? 'html' : 'markdown';
}

/**
 * 读取正文
 *
 * 编辑器已挂载时必须走实例 API（WiseEditor 只在 init 时读一次字段初值），
 * 未挂载（wiseeditor 不可用 / 代码型）则回退到字段本身。
 */
function readContent(mode) {
    const cfg = CONTENT_EDITORS[mode] || CONTENT_EDITORS.markdown;

    const editor = cfg.container && window.WiseEditor && window.WiseEditor.get(cfg.container);
    if (editor && typeof editor.getContent === 'function') {
        return editor.getContent() || '';
    }

    const field = document.getElementById(cfg.field);

    return field ? (field.value || '') : '';
}

/** 写入正文（编辑器实例存在时必须用 setContent，否则界面不会更新） */
function writeContent(mode, value) {
    const cfg = CONTENT_EDITORS[mode] || CONTENT_EDITORS.markdown;
    const content = value || '';

    const editor = cfg.container && window.WiseEditor && window.WiseEditor.get(cfg.container);
    if (editor && typeof editor.setContent === 'function') {
        editor.setContent(content);
    }

    const field = document.getElementById(cfg.field);
    if (field) {
        field.value = content;
    }
}

/* ------------------------------------------------------------------ *
 * 行级差异（前端计算）
 * ------------------------------------------------------------------ */

/**
 * 把两段文本拆成行级差异
 *
 * 先裁剪公共前后缀缩小规模，中间部分用 LCS 求最小编辑序列；
 * 规模过大（> 40 万格）时退化为「整段删除 + 整段新增」，避免阻塞界面。
 *
 * @returns {Array<{type: 'same'|'add'|'del', text: string}>}
 */
function diffLines(oldText, newText) {
    const a = oldText === '' ? [] : String(oldText).split(/\r\n|\r|\n/);
    const b = newText === '' ? [] : String(newText).split(/\r\n|\r|\n/);

    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) {
        start++;
    }

    let endA = a.length;
    let endB = b.length;
    while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
        endA--;
        endB--;
    }

    const rows = [];
    let i;

    for (i = 0; i < start; i++) {
        rows.push({ type: 'same', text: a[i] });
    }

    const midA = a.slice(start, endA);
    const midB = b.slice(start, endB);

    if (midA.length * midB.length > 400000) {
        midA.forEach((t) => rows.push({ type: 'del', text: t }));
        midB.forEach((t) => rows.push({ type: 'add', text: t }));
    } else {
        rows.push(...diffBlock(midA, midB));
    }

    for (i = endA; i < a.length; i++) {
        rows.push({ type: 'same', text: a[i] });
    }

    return rows;
}

/** 中间区块的 LCS 差异 */
function diffBlock(a, b) {
    const n = a.length;
    const m = b.length;

    if (n === 0 && m === 0) return [];
    if (n === 0) return b.map((t) => ({ type: 'add', text: t }));
    if (m === 0) return a.map((t) => ({ type: 'del', text: t }));

    const dp = [];
    for (let i = 0; i <= n; i++) {
        dp.push(new Array(m + 1).fill(0));
    }

    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = a[i] === b[j]
                ? dp[i + 1][j + 1] + 1
                : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }

    const rows = [];
    let i = 0;
    let j = 0;

    while (i < n && j < m) {
        if (a[i] === b[j]) {
            rows.push({ type: 'same', text: a[i] });
            i++;
            j++;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) {
            rows.push({ type: 'del', text: a[i] });
            i++;
        } else {
            rows.push({ type: 'add', text: b[j] });
            j++;
        }
    }

    while (i < n) {
        rows.push({ type: 'del', text: a[i] });
        i++;
    }
    while (j < m) {
        rows.push({ type: 'add', text: b[j] });
        j++;
    }

    return rows;
}

function escapeHtml(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/* ------------------------------------------------------------------ *
 * 组件型沙箱预览
 * ------------------------------------------------------------------ */

/** 转义可能提前闭合内联标签的序列（与服务端 escapeClosingTag 保持一致） */
function breakClosingTag(code, tag) {
    if (!code) {
        return '';
    }

    return String(code).replace(new RegExp('</\\s*' + tag, 'gi'), '<\\/' + tag);
}

/** 本地组装沙箱预览文档（不提交、不落库） */
function buildDemoDocument(html, css, js) {
    const base = '*{box-sizing:border-box}html,body{margin:0;padding:0}' +
        'body{padding:16px;font-size:14px;line-height:1.6;color:#212529;background:#fff}';

    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1">' +
        '<style>' + base + '\n' + breakClosingTag(css, 'style') + '</style>' +
        '</head><body>' + html +
        (js ? '<script>' + breakClosingTag(js, 'script') + '<\/script>' : '') +
        '</body></html>';
}

const OPERATE = [
    { action: 'publish', title: '发布', icon: 'bi bi-send-check',         class: 'btn-outline-success' },
    { action: 'preview', title: '预览', icon: 'bi bi-box-arrow-up-right', class: 'btn-outline-secondary' },
    { action: 'clone',   title: '派生', icon: 'bi bi-files',              class: 'btn-outline-secondary' },
    { action: 'edit',    title: '编辑', icon: 'bi bi-pencil',             class: 'btn-outline-primary' },
    { action: 'del',     title: '删除', icon: 'bi bi-trash',              class: 'btn-outline-danger' },
];

const STATE_BADGE = { 0: 'secondary', 1: 'success', 2: 'warning' };

function badge(text, type) {
    return '<span class="badge bg-' + (type || 'secondary') + '">' + (text || '') + '</span>';
}

/* ------------------------------------------------------------------ *
 * 独立编辑页（ws-layer iframe）
 * ------------------------------------------------------------------ */

/**
 * 打开新增 / 编辑页
 *
 * @param {number} id      0 表示新增
 * @param {Function} onSaved 提交成功后的回调（一般是刷新列表）
 */
function openDocumentForm(id, onSaved) {
    const docId = Number(id) || 0;
    const url = URL.form + '?id=' + docId;

    const layer = window.Wise && window.Wise.ui && window.Wise.ui.dialog;

    if (!layer) {
        // 没有弹层库时退化为新窗口，功能不丢失
        window.open(url, '_blank', 'noopener');
        return;
    }

    const index = layer.open(url, docId > 0 ? '编辑文档' : '新增文档', {
        area: ['94%', '92%'],
        // ws-layer 全局 maxWidth 默认 860px，会把 94% 压回 860（表单只能单列）。
        // 编辑页需要「左正文 + 右设置」双列，这里单独放宽，仍受视口宽度约束。
        maxWidth: 1200,
        resize: true,
        shadeClose: false,
        // 页面自带操作栏（保存 / 保存并发布），不要弹层默认的「确定」按钮
        btn: false,
    });

    // 供 iframe 页提交成功后回调：关闭当前弹层 + 刷新列表
    window.wisedocsDocumentSaved = function () {
        try {
            layer.close(index);
        } catch (e) {
            // 用户已手动关闭
        }
        if (typeof onSaved === 'function') {
            onSaved();
        }
    };

    // 供 iframe 页「取消」回调：只关闭弹层（弹层实例在父页，子页的 wsLayer 关不掉）
    window.wisedocsDocumentClose = function () {
        try {
            layer.close(index);
        } catch (e) {
            // 用户已手动关闭
        }
    };
}

/** 关闭当前 iframe 弹层（无弹层环境则返回列表页） */
function closeFormPage() {
    const parentWin = window.parent && window.parent !== window ? window.parent : null;

    if (parentWin && typeof parentWin.wisedocsDocumentSaved === 'function') {
        parentWin.wisedocsDocumentSaved();
        return;
    }

    if (window.wsLayer && typeof window.wsLayer.closeFrame === 'function') {
        window.wsLayer.closeFrame();
        return;
    }

    window.location.href = '/plugin/wisedocs/document';
}

/** 编辑页初始化（Controller.form） */
function initFormPage() {
    const $form = $('#document-form');
    if (!$form.length) return;

    const docId = Number($('#form-id').val()) || 0;

    // 在 ws-layer iframe 内打开时隐藏页面标题（弹层标题栏已有），避免重复
    if (window.self !== window.top) {
        document.body.classList.add('docs-form--embedded');
    }

    if (docId > 0) {
        $('#form-title').text('编辑文档');
        // 编辑已有文档时格式锁定（后端也会强制以库中值为准），如需换格式请用「派生」
        $('#form-content-format').prop('disabled', true);
    }

    // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
    const validator = new window.WsFormValidator('document-form', {
        liveValidation: true,
        showIcons: true,
    });

    // 正文必填：三种编辑模式各挂一条，用 when 互斥（编辑器值必须用 getValue 取）
    const contentFeedback = document.getElementById('content-feedback');

    ['markdown', 'html', 'code'].forEach(function (mode) {
        const cfg = CONTENT_EDITORS[mode];

        validator.setRules('#' + cfg.field, 'required', {
            when: () => resolveMode($('#form-content-type').val(), $('#form-content-format').val()) === mode,
            getValue: () => readContent(mode),
            uiTarget: document.querySelector(cfg.slot),
            feedback: contentFeedback,
            messages: { required: '请填写正文' },
        });
    });

    /* ------------------------------------------------------------------ *
     * 正文编辑模式
     * ------------------------------------------------------------------ */

    function applyContentMode(contentType, format) {
        const mode = resolveMode(contentType, format);
        const isCode = mode === 'code';

        // 同名字段（三个 slot 里都是 name="content"）只能有一个参与提交
        Object.keys(CONTENT_EDITORS).forEach(function (key) {
            const cfg = CONTENT_EDITORS[key];
            $(cfg.slot).toggleClass('d-none', key !== mode);
            $('#' + cfg.field).prop('disabled', key !== mode);
        });

        // 代码型没有「格式」概念，隐藏格式选择
        $('#form-content-format').toggleClass('d-none', isCode);

        const hints = {
            code: '纯文本代码内容，前台以代码块展示；代码型不参与富文本净化。',
            markdown: 'Markdown 语法，可预览。当前版本不支持内嵌图片（图片语法会显示为链接）。',
            html: '支持部分 HTML（脚本、事件属性、iframe 等会被服务端清除）。',
        };

        $('#hint-content').text(hints[mode] || '');

        return mode;
    }

    $('#form-content-format').on('change', function () {
        applyContentMode($('#form-content-type').val(), $(this).val());
    });

    // 按内容类型切换表单字段
    function applyTypeUI(type) {
        type = Number(type);

        $('#group-code-language').toggleClass('d-none', type !== TYPE.CODE);
        $('#group-api-method').toggleClass('d-none', type !== TYPE.API);
        $('#group-api-endpoint').toggleClass('d-none', type !== TYPE.API);
        $('#group-component').toggleClass('d-none', type !== TYPE.COMPONENT);

        const labels = {
            [TYPE.ARTICLE]: '正文',
            [TYPE.CODE]: '代码内容',
            [TYPE.COMPONENT]: '组件说明 / 用法',
            [TYPE.API]: '接口说明 / 响应示例',
        };
        $('#label-content').html((labels[type] || '正文') + ' <span class="text-danger">*</span>');

        // 切走组件类型时收起本地预览，避免误以为已保存
        if (type !== TYPE.COMPONENT) {
            $('#demo-preview-wrap').addClass('d-none');
        }

        // 条件必填：仅组件型要求填写「示例 HTML 结构」。
        validator.setRules('#form-demo-html', { required: true }, {
            when: () => Number($('#form-content-type').val()) === TYPE.COMPONENT,
            messages: { required: '请填写组件示例的 HTML 结构' },
        });

        applyContentMode(type, $('#form-content-format').val());
    }

    $('#form-content-type').on('change', function () { applyTypeUI($(this).val()); });

    // 组件示例本地沙箱预览（srcdoc 组装，不提交、不落库）
    $('#btn-demo-preview').on('click', function () {
        const html = $('#form-demo-html').val() || '';
        if (html.trim() === '') {
            toast('请先填写示例 HTML 结构', 'warning');
            return;
        }

        const frame = document.getElementById('demo-preview-frame');
        if (frame) {
            frame.style.height = (Number($('#form-demo-height').val()) || DEFAULT_DEMO_HEIGHT) + 'px';
            // iframe 已声明 sandbox="allow-scripts allow-popups"，不授予同源
            frame.srcdoc = buildDemoDocument(
                html,
                $('#form-demo-css').val() || '',
                $('#form-demo-js').val() || ''
            );
        }

        $('#demo-preview-wrap').removeClass('d-none');
    });

    /* ------------------------------------------------------------------ *
     * 回填 / 提交
     * ------------------------------------------------------------------ */

    function setDraftHint(text) {
        const hintEl = document.getElementById('form-draft-hint');
        if (hintEl) hintEl.textContent = text || '';
    }

    function fillForm(doc) {
        const contentType = Number(doc.content_type) || TYPE.ARTICLE;
        const format = String(doc.content_format) === 'html' ? 'html' : 'markdown';

        $('#form-project-id').val(doc.project_id || 0);
        $('#form-category-id').val(doc.category_id || '');
        $('#form-title-input').val(doc.title || '');
        $('#form-slug').val(doc.slug || '');
        $('#form-content-type').val(contentType);
        $('#form-content-format').val(format);
        $('#form-status').val(doc.status != null ? doc.status : 1);
        $('#form-sort').val(doc.sort_order || 0);
        $('#form-published-at').val(
            doc.published_at ? String(doc.published_at).replace(' ', 'T').slice(0, 16) : ''
        );
        $('#form-excerpt').val(doc.excerpt || '');
        $('#form-cover').val(doc.cover || '');
        $('#form-code-language').val(doc.code_language || '');
        $('#form-api-method').val(doc.api_method || 'GET');
        $('#form-api-endpoint').val(doc.api_endpoint || '');
        $('#form-is-top').prop('checked', Number(doc.is_top) === 1);
        $('#form-is-featured').prop('checked', Number(doc.is_featured) === 1);
        $('#form-tag-ids').val((doc.tag_ids || []).map(String));

        // 组件型演示代码（服务端 detail 返回 demo 结构）
        const demo = doc.demo || {};
        $('#form-demo-html').val(demo.html || '');
        $('#form-demo-css').val(demo.css || '');
        $('#form-demo-js').val(demo.js || '');
        $('#form-demo-height').val(demo.height || DEFAULT_DEMO_HEIGHT);
        $('#demo-preview-wrap').addClass('d-none');

        // 编辑稿提示：让「保存」与「保存并发布」的差别可见
        if (doc.has_draft) {
            setDraftHint('当前显示的是未发布的编辑稿，需点「保存并发布」才会生效');
        } else if (Number(doc.publish_state) === STATE.PUBLISHED) {
            setDraftHint('这是已发布内容，保存的改动需发布后才会生效');
        } else {
            setDraftHint('');
        }

        // 先切字段可见性，再灌正文（applyTypeUI 内部会调用 applyContentMode）
        applyTypeUI(contentType);

        Object.keys(CONTENT_EDITORS).forEach(function (mode) {
            writeContent(mode, '');
        });
        writeContent(resolveMode(contentType, format), doc.content || '');
    }

    /** 「保存并发布」标记：由页脚两个按钮决定 */
    let publishAfterSave = false;

    $('#btn-save-draft').on('click', function () {
        publishAfterSave = false;
        $form.trigger('submit');
    });

    $('#btn-save-publish').on('click', function () {
        publishAfterSave = true;
        $form.trigger('submit');
    });

    $('#btn-cancel').on('click', function () {
        // 取消只关闭弹层，不触发父页刷新（弹层实例在父页，必须由父页关闭）
        const parentWin = window.parent && window.parent !== window ? window.parent : null;

        if (parentWin && typeof parentWin.wisedocsDocumentClose === 'function') {
            parentWin.wisedocsDocumentClose();
            return;
        }

        if (window.wsLayer && typeof window.wsLayer.closeFrame === 'function') {
            window.wsLayer.closeFrame();
            return;
        }

        window.location.href = '/plugin/wisedocs/document';
    });

    $form.on('submit', async function (e) {
        e.preventDefault();

        // 校验交由 WsFormValidator 统一负责（错误内联到字段，不再弹 toast）
        if (validator.validateForm() !== true) return;

        const contentType = Number($('#form-content-type').val()) || TYPE.ARTICLE;
        const format = $('#form-content-format').val() === 'html' ? 'html' : 'markdown';
        const mode = resolveMode(contentType, format);
        const willPublish = publishAfterSave;

        const data = {
            id: Number($('#form-id').val()) || 0,
            project_id: Number($('#form-project-id').val()) || 0,
            category_id: Number($('#form-category-id').val()) || 0,
            title: $('#form-title-input').val() || '',
            slug: $('#form-slug').val() || '',
            content_type: contentType,
            // 代码型固定 html（与服务端 normalizeContentFormat 同口径）
            content_format: contentType === TYPE.CODE ? 'html' : format,
            content: readContent(mode),
            status: Number($('#form-status').val()),
            sort_order: Number($('#form-sort').val()) || 0,
            published_at: $('#form-published-at').val() || '',
            excerpt: $('#form-excerpt').val() || '',
            cover: $('#form-cover').val() || '',
            code_language: $('#form-code-language').val() || '',
            api_method: $('#form-api-method').val() || 'GET',
            api_endpoint: $('#form-api-endpoint').val() || '',
            is_top: $('#form-is-top').is(':checked') ? 1 : 0,
            is_featured: $('#form-is-featured').is(':checked') ? 1 : 0,
            tag_ids: ($('#form-tag-ids').val() || []).map(Number),
            // 组件型（D4）：示例代码不净化，由沙箱隔离执行
            demo_html: $('#form-demo-html').val() || '',
            demo_css: $('#form-demo-css').val() || '',
            demo_js: $('#form-demo-js').val() || '',
            demo_height: Number($('#form-demo-height').val()) || DEFAULT_DEMO_HEIGHT,
            // 保存并发布：后端在同一事务内完成发布（合并编辑稿 + 写快照）
            publish_now: willPublish ? 1 : 0,
        };

        const done = await submit({
            url: URL.save,
            data,
            message: willPublish ? '已保存并发布' : (data.id > 0 ? '修改成功' : '添加成功'),
        });

        if (done) {
            publishAfterSave = false;
            // 先让成功提示展示出来，再关闭弹层并刷新父页列表
            setTimeout(closeFormPage, 320);
        }
    });

    // ---- 初始化 ----
    applyTypeUI(TYPE.ARTICLE);

    if (docId > 0) {
        http.get(URL.detail, { id: docId }).then(function (doc) {
            if (doc) fillForm(doc);
        }).catch(function () {
            // 错误提示由内核 http 统一处理
        });
    } else {
        setDraftHint('「保存」存为草稿，「保存并发布」立即上线');
        const titleInput = document.getElementById('form-title-input');
        if (titleInput) titleInput.focus();
    }
}

const Controller = {
    index() {
        // 支持从概览工作台带参进入：
        //   ?status=0 / ?content_type=2 / ?category_id=3 / ?keyword=xx / ?publish_state=2（预置筛选）
        //   ?edit=12（直接打开该文档的编辑页）
        const params = new URLSearchParams(window.location.search);

        const FILTER_MAP = {
            keyword: '#search-keyword',
            category_id: '#search-category',
            content_type: '#search-content-type',
            status: '#search-status',
            publish_state: '#search-publish-state',
            tag_id: '#search-tag',
        };

        // 列表筛选状态：先恢复上次会话，再由 URL 参数覆盖（工作台下钻等带参入口优先）
        bindListState('document', $('#search-form'));

        Object.keys(FILTER_MAP).forEach(function (key) {
            const value = params.get(key);
            if (value !== null && value !== '') {
                $(FILTER_MAP[key]).val(value);
            }
        });

        const table = createTable({
            url: URL.list,
            // 批量选择：与批量接口（delete / publish / batch 均接受 ids 数组）配套
            checkboxHeader: true,
            columns: [
                { field: 'ck', checkbox: true, align: 'center', valign: 'middle' },
                { field: 'id', title: 'ID', width: 70, align: 'center', sortable: true },
                { field: 'title', title: '标题' },
                { field: 'category_name', title: '分类', width: 130 },
                {
                    field: 'content_type',
                    title: '类型',
                    width: 80,
                    align: 'center',
                    formatter(value, row) {
                        const map = { 1: 'primary', 2: 'info', 3: 'success', 4: 'warning' };
                        return badge(row.content_type_text, map[Number(row.content_type)] || 'secondary');
                    },
                },
                {
                    field: 'content_format',
                    title: '格式',
                    width: 95,
                    align: 'center',
                    formatter(value, row) {
                        const isMd = String(row.content_format) === 'markdown';
                        return badge(row.content_format_text || (isMd ? 'Markdown' : '富文本'), isMd ? 'info' : 'secondary');
                    },
                },
                {
                    field: 'publish_state',
                    title: '发布状态',
                    width: 100,
                    align: 'center',
                    formatter(value, row) {
                        return badge(row.publish_state_text, STATE_BADGE[Number(row.publish_state)] || 'secondary');
                    },
                },
                {
                    field: 'status',
                    title: '上线',
                    width: 80,
                    align: 'center',
                    // 框架内置行内开关（yes/no + url），无需自写前端控件
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/plugin/wisedocs/document/status',
                },
                { field: 'view_count', title: '浏览', width: 70, align: 'center' },
                { field: 'update_time', title: '更新时间', width: 160 },
                {
                    field: 'operate',
                    title: '操作',
                    width: 290,
                    align: 'center',
                    formatter(value, row) {
                        return operateButtons(OPERATE, row);
                    },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    category_id: $('#search-category').val() || '',
                    content_type: $('#search-content-type').val() || '',
                    status: $('#search-status').val() || '',
                    publish_state: $('#search-publish-state').val() || '',
                    tag_id: $('#search-tag').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-add',
                    name: 'add',
                    title: '新增文档',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary mx-1',
                    click() { openDocumentForm(0, () => refresh(table)); },
                },
                {
                    id: 'btn-batch-publish',
                    name: 'batchPublish',
                    title: '批量发布',
                    icon: 'bi bi-send-check',
                    class: 'btn btn-sm btn-success mx-1',
                    click() { batchPublish(); },
                },
                {
                    id: 'btn-batch-draft',
                    name: 'batchDraft',
                    title: '批量转草稿',
                    icon: 'bi bi-file-earmark-arrow-down',
                    class: 'btn btn-sm btn-outline-secondary mx-1',
                    click() { batchDraft(); },
                },
                {
                    id: 'btn-batch-del',
                    name: 'batchDel',
                    title: '批量删除',
                    icon: 'bi bi-trash3',
                    class: 'btn btn-sm btn-danger mx-1',
                    click() { batchRemove(); },
                },
                {
                    id: 'btn-refresh',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-info mx-1',
                    click() { refresh(table); },
                },
            ],
            onClick(action, row) {
                if (action === 'publish') return publishOne(row);
                if (action === 'preview') {
                    window.open(VIEW_URL + '?slug=' + encodeURIComponent(row.slug || ''), '_blank', 'noopener');
                    return;
                }
                if (action === 'clone') return cloneDoc(row);
                if (action === 'edit') return openDocumentForm(row.id, () => refresh(table));
                if (action === 'del') return remove(row);
            },
        });

        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-category').val('');
            $('#search-content-type').val('');
            $('#search-status').val('');
            $('#search-publish-state').val('');
            $('#search-tag').val('');
            refresh(table);
        });

        /* ------------------------------------------------------------------ *
         * 发布状态机
         * ------------------------------------------------------------------ */

        let pendingPublishId = 0;

        /** 单条发布：有待发布改动先看差异，否则直接确认发布 */
        async function publishOne(row) {
            if (Number(row.publish_state) === STATE.PENDING) {
                openDiff(row);
                return;
            }

            const ok = await confirm(
                '「' + (row.title || '') + '」当前没有未发布的改动。\n确定要执行一次发布（上线并记录快照）吗？'
            );
            if (!ok) return;

            const done = await submit({ url: URL.publish, data: { id: row.id }, message: '发布成功' });
            if (done) refresh(table);
        }

        /** 拉取差异数据并打开对比弹窗 */
        async function openDiff(row) {
            pendingPublishId = Number(row.id) || 0;

            $('#diff-summary').text('正在比对…');
            $('#diff-host').empty();
            $('#publish-diff-modal').modal('show');

            let data = null;
            try {
                data = await http.get(URL.diff, { id: row.id });
            } catch (e) {
                $('#diff-summary').text('差异数据加载失败');
                return;
            }

            if (data) {
                renderDiff(data);
            }
        }

        /** 渲染行级差异 */
        function renderDiff(data) {
            const oldTitle = (data.old && data.old.title) || '';
            const newTitle = (data.new && data.new.title) || '';
            const oldText = (data.old && data.old.content) || '';
            const newText = (data.new && data.new.content) || '';

            const rows = diffLines(oldText, newText);
            const added = rows.filter((r) => r.type === 'add').length;
            const removed = rows.filter((r) => r.type === 'del').length;

            const parts = [];
            if (!data.has_revision) {
                parts.push('首次发布：没有历史快照可比对');
            } else {
                parts.push('正文 + ' + added + ' 行 / - ' + removed + ' 行');
            }
            if (oldTitle !== newTitle) {
                parts.push('标题已修改');
            }

            $('#diff-summary').text(parts.join('；'));

            const host = document.getElementById('diff-host');
            if (!host) return;

            if (!rows.length) {
                host.innerHTML = '<div class="docs-diff__empty">没有可比对的内容</div>';
                return;
            }

            let oldNo = 0;
            let newNo = 0;

            const html = rows.map(function (r) {
                let noText = '';
                if (r.type === 'same') {
                    oldNo++;
                    newNo++;
                    noText = oldNo + ' / ' + newNo;
                } else if (r.type === 'del') {
                    oldNo++;
                    noText = oldNo + ' / ';
                } else {
                    newNo++;
                    noText = '/ ' + newNo;
                }

                return '<div class="docs-diff__row docs-diff__row--' + r.type + '">' +
                    '<span class="docs-diff__no">' + noText + '</span>' +
                    '<span class="docs-diff__text">' + escapeHtml(r.text) + '</span>' +
                    '</div>';
            }).join('');

            host.innerHTML = '<div class="docs-diff">' + html + '</div>';
        }

        $('#btn-confirm-publish').on('click', async function () {
            if (!pendingPublishId) return;

            const done = await submit({ url: URL.publish, data: { id: pendingPublishId }, message: '发布成功' });
            if (done) {
                $('#publish-diff-modal').modal('hide');
                pendingPublishId = 0;
                refresh(table);
            }
        });

        /** 批量发布（走 publish，需写快照） */
        async function batchPublish() {
            const ids = selectedIds('#table');
            if (!ids.length) {
                toast('请先勾选要发布的文档', 'warning');
                return;
            }

            const ok = await confirm(
                '确定要发布选中的 ' + ids.length + ' 篇文档吗？\n将把编辑稿合并到线上并记录发布快照。'
            );
            if (!ok) return;

            const done = await submit({ url: URL.publish, data: { ids }, message: '批量发布成功' });
            if (done) refresh(table);
        }

        /** 批量转草稿（下架） */
        async function batchDraft() {
            const ids = selectedIds('#table');
            if (!ids.length) {
                toast('请先勾选要转换的文档', 'warning');
                return;
            }

            const ok = await confirm('确定要把选中的 ' + ids.length + ' 篇文档转为草稿（前台下架）吗？');
            if (!ok) return;

            const done = await submit({ url: URL.batch, data: { ids, action: 'draft' }, message: '已转为草稿' });
            if (done) refresh(table);
        }

        async function remove(row) {
            const ok = await confirm('确定要删除文档「' + (row.title || '') + '」吗？');
            if (!ok) return;
            const done = await submit({ url: URL.del, data: { ids: [row.id] }, message: '删除成功' });
            if (done) refresh(table);
        }

        /** 批量删除（勾选行 → 二次确认 → 批量接口） */
        async function batchRemove() {
            const ids = selectedIds('#table');
            if (!ids.length) {
                toast('请先勾选要删除的文档', 'warning');
                return;
            }

            const ok = await confirm('确定要删除选中的 ' + ids.length + ' 篇文档吗？删除后不可恢复。');
            if (!ok) return;

            const done = await submit({ url: URL.del, data: { ids }, message: '删除成功' });
            if (done) refresh(table);
        }

        /** 派生（克隆）：生成内容相同、状态为草稿的独立文档 */
        async function cloneDoc(row) {
            const ok = await confirm(
                '确定要派生文档「' + (row.title || '') + '」吗？\n' +
                '将创建一个内容相同的独立副本（标题追加「（副本）」、状态为草稿），' +
                '与当前文档不再有关联。'
            );
            if (!ok) return;

            const done = await submit({ url: URL.clone, data: { id: row.id }, message: '派生成功' });
            if (done) refresh(table);
        }

        // 概览「最近更新」带 ?edit=ID 进入 → 直接打开编辑页（少一次点击）
        const editId = Number(params.get('edit')) || 0;
        if (editId > 0) {
            openDocumentForm(editId, () => refresh(table));
        }
    },

    /** 独立编辑页（iframe）入口 */
    form() {
        initFormPage();
    },
};

export default Controller;
