<?php
namespace plugin\shortlink\service;

use plugin\shortlink\model\Link;
use plugin\shortlink\model\Log;

class StatService
{
    public function getStats(int $linkId): array
    {
        $link = Link::find($linkId);
        if (!$link) {
            throw new \RuntimeException('短链接不存在');
        }
        return [
            'link'         => $link->toArray(),
            'total_visits' => $link->total_visits,
            'unique_visits'=> $link->unique_visits,
            'today_visits' => $this->getTodayVisits($link->id),
            'device_stats' => $this->getDeviceStats($link->id),
            'browser_stats'=> $this->getBrowserStats($link->id),
        ];
    }

    public function getTodayVisits(int $linkId): int
    {
        return Log::where('link_id', $linkId)->whereDay('create_time', 'today')->count();
    }

    public function getDeviceStats(int $linkId): array
    {
        return Log::where('link_id', $linkId)
            ->field('device, count(*) as count')
            ->group('device')->select()->toArray();
    }

    public function getBrowserStats(int $linkId): array
    {
        return Log::where('link_id', $linkId)
            ->field('browser, count(*) as count')
            ->group('browser')->select()->toArray();
    }

    public function getTrend(int $linkId, int $days = 7): array
    {
        $start = date('Y-m-d', strtotime("-{$days} days"));
        $end = date('Y-m-d');

        $rows = Log::where('link_id', $linkId)
            ->whereBetweenTime('create_time', $start . ' 00:00:00', $end . ' 23:59:59')
            ->field('DATE(create_time) as date, count(*) as count')
            ->group('DATE(create_time)')->select()->toArray();

        $map = array_column($rows, 'count', 'date');
        $data = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $data[] = ['date' => $date, 'count' => (int) ($map[$date] ?? 0)];
        }
        return $data;
    }

    public function getTopReferers(int $linkId, int $limit = 10): array
    {
        return Log::where('link_id', $linkId)
            ->whereNotNull('referer')->where('referer', '<>', '')
            ->field('referer, count(*) as count')
            ->group('referer')->order('count', 'desc')->limit($limit)->select()->toArray();
    }

    public function getLogs(int $linkId, int $page = 1, int $pageSize = 20): array
    {
        $list = Log::where('link_id', $linkId)->order('id', 'desc')->paginate([
            'list_rows' => $pageSize, 'page' => $page,
        ]);
        return ['total' => $list->total(), 'rows' => $list->items()];
    }
}
