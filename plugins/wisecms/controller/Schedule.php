<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\engine\SchedulerService;
class Schedule extends BaseController
{
    protected SchedulerService $scheduler;

    protected function initPlugin(): void
    {
        $this->scheduler = new SchedulerService();
    }

    /**
     * 排期日历：AJAX 返回事件数组，非 AJAX 渲染日历视图（UIUX-P0-35 补视图分支）
     */
    public function calendar()
    {
        $start = $this->request->param('start', date('Y-m-01'));
        $end = $this->request->param('end', date('Y-m-t'));
        $events = $this->scheduler->getCalendar(1, $start, $end);

        if ($this->request->isAjax()) {
            return $this->success('', $events);
        }

        $this->assign('events', $events);
        return $this->fetch('admin/schedule/calendar');
    }

    /**
     * 批量排期
     */
    public function batch()
    {
        $ids = $this->request->post('ids/a', []);
        $time = $this->request->post('scheduled_time', '');
        if (empty($ids) || empty($time)) {
            return $this->error('参数不完整');
        }
        // 时间字段统一：由服务层用 cms_datetime() 归一（接受 datetime-local 字符串或时间戳）
        try {
            $count = $this->scheduler->batchSchedule(array_map('intval', $ids), $time);
        } catch (WisecmsException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success("已排期 {$count} 篇内容");
    }

    /**
     * 推荐发布时间
     */
    public function suggest()
    {
        $catId = (int)$this->request->param('catid', 0);
        $suggestions = $this->scheduler->suggestTime($catId);
        return $this->success('', $suggestions);
    }
}
