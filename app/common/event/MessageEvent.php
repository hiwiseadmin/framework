<?php
namespace app\common\event;

class MessageEvent
{
    public $messageData;
    public $result;
    
    public function __construct(array $messageData)
    {
        $defaults = [
            'priority' => 1,
            'status' => 1,
            'receiver_type' => 'all',
            'create_time' => date('Y-m-d H:i:s')
        ];
        
        $this->messageData = array_merge($defaults, $messageData);
    }
    
    public function setResult($result)
    {
        $this->result = $result;
    }
    
    public function getResult()
    {
        return $this->result;
    }
}