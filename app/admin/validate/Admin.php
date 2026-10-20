<?php
namespace app\admin\validate;

use think\Validate;

class Admin extends Validate
{
    /**
     * 密码策略（与前端 ws-form-validator 的 passwordStrength 默认策略保持一致）
     *
     * 前端模板写法：passwordStrength:8,letter,digit
     * 调整时须同步：本类常量 + ws-form-validator.js 的 PASSWORD_POLICY + 相关模板规则参数。
     */
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_LENGTH = 32;

    // 验证规则
    protected $rule = [
        'username'      => 'require|max:50|unique:admins',
        // 登录场景在 $scene['login'] 单独覆盖，不受强度规则影响
        'password'      => 'require|confirm|passwordPolicy',
        'old_password'  => 'require',
        'new_password'  => 'require|confirm:confirm_password|passwordPolicy',
        'confirm_password' => 'require',
        'nickname'      => 'require|max:50',
        'email'         => 'email|max:100|unique:admins',
        'mobile'        => 'regex:/^1[3-9]\d{9}$/|unique:admins',
        'role_ids'      => 'require|array|gt:1',
        'status'        => 'in:0,1',
        'last_login_ip' => 'max:50',
        'avatar'        => 'max:255',
        'description'   => 'max:255'
    ];
    
    // 验证提示
    protected $message = [
        'username.require' => '用户名不能为空',
        'username.max' => '用户名不能超过50个字符',
        'username.unique' => '用户名已存在',
        'password.require' => '密码不能为空',
        'password.min' => '密码不能少于6个字符',
        'password.max' => '密码不能超过32个字符',
        'password.confirm' => '两次密码输入不一致',
        'old_password.require' => '当前密码不能为空',
        'new_password.require' => '新密码不能为空',
        'new_password.min' => '新密码不能少于6个字符',
        'new_password.max' => '新密码不能超过32个字符',
        'new_password.confirm' => '两次密码输入不一致',
        'confirm_password.require' => '确认密码不能为空',
        'nickname.require' => '昵称不能为空',
        'nickname.max' => '昵称不能超过50个字符',
        'email.email' => '邮箱格式不正确',
        'email.max' => '邮箱不能超过100个字符',
        'email.unique' => '邮箱已存在',
        'mobile.regex' => '手机号格式不正确',
        'mobile.unique' => '手机号已存在',
        'role_ids.require' => '请选择角色',
        'role_ids.array' => '角色ID必须是数组',
        'role_ids.gt' => '角色必须选择至少一个',
        'status.in' => '状态值必须为0或1',
        'last_login_ip.max' => '登录IP不能超过50个字符',
        'avatar.max' => '头像路径不能超过255个字符',
        'description.max' => '备注不能超过255个字符'
    ];
    
    // 场景验证
    protected $scene = [
        'add'  => ['username', 'password', 'nickname', 'email', 'mobile', 'status', 'avatar', 'description'],
        'edit' => ['nickname', 'email', 'mobile', 'status', 'avatar', 'description'],
        'password' => ['password'],
        'change_password' => ['old_password', 'new_password', 'confirm_password'],
        'login' => [
            'username' => 'require|max:50',
            // 登录不做强度校验：存量账号可能是 6 位旧密码，改密时才强制新策略
            'password' => 'require|min:6|max:32'
        ]
    ];

    /**
     * 密码强度校验（自定义规则 passwordPolicy）
     *
     * 失败时返回具体原因字符串 —— think-validate 会把非 true 的字符串
     * 直接作为字段错误信息（Validate::checkItem），因此无需额外 message。
     *
     * 与前端 passwordStrength 的判定/文案保持一致：
     *   8~32 位、至少 1 个字母、至少 1 个数字、不允许空格。
     *
     * @param mixed  $value
     * @param mixed  $rule  规则参数（本规则不使用）
     * @param array  $data
     * @param string $field
     * @param string $title
     * @return true|string
     */
    protected function passwordPolicy($value, $rule, $data = [], $field = '', $title = '')
    {
        if ($value === null || $value === '') {
            return true; // 空值交由 require 处理
        }

        $value  = (string) $value;
        $length = mb_strlen($value, 'UTF-8');

        if ($length < self::PASSWORD_MIN_LENGTH) {
            return '密码至少 ' . self::PASSWORD_MIN_LENGTH . ' 位（当前 ' . $length . ' 位）';
        }

        if ($length > self::PASSWORD_MAX_LENGTH) {
            return '密码不能超过 ' . self::PASSWORD_MAX_LENGTH . ' 位（当前 ' . $length . ' 位）';
        }

        if (preg_match('/\s/u', $value)) {
            return '密码不能包含空格';
        }

        if (!preg_match('/[A-Za-z]/', $value)) {
            return '需包含至少 1 个字母';
        }

        if (!preg_match('/[0-9]/', $value)) {
            return '需包含至少 1 个数字';
        }

        return true;
    }

}
