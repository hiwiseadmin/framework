<?php
namespace app\common\event;

class MessageEvent
{
    public $messageData;
    public $result;
    
    public function __construct(array $messageData)
    {
        // 设置默认值
        $defaults = [
            'priority' => 1,
            'status' => 1,
            'receiver_type' => 'all',
            'create_time' => date('Y-m-d H:i:s')
        ];
        
        $this->messageData = array_merge($defaults, $messageData);
    }
    
    // 设置处理结果
    public function setResult($result)
    {
        $this->result = $result;
    }
    
    // 获取处理结果
    public function getResult()
    {
        return $this->result;
    }
}