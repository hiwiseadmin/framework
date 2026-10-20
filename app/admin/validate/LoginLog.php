<?php
namespace app\admin\validate;

use think\Validate;

class LoginLog extends Validate
{
    protected $rule = [
        'admin_id' => 'require|integer|min:0',
        'username' => 'require|max:50',
        'login_ip' => 'require|ip',
        'location' => 'max:100',
        'browser' => 'max:100',
        'os' => 'max:50',
        'status' => 'require|in:0,1',
        'message' => 'max:255',
        'login_time' => 'require|date',
    ];

    protected $message = [
        'admin_id.require' => '管理员ID不能为空',
        'admin_id.integer' => '管理员ID必须为整数',
        'admin_id.min' => '管理员ID不能小于0',
        'username.require' => '用户名不能为空',
        'username.max' => '用户名长度不能超过50个字符',
        'login_ip.require' => '登录IP不能为空',
        'login_ip.ip' => '登录IP格式不正确',
        'location.max' => '登录地点长度不能超过100个字符',
        'browser.max' => '浏览器信息长度不能超过100个字符',
        'os.max' => '操作系统信息长度不能超过50个字符',
        'status.require' => '登录状态不能为空',
        'status.in' => '登录状态值不合法',
        'message.max' => '提示消息长度不能超过255个字符',
        'login_time.require' => '登录时间不能为空',
        'login_time.date' => '登录时间格式不正确',
    ];

    // 场景定义
    protected $scene = [
        'create' => ['admin_id', 'username', 'login_ip', 'status', 'login_time'],
        'search' => ['username', 'status', 'login_time'],
    ];

    /**
     * 自定义验证 - 登录时间范围
     */
    protected function checkDateRange($value, $rule, $data = [])
    {
        if (isset($data['start_date']) && isset($data['end_date'])) {
            $start = strtotime($data['start_date']);
            $end = strtotime($data['end_date']);
            
            if ($start > $end) {
                return '开始时间不能大于结束时间';
            }
            
            // 限制查询时间范围不超过90天
            if (($end - $start) > 90 * 24 * 3600) {
                return '查询时间范围不能超过90天';
            }
        }
        
        return true;
    }
}