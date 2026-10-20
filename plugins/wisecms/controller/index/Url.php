<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\controller\index\Category as CategoryController;
use plugin\wisecms\controller\index\Content as ContentController;
use plugin\wisecms\controller\index\Page as PageController;
use plugin\wisecms\controller\index\Tag as TagController;
use plugin\wisecms\model\UrlAlias;
use plugin\wisecms\service\security\UrlGuard;
use plugin\wisecms\service\url\UrlService;

/**
 * 伪静态统一入口
 *
 * route/index.php 的 catch-all 规则把所有 /cms/{path} 请求交给本控制器，
 * 由 UrlService 依据「别名索引 + 规则正则 + 手工301」解析出实体再转发渲染；
 * 历史 URL 命中时返回 301 到当前规范 URL。
 */
class Url extends Base
{
    public function dispatch()
    {
        $path = trim((string) $this->request->param('path', ''));
        if ($path === '') {
            $path = (string) $this->request->pathinfo();
        }

        $service = new UrlService();
        $result  = $service->resolvePath($path, $this->siteId);

        if (!$result) {
            $this->abort404('页面不存在');
        }

        // 历史 URL / 手工 301
        // P1-A2：目标来自库内（url_alias 历史 / 手工 301 target_url），
        // 过协议白名单后再跳转，避免 javascript:/data: 等被当作跳转目标
        if (!empty($result['redirect'])) {
            if (!UrlGuard::isSafeScheme((string) $result['redirect'])) {
                cms_log('warning', '重定向目标非法，已拒绝跳转: ' . (string) $result['redirect'], ['path' => $path]);
                $this->abort404('页面不存在');
            }

            return redirect($service->absolute($result['redirect']), 301);
        }

        $id   = (int) ($result['entity_id'] ?? 0);
        $page = max(1, (int) ($result['page'] ?? 1));

        return match ($result['entity_type'] ?? '') {
            UrlAlias::TYPE_CONTENT  => $this->forward(ContentController::class, 'detail', ['id' => $id]),
            UrlAlias::TYPE_CATEGORY => $this->forward(CategoryController::class, 'index', ['catid' => $id, 'page' => $page]),
            UrlAlias::TYPE_TAG      => $this->forward(TagController::class, 'index', ['id' => $id, 'page' => $page]),
            UrlAlias::TYPE_PAGE     => $this->forward(PageController::class, 'detail', ['id' => $id]),
            default                 => $this->abort404('页面不存在'),
        };
    }

    /**
     * 内部转发：把解析出的参数注入路由变量后调用目标控制器方法
     */
    protected function forward(string $controllerClass, string $action, array $params = [])
    {
        if (!empty($params)) {
            $this->request->setRoute($params);
        }

        $controller = $this->app->make($controllerClass);

        return $controller->{$action}();
    }
}
