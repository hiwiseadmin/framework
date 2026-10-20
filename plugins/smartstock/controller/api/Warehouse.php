<?php
namespace plugin\smartstock\controller\api;

use plugin\ApiController;
use plugin\smartstock\model\Warehouse as WarehouseModel;

/**
 * SmartStock 仓库 API 控制器
 *
 * GET /api/smartstock/warehouse/list — 仓库列表
 *
 * @author 齐活林 (Qi)
 * @date   2026-06-19
 */
class Warehouse extends ApiController
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
     * 仓库列表（仅启用的）
     *
     * GET /api/smartstock/warehouse/list
     */
    public function list()
    {
        $list = WarehouseModel::where('status', 1)
            ->field('id,name,code,address,contact,phone')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        return $this->success('ok', $list);
    }
}
