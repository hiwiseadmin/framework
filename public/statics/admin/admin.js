/**
 * admin.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;
const Layer = window.Layer;
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
                toolbarBtn:['add','preview','edit','del','multi'],
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

            // 重置密码表单验证
            // 校验与提交都在校验器内完成：校验不通过时 onSubmit 不会触发，
            // 避免绕过前端校验直接提交弱密码（原先另挂 submit 处理器会跳过校验）。
            new FormValidator('resetPwdForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: false,
                feedbackPlaceholder: true,
                onSubmit(form, e, data) {
                    resetPassword();
                }
            });

            // 显示重置密码模态框
            // $('#resetPwdBtn').click(function() {
            //     const id = $(this).data('id');
                // $('#resetUserId').val(id);
            //     $('#resetPwdForm')[0].reset();
            //     $('#resetPwdModal').modal('show');
            // });

            // 重置密码
            function resetPassword() {
                const id = $('#resetUserId').val();
                const password = $('#password').val();
                const confirmPassword = $('#password_confirm').val();

                $.post('/admin/admin/resetPassword', {
                    id: id,
                    password: password,
                    password_confirm: confirmPassword
                }, function(res) {
                    if (res.code === CODE_OK) {
                        Layer.top().toast({content:'密码重置成功', type:'success'});
                        $('#resetPwdModal').modal('hide');
                    } else {
                        Layer.top().toast({content:res.msg || res.message || '密码重置失败', type:'error'});
                    }
                    // 走原生 reset：同时清空强度条与条件清单（校验器监听 reset 事件）
                    document.getElementById('resetPwdForm').reset();
                }).fail(() => {
                    document.getElementById('resetPwdForm').reset();
                    Layer.top().toast({content:'网络错误，密码重置失败', type:'error'});
                });
            }

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
