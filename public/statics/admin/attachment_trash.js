/**
 * attachment_trash.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Layer = window.Layer;

const Controller = {
        index() {
            var selectedItems = new Set();
            var currentPage = 1;

            function init() {
                initEvents();
                loadTrashList();
            }

            function initEvents() {
                $('#searchBtn').on('click', () => { loadTrashList(); });
                $('#keywordFilter').on('keypress', (e) => { if (e.key === 'Enter') loadTrashList(); });

                // 批量恢复
                $('#batchRestoreBtn').on('click', batchRestore);
                // 批量永久删除
                $('#batchForceDeleteBtn').on('click', batchForceDelete);
                // 清空回收站
                $('#emptyTrashBtn').on('click', emptyTrash);
                // 取消选择
                $('#clearSelectionBtn').on('click', clearSelection);

                // 卡片点击选择
                $('#trashList').on('click', '.attachment-card', function(e) {
                    if ($(e.target).closest('button, a').length) return;
                    toggleSelect($(this).data('id'));
                });
            }

            function loadTrashList(keyword) {
                keyword = keyword || $('#keywordFilter').val();
                $.get('/admin/attachment/getTrashList', { page: currentPage, keyword: keyword }, (r) => {
                    if (r.code === 0) {
                        renderList(r.data);
                        renderPagination(r.data);
                        $('#trashCount').text('共 ' + r.data.total + ' 个已删除文件');
                    } else {
                        Layer.toast({content: '加载失败: ' + r.msg, type: 'danger'});
                    }
                });
            }

            function renderList(data) {
                var $container = $('#trashList');
                if (!data.list || data.list.length === 0) {
                    $container.html('<div class="col-12 text-center py-5"><i class="bi bi-trash display-1 text-muted"></i><p class="text-muted mt-3">回收站为空</p></div>');
                    return;
                }

                var html = '';
                data.list.forEach(function(item) {
                    var isSelected = selectedItems.has(item.id);
                    var thumbHtml = '';
                    if (item.is_image) {
                        thumbHtml = '<div class="attachment-thumb"><img src="' + item.url + '" alt="" loading="lazy"></div>';
                    } else {
                        thumbHtml = '<div class="attachment-thumb"><i class="' + item.icon_class + ' file-icon"></i></div>';
                    }

                    html += '<div class="col-md-4 col-lg-3 col-xl-2 mb-3">' +
                        '<div class="card attachment-card position-relative h-100 ' + (isSelected ? 'selected' : '') + '" data-id="' + item.id + '">' +
                        '<span class="badge bg-secondary badge-deleted">已删除</span>' +
                        thumbHtml +
                        '<div class="card-body p-2">' +
                        '<p class="card-text small text-truncate mb-1" title="' + item.name + '">' + item.name + '</p>' +
                        '<small class="text-muted">' + item.human_size + '</small>' +
                        '<small class="text-muted d-block">' + (item.delete_time || '') + '</small>' +
                        '</div>' +
                        '<div class="card-footer p-1">' +
                        '<div class="btn-group w-100 btn-group-sm">' +
                        '<button class="btn btn-outline-success restore-btn" data-id="' + item.id + '" title="恢复"><i class="bi bi-arrow-counterclockwise"></i></button>' +
                        '<button class="btn btn-outline-danger force-delete-btn" data-id="' + item.id + '" title="永久删除"><i class="bi bi-x-octagon"></i></button>' +
                        '</div>' +
                        '</div>' +
                        '</div></div>';
                });
                $container.html(html);
            }

            function renderPagination(data) {
                var $nav = $('#paginationContainer');
                if (data.total_page <= 1) { $nav.empty(); return; }
                var html = '<ul class="pagination pagination-sm justify-content-center">';
                for (var i = 1; i <= data.total_page; i++) {
                    html += '<li class="page-item ' + (i === currentPage ? 'active' : '') + '"><a class="page-link page-link-btn" href="#" data-page="' + i + '">' + i + '</a></li>';
                }
                html += '</ul>';
                $nav.html(html);
                $('.page-link-btn').on('click', function(e) {
                    e.preventDefault();
                    currentPage = parseInt($(this).data('page'));
                    loadTrashList();
                });
            }

            function toggleSelect(id) {
                if (selectedItems.has(id)) {
                    selectedItems.delete(id);
                } else {
                    selectedItems.add(id);
                }
                updateSelectionUI();
            }

            function clearSelection() {
                selectedItems.clear();
                updateSelectionUI();
            }

            function updateSelectionUI() {
                $('.attachment-card').each(function() {
                    var id = $(this).data('id');
                    $(this).toggleClass('selected', selectedItems.has(id));
                });
                var count = selectedItems.size;
                $('#selectedCount').text('已选择 ' + count + ' 个文件');
                $('#batchToolbar').toggle(count > 0);
                $('#batchRestoreBtn').prop('disabled', count === 0);
                $('#batchForceDeleteBtn').prop('disabled', count === 0);
            }

            // 单个恢复
            $('#trashList').on('click', '.restore-btn', function(e) {
                e.stopPropagation();
                var id = $(this).data('id');
                restoreItems([id]);
            });

            // 单个永久删除
            $('#trashList').on('click', '.force-delete-btn', function(e) {
                e.stopPropagation();
                var id = $(this).data('id');
                forceDeleteItems([id]);
            });

            function batchRestore() {
                if (selectedItems.size === 0) return;
                restoreItems(Array.from(selectedItems));
            }

            function batchForceDelete() {
                if (selectedItems.size === 0) return;
                forceDeleteItems(Array.from(selectedItems));
            }

            function restoreItems(ids) {
                Layer.confirm('确定要恢复 ' + ids.length + ' 个文件吗？', function(idx) {
                    $.post('/admin/attachment/restore', { ids: ids.join(',') }, (r) => {
                        if (r.code === 0) {
                            Layer.toast({content: r.msg, type: 'success'});
                            clearSelection();
                            loadTrashList();
                        } else {
                            Layer.toast({content: r.msg, type: 'danger'});
                        }
                    });
                    Layer.close(idx);
                });
            }

            function forceDeleteItems(ids) {
                Layer.confirm('此操作将<b>永久删除</b> ' + ids.length + ' 个文件，<b>不可恢复</b>！确定继续吗？', function(idx) {
                    $.post('/admin/attachment/forceDelete', { ids: ids.join(',') }, (r) => {
                        if (r.code === 0) {
                            Layer.toast({content: r.msg, type: 'success'});
                            clearSelection();
                            loadTrashList();
                        } else {
                            Layer.toast({content: r.msg, type: 'danger'});
                        }
                    });
                    Layer.close(idx);
                }, {icon: 3, title: '警告'});
            }

            function emptyTrash() {
                Layer.confirm('确定要<b>清空回收站</b>吗？所有文件将<b>永久删除且不可恢复</b>！', function(idx) {
                    $.post('/admin/attachment/emptyTrash', {}, (r) => {
                        if (r.code === 0) {
                            Layer.toast({content: r.msg, type: 'success'});
                            clearSelection();
                            loadTrashList();
                        } else {
                            Layer.toast({content: r.msg, type: 'danger'});
                        }
                    });
                    Layer.close(idx);
                }, {icon: 3, title: '警告'});
            }

            init();
        }
    };

export default Controller;
