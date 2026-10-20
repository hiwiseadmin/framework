<?php
namespace app\admin\model;

use app\common\model\BaseModel;
use think\helper\Arr;
use think\model\relation\MorphToMany;
use wise\auth\contract\UserContract;
use wise\auth\traits\HasRoles;
use wise\loginsecurity\contract\TotpUserInterface;
use wise\loginsecurity\traits\HasTotp;
use wise\notification\contract\Notifiable;
use wise\notification\traits\NotifiableTrait;

class Admin extends BaseModel implements UserContract, TotpUserInterface,Notifiable
{
    use HasRoles;
    use HasTotp;
    use NotifiableTrait;
    
    // 数据库表名
    protected $name = 'admins';
    
    protected $hidden = ['password'];

    protected $json = ['ext_fields'];

    /**
     * 可写字段白名单（防批量赋值）
     * 密码/角色等敏感字段必须由专用方法处理，不允许通过表单直接写入
     */
    protected $allowWriteField = [
        'username', 'password', 'nickname', 'email', 'mobile',
        'status', 'avatar', 'description', 'last_login_time', 'last_login_ip', 'ext_fields',
    ];

    /**
     * 当前模型的 user_type 标识
     *
     * @var string $totpUserType
     */
    protected string $totpUserType = 'admin';

    
    public function getTotpLabel(): string
    {
        return $this->username;
    }

    /**
     * 模型初始化：注册多态映射，确保 morphToMany 使用简短的 user_type 值
     */
    // protected static function init()
    // {
    //     // 将 FQCN 映射为短标识，与 auth_user_role.user_type 默认值 'admin' 一致
    //     MorphToMany::morphMap([
    //         static::class => 'admin',
    //     ]);
    // }

    /**
     * 密码加密
     * @param $value
     */
    public function setPasswordAttr(string $value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 关联角色（由 wise-auth HasRoles trait 提供，多态关联 auth_user_role 表）
     *
     * 注意：此方法不再自行定义，完全交由 HasRoles trait 实现，
     * 使用 wise-auth 的 morphToMany 多态关联，支持多用户类型。
     *
     * @return \think\model\relation\MorphToMany
     */
    // public function roles()
    // {
    //     return $this->belongsToMany(Role::class, 'admin_role', 'role_id', 'admin_id');
    // }

    /**
     * 获取扩展字段值
     * @param string $key
     * @param $default
     * @return mixed
     */
    public function getExtField(string $key, $default = null)
    {
        $extFields = (array)$this->ext_fields ?: [];
        return Arr::get($extFields, $key, $default);
    }
    
    /**
     * 设置扩展字段值
     * @param string $key
     * @param $value
     * @return $this
     */
    public function setExtField(string $key, $value)
    {
        $extFields = (array)$this->ext_fields ?: [];
        Arr::set($extFields, $key, $value);
        $this->ext_fields = $extFields;
        return $this;
    }
    
    /**
     * 删除扩展字段
     * @param string $key
     * @return $this
     */
    public function removeExtField(string $key)
    {
        $extFields = (array)$this->ext_fields ?: [];
        Arr::forget($extFields, $key);
        $this->ext_fields = $extFields;
        return $this;
    }
}
