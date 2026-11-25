/**
 * member_group.js — ESM 模块（已从 AMD 迁移）
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
                url: '/admin/member_group/getList',
                extend: {
                    index_url: '/admin/member_group/getList',
                    preview_url: '/admin/member_group/preview',
                    add_url: '/admin/member_group/add',
                    edit_url: '/admin/member_group/edit',
                    del_url: '/admin/member_group/delete',
                    restore_url: '/admin/member_group/restore'
                },
                toolbarBtn:['add','preview','edit','del','refresh','import','export'],
                columns: [{
                    field: 'state',
                    id: 'state',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle'
                },{
                    field: 'name',
                    title: '组名',
                    width: '120',
                },{
                    field: 'level',
                    title: '等级',
                    width: '60'
                }, {
                    field: 'description',
                    title: '描述',
                    formatter: 'normal'
                }, {
                    field: 'status',
                    title: '状态',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/admin/member_group/status'
                }, {
                    field: 'create_time',
                    title: '创建时间',
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
