/**
 * 搜索日志 / 热搜词页面控制器（UIUX-P2-04）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/search_log`
 * （`SearchLogController` → snake_case `search_log`，与视图目录 `searchLog/` 无关，
 * 同 P1 的 `model_field.js` 约定）。
 *
 * 对应控制器动作：
 *   searchLog/index → 页面（热搜词 JSON 经 `#hot-keywords-data` 的 data 属性下发）+ 日志列表
 *   searchLog/hot   → 热搜词（站点切换时局部刷新用）
 *
 * ⚠️ 三处已核实的事实：
 *   1. **只读页**：`wisecms_search_log` 无置顶/排序/状态列 → 没有「置顶热词」按钮，
 *      页面已显式说明原因（宁缺一个入口，不放点了没反应的按钮）。
 *   2. **按站点隔离**：日志与热词都带 `site_id`；站点切换走 `cmsOnSiteSwitched` **局部刷新**
 *      （表格 + 热词卡片），不整页 reload（P1-14 约定）。
 *   3. 热词按次数聚合（后端 `getHotKeywords`），**无缓存** → 每次切站/刷新都是一次实时聚合。
 *
 * @see statics/js/cms-admin.js
 */

import {
    cmsTable, cmsUrl, cmsRefresh, cmsEscape, cmsDecodeEntities, cmsOnSiteSwitched,
} from './cms-admin.js';

const URL = {
    list: cmsUrl('searchLog/index'),
    hot: cmsUrl('searchLog/hot'),
};

/**
 * 渲染热搜词卡片
 *
 * ⚠️ 关键词来自**用户输入**，必须 `cmsEscape` 后插入；`rank`/`count` 为数字。
 *
 * @param {Array} hot `[{keyword, count, rank}, ...]`
 * @returns {string}
 */
function renderHot(hot) {
    const list = Array.isArray(hot) ? hot : [];
    if (!list.length) {
        return '<div class="text-muted small p-2">本站暂无搜索记录。</div>';
    }

    return list.map((item, index) => {
        const rank = Number(item.rank) || (index + 1);
        const count = Number(item.count) || 0;
        // 前三名给个视觉强调，其余用浅色徽章
        const badgeCls = rank <= 3 ? 'bg-danger' : 'bg-light text-dark border';
        return '<div class="d-flex align-items-center py-1 border-bottom">'
            + '<span class="badge ' + badgeCls + ' me-2" style="width:26px">' + rank + '</span>'
            + '<span class="flex-grow-1 small text-truncate" title="' + cmsEscape(item.keyword || '') + '">'
            + cmsEscape(item.keyword || '') + '</span>'
            + '<span class="text-muted small ms-2">' + count + ' 次</span>'
            + '</div>';
    }).join('');
}

/** 从 data 属性读取首屏热词（读后即用，不在 DOM 里留 JS 状态） */
function readInitialHot() {
    const $box = $('#hot-keywords-data');
    if (!$box.length) {
        return [];
    }
    try {
        // 模板引擎已对属性值做 HTML 转义 → 先解一层再解析
        return JSON.parse(cmsDecodeEntities($box.attr('data-hot') || '[]')) || [];
    } catch (e) {
        return [];
    }
}

export default {
    /** 页面入口 */
    index() {
        const $hotBox = $('#hot-keywords');
        $hotBox.html(renderHot(readInitialHot()));

        let list = null;

        /** 站点切换后局部刷新热词卡片 */
        const refreshHot = () => {
            const wise = window.Wise || {};
            const http = wise.util && wise.util.http;
            if (!http || typeof http.get !== 'function') {
                return;
            }
            // 内核 http 默认 interceptor:true → resolve 的就是 data（{hot: [...]}）
            http.get(URL.hot, {})
                .then((d) => { $hotBox.html(renderHot((d && d.hot) || [])); })
                .catch(() => { /* 热词刷新失败不打断列表使用 */ });
        };

        list = cmsTable({
            url: URL.list,
            shape: 'standard',
            query: () => ({ keyword: ($('#filter-keyword').val() || '').trim() }),

            columns: [
                { field: 'id', title: 'ID', width: 70, align: 'center', formatter: 'normal' },
                {
                    field: 'keyword',
                    title: '搜索关键词',
                    width: 220,
                    align: 'left',
                    formatter: (value) => cmsEscape(value || ''),
                },
                {
                    field: 'result_count',
                    title: '结果数',
                    width: 90,
                    align: 'center',
                    formatter: (value) => {
                        const n = Number(value) || 0;
                        // 0 结果值得高亮：这是「搜了却搜不到」的信号（选品/补内容线索）
                        return n === 0
                            ? '<span class="badge bg-warning text-dark">0</span>'
                            : String(n);
                    },
                },
                { field: 'ip', title: '来源 IP', width: 140, align: 'center', formatter: (value) => cmsEscape(value || '') },
                { field: 'create_time', title: '搜索时间', width: 165, align: 'center', sortable: true, formatter: 'datetime' },
            ],

            toolbarBtn: [
                {
                    id: 'btn-refresh-log',
                    name: 'refresh',
                    title: '刷新',
                    icon: 'bi bi-arrow-repeat',
                    class: 'btn btn-sm btn-outline-secondary',
                    click: () => {
                        cmsRefresh(list);
                        refreshHot();
                    },
                },
            ],
        });

        // 筛选交互（条件变化即局部刷新）
        $('#btn-search-log').on('click', () => cmsRefresh(list));
        $('#filter-keyword').on('keydown', function (e) {
            if (e.key === 'Enter') {
                cmsRefresh(list);
            }
        });
        $('#btn-reset-log').on('click', () => {
            $('#filter-keyword').val('');
            cmsRefresh(list);
        });

        // 站点切换：日志表 + 热词卡片一起局部刷新（不整页 reload）
        cmsOnSiteSwitched(() => {
            cmsRefresh(list);
            refreshHot();
        });

        return list;
    },
};
