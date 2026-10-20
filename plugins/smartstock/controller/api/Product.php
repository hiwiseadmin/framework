<?php
namespace plugin\smartstock\controller\api;

use plugin\ApiController;
use plugin\smartstock\model\Product as ProductModel;
use plugin\smartstock\model\Stock as StockModel;

/**
 * SmartStock 商品 API 控制器
 *
 * GET /api/smartstock/product/search — 商品搜索
 * GET /api/smartstock/product/detail — 商品详情
 *
 * @author 齐活林 (Qi)
 * @date   2026-06-19
 */
class Product extends ApiController
{
    /**
     * 中间件：JWT 认证
     *
     * @var array
     */
    protected $middleware = [
        \wise\member\middleware\JwtAuth::class,
    ];

    /**
     * 商品搜索（下拉选择器 / 关键词）
     *
     * GET /api/smartstock/product/search
     *
     * @query keyword   string 关键词
     * @query limit     int    返回条数（默认20）
     */
    public function search()
    {
        $keyword = $this->request->param('keyword', '');
        $limit   = $this->request->param('limit/d', 20);

        $query = ProductModel::where('status', 1)
            ->field('id,name,sku_code,category_id,unit_id,selling_price,image')
            ->order('id', 'desc')
            ->limit($limit);

        if (!empty($keyword)) {
            $query->whereLike('name', "%{$keyword}%");
        }

        $list = $query->select()->toArray();

        return $this->success('ok', $list);
    }

    /**
     * 商品详情
     *
     * GET /api/smartstock/product/detail
     *
     * @query id int 商品ID
     */
    public function detail()
    {
        $id = $this->request->param('id/d', 0);
        if ($id <= 0) {
            return $this->error('缺少商品ID');
        }

        $product = ProductModel::with(['category', 'stock'])->find($id);
        if (!$product) {
            return $this->error('商品不存在');
        }

        $data = $product->toArray();

        // 附加各仓库库存
        $data['stocks'] = StockModel::where('product_id', $id)
            ->with(['warehouse'])
            ->select()
            ->toArray();

        return $this->success('ok', $data);
    }
}
