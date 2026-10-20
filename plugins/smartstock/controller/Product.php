<?php
namespace plugin\smartstock\controller;


use wise\support\context\ContextualLogger;
use plugin\smartstock\model\Product as ProductModel;
use plugin\smartstock\model\ProductCategory;
use plugin\smartstock\model\Warehouse;
use plugin\smartstock\validate\ProductValidate;
use plugin\smartstock\service\ProductService;
class Product extends Base
{
    /**
     * 商品列表页
     */
    public function index()
    {
        $categories = ProductCategory::where('status', 1)
            ->order('sort_order', 'asc')
            ->select();

        $warehouses = Warehouse::where('status', 1)
            ->order('sort_order', 'asc')
            ->select();

        return $this->assign([
            'categories' => $categories,
            'warehouses' => $warehouses,
        ])->fetch();
    }

    /**
     * Bootstrap Table 分页数据接口
     */
    public function getList()
    {
        $params = $this->buildQueryParams();
        $page   = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 10);

        $query = (new ProductModel())->search($params)->with(['category']);

        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row = $item->toArray();
            $row['category_name'] = $item->category ? $item->category->name : '';
            $row['status_text']   = $item->status_text;
            $rows[] = $row;
        }

        return $this->success('', [
            'total' => $list->total(),
            'rows'  => $rows,
        ]);
    }

    /**
     * 新增/编辑商品
     */
    public function save()
    {
        $data = $this->request->post();

        // 验证
        $validate = new ProductValidate();
        if (!$validate->scene('save')->check($data)) {
            return $this->error($validate->getError());
        }

        $id = $data['id'] ?? 0;
        $specValues = $data['spec_values'] ?? [];

        // 字段白名单：防止批量赋值注入 status/delete_time 等字段
        $allowed = ['name', 'sku_code', 'category_id', 'unit_id', 'barcode', 'spec_summary', 'cost_price', 'selling_price', 'purchase_price', 'image', 'description', 'expiry_date', 'status'];
        $data = array_intersect_key($data, array_flip($allowed));

        try {
            $productService = new ProductService();

            if ($id > 0) {
                $product = ProductModel::find($id);
                if (!$product) {
                    return $this->error('商品不存在');
                }
                $product->save($data);
            } else {
                $product = new ProductModel();
                // 生成 SKU 编码
                if (empty($data['sku_code'])) {
                    $data['sku_code'] = $productService->generateSkuCode(
                        $data['name'],
                        $data['category_id'] ?? 0
                    );
                }
                $product->save($data);
            }

            // 保存规格值
            if (!empty($specValues)) {
                $productService->saveSpecValues($product->id, $specValues);
            }

            return $this->success('保存成功', ['id' => $product->id]);
        } catch (\Exception $e) {
            ContextualLogger::error('Product save error: ' . $e->getMessage());
            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除商品（软删除）
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
            // 有库存记录的商品不允许删除，避免库存数据悬挂
            $stockCount = \plugin\smartstock\model\Stock::whereIn('product_id', $ids)->count();
            if ($stockCount > 0) {
                return $this->error('存在库存记录的商品不能删除，请先清空相关库存');
            }

            ProductModel::destroy($ids);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            ContextualLogger::error('Product delete error: ' . $e->getMessage());
            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 商品搜索（选择器用）
     */
    public function search()
    {
        $keyword = $this->request->param('keyword', '');
        $categoryId = $this->request->param('category_id', 0);

        $query = ProductModel::where('status', 1);
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', "%{$keyword}%")
                  ->whereOrLike('sku_code', "%{$keyword}%");
            });
        }
        if (!empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        $list = $query->field('id, name, sku_code, spec_summary')
            ->limit(50)
            ->select()
            ->toArray();

        return $this->success('', $list);
    }
}
