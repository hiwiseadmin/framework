/**
 * 广告管理页面控制器（UIUX-P0-16）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/ad`
 * （`AdController` → snake_case `ad`）。**列表页与表单页共用本模块**。
 *
 * 对应控制器动作：
 *   ad/index  → 列表（标准 paginate 契约；**固定页长 15**，不读 `page`/`limit`/`keyword`）
 *   ad/create → 表单 + 提交（POST 返回 JSON）
 *   ad/edit   → 表单 + 提交（POST 返回 JSON；也用于状态开关）
 *   ad/delete → **硬删除**（`Ad::destroy`，广告表无软删）
 *
 * ⚠️ 四处已核实的事实：
 *   1. **广告是全站共享的**：`wisecms_ad` 无 `site_id` 列（见建表迁移的决策记录），
 *      列表**不跟随后台站点选择器** —— 页面必须显式说明，否则用户切站点后"没反应"会误判为 bug。
 *   2. **固定页长 15**：`->paginate(15)` 不读 `limit` → 必须 `fixedPageSize: true`
 *      （隐藏每页条数选择器；bootstrap-table 由 `paginationParts` 控制，**不是 `pageList`**，见 §1.1⑪）。
 *   3. **无 `toggleStatus` 接口** → 状态开关复用 `ad/edit` 提交 `{id, status}`；
 *      `MassAssignmentGuard` 对 `wisecms_ad` 未设黑名单（`status` 可写），
 *      `Ad::edit` 收到单字段也能部分更新（`normalizeTimeWindow` 无时间键时是 no-op）。
 *   4. 表单页原为**裸表单 POST** 而控制器 POST 返回 JSON → 提交后页面直接显示 JSON。
 *      已改为 AJAX 提交（模板内联脚本清零）。
 *
 * 关于时间列的两次选择：
 *   · 「剩余」列用内核 `countdown` formatter —— 已核实其依赖的 moment 子集方法
 *     （`isBefore`/`diff`/`duration.asDays|hours|minutes|seconds`）**全部实现**，
 *     与 `timeAgo` 不同（后者调未实现的 `years()/months()`，必抛 TypeError，见 §1.1⑮）。
 *     注意 `countdown` 是**渲染时静态计算**，没有计时器（全库无 `.countdown` 处理器）。
 *   · 「投放期」列自己算状态（长期 / 未开始 / 投放中 / 已过期）——
 *     `countdown` 只能表达"距 end_time 还有多久"，表达不了"未开始/不限"；
 *     且自带 `Date.parse` 归一（`YYYY-MM-DD HH:mm:ss` 的空格分隔在 Safari 上解析为 Invalid Date）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsAction, cmsDelete,
    cmsEscape, cmsBindRowToggles, cmsPeriodInfo,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('ad/index'),
    create: cmsUrl('ad/create'),
    edit: cmsUrl('ad/edit'),
    del: cmsUrl('ad/delete'),
};

const TYPE_MAP = { image: '图片', html: 'HTML', code: '代码' };

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del', title: '删除（不可恢复）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

/**
 * 投放期单元格：时间窗 + 状态徽章（两行，避免再多一列）
 *
 * 状态判定用公共助手 `cmsPeriodInfo()`（含 Safari 日期解析归一，见 `cms-admin.js`），
 * 与友链页（P0-21）共用同一口径。
 */
function windowCell(value, row) {
    const start = row.start_time ? String(row.start_time).substring(0, 16) : '不限';
    const end = row.end_time ? String(row.end_time).substring(0, 16) : '不限';
    const state = cmsPeriodInfo(row);

    return '<div class="small">' + cmsEscape(start) + ' ~ ' + cmsEscape(end) + '</div>'
        + '<span class="badge ' + state.cls + '">' + state.text + '</span>';
}

/**
 * 表单页提交（create / edit 共用）
 */
function bindForm(defaultMsg) {
    const $form = $('#adForm');
    if (!$form.length) return;

    function goBack() {
        try {
            if (window.parent && window.parent !== window
                && typeof window.parent.cmsAdFormDone === 'function') {
                window.parent.cmsAdFormDone();
                return;
            }
        } catch (e) { /* 跨域或钩子异常，走兜底 */ }

        window.location.href = URL.list;
    }

    $form.on('submit', async function (e) {
        e.preventDefault();
        const res = await cmsAction({
            url: $form.attr('action'),
            // FormData：`datetime-local` 的 `2026-10-10T12:00` 原样提交，由后端
            // `normalizeTimeWindow()` 用 cms_datetime() 归一（直接入库会被 MySQL 拒绝）
            data: new FormData(this),
            successMsg: defaultMsg,
        });
        if (res && res.success) {
            setTimeout(goBack, 400);
        }
    });

    $('#btn-ad-back').on('click', function (e) {
        e.preventDefault();
        goBack();
    });
}

export default {
    /** 列表页入口 */
    index() {
        let list = null;

        /** 打开广告表单（iframe 弹层，与单页/栏目同一套父子回调约定） */
        function openForm(url, title) {
            const index = window.Wise.ui.dialog.open(url, title, { area: ['860px', '640px'], resize: true });

            window.cmsAdFormDone = function () {
                try {
                    window.Wise.ui.dialog.close(index);
                } catch (e) {
                    // 弹层可能已被用户手动关闭，忽略
                }
                cmsRefresh(list);
            };

            return index;
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(15)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            // 过期广告整行淡化（含"已过期"徽章），未开始的不淡化
            rowStyle(row) {
                return cmsPeriodInfo(row).key === 'expired'
                    ? { classes: 'text-muted', css: { opacity: '0.65' } }
                    : {};
            },

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', visible: false, formatter: 'normal' },
                { field: 'space_name', title: '广告位', width: 140, align: 'left', formatter: 'normal' },
                {
                    field: 'name',
                    title: '广告名称',
                    width: 180,
                    align: 'left',
                    formatter: (value, row) => '<a href="javascript:;" data-action="edit" data-id="'
                        + cmsEscape(row.id) + '" title="点击编辑">' + (value || '')
                        + '</a>',
                },
                {
                    field: 'type',
                    title: '类型',
                    width: 80,
                    align: 'center',
                    formatter: (value) => '<span class="badge bg-light text-dark">'
                        + cmsEscape(TYPE_MAP[value] || value || '') + '</span>',
                },
                {
                    field: 'content',
                    title: '预览',
                    width: 90,
                    align: 'center',
                    // 仅图片型可预览；HTML/代码型只显示占位，不去执行/渲染其内容
                    formatter: (value, row) => {
                        if (row.type !== 'image' || !value) {
                            return '<span class="text-muted small">—</span>';
                        }
                        return '<img src="' + value + '" alt="广告预览"'
                            + ' style="height:34px;max-width:80px;object-fit:contain">';
                    },
                },
                { field: 'start_time', title: '投放期', width: 210, align: 'left', formatter: windowCell },
                {
                    field: 'end_time',
                    title: '剩余',
                    width: 120,
                    align: 'center',
                    // 内核 countdown：渲染时静态计算（无计时器）；end_time 为空时返回空
                    formatter: 'countdown',
                },
                { field: 'sort', title: '排序', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: URL.edit,
                    labels: { on: '启用', off: '禁用' },
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 110,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => window.Wise.table.operateButtons(OPERATE, row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-ad',
                    name: 'add',
                    title: '添加广告',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => openForm(URL.create, '添加广告'),
                },
                {
                    id: 'btn-refresh-ad',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    return openForm(URL.edit + '?id=' + encodeURIComponent(row.id), '编辑广告');
                }
                if (action === 'del') {
                    return cmsDelete({
                        url: URL.del,
                        data: { id: row.id },
                        refresh: list,
                        // 广告表无软删 → 真删除且**无回收站**；确认框按 HTML 渲染，故转义 row 值
                        confirmMessage: '确定永久删除广告「' + cmsEscape(row.name || '') + '」？'
                            + '广告没有回收站，删除后<b>无法恢复</b>。',
                        successMsg: '广告已删除',
                    });
                }
                return undefined;
            },
        });

        // 状态开关（启用 / 禁用）：提交 {id, status} 到 ad/edit，失败自动回滚
        cmsBindRowToggles({
            url: URL.edit,
            refresh: list,
            message: (field, target) => (String(target) === '1' ? '已启用' : '已禁用'),
        });

        return list;
    },

    /** 添加广告表单页 */
    create() {
        bindForm('广告创建成功');
    },

    /** 编辑广告表单页 */
    edit() {
        bindForm('更新成功');
    },
};
