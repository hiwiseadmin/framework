import api from '../common/api';
const $ = window.jQuery;

    'use strict';

    var $table, $modal, groupModal;

    export default {
        index() {
            $table = $('#data-table');
            $modal = $('#group-modal');
            groupModal = new bootstrap.Modal($modal[0]);

            window.queryParams = function(params) {
                params.keyword = $('#search-keyword').val();
                return params;
            };

            window.handleAdd = () => {
                $('#group-form')[0].reset();
                $('#group-form [name="id"]').val(0);
                groupModal.show();
            };

            window.submitGroup = () => {
                var data = $('#group-form').serializeArray().reduce((obj, item) => { obj[item.name] = item.value; return obj; }, {});
                api.post('/plugin/shortlink/group/save', data, {
                    success(res) {
                        if (res.code === 200) {
                            groupModal.hide();
                            $table.bootstrapTable('refresh');
                        } else { alert(res.msg); }
                    }
                });
            };

            window.operateFormatter = function(value, row) {
                return '<button class="btn btn-xs btn-outline-primary me-1 btn-edit" data-id="' + row.id + '">编辑</button>' +
                       '<button class="btn btn-xs btn-outline-danger btn-delete" data-id="' + row.id + '">删除</button>';
            };

            window.operateEvents = {
                'click .btn-edit'(e, value, row) {
                    $('#group-form [name="id"]').val(row.id);
                    $('#group-form [name="name"]').val(row.name);
                    $('#group-form [name="ident"]').val(row.ident);
                    $('#group-form [name="description"]').val(row.description);
                    $('#group-form [name="sort_order"]').val(row.sort_order);
                    $('#group-form [name="status"]').val(row.status);
                    groupModal.show();
                },
                'click .btn-delete'(e, value, row) {
                    if (confirm('确定删除吗？')) {
                        api.post('/plugin/shortlink/group/delete', {id: row.id}, {
                            success() { $table.bootstrapTable('refresh'); }
                        });
                    }
                }
            };

            window.handleSearch = () => $table.bootstrapTable('refresh');

            $table.bootstrapTable();
        }
    };
