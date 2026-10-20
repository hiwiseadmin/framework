<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Site as SiteModel;
use plugin\wisecms\service\model\MassAssignmentGuard;
use plugin\wisecms\service\site\SiteService;
use think\facade\Db;

class Site extends BaseController
{
    use PageFallbackTrait;

    public function index()
    {
        // ⚠️ 固定页长 15（不读 limit）→ 前端用 fixedPageSize 隐藏每页条数选择器
        $list   = SiteModel::order('sort', 'asc')->paginate(15);
        $result = $list->toArray();

        // UIUX-P0-25：附带「站点数据量」。
        // `SiteService::deleteSite()` 的删除闸门正是「内容 + 栏目 + 单页（**含回收站**）必须为 0」，
        // 把这个判断前置到列表，用户一眼能看出哪些站点可删 —— 而不是点了删除才被拒绝。
        // 注意：这里用原生查询（不过软删过滤）= 闸门里的 `withTrashed()` 口径，两者必须一致。
        $ids    = array_values(array_filter(array_map('intval', array_column($result['data'], 'id'))));
        $counts = [];

        if ($ids !== []) {
            $maps = [
                'content'  => 'wisecms_content',
                'category' => 'wisecms_category',
                'page'     => 'wisecms_page',
            ];
            foreach ($maps as $key => $table) {
                $rows = Db::name($table)
                    ->whereIn('site_id', $ids)
                    ->field('site_id, COUNT(*) AS c')
                    ->group('site_id')
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $counts[(int) $row['site_id']][$key] = (int) $row['c'];
                }
            }
        }

        $result['data'] = array_map(static function (array $row) use ($counts): array {
            $id = (int) $row['id'];
            $row['data_counts'] = [
                'content'  => $counts[$id]['content'] ?? 0,
                'category' => $counts[$id]['category'] ?? 0,
                'page'     => $counts[$id]['page'] ?? 0,
            ];

            return $row;
        }, $result['data']);

        if ($this->request->isAjax()) {
            return $this->success('', $result);
        }

        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $result);
        return $this->fetch('admin/site/index');
    }

    public function create()
    {
        if ($this->request->isPost()) {
            // P1-1：黑名单剔除 id / create_time / update_time 等非表单字段。
            // Site 模型 autoWriteTimestamp=true，两个时间列由模型自动写入；
            // 原实现手写 time() 整数会写坏 datetime 列（严格模式下报错）。
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_site');
            \plugin\wisecms\model\Site::create($data);
            return $this->success('站点创建成功');
        }
        return $this->fetch('admin/site/create');
    }

    public function edit()
    {
        $id = (int)$this->request->param('id');
        $site = \plugin\wisecms\model\Site::find($id);
        if (!$site) return $this->pageFallback('站点不存在', plugin_url('wisecms/site/index'));
        if ($this->request->isPost()) {
            // P1-1：同上；update_time 由模型 autoWriteTimestamp 维护。
            // P1-30：`setting`/`seo_setting` 以 `setting[key]` 数组提交，`$json` 属性自动编码；
            // 二者不在 MassAssignmentGuard 黑名单（wisecms_site => []），可直接写入。
            $site->save(MassAssignmentGuard::filter($this->request->post(), 'wisecms_site'));
            return $this->success('站点更新成功');
        }
        $this->assign('site', $site);
        // P1-30：归一化 JSON 列（null → []），供模板编辑器安全渲染（避免 null 上取子键）
        $this->assign('siteSetting', is_array($site->setting) ? $site->setting : []);
        $this->assign('seoSetting', is_array($site->seo_setting) ? $site->seo_setting : []);
        return $this->fetch('admin/site/edit');
    }

    /**
     * 站点选择器数据源（P2 后台站点选择器）
     *
     * 供每个 wisecms 后台页的顶栏下拉框调用：返回启用站点列表 + 当前站点。
     */
    public function options()
    {
        return $this->success('', (new SiteService())->getSwitchOptions());
    }

    /**
     * 切换当前站点（P2 后台站点选择器）
     *
     * 只切换**后台编辑上下文**（会话级），不修改任何业务数据；
     * 因此权限上与「站点增删改」分离，可单独授予内容编辑者
     * （manifest：plugin.wisecms.action.site.context）。
     */
    public function switch()
    {
        $siteId = (int) $this->request->post('site_id', 0);
        if ($siteId <= 0) {
            return $this->error('参数错误');
        }

        try {
            $site = (new SiteService())->switchTo($siteId);
        } catch (WisecmsException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('已切换到「' . (string) $site['name'] . '」', [
            'site_id'   => (int) $site['id'],
            'site_name' => (string) $site['name'],
        ]);
    }

    public function delete()
    {
        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            return $this->error('参数错误');
        }

        // P1-C：改由服务层执行「有内容则拒绝 + 级联清理站点附属数据」，
        // 原实现直接 Site::destroy() 会留下大量 site_id 悬空行
        try {
            (new SiteService())->deleteSite($id);
        } catch (WisecmsException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('站点已删除');
    }
}
