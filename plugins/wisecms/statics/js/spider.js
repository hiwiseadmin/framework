/**
 * 采集任务页面控制器（UIUX-P0-22）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/spider`
 * （`SpiderController` → snake_case `spider`）。
 *
 * 对应控制器动作：
 *   spider/index  → 列表（标准 paginate 契约；**固定页长 15**，无筛选/搜索参数）
 *   spider/create → **整页**表单（非 iframe 弹层，故「新建」用链接跳转）
 *   spider/run    → **同步**执行采集（无进度接口）
 *   spider/delete → **硬删除**（表无软删、无回收站）
 *
 * ⚠️ 五个已核实的事实：
 *   1. **`run` 是同步接口**：`Spider::run` 直接调 `SpiderService::runTask()`，内部抓列表页
 *      （最多 20 个 URL）+ 逐个抓正文（curl 单条 10s 超时）→ 最坏可跑数分钟，**没有进度接口**。
 *      → 与 rebuild / 死链检测同款决策：**不做假进度条**，L3 确认后进全局 loading 态
 *      （`interceptor:false` + `loading:true`），完成后用后端返回的分项计数提示。
 *      真进度属 `P1-13`（需后端任务化），本页不假装有。
 *   2. **执行有副作用**：会把抓到的内容以「草稿」入库到 `target_catid` 栏目 → 方案 §4.6
 *      把「采集执行」明确列为 L3 高危操作，确认词 = 任务名。
 *   3. **列表只有 `target_catid` 数字**：栏目名由 `Spider::index` 附带 `cat_name`（纯读增强）；
 *      栏目被删/禁用时 `cat_name` 为空 → 降级显示 `catid` 数字，不猜。
 *   4. **删除是硬删**：`SpiderTask::destroy()` 只删**任务定义**，**不影响**已采集入库的内容。
 *   5. **`spider_task` 无 `site_id`**：列表**跨站**显示所有任务，且 `target_catid` 可能指向
 *      任意站点的栏目（采集时 `ContentService` 以栏目所属站点落库）→ 多站点隔离缺口，
 *      已登记（与评论 P1-26 同类，但「任务归属哪个站点」的语义需先定，不在本页改）。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsToast, cmsConfirm, cmsAction, cmsEscape, cmsDangerConfirm,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('spider/index'),
    create: cmsUrl('spider/create'),
    edit: cmsUrl('spider/edit'),
    run: cmsUrl('spider/run'),
    del: cmsUrl('spider/delete'),
};

/** 上次采集时间：无值 → 未执行；有值 → 显示到分钟（用字符串切片，规避内核 moment 子集的 Safari 解析坑 P1-28） */
function lastRunCell(value) {
    if (value === null || value === undefined || value === '' || value === '0000-00-00 00:00:00') {
        return '<span class="text-muted">未执行</span>';
    }
    return '<span title="' + cmsEscape(String(value)) + '">' + cmsEscape(String(value).slice(0, 16)) + '</span>';
}

/** 定时表达式：空 → 手动执行 */
function cronCell(value) {
    const cron = String(value || '').trim();
    if (!cron) return '<span class="text-muted">手动执行</span>';
    return '<code class="small">' + cmsEscape(cron) + '</code>';
}

/** 目标栏目：有栏目名显示栏目名，否则降级显示 catid 数字 */
function catCell(value, row) {
    const name = String(row.cat_name || '').trim();
    if (name) return '<span>' + cmsEscape(name) + '</span>';
    const catid = Number(row.target_catid) || 0;
    return catid > 0
        ? '<span class="text-muted">#' + catid + '</span>'
        : '<span class="text-muted">—</span>';
}

export default {
    index() {
        let list = null;

        /** L3：执行采集（同步长任务 + 有副作用） */
        async function run(row) {
            const name = String(row.name || '').trim();
            const word = name || 'RUN';

            const ok = await cmsDangerConfirm({
                title: '执行采集任务',
                word: word,
                confirmText: '开始采集',
                area: ['580px', '460px'],
                html: '<div class="alert alert-warning py-2 mb-3">'
                    + '<i class="bi bi-cloud-download me-1"></i>'
                    + '将立即抓取「' + cmsEscape(name) + '」的列表页并逐篇入库为<b>草稿</b>'
                    + '（栏目 #' + cmsEscape(String(row.target_catid || '')) + '）。'
                    + '该任务<b>不会</b>覆盖或删除任何既有内容。'
                    + '</div>'
                    + '<div class="small text-muted">'
                    + '⚠️ 该任务是<b>同步执行</b>的（后端未提供进度接口）：'
                    + '单次最多抓取 20 个链接、每个链接最长 10 秒，'
                    + '可能耗时数分钟，执行期间页面不显示进度条 —— 请不要重复提交或关闭页面。'
                    + '</div>',
            });
            if (!ok) return;

            const wise = window.Wise || {};
            try {
                if (!(wise.util && wise.util.http && typeof wise.util.http.post === 'function')) {
                    cmsToast('请求组件未就绪', 'error');
                    return;
                }
                // interceptor:false → 拿完整信封 {code,msg,data}；loading:true → 全局遮罩防重复提交
                const body = await wise.util.http.post(URL.run, { id: row.id }, { interceptor: false, loading: true });

                if (body && body.code === 0) {
                    const count = Number(body.data && body.data.count) || 0;
                    cmsToast(body.msg || '采集完成', 'success');
                    if (count === 0) {
                        cmsToast('本次未采集到内容：请检查列表页 URL 是否可达、是否被 SSRF 校验拦截', 'warning');
                    }
                    cmsRefresh(list);
                } else {
                    cmsToast((body && body.msg) || '采集失败，请查看系统日志', 'error');
                }
            } catch (e) {
                cmsToast('采集请求失败，请稍后重试', 'error');
            }
        }

        /** 删除任务定义（硬删，不影响已采集内容） */
        function del(row) {
            return cmsConfirm(
                '确定删除采集任务「' + cmsEscape(row.name || '') + '」？<br>'
                + '仅删除<b>任务定义</b>，不会影响它<b>已经采集入库</b>的内容；'
                + '删除后<b>没有回收站</b>。',
                '删除采集任务'
            ).then((ok) => (ok
                ? cmsAction({ url: URL.del, data: { id: row.id }, refresh: list, successMsg: '任务已删除' })
                : undefined));
        }

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            // 后端 `->paginate(15)` 固定页长、不读 limit → 隐藏每页条数选择器
            fixedPageSize: true,

            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', formatter: 'normal' },
                { field: 'name', title: '任务名称', width: 180, align: 'left', formatter: 'normal' },
                { field: 'target_catid', title: '目标栏目', width: 140, align: 'left', formatter: catCell },
                { field: 'cron', title: '定时', width: 130, align: 'left', formatter: cronCell },
                { field: 'last_run_time', title: '上次采集', width: 150, align: 'center', formatter: lastRunCell },
                { field: 'last_count', title: '上次数量', width: 90, align: 'center', formatter: 'normal' },
                { field: 'total_count', title: '累计数量', width: 90, align: 'center', formatter: 'normal' },
                {
                    // 无 toggleStatus 接口 → 只读徽章
                    field: 'status',
                    title: '状态',
                    width: 80,
                    align: 'center',
                    formatter: 'status',
                    searchList: { 0: '禁用', 1: '启用' },
                    custom: { 0: 'secondary', 1: 'success' },
                },
                {
                    field: 'operate',
                    title: '操作',
                    width: 230,
                    align: 'center',
                    formatter: (value, row) => window.Wise.table.operateButtons([
                        { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
                        { action: 'run', title: '执行采集', icon: 'bi bi-cloud-download', class: 'btn-outline-info' },
                        { action: 'del', title: '删除（无回收站）', icon: 'bi bi-trash', class: 'btn-outline-danger' },
                    ], row),
                },
            ],

            toolbarBtn: [
                {
                    id: 'btn-add-spider',
                    name: 'add',
                    title: '新建采集任务',
                    icon: 'bi bi-plus-lg',
                    class: 'btn btn-sm btn-primary',
                    click: () => { window.location.href = URL.create; },
                },
                {
                    id: 'btn-refresh-spider',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-clockwise',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => cmsRefresh(list),
                },
            ],

            onClick(action, row) {
                if (action === 'edit') {
                    // P1-15：编辑整页表单（与 create 同为整页，非弹层）
                    window.location.href = URL.edit + '?id=' + encodeURIComponent(row.id);
                    return undefined;
                }
                if (action === 'run') return run(row);
                if (action === 'del') return del(row);
                return undefined;
            },
        });

        return list;
    },
};
