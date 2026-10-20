<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Category as CategoryModel;
use plugin\wisedocs\model\Document;
use plugin\wisedocs\model\Project as ProjectModel;
use plugin\wisedocs\validate\Category as CategoryValidate;

/**
 * 分类管理（P2-2）
 *
 * 树形分类：支持父级选择、排序、状态；删除前校验子分类与文档。
 */
class Category extends Base
{
    public function index()
    {
        return $this->assign([
            'spaces' => ProjectModel::order('sort_order DESC, id ASC')->select(),
        ])->fetch();
    }

    /**
     * 分类列表（树形表格：全量返回，不再分页）
     *
     * 排序与拖拽排序的客户端重排保持一致：parent_id ASC, sort ASC, id ASC。
     * 每行额外输出 `sort`（与 `sort_order` 同源），供拖拽按钮与框架重排使用。
     */
    public function getList()
    {
        $keyword   = trim((string) $this->request->param('keyword', ''));
        $status    = $this->request->param('status', '');
        $projectId = (int) $this->request->param('project_id/d', 0);

        $query = CategoryModel::order('parent_id ASC, sort_order ASC, id ASC');

        if ($keyword !== '') {
            $query->whereLike('name|slug', "%{$keyword}%");
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $rows = [];
        foreach ($query->select() as $item) {
            $row                = $item->toArray();
            $row['sort']        = (int) $item->sort_order;
            $row['status_text'] = (int) $item->status === 1 ? '启用' : '禁用';
            $rows[]             = $row;
        }

        return $this->listResponse(count($rows), $rows);
    }

    /**
     * 分类树（父级选择器）
     */
    public function tree()
    {
        $projectId = (int) $this->request->param('project_id/d', 0);

        $query = CategoryModel::order('sort_order DESC, id ASC');
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $items = $query->field('id,parent_id,name,level')->select()->toArray();

        return $this->success('', $this->buildTree($items, 0));
    }

    /**
     * 新增 / 编辑
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        // slug 为空时自动生成（验证器要求必填）
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = $this->makeSlug((string) $data['name'], $id);
        }

        $validate = new CategoryValidate();
        if (!$validate->scene($id > 0 ? 'update' : 'create')->check($data)) {
            return $this->error($validate->getError());
        }

        $fields = [
            'project_id'  => (int) ($data['project_id'] ?? 0),
            'parent_id'   => (int) ($data['parent_id'] ?? 0),
            'name'        => (string) ($data['name'] ?? ''),
            'slug'        => (string) ($data['slug'] ?? ''),
            'icon'        => (string) ($data['icon'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'status'      => (int) ($data['status'] ?? 1),
        ];

        try {
            if ($id > 0) {
                $model = CategoryModel::find($id);
                if (!$model) {
                    return $this->error('分类不存在');
                }
                $model->save($fields);
            } else {
                $model = CategoryModel::create($fields);
                $id    = (int) $model->id;
            }

            // 维护 level / path
            (new CategoryModel())->updatePath($id);

            return $this->success('保存成功', ['id' => $id]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] category save failed: ' . $e->getMessage());

            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除（存在子分类或文档时拒绝）
     */
    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids = array_map('intval', (array) $this->request->post('ids/a', []));
        if (empty($ids)) {
            return $this->error('请选择要删除的数据');
        }

        try {
            $children = CategoryModel::whereIn('parent_id', $ids)->count();
            if ($children > 0) {
                return $this->error('存在子分类，请先删除子分类');
            }

            $documents = Document::whereIn('category_id', $ids)->count();
            if ($documents > 0) {
                return $this->error('分类下存在文档，无法删除');
            }

            CategoryModel::destroy($ids);

            return $this->success('删除成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] category delete failed: ' . $e->getMessage());

            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 拖拽排序（仅同级）
     *
     * 只调整与被拖拽节点「同一父级 + 同一空间」的兄弟顺序，**不改变 parent_id**，
     * 因此天然不存在「挂到自身子孙下」的成环风险。请求里的 `pid` 仅作参考，
     * 以库内 `changeid` 的 parent_id 为准，避免前端篡改。
     *
     * 前端框架（enhanced-table.js）约定参数：ids(可见顺序 CSV) / changeid / pid / field / pk。
     */
    public function sort()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $changeId = (int) $this->request->post('changeid/d', 0);
        if ($changeId <= 0) {
            return $this->error('参数错误');
        }

        $current = CategoryModel::find($changeId);
        if (!$current) {
            return $this->error('分类不存在');
        }

        // 同级（同父级 + 同空间）参与排序
        $siblings = CategoryModel::where('parent_id', (int) $current->parent_id)
            ->where('project_id', (int) $current->project_id)
            ->order('sort_order ASC, id ASC')
            ->column('id');

        // 前端可见顺序里只取同级项；未出现的同级项按原序兜底追加，保证不丢
        $visibleIds = [];
        foreach (explode(',', (string) $this->request->post('ids', '')) as $raw) {
            $id = (int) trim($raw);
            if ($id > 0) {
                $visibleIds[] = $id;
            }
        }

        $ordered = [];
        foreach ($visibleIds as $id) {
            if (in_array($id, $siblings, true) && !in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        foreach ($siblings as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        try {
            \think\facade\Db::transaction(function () use ($ordered) {
                foreach ($ordered as $index => $id) {
                    CategoryModel::where('id', $id)->update(['sort_order' => $index]);
                }
            });

            return $this->success('排序已保存');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] category sort failed: ' . $e->getMessage());

            return $this->error('排序失败，请稍后重试');
        }
    }

    /**
     * 行内启用 / 禁用（供 EnhancedTable 的 toggle formatter 调用）
     */
    public function status()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 1);

        if ($id <= 0 || !in_array($status, [0, 1], true)) {
            return $this->error('参数错误');
        }

        $model = CategoryModel::find($id);
        if (!$model) {
            return $this->error('分类不存在');
        }

        try {
            $model->save(['status' => $status]);

            return $this->success($status === 1 ? '已启用' : '已禁用');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] category status failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 递归构建分类树
     */
    protected function buildTree(array $items, int $parentId): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int) $item['parent_id'] === $parentId) {
                // children 恒定存在（叶子为 []）：与 HelpService::buildTree 同约定，
                // 避免消费方按「键是否存在」判断时踩空
                $item['children'] = $this->buildTree($items, (int) $item['id']);
                $tree[] = $item;
            }
        }

        return $tree;
    }

    /**
     * 生成唯一 slug（纯中文等无法生成时给出兜底）
     */
    protected function makeSlug(string $name, int $excludeId = 0): string
    {
        // 仅保留 ASCII 字母/数字，其余（中文、空格、符号）折叠为 '-'
        // 不能用 /[^\w]+/u：UTF-8 下 \w 会匹配中文，生成含中文的标识会被 alphaDash 拒绝。
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'cat-' . substr(md5($name . microtime(true)), 0, 8);
        }

        $base = $slug;
        $i    = 1;
        while (CategoryModel::where('slug', $slug)->where('id', '<>', $excludeId)->count() > 0) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
