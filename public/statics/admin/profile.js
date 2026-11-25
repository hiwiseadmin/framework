/**
 * profile.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Framework = window.Framework;
const Layer = window.Layer;
const FormValidator = window.FormValidator;
const Broadcast = window.Broadcast;

'use strict';
    const Controller = {
        index() {           
            // 点击刷新按钮
            $('#refreshBtn').click(function() {
                window.location.reload();
            });

            // 点击编辑资料按钮
            $('#editProfileBtn').click(function() {
                // 打开编辑模态框
                const editModal = new bootstrap.Modal(document.getElementById('editProfileModal'));
                editModal.show();
            });
            
            new FormValidator('profileForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: false,
                onSubmit(form, e, data) {
                    profileFormSubmit(form);
                }
            });

            // 提交个人资料表单
            var profileFormSubmit = function(form) {
                const saveBtn = $('#saveProfileBtn');
                const originalText = saveBtn.html();
                
                // 显示加载状态
                saveBtn.prop('disabled', true);
                saveBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>保存中...');
                
                // 收集表单数据
                const formData = $(form).serialize();;
                
                // 如果有头像文件，也添加到FormData
                // const avatarFile = $('#avatarFile')[0].files[0];
                // if (avatarFile) {
                //     formData.append('avatar', avatarFile);
                // }
                
                $.ajax({
                    url: '/admin/profile/update',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    // processData: false,
                    // contentType: false,
                    success(response) {
                        if (response.code === 200) {
                            Layer.top().toast({content:'个人资料更新成功', type:'success'});
                            
                            // 更新页面显示
                            window.location.reload();
                        } else {
                            Layer.top().toast({content:response.msg, type:'error'});
                        }
                        
                        // 恢复按钮状态
                        saveBtn.prop('disabled', false);
                        saveBtn.html(originalText);
                    },
                    error() {
                        Layer.top().toast({content:'网络错误，请稍后重试', type:'error'});
                        
                        // 恢复按钮状态
                        saveBtn.prop('disabled', false);
                        saveBtn.html(originalText);
                    }
                });
            };
            
            // 校验密码表单
            new FormValidator('passwordForm', {
                liveValidation: true,
                showIcons: true,
                autoSubmit: false,
                onSubmit(form, e, data) {
                    passwordFormSubmit(form);
                }
            });

            // 点击修改密码按钮
            $('#changePasswordBtn').on('click', function() {
                const passwordModal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
                passwordModal.show();
            });
            
            // 提交密码修改表单
            var passwordFormSubmit = function(form) {                
                const formData = $(form).serialize();
                
                $.ajax({
                    url: '/admin/profile/changePassword',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success(ret) {
                        if (ret.code === 200) {
                            Layer.top().toast({content:'密码修改成功', type:'success'});
                            
                            // 关闭模态框并清空表单
                            bootstrap.Modal.getInstance(document.getElementById('changePasswordModal')).hide();
                            $('#passwordForm')[0].reset();
                        } else {
                            Layer.top().toast({content:ret.msg, type:'error'});
                        }
                    },
                    error() {
                        Layer.top().toast({content:'网络错误，请稍后重试', type:'error'});
                    }
                });
            };

            // Wise.upload.avatarUploader（完整头像上传器）
            let avatarUploader = null;

            $('#avatarUploadBtn').click(function() {
                if (!avatarUploader) {
                    avatarUploader = window.Wise.upload.avatarUploader({
                        modal: '#avatarSelectModal',
                        presetUrl: '/admin/profile/getAvatars',
                        uploadCroppedAvatarUrl: '/admin/profile/uploadCroppedAvatar',
                        updateAvatarUrl: '/admin/profile/update',
                        onSuccess(avatarUrl) {
                            $('#avatarImg').attr('src', avatarUrl);
                            const broadcast = new Broadcast({channelName: 'avatar'});
                            broadcast.send('updateAvatar', {avatar: avatarUrl});
                        },
                    });
                }
                avatarUploader.show();
            });
        }
    };
    
export default Controller;
