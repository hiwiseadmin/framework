<?php
namespace app\admin\controller;

use app\admin\validate\AttachmentCategory as AttachmentCategoryValidate;
use wise\attachment\model\AttachmentCategory as AttachmentCategoryModel;
use wise\attachment\model\AttachmentModel;

class AttachmentCategory extends BaseController
{
    /**
     * 分类管理首页
     */
    public function index()
    {
        return $this->fetch('attachment/category');
    }

    /**
     * 分类树列表
     * GET /admin/attachment_category/getList
     */
    public function getList()
    {
        $categories = AttachmentCategoryModel::getTree();
        return $this->success('获取成功', $categories);
    }

    /**
     * 添加分类
     * POST /admin/attachment_category/add
     */
    public function add()
    {
        $data = $this->request->post();

        try {
            validate(AttachmentCategoryValidate::class)
                ->scene('add')->check($data);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        try {
            $category = AttachmentCategoryModel::create([
                'name'        => $data['name'],
                'parent_id'   => $data['parent_id'] ?? 0,
                'icon'        => $data['icon'] ?? '',
                'description' => $data['description'] ?? '',
                'sort_order'  => $data['sort_order'] ?? 0,
                'is_system'   => 0,
            ]);

            return $this->success('添加成功', ['id' => $category->id]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新分类
     * POST /admin/attachment_category/update
     */
    public function update()
    {
        $id = $this->request->param('id/d', 0);
        $data = $this->request->post();

        if (!$id) {
            return $this->error('参数错误');
        }

        try {
            validate(AttachmentCategoryValidate::class)
                ->scene('edit')->check($data);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $category = AttachmentCategoryModel::find($id);
        if (!$category) {
            return $this->error('分类不存在');
        }

        // 检查不能将分类设置为自己或自己的子分类
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            $parentId = (int) $parentId;
            if ($parentId == $id) {
                return $this->error('不能将分类设置为自己的子分类');
            }
            $childIds = AttachmentCategoryModel::getAllChildIds($id);
            if (in_array($parentId, $childIds)) {
                return $this->error('不能将分类移动到自己的子分类下');
            }
        }

        try {
            $category->save([
                'name'        => $data['name'] ?? $category->name,
                'parent_id'   => $parentId ?? $category->parent_id,
                'icon'        => $data['icon'] ?? $category->icon,
                'description' => $data['description'] ?? $category->description,
                'sort_order'  => $data['sort_order'] ?? $category->sort_order,
            ]);

            return $this->success('更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除分类
     * POST /admin/attachment_category/delete
     */
    public function delete()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $category = AttachmentCategoryModel::find($id);
        if (!$category) {
            return $this->error('分类不存在');
        }

        // 检查是否有子分类
        $childCount = AttachmentCategoryModel::where('parent_id', $id)->count();
        if ($childCount > 0) {
            return $this->error('该分类下存在子分类，请先删除子分类');
        }

        // 检查是否有附件使用此分类
        $attachmentCount = AttachmentModel::where('category_id', $id)->count();
        if ($attachmentCount > 0) {
            return $this->error("该分类下有 {$attachmentCount} 个附件，请先移动附件");
        }

        $category->delete();
        return $this->success('删除成功');
    }

    /**
     * 获取分类详情
     * GET /admin/attachment_category/info
     */
    public function info()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $category = AttachmentCategoryModel::find($id);
        if (!$category) {
            return $this->error('分类不存在');
        }

        return $this->success('获取成功', $category->toArray());
    }

    /**
     * 获取分类下拉选项（扁平列表，含层级前缀）
     * GET /admin/attachment_category/options
     */
    public function options()
    {
        $tree = AttachmentCategoryModel::getTree();

        $options = [];
        $this->flattenTree($tree, $options);

        return $this->success('获取成功', $options);
    }

    /**
     * 递归扁平化分类树为下拉选项
     */
    private function flattenTree(array $tree, array &$options, int $level = 0): void
    {
        foreach ($tree as $node) {
            $prefix = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
            $options[] = [
                'id'    => $node['id'],
                'name'  => $prefix . $node['name'],
                'level' => $level,
            ];

            if (!empty($node['children'])) {
                $this->flattenTree($node['children'], $options, $level + 1);
            }
        }
    }
}
