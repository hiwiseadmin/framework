<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\model\Menu;
use wise\operationlog\attribute\OperationLog;
use wise\plugin\adapter\MenuRegistrar;

/**
 * 插件菜单布局（摆放）管理
 *
 * 归属三选一（由系统按菜单结构自动分流，管理员可手动覆盖）：
 *   - extend   插件扩展（单顶级菜单插件默认）
 *   - launcher 启动台（多顶级菜单插件默认）
 *   - pinned   常用插件区（多顶级插件被用户钉选）
 *
 * 覆盖结果直接写入 menus 表插件根节点（parent_id / pinned），
 * Index 渲染时自动生效；插件重装/启用不覆盖后台已配置的展示字段。
 */
class PluginMenuLayout extends BaseController
{
    /**
     * 菜单布局配置页。
     */
    public function index()
    {
        $roots = Menu::where('is_plugin_root', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($roots as &$root) {
            $root['child_count']    = Menu::where('parent_id', (int)$root['id'])->count();
            $root['placement']      = $this->placementOf($root);
            $root['placement_text'] = $this->placementText($root['placement']);
            $root['can_pin'] = (int)$root['mount'] === Menu::MOUNT_LAUNCHER ? 1 : 0;
        }
        unset($root);

        return $this->assign([
            'roots' => $roots,
            'placements' => [
                'extend'   => '插件扩展',
                'launcher' => '启动台',
                'pinned'   => '常用插件区',
            ],
        ])->fetch();
    }

    /**
     * 判定插件根节点的当前归属。
     */
    protected function placementOf(array $root): string
    {
        if ((int)$root['parent_id'] !== 0) {
            return 'extend';
        }
        return !empty($root['pinned']) ? 'pinned' : 'launcher';
    }

    /**
     * 归属文本。
     */
    protected function placementText(string $placement): string
    {
        return [
            'extend'   => '插件扩展',
            'launcher' => '启动台',
            'pinned'   => '常用插件区',
        ][$placement] ?? '未知';
    }

    /**
     * 保存各插件的归属布局。
     */
    #[OperationLog(template: '保存插件菜单布局', level: 'WARNING')]
    public function save()
    {
        $layouts = $this->request->post('layouts/a', []);
        if (empty($layouts)) {
            return $this->error('没有可保存的配置');
        }

        $registrar = new MenuRegistrar();
        $updated = 0;

        foreach ($layouts as $item) {
            $plugin = trim((string)($item['plugin'] ?? ''));
            if ($plugin === '') {
                continue;
            }

            $layout = [
                'placement' => (string)($item['placement'] ?? 'launcher'),
                'sort'      => (int)($item['sort'] ?? 0),
                'name'      => trim((string)($item['name'] ?? '')),
                'icon'      => trim((string)($item['icon'] ?? '')),
            ];

            if ($registrar->updateRootLayout($plugin, $layout)) {
                $updated++;
            }
        }

        return $this->success("已保存 {$updated} 个插件布局");
    }

    /**
     * 钉选插件到常用插件区（仅多顶级插件）。
     */
    #[OperationLog(template: '钉选插件到常用区', level: 'WARNING')]
    public function pin()
    {
        $plugin = $this->request->post('plugin', '', 'strip_tags');
        if ($plugin === '') {
            return $this->error('缺少插件标识');
        }

        $registrar = new MenuRegistrar();
        if ($registrar->pinPlugin($plugin)) {
            return $this->success('已钉选到常用区');
        }
        return $this->error('钉选失败（单顶级插件不可钉选）');
    }

    /**
     * 取消钉选。
     */
    #[OperationLog(template: '取消钉选插件', level: 'WARNING')]
    public function unpin()
    {
        $plugin = $this->request->post('plugin', '', 'strip_tags');
        if ($plugin === '') {
            return $this->error('缺少插件标识');
        }

        $registrar = new MenuRegistrar();
        if ($registrar->unpinPlugin($plugin)) {
            return $this->success('已取消钉选');
        }
        return $this->error('取消钉选失败');
    }
}
