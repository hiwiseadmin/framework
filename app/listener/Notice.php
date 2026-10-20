<?php
namespace app\listener;

class Notice
{
    /**
     * 通知事件监听
     * @param Array $message 通知信息
     * @param string $message.type 通知类型 system:系统通知 user:用户通知
     * @param string $message.title 通知标题
     * @param string $message.url 通知跳转链接
     * @param string $message.format 通知格式，默认Html格式，支持 html:Html格式 markdown:Markdown格式, text:文本格式
     * @param string $message.content 通知内容，支持Html格式,Markdown格式,文本格式
     * 
     */
    public function handle(Array $message)
    {
        if(isset($message['type'])){
            $type = strtolower($message['type']);
            if($type == 'system'){
                $this->sendSystemNotice($message);
            } else if($type == 'user'){
                $this->sendUserNotice($message);
            }
        }
    }

    /**
     * 发送系统通知
     * @param Array $message 通知信息
     */
    private function sendSystemNotice($message)
    {
        // 处理系统通知
    }

    /**
     * 发送用户通知
     * @param Array $message 通知信息
     */
    private function sendUserNotice($message)
    {
        // 处理用户通知
    }
}