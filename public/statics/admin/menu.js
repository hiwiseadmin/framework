/**
 * menu.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const layer = window.Layer;

const Controller = {
        index() {
            // 初始化表格
            const table = new Table({
                idTable: 'table',
                url: '/admin/menu/getList',
                showColumns: true,
                // sidePagination: 'client', // 改为客户端分页
                pagination: false,
                dataDataField: 'data.list', // 假设返回的数据字段为 menus
                treeColumn: 2,          // 树形网格的列索引，从 0 开始计数
                treeShowField: 'name', // 显示树状结构的字段，设置后将自动启用树形网格
                idField: 'id', // 树状结构的 ID 字段
                parentIdField: 'parent_id', // 树状结构的父节点字段
                extend: {
                    preview_url: '/admin/menu/preview',
                    add_url: '/admin/menu/add',
                    edit_url: '/admin/menu/edit',
                    del_url: '/admin/menu/delete',
                    multi_url: '/admin/menu/multi',
                    dragsort_url: '/admin/menu/dragsort'
                },
                toolbarBtn:['add','preview','edit','del','refresh','import','export',
                    {
                        id: 'syncMenu',
                        name: 'syncMenu',
                        title: '同步声明菜单',
                        icon: 'bi bi-cloud-arrow-down',
                        class: 'btn btn-sm btn-info mx-1',
                        click() {
                            Layer.confirm(
                                '将按扩展点声明（ExtPoints::MENU_ITEM）同步后台菜单：已存在的菜单默认保留后台改动，仅补建缺失项与恢复软删项。是否继续？',
                                {icon: 3, title: '同步声明菜单'},
                                function () {
                                    $.post('/admin/menu/sync', {}, function (res) {
                                        Layer.msg(res.msg, {icon: res.code === 0 ? 1 : 2, time: 2600});
                                        if (res.code === 0) {
                                            $('#table').bootstrapTable('refresh');
                                        }
                                    }, 'json');
                                }
                            );
                        }
                    }],
                columns: [{
                    field: 'ck',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle'
                },{
                    field: 'id',
                    title: 'ID',
                    width: '60',
                    sortable: true
                }, {
                    field: 'name',
                    title: '名称',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'icon',
                    title: '图标',
                    formatter: 'icon',
                    defaultIcon: 'bi bi-circle',
                    align: 'center',
                    width: '40'
                }, {
                    field: 'path',
                    title: '路径',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'component',
                    title: '组件',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'type',
                    title: '类型',
                    formatter: 'normal',
                    searchList: {
                        'dir': '目录',
                        'menu': '菜单',
                        'button': '按钮'
                    },
                    align: 'left',
                    width: '100'
                }, {
                    field: 'status',
                    title: '状态',
                    url: '/admin/menu/status',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    width: '100'
                }, {
                    field: 'sort',
                    title: '排序',
                    url: '/admin/menu/sort',
                    formatter: 'input',
                    align: 'center',
                    width: '80'
                }, {
                    field: 'operate',
                    buttons: ['dragsort',
                        {name:'add', disable(value, row, button) {return row.type == 'button'; }},
                        'preview','edit','del'],
                    title: '操作',
                    width: '200',
                    formatter: 'operate'
                }]
            });
            // 树状结构展开第一层
            $('#table').on('post-body.bs.table',function(){
                var $roots = $('tr[class^="treegrid-"]:not([class*="treegrid-parent-"])');
                if ($roots.length > 0 && $.fn.treegrid && $.fn.treegrid.defaults) {
                    $roots.treegrid('expand');
                }
            });
        },
        show(){

        },
        add() {
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {
                    // console.log(form, e, data);
                }
            });

            $('#icon').on('change', function () {
                const icon = $(this).val();
                $('#iconPreview').html(`<i class="${icon}"></i>`);
            });
        },
        edit() {
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {
                    // console.log(form, e, data);
                }
            });

            $('#icon').on('change', function () {
                const icon = $(this).val();
                $('#iconPreview').html(`<i class="${icon}"></i>`);
            });
        }
    };

export default Controller;
