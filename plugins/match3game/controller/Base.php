<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\BaseController;

/**
 * 消消乐插件后台控制器基类
 *
 * 继承链：plugin\BaseController → app\common\controller\BaseController
 * 提供：JSON 请求体合并、插件配置注入视图。
 */
class Base extends BaseController
{
    protected $noNeedLogin = [];
    protected $noNeedAuth  = [];

    protected function initPlugin(): void
    {
        // 前端统一用 application/json 提交，合并进 $_POST
        $this->mergeJsonBody();

        // 注入插件配置到视图
        $this->assign('match3Config', $this->getPluginConfig());
    }

    /**
     * 将 application/json 请求体并入 POST 参数
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
