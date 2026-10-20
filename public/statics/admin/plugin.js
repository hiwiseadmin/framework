/**
 * plugin.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const layer = window.parent.Layer || window.Layer;

const OK_CODE = 0;

/**
 * 列表行数据缓存（ident → 行）。
 *
 * 行内按钮只能拿到 data-* 属性，而「安装状态冲突」弹窗需要整行数据
 * （severity / state_key / migration_rows / declared_tables / residual_tables），
 * 故在 load-success 里按 ident 建索引供弹窗取用。
 */
const rowIndex = {};

/** HTML 转义（弹窗内容含表名/插件名，需防注入） */
function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[m]));
}

/**
 * 通知顶部框架刷新「应用启动台」。
 *
 * 启动台在首页是服务端直出的快照，安装/卸载插件会改变插件入口集合。
 * 页面控制器（admin/index.js）已把刷新能力挂到 window.Wise.launcher.reload，
 * 这里通过 window.parent 调用；非 iframe / 无该 API 时静默降级。
 */
function notifyLauncherReload() {
    try {
        const topWin = window.parent || window;
        const api = topWin.Wise && topWin.Wise.launcher;
        if (api && typeof api.reload === 'function') {
            api.reload();
        }
    } catch (e) {
        // 独立打开页面或跨域时忽略，不影响插件操作本身
    }
}

/**
 * 重装请求（P1）：/admin/plugin/reinstall
 *
 * @param {string} ident   插件标识
 * @param {string} mode    repair（保留数据）| force（清库重建，删数据）
 * @param {Function} onDone 成功回调（通常是刷新表格）
 */
function doReinstall(ident, mode, onDone) {
    $.ajax({
        url: '/admin/plugin/reinstall',
        type: 'POST',
        data: { plugin: ident, mode: mode },
        dataType: 'json',
        success(res) {
            if (res && res.code === OK_CODE) {
                layer.top().toast({ content: res.msg || '操作成功', type: 'success' });
                if (typeof onDone === 'function') onDone();
            } else {
                layer.top().toast({ content: '操作失败：' + ((res && res.msg) || '未知错误'), type: 'error' });
            }
        },
        error() {
            layer.top().toast({ content: '操作失败：请求错误', type: 'error' });
        },
    });
}

/**
 * 安装状态冲突弹窗（P1）。
 *
 * 把后端自检的三处事实摆给运营看，并给出两条出路：
 *   仅修复状态（保留数据，安全） / 清库重装（删除数据表，危险，二次确认）
 * 这样"列表未安装、点安装报表已存在"就不再是死路。
 */
function showConflictDialog(row, onDone) {
    const tables = row.residual_tables || [];
    const shown = tables.slice(0, 20);
    const rest = tables.length > shown.length
        ? '<li class="text-muted">… 其余 ' + (tables.length - shown.length) + ' 张</li>'
        : '';
    const tableList = tables.length
        ? '<ul class="mb-2 ps-3">' + shown.map((t) => '<li><code>' + esc(t) + '</code></li>').join('') + rest + '</ul>'
        : '<div class="text-muted mb-2">未检测到残留数据表</div>';

    const html = ''
        + '<div style="line-height:1.7">'
        + '<div class="mb-2">插件 <b>' + esc(row.name || row.ident) + '</b>'
        + '（<code>' + esc(row.ident) + '</code>）的安装记录不一致：</div>'
        + '<table class="table table-sm mb-2"><tbody>'
        + '<tr><td style="width:140px">① 安装状态键</td><td>'
        + (Number(row.state_key) ? '已记录' : '<span class="text-danger">未记录</span>') + '</td></tr>'
        + '<tr><td>② 迁移记录</td><td>' + Number(row.migration_rows || 0) + ' 条</td></tr>'
        + '<tr><td>③ 数据库实际表</td><td>' + tables.length + ' / '
        + Number(row.declared_tables || 0) + ' 张仍存在</td></tr>'
        + '</tbody></table>'
        + '<div class="mb-1">该插件声明的数据表：</div>' + tableList
        + '<div class="text-danger mb-2">「清库重装」会<strong>永久删除</strong>以上数据表的全部数据。</div>'
        + '<div class="text-muted small">命令行等价操作：'
        + '<code>php think plugin:migrate:rollback -p ' + esc(row.ident) + ' --all</code></div>'
        + '</div>';

    layer.top().open({
        type: 1,
        icon: -1,
        title: '安装状态冲突',
        area: ['640px', 'auto'],
        content: html,
        btn: ['仅修复状态（保留数据）', '清库重装（删除数据表）', '取消'],
        yes() {
            doReinstall(row.ident, 'repair', onDone);
            return true;
        },
        btn2() {
            layer.top().confirm(
                '将删除该插件的 ' + tables.length + ' 张数据表并重新建表灌初始数据，'
                + '全部业务数据不可恢复。确定继续吗？',
                { icon: 'danger' },
                () => doReinstall(row.ident, 'force', onDone)
            );
            return true;
        },
    });
}

const Controller = {
        index() {
            // 当前激活的分类（0 = 全部）。
            // 用变量维护而非依赖 DOM 的 active 状态：切换「全部/本地插件」后
            // 分类 tab 会按响应重建，DOM active 不再可靠，必须以本值为准。
            let currentCatid = 0;

            // 安装状态列：正常态沿用通用 normal 渲染（颜色徽标 + searchList 文案）；
            // 因库包依赖缺失而**不会被加载**时，改为展示诊断徽标（tooltip 给出缺失的包），
            // 避免"已启用却没效果"无从排查。
            const stateColumn = {
                field: 'state',
                title: '安装状态',
                searchList: {
                    not_installed: '未安装',
                    installed: '已安装·未启用',
                    enabled: '已启用',
                    // 数据与状态不一致：库里有残留表却无安装状态，或状态说装过而表不在。
                    // 这类插件既不能装（撞 1050 表已存在）也不该启用（表不可用），
                    // 只能走「重装」把两头对齐。
                    inconsistent: '数据残留·需修复',
                },
                custom: {
                    not_installed: 'gray',
                    installed: 'warning',
                    enabled: 'success',
                    inconsistent: 'danger',
                },
                width: 130,
            };

            stateColumn.formatter = function (value, row, index) {
                if (row && row.blocked) {
                    const reason = String(row.block_reason || '依赖的库包未安装，该插件不会被加载')
                        .replace(/&/g, '&amp;')
                        .replace(/"/g, '&quot;')
                        .replace(/</g, '&lt;');

                    return '<span class="badge text-bg-warning" data-bs-toggle="tooltip" '
                        + 'data-bs-placement="top" title="' + reason + '">依赖缺失·未加载</span>';
                }

                return window.EnhancedTable.FORMATTERS.normal.call(stateColumn, null, value, row, index);
            };

            // 初始化表格
            const table = new Table({
                idTable: 'table',
                // 用 ident（插件目录名）作为主键：name 是 manifest 的 title（可能是中文），
                // 后端生命周期操作按插件标识校验，传 name 会被拒绝
                pk: 'ident',
                url: '/admin/plugin/getList',
                extend: {
                    edit_url: '/admin/plugin/edit',
                    del_url: '/admin/plugin/uninstall',
                    multi_url: '/admin/plugin/multi'
                },
                toolbarBtn:[],
                columns: [{
                    field: 'index_url',
                    title: '前台',
                    formatter: 'link',
                    text: ' ',
                    alt: '点击打开插件前台',
                    underline: false,
                    icon: 'bi bi-house-heart-fill',
                    width: 60
                }, {
                    field: 'name',
                    title: '插件名称',
                    formatter: 'normal',
                    width: 200
                }, {
                    field: 'description',
                    title: '插件描述',
                    formatter: 'normal',
                }, {
                    field: 'author',
                    title: '作者',
                    formatter: 'normal',
                    width: 120
                }, {
                    field: 'version',
                    title: '版本号',
                    formatter: 'normal',
                    width: 100
                }, {
                    field: 'download',
                    title: '下载量',
                    formatter: 'normal',
                    width: 100
                }, stateColumn, {
                    field: 'status',
                    title: '启用',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/admin/plugin/toggle',
                    // 未安装的插件禁用开关：建库属于"安装"动作，
                    // 开关只翻转 enabled 状态位，不允许隐式建表；
                    // 硬依赖缺失（blocked）时启用也毫无效果（initServiceProvider 会跳过），一并禁用
                    disable: (value, row) => !row.installed || !!row.blocked,
                    width: 80
                }, {
                    field: 'operate',
                    title: '操作',
                    buttons: [{
                        text: '配置',
                        extend: 'data-bs-toggle="tooltip" title="配置"',
                        icon: 'bi bi-gear-fill',
                        url: '/admin/plugin/config',
                        classname: 'btn-secondary',
                        event: 'config',
                        data(row) {
                            return {'plugin-ident': row.ident};
                        },
                        hidden(value, row, button){
                            return !(row.actions || []).includes('config');
                        }
                    },{
                        text: '升级',
                        extend: 'data-bs-toggle="tooltip" title="升级"',
                        icon: 'bi bi-cloud-arrow-down-fill',
                        url: '/admin/plugin/upgrade',
                        classname: 'btn-primary',
                        event: 'upgrade',
                        data(row, index){
                            return {version: 'last', 'plugin-ident': row.ident};
                        },
                        dropdown: 'versions',
                        hidden(value, row, button){
                            return !(row.actions || []).includes('upgrade');
                        }
                    },{
                        text: '安装',
                        extend: 'data-bs-toggle="tooltip" title="安装"',
                        icon: 'bi bi-cloud-arrow-down-fill',
                        url: '/admin/plugin/install',
                        classname: 'btn-primary',
                        event: 'install',
                        data(row, index){
                            return {version: 'last', 'plugin-ident': row.ident};
                        },
                        dropdown: 'versions',
                        hidden(value, row, button){
                            return !(row.actions || []).includes('install');
                        }
                    },                    {
                        text: '卸载',
                        extend: 'data-bs-toggle="tooltip" title="卸载"',
                        icon: 'bi bi-trash3',
                        url: '/admin/plugin/uninstall',
                        classname: 'btn-danger',
                        event: 'uninstall',
                        data(row) {
                            return {'plugin-ident': row.ident};
                        },
                        hidden(value, row, button){
                            return !(row.actions || []).includes('uninstall');
                        }
                    },                    {
                        // 安装状态冲突时的唯一出路：仅修复状态 / 清库重装
                        text: '重装',
                        extend: 'data-bs-toggle="tooltip" title="安装状态冲突，点此修复"',
                        icon: 'bi bi-arrow-repeat',
                        url: '/admin/plugin/reinstall',
                        classname: 'btn-warning',
                        event: 'reinstall',
                        data(row){
                            return {'plugin-ident': row.ident, 'mode': 'repair'};
                        },
                        hidden(value, row, button){
                            return !(row.actions || []).includes('reinstall');
                        }
                    }],
                    formatter: 'operate',
                    width: 'auto'
                }]
            });

            /**
             * 只刷新单行（安装 / 卸载 / 升级 / 重装成功后）。
             *
             * 不再整表 refresh：整表重取会让列表重排、滚动复位，运营"装一个、找一次"，
             * 连续安装多个插件时体验极差。这里按 ident 重取该行数据并原地 updateRow，
             * 行的操作按钮（安装/卸载/配置/重装…）、安装状态徽标与启用开关一并更新。
             *
             * 取不到单行（插件目录已删除等）时回落到整表刷新，保证状态最终一致。
             */
            function refreshRow(ident, fallback) {
                const fallbackRefresh = () => {
                    if (typeof fallback === 'function') fallback();
                    else table.refresh();
                };

                if (!ident) return fallbackRefresh();

                $.ajax({
                    url: '/admin/plugin/getList',
                    type: 'GET',
                    data: { row_ident: ident },
                    dataType: 'json',
                    success(res) {
                        const fresh = (res && res.code === OK_CODE && res.data
                            && res.data.list && res.data.list[0]) || null;
                        if (!fresh) return fallbackRefresh();

                        const jq = table.getTable();
                        const data = jq.bootstrapTable('getData') || [];
                        const index = data.findIndex((r) => r && r.ident === ident);
                        if (index < 0) return fallbackRefresh();

                        // 合并而非整行替换：远程市场列表特有的展示字段保持原值，
                        // 避免被本地 manifest 数据覆盖（如"下载量"被抹成 0）
                        const original = data[index];
                        const row = Object.assign({}, original, fresh);
                        if (original.download !== undefined) row.download = original.download;
                        if (original.category !== undefined) row.category = original.category;

                        jq.bootstrapTable('updateRow', { index: index, row: row });
                        rowIndex[ident] = row;
                    },
                    error() {
                        fallbackRefresh();
                    },
                });
            }

            /**
             * 生命周期操作（安装/卸载/升级/重装）成功后的统一收尾：
             * 局部刷新该行 + 通知顶部框架刷新启动台入口。
             */
            function afterLifecycleChange(ident, fallback) {
                refreshRow(ident, fallback);
                notifyLauncherReload();
            }

            // 启用/禁用成功后联动刷新「安装状态」徽标（已启用 ⇄ 已安装·未启用）。
            // EnhancedTable 仅在请求成功时派发该事件；业务失败会走 catch 恢复开关，
            // 故这里无需处理失败分支。只替换该单元格（reinit:false），不重绘整表。
            table.getTable().on('ws:table:toggle-success', function(e, field, value, row, index) {
                if (field !== 'status' || !row) return;

                row.state = Number(value) == 1 ? 'enabled' : 'installed';
                rowIndex[row.ident] = row;

                try {
                    table.getTable().bootstrapTable('updateCell', {
                        index: index,
                        field: 'state',
                        value: row.state,
                        reinit: false,
                    });
                } catch (err) {
                    console.warn('[plugin] 安装状态徽标联动失败', err);
                }
            });

            // 行内安装插件（安装 = 建表 + 灌初始数据 + 框架集成，需二次确认）
            table.getTable().on('click', '[data-event="install"]', function(e) {
                e.preventDefault();
                let $btn = $(this);
                let url = $btn.data('url');
                let version = $btn.data('version');
                let pluginIdent = $btn.data('pluginIdent');
                let mainBtn = $btn.hasClass('dropdown-item') ? $btn.closest('.btn-group').find('button:first') : this;

                // 安装状态冲突（库里有残留表但状态说没装）时，"安装"必然撞 1050。
                // 直接改走冲突弹窗，避免用户点一次、看一次无解的报错。
                let row = rowIndex[pluginIdent];
                if (row && row.state === 'inconsistent') {
                    showConflictDialog(row, () => afterLifecycleChange(pluginIdent));
                    return;
                }

                layer.top().confirm(
                    '安装将创建该插件所需的数据表并写入初始数据，确定安装吗？',
                    {icon: 'primary'},
                    () => {
                        let html = $(mainBtn).html();
                        $(mainBtn).text('安装中...').prop('disabled', true);
                        $.ajax({
                            url: url,
                            type: 'POST',
                            data: {
                                plugin: pluginIdent,
                                version: version,
                            },
                            dataType: 'json',
                            success(data){
                                if(data.code === OK_CODE) {
                                    layer.top().toast({content: '安装成功', type: 'success'});
                                    afterLifecycleChange(pluginIdent);
                                } else {
                                    layer.top().toast({content: '安装失败:' + data.msg, type: 'error'});
                                }
                                $(mainBtn).html(html).prop('disabled', false);
                            },
                            error(){
                                $(mainBtn).html(html).prop('disabled', false);
                                layer.top().toast({content: '安装失败: 请求错误', type: 'error'});
                            }
                        });
                        return true;
                    }
                );
            });

            // 行内升级插件
            table.getTable().on('click', 'a[data-event="upgrade"]', function(e) {
                e.preventDefault();
                let url = $(this).data('url');
                let version = $(this).data('version') || 'last';
                let pluginIdent = $(this).data('pluginIdent') || ($(this).closest('.btn-group').length ? $(this).closest('.btn-group').find('button:first').data('pluginIdent') : '');
                let mainBtn = $(this).hasClass('dropdown-item') ? $(this).closest('.btn-group').find('button:first') : this;
                let html = $(mainBtn).html();
                $(mainBtn).text('升级中...').prop('disabled', true);
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        name: pluginIdent,
                        version: version,
                    },
                    dataType: 'json',
                    success(data){
                        if(data.code === OK_CODE) {
                            layer.top().toast({content: '安装成功', type: 'success'});
                            afterLifecycleChange(pluginIdent, () => table.refresh());
                        } else {
                            layer.top().toast({content: '安装失败:' + data.msg, type: 'error'});
                        }
                        $(mainBtn).html(html).prop('disabled', false);
                    },
                    error(){
                        $(mainBtn).html(html).prop('disabled', false);
                        layer.top().toast({content: '安装失败: 请求错误', type: 'error'});
                    }
                });
            });

            // 行内卸载插件（卸载 = 回滚迁移删表 + 清菜单权限 + 删静态资源，需二次确认）
            table.getTable().on('click', 'a[data-event="uninstall"]', function(e) {
                e.preventDefault();
                let $btn = $(this);
                let url = $btn.data('url');
                let pluginIdent = $btn.data('pluginIdent');

                layer.top().confirm(
                    '卸载将删除该插件的数据表、菜单权限与静态资源，且不可恢复，确定卸载吗？',
                    {icon: 'danger'},
                    () => {
                        let html = $btn.html();
                        $btn.text('卸载中...').prop('disabled', true);
                        let that = $btn[0];
                        $.ajax({
                            url: url,
                            type: 'POST',
                            data: { plugin: pluginIdent },
                            success(data){
                                if(data.code === OK_CODE) {
                                    layer.top().toast({content: '卸载成功', type: 'success'});
                                    afterLifecycleChange(pluginIdent);
                                } else {
                                    layer.top().toast({content: '卸载失败:' + data.msg, type: 'error'});
                                }
                                $(that).html(html).prop('disabled', false);
                            },
                            error(){
                                $(that).html(html).prop('disabled', false);
                                layer.top().toast({content: '卸载失败: 请求错误', type: 'error'});
                            }
                        });
                        return true;
                    }
                );
            });

            // 行内重装（安装状态冲突修复）：弹窗里再选"仅修复状态 / 清库重装"
            table.getTable().on('click', 'a[data-event="reinstall"]', function(e) {
                e.preventDefault();

                let pluginIdent = $(this).data('pluginIdent');
                let row = rowIndex[pluginIdent];

                if (!row) {
                    layer.top().toast({ content: '未获取到该插件的自检信息，请刷新列表后重试', type: 'error' });
                    return;
                }

                showConflictDialog(row, () => afterLifecycleChange(pluginIdent, () => table.refresh()));
            });

            // 行内配置按钮
            // URL 形态与「插件配置」菜单保持一致：/admin/plugin/configIndex/name/{ident}
            // （path 参数形式 —— 请求路由与菜单派生的权限节点 route 同构，非超管才不会被 RBAC 拦掉；
            //   历史 query 形式 /admin/plugin/configIndex?name=xxx 因权限 route 带 query 而恒 403）
            table.getTable().on('click', 'a[data-event="config"]', function(e) {
                e.preventDefault();
                var pluginName = $(this).data('pluginIdent');
                layer.iframe('/admin/plugin/configIndex/name/' + encodeURIComponent(pluginName), {title: '插件配置', area: ['80%', '80%'], btn: ['保存配置', '关闭'],
                    yes: function(index, layero){
                        // 插件页运行在标签页 iframe 中，layer 实例在父窗口：
                        // 必须用 getChildFrame（在 layer 所在窗口查找），
                        // 否则 document.getElementById 查不到父文档里的弹层 iframe。
                        var $form = (typeof layer.getChildFrame === 'function')
                            ? layer.getChildFrame('form', index)
                            : $();
                        if (!$form || !$form.length) {
                            layer.toast({content: '未找到配置表单，请刷新后重试', type: 'danger'});
                            return false;
                        }

                        var formData = $form.serialize();
                        $.ajax({
                            url: '/admin/plugin/config',
                            type: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(res) {
                                if (res.code == 0) {
                                    layer.toast({content: '配置保存成功', type: 'success'});
                                    layer.close(index);
                                } else {
                                    layer.toast({content: res.msg || '保存失败', type: 'danger'});
                                }
                            },
                            error: function() {
                                layer.toast({content: '网络错误', type: 'danger'});
                            }
                        });
                        return false;
                    }
                });
            });
            
            //***************  列表相关  **************/

            // 列表加载完成后，按本次响应重建分类选项卡（与「全部」并列）。
            //
            // 支持两种模式共用同一套分类 tab：
            //   - 「全部」模式：分类来自服务器端远程插件市场的 category（数字 catid）
            //   - 「本地插件」模式：分类来自本地插件 manifest 的 category 字段（分类名）
            // 切换模式后响应返回不同的 category，这里整体重建并恢复当前激活分类；
            // 若当前分类在新模式下不存在（例如本地无该分类），自动回落到「全部」。
            table.on('load-success', function(data){
                if(!data || data.code !== OK_CODE) return;

                // 重建行索引：重装弹窗需要整行自检信息（含残留表清单）
                Object.keys(rowIndex).forEach((k) => delete rowIndex[k]);
                ((data.data && data.data.list) || []).forEach((row) => {
                    if (row && row.ident) rowIndex[row.ident] = row;
                });

                let category = (data?.data && data.data.category) || [];
                let catid = currentCatid;

                // 重建：保留首个「全部」tab，其余移除后按本次响应追加
                $('#category li:not(:first)').remove();
                if(category.length > 0){
                    $('#category').append(category.map(item =>
                        `<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" data-catid="${item.id}" href="#">${item.name}</a></li>`
                    ).join(''));
                }

                // 恢复激活态；当前分类在本次响应中不存在时回落到「全部」
                let $active = $('#category a[data-catid="' + catid + '"]');
                if($active.length === 0){
                    catid = 0;
                    $active = $('#category a[data-catid="0"]');
                }
                $('#category .nav-link').removeClass('active');
                $active.addClass('active');
                currentCatid = catid;
            });

            // 切换插件类型（全部 / 本地插件），保持当前分类
            $('input[name="soft_type"]').on('change', function(event) {
                event.preventDefault();
                table.replaceUrl('/admin/plugin/getList?catid=' + currentCatid + '&type=' + $(this).val());
            });

            // 切换插件分类（两种模式下共用）
            $('#category').on('click', 'a[data-bs-toggle="tab"]', function(event) {
                event.preventDefault();
                currentCatid = $(this).data('catid') || 0;
                table.replaceUrl('/admin/plugin/getList?catid=' + currentCatid + '&type=' + $('input[name="soft_type"]:checked').val());
            });

            // 本地安装插件
            const validExtensions = ['.zip'];
            $('#local_install_file').attr('accept', validExtensions.join(','));
            $('#local_install').on('click', function(event) {
                event.preventDefault();
                $('#local_install_file').val('');
                $('#local_install_file').click();
            });

            // 监听本地安装文件选择
            $('#local_install_file').on('change', function(event) {
                event.preventDefault();
                let file = $(this)[0].files[0];
                if(!file) {
                    return layer.top().toast({content:'请选择插件文件', type:'error'});
                }
                const fileName = file.name.toLowerCase();
                const isValid = validExtensions.some(ext => fileName.endsWith(ext));
                if(!isValid) {
                    return layer.top().toast({content:'请选择ZIP格式压缩包文件', type:'error'});
                }
                // 上传文件
                let formData = new FormData();
                formData.append('file', file);
                $.ajax({
                    url: '/admin/plugin/localInstall',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success(data) {
                        if(data.code === OK_CODE) {
                            layer.top().toast({content:'安装成功', type:'success'});
                            // 本地安装返回的 ident 用于定位并局部刷新该行；
                            // 取不到时回落到整表刷新（本地插件模式下列表本就很短）
                            const ident = (data.data && data.data.ident) || '';
                            afterLifecycleChange(ident, () => table.replaceUrl(table.url));
                        } else {
                            layer.top().toast({content:data.msg || '安装失败', type:'error'});
                        }
                    }
                });
            });

            /***** 会员登录相关 *****/
            // 登录表单验证并提交
            new FormValidator('loginForm', {
                liveValidation: true,
                showIcons: true,
                feedbackPlaceholder: true,
                onSubmit(form, e) {
                    var data = $(form).serialize();
                    $.ajax({
                        url: $(form).attr('action'),
                        type: 'post',
                        data: data,
                        dataType: 'json',
                        beforeSend() {
                            $('button[type="submit"]').prop('disabled', true).text('登录中...');
                        },
                        success(res) {
                            if (res.code == 0) {
                                $('[name="keeplogin"]').prop('checked') ? Cookie.set('keeplogin', '1', {days: 7}) : Cookie.delete('keeplogin');
                                layer.top().toast({content:res.msg, type:'success', time:2000, end(){
                                    if(window.self === window.top){
                                        window.location.href = res.data.url;
                                    } else {
                                        const referer = Wise.util.url.getParam('referer') || res.data.url;
                                        window.location.href = referer;
                                    }
                                }});
                            } else {
                                layer.top().toast({content:res.msg, type:'warning'});
                                // 判断是否需要显示验证码
                                if(res?.data?.needcaptcha){
                                    $('[name="captcha"]').attr('required', true);
                                    $('#needcaptcha').show();
                                }
                                // 刷新验证码
                                $('.captcha-img').click();
                                $('[name="captcha"]').val('');
                                $('button[type="submit"]').prop('disabled', false).text('登录');
                            }
                        },
                        error() {
                            layer.top().toast({content: '网络错误，请重试', type:'warning'});
                            $('button[type="submit"]').prop('disabled', false).text('登录');
                        }
                    });
                }
            });    
        },
    };

export default Controller;
