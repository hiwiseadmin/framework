<?php
namespace plugin\wisecms\controller\index;

use plugin\wisecms\model\FriendLink as FriendLinkModel;
use plugin\wisecms\service\content\FriendLinkService;

/**
 * CMS 前台友情链接（B-1）
 *
 * 独立友链页 /cms/link/index 与点击计数跳转 /cms/link/go。
 */
class Link extends Base
{
    /**
     * 独立友链页：按 group_name 分组展示
     */
    public function index()
    {
        $links = (new FriendLinkService())->forFront($this->siteId);

        $grouped = [];
        foreach ($links as $row) {
            $group = trim((string) ($row['group_name'] ?? ''));
            $grouped[$group === '' ? '默认' : $group][] = $row;
        }

        $this->canonicalize($this->urls()->absolute('cms/link/index'));

        $this->assign([
            'linkGroups' => $grouped,
            'showDesc'   => (bool) $this->getPluginConfig('friendlink_show_desc', false),
        ]);

        return $this->fetch('index/link');
    }

    /**
     * 点击计数并 302 跳转
     *
     * 只收 id、只从库内取 URL，禁止 redirect($_GET['url'])（开放重定向，§8.3）。
     */
    public function go()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            $this->abort404('链接不存在');
        }

        $row = FriendLinkModel::where('id', $id)
            ->where('site_id', $this->siteId)
            ->where('status', 1)
            ->find();

        if (!$row) {
            $this->abort404('链接不存在');
        }

        $url = FriendLinkService::normalizeUrl((string) $row->url);
        if ($url === null) {
            $this->abort404('链接地址非法');
        }

        (new FriendLinkService())->incrementClick($id);

        return redirect($url, 302);
    }
}
