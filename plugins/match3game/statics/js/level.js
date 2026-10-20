/**
 * level.js — 关卡管理（Level Schema v1 · 三层字符地图编辑器）
 *
 * 编辑对象是一份 Level Schema v1：
 *   regions[0].layers = { terrain, entity, overlay }   ← 本页用网格画
 *   rules / palette / reward_conf                      ← JSON textarea
 * 提交时把三层拼回 regions JSON 放进隐藏域，后端 Level::save 先跑 LevelValidator 再落库。
 */
import { http, $, confirm, submit, toast } from './common/util.js';
import { createTable, operateButtons, refresh } from './common/table.js';

const URL = {
    list:    '/plugin/match3game/level/getList',
    save:    '/plugin/match3game/level/save',
    del:     '/plugin/match3game/level/delete',
    check:   '/plugin/match3game/level/check',
    detail:  '/plugin/match3game/level/detail',
};

const OPERATE = [
    { action: 'edit', title: '编辑', icon: 'bi bi-pencil', class: 'btn-outline-primary' },
    { action: 'del',  title: '删除', icon: 'bi bi-trash',  class: 'btn-outline-danger' },
];

/**
 * 状态枚举 → 徽章样式与文案
 * 取值与 view/level/form.html 的 status 下拉、迁移注释（0草稿 1已发布 2下架）一致。
 */
const STATUS_BADGE = {
    0: ['bg-secondary', '草稿'],
    1: ['bg-success',   '已发布'],
    2: ['bg-dark',      '下架'],
};

/** 画笔：各层可用字符（01 §4.2 默认图例 + 02 §9.2 P4 扩展） */
const BRUSHES = {
    terrain: [
        ['o', '普通'], ['S', '生成器'], ['.', '空'], ['#', '障碍'],
        ['D', '出口'], ['P', '传送门'], ['>', '带→'], ['<', '带←'],
        ['^', '带↑'], ['v', '带↓'], ['*', '危险生成'],
    ],
    entity: [
        ['.', '空'], ['?', '随机'], ['1', '色1'], ['2', '色2'], ['3', '色3'],
        ['4', '色4'], ['5', '色5'], ['6', '色6'],
        ['H', '横条'], ['V', '竖条'], ['B', '炸弹'], ['M', '魔力鸟'],
        ['C', '牢笼'], ['X', '石块'], ['G', '藤蔓'], ['F', '固定'], ['T', '太妃糖'],
    ],
    overlay: [
        ['.', '无'], ['I', '单冰'], ['J', '双冰'], ['L', '果冻'],
        ['K', '雪块'], ['Q', '巧克力'], ['W', '毒液'], ['N', '锁定'],
    ],
};

const BELT_CHARS = '><^v';
const LAYERS     = ['terrain', 'entity', 'overlay'];
/** 新建尺寸变化时的默认填充字符 */
const FILL_CHAR  = { terrain: 'o', entity: '.', overlay: '.' };

/**
 * 通关目标类型：口径取自 controller/Level.php::describeGoals()，
 * extra.key 必须与该处读取的字段名一致，否则列表摘要会显示 "?"。
 */
const GOAL_TYPES = [
    { type: 'score',   label: '分数达标',   extra: null },
    { type: 'collect', label: '收集元素',   extra: { key: 'target', label: '元素编码', source: 'gems' } },
    { type: 'clear',   label: '清除障碍',   extra: { key: 'layer',  label: '障碍类型', source: 'layers' } },
    { type: 'order',   label: '顺序消除色', extra: { key: 'gem',    label: '颜色编码', source: 'gems' } },
    { type: 'rescue',  label: '解救对象',   extra: { key: 'target', label: '对象编码', source: null } },
    { type: 'boss',    label: '击败 Boss',  extra: { key: 'actor',  label: 'Boss 标识', source: null } },
    { type: 'spread',  label: '清除蔓延物', extra: { key: 'layer',  label: '蔓延物类型', source: 'layers' } },
    { type: 'survive', label: '存活回合',   extra: null },
];

/** 可作 clear 目标的层（与 LevelValidator::CLEARABLE_LAYERS 保持一致） */
const CLEARABLE_LAYERS = ['ice', 'jelly', 'snow', 'vine', 'chocolate', 'poison', 'bubble', 'toffee'];

/** 编辑器状态 */
const state = {
    layer:  'terrain',
    rows:   9,
    cols:   9,
    brush:  { terrain: 'o', entity: '.', overlay: '.' },
    layers: { terrain: [], entity: [], overlay: [] },
    spawn:  { mode: 'top', weights: [1, 1, 1, 1, 1, 1], excludeInitialMatch: true },
};

const Controller = {
    index() {
        const $modal = $('#level-modal');
        const $form  = $('#level-form');

        // ---- 表单校验（契约见 skill wiseadmin-admin-ui 第三节）----
        // rules 是 JSON 文本，用 onCustomRules 注册 validJson —— 组件无内置 json 规则
        const validator = new window.WsFormValidator('level-form', {
            liveValidation: true,
            onCustomRules: {
                validJson(value) {
                    if (!value) return true;   // 空值交由 required 判定
                    try { JSON.parse(value); return true; } catch (err) { return false; }
                },
            },
        });

        const table = createTable({
            url: URL.list,
            columns: [
                { field: 'id', title: 'ID', width: 60, align: 'center', sortable: true },
                { field: 'code', title: '编码', width: 110 },
                { field: 'name', title: '名称' },
                { field: 'chapter', title: '章节', width: 130 },
                { field: 'goal_text', title: '目标' },
                { field: 'moves', title: '步数', width: 70, align: 'center' },
                { field: 'sort_order', title: '排序', width: 70, align: 'center' },
                {
                    field: 'status', title: '状态', width: 90, align: 'center',
                    formatter(v) {
                        const [cls, text] = STATUS_BADGE[Number(v)] || ['bg-secondary', String(v)];
                        return '<span class="badge ' + cls + '">' + text + '</span>';
                    },
                },
                {
                    field: 'operate', title: '操作', width: 150, align: 'center',
                    formatter(value, row) { return operateButtons(OPERATE, row); },
                },
            ],
            query() {
                return {
                    keyword: $('#search-keyword').val() || '',
                    chapter: $('#search-chapter').val() || '',
                    status:  $('#search-status').val() || '',
                };
            },
            toolbarBtn: [
                { id: 'btn-add', name: 'add', title: '新增关卡', icon: 'bi bi-plus-lg', class: 'btn btn-sm btn-primary mx-1', click() { openModal(null); } },
                { id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1', click() { refresh(table); } },
            ],
            onClick(action, row, $el) {
                if (action === 'edit') return openModal(row, $el);
                if (action === 'del') return remove(row, $el);
            },
        });

        $('#btn-search').on('click', () => refresh(table));
        $('#search-form').on('submit', (e) => { e.preventDefault(); refresh(table); });
        $('#btn-reset').on('click', () => {
            $('#search-keyword').val('');
            $('#search-chapter').val('');
            $('#search-status').val('');
            refresh(table);
        });

        // ===================== 编辑器事件 =====================

        $('#layer-tabs').on('click', 'a[data-layer]', function (e) {
            e.preventDefault();
            state.layer = $(this).data('layer');
            $('#layer-tabs a').removeClass('active');
            $(this).addClass('active');
            renderBrushBar();
            renderGrid();
        });

        $('#brush-bar').on('click', 'button', function () {
            state.brush[state.layer] = $(this).data('ch').toString();
            renderBrushBar();
        });

        $('#grid-canvas').on('click', '.m3-cell', function () {
            const r = Number($(this).data('r'));
            const c = Number($(this).data('c'));
            paint(r, c);
        });

        LAYERS.forEach((layer) => {
            $(`#text-${layer}`).on('input', function () {
                state.layers[layer] = parseLayer($(this).val(), state.rows, state.cols, FILL_CHAR[layer]);
                syncHidden();
                renderGrid();
            });
        });

        $('#btn-resize').on('click', () => {
            state.rows = clampSize($('#form-rows').val());
            state.cols = clampSize($('#form-cols').val());
            LAYERS.forEach((layer) => {
                state.layers[layer] = resizeLayer(state.layers[layer], state.rows, state.cols, FILL_CHAR[layer]);
            });
            syncAll();
        });

        $('#btn-fill-rect').on('click', () => {
            fillStandardRect();
            syncAll();
        });

        // http.post 直接返回 body.data（wise-util 拦截器已解包），且不会自动 toast
        $('#btn-validate').on('click', async () => {
            syncHidden();
            $('#validate-result').html('<div class="text-muted small">校验中…</div>');
            try {
                const d = await http.post(URL.check, collectPayload());
                showValidateResult(d || {});
            } catch (e) {
                $('#validate-result').empty();
            }
        });

        // ===================== 通关条件控件（↔ rules JSON） =====================

        $('#form-moves, #form-star-1, #form-star-2, #form-star-3').on('input change', controlsToRules);

        // 直接编辑 JSON 时回填控件；解析失败则保持控件不动，由用户修正
        $('#form-rules').on('input', () => {
            if (rulesToControls()) refreshGoalLists();
        });

        // 元素候选跟随调色板变化
        $('#form-palette').on('input', refreshGoalLists);

        $('#goal-rows').on('input change', '.m3-goal-count, .m3-goal-extra', controlsToRules);

        $('#goal-rows').on('change', '.m3-goal-type', function () {
            const def = GOAL_TYPES.find((t) => t.type === String($(this).val()));
            $(this).closest('.m3-goal-row')
                .find('.m3-goal-extra, .m3-goal-extra-none').first()
                .replaceWith(goalExtraHtml(def, ''));
            controlsToRules();
        });

        $('#goal-rows').on('click', '.m3-goal-del', function () {
            $(this).closest('.m3-goal-row').remove();
            if (!$('#goal-rows').children('.m3-goal-row').length) renderGoalRows([]);
            controlsToRules();
        });

        $('#btn-add-goal').on('click', () => {
            $('#goal-rows').children().not('.m3-goal-row').remove();   // 去掉"未设置目标"占位
            $('#goal-rows').append(goalRowHtml({ type: 'score' }));
            controlsToRules();
        });

        // ===================== 表单 =====================

        /**
         * 打开新增 / 编辑弹窗
         *
         * ⚠️ 编辑时**不能只信列表行**`row`：表格组件取行失败时我们拿到的是 `{}`，
         * 于是 `row.id` 为 `undefined` → detail 接口收到 `id=0` → "关卡不存在" →
         * 退回 `fillForm({})` —— 表现就是"点了编辑，表单却和新增一样是空的"。
         * 现在 **id 以按钮上的 `data-id` 为准**（`operateButtons` 渲染时就写好了），
         * 完整数据一律向 `detail` 要，行数据只当兜底。
         */
        async function openModal(row, $el) {
            $form[0].reset();
            $('#validate-result').empty();

            const id = Number((row && row.id) || ($el ? $el.data('id') : 0) || 0);
            $('#form-id').val(id);
            $('#form-title').text(id > 0 ? '编辑关卡' : '新增关卡');

            if (id > 0) {
                try {
                    const res = await http.get(URL.detail, { id });
                    if (res && res.id) {
                        fillForm(res);
                        $modal.modal('show');
                        return;
                    }
                    console.warn('[match3game] 关卡详情为空', id, res);
                } catch (e) {
                    console.warn('[match3game] 关卡详情加载失败', id, e);
                }

                // 详情拿不到 → 退回列表行（列表行本身是完整的 toArray，只是没有兜底默认值）
                if (row && row.code) {
                    fillForm(row);
                    $modal.modal('show');
                    return;
                }

                toast('关卡详情加载失败，已按新建打开；请刷新列表后重试', 'error');
                fillDefaults();
                $modal.modal('show');
                return;
            }

            fillDefaults();
            $modal.modal('show');
        }

        /** 新增（或详情彻底拿不到时）的默认内容：不然表单会是一片空白 */
        function fillDefaults() {
            $('#form-difficulty').val(1);
            $('#form-cell-size').val(76);
            $('#form-seed-policy').val('server');
            // 与后端 `LevelProgressService::DEFAULT_UNLOCK_STARS` 保持一致（通关即解锁）
            $('#form-unlock-stars').val(1);
            $('#form-sort-order').val(0);
                $('#form-status').val(1);
                $('#form-rules').val(JSON.stringify({
                    mode: 'moves', moves: 30, minMatch: 3,
                    starScores: [3000, 6000, 9000],
                    goals: [{ type: 'score', count: 2000 }],
                    autoShuffle: true,
                }, null, 2));
                $('#form-palette').val(JSON.stringify({
                    gems: ['red_gem', 'blue_gem', 'yellow_gem', 'green_gem', 'purple_gem', 'orange_gem'],
                    theme: 'orchard',
                }, null, 2));
                $('#form-reward-conf').val(JSON.stringify({ coins: [200, 400, 600] }, null, 2));
                $('#form-props').val('');

                refreshGoalLists();
                rulesToControls();

            state.rows = 9;
            state.cols = 9;
            state.spawn = { mode: 'top', weights: [1, 1, 1, 1, 1, 1], excludeInitialMatch: true };
            fillStandardRect();
            syncAll();
        }

        function fillForm(d) {
            $('#form-code').val(d.code || '');
            $('#form-name').val(d.name || '');
            $('#form-chapter').val(d.chapter || '');
            $('#form-difficulty').val(d.difficulty || 1);
            $('#form-cell-size').val(d.cell_size || 76);
            $('#form-seed-policy').val(d.seed_policy || 'server');
            $('#form-fixed-seed').val(d.fixed_seed ?? '');
            $('#form-pre-unlock').val(d.pre_unlock || '');
            // 0 是历史合法值（"只要通关"），不能用 `||` 吃掉；缺省才回落到默认 1
            $('#form-unlock-stars').val(d.unlock_stars ?? 1);
            $('#form-sort-order').val(d.sort_order ?? 0);
            $('#form-status').val(d.status != null ? d.status : 1);
            $('#form-description').val(d.description || '');
            $('#form-rules').val(JSON.stringify(d.rules || {}, null, 2));
            $('#form-palette').val(d.palette ? JSON.stringify(d.palette, null, 2) : '');
            $('#form-reward-conf').val(d.reward_conf ? JSON.stringify(d.reward_conf, null, 2) : '');
            $('#form-props').val(d.props ? JSON.stringify(d.props, null, 2) : '');

            refreshGoalLists();
            rulesToControls();

            const region = (d.regions && d.regions[0]) || {};
            const layers = region.layers || {};
            const terrain = layers.terrain || [];

            state.rows = Number(d.board_rows) || terrain.length || 9;
            state.cols = Number(d.board_cols) || (terrain[0] ? terrain[0].length : 9);
            state.spawn = region.spawn || { mode: 'top', weights: [1, 1, 1, 1, 1, 1], excludeInitialMatch: true };

            LAYERS.forEach((layer) => {
                state.layers[layer] = resizeLayer(layers[layer] || [], state.rows, state.cols, FILL_CHAR[layer]);
            });
            if (state.layers.terrain.length === 0) {
                fillStandardRect();
            }

            $('#form-rows').val(state.rows);
            $('#form-cols').val(state.cols);
            syncAll();
        }

        async function remove(row, $el) {
            // 与 `openModal` 同理：行数据可能拿不到，id 优先取按钮上的 `data-id`
            const id = Number((row && row.id) || ($el ? $el.data('id') : 0) || 0);
            if (!id) {
                toast('无法确定要删除的关卡，请刷新列表后重试', 'error');
                return;
            }

            const ok = await confirm(
                '确定要删除关卡「' + ((row && row.name) || ('#' + id)) + '」吗？' +
                '删除为软删除：状态会变为「草稿」，可在「状态」筛选中找回并重新发布。'
            );
            if (!ok) return;
            const res = await submit({ url: URL.del, data: { ids: [id] }, message: '删除成功' });
            if (res) refresh(table);
        }

        $form.on('submit', async function (e) {
            e.preventDefault();
            syncHidden();

            // 字段级校验交由 WsFormValidator（错误内联到字段）。
            // 必须放在 syncHidden() 之后 —— 否则校验的是同步前的 rules 内容。
            if (validator.validateForm() !== true) return;

            const res = await submit({
                url: URL.save,
                data: collectPayload(),
                message: (Number($('#form-id').val()) > 0 ? '修改成功' : '添加成功'),
            });
            if (res) { $modal.modal('hide'); refresh(table); }
        });
    },
};

/* ======================================================================
 *  网格编辑器
 * ====================================================================== */

function clampSize(v) {
    const n = parseInt(v, 10);
    return Math.min(15, Math.max(3, isNaN(n) ? 9 : n));
}

/** 解析 textarea：每行一条，补齐/截断到 rows × cols */
function parseLayer(text, rows, cols, fill) {
    const src = String(text || '').split('\n');
    const out = [];
    for (let r = 0; r < rows; r++) {
        let line = (src[r] ?? '').replace(/\r/g, '');
        line = line.length > cols ? line.slice(0, cols) : line.padEnd(cols, fill);
        out.push(line);
    }
    return out;
}

function resizeLayer(layer, rows, cols, fill) {
    const out = [];
    for (let r = 0; r < rows; r++) {
        let line = layer[r] ?? '';
        line = line.length > cols ? line.slice(0, cols) : line.padEnd(cols, fill);
        out.push(line);
    }
    return out;
}

/** 标准矩形：第 0 行生成器 S，其余可玩格 o（01 §5.1） */
function fillStandardRect() {
    state.layers.terrain = resizeLayer(
        [strRepeat('S', state.cols)], state.rows, state.cols, 'o'
    );
    // 第 0 行必须整行是 S
    state.layers.terrain[0] = strRepeat('S', state.cols);
}

function strRepeat(ch, n) {
    return new Array(Math.max(0, n) + 1).join(ch);
}

function paint(r, c) {
    const layer = state.layer;
    const line  = state.layers[layer][r] ?? '';
    const ch    = state.brush[layer];
    state.layers[layer][r] = line.substring(0, c) + ch + line.substring(c + 1);
    // 必须一并刷新三个 textarea：否则它仍持有旧内容，
    // 用户随后在 textarea 里输入一次就会用旧内容反向覆盖 state.layers，
    // 画布上的点选被静默丢弃。
    syncTexts();
    syncHidden();
    renderGrid();
}

function terrainClass(ch) {
    if (BELT_CHARS.indexOf(ch) >= 0) return 'belt';
    return ch;
}

function renderGrid() {
    const $canvas = $('#grid-canvas');
    $canvas.empty().css('grid-template-columns', `repeat(${state.cols}, 26px)`);

    const { terrain, entity, overlay } = state.layers;
    const cur = state.layer;

    for (let r = 0; r < state.rows; r++) {
        for (let c = 0; c < state.cols; c++) {
            const t = terrain[r] ? terrain[r][c] : '.';
            const e = (entity[r] && entity[r][c]) ? entity[r][c] : '.';
            const o = (overlay[r] && overlay[r][c]) ? overlay[r][c] : '.';

            let text = '.';
            if (cur === 'terrain') text = t;
            else if (cur === 'entity') text = e;
            else if (cur === 'overlay') text = o;

            const $cell = $(`<div class="m3-cell t-${terrainClass(t)}" data-r="${r}" data-c="${c}"></div>`);
            if (o !== '.' && o !== '0') {
                $cell.append(`<div class="m3-ov m3-ov-${o}"></div>`);
            }
            $cell.append(`<span>${text === '.' ? '' : escapeHtml(text)}</span>`);
            $canvas.append($cell);
        }
    }
}

function renderBrushBar() {
    const $bar = $('#brush-bar');
    $bar.empty();
    BRUSHES[state.layer].forEach(([ch, label]) => {
        const active = state.brush[state.layer] === ch ? ' active' : '';
        $bar.append(
            `<button type="button" class="btn btn-sm btn-outline-secondary m3-brush${active}" data-ch="${ch}">${escapeHtml(ch)} <small>${label}</small></button>`
        );
    });
}

/** 三层 → 隐藏域 regions */
function syncHidden() {
    const region = {
        id: 'main',
        origin: { c: 0, r: 0 },
        gravity: 'down',
        layers: {
            terrain: state.layers.terrain,
            entity: state.layers.entity.some((l) => /[^.]/.test(l)) ? state.layers.entity : [],
            overlay: state.layers.overlay.some((l) => /[^.]/.test(l)) ? state.layers.overlay : [],
        },
        spawn: state.spawn,
    };
    $('#form-regions').val(JSON.stringify([region]));
}

/** 三层 → 三个 textarea */
function syncTexts() {
    LAYERS.forEach((layer) => {
        $(`#text-${layer}`).val(state.layers[layer].join('\n'));
    });
}

function syncAll() {
    syncTexts();
    syncHidden();
    renderBrushBar();
    renderGrid();
}

/* ======================================================================
 *  rules ↔ 通关条件控件 双向同步
 *
 *  `textarea[name=rules]` 仍是唯一提交源；下面这组控件只是它的翻译器：
 *    · 改控件  → 回写 rules JSON（保留 JSON 里未暴露的键：mode/minMatch/...）
 *    · 改 JSON（且能解析）→ 回填控件
 *  任一侧 JSON 非法时**不覆盖另一侧**，避免把用户正在编辑的内容吃掉。
 * ====================================================================== */

/** 解析 rules JSON；非法返回 null */
function readRules() {
    try {
        const v = JSON.parse($('#form-rules').val() || '{}');
        return (v && typeof v === 'object' && !Array.isArray(v)) ? v : null;
    } catch (e) {
        return null;
    }
}

/** 目标行 → rules.goals */
function goalsFromRows() {
    const goals = [];
    $('#goal-rows .m3-goal-row').each(function () {
        const def = GOAL_TYPES.find((t) => t.type === String($(this).find('.m3-goal-type').val()));
        if (!def) return;

        const goal = { type: def.type };

        const extra = String($(this).find('.m3-goal-extra').val() || '').trim();
        if (def.extra && extra !== '') goal[def.extra.key] = extra;

        const count = parseInt($(this).find('.m3-goal-count').val(), 10);
        if (!isNaN(count)) goal.count = count;

        goals.push(goal);
    });
    return goals;
}

/** 控件 → rules JSON */
function controlsToRules() {
    const rules = readRules();
    if (!rules) return;

    const moves = parseInt($('#form-moves').val(), 10);
    if (isNaN(moves)) delete rules.moves; else rules.moves = moves;

    const stars = [1, 2, 3].map((i) => parseInt($(`#form-star-${i}`).val(), 10));
    if (stars.every((v) => !isNaN(v))) rules.starScores = stars;

    rules.goals = goalsFromRows();
    $('#form-rules').val(JSON.stringify(rules, null, 2));
}

/** rules JSON → 控件；JSON 非法时返回 false 且不改动控件 */
function rulesToControls() {
    const rules = readRules();
    if (!rules) return false;

    $('#form-moves').val(rules.moves != null ? rules.moves : '');

    const stars = Array.isArray(rules.starScores) ? rules.starScores : [];
    for (let i = 1; i <= 3; i++) {
        $(`#form-star-${i}`).val(stars[i - 1] != null ? stars[i - 1] : '');
    }

    renderGoalRows(Array.isArray(rules.goals) ? rules.goals : []);
    return true;
}

function renderGoalRows(goals) {
    const $box = $('#goal-rows').empty();
    (Array.isArray(goals) ? goals : []).forEach((g) => $box.append(goalRowHtml(g || {})));

    if (!$box.children('.m3-goal-row').length) {
        $box.html('<div class="text-muted small py-1">未设置目标（<code>rules.goals</code> 为空会导致校验不通过）</div>');
    }
}

function goalRowHtml(goal) {
    const type = GOAL_TYPES.some((t) => t.type === goal.type) ? goal.type : 'score';
    const def  = GOAL_TYPES.find((t) => t.type === type);

    const options = GOAL_TYPES
        .map((t) => `<option value="${t.type}"${t.type === type ? ' selected' : ''}>${t.label}</option>`)
        .join('');

    const count = goal.count != null ? escapeHtml(String(goal.count)) : '';
    const extra = def.extra && goal[def.extra.key] != null ? String(goal[def.extra.key]) : '';

    return `<div class="m3-goal-row input-group input-group-sm mb-1">
        <select class="form-select m3-goal-type" style="max-width:132px">${options}</select>
        ${goalExtraHtml(def, extra)}
        <input type="number" class="form-control m3-goal-count" style="max-width:118px" min="1" placeholder="数量" value="${count}">
        <button type="button" class="btn btn-outline-danger m3-goal-del" title="删除该目标"><i class="bi bi-x-lg"></i></button>
    </div>`;
}

/** 目标类型的"附加字段"（collect→target / clear→layer / boss→actor …） */
function goalExtraHtml(def, value) {
    if (!def.extra) {
        return '<span class="input-group-text text-muted m3-goal-extra-none" title="该类型无需附加字段">—</span>';
    }
    const list  = def.extra.source === 'gems' ? 'goal-gem-list'
        : def.extra.source === 'layers' ? 'goal-layer-list' : '';
    const attr  = list ? ` list="${list}"` : '';
    return `<input type="text" class="form-control m3-goal-extra" style="max-width:160px"${attr}` +
        ` placeholder="${escapeHtml(def.extra.label)}" value="${escapeHtml(value)}">`;
}

/** 两个候选下拉：可清除层来自后端常量，元素候选取自当前 palette.gems */
function refreshGoalLists() {
    $('#goal-layer-list').html(
        CLEARABLE_LAYERS.map((l) => `<option value="${l}"></option>`).join('')
    );

    let gems = [];
    try {
        const palette = JSON.parse($('#form-palette').val() || '{}');
        if (Array.isArray(palette.gems)) gems = palette.gems;
    } catch (e) { /* palette 非法时不给候选 */ }

    $('#goal-gem-list').html(
        gems.map((g) => `<option value="${escapeHtml(String(g))}"></option>`).join('')
    );
}

/** 收集提交载荷（只取表单里有 name 的字段） */
function collectPayload() {
    const data = {};
    $('#level-form').find('input, select, textarea').each(function () {
        const n = $(this).attr('name');
        if (n) data[n] = $(this).val();
    });
    data.board_rows = state.rows;
    data.board_cols = state.cols;
    return data;
}

function showValidateResult(res) {
    const $box = $('#validate-result');
    // http 拦截器已解包，res 即 data 载荷本身
    const d = res || {};
    if (d.valid) {
        $box.html(`<div class="alert alert-success mb-0 py-2">校验通过，指纹 <code>${escapeHtml(d.fingerprint || '')}</code></div>`);
    } else {
        const items = (d.errors || []).map((e) => `<li>${escapeHtml(e)}</li>`).join('');
        $box.html(`<div class="alert alert-danger mb-0 py-2"><b>校验不通过：</b><ul class="mb-0">${items}</ul></div>`);
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (m) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
    ));
}

export default Controller;
