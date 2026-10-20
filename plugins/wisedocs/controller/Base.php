<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use app\common\middleware\VerifyCsrfToken;
use plugin\BaseController;
use plugin\wisedocs\middleware\DocsAuth;

/**
 * WiseDocs 后台控制器基类（P0-2）
 *
 * 所有 WiseDocs **后台管理**控制器应继承此类，从而自动获得：
 * - 登录态 + 权限校验（`DocsAuth` 中间件，本插件兜底方案）
 * - 与前台 `plugin\FrontController` 体系明确的边界（前台控制器不继承本类，因此不受守护）
 *
 * 继承链：app\BaseController → app\common\controller\BaseController → plugin\BaseController → 本类
 *
 * 中间件注册方式：PluginDispatch 会读取控制器的 `$middleware` 属性并注册为
 * 控制器级中间件（可靠，不受进程/请求时序影响），因此本类只需声明即可生效。
 */
abstract class Base extends BaseController
{
    /** 默认：全部动作需登录 */
    protected $noNeedLogin = [];

    /** 默认：全部动作需权限校验 */
    protected $noNeedAuth = [];

    /** 兼容部分插件的 `noNeedRight` 命名 */
    protected $noNeedRight = [];

    /**
     * 控制器级中间件
     *
     * 顺序与 `app/admin/middleware.php` 一致：**鉴权在前、CSRF 在后**（先确认身份，
     * 再校验请求来源，避免匿名请求拿到 419 而非 401/403 的语义混淆）。
     *
     * - `DocsAuth`（P0-2）：插件层兜底的后台登录 + 权限校验；
     * - `VerifyCsrfToken`（H1）：写请求（POST/PUT/DELETE/PATCH）校验 CSRF 令牌，
     *   只读请求仅补齐 Token。令牌由前端自动注入（`wise-util.js` 的 `X-CSRF-TOKEN`
     *   注入 + `statics/js/common/util.js` 的 `$.ajaxSetup` 兜底 jQuery 路径），
     *   页面侧由 `extend/plugin/view/base.html` 的 `{:csrf_meta()}` 提供。
     */
    protected $middleware = [
        DocsAuth::class,
        VerifyCsrfToken::class,
    ];

    /**
     * 插件初始化（plugin\BaseController::initialize() 第 5 步调用）
     */
    protected function initPlugin(): void
    {
        // 前端统一使用 wise-util 的 http（application/json 提交），
        // 而 ThinkPHP 的 request->post() 只解析 form-encoded 报文。
        // 这里把 JSON 报文体并入 POST，控制器内 request->post() 无需改动。
        $this->mergeJsonBody();
    }

    /**
     * 将 application/json 报文体并入 POST（幂等：仅 JSON 且解析成功时合并，表单字段优先）
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

    /**
     * Bootstrap Table 分页参数（EnhancedTable 发送 pageNumber / pageSize）
     *
     * @return array{0:int,1:int} [page, size]
     */
    protected function pageParams(int $defaultSize = 20, int $maxSize = 200): array
    {
        $page = max(1, (int) $this->request->param('pageNumber/d', 1));
        $size = (int) $this->request->param('pageSize/d', $defaultSize);

        if ($size < 1) {
            $size = $defaultSize;
        }
        if ($maxSize > 0 && $size > $maxSize) {
            $size = $maxSize;
        }

        return [$page, $size];
    }

    /**
     * 列表响应（EnhancedTable 约定 data.{total, rows}）
     */
    protected function listResponse(int $total, array $rows)
    {
        return $this->success('', ['total' => $total, 'rows' => $rows]);
    }
}
