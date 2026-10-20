<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3PlayerProgress;
use plugin\match3game\model\Match3PlayerInventory;
use plugin\match3game\model\Match3Battle;

/**
 * 玩家查询（§8.2）
 *
 * 按 user_id 查进度 / 背包 / 最近对局。
 */
class Player extends Base
{
    public function index()
    {
        return $this->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $userId   = $this->request->param('user_id/d', 0);

        $query = Match3PlayerProgress::where('user_id', '>', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        // 按 user_id 聚合：总星数 + 通关数
        $query->field([
            'user_id',
            'COUNT(*) as level_count',
            'SUM(best_stars) as star_total',
            'SUM(IF(best_stars > 0, 1, 0)) as passed_count',
            'MAX(last_play_at) as last_play_at',
        ])->group('user_id');

        $list = $query->order('user_id', 'asc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $rows[] = [
                'user_id'      => (int) $item->user_id,
                'level_count'  => (int) $item->level_count,
                'star_total'   => (int) $item->star_total,
                'passed_count' => (int) $item->passed_count,
                'last_play_at' => $item->last_play_at,
            ];
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 玩家详情：背包 + 关卡进度 + 最近对局
     */
    public function detail()
    {
        $userId = $this->request->param('user_id/d', 0);
        if ($userId <= 0) {
            return $this->error('缺少 user_id');
        }

        $inventory = Match3PlayerInventory::where('user_id', $userId)
            ->field('item_id, count')
            ->select()
            ->toArray();

        $progress = Match3PlayerProgress::where('user_id', $userId)
            ->field('level_code, best_stars, best_score, play_count, first_pass_at')
            ->order('level_code', 'asc')
            ->select()
            ->toArray();

        $battles = Match3Battle::where('user_id', $userId)
            ->field('level_code, outcome, score, moves_used, started_at, finished_at')
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();

        return $this->success('', [
            'user_id'   => $userId,
            'inventory' => $inventory,
            'progress'  => $progress,
            'battles'   => $battles,
        ]);
    }
}
