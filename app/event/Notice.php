<?php
namespace app\event;

/**
 * 通知事件
 */
class Notice
{
    /**
     * 通知信息
     */
    public $message = [];

    public function __construct(Array $message)
    {
        $this->message = $message;
    }
}
