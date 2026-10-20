<?php
namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\ProductCategory as ProductCategoryModel;
use plugin\smartstock\validate\ProductCategoryValidate;
use wise\support\tools\Tree;
class ProductCategory extends Base
{
    protected $noNeedAuth = ['tree'];

    /**
     * 分类列表页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 分类树形 JSON
     */
    public function tree()
    {
        $categories = ProductCategoryModel::where('status', 1)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $tree = Tree::instance()->init($categories, 'parent_id');

        return $this->success('', $tree->getTreeArray(0));
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        $params = $this->buildQueryParams();
        $page   = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 10);

        $query = (new ProductCategoryModel())->search($params);

        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $rows[] = $item->toArray();
        }

        return $this->success('', [
            'total' => $list->total(),
            'rows'  => $rows,
        ]);
    }

    /**
     * 新增/编辑分类
     */
    public function save()
    {
        $data = $this->request->post();

        // 验证
        $validate = new ProductCategoryValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        $id = $data['id'] ?? 0;

        try {
            if ($id > 0) {
                $category = ProductCategoryModel::find($id);
                if (!$category) {
                    return $this->error('分类不存在');
                }
                $category->save($data);
            } else {
                $category = new ProductCategoryModel();
                $category->save($data);
            }

            return $this->success('保存成功', ['id' => $category->id]);
        } catch (\Exception $e) {
            ContextualLogger::error('ProductCategory save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除分类（软删除）
     */
    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的数据');
        }

        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            // 检查是否有子分类
            $childrenCount = ProductCategoryModel::whereIn('parent_id', $ids)->count();
            if ($childrenCount > 0) {
                return $this->error('存在子分类，请先删除子分类');
            }

            ProductCategoryModel::destroy($ids);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            ContextualLogger::error('ProductCategory delete error: ' . $e->getMessage());
            return $this->error('删除失败，请稍后重试');
        }
    }
}
