<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3Element;
use wise\support\context\ContextualLogger;

/**
 * 元素/障碍物字典管理（§8.2）
 */
class Element extends Base
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

        $query = Match3Element::where('status', 1);
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('code', "%{$keyword}%")->whereOrLike('name', "%{$keyword}%");
            });
        }

        $list = $query->order('sort_order', 'asc')
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

        $allowed = ['code', 'name', 'kind', 'color', 'weight', 'clear_rule', 'icon', 'sort_order', 'status'];
        $data = array_intersect_key($data, array_flip($allowed));

        if (empty($data['code']) || empty($data['name'])) {
            return $this->error('元素编码与名称不能为空');
        }

        try {
            if ($id > 0) {
                $element = Match3Element::find($id);
                if (!$element) {
                    return $this->error('元素不存在');
                }
                $element->save($data);
            } else {
                $element = new Match3Element();
                $element->save($data);
            }
            return $this->success('保存成功', ['id' => $element->id]);
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] Element save error: ' . $e->getMessage());
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的元素');
        }
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            Match3Element::whereIn('id', $ids)->update(['status' => 0]);
            return $this->success('删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    protected function kindText(int $kind): string
    {
        return [
            Match3Element::KIND_NORMAL   => '普通色块',
            Match3Element::KIND_COLLECT  => '收集目标',
            Match3Element::KIND_OBSTACLE => '障碍物',
            Match3Element::KIND_SPECIAL  => '特殊块',
        ][$kind] ?? '未知';
    }
}
