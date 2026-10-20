/**
 * login_log.js — ESM 模块（已从 AMD 迁移）
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
                url: '/admin/login_log/getList',
                showColumns: true,
                pagination: true,
                dataDataField: 'data.list',
                extend: {
                    index_url: '/admin/login_log/index',
                    preview_url: '/admin/login_log/preview'
                },
                toolbarBtn:['preview','refresh','export'],
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
                //     field: 'admin_id',
                //     title: '管理员ID',
                //     formatter: 'normal',
                //     align: 'left'
                // },  {
                    field: 'username',
                    title: '用户名',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'location',
                    title: '登录位置',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'browser',
                    title: '浏览器',
                    formatter: 'normal',
                    align: 'left'
                },{
                    field: 'security_level',
                    title: '安全等级',
                    formatter: 'normal',
                    align: 'left',
                    custom: {
                        '1': 'success',
                        '2': 'info',
                        '3': 'danger'
                    },
                    searchList: {
                        '1': '低风险',
                        '2': '中风险',
                        '3': '高风险'
                    }
                },{
                    field: 'is_trusted_device',
                    title: '是否信任设备',
                    formatter: 'status',
                    align: 'left',
                    custom: {
                        '0': 'danger',
                        '1': 'success'
                    },
                    searchList: {
                        '0': '未信任',
                        '1': '已信任'
                    }
                },{
                    field: 'os',
                    title: '操作系统',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'login_ip',
                    title: '登录IP',
                    formatter: 'search',
                    align: 'left'
                },{
                    field: 'message',
                    title: '登录信息',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'status',
                    title: '状态',
                    formatter: 'status',
                    custom: {
                        '0': 'danger',
                        '1': 'success'
                    },
                    searchList: {
                        '0': '失败',
                        '1': '成功'
                    }
                },{
                    field: 'login_time',
                    title: '登录时间',
                    formatter: 'normal',
                    align: 'left'
                }, {
                    field: 'operate',
                    buttons: ['preview','del'],
                    title: '操作',
                    formatter: 'operate'
                }]
            });
        }
    };

export default Controller;
