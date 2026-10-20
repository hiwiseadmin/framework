<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3LevelDef;

/**
 * 章节/地图管理（§8.2）
 *
 * 章节 = level_def.chapter 分组，展示章节内关卡顺序与解锁链。
 */
class Chapter extends Base
{
    public function index()
    {
        return $this->fetch();
    }

    public function getList()
    {
        $chapters = Match3LevelDef::where('status', 1)
            ->field('chapter')
            ->group('chapter')
            ->order('chapter')
            ->column('chapter');

        $rows = [];
        foreach ($chapters as $index => $chapter) {
            $levels = Match3LevelDef::where('status', 1)
                ->where('chapter', $chapter)
                ->field('code, name, difficulty, pre_unlock, unlock_stars, sort_order')
                ->order('sort_order', 'asc')
                ->select()
                ->toArray();

            $rows[] = [
                'id'          => $index + 1,
                'chapter'     => $chapter,
                'level_count' => count($levels),
                'levels'      => $levels,
            ];
        }

        return $this->success('', ['total' => count($rows), 'rows' => $rows]);
    }

    /**
     * 批量更新关卡归属章节
     */
    public function moveLevels()
    {
        $levelIds = $this->request->post('ids', []);
        $chapter  = (string) $this->request->post('chapter', '');

        if (empty($levelIds) || $chapter === '') {
            return $this->error('缺少参数');
        }
        if (!is_array($levelIds)) {
            $levelIds = explode(',', $levelIds);
        }

        Match3LevelDef::whereIn('id', $levelIds)->update(['chapter' => $chapter]);
        return $this->success('移动成功');
    }
}
