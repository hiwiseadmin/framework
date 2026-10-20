/**
 * reward.js — GM 道具发放 + 背包流水审计（P6-B04）
 */
import { http, $, submit, toast } from './common/util.js';
import { createTable, refresh } from './common/table.js';

export default {
    async index() {
        // ---------- 资源清单 ----------
        let items = [];
        try {
            const res = await http.get('/plugin/match3game/reward/items');
            items = res || [];
        } catch (e) {
            /* 错误由全局处理器提示 */
        }

        const itemName = {};
        const opts = items
            .map((i) => {
                itemName[i.item_id] = i.name;
                return '<option value="' + i.item_id + '">' + i.name + '</option>';
            })
            .join('');
        $('#form-item-id').html(opts);
        $('#log-item-id').append(
            items.map((i) => '<option value="' + i.item_id + '">' + i.name + '</option>').join('')
        );

        // ---------- 发放 ----------
        $('#reward-form').on('submit', async function (e) {
            e.preventDefault();
            const data = {};
            $(this).find('input, select').each(function () {
                const n = $(this).attr('name');
                if (n) data[n] = $(this).val();
            });

            const res = await submit({
                url: '/plugin/match3game/reward/send',
                data,
                message: '发放成功',
                confirmTitle:
                    '确认发放 ' + data.count + ' 个 ' + (itemName[data.item_id] || data.item_id) +
                    ' 给用户 ' + data.user_id + '？',
            });

            if (res) {
                $('#reward-form')[0].reset();
                // 发放后自动把用户ID带到审计查询，方便立刻核对流水
                $('#log-user-id').val(data.user_id);
                refresh(logTable);
            }
        });

        // ---------- 流水审计 ----------
        const logTable = createTable({
            // 本页表格与工具栏的 id 与其它页不同（#log-table / #log-toolbar），
            // 必须显式覆盖 createTable 的默认值，否则 EnhancedTable 找不到容器直接抛错
            idTable: 'log-table',
            extend: { toolbar: '#log-toolbar' },
            url: '/plugin/match3game/reward/logs',
            columns: [
                { field: 'id', title: 'ID', width: 80, align: 'center', sortable: true },
                {
                    field: 'item_id', title: '物品', width: 110,
                    formatter: (v) => itemName[v] || v,
                },
                {
                    field: 'change', title: '变动', width: 100, align: 'right',
                    formatter(v) {
                        const n = Number(v);
                        const cls = n >= 0 ? 'text-success' : 'text-danger';
                        return '<span class="' + cls + '">' + (n >= 0 ? '+' : '') + n + '</span>';
                    },
                },
                { field: 'balance', title: '变动后余额', width: 120, align: 'right' },
                {
                    field: 'reason', title: '原因', width: 200,
                    formatter: (v, row) => (row.reason_text || v) + ' <code>' + v + '</code>',
                },
                {
                    field: 'extra', title: '扩展', formatter: (v) => (v ? String(v) : '-'),
                },
                { field: 'create_time', title: '时间', width: 170 },
            ],
            query() {
                return {
                    user_id: $('#log-user-id').val() || '',
                    item_id: $('#log-item-id').val() || '',
                };
            },
            toolbarBtn: [
                {
                    id: 'btn-log-refresh', name: 'refresh', title: '刷新',
                    icon: 'bi bi-arrow-repeat', class: 'btn btn-sm btn-info mx-1',
                    click() { refresh(logTable); },
                },
            ],
        });

        $('#log-search-form').on('submit', (e) => {
            e.preventDefault();
            if (!$('#log-user-id').val()) {
                toast('请先填写用户ID', 'error');
                return;
            }
            refresh(logTable);
        });
    },
};
