<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3Battle;
use plugin\match3game\model\Match3PlayerProgress;
use plugin\match3game\model\Match3Element;
use plugin\match3game\model\Match3Prop;

/**
 * 游戏运营看板（§8.2）
 */
class Dashboard extends Base
{
    public function index()
    {
        return $this->fetch();
    }

    public function getStats()
    {
        $today = date('Y-m-d 00:00:00');

        // 总关卡数
        $totalLevels = Match3LevelDef::where('status', 1)->count();
        // 总对局数
        $totalBattles = Match3Battle::count();
        // 今日对局
        $todayBattles = Match3Battle::where('create_time', '>=', $today)->count();
        // 今日通关
        $todayPass = Match3Battle::where('create_time', '>=', $today)
            ->where('outcome', Match3Battle::OUTCOME_WIN)->count();
        // 总玩家数（有进度记录的）
        $totalPlayers = Match3PlayerProgress::group('user_id')->count();
        // 元素/道具数
        $totalElements = Match3Element::where('status', 1)->count();
        $totalProps    = Match3Prop::where('status', 1)->count();

        // 近 7 天对局趋势
        $trend = $this->recentTrend();

        // 星级分布
        $starDist = $this->starDistribution();

        return $this->success('', [
            'total_levels'   => $totalLevels,
            'total_battles'  => $totalBattles,
            'today_battles'  => $todayBattles,
            'today_pass'     => $todayPass,
            'pass_rate'      => $todayBattles > 0 ? round($todayPass / $todayBattles * 100, 2) : 0,
            'total_players'  => $totalPlayers,
            'total_elements' => $totalElements,
            'total_props'    => $totalProps,
            'trend'          => $trend,
            'star_dist'      => $starDist,
        ]);
    }

    protected function recentTrend(): array
    {
        $start = date('Y-m-d 00:00:00', strtotime('-6 days'));

        $rows = Match3Battle::where('create_time', '>=', $start)
            ->field([
                'DATE(create_time) as stat_date',
                'COUNT(*) as total',
                'SUM(IF(outcome = "win", 1, 0)) as passed',
            ])
            ->group('stat_date')
            ->select()
            ->toArray();

        $map = array_column($rows, null, 'stat_date');
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date'   => date('m-d', strtotime($date)),
                'total'  => (int) ($map[$date]['total'] ?? 0),
                'passed' => (int) ($map[$date]['passed'] ?? 0),
            ];
        }
        return $trend;
    }

    protected function starDistribution(): array
    {
        $rows = Match3PlayerProgress::where('best_stars', '>', 0)
            ->field('best_stars, COUNT(*) as cnt')
            ->group('best_stars')
            ->select()
            ->toArray();

        $dist = [1 => 0, 2 => 0, 3 => 0];
        foreach ($rows as $row) {
            $stars = (int) $row['best_stars'];
            if (isset($dist[$stars])) {
                $dist[$stars] = (int) $row['cnt'];
            }
        }
        return $dist;
    }
}
