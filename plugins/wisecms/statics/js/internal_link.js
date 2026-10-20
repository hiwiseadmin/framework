/**
 * 内链管理页面控制器（UIUX-P1-21）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/internal_link`
 * （`InternalLinkController` → snake_case `internal_link`）。
 *
 * 对应控制器动作：
 *   internalLink/index   → 列表（标准 paginate 契约，按当前站点隔离，固定页长 20）
 *   internalLink/create  → 新增（POST keyword/target_url/target_title）
 *   internalLink/delete  → 删除（POST id）
 *   internalLink/rebuild → 从内容库重建（POST，同步遍历已发布内容的关键词）
 *
 * ⚠️ 三点已核实的事实：
 *   1. 内链规则有 `site_id` → 列表按「后台当前站点」隔离（与内容/栏目一致）。
 *   2. **无 `toggleStatus` 接口** → 状态列只读徽章（规则创建即启用）。
 *   3. **`rebuild` 是同步实现**（`rebuildFromContent` 遍历已发布内容 + 逐个 create，无进度接口）
 *      → 与采集/死链同款：L3 确认 + 全局 loading，不做假进度条；完成后回显新增条数。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsAction, cmsConfirm,
    cmsEscape, cmsFormDialog, cmsDangerConfirm,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('internalLink/index'),
    create: cmsUrl('internalLink/create'),
    del: cmsUrl('internalLink/delete'),
    rebuild: cmsUrl('internalLink/rebuild'),
};

export default {
    index() {
        let list = null;

        /** 新增内链规则（弹层表单） */
        function addLink() {
            cmsFormDialog({
                title: '添加内链规则',
                area: ['560px', '440px'],
                fields: [
                    { name: 'keyword', label: '关键词', type: 'text', required: true, placeholder: '如：内容管理', messages: { required: '请输入关键词' } },
                    { name: 'target_url', label: '链接地址', type: 'text', required: true, placeholder: '如：https://… 或 /content/1', messages: { required: '请输入链接地址' } },
                    { name: 'target_title', label: '目标标题', type: 'text', placeholder: '可选，鼠标悬停提示' },
                ],
                url: URL.create,
                successMsg: '内链规则已添加',
                onSuccess: () => cmsRefresh(list),
            });
        }

        /** 从内容库重建（L3 + 同步 loading，不做假进度条） */
        async function rebuild() {
            const ok = await cmsDangerConfirm({
                title: '从内容库重建内链',
                word: 'REBUILD',
                confirmText: '开始重建',
                area: ['560px', '420px'],
                html: '<div class="alert alert-warning py-2 mb-3">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + '将遍历<b>当前站点</b>已发布内容的关键词，去重后生成内链规则'
                    + '（已存在的关键词会跳过，不会删除现有规则）。'
                    + '</div>'
                    + '<div class="small text-muted">同步执行，内容多时可能较慢，执行期间请勿重复提交。</div>',
            });
            if (!ok) return;

            const wise = window.Wise || {};
            if (!(wise.util && wise.util.http && typeof wise.util.http.post === 'function')) {
                cmsToast('请求组件未就绪', 'error');
                return;
            }
            // interceptor:false → 拿完整信封 {code,msg,data}；loading:true → 全局遮罩
            wise.util.http.post(URL.rebuild, {}, { interceptor: false, loading: true })
                .then((body) => {
                    if (body && body.code === 0) {
                        cmsToast(body.msg || '重建完成', 'success');
                        cmsRefresh(list);
                    } else {
                        cmsToast((body && body.msg) || '重建失败，请查看系统日志', 'error');
                    }
                })
                .catch(() => cmsToast('重建请求失败，请稍后重试', 'error'));
        }

        /** 删除规则（普通删除，L2） */
        function del(row) {
            return cmsConfirm(
                '确定删除内链规则「' + cmsEscape(row.keyword || '') + '」？',
                '删除内链规则'
            ).then((ok) => (ok
                ? cmsAction({ url: URL.del, data: { id: row.id }, refresh: list, successMsg: '已删除' })
                : undefined));
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(20)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                { field: 'keyword', title: '关键词', width: 200, align: 'left', formatter: (value) => '<code>' + cmsEscape(value || '') + '</code>' },
                {
                    field: 'target_url',
                    title: '链接地址',
                    align: 'left',
                    formatter: (value) => (value
                        ? '<span class="d-inline-block text-truncate" style="max-width:420px" title="'
                            + cmsEscape(value) + '">' + cmsEscape(value) + '</span>'
                        : '<span class="text-muted">—</span>'),
                },
                { field: 'target_title', title: '目标标题', width: 200, align: 'left', formatter: (value) => (value || '<span class="text-muted">—</span>') },
                {
                    // 无 toggleStatus 接口 → 只读徽章（创建即启用）
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '禁用', 1: '启用' },
                    custom: { 0: 'secondary', 1: 'success' },
                },
                { field: 'create_time', title: '创建时间', width: 165, align: 'center', formatter: 'datetime' },
                {
                    field: 'operate',
                    title: '操作',
                    width: 110,
                    align: 'center',
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'del', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-internal-link',
                    name: 'add',
                    title: '添加规则',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => addLink(),
                },
                {
                    id: 'btn-rebuild-internal-link',
                    name: 'rebuild',
                    title: '从内容库重建',
                    icon: 'bi bi-magic',
                    class: 'btn btn-sm btn-outline-warning',
                    click: () => rebuild(),
                },
                {
                    id: 'btn-refresh-internal-link',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'del') return del(row);
                return undefined;
            },
        });

        return list;
    },
};
