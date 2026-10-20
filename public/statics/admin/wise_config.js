/**
 * wise_config.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const layer = window.Layer;

'use strict';

    var module  = window.__WS_CONFIG__ || {};
    var baseUrl = '/' + module.modulename + '/' + module.controller;

    // ==================== 全局状态 ====================
    var currentNamespace = $('.wise-ns-tab.active').data('ns') || 'default';
    var tagList = [];
    var collapsedGroups = {};   // 从 localStorage 恢复
    var editingRow = null;
    var savingRow = null;  // 正在保存的行，防止重复提交      // 当前正在行内编辑的行
    var currentFilter = '';     // 当前状态筛选: ''=全部, '1'=启用, '0'=禁用
    var uploading = false;      // 图片上传中

    try { collapsedGroups = JSON.parse(localStorage.getItem('wise_config_collapsed') || '{}'); } catch(e) {}

    // ==================== 工具函数 ====================
    function toast(msg, type) {
        layer.toast({ content: msg, type: type || 'success' });
    }

    function post(url, data, cb) {
        Wise.util.http.post(url, data, { interceptor: false }).then(function (res) {
            if (res.code === 0) cb(res); else toast(res.msg || '操作失败', 'danger');
        }).catch(() => {});
    }

    function get(url, params, cb) {
        $.ajax({ url: url, data: params || {}, dataType: 'json', success(res) {
            if (res.code === 0) cb(res); else toast(res.msg || '请求失败', 'danger');
        }});
    }

    function htmlEncode(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function truncate(s, n) {
        s = String(s || '');
        return s.length > n ? s.substring(0, n) + '...' : s;
    }

    // ==================== 命名空间 Tab ====================
    function switchNamespace(ns) {
        currentNamespace = ns;
        $('.wise-ns-tab').removeClass('active');
        $('.wise-ns-tab[data-ns="' + ns + '"]').addClass('active');
        loadNamespaceGroups();
    }

    // 推断命名空间继承箭头显示
    function renderInheritArrows(namespaces) {
        var nsMap = {};
        namespaces.forEach(function (ns) {
            var parts = ns.name.split('.');
            if (parts.length > 1) {
                var parent = parts.slice(0, -1).join('.');
                nsMap[ns.name] = parent;
            }
        });

        $('.wise-ns-tab').each(function () {
            var ns = $(this).data('ns');
            // 移出现有箭头
            $(this).find('.wise-inherit-arrow').remove();
            if (nsMap[ns]) {
                $(this).append('<span class="wise-inherit-arrow" title="继承自 ' + nsMap[ns] + '">←</span>');
            }
        });
    }

    function loadNamespaces() {
        get(baseUrl + '/listNamespaces', {}, function (res) {
            if (res.data && res.data.length) {
                renderInheritArrows(res.data);
            }
        });
    }

    // ==================== 分组卡片 ====================
    function loadNamespaceGroups() {
        get(baseUrl + '/getNamespaceGroups', { namespace: currentNamespace }, function (res) {
            var groups = res.data || [];
            if (groups.length === 0) {
                $('#groupsArea').html(
                    '<div class="wise-empty">' +
                    '<i class="bi bi-folder2-open"></i>' +
                    '<p>当前命名空间没有配置分组</p>' +
                    '<button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnAddGroupEmpty">' +
                    '<i class="bi bi-folder-plus me-1"></i> 创建第一个分组</button>' +
                    '</div>'
                );
                return;
            }
            // 为每个分组加载配置
            var html = '';
            groups.forEach(function (group, idx) {
                html += buildGroupCard(group);
            });
            $('#groupsArea').html(html);
            // 并行加载各分组配置
            groups.forEach(function (group) {
                loadGroupConfigs(group);
            });
        });
    }

    function buildGroupCard(group) {
        var collapsed = collapsedGroups[getGroupKey(group)] ? ' collapsed' : '';
        return '<div class="wise-group-card' + collapsed + '" data-group="' + htmlEncode(group) + '">' +
            '<div class="wise-group-header" data-group="' + htmlEncode(group) + '">' +
                '<div class="wise-group-header-left">' +
                    '<span class="wise-group-icon"><i class="bi bi-collection"></i></span>' +
                    '<span class="wise-group-name">' + htmlEncode(group) + '</span>' +
                    '<span class="wise-group-badge wise-group-count" data-group="' + htmlEncode(group) + '">...</span>' +
                '</div>' +
                '<div style="display:flex;align-items:center">' +
                    '<button class="wise-group-delete" data-group="' + htmlEncode(group) + '" title="删除整个分组"><i class="bi bi-x-lg"></i></button>' +
                    '<div class="wise-group-toggle"><i class="bi bi-chevron-down"></i></div>' +
                '</div>' +
            '</div>' +
            '<div class="wise-group-body" id="groupBody_' + htmlEncode(group).replace(/[^a-zA-Z0-9\u4e00-\u9fa5]/g, '_') + '">' +
                '<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary"></span></div>' +
            '</div>' +
            '</div>';
    }

    function getGroupKey(group) {
        return currentNamespace + ':' + group;
    }

    function loadGroupConfigs(group) {
        get(baseUrl + '/getGroupConfigs', { namespace: currentNamespace, group: group }, function (res) {
            var configs = res.data || [];
            var bodyId = 'groupBody_' + group.replace(/[^a-zA-Z0-9\u4e00-\u9fa5]/g, '_');
            var $body = $('#' + bodyId);
            if (!$body.length) return;

            // 应用状态筛选
            var filtered = configs;
            if (currentFilter !== '') {
                filtered = configs.filter(function (c) {
                    return String(c.status) === currentFilter;
                });
            }

            // 更新分组配置数 badge（显示筛选结果/总数）
            var $card = $body.closest('.wise-group-card');
            $card.find('.wise-group-count').text(
                currentFilter !== '' ? filtered.length + '/' + configs.length : configs.length
            );

            if (filtered.length === 0) {
                $body.html('<div class="text-center py-2 text-secondary small">' +
                    (currentFilter !== '' ? '当前筛选下无配置项' : '暂无配置项') + '</div>');
                return;
            }

            var html = '';
            filtered.forEach(function (c) {
                html += buildConfigRow(c);
            });
            $body.html(html);
        });
    }

    function buildConfigRow(c) {
        var valueDisplay = c.value;
        var emptyClass = '';
        var disabledClass = c.status == 0 ? ' value-disabled' : '';
        if (valueDisplay === null || valueDisplay === undefined || valueDisplay === '') {
            valueDisplay = '未设置';
            emptyClass = ' value-empty';
        } else {
            valueDisplay = htmlEncode(truncate(String(valueDisplay), 80));
        }

        var typeDisplay = { text:'T', number:'N', html:'H', json:'J', image:'I' }[c.type] || c.type;
        var inheritIcon = c.inherit == 1 ? '<button class="btn-inherit" title="继承来源" data-id="' + c.id + '"><i class="bi bi-diagram-3"></i></button>' : '';
        var statusBadge = c.status == 0 ? '<span class="badge bg-secondary ms-1" style="font-size:0.6rem">禁用</span>' : '';

        return '<div class="wise-config-row" data-id="' + c.id + '" data-key="' + htmlEncode(c.key) + '" data-type="' + htmlEncode(c.type) + '" data-value="' + htmlEncode(String(c.value || '')) + '">' +
            '<div class="wise-config-label">' +
                '<span class="title" title="' + htmlEncode(c.title || c.key) + '">' + htmlEncode(c.title || c.key) + statusBadge + '</span>' +
                '<span class="key-info">' + htmlEncode(c.namespace) + ':' + htmlEncode(c.group) + ':' + htmlEncode(c.key) + '</span>' +
            '</div>' +
            '<div class="wise-config-value">' +
                '<span class="type-badge">' + typeDisplay + '</span>' +
                '<span class="value-display' + emptyClass + disabledClass + '" data-id="' + c.id + '">' + valueDisplay + '</span>' +
            '</div>' +
            '<div class="wise-config-actions">' +
                '<button class="btn-copy" title="复制键名" data-key="' + htmlEncode(c.namespace + ':' + c.group + ':' + c.key) + '"><i class="bi bi-clipboard"></i></button>' +
                '<button class="btn-edit" title="编辑" data-id="' + c.id + '" data-type="' + htmlEncode(c.type) + '"><i class="bi bi-pencil"></i></button>' +
                '<button class="btn-history" title="变更历史" data-id="' + c.id + '" data-key="' + htmlEncode(c.key) + '"><i class="bi bi-clock-history"></i></button>' +
                inheritIcon +
                '<button class="btn-delete" title="删除" data-id="' + c.id + '" data-key="' + htmlEncode(c.key) + '"><i class="bi bi-trash"></i></button>' +
            '</div>' +
            '</div>';
    }

    // ==================== 行内编辑 ====================
    function startInlineEdit(rowEl) {
        if (editingRow) cancelInlineEdit();
        var $row = $(rowEl);
        var type = $row.data('type');
        var value = $row.attr('data-value');

        // text/number 类型支持行内编辑，复杂类型弹出模态框
        if (type === 'text' || type === 'number') {
            editingRow = $row;
            var $display = $row.find('.value-display');
            var displayText = $display.text();
            if (displayText === '未设置') displayText = '';

            var inputHtml;
            if (displayText.length > 40 || displayText.indexOf('\n') > -1) {
                inputHtml = '<textarea class="inline-edit" data-id="' + $row.data('id') + '">' + htmlEncode(displayText) + '</textarea>';
            } else {
                inputHtml = '<input type="' + (type === 'number' ? 'number' : 'text') +
                    '" class="inline-edit" data-id="' + $row.data('id') +
                    '" value="' + htmlEncode(displayText) + '">';
            }
            $display.replaceWith(inputHtml);
            var $input = $row.find('.inline-edit');
            // 延迟聚焦，确保 DOM 替换完成后再获取焦点，避免浏览器提前触发 blur
            setTimeout(function () {
                $input.focus().select();
            }, 0);

            // 失焦保存
            $input.on('blur', function () {
                // 防重入：如果该行正在保存中，忽略后续 blur 事件
                var $thisRow = $row;
                if (savingRow && savingRow.is($thisRow)) return;
                var newVal = $(this).val();
                var cfgId = $(this).data('id');
                // 比较新旧值，未修改则直接退出编辑，不提交
                var oldVal = $thisRow.attr('data-value') || '';
                if (String(newVal) === String(oldVal)) {
                    cancelInlineEdit();
                    return;
                }
                saveQuickEdit(cfgId, newVal, $thisRow);
            });

            // 回车保存
            $input.on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    $(this).blur();
                }
                if (e.key === 'Escape') {
                    cancelInlineEdit();
                }
            });
        } else {
            // 复杂类型：弹出模态框
            openConfigModal($row.data('id'));
        }
    }

    function cancelInlineEdit() {
        if (!editingRow) return;
        var oldValue = editingRow.attr('data-value');
        var display = oldValue || '未设置';
        var emptyClass = oldValue ? '' : ' value-empty';
        var disabledClass = editingRow.find('.value-disabled').length ? ' value-disabled' : '';
        editingRow.find('.inline-edit').replaceWith(
            '<span class="value-display' + emptyClass + disabledClass + '">' + htmlEncode(truncate(display, 80)) + '</span>'
        );
        editingRow = null;
    }

    function saveQuickEdit(id, value, $row) {
        // 防重入：如果已有保存任务在执行，忽略本次调用
        if (savingRow) return;
        // 标记该行为正在保存，防止 blur 和 document.click 事件重复触发
        savingRow = $row;
        post(baseUrl + '/quickEdit', { id: id, value: value }, function (res) {
            toast('已更新');
            $row.attr('data-value', String(value));
            cancelInlineEdit();
            // 重建这一行
            var $body = $row.closest('.wise-group-body');
            var group = $row.closest('.wise-group-card').data('group');
            if ($body.length && group) {
                loadGroupConfigs(group);
            }
            savingRow = null;  // 保存完成后清除标记
        });
    }

    // ==================== 配置模态框（新增/编辑复杂类型） ====================
    function openConfigModal(id) {
        if (id) {
            get(baseUrl + '/detail?id=' + id, {}, function (res) {
                populateConfigModal(res.data);
            });
        } else {
            $('#configForm')[0].reset();
            $('#cfgId').val('');
            $('#cfgNamespace').val(currentNamespace);
            $('#cfgGroup').val('system');
            $('#cfgType').val('text');
            $('#cfgSort').val('0');
            $('#cfgInherit').prop('checked', true);
            $('#cfgStatus').prop('checked', true);
            $('#cfgVisibility').prop('checked', true);
            $('#configModalLabel').text('新增配置项');
            tagList = [];
            renderTags();
            $('#configModal').modal('show');
        }
    }

    function populateConfigModal(d) {
        $('#cfgId').val(d.id);
        $('#cfgNamespace').val(d.namespace || 'default');
        $('#cfgGroup').val(d.group || 'system');
        $('#cfgKey').val(d.key);
        $('#cfgTitle').val(d.title || '');
        $('#cfgType').val(d.type || 'text');
        $('#cfgSort').val(d.sort || 0);
        $('#cfgValue').val(d.value || '');
        $('#cfgDescription').val(d.description || '');
        $('#cfgInherit').prop('checked', d.inherit == 1);
        $('#cfgStatus').prop('checked', d.status == 1);
        // visibility: 1=公开 0=私有；兼容无该字段的历史数据，默认公开
        $('#cfgVisibility').prop('checked', (d.visibility === undefined ? 1 : d.visibility) == 1);
        $('#configModalLabel').text('编辑配置项');
        tagList = d.tags_array || [];
        renderTags();
        $('#configModal').modal('show');
    }

    function renderTags() {
        var $editor = $('#tagEditor');
        $editor.find('.wise-tag').remove();
        tagList.forEach(function (t) {
            var $chip = $('<span class="wise-tag">' + htmlEncode(t) + '<span class="wise-tag-remove">&times;</span></span>');
            $chip.find('.wise-tag-remove').click(function (e) {
                e.stopPropagation();
                var idx = tagList.indexOf(t);
                if (idx > -1) tagList.splice(idx, 1);
                $chip.remove();
                $('#cfgTags').val(JSON.stringify(tagList));
            });
            $('#tagInput').before($chip);
        });
        $('#cfgTags').val(JSON.stringify(tagList));
    }

    // ==================== 历史抽屉 ====================
    function openHistoryDrawer(configId, configKey) {
        $('#drawerTitle').text('变更历史 — ' + htmlEncode(configKey));
        $('#drawerBody').html('<div class="text-center py-5"><span class="spinner-border spinner-border-sm text-secondary"></span></div>');
        $('#drawerFooter').addClass('d-none');
        $('#drawerFooter').data('selected', null);
        openDrawer();

        get(baseUrl + '/history', { config_id: configId, limit: 50 }, function (res) {
            var items = res.data.list || [];
            if (items.length === 0) {
                $('#drawerBody').html('<div class="text-center py-5 text-secondary small"><i class="bi bi-clock d-block mb-2" style="font-size:1.5rem"></i>暂无变更记录</div>');
                return;
            }
            var html = '';
            items.forEach(function (item, idx) {
                var selected = idx === 0 ? ' selected' : '';
                html += '<div class="history-item' + selected + '" data-history-id="' + item.id + '">';
                html += '<div class="d-flex justify-content-between align-items-start">';
                html += '<div><span class="time"><i class="bi bi-calendar3 me-1"></i>' + htmlEncode(item.create_time || '') + '</span></div>';
                html += '<button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 btn-revert-single" data-history-id="' + item.id + '" title="回滚"><i class="bi bi-arrow-counterclockwise"></i></button>';
                html += '</div>';
                if (item.old_value !== null && item.old_value !== undefined) {
                    html += '<div class="val">旧: <span>' + htmlEncode(truncate(String(item.old_value), 60)) + '</span></div>';
                }
                html += '<div class="val">新: <strong>' + htmlEncode(truncate(String(item.new_value || ''), 60)) + '</strong></div>';
                html += '</div>';
            });
            $('#drawerBody').html(html);
            $('#drawerFooter').removeClass('d-none');

            // 默认选中最新一条
            $('#drawerFooter').data('selected', items[0].id);
            $('.history-item').first().addClass('bg-primary bg-opacity-10');
        });
    }

    function openDrawer() {
        $('#historyDrawer').addClass('show');
        $('#drawerOverlay').addClass('show');
    }

    function closeDrawer() {
        $('#historyDrawer').removeClass('show');
        $('#drawerOverlay').removeClass('show');
    }

    // ==================== 全局搜索 ====================
    var searchTimer = null;
    $('#globalSearch').on('input', function () {
        clearTimeout(searchTimer);
        var keyword = $(this).val().trim();
        if (keyword.length < 1) {
            $('#searchDropdown').removeClass('show');
            return;
        }
        searchTimer = setTimeout(function () {
            get(baseUrl + '/search', { keyword: keyword }, function (res) {
                var items = res.data || [];
                if (items.length === 0) {
                    $('#searchDropdown').html('<div class="text-center py-3 text-secondary small">未找到相关配置</div>').addClass('show');
                    return;
                }
                var html = '';
                items.forEach(function (item) {
                    html += '<div class="wise-search-result-item" data-ns="' + htmlEncode(item.namespace) + '" data-group="' + htmlEncode(item.group) + '" data-key="' + htmlEncode(item.key) + '">';
                    html += '<span><strong>' + htmlEncode(item.key) + '</strong><br><small>' + htmlEncode(truncate(String(item.value || ''), 30)) + '</small></span>';
                    html += '<span class="meta">' + htmlEncode(item.namespace) + ' / ' + htmlEncode(item.group) + '</span>';
                    html += '</div>';
                });
                $('#searchDropdown').html(html).addClass('show');
            });
        }, 300);
    });

    // ==================== 事件绑定 ====================

    // 命名空间 Tab 切换
    $(document).on('click', '.wise-ns-tab', function () {
        switchNamespace($(this).data('ns'));
    });

    // 新建命名空间
    $('#btnAddNamespace').click(function () {
        layer.prompt({ title: '新建命名空间', placeholder: '如: project_b' }, function (ns) {
            ns = (ns || '').trim();
            if (!ns) { toast('命名空间名不能为空', 'warning'); return; }
            // 检查是否已存在
            if ($('.wise-ns-tab[data-ns="' + ns + '"]').length) {
                toast('该命名空间已存在', 'warning');
                return;
            }
            // 创建一条占位配置来建立命名空间
            post(baseUrl + '/save', {
                namespace: ns, group: 'system',
                key: '_namespace_init_' + Date.now(),
                value: '', type: 'text', title: '命名空间初始化标记',
                status: 1
            }, function () {
                toast('命名空间已创建');
                location.reload();
            });
        });
    });

    // 分组卡片折叠/展开
    $(document).on('click', '.wise-group-header', function () {
        var group = $(this).data('group');
        var $card = $(this).closest('.wise-group-card');
        $card.toggleClass('collapsed');
        var key = getGroupKey(group);
        collapsedGroups[key] = $card.hasClass('collapsed');
        localStorage.setItem('wise_config_collapsed', JSON.stringify(collapsedGroups));
    });

    // 分组名双击编辑
    $(document).on('dblclick', '.wise-group-name', function (e) {
        e.stopPropagation();
        var $span = $(this);
        var oldName = $span.text();
        var $input = $('<input type="text" class="wise-group-name-edit" value="' + htmlEncode(oldName) + '">');
        $span.replaceWith($input);
        $input.focus().select();

        $input.on('blur keydown', function (ev) {
            if (ev.type === 'keydown' && ev.key === 'Escape') {
                $input.replaceWith('<span class="wise-group-name">' + htmlEncode(oldName) + '</span>');
                return;
            }
            if (ev.type === 'blur' || (ev.type === 'keydown' && ev.key === 'Enter')) {
                var newName = $(this).val().trim();
                if (!newName || newName === oldName) {
                    $input.replaceWith('<span class="wise-group-name">' + htmlEncode(oldName) + '</span>');
                    return;
                }
                post(baseUrl + '/renameGroup', {
                    namespace: currentNamespace,
                    old_group: oldName,
                    new_group: newName
                }, function (res) {
                    toast(res.msg || '已重命名');
                    loadNamespaceGroups();
                });
            }
        });
    });

    // 行内编辑（单击 value 区域）
    $(document).on('mousedown', '.value-display:not(.value-disabled)', function (e) {
        // 使用 mousedown 替代 click：mousedown 在 blur 之前触发，
        // 避免 replaceWith 后 input 因 DOM 变化被浏览器提前失焦
        var $row = $(this).closest('.wise-config-row');
        if ($row.length) {
            e.preventDefault();  // 阻止默认行为，防止触发不必要的焦点转移
            startInlineEdit($row[0]);
        }
    });

    // 复制键名 —— 走框架的 Clipboard 能力（Wise.ui.clipboard）：
    //   1. 优先 window.Clipboard（框架已引入的 Clipboard 组件，含兼容处理）；
    //   2. 其次 navigator.clipboard.writeText；
    //   3. 最后 textarea + execCommand 兜底。
    // 直接用 navigator.clipboard.writeText 的问题：http 等非安全上下文或旧浏览器下
    // navigator.clipboard 可能整体不存在或没有 writeText，会抛「无 writeText 方法」
    // 且没有任何兜底，表现为点「复制」没反应。
    $(document).on('click', '.btn-copy', function (e) {
        e.stopPropagation();
        var key = String($(this).data('key') || '');
        var clipboard = window.Wise && Wise.ui && Wise.ui.clipboard;

        var task = (clipboard && typeof clipboard.copy === 'function')
            ? clipboard.copy(key)
            : Promise.resolve(false);

        task.then(function (ok) {
            if (ok === false) {
                toast('复制失败，请手动选择文本复制', 'danger');
                return;
            }
            toast('已复制: ' + key);
        }).catch(function () {
            toast('复制失败，请手动选择文本复制', 'danger');
        });
    });

    // 编辑按钮
    $(document).on('click', '.btn-edit', function (e) {
        e.stopPropagation();
        openConfigModal($(this).data('id'));
    });

    // 历史按钮
    $(document).on('click', '.btn-history', function (e) {
        e.stopPropagation();
        openHistoryDrawer($(this).data('id'), $(this).data('key'));
    });

    // 继承按钮
    $(document).on('click', '.btn-inherit', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        get(baseUrl + '/inheritStatus', { id: id }, function (res) {
            var d = res.data;
            var badge = d.is_inherited
                ? '<span class="wise-inherit-badge inherited"><i class="bi bi-arrow-down"></i> 继承自: ' + (d.source_ns || '上级') + '</span>'
                : '<span class="wise-inherit-badge override"><i class="bi bi-pencil"></i> 已覆盖上级默认值</span>';
            layer.alert(
                '<div class="text-center">' + badge + '</div>' +
                '<p class="small text-secondary mt-2 mb-0">值: <code>' + htmlEncode(truncate(String(d.config.value || ''), 60)) + '</code></p>' +
                (d.is_inherited ? '<button type="button" class="btn btn-sm btn-outline-warning mt-2 btn-reset-inherit-alert" data-id="' + id + '"><i class="bi bi-arrow-repeat me-1"></i>重置覆盖，恢复继承</button>' : '')
            );
        });
    });

    $(document).on('click', '.btn-reset-inherit-alert', function () {
        var id = $(this).data('id');
        post(baseUrl + '/resetInherit', { id: id }, function () {
            toast('已恢复继承');
            layer.closeAll();
            loadNamespaceGroups();
        });
    });

    // 删除按钮
    $(document).on('click', '.btn-delete', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        var key = $(this).data('key');
        layer.confirm('确定删除配置 <strong>' + htmlEncode(key) + '</strong> 吗？此操作不可恢复！', 
        { title: '删除配置', icon: 'warning' },
        function (index) {
            post(baseUrl + '/delete', { id: id }, function () {
                toast('删除成功');
                loadNamespaceGroups();
                layer.close(index);
            });
        });
    });

    // 关闭抽屉
    $('#btnCloseDrawer, #drawerOverlay').click(closeDrawer);

    // 抽屉内回滚
    $('#btnRevertDrawer').click(function () {
        var historyId = $(this).data('selected');
        if (!historyId) { toast('请先选中一个版本', 'warning'); return; }
        layer.confirm('确定回滚到该版本吗？当前值将被覆盖！', { title: '回滚配置', icon: 'warning' }, function (index) {
            post(baseUrl + '/historyRevert', { id: historyId }, function () {
                toast('回滚成功');
                closeDrawer();
                loadNamespaceGroups();
                layer.close(index);
            });
        });
    });

    // 抽屉内历史条目点击选中
    $(document).on('click', '.history-item', function () {
        $('.history-item').removeClass('bg-primary bg-opacity-10');
        $(this).addClass('bg-primary bg-opacity-10');
        $('#drawerFooter').data('selected', $(this).data('history-id'));
    });

    // 抽屉内单条回滚
    $(document).on('click', '.btn-revert-single', function (e) {
        e.stopPropagation();
        var historyId = $(this).data('history-id');
        layer.confirm('回滚到此版本？', { title: '回滚配置', icon: 'warning' }, function (index) {
            post(baseUrl + '/historyRevert', { id: historyId }, function () {
                toast('回滚成功');
                closeDrawer();
                loadNamespaceGroups();
                layer.close(index);
            });
        });
    });

    // 搜索下拉点击
    $(document).on('click', '.wise-search-result-item', function () {
        var ns = $(this).data('ns');
        var group = $(this).data('group');
        var key = $(this).data('key');
        $('#searchDropdown').removeClass('show');
        $('#globalSearch').val('');

        // 切换到目标命名空间
        if (currentNamespace !== ns) {
            switchNamespace(ns);
            // 等分组加载完成后定位
            setTimeout(function () {
                highlightAndScrollTo(group, key);
            }, 500);
        } else {
            highlightAndScrollTo(group, key);
        }
    });

    function highlightAndScrollTo(group, key) {
        var $card = $('.wise-group-card[data-group="' + group + '"]');
        if ($card.hasClass('collapsed')) {
            $card.removeClass('collapsed');
            delete collapsedGroups[getGroupKey(group)];
            localStorage.setItem('wise_config_collapsed', JSON.stringify(collapsedGroups));
        }
        var $row = $card.find('.wise-config-row[data-key="' + key + '"]');
        if ($row.length) {
            $('html, body').animate({ scrollTop: $row.offset().top - 100 }, 400);
            $row.addClass('highlight');
            setTimeout(function () { $row.removeClass('highlight'); }, 2200);
        }
    }

    // 全局搜索点击外部关闭
    $(document).click(function (e) {
        if (!$(e.target).closest('.wise-search-wrap').length) {
            $('#searchDropdown').removeClass('show');
        }
    });

    // 点击 value 区域外部取消行内编辑
    $(document).click(function (e) {
        if (editingRow && !savingRow && !$(e.target).closest('.wise-config-row').length) {
            var newVal = editingRow.find('.inline-edit').val();
            var oldVal = editingRow.attr('data-value') || '';
            // 比较新旧值，未修改则直接退出编辑，不提交
            if (String(newVal) === String(oldVal)) {
                cancelInlineEdit();
                return;
            }
            saveQuickEdit(
                editingRow.data('id'),
                newVal,
                editingRow
            );
        }
    });

    // 配置表单提交
    $('#configForm').submit(function (e) {
        e.preventDefault();
        var data = {
            id: $('#cfgId').val(),
            namespace: $('#cfgNamespace').val() || 'default',
            group: $('#cfgGroup').val() || 'system',
            key: $('#cfgKey').val(),
            value: $('#cfgValue').val(),
            type: $('#cfgType').val(),
            title: $('#cfgTitle').val(),
            description: $('#cfgDescription').val(),
            sort: parseInt($('#cfgSort').val()) || 0,
            inherit: $('#cfgInherit').is(':checked') ? 1 : 0,
            status: $('#cfgStatus').is(':checked') ? 1 : 0,
            visibility: $('#cfgVisibility').is(':checked') ? 1 : 0,
            tags: tagList
        };
        if (!data.key) { toast('请填写键名', 'warning'); return; }

        post(baseUrl + '/save', data, function (res) {
            $('#configModal').modal('hide');
            toast(res.msg || '保存成功');
            loadNamespaceGroups();
            // 若私有配置临时展示区已打开，同步刷新
            if (!$('#privatePanel').hasClass('d-none')) {
                loadPrivateConfigs();
            }
        });
    });

    // 新增配置按钮
    $('#btnAddConfigMain').click(function () {
        openConfigModal(null);
    });

    $('#btnAddGroup').click(function () {
        $('#newGroupName').val('');
        $('#groupModal').modal('show');
    });
    $(document).on('click', '#btnAddGroupEmpty', function () {
        $('#newGroupName').val('');
        $('#groupModal').modal('show');
    });

    $('#groupForm').submit(function (e) {
        e.preventDefault();
        var name = $('#newGroupName').val().trim();
        if (!name) { toast('请填写分组名', 'warning'); return; }
        // 检查重复
        if ($('.wise-group-card[data-group="' + name + '"]').length) {
            toast('该分组已存在', 'warning');
            return;
        }
        // 通过创建一个临时配置来建立分组
        var tempKey = '_group_placeholder_' + Date.now();
        post(baseUrl + '/save', {
            namespace: currentNamespace,
            group: name,
            key: tempKey,
            value: '',
            type: 'text',
            title: '（占位项，可删除）',
            status: 0
        }, function () {
            $('#groupModal').modal('hide');
            toast('分组已创建');
            loadNamespaceGroups();
        });
    });

    // 标签编辑器
    $('#tagInput').keydown(function (e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            var val = $(this).val().trim().replace(/,/g, '');
            if (val && tagList.indexOf(val) === -1 && tagList.length < 10) {
                tagList.push(val);
                renderTags();
            }
            $(this).val('');
        }
    });
    $('#tagEditor').click(function () { $('#tagInput').focus(); });

    // 右上角菜单
    $('#menuRefreshCache').click(function (e) {
        e.preventDefault();
        post(baseUrl + '/refreshCache', {}, function (res) { toast(res.msg); });
    });
    $('#menuClearDictCache').click(function (e) {
        e.preventDefault();
        post(baseUrl + '/clearDictCache', {}, function (res) { toast(res.msg); });
    });
    $('#menuExportConfig').click(function (e) {
        e.preventDefault();
        window.open(baseUrl + '/export?namespace=' + encodeURIComponent(currentNamespace), '_blank');
    });
    $('#menuImportConfig').click(function (e) {
        e.preventDefault();
        $('#importNs').val(currentNamespace);
        $('#importTargetNs').text(currentNamespace);
        $('#importJsonText').val('');
        $('#importFileInput').val('');
        $('#importModal').modal('show');
    });

    // ==================== 私有（非公开）配置临时展示区 ====================
    // 仅用于调试、重置或删除私有配置，展示状态不持久化（刷新页面后消失）
    $('#menuShowPrivate').click(function (e) {
        e.preventDefault();
        var $panel = $('#privatePanel');
        if ($panel.hasClass('d-none')) {
            $panel.removeClass('d-none');
            loadPrivateConfigs();
        }
    });

    $('#btnClosePrivatePanel').click(function (e) {
        e.preventDefault();
        $('#privatePanel').addClass('d-none');
        $('#privatePanelBody').html('');
    });

    function loadPrivateConfigs() {
        var $body = $('#privatePanelBody');
        $body.html('<div class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary"></span></div>');
        get(baseUrl + '/getPrivateConfigs', {}, function (res) {
            var grouped = (res.data && res.data.grouped) || {};
            var nsKeys = Object.keys(grouped);
            if (nsKeys.length === 0) {
                $body.html('<div class="text-center py-4 text-secondary small"><i class="bi bi-check-circle d-block mb-1" style="font-size:1.4rem"></i>当前没有非公开配置项</div>');
                return;
            }
            var html = '';
            nsKeys.forEach(function (ns) {
                html += '<div class="wise-private-ns-title">' + htmlEncode(ns) + '</div>';
                Object.keys(grouped[ns]).forEach(function (group) {
                    html += '<div class="wise-private-group" data-group="' + htmlEncode(group) + '">' +
                        '<div class="wise-private-group-head"><span><i class="bi bi-folder2 me-1"></i>' + htmlEncode(group) + '</span><span class="badge text-bg-danger" style="font-size:0.62rem">' + grouped[ns][group].length + ' 项</span></div>';
                    grouped[ns][group].forEach(function (c) {
                        var val = (c.value === null || c.value === undefined || c.value === '') ? '（空）' : htmlEncode(truncate(String(c.value), 80));
                        html += '<div class="wise-private-row" data-id="' + c.id + '" data-key="' + htmlEncode(c.key) + '">' +
                            '<div class="p-title">' + htmlEncode(c.title || c.key) + '</div>' +
                            '<div>' +
                                '<div class="p-key">' + htmlEncode(c.key) + (c.inherit == 1 ? ' <span class="badge text-bg-secondary" style="font-size:0.58rem">可继承</span>' : '') + '</div>' +
                                '<div class="p-value">' + val + '</div>' +
                            '</div>' +
                            '<div class="p-actions">' +
                                '<button class="btn-p-edit" title="编辑" data-id="' + c.id + '"><i class="bi bi-pencil"></i></button>' +
                                '<button class="btn-p-reset" title="重置覆盖，恢复继承" data-id="' + c.id + '"><i class="bi bi-arrow-counterclockwise"></i></button>' +
                                '<button class="btn-p-delete" title="删除" data-id="' + c.id + '" data-key="' + htmlEncode(c.key) + '"><i class="bi bi-trash"></i></button>' +
                            '</div>' +
                            '</div>';
                    });
                    html += '</div>';
                });
            });
            $body.html(html);
        });
    }

    // 私有配置行操作（编辑/重置/删除），操作成功后刷新私有面板
    $(document).on('click', '.btn-p-edit', function (e) {
        e.stopPropagation();
        openConfigModal($(this).data('id'));
    });
    $(document).on('click', '.btn-p-reset', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        layer.confirm('确定重置该私有配置，恢复继承值吗？', { title: '重置配置', icon: 'warning' }, function (index) {
            post(baseUrl + '/resetInherit', { id: id }, function () {
                toast('已恢复继承');
                loadPrivateConfigs();
                layer.close(index);
            });
        });
    });
    $(document).on('click', '.btn-p-delete', function (e) {
        e.stopPropagation();
        var id = $(this).data('id');
        var key = $(this).data('key');
        layer.confirm('确定删除私有配置 <strong>' + htmlEncode(key) + '</strong> 吗？此操作不可恢复！', { title: '删除配置', icon: 'warning' }, function (index) {
            post(baseUrl + '/delete', { id: id }, function () {
                toast('删除成功');
                loadPrivateConfigs();
                loadNamespaceGroups();
                layer.close(index);
            });
        });
    });
    // 私有分组头点击折叠/展开
    $(document).on('click', '.wise-private-group-head', function () {
        $(this).parent().toggleClass('collapsed');
    });

    // ==================== 状态筛选 ====================
    $('#filterStatus').on('change', function () {
        currentFilter = $(this).val();
        loadNamespaceGroups();
    });

    // ==================== 分组删除 ====================
    $(document).on('click', '.wise-group-delete', function (e) {
        e.stopPropagation();
        var group = $(this).data('group');
        layer.confirm(
            '<div class="text-center">' +
            '<strong>确定删除分组「' + htmlEncode(group) + '」？</strong><br>' +
            '<small class="text-secondary">分组下所有配置将被永久删除，此操作不可恢复！</small></div>',
            {
                title: '确认删除',
                icon: 'warning',
            },
            function (index) {
                post(baseUrl + '/deleteGroup', {namespace: currentNamespace, group: group, force: 1}, function () {
                    toast('分组已删除');
                    loadNamespaceGroups();
                    layer.close(index);
                });
            }
        );
    });

    // ==================== 图片上传 ====================
    $('#cfgType').on('change', function () {
        var type = $(this).val();
        if (type === 'image') {
            $('#imageUploadArea').addClass('show');
            $('#cfgValue').attr('placeholder', '图片上传后自动填充路径...');
        } else {
            $('#imageUploadArea').removeClass('show');
            $('#cfgValue').attr('placeholder', '输入配置值...');
        }
    });

    $('#imageUploadArea, #imageFileInput').on('click', function (e) {
        if (uploading) return;
        e.stopPropagation();
        if (e.target === $('#imageFileInput')[0]) return;
        $('#imageFileInput').click();
    });

    $('#imageUploadArea').on('dragover dragenter', function (e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave dragend drop', function (e) {
        e.preventDefault();
        $(this).removeClass('dragover');
    });

    $('#imageUploadArea').on('drop', function (e) {
        var file = e.originalEvent.dataTransfer.files[0];
        if (file) uploadImageFile(file);
    });

    $('#imageFileInput').on('change', function () {
        var file = this.files[0];
        if (file) uploadImageFile(file);
    });

    function uploadImageFile(file) {
        if (uploading) return;
        if (!file.type.match(/^image\//)) {
            toast('请选择图片文件', 'warning');
            return;
        }
        var formData = new FormData();
        formData.append('file', file);
        uploading = true;
        $('#imageUploadArea').append('<div class="text-center py-2"><span class="spinner-border spinner-border-sm text-primary"></span> 上传中...</div>');

        $.ajax({
            url: baseUrl + '/uploadImage',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                uploading = false;
                $('#imageUploadArea').find('.spinner-border').parent().remove();
                if (res.code === 0) {
                    var url = res.data.url || res.data.path || '';
                    $('#cfgValue').val(url);
                    $('#imgPreview').attr('src', url);
                    $('#imageUploadArea').addClass('has-preview');
                    toast('上传成功');
                } else {
                    toast(res.msg || '上传失败', 'danger');
                }
            },
            error: function () {
                uploading = false;
                $('#imageUploadArea').find('.spinner-border').parent().remove();
                toast('上传失败', 'danger');
            }
        });
    }

    // ==================== 导入配置 ====================
    $('#importDropzone').on('click', function () {
        $('#importFileInput').click();
    }).on('dragover dragenter', function (e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave dragend', function (e) {
        e.preventDefault();
        $(this).removeClass('dragover');
    }).on('drop', function (e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        var file = e.originalEvent.dataTransfer.files[0];
        if (file) handleImportFile(file);
    });

    $('#importFileInput').on('change', function () {
        var file = this.files[0];
        if (file) handleImportFile(file);
    });

    function handleImportFile(file) {
        var reader = new FileReader();
        reader.onload = function (ev) {
            $('#importJsonText').val(ev.target.result);
        };
        reader.readAsText(file);
    }

    $('#importForm').on('submit', function (e) {
        e.preventDefault();
        var jsonText = $('#importJsonText').val().trim();
        if (!jsonText) { toast('请选择文件或粘贴 JSON 内容', 'warning'); return; }
        post(baseUrl + '/importConfig', {
            namespace: currentNamespace,
            json_text: jsonText
        }, function (res) {
            $('#importModal').modal('hide');
            toast(res.msg || '导入完成');
            loadNamespaceGroups();
        });
    });

    // ==================== 命名空间计数 ====================
    function loadNamespaceCounts() {
        get(baseUrl + '/namespaceConfigCounts', {}, function (res) {
            var counts = res.data || {};
            $('.wise-ns-tab').each(function () {
                var ns = $(this).data('ns');
                var count = counts[ns] || 0;
                // 移除旧 badge
                $(this).find('.wise-ns-count').remove();
                if (count > 0) {
                    $(this).append('<span class="wise-ns-count" style="font-size:0.65rem;background:var(--bs-secondary-bg);padding:0.1rem 0.35rem;border-radius:999px;margin-left:0.2rem;color:var(--bs-secondary-color)">' + count + '</span>');
                }
            });
        });
    }

    // ==================== 初始化 ====================
    loadNamespaces();
    loadNamespaceGroups();
    loadNamespaceCounts();

export default { index() {} };

