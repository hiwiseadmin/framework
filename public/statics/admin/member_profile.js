/**
 * member_profile.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const Layer = window.Layer;

const Controller = {
        index() {
            // 初始化表格
            const table = new Table({
                idTable: 'table',
                url: '/admin/member/getList',
                dataDataField: 'data.list',
                dataTotalField: 'data.total',
                extend: {
                    index_url: '/admin/member/getList',
                    preview_url: '/admin/member/preview',
                    add_url: '/admin/member/add',
                    edit_url: '/admin/member/edit',
                    del_url: '/admin/member/delete',
                    restore_url: '/admin/member/restore',
                    multi_url: '/admin/member/batch'
                },
                toolbarBtn:['add','preview','edit','del','refresh','import','export','multi'],
                columns: [{
                    field: 'state',
                    id: 'state',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle'
                },{
                    field: 'id',
                    title: 'ID',
                    width: '60',
                    sortable: true
                }, {
                    field: 'username',
                    title: '用户名',
                    formatter: 'normal'
                }, {
                    field: 'nickname',
                    title: '昵称',
                    formatter: 'normal'
                }, {
                    field: 'status',
                    title: '状态',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/admin/member/status'
                }, {
                    field: 'last_login_time',
                    title: '最后登录时间',
                    formatter: 'normal',
                }, {
                    field: 'operate',
                    buttons: ['preview','edit','del'],
                    title: '操作',
                    formatter: 'operate'
                }]
            });
        },
        preview(){

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
        },
        edit() {
            // 编辑用户表单验证
            new FormValidator('wsForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: true,
                onSubmit(form, e, data) {
                    // console.log(form, e, data);
                }
            });

            // 重置密码表单验证
            new FormValidator('resetPwdForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: false,
                feedbackPlaceholder: true,
                onSubmit(form, e, data) {
                    console.log(form, e, data);
                }
            });
        }
    };

export default Controller;
