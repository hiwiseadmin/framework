/**
 * 系统工具页面控制器（UIUX-P2-06 / P2-05）
 *
 * 由 `app/plugin/base/BaseController.php:51` 自动注入路径 `../plugins/wisecms/js/system`
 * （`SystemController` → snake_case `system`）。
 *
 * 对应控制器动作：
 *   system/index        → 系统工具页（视图）
 *   system/flushCache   → 刷新 CMS 缓存（POST，清空本插件全部缓存家族）
 *   system/orphans      → 孤儿数据扫描（GET，dry-run，只统计不写入）
 *   system/purgeOrphans → 孤儿数据清理（POST，**物理删除**）
 *
 * 分级（方案 §4.6）：
 *   · 刷新缓存 —— 幂等、不动数据 → **L2**（确认框 + 回显影响面，走 `cmsAction.confirmMessage`）；
 *   · 孤儿数据清理 —— 不可逆物理删除 → **L3**（先「扫描」预览 → `cmsDangerConfirm`
 *     逐字输入确认词 `DELETE` → 才提交）。
 *
 * 说明：两个操作都是**全站**范围（缓存家族不分站点；孤儿行按主键悬空判定），故本页不挂站点条。
 *
 * @see statics/js/cms-admin.js
 */

import { cmsUrl, cmsAction, cmsToast, cmsEscape, cmsDangerConfirm } from './cms-admin.js';

/** L3 确认词（与方案 §4.6 的示例一致） */
const PURGE_WORD = 'DELETE';

const URL = {
    flushCache: cmsUrl('system/flushCache'),
    orphans: cmsUrl('system/orphans'),
    purgeOrphans: cmsUrl('system/purgeOrphans'),
};

/** 内核 http 就绪检测 */
function http() {
    const wise = window.Wise || {};
    return (wise.util && wise.util.http) || null;
}

/** GET（内核默认 `interceptor:true` → resolve 的就是信封里的 `data`） */
function httpGet(url) {
    const client = http();
    if (!client || typeof client.get !== 'function') {
        return Promise.reject(new Error('请求组件未就绪'));
    }
    return client.get(url, {}, { loading: true });
}

/**
 * POST 并拿到**完整信封** `{code,msg,data}`
 *
 * 走 `interceptor:false`：清理接口返回的 `msg` 带**实际删除行数**，
 * 比前端拿「扫描时的数字」拼提示更可信（同 `url_rule.js` rebuild 的取数口径）。
 */
function httpPostRaw(url, data) {
    const client = http();
    if (!client || typeof client.post !== 'function') {
        return Promise.reject(new Error('请求组件未就绪'));
    }
    return client.post(url, data || {}, { interceptor: false, loading: true });
}

/**
 * 渲染孤儿报告（`{total, tables:[{table,label,count}]}`）
 *
 * @param {Object} data
 * @param {'preview'|'done'} mode preview = 扫描结果（待清理）/ done = 执行结果（已清理）
 * @returns {string}
 */
function renderReport(data, mode) {
    const rows = (data && Array.isArray(data.tables)) ? data.tables : [];
    const heading = mode === 'done' ? '本次已清理' : '待清理';

    if (rows.length === 0) {
        return '<div class="alert alert-success py-2 mb-0 small">'
            + '<i class="bi bi-check-circle me-1"></i>未发现孤儿数据。'
            + '</div>';
    }

    let total = 0;
    let body = '';
    rows.forEach((row) => {
        const count = Number(row.count) || 0;
        total += count;
        body += '<tr>'
            + '<td><code>' + cmsEscape(row.table) + '</code></td>'
            + '<td>' + cmsEscape(row.label) + '</td>'
            + '<td class="text-end">' + count + '</td>'
            + '</tr>';
    });

    return '<div class="small text-muted mb-1">' + heading + '：<b>' + total + '</b> 行（'
        + rows.length + ' 张表）</div>'
        + '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">'
        + '<thead><tr><th style="width:40%">数据表</th><th>说明</th>'
        + '<th class="text-end" style="width:120px">行数</th></tr></thead>'
        + '<tbody>' + body + '</tbody>'
        + '<tfoot><tr><th colspan="2">合计</th><th class="text-end">' + total + '</th></tr></tfoot>'
        + '</table></div>';
}

/** 刷新 CMS 缓存（L2：确认框 + 回显影响面） */
function bindFlushCache() {
    $('#btn-flush-cache').on('click', () => cmsAction({
        method: 'POST',
        url: URL.flushCache,
        data: {},
        confirmMessage: '将清空 wisecms 插件的全部缓存（内容 / 栏目 / 标签 / Feed / '
            + '模板标签库 / 推荐位 / SEO 等，全站所有站点）。'
            + '不影响数据库数据，刷新后首次访问会重新生成缓存。确定继续？',
        successMsg: '缓存已刷新',
    }));
}

/** 孤儿数据：扫描预览 → L3 确认 → 清理（P2-05） */
function bindOrphans() {
    const $scan = $('#btn-scan-orphans');
    const $purge = $('#btn-purge-orphans');
    const $result = $('#orphan-result');
    const $summary = $('#orphan-summary');

    if (!$scan.length) {
        return;
    }

    let scanned = false;   // 是否已扫描（未扫描不允许直接清理）
    let pending = 0;       // 待清理行数

    /** 回到「未扫描 / 不可清理」状态 */
    const idle = () => {
        scanned = false;
        pending = 0;
        $purge.prop('disabled', true).html('<i class="bi bi-eraser me-1"></i>清理孤儿数据');
    };

    /** 扫描（只统计不写入） */
    const scan = () => {
        $scan.prop('disabled', true);
        return httpGet(URL.orphans)
            .then((data) => {
                const total = Number((data || {}).total) || 0;
                scanned = true;
                pending = total;

                $result.html(renderReport(data || {}, 'preview'));

                if (total > 0) {
                    $summary.html('<span class="text-danger fw-semibold">待清理 ' + total + ' 行</span>');
                    $purge.prop('disabled', false)
                        .html('<i class="bi bi-eraser me-1"></i>清理孤儿数据（' + total + ' 行）');
                } else {
                    $summary.html('<span class="text-success">未发现孤儿数据</span>');
                    idle();
                }
            })
            .catch(() => cmsToast('扫描失败，请稍后重试', 'error'))
            .then(() => { $scan.prop('disabled', false); });
    };

    /** 清理（L3：逐字输入确认词才放行） */
    const purge = () => {
        if (!scanned || pending <= 0) {
            cmsToast('请先扫描，确认存在待清理项后再执行', 'warning');
            return;
        }

        cmsDangerConfirm({
            title: '清理孤儿数据（不可恢复）',
            html: '<div class="alert alert-danger mb-2">'
                + '将<b>物理删除 ' + pending + ' 行</b>孤儿数据，<b>不可恢复</b>。'
                + '<br>被删的每一行其外键都指向不存在的内容，不影响任何可见数据。'
                + '<br>作用范围：<b>全站（所有站点）</b>。'
                + '</div>'
                + '<div class="small text-muted">执行前请确认上方「待清理」清单符合预期。</div>',
            word: PURGE_WORD,
            confirmText: '确认清理',
        }).then((ok) => {
            if (!ok) {
                return undefined;
            }

            $purge.prop('disabled', true);

            return httpPostRaw(URL.purgeOrphans, {})
                .then((body) => {
                    if (!body || body.code !== 0) {
                        cmsToast((body && body.msg) || '清理失败，请稍后重试', 'error');
                        return undefined;
                    }

                    cmsToast(body.msg || '孤儿数据清理完成', 'success');
                    $result.html(renderReport(body.data || {}, 'done'));
                    $summary.html('<span class="text-success">' + cmsEscape(body.msg || '已清理') + '</span>');
                    idle();
                    return undefined;
                })
                .catch(() => {
                    cmsToast('清理失败，请稍后重试', 'error');
                    // 失败时保留扫描结果，允许直接重试（不清空 scanned / pending）
                    $purge.prop('disabled', false);
                });
        });
    };

    $scan.on('click', scan);
    $purge.on('click', purge);
}

export default {
    /**
     * 系统工具页入口（与 action 同名，由 wise-bootstrap 调用）
     */
    index() {
        bindFlushCache();
        bindOrphans();

        // 本页无表格，无需返回 EnhancedTable 实例
        return null;
    },
};
