<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\model\Page as PageModel;
use think\facade\Db;

/**
 * CMS 前台单页
 */
class Page extends Base
{
    public function detail()
    {
        $id    = (int) $this->request->param('id', 0);
        $alias = trim((string) $this->request->param('alias', ''));
        $catid = (int) $this->request->param('catid', 0);

        // P1-B3：站点隔离 —— 单页必须归属当前站点，否则可被 ?id=/?alias= 跨站读取
        $query = PageModel::where('status', 1)->where('site_id', $this->siteId);

        if ($id > 0) {
            $page = $query->find($id);
        } elseif ($alias !== '') {
            $page = $query->where('alias', $alias)->find();
        } elseif ($catid > 0) {
            $page = $query->where('catid', $catid)->find();
        } else {
            $page = null;
        }

        if (!$page) {
            $this->abort404('单页不存在或未发布');
        }

        // 规范 URL（伪静态开启时 ?id=/?alias= 访问会 301 归一）
        $this->canonicalize($this->urls()->page($page));

        PageModel::where('id', $page->id)->update([
            'view_count' => Db::raw('view_count+1'),
        ]);

        $this->assign('page', $page);

        return $this->fetch('index/page');
    }
}
