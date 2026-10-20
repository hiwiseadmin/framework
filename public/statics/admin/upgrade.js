/**
 * upgrade.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const layer = window.Layer;

const Controller = {
        index() {
            let taskId = null;
            let pollingInterval = null;

            $(function() {
                // 检查更新
                $('#checkUpdateBtn').click(function() {
                    $.post('/admin/upgrade/check', function(res) {
                        if (res.code == 0) {
                            layer.toast({content: '发现新版本：' + res.data.version + '\n更新内容：' + res.data.description, type: 'info'});
                            $('#startUpdateBtn').prop('disabled', false);
                            // 存储升级包地址
                            window.packageUrl = res.data.package_url;
                        } else {
                            layer.toast({content: res.msg, type: 'error'});
                        }
                    });
                });

                // 开始升级
                $('#startUpdateBtn').click(function() {
                    if (!window.packageUrl) {
                        layer.toast({content: '请先检查更新', type: 'error'});
                        return;
                    }
                    layer.confirm('升级将自动备份当前系统，确认开始升级吗？', {
                        title: '升级确认',
                        btn: ['确认升级', '取消']
                    }, function(index) {
                        $.post('/admin/upgrade/start', {package_url: window.packageUrl}, function(res) {
                            if (res.code == 0) {
                                taskId = res.data.task_id;
                                $('#updateProgressCard').show();
                                startPolling();
                                $('#startUpdateBtn').prop('disabled', true);
                                $('#checkUpdateBtn').prop('disabled', true);
                            } else {
                                layer.toast({content: res.msg, type: 'error'});
                            }
                            layer.close(index);
                        });
                    });
                });
            });

            /**
             * 开始轮询升级进度
             */
            function startPolling() {
                if (pollingInterval) clearInterval(pollingInterval);
                pollingInterval = setInterval(function() {
                    if (!taskId) return;
                    $.get('/admin/upgrade/progress', {taskId: taskId}, function(res) {
                        if (res.code == 0) {
                            let data = res.data;
                            let percent = data.percent;
                            if (percent < 0) {
                                // 升级失败
                                clearInterval(pollingInterval);
                                $('#progressBar').css('width', '100%').text('升级失败');
                                $('#progressMessage').text(data.message);
                                $('#startUpdateBtn').prop('disabled', false);
                                $('#checkUpdateBtn').prop('disabled', false);
                                layer.toast({content: '升级失败：' + data.message, type: 'error'});
                                location.reload(); // 刷新页面显示最新状态
                            } else if (percent >= 100) {
                                clearInterval(pollingInterval);
                                $('#progressBar').css('width', '100%').text('100%');
                                $('#progressMessage').text(data.message);
                                layer.toast({content: '升级成功！', type: 'success'});
                                location.reload();
                            } else {
                                $('#progressBar').css('width', percent + '%').text(percent + '%');
                                $('#progressMessage').text(data.message);
                            }
                        } else {
                            // 可能还没有进度信息，忽略
                        }
                    });
                }, 2000);
            };
        }
    };

export default Controller;
