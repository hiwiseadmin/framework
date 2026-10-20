<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3Battle;

/**
 * 对局日志查询（§8.2）
 *
 * 结算明细 / 校验失败 / 风控命中查询。
 */
class BattleLog extends Base
{
    public function index()
    {
        return $this->fetch();
    }

    public function getList()
    {
        $page      = $this->request->param('pageNumber/d', 1);
        $pageSize  = $this->request->param('pageSize/d', 20);
        $userId    = $this->request->param('user_id/d', 0);
        $levelCode = $this->request->param('level_code', '');
        $outcome   = $this->request->param('outcome', '');

        $query = Match3Battle::where('id', '>', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($levelCode !== '') {
            $query->where('level_code', $levelCode);
        }
        if ($outcome !== '') {
            $query->where('outcome', $outcome);
        }

        $list = $query->order('id', 'desc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['status_text'] = $this->statusText((int) $row['status']);
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 对局详情（结算明细）
     */
    public function detail()
    {
        $id = $this->request->param('id/d', 0);
        $battle = Match3Battle::find($id);
        if (!$battle) {
            return $this->error('对局不存在');
        }
        return $this->success('', $battle->toArray());
    }

    protected function statusText(int $status): string
    {
        return [
            Match3Battle::STATUS_PLAYING   => '进行中',
            Match3Battle::STATUS_DONE      => '已结算',
            Match3Battle::STATUS_ABANDONED => '已放弃',
            Match3Battle::STATUS_EXPIRED   => '已过期',
        ][$status] ?? '未知';
    }
}
