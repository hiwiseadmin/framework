<?php
namespace plugin\shortlink\controller;

use plugin\shortlink\model\Link as LinkModel;
use plugin\shortlink\service\StatService;

class Stat extends Base
{
    public function index()
    {
        $links = LinkModel::where('status', LinkModel::STATUS_ACTIVE)->order('id', 'desc')->limit(100)->field('id, code, title, long_url')->select();
        return $this->fetch('stat/index', ['links' => $links]);
    }

    public function getList()
    {
        $linkId = $this->request->param('link_id/d', 0);
        $page = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);

        $query = LinkModel::order('id', 'desc');
        if ($linkId > 0) $query->where('id', $linkId);
        $list = $query->paginate(['list_rows' => $pageSize, 'page' => $page]);

        // 批量获取今日访问量，避免 N+1 查询
        $linkIds = array_column($list->items(), 'id');
        $todayVisitsMap = [];
        if (!empty($linkIds)) {
            $todayStats = \plugin\shortlink\model\Log::whereIn('link_id', $linkIds)
                ->whereDay('create_time', 'today')
                ->field('link_id, count(*) as count')
                ->group('link_id')
                ->select()
                ->toArray();
            foreach ($todayStats as $stat) {
                $todayVisitsMap[$stat['link_id']] = (int) $stat['count'];
            }
        }

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['short_url'] = $item->short_url;
            $row['today_visits'] = $todayVisitsMap[$item->id] ?? 0;
            $rows[] = $row;
        }
        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    public function getTrend()
    {
        $linkId = $this->request->param('link_id/d', 0);
        $days = $this->request->param('days/d', 7);
        if ($linkId <= 0) return $this->error('请选择短链接');
        $link = LinkModel::find($linkId);
        if (!$link) return $this->error('短链接不存在');
        if (!$this->canManage((int) $link->admin_id)) {
            return $this->error('无权查看该短链接统计数据');
        }
        return $this->success('success', (new StatService())->getTrend($linkId, $days));
    }

    public function getTop()
    {
        $linkId = $this->request->param('link_id/d', 0);
        if ($linkId <= 0) return $this->error('请选择短链接');
        $link = LinkModel::find($linkId);
        if (!$link) return $this->error('短链接不存在');
        if (!$this->canManage((int) $link->admin_id)) {
            return $this->error('无权查看该短链接统计数据');
        }
        $statService = new StatService();
        return $this->success('success', [
            'referers' => $statService->getTopReferers($linkId),
            'devices'  => $statService->getDeviceStats($linkId),
            'browsers' => $statService->getBrowserStats($linkId),
        ]);
    }
}
