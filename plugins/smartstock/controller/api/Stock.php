<?php
namespace plugin\smartstock\controller\api;

use plugin\ApiController;
use plugin\smartstock\model\Stock as StockModel;

/**
 * SmartStock 库存 API 控制器
 *
 * GET /api/smartstock/stock/search — 库存查询
 *
 * @author 齐活林 (Qi)
 * @date   2026-06-19
 */
class Stock extends ApiController
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
     * 按商品/仓库搜索库存
     *
     * GET /api/smartstock/stock/search
     *
     * @query product_id    int    商品ID
     * @query warehouse_id  int    仓库ID
     * @query keyword       string 商品名称模糊搜索
     * @query page          int    页码（默认1）
     * @query pageSize      int    每页条数（默认20）
     */
    public function search()
    {
        $productId   = $this->request->param('product_id/d', 0);
        $warehouseId = $this->request->param('warehouse_id/d', 0);
        $keyword     = $this->request->param('keyword', '');
        $page        = $this->request->param('page/d', 1);
        $pageSize    = $this->request->param('pageSize/d', 20);

        $query = StockModel::with(['product', 'warehouse'])
            ->where('quantity', '>', 0);

        if ($productId > 0) {
            $query->where('product_id', $productId);
        }
        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);
        }
        if (!empty($keyword)) {
            $query->whereHas('product', function ($q) use ($keyword) {
                $q->whereLike('name', "%{$keyword}%");
            });
        }

        $list = $query->order('quantity', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        $rows = [];
        foreach ($list->items() as $item) {
            $rows[] = [
                'id'              => $item->id,
                'product_id'      => $item->product_id,
                'product_name'    => $item->product->name ?? '',
                'product_sku'     => $item->product->sku_code ?? '',
                'warehouse_id'    => $item->warehouse_id,
                'warehouse_name'  => $item->warehouse->name ?? '',
                'quantity'        => $item->quantity,
                'locked_quantity' => $item->locked_quantity ?? 0,
                'available'       => max(0, $item->quantity - ($item->locked_quantity ?? 0)),
                'update_time'     => $item->update_time,
            ];
        }

        return $this->success('ok', [
            'total' => $list->total(),
            'page'  => $page,
            'rows'  => $rows,
        ]);
    }
}
