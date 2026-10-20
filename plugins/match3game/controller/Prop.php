<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3Prop;
use wise\support\context\ContextualLogger;

/**
 * 道具配置管理（§8.2）
 */
class Prop extends Base
{
    public function index()
    {
        return $this->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $keyword  = $this->request->param('keyword', '');

        $query = Match3Prop::where('status', 1);
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('code', "%{$keyword}%")->whereOrLike('name', "%{$keyword}%");
            });
        }

        $list = $query->order('id', 'asc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['kind_text'] = $this->kindText((int) $row['kind']);
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    public function save()
    {
        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        $allowed = ['code', 'name', 'kind', 'effect', 'price', 'ad_unit_id', 'per_battle_limit', 'icon', 'status'];
        $data = array_intersect_key($data, array_flip($allowed));

        if (empty($data['code']) || empty($data['name'])) {
            return $this->error('道具编码与名称不能为空');
        }

        foreach (['effect', 'price'] as $f) {
            if (isset($data[$f]) && is_string($data[$f])) {
                $data[$f] = json_decode($data[$f], true);
                if ($data[$f] === null) {
                    return $this->error("字段 {$f} 的 JSON 格式非法");
                }
            }
        }

        try {
            if ($id > 0) {
                $prop = Match3Prop::find($id);
                if (!$prop) {
                    return $this->error('道具不存在');
                }
                $prop->save($data);
            } else {
                $prop = new Match3Prop();
                $prop->save($data);
            }
            return $this->success('保存成功', ['id' => $prop->id]);
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] Prop save error: ' . $e->getMessage());
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的道具');
        }
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            Match3Prop::whereIn('id', $ids)->update(['status' => 0]);
            return $this->success('删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    protected function kindText(int $kind): string
    {
        return [
            Match3Prop::KIND_START  => '开局道具',
            Match3Prop::KIND_BATTLE => '局内道具',
            Match3Prop::KIND_PACK   => '购买资源包',
        ][$kind] ?? '未知';
    }
}
