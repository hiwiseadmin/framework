import api from '../common/api';
const $ = window.jQuery;

    'use strict';

    var $table;

    export default {
        index() {
            $table = $('#data-table');

            window.handleAdd = () => { window.location.href = '/plugin/shortlink/link/form'; };

            window.queryParams = function(params) {
                params.keyword = $('#search-keyword').val();
                params.group_id = $('#search-group-id').val();
                params.status = $('#search-status').val();
                return params;
            };

            window.urlFormatter = function(value) {
                if (!value) return '-';
                var display = value.length > 50 ? value.substring(0, 50) + '...' : value;
                return '<a href="' + value + '" target="_blank" title="' + value + '">' + display + '</a>';
            };

            window.shortUrlFormatter = function(value) {
                if (!value) return '-';
                var url = window.location.origin + value;
                return '<div class="input-group input-group-sm"><input type="text" class="form-control" value="' + url + '" readonly><button class="btn btn-outline-secondary copy-btn" data-url="' + url + '" title="复制"><i class="bi bi-clipboard"></i></button></div>';
            };

            window.operateFormatter = function(value, row) {
                return '<button class="btn btn-xs btn-outline-primary me-1 btn-edit" data-id="' + row.id + '">编辑</button>' +
                       '<button class="btn btn-xs btn-outline-danger btn-delete" data-id="' + row.id + '">删除</button>';
            };

            window.operateEvents = {
                'click .btn-edit'(e, value, row) {
                    window.location.href = '/plugin/shortlink/link/form?id=' + row.id;
                },
                'click .btn-delete'(e, value, row) {
                    if (confirm('确定删除吗？')) {
                        api.post('/plugin/shortlink/link/delete', {ids: [row.id]}, {
                            success() { $table.bootstrapTable('refresh'); }
                        });
                    }
                },
                'click .copy-btn'(e) {
                    var val = $(this).data('url');
                    navigator.clipboard.writeText(val).then(() => alert('已复制'));
                }
            };

            window.handleSearch = () => $table.bootstrapTable('refresh');
            window.handleReset = () => { $('#search-keyword,#search-group-id,#search-status').val(''); $table.bootstrapTable('refresh', {query: {}}); };

            api.get('/plugin/shortlink/group/getAll', {}, {
                success(res) { $.each(res, (i, g) => $('#search-group-id').append('<option value="' + g.id + '">' + g.name + '</option>')); }
            });
            $table.bootstrapTable();
        }
    };
