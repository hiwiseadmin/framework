<?php
namespace plugin\shortlink\model;

use plugin\BaseModel;

/**
 * 短链接模型
 *
 * @property int    $id
 * @property string $code           短码
 * @property string $long_url       原始长链接
 * @property string $title          链接标题
 * @property int    $group_id       分组ID
 * @property string $password       访问密码(bcrypt哈希)
 * @property string $expire_time    过期时间
 * @property int    $max_visits     最大访问次数(0=不限制)
 * @property int    $total_visits   总访问次数
 * @property int    $unique_visits  独立访客数
 * @property int    $status         状态
 * @property int    $admin_id       创建者ID
 */
class Link extends BaseModel
{
    // 状态常量
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ACTIVE   = 1; // 启用
    const STATUS_EXPIRED  = 2; // 已过期

    protected $name = 'shortlink_link';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';

    // 隐藏敏感字段，防止 toArray() / JSON 序列化泄露密码哈希
    protected $hidden = ['password'];

    protected $schema = [
        'id'            => 'int',
        'code'          => 'string',
        'long_url'      => 'string',
        'title'         => 'string',
        'group_id'      => 'int',
        'password'      => 'string',
        'expire_time'   => 'datetime',
        'max_visits'    => 'int',
        'total_visits'  => 'int',
        'unique_visits' => 'int',
        'status'        => 'int',
        'admin_id'      => 'int',
        'create_time'    => 'datetime',
        'update_time'    => 'datetime',
        'delete_time'    => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'link_id');
    }

    public function getShortUrlAttr($value, $data): string
    {
        return shortlink_url($data['code']);
    }

    public function getStatusTextAttr($value, $data): string
    {
        $map = [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ACTIVE   => '启用',
            self::STATUS_EXPIRED  => '已过期',
        ];
        return $map[$data['status']] ?? '未知';
    }

    /**
     * 判断是否已过期（实时计算）
     */
    public function getIsExpiredAttr(): bool
    {
        if ($this->getData('status') == self::STATUS_EXPIRED) {
            return true;
        }
        if (!empty($this->getData('expire_time')) && strtotime($this->getData('expire_time')) < time()) {
            return true;
        }
        return false;
    }

    /**
     * 同步过期状态：将到达过期时间但状态未更新的链接标记为已过期
     */
    public static function syncExpiredStatus(): int
    {
        return self::where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expire_time')
            ->where('expire_time', '<', date('Y-m-d H:i:s'))
            ->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * 查询作用域：仅启用状态
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 查询作用域：按分组筛选
     */
    public function scopeByGroup($query, int $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * 查询作用域：未过期
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expire_time')
              ->whereOr('expire_time', '>', date('Y-m-d H:i:s'));
        });
    }

    public static function search($params)
    {
        $query = self::where('1', '1');

        if (!empty($params['keyword'])) {
            // 转义 LIKE 通配符，防止非预期查询
            $keyword = addcslashes($params['keyword'], '%_\\');
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                  ->whereOr('title', 'like', "%{$keyword}%")
                  ->whereOr('long_url', 'like', "%{$keyword}%");
            });
        }
        if (!empty($params['group_id'])) {
            $query->where('group_id', $params['group_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        if (!empty($params['admin_id'])) {
            $query->where('admin_id', $params['admin_id']);
        }

        return $query->order('id', 'desc');
    }
}
