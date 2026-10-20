<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Category;
use plugin\wisedocs\model\Document;
use plugin\wisedocs\model\Project as ProjectModel;
use plugin\wisedocs\service\SpaceService;
use plugin\wisedocs\validate\Space as SpaceValidate;

/**
 * 空间管理（P2-1）
 *
 * 模型为 Project（表 wisedocs_projects），路由控制器名为 space（见 manifest 菜单）。
 */
class Space extends Base
{
    public function index()
    {
        return $this->fetch();
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        [$page, $size] = $this->pageParams();

        $keyword = trim((string) $this->request->param('keyword', ''));
        $status  = $this->request->param('status', '');

        $query = ProjectModel::order('sort_order DESC, id DESC');

        if ($keyword !== '') {
            $query->whereLike('name|code', "%{$keyword}%");
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->page($page, $size)->select();

        // 空间维度的文档数 / 分类数（列表列展示，一次聚合避免 N+1）
        // 注意：必须先在聚合查询上 select()，再由 Collection::column() 取键值。
        // 直接链式 ->column('cnt', 'project_id') 会用 `cnt` 覆盖 field()，
        // 生成 `SELECT cnt FROM ...` 而报 "Unknown column 'cnt'"。
        $docCounts = Document::field('project_id, COUNT(*) AS cnt')
            ->group('project_id')->select()->column('cnt', 'project_id');
        $catCounts = Category::field('project_id, COUNT(*) AS cnt')
            ->group('project_id')->select()->column('cnt', 'project_id');

        $rows = [];
        foreach ($list as $item) {
            $row                   = $item->toArray();
            $row['status_text']    = (int) $item->status === 1 ? '启用' : '禁用';
            $row['document_count'] = (int) ($docCounts[$item->id] ?? 0);
            $row['category_count'] = (int) ($catCounts[$item->id] ?? 0);
            $rows[]                = $row;
        }

        return $this->listResponse($total, $rows);
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

        $validate = new SpaceValidate();
        if (!$validate->scene($id > 0 ? 'update' : 'create')->check($data)) {
            return $this->error($validate->getError());
        }

        // 字段白名单，避免批量赋值
        $fields = [
            'name'        => (string) ($data['name'] ?? ''),
            'code'        => (string) ($data['code'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'logo'        => (string) ($data['logo'] ?? ''),
            'theme'       => (string) ($data['theme'] ?? 'default'),
            'domain'      => (string) ($data['domain'] ?? ''),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'status'      => (int) ($data['status'] ?? 1),
        ];

        try {
            if ($id > 0) {
                $model = ProjectModel::find($id);
                if (!$model) {
                    return $this->error('空间不存在');
                }
                $model->save($fields);
            } else {
                $model = ProjectModel::create($fields);
                $id    = (int) $model->id;
            }

            (new SpaceService($this->request))->clearCache();

            return $this->success('保存成功', ['id' => $id]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] space save failed: ' . $e->getMessage());

            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除（存在分类或文档时拒绝）
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
            if (Category::whereIn('project_id', $ids)->count() > 0) {
                return $this->error('该空间下存在分类，无法删除');
            }
            if (Document::whereIn('project_id', $ids)->count() > 0) {
                return $this->error('该空间下存在文档，无法删除');
            }

            ProjectModel::destroy($ids);

            (new SpaceService($this->request))->clearCache();

            return $this->success('删除成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] space delete failed: ' . $e->getMessage());

            return $this->error('删除失败，请稍后重试');
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

        $model = ProjectModel::find($id);
        if (!$model) {
            return $this->error('空间不存在');
        }

        try {
            $model->save(['status' => $status]);

            (new SpaceService($this->request))->clearCache();

            return $this->success($status === 1 ? '已启用' : '已禁用');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] space status failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }
}
