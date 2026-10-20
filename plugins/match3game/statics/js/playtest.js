/**
 * playtest.js — 测试工具（关卡体检 / 随机关卡生成预览）
 *
 * 三个动作：
 *   GET  playtest/run        确定性棋盘 sanityCheck（棋盘图 + 可消除性）
 *   POST playtest/simulate   贪心 AI 体检（LevelValidator + 通关率）
 *   POST playtest/generate   按 generation 配置预览一张随机关卡
 */
import { http, toast, $ } from './common/util.js';

/** 元素码 → 颜色（与元素字典对齐） */
const COLOR = {
    red_gem: '#e74c3c', blue_gem: '#3498db', yellow_gem: '#f1c40f',
    green_gem: '#2ecc71', purple_gem: '#9b59b6', orange_gem: '#e67e22',
};

/** overlay 字符 → 颜色 */
const OVERLAY_COLOR = { I: '#aee1f9', J: '#7ec8e3', L: '#f5b7b1', K: '#ecf0f1', Q: '#8d6e63', W: '#a5d6a7', N: '#495057' };

/** 生成棋盘 → 色块网格 HTML（null = 不可站立格） */
function renderBoard(board, gems) {
    const palette = gems && gems.length ? gems : Object.keys(COLOR);

    const rows = board.map((row) => {
        const cells = row.map((cell) => {
            if (cell === null || cell === undefined) {
                return '<span class="m3p-cell" style="background:#f1f3f5"></span>';
            }
            const idx = palette.indexOf(cell);
            const bg = COLOR[cell] || (idx >= 0 ? ['#e74c3c', '#3498db', '#f1c40f', '#2ecc71', '#9b59b6', '#e67e22'][idx % 6] : '#adb5bd');
            return '<span class="m3p-cell" style="background:' + bg + '">' + String(cell).substring(0, 1).toUpperCase() + '</span>';
        }).join('');
        return '<div>' + cells + '</div>';
    }).join('');

    return '<div class="m3p-grid" style="background:#f8f9fa;padding:8px;border-radius:8px;display:inline-block">' + rows + '</div>';
}

/** 三层字符地图 → 预览（terrain 决定底色，overlay 叠加，entity 显示字符） */
function renderLayers(preview) {
    const terrain = preview.terrain || [];
    const entity = preview.entity || [];
    const overlay = preview.overlay || [];

    const rows = terrain.map((line, r) => {
        const cells = String(line).split('').map((t, c) => {
            const o = (overlay[r] || '')[c] || '.';
            const e = (entity[r] || '')[c] || '0';

            let bg = '#ffffff';
            if (t === 'S') bg = '#d6d8db';
            else if (t === '.') bg = '#f1f3f5';
            else if (t === '#') bg = '#6c757d';
            else if (t === 'D') bg = '#cce5ff';

            // 显示优先级：覆盖物 > 元素 > 地形
            let label = '';
            if (o !== '.' && o !== '0') {
                label = o;
                bg = OVERLAY_COLOR[o] || bg;
            } else if (e !== '0' && e !== '.') {
                label = e;
            } else if (t !== 'o') {
                label = t;
            }

            return '<span class="m3p-cell" style="background:' + bg + ';color:#333">' + label + '</span>';
        }).join('');
        return '<div>' + cells + '</div>';
    }).join('');

    return '<div class="m3p-grid" style="background:#f8f9fa;padding:8px;border-radius:8px;display:inline-block">' + rows + '</div>';
}

/** 通关率徽章：落在 [0.35, 0.85] 视为合理 */
function passRateBadge(rate) {
    const v = Number(rate || 0);
    const cls = v >= 0.35 && v <= 0.85 ? 'bg-success' : (v > 0.85 ? 'bg-warning text-dark' : 'bg-danger');
    return '<span class="badge ' + cls + '">通关率 ' + (v * 100).toFixed(0) + '%</span>';
}

export default {
    index() {
        // ---------- 生成棋盘（确定性 sanityCheck） ----------
        $('#playtest-form').on('submit', async function (e) {
            e.preventDefault();
            const levelCode = $('#form-level-code').val();
            const seed = $('#form-seed').val();

            try {
                const d = (await http.get('/plugin/match3game/playtest/run', { level_code: levelCode, seed })) || {};
                const badge = d.playable
                    ? '<span class="badge bg-success">可玩（开局必有解）</span>'
                    : '<span class="badge bg-danger">不可玩（无有效交换）</span>';

                const stats = Object.entries(d.stats || {}).map(([k, v]) => k + '×' + v).join('，');

                $('#playtest-result').html(
                    '<div class="alert alert-info py-2">seed=' + d.seed + '，' + badge +
                    '<br><small>元素统计：' + (stats || '无') + '</small></div>' +
                    renderBoard(d.board || [])
                );
            } catch (err) {
                /* 错误由全局处理器提示 */
            }
        });

        // ---------- AI 体检 ----------
        $('#btn-simulate').on('click', async function () {
            const levelCode = $('#form-level-code').val();
            const seed = $('#form-seed').val();
            const tries = $('#form-tries').val() || 20;

            $('#playtest-result').html('<div class="text-muted small">模拟中…（贪心 AI 跑 ' + tries + ' 次）</div>');

            try {
                const d = (await http.post('/plugin/match3game/playtest/simulate', {
                    level_code: levelCode, seed, tries,
                })) || {};

                if (!d.valid) {
                    const items = (d.errors || []).map((e) => '<li>' + e + '</li>').join('');
                    $('#playtest-result').html(
                        '<div class="alert alert-danger py-2"><b>Schema 校验不通过：</b><ul class="mb-0">' + items + '</ul></div>'
                    );
                    return;
                }

                $('#playtest-result').html(
                    '<div class="alert alert-light border py-2">' +
                    'seed=' + d.seed + '，' + passRateBadge(d.passRate) +
                    ' <span class="badge bg-secondary">开局有解 ' + (d.hasValidMove ? '是' : '否') + '</span>' +
                    ' <span class="badge bg-light text-dark">平均步数 ' + d.avgMoves + '</span>' +
                    ' <span class="badge bg-light text-dark">平均分 ' + d.avgScore + '</span>' +
                    '<br><small class="text-muted">胜/总：' + d.wins + '/' + d.tries +
                    '；健康区间 35%~85%（05 §B8.1 第 6 条）</small></div>'
                );
            } catch (err) {
                /* 错误由全局处理器提示 */
            }
        });

        // ---------- 随机关卡生成预览 ----------
        $('#btn-generate').on('click', async function () {
            const raw = $('#gen-config').val();
            let generation;
            try {
                generation = JSON.parse(raw);
            } catch (err) {
                toast('generation JSON 格式非法', 'error');
                return;
            }

            $('#generate-result').html('<div class="text-muted small">生成中…（含通关率体检，可能重试换 seed）</div>');

            try {
                const d = (await http.post('/plugin/match3game/playtest/generate', {
                    generation: JSON.stringify(generation),
                    seed: $('#gen-seed').val() || 0,
                    d: $('#gen-d').val() || 0,
                    node_id: $('#gen-node-id').val() || 'preview',
                })) || {};

                const p = d.preview || {};
                const goals = (p.goals || []).map((g) => {
                    if (g.type === 'score') return '分数≥' + g.count;
                    if (g.type === 'collect') return '收集' + g.target + '×' + g.count;
                    if (g.type === 'clear') return '清除' + g.layer + '×' + g.count;
                    return g.type;
                }).join(' + ');

                const errs = (d.errors || []).length
                    ? '<div class="alert alert-warning py-2 mb-2">体检告警：' + d.errors.join('；') + '</div>'
                    : '';

                $('#generate-result').html(
                    errs +
                    '<div class="alert alert-light border py-2">' +
                    '<b>' + (p.code || '-') + '</b>　' + passRateBadge((d.quality || {}).passRate) +
                    (d.degraded ? ' <span class="badge bg-warning text-dark">已降级保守参数</span>' : '') +
                    ' <span class="badge bg-secondary">seed ' + d.seed + '</span>' +
                    ' <span class="badge bg-secondary">D ' + d.d + '</span>' +
                    ' <span class="badge bg-secondary">尝试 ' + d.attempts + ' 次</span>' +
                    '<br><small>颜色数 ' + (p.gems || []).length + '；步数 ' + p.moves +
                    '；目标 ' + (goals || '-') + '；三星 ' + ((p.stars || []).join('/')) + '</small></div>' +
                    renderLayers(p)
                );
            } catch (err) {
                /* 错误由全局处理器提示 */
            }
        });
    },
};
