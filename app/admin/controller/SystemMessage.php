<?php
namespace app\admin\controller;

use app\admin\service\SystemMessageService;

class SystemMessage extends BaseController
{
    /**
     * 首页
     * @return string
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取消息列表
     */
    public function getList()
    {
        $userId = auth_id('admin');
        $page = $this->request->get('page', 1);
        $limit = $this->request->get('limit', 10);
        
        $result = SystemMessageService::instance()->getUserMessages($userId, $page, $limit);
        
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => $result
        ]);
    }
    
    /**
     * 标记消息为已读
     */
    public function markAsRead()
    {
        $userId = auth_id('admin');
        $messageId = $this->request->post('message_id');
        
        $result = SystemMessageService::instance()->markAsRead($messageId, $userId);
        
        return json([
            'code' => $result ? 200 : 400,
            'msg' => $result ? '标记成功' : '消息不存在或已标记',
            'data' => null
        ]);
    }
    
    /**
     * 批量标记为已读
     */
    public function batchMarkAsRead()
    {
        $userId = auth_id('admin');
        $messageIds = $this->request->post('message_ids/a', []);
        
        if (empty($messageIds)) {
            return json([
                'code' => 400,
                'msg' => '请选择消息',
                'data' => null
            ]);
        }
        
        $result = SystemMessageService::instance()->batchMarkAsRead($messageIds, $userId);
        
        return json([
            'code' => $result ? 200 : 500,
            'msg' => $result ? '批量标记成功' : '批量标记失败',
            'data' => ['count' => $result]
        ]);
    }
    
    /**
     * 标记所有为已读
     */
    public function markAllAsRead()
    {
        $userId = auth_id('admin');
        
        $result = SystemMessageService::instance()->markAllAsRead($userId);
        
        return json([
            'code' => $result ? 200 : 500,
            'msg' => $result ? '全部标记为已读' : '操作失败',
            'data' => null
        ]);
    }
    
    /**
     * 删除消息
     */
    public function delete()
    {
        $userId = auth_id('admin');
        $messageId = $this->request->post('message_id');
        
        $result = SystemMessageService::instance()->deleteUserMessage($messageId, $userId);
        
        return json([
            'code' => $result ? 200 : 400,
            'msg' => $result ? '删除成功' : '删除失败',
            'data' => null
        ]);
    }
    
    /**
     * 获取消息统计
     */
    public function getStats()
    {
        $userId = auth_id('admin');
        
        $stats = SystemMessageService::instance()->getMessageStats($userId);
        
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $stats
        ]);
    }
}