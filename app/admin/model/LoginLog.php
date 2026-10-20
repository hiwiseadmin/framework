<?php
namespace app\admin\model;

use app\common\library\Client;
use app\common\library\IPLocation;
use app\common\model\BaseModel;
use wise\loginsecurity\device\DeviceFingerprint;
use wise\support\context\ContextualLogger;

class LoginLog extends BaseModel
{
    protected $name = 'login_log';
    protected $autoWriteTimestamp = false;
    
    // 登录状态
    const STATUS_SUCCESS = 1;
    const STATUS_FAILED = 0;
    
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
    
    public function getStatusTextAttr($value, $data)
    {
        return $data['status'] == self::STATUS_SUCCESS ? '成功' : '失败';
    }
    
    /**
     * 记录登录日志（仅写入 login_log 表，保持向后兼容）
     *
     * 与 wise-login-security 包的分工：
     * - 设备指纹：通过 DeviceFingerprint 生成
     * - 安全事件（login_success / login_failure 等）：由 LoginResultMiddleware 统一写入
     *   login_security_security_events 表，此处不再重复记录
     * - 设备管理：DeviceManager 由 wise-login-security 的 result_pipeline 自动处理
     */
    public static function record(int $adminId, string $username, int $status, string $message = '')
    {
        $request = request();
        $ip = $request->ip();

        // 使用 wise-login-security 包的 DeviceFingerprint 生成设备指纹
        $fingerprintSalt = config('wise-login-security.device_management.fingerprint_salt', '');
        $deviceFingerprint = DeviceFingerprint::fromRequest(
            $request->server(),
            $ip,
            $fingerprintSalt
        );

        $data = [
            'admin_id'           => $adminId,
            'username'           => $username,
            'login_ip'           => $ip,
            'location'           => IPLocation::getInstance()->getLocation($ip)->full(),
            'browser'            => self::getBrowser(),
            'os'                 => self::getOs(),
            'status'             => $status,
            'message'            => $message,
            'session_id'         => cookie('PHPSESSID'),
            'security_level'     => 2,   // 默认中等，具体等级由 wise-login-security 的异常检测决定
            'is_trusted_device'  => 0,   // 默认非信任，设备信任由 wise-login-security 的 DeviceManager 管理
            'device_fingerprint' => $deviceFingerprint,
            'login_time'         => date('Y-m-d H:i:s'),
        ];

        // 数据验证
        if (class_exists(\app\admin\validate\LoginLog::class)) {
            $validate = new \app\admin\validate\LoginLog();
            if (!$validate->scene('create')->check($data)) {
                ContextualLogger::error('登录日志验证失败: ' . $validate->getError());
                return false;
            }
        }

        return self::create($data);
    }

    /**
     * 获取浏览器信息
     */
    protected static function getBrowser()
    {
        return substr(Client::getBrowser()['string'], 0, 100);
    }
    
    /**
     * 获取操作系统
     */
    protected static function getOs()
    {
        return substr(Client::getOs()['string'], 0, 50);
    }
}