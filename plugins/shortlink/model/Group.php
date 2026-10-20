<?php
namespace plugin\shortlink\model;

use plugin\BaseModel;

class Group extends BaseModel
{
    const STATUS_DISABLED = 0;
    const STATUS_ACTIVE   = 1;

    protected $name = 'shortlink_group';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time';

    protected $schema = [
        'id'          => 'int',
        'name'        => 'string',
        'ident'       => 'string',
        'description' => 'string',
        'sort_order'  => 'int',
        'admin_id'    => 'int',
        'status'      => 'int',
        'create_time'  => 'datetime',
        'update_time'  => 'datetime',
        'delete_time'  => 'datetime',
    ];

    public function links()
    {
        return $this->hasMany(Link::class, 'group_id');
    }

    public function getStatusTextAttr($value, $data): string
    {
        $map = [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ACTIVE   => '启用',
        ];
        return $map[$data['status']] ?? '未知';
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
