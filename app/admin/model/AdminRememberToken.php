<?php
namespace app\admin\model;

use app\common\model\BaseModel;

/**
 * 后台保持登录令牌
 *
 * @property int    $id
 * @property int    $admin_id
 * @property string $selector
 * @property string $validator_hash
 * @property string $credential_hash
 * @property string $expires_at
 * @property string $last_used_time
 * @property string $login_ip
 * @property string $user_agent
 * @property string $create_time
 * @property string $update_time
 */
class AdminRememberToken extends BaseModel
{
    // 数据库表名
    protected $name = 'admin_remember_token';

    // 敏感字段不参与序列化输出
    protected $hidden = ['validator_hash', 'credential_hash'];

    protected $allowWriteField = [
        'admin_id', 'selector', 'validator_hash', 'credential_hash',
        'expires_at', 'last_used_time', 'login_ip', 'user_agent',
    ];
}
