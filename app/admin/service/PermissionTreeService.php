<?php
declare(strict_types=1);

namespace app\admin\service;

use app\admin\model\Menu;

/**
 * 权限树装饰器：把「菜单目录（dir）」以虚拟分组的形式补回权限树
 *
 * 背景
 * ----
 * 权限节点由菜单派生（MenuPermissionBridge），而 `dir` 目录没有 path，
 * 因此不会生成权限节点 —— 结果是「菜单管理」里有分组层级，
 * 「权限配置」里却是扁平的权限堆，两棵树形状不一致，首次配置很难对上号。
 *
 * 做法
 * ----
 * 不往数据库里塞假权限（空 route 节点会污染鉴权匹配），而是在**读取时**按
 * 权限节点的来源菜单，找到它的目录祖先链（dir），生成 `virtual = true` 的
 * 分组节点（id 取负数，避免与真实权限 ID 冲突，前端不渲染复选框）。
 *
 * 约束
 * ----
 *   - 只包裹「根权限」（parent_id = 0），子权限仍挂在父权限下，不重复包；
 *   - 无来源菜单的权限（插件/AI 工具等）保持原样，追加在树尾；
 *   - 空目录（没有任何权限后代）不输出，避免噪音；
 *   - 不改任何写库逻辑，虚拟节点不会出现在角色权限 ID 里。
 */
class PermissionTreeService
{
    /** @var array<int, array> 菜单 id => 菜单行 */
    protected array $menuById = [];

    /** @var array<int, array> 权限 id => 菜单行（menu.permission_id 绑定） */
    protected array $menuByPermission = [];

    /**
     * 为权限树补上目录分组
     *
     * @param array $permissionTree Tree::getTreeArray() 产出的权限树
     * @return array
     */
    public function build(array $permissionTree): array
    {
        $this->loadMenuIndex();

        if (empty($this->menuByPermission)) {
            return $permissionTree;
        }

        $groups    = [];
        $ungrouped = [];

        foreach ($permissionTree as $node) {
            $chain = $this->dirChain((int) ($node['id'] ?? 0));

            if (empty($chain)) {
                $ungrouped[] = $node;
                continue;
            }

            $parentId = null;
            foreach ($chain as $dir) {
                $menuId = (int) $dir['id'];

                if (!isset($groups[$menuId])) {
                    $groups[$menuId] = [
                        'meta'   => $dir,
                        'parent' => $parentId,
                        'items'  => [],
                    ];
                }

                $parentId = $menuId;
            }

            $groups[$parentId]['items'][] = $node;
        }

        return array_merge($this->renderGroups($groups, null), $ungrouped);
    }

    /**
     * 递归渲染分组节点
     *
     * @param array    $groups
     * @param int|null $parentId
     * @return array
     */
    protected function renderGroups(array $groups, ?int $parentId): array
    {
        $childIds = [];
        foreach ($groups as $menuId => $group) {
            if ($group['parent'] === $parentId) {
                $childIds[] = (int) $menuId;
            }
        }

        usort($childIds, static function (int $a, int $b) use ($groups): int {
            $sortA = (int) ($groups[$a]['meta']['sort'] ?? 0);
            $sortB = (int) ($groups[$b]['meta']['sort'] ?? 0);

            return $sortA === $sortB ? $a <=> $b : $sortA <=> $sortB;
        });

        $nodes = [];
        foreach ($childIds as $menuId) {
            $group = $groups[$menuId];

            $nodes[] = [
                'id'        => -$menuId,
                'virtual'   => true,
                'menu_id'   => $menuId,
                'name'      => (string) ($group['meta']['name'] ?? ''),
                'type'      => 'dir',
                'ident'     => '',
                'route'     => '',
                'method'    => '',
                'plugin'    => '',
                'sort'      => (int) ($group['meta']['sort'] ?? 0),
                'childlist' => array_merge(
                    $group['items'],
                    $this->renderGroups($groups, $menuId)
                ),
            ];
        }

        return $nodes;
    }

    /**
     * 取权限所属菜单的目录祖先链（root → leaf，只含 dir 类型）
     *
     * @param int $permissionId
     * @return array
     */
    protected function dirChain(int $permissionId): array
    {
        $menu = $this->menuByPermission[$permissionId] ?? null;
        if (!$menu) {
            return [];
        }

        $chain = [];
        $guard = 0;
        $cursor = $this->menuById[(int) ($menu['parent_id'] ?? 0)] ?? null;

        while ($cursor && $guard++ < 20) {
            if (($cursor['type'] ?? '') === 'dir') {
                array_unshift($chain, $cursor);
            }
            $cursor = $this->menuById[(int) ($cursor['parent_id'] ?? 0)] ?? null;
        }

        return $chain;
    }

    /**
     * 载入启用菜单索引（一次请求内复用）
     *
     * @return void
     */
    protected function loadMenuIndex(): void
    {
        if (!empty($this->menuById)) {
            return;
        }

        $menus = Menu::where('status', Menu::STATUS_ENABLE)
            ->field('id,parent_id,name,type,permission_id,sort')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($menus as $menu) {
            $menuId = (int) $menu['id'];

            $this->menuById[$menuId] = $menu;

            $permId = (int) ($menu['permission_id'] ?? 0);
            if ($permId > 0) {
                $this->menuByPermission[$permId] = $menu;
            }
        }
    }
}
