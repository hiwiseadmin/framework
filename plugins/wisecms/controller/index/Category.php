<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\model\Category as CategoryModel;
use plugin\wisecms\model\Content as ContentModel;
use plugin\wisecms\service\content\ContentService;
use plugin\wisecms\service\security\UrlGuard;

/**
 * CMS 前台栏目页
 */
class Category extends Base
{
    public function index()
    {
        $catid = (int) $this->request->param('catid', 0);
        $alias = trim((string) $this->request->param('alias', ''));

        $query = CategoryModel::where('status', 1)->where('site_id', $this->siteId);
        $category = $catid > 0 ? $query->find($catid) : $query->where('alias', $alias)->find();

        if (!$category) {
            $this->abort404('栏目不存在');
        }

        // 外链栏目直接跳转
        // P1-A2：目标 URL 过协议白名单（http/https 或站内相对路径），
        // 阻断 javascript:/data: 等可被用于 XSS 或钓鱼的跳转目标；非法则按页面不存在处理
        if ((int) $category->type === 2 && !empty($category->url)) {
            if (!UrlGuard::isSafeScheme((string) $category->url)) {
                cms_log('warning', '栏目外链地址非法，已拒绝跳转: ' . (string) $category->url, ['catid' => $category->id]);
                $this->abort404('栏目外链地址非法');
            }

            return redirect($category->url);
        }

        // 单页栏目跳转对应单页
        if ((int) $category->type === 1) {
            return redirect($this->frontUrl('page/detail', ['catid' => $category->id]));
        }

        // 当前栏目 + 子栏目
        $catids = CategoryModel::where('parent_id', $category->id)
            ->where('status', 1)
            ->where('site_id', $this->siteId)
            ->column('id');
        $catids[] = $category->id;

        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = (int) ($category->list_rows ?: $this->getPluginConfig('list_rows', 15));

        $list = $this->withUrls(ContentModel::with(['category'])
            ->where('delete_time', null)
            ->where('status', ContentService::STATUS_PUBLISHED)
            ->where('site_id', $this->siteId)
            ->whereIn('catid', $catids)
            ->order('is_top', 'desc')
            ->order('create_time', 'desc')
            ->paginate(['page' => $page, 'list_rows' => $limit])
            ->toArray());

        // 规范 URL（伪静态开启时 ?catid= 访问会 301 归一）
        $this->canonicalize($this->urls()->category($category));

        $this->assign([
            'category' => $category,
            'list'     => $list,
            'pager'    => $this->pager(
                (int) ($list['total'] ?? 0),
                $page,
                (int) ($list['per_page'] ?? $limit),
                [],
                $this->urls()->paginationTemplate('category', $category)
            ),
        ]);

        return $this->fetch('index/category');
    }
}
