/**
 * 评论管理页面控制器（UIUX-P0-13）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/comment`
 * （`CommentController` → snake_case `comment`）。
 *
 * 对应控制器动作：
 *   comment/index   → 列表（标准 paginate 契约；参数 `status`(0/1/2 或空) / `page` / `limit`）
 *   comment/approve → 审核通过（POST `id`）
 *   comment/reject  → 拒绝（POST `id`）
 *   comment/delete  → 删除（POST `id`，**级联删除所有子孙回复 + 点赞记录**）
 *
 * 关键背景（已在后端一并修正，见 §1.1⑱）：
 *   · 原 `Comment::index` **只特判 `status === '0'`**，传 `1`/`2` 会静默返回全部
 *     → 若直接做「已通过 / 已拒绝」筛选就是死控件。已改为经 `CommentService::getAdminList()`
 *     按 `0/1/2` 过滤。
 *   · 评论正文入库时已被 `htmlspecialchars()` 转义，需解一层再渲染（见 `displayText()`）。
 *
 * 未提供（后端不支持，宁缺勿假）：
 *   · 关键词搜索（`Comment::index` 无 `keyword` 参数）；
 *   · 评论回复（后台发回复的接口不存在）。
 *
 * P1-19（已完成）：批量审核通过 / 批量拒绝接口已就绪 → 本页开 `checkboxHeader`。
 *
 * P1-26（已完成）：评论表已补 `site_id`，本页列表按「后台当前站点」过滤，
 *   审核 / 拒绝 / 删除前先校验该评论属于当前站点。
 *   `site_id` 由 `CommentService::create()` 从**所属内容**推导，不接受前端传值。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsDelete,
    cmsOperateButtons, cmsEscape, cmsDecodeEntities, cmsBatch,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('comment/index'),
    approve: cmsUrl('comment/approve'),
    reject: cmsUrl('comment/reject'),
    del: cmsUrl('comment/delete'),
    contentEdit: cmsUrl('content/edit'),
    batchApprove: cmsUrl('comment/batchApprove'),
    batchReject: cmsUrl('comment/batchReject'),
};

const STATUS_MAP = { 0: '待审核', 1: '已通过', 2: '已拒绝' };

const OPERATE = [
    {
        action: 'approve',
        title: '审核通过',
        icon: 'bi bi-check-lg',
        class: 'btn-outline-success',
        // 已通过的评论不再显示「通过」按钮（内核 operateButtons 不支持按行显隐，故用谓词先过滤）
        show: (row) => String(row.status) !== '1',
    },
    {
        action: 'reject',
        title: '拒绝',
        icon: 'bi bi-x-lg',
        class: 'btn-outline-warning',
        show: (row) => String(row.status) !== '2',
    },
    { action: 'del', title: '删除（连同其下所有回复）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
];

/**
 * 评论正文展示文本。
 *
 * 两层转义要还原：PHP 入库时 `htmlspecialchars()` 一次 + 内核输出 `escapeHTML()` 一次
 * → 解两次得到用户原文；最后**必须** `cmsEscape()` 一次再插入，
 * 这样即使库里存着未转义的脏数据也不会执行 HTML。
 */
function displayText(value) {
    return cmsEscape(cmsDecodeEntities(cmsDecodeEntities(value)));
}

export default {
    index() {
        let list = null;

        function selectedIds() {
            const ids = window.Wise.table.getSelectedIds('#table');
            return Array.isArray(ids) ? ids : [];
        }

        /** 批量审核通过 / 拒绝（P1-19，站点过滤 + 单条容错） */
        function batchModerate(action) {
            const ids = selectedIds();
            if (!ids.length) {
                cmsToast('请先勾选要操作的评论', 'warning');
                return;
            }
            const isApprove = action === 'approve';
            return cmsBatch({
                url: isApprove ? URL.batchApprove : URL.batchReject,
                ids: ids,
                confirmMessage: '将' + (isApprove ? '通过' : '拒绝') + '选中的 ' + ids.length + ' 条评论，确定继续？',
                successMsg: isApprove ? '已通过选中的评论' : '已拒绝选中的评论',
                refresh: list,
            });
        }

        const $page = $('#cmsCommentPage');
        const params = new URLSearchParams(window.location.search);

        // 默认筛选：URL 显式指定 > 审核模式推导 > 全部
        //   comment_check = 1（先审后发）→ 默认落在「待审核」队列，审核员打开就能干活
        const checkMode = parseInt($page.data('comment-check'), 10);
        const defaultStatus = checkMode === 1 ? '0' : '';
        const initialStatus = params.get('status') !== null ? params.get('status') : defaultStatus;
        $('#filter-status').val(initialStatus);

        /** 审核通过 / 拒绝（幂等目标值由后端写死，无需传目标状态） */
        function moderate(action, row) {
            const isApprove = action === 'approve';
            return cmsAction({
                url: isApprove ? URL.approve : URL.reject,
                data: { id: row.id },
                successMsg: isApprove ? '已通过该评论' : '已拒绝该评论',
                refresh: list,
            });
        }

        /** 删除：级联删除子孙回复 + 点赞记录 → L2（二次确认 + 回显影响面） */
        function remove(row) {
            const isReply = String(row.parent_id) !== '0';
            const cascade = isReply
                ? '将同时删除它下面的所有子回复及其点赞记录'
                : '将同时删除它下面的所有回复及其点赞记录';

            return cmsDelete({
                url: URL.del,
                data: { id: row.id },
                refresh: list,
                confirmMessage: '确定删除该' + (isReply ? '条回复' : '条评论') + '？' + cascade + '，不可恢复。',
                successMsg: isReply ? '回复已删除' : '评论已删除',
            });
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            pageSize: 20,
            // 批量：后端有 batchApprove / batchReject（P1-19）→ 开勾选
            checkboxHeader: true,

            columns: [
                { checkbox: true },
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'article.title',
                    title: '所属内容',
                    width: 190,
                    align: 'left',
                    formatter: (value, row) => {
                        if (!value) {
                            return '<span class="text-muted">内容已删除</span>';
                        }
                        const id = row.article && row.article.id;
                        return id
                            ? '<a href="' + URL.contentEdit + '?id=' + id + '">' + value + '</a>'
                            : value;
                    },
                },
                { field: 'username', title: '评论人', width: 110, align: 'left', formatter: 'normal' },
                {
                    field: 'content',
                    title: '评论内容',
                    align: 'left',
                    formatter: (value) => {
                        const text = displayText(value);
                        return '<span class="d-inline-block text-truncate" style="max-width:420px"'
                            + ' title="' + text + '">' + text + '</span>';
                    },
                },
                {
                    field: 'parent_id',
                    title: '回复关系',
                    width: 120,
                    align: 'center',
                    // 分页后的列表是扁平的，父子关系只能显式标注（不能靠缩进假装是树）
                    formatter: (value, row) => (String(value) === '0'
                        ? '<span class="badge bg-light text-dark">主评论</span>'
                        : '<span class="badge bg-info" title="根评论 #' + (row.root_id || '') + '">'
                            + '回复 #' + value + '</span>'),
                },
                {
                    field: 'like_count',
                    title: '赞 / 踩',
                    width: 100,
                    align: 'center',
                    formatter: (value, row) => '<i class="bi bi-hand-thumbs-up text-success"></i> '
                        + (value || 0) + ' <i class="bi bi-hand-thumbs-down text-danger ms-1"></i> '
                        + (row.dislike_count || 0),
                },
                { field: 'ip', title: 'IP', width: 130, align: 'left', visible: false, formatter: 'normal' },
                { field: 'create_time', title: '评论时间', width: 165, align: 'center', formatter: 'datetime' },
                {
                    field: 'status',
                    title: '状态',
                    width: 100,
                    align: 'center',
                    formatter: 'status',
                    searchList: STATUS_MAP,
                    custom: { 0: 'warning', 1: 'success', 2: 'danger' },
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 150,
                    align: 'center',
                    clickToSelect: false,
                    formatter: (value, row) => cmsOperateButtons(OPERATE, row),
                },
            ],

            query() {
                const status = $('#filter-status').val();
                return (status === '' || status === null || status === undefined) ? {} : { status: status };
            },

            toolbarBtn: [
                {
                    id: 'btn-batch-approve-comment',
                    name: 'batchApprove',
                    title: '批量通过',
                    icon: 'bi bi-check-lg',
                    class: 'btn btn-sm btn-outline-success',
                    click: () => batchModerate('approve'),
                },
                {
                    id: 'btn-batch-reject-comment',
                    name: 'batchReject',
                    title: '批量拒绝',
                    icon: 'bi bi-x-lg',
                    class: 'btn btn-sm btn-outline-warning',
                    click: () => batchModerate('reject'),
                },
                {
                    id: 'btn-refresh-comment',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'approve' || action === 'reject') return moderate(action, row);
                if (action === 'del') return remove(row);
                return undefined;
            },
        });

        // 状态筛选：就地刷新表格（原实现是 <a href> 整页跳转）
        $('#filter-status').on('change', () => cmsRefresh(list));

        return list;
    },
};
