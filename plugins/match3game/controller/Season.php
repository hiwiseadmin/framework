<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3Season;
use plugin\match3game\model\Match3SeasonPlayer;
use plugin\match3game\service\social\SeasonService;
use plugin\match3game\service\social\SocialRules;
use wise\support\context\ContextualLogger;

/**
 * 赛季管理（Phase 5 · 05 §A7.1，P5-B12）
 *
 * 赛季周期 **2 周**；段位 6 档；负分不降段。本页负责：
 *   · 赛季列表 / 新建 / 修改（含段位奖励配置）
 *   · 一键结算并切到下一赛季（等价于 `php think match3:season`）
 *   · 查看某赛季的玩家积分分布
 */
class Season extends Base
{
    protected const ALLOWED = ['season_id', 'name', 'start_at', 'end_at', 'status', 'reward_conf'];

    public function index()
    {
        return $this->assign([
            'tiers'  => $this->tierTable(),
            'status' => [
                Match3Season::STATUS_PENDING => '未开始',
                Match3Season::STATUS_ACTIVE  => '进行中',
                Match3Season::STATUS_SETTLED => '已结算',
            ],
            'current' => (new SeasonService())->ensureSeason(),
        ])->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $status   = $this->request->param('status', '');

        $query = Match3Season::where('1=1');
        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        $list = $query->order('id', 'desc')->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row          = $item->toArray();
            $row['status_text'] = Match3Season::statusText((int) $row['status']);
            $row['player_count'] = Match3SeasonPlayer::where('season_id', $row['season_id'])->count();
            // 奖励配置项数（前端展示用，避免整块 JSON 塞进列表）
            $row['reward_tiers'] = is_array($row['reward_conf'] ?? null) ? count($row['reward_conf']) : 0;
            unset($row['reward_conf']);
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /** 赛季详情（含奖励配置原文） */
    public function detail()
    {
        $seasonId = (string) $this->request->param('season_id', '');
        $row      = Match3Season::where('season_id', $seasonId)->find();
        if (!$row) {
            return $this->error('赛季不存在');
        }

        $data = $row->toArray();

        // 段位分布（便于核对结算）
        $dist = [];
        foreach (array_keys(SocialRules::TIERS) as $tier) {
            $dist[$tier] = Match3SeasonPlayer::where('season_id', $seasonId)->where('tier', $tier)->count();
        }
        $data['tier_dist'] = $dist;
        $data['top']       = Match3SeasonPlayer::where('season_id', $seasonId)
            ->order('points', 'desc')->limit(20)->select()->toArray();

        return $this->success('', $data);
    }

    public function save()
    {
        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);
        $data = array_intersect_key($data, array_flip(self::ALLOWED));

        if (empty($data['season_id'])) {
            return $this->error('赛季 ID 不能为空（形如 S2026-01）');
        }

        if (isset($data['reward_conf']) && is_string($data['reward_conf'])) {
            $decoded = json_decode(trim($data['reward_conf']), true);
            if (!is_array($decoded)) {
                return $this->error('reward_conf 必须是合法 JSON');
            }
            $data['reward_conf'] = $decoded;
        }
        if (empty($data['reward_conf'])) {
            $data['reward_conf'] = SeasonService::defaultRewards();
        }

        // 奖励配置的段位键必须合法
        foreach (array_keys($data['reward_conf']) as $tier) {
            if (!isset(SocialRules::TIERS[$tier])) {
                return $this->error('未知段位: ' . $tier . '（允许 ' . implode('/', array_keys(SocialRules::TIERS)) . '）');
            }
        }

        try {
            if ($id > 0) {
                $row = Match3Season::find($id);
                if (!$row) {
                    return $this->error('赛季不存在');
                }
                $row->save($data);
            } else {
                if (Match3Season::where('season_id', $data['season_id'])->find()) {
                    return $this->error('赛季 ID 已存在: ' . $data['season_id']);
                }
                (new Match3Season())->save($data);
            }

            return $this->success('保存成功');
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] Season save error: ' . $e->getMessage());

            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 结算并切换赛季（等价 `php think match3:season`）
     */
    public function switchSeason()
    {
        $settle = (string) $this->request->param('settle', '');

        try {
            $result = (new SeasonService())->switchSeason($settle);
        } catch (\Throwable $e) {
            return $this->error('切换失败: ' . $e->getMessage());
        }

        return $this->success('切换完成', $result);
    }

    /**
     * 段位表（前端下拉/说明用）
     *
     * @return array<int,array<string,mixed>>
     */
    protected function tierTable(): array
    {
        $rewards = SeasonService::defaultRewards();
        $out     = [];
        foreach (SocialRules::TIERS as $tier => [$min, $max]) {
            $out[] = [
                'tier'    => $tier,
                'name'    => SocialRules::tierName($tier),
                'min'     => $min,
                'max'     => $max,
                'rewards' => $rewards[$tier] ?? [],
            ];
        }

        return $out;
    }
}
