<?php
namespace plugin\smartstock\controller;

use plugin\BaseController;

/**
 * SmartStock 插件控制器基类
 *
 * 继承链：
 *   plugin\smartstock\controller\Base
 *     -> plugin\BaseController (插件基类，模板路径、pluginUrl)
 *       -> app\common\controller\BaseController (全局基类，RBAC中间件、JSON响应trait)
 *
 * 自动处理：
 * - 插件视图路径设置
 * - 权限验证（RbacMiddleware）
 * - CSRF 令牌验证
 * - JSON 响应（success/error）
 * - 请求参数构建（buildQueryParams）
 */
class Base extends BaseController
{
    protected $noNeedLogin      = [];
    protected $noNeedAuth       = [];
    protected $noNeedSelfAuthId = [];

    /**
     * 插件初始化
     */
    protected function initPlugin(): void
    {
        // 前端统一使用 Wise.util.http（wise-util.js），POST 以 application/json 提交，
        // 而 ThinkPHP 的 request->post() 只解析 form-encoded 报文。
        // 这里把 JSON 报文体并入 $_POST，控制器原有的 request->post() 无需改动。
        $this->mergeJsonBody();

        // 加载插件公共配置到视图
        $this->assign('smartstockConfig', $this->getPluginConfig());

        // 加载公共分类数据
        $this->assign('stockInTypes', config('smartstock.app.stock_in_types', []));
        $this->assign('stockOutTypes', config('smartstock.app.stock_out_types', []));
    }

    /**
     * 将 application/json 请求体并入 POST 参数
     *
     * 幂等：仅在 Content-Type 为 JSON 且解析成功时合并，已存在的表单字段优先。
     */
    protected function mergeJsonBody(): void
    {
        if (!$this->request->isPost() && !$this->request->isPut()) {
            return;
        }

        if (!str_contains((string) $this->request->contentType(), 'json')) {
            return;
        }

        $raw = $this->request->getInput();
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        $this->request->withPost(array_merge($decoded, $this->request->post()));
    }
}
