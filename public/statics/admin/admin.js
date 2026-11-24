/**
 * admin.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const CODE_OK = 0;

const Controller = {
        index() {
            // 初始化表格
            const table = new Table({
                idTable: 'table',
                url: '/admin/admin/getList',
                sortName: '',
                sortOrder: '',
                extend: {
                    preview_url: '/admin/admin/preview',
                    add_url: '/admin/admin/add',
                    edit_url: '/admin/admin/edit',
                    del_url: '/admin/admin/delete',
                    multi_url: '/admin/admin/multi'
                },
                // search：切换搜索区显隐（页面有 .ws-filter-bar 才渲染该按钮）
                toolbarBtn:['add','preview','edit','del','multi','search'],
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
                    order: 'asc',
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
                    field: 'description',
                    title: '备注',
                    formatter: 'normal'
                }, {
                    field: 'status',
                    title: '状态',
                    formatter: 'toggle',
                    yes: 1,
                    no: 0,
                    url: '/admin/admin/status',
                    disable(value, row, index) {
                        return row.id == 1;
                    }
                }, {
                    field: 'last_login_time',
                    title: '最后登录时间',
                    formatter: 'normal',
                }, {
                    field: 'operate',
                    buttons: ['preview','edit','del'],
                    title: '操作',
                    formatter: 'operate',
                    hidden(value, row, index) {
                        return row.id == 1;
                    }
                }]
            });

            // 搜索区（UIS v1.1 P3）：把 .ws-filter-bar 的参数并入表格 queryParams。
            // bindFilterBar 负责「查询 / 重置 / 回车」，并只在参数非空时追加，
            // 因此与表格原有分页/排序参数不冲突。筛选条由服务端按 defineSearch() 渲染。
            if (window.Wise && window.Wise.crud && document.getElementById('ws-filter')) {
                window.Wise.crud.bindFilterBar({ form: '#ws-filter', table });
            }
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

            // 初始化头像上传组件（懒加载，首次点击时创建）
            let avatarUploader = null;
            $('#upload-avatar').click(function() {
                if (!avatarUploader && window.Wise?.upload?.avatarUploader) {
                    avatarUploader = window.Wise.upload.avatarUploader({
                        modal: '#avatarSelectModal',
                        presetUrl: '/admin/profile/getAvatars',
                        uploadCroppedAvatarUrl: '/admin/profile/uploadCroppedAvatar',
                        showUploadType: false,
                        onSuccess: function(avatar) {
                            $('#avatar').val(avatar);
                            $('#avatar-preview').attr('src', avatar);
                        }
                    });
                }
                if (avatarUploader) avatarUploader.show();
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

            // 重置密码：统一入口 dialog.form（UIS v1.1 §4）
            // 本页本身是被 iframe 弹层打开的，弹窗由父窗口的 dialog 渲染，
            // 避免「弹层里再套 modal」（原 data-bs-toggle 方案的三层叠加问题）。
            // 校验、提交 loading、成功提示与关闭延迟都由 dialog.form 统一处理。
            $('#resetPwdBtn').on('click', function () {
                const tpl = document.getElementById('resetPwdTpl');
                if (!tpl) return;

                const ui = (window.parent && window.parent.Wise && window.parent.Wise.ui) || window.Wise.ui;
                ui.dialog.form({
                    title: '重置密码',
                    html: tpl.innerHTML,
                    action: '/admin/admin/resetPassword',
                    submitText: '确认重置',
                    successMsg: '密码重置成功',
                });
            });

            // 初始化头像上传组件（懒加载，首次点击时创建）
            let avatarUploader = null;
            $('#upload-avatar').click(function() {
                if (!avatarUploader && window.Wise?.upload?.avatarUploader) {
                    avatarUploader = window.Wise.upload.avatarUploader({
                        modal: '#avatarSelectModal',
                        presetUrl: '/admin/profile/getAvatars',
                        uploadCroppedAvatarUrl: '/admin/profile/uploadCroppedAvatar',
                        showUploadType: false,
                        onSuccess: function(avatar) {
                            $('#avatar').val(avatar);
                            $('#avatar-preview').attr('src', avatar);
                        }
                    });
                }
                if (avatarUploader) avatarUploader.show();
            });
        }
    };

export default Controller;
