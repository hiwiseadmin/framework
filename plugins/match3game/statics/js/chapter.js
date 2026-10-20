/**
 * chapter.js — 章节/地图管理
 */
import { http, $ } from './common/util.js';

export default {
    async index() {
        try {
            const res = await http.get('/plugin/match3game/chapter/getList', { pageNumber: 1, pageSize: 100 });
            const rows = res && res.rows ? res.rows : [];

            const html = rows.map((ch) => {
                const levels = (ch.levels || []).map((lv) => {
                    const pre = lv.pre_unlock ? '<span class="text-muted small">← ' + lv.pre_unlock + '</span>' : '';
                    return '<li class="list-group-item d-flex justify-content-between align-items-center">' +
                        '<span><b>' + lv.code + '</b> ' + lv.name + ' ' + pre + '</span>' +
                        '<span class="badge bg-secondary">难度 ' + lv.difficulty + '</span></li>';
                }).join('');

                return '<div class="card mb-3"><div class="card-header">' +
                    '<h6 class="mb-0"><i class="bi bi-folder2-open me-2"></i>' + ch.chapter +
                    ' <span class="badge bg-primary ms-2">' + ch.level_count + ' 关</span></h6></div>' +
                    '<ul class="list-group list-group-flush">' + levels + '</ul></div>';
            }).join('');

            $('#chapter-list').html(html || '<div class="alert alert-info">暂无章节</div>');
        } catch (e) {
            /* 错误由全局处理器提示 */
        }
    },
};
