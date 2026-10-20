<?php
namespace plugin\shortlink\model;

use plugin\BaseModel;

class Log extends BaseModel
{
    protected $name = 'shortlink_log';
    protected $readonly = ['create_time'];
    protected $deleteTime = false;

    protected $schema = [
        'id'         => 'int',
        'link_id'    => 'int',
        'code'       => 'string',
        'ip'         => 'string',
        'ua'         => 'string',
        'referer'    => 'string',
        'country'    => 'string',
        'province'   => 'string',
        'city'       => 'string',
        'device'     => 'string',
        'browser'    => 'string',
        'os'         => 'string',
        'create_time'=> 'datetime',
    ];

    public static function onBeforeUpdate($model)
    {
        return false;
    }

    public function link()
    {
        return $this->belongsTo(Link::class, 'link_id');
    }
}
