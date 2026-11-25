<?php
namespace app\admin\service;

use app\admin\model\Menu;
use think\helper\Str;
use wise\auth\facade\Perm;
use wise\auth\facade\Rbac;
use wise\auth\model\Permission;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * 菜单权限桥接服务
 *
 * 作为 Menu Model 与 wise-auth 包之间的唯一翻译层，
 * 根据 menu_permission.php 配置将菜单数据转换为权限数据。
 *
 * 职责:
 *   - 菜单数据 → 权限数据的字段映射与转换
 *   - 权限标识生成（多策略可配置）
 *   - HTTP 方法推断（多策略可配置）
 *   - 父权限解析（多策略可配置）
 *   - 批量菜单树同步到权限表
 *   - 根据角色过滤有权限的菜单
 *   - 菜单删除时联动清除权限
 */
class MenuPermissionBridge
{
    /** @var array 当前生效的配置（可合并插件级覆盖） */
    protected $config;

    /** @var string|null 当前操作对应的插件标识 */
    protected $pluginIdent;

    /** @var MenuPathNormalizer|null 菜单 path 归一器（延迟构造：读取 route 默认动作 / 默认控制器） */
    protected ?MenuPathNormalizer $normalizer = null;

    public function __construct(array $options = [])
    {
        $this->config = config('menu_permission', []);
        if (!empty($options)) {
            $this->config = array_merge($this->config, $options);
        }
    }

    /**
     * 菜单 path 归一器（`/plugin/x/y` ≡ `/plugin/x/y/index`，见 {@see MenuPathNormalizer}）
     */
    public function normalizer(): MenuPathNormalizer
    {
        return $this->normalizer ??= MenuPathNormalizer::fromConfig();
    }

    /**
     * 设置当前操作的插件标识
     */
    public function setPlugin(string $pluginIdent): self
    {
        $this->pluginIdent = $pluginIdent;
        return $this;
    }

    /**
     * 获取当前插件标识
     */
    public function getPlugin(): string
    {
        return $this->pluginIdent ?? '';
    }


    /**
     * 将菜单数据转换为权限数据数组
     *
     * @param array $menuData     菜单属性数组（Menu 模型字段）
     * @param int   $parentPermId 父权限 ID
     * @return array 权限数据数组（可直接传给 PermissionService::registerPermission）
     */
    public function transform(array $menuData, int $parentPermId = 0): array
    {
        $perm = $this->config['defaults'] ?? [];

        foreach ($this->config['field_map'] ?? [] as $menuKey => $permKey) {
            if (array_key_exists($menuKey, $menuData)) {
                $perm[$permKey] = $menuData[$menuKey];
            }
        }

        $path                = $menuData['path'] ?? '';
        $perm['ident']       = $this->generateIdent($path);
        $perm['route']       = $path;
        $perm['method']      = $this->inferMethod($path);
        $perm['parent_id']   = $parentPermId;
        $perm['plugin']      = $menuData['plugin'] ?? $this->getPlugin();
        $perm['description'] = $menuData['description'] ?? ($menuData['remark'] ?? '');

        return $perm;
    }

    /**
     * 批量将菜单树转换为权限定义数组
     *
     * 用于「manifest.json 未定义 permissions 时，从 menus 自动生成权限」场景。
     *
     * @param array $menus 菜单树数组
     * @return array 权限定义数组（含嵌套 children 键）
     */
    public function transformBatch(array $menus): array
    {
        $permissions = [];
        foreach ($menus as $menu) {
            if (!empty($menu['path'])) {
                $perm = $this->transform($menu);

                $children = $menu['children'] ?? $menu['child'] ?? [];
                if (!empty($children)) {
                    if (!is_array(reset($children))) {
                        $children = [$children];
                    }
                    $childPerms = $this->transformBatch($children);
                    if (!empty($childPerms)) {
                        $perm['children'] = $childPerms;
                    }
                }

                $permissions[] = $perm;
            }
        }
        return $permissions;
    }


    /**
     * 为单个菜单创建或更新权限（幂等）
     *
     * @param Menu $menu          菜单模型实例
     * @param int  $parentPermId  父权限 ID
     * @param bool $preserveMethod 已存在权限是否保留原 method
     *                             （默认 true；`--fix-method` 时传 false 用配置重算）
     * @return int 权限 ID（无 path 的菜单返回 0）
     */
    public function syncFromMenu(Menu $menu, int $parentPermId = 0, bool $preserveMethod = true): int
    {
        if (empty($menu->getAttr('path'))) {
            return 0;
        }

        $permData = $this->transform($menu->toArray(), $parentPermId);

        $permData['preserve_method'] = $preserveMethod;

        return Perm::registerPermission($permData);
    }

    /**
     * 批量同步菜单树到权限表
     *
     * 遍历菜单树，对每个有 path 的菜单创建/更新权限，
     * 并将权限 ID 回写到 menu.permission_id 实现双向绑定。
     *
     * @param string $pluginIdent  插件标识
     * @param array  $menus        菜单树数组
     * @param int    $parentMenuId 父菜单 ID
     * @param int    $parentPermId 父权限 ID
     */
    public function syncBatch(
        string $pluginIdent,
        array $menus,
        int $parentMenuId = 0,
        int $parentPermId = 0,
        bool $preserveExisting = false
    ): void {
        $this->setPlugin($pluginIdent);

        foreach ($menus as $menuData) {
            $path = $menuData['path'] ?? '';

            $menu  = $this->findOrCreateMenu($menuData, $pluginIdent, $parentMenuId);
            $isNew = empty($menu->getAttr('id'));

            if ($isNew || !$preserveExisting) {
                $this->fillMenuFields($menu, $menuData, $parentMenuId, $pluginIdent);
            }

            $currentPermId = $parentPermId;
            if (!empty($path)) {
                $currentPermId = $this->syncFromMenu($menu, $parentPermId);
                if ($this->config['auto_bind'] ?? true) {
                    $menu->setAttr('permission_id', $currentPermId);
                    $menu->save();
                }
            }

            $children = $menuData['children'] ?? $menuData['child'] ?? $menuData['childlist'] ?? [];
            if (!empty($children)) {
                if (is_array($children) && !isset($children[0])) {
                    $children = [$children];
                }
                $this->syncBatch($pluginIdent, $children, $menu->getAttr('id'), $currentPermId, $preserveExisting);
            }
        }
    }

    /**
     * 为单个菜单同步权限（编辑场景，幂等）
     *
     * @param Menu $menu        菜单模型实例
     * @param bool $forceMethod 是否用当前配置覆盖已存在权限的 method
     * @return bool
     */
    public function syncPermissionForMenu(Menu $menu, bool $forceMethod = false): bool
    {
        if (empty($menu->getAttr('path'))) {
            return true;
        }

        $permParentId = 0;
        if ($menu->getAttr('parent_id') > 0) {
            $parentMenu = Menu::find($menu->getAttr('parent_id'));
            $permParentId = $this->resolveParentPermId($parentMenu);
        }

        $permId = $this->syncFromMenu($menu, $permParentId, !$forceMethod);

        if ($this->config['auto_bind'] ?? true) {
            if ((int)$menu->getAttr('permission_id') !== $permId) {
                $menu->setAttr('permission_id', $permId);
                $menu->save();
            }
        }

        return true;
    }

    /**
     * 删除菜单对应的权限
     *
     * @param Menu $menu 菜单模型实例
     * @return bool
     */
    public function deletePermissionForMenu(Menu $menu): bool
    {
        return $this->deletePermissionById((int) $menu->getAttr('permission_id'));
    }

    /**
     * 按权限 ID 删除权限（软删，保留角色关联记录）
     *
     * 说明：`Perm::deletePermission()` 以 **ident** 为键，而菜单上只存了
     * `permission_id`；历史上此处误把数字 id 当作 ident 传入，导致匹配失败、
     * 删除菜单时权限从未被清理。这里统一按 id 反查 ident 后再删除。
     */
    public function deletePermissionById(int $permId): bool
    {
        if ($permId <= 0) {
            return true;
        }

        $ident = (string) Permission::where('id', $permId)->value('ident');
        if ($ident === '') {
            return true;
        }

        return Perm::deletePermission($ident);
    }


    /**
     * 根据角色获取有权限的菜单 ID 列表
     *
     * @param array $roleIds 角色 ID 数组
     * @return array 菜单 ID 数组
     */
    public function filterMenuIdsByRoles(array $roleIds): array
    {
        $permissionIds = Rbac::resolveRolesPermissionIds($roleIds);
        if (empty($permissionIds)) {
            return [];
        }

        $menus = Menu::whereIn('permission_id', $permissionIds)
            ->where('status', Menu::STATUS_ENABLE)
            ->field('id,plugin')
            ->select()
            ->toArray();

        $menus = static::filterDisabledPluginRows($menus);

        return array_map('intval', array_column($menus, 'id'));
    }


    /**
     * 已禁用插件的标识列表（请求级缓存）
     *
     * 背景：插件禁用只翻转 `plugin_config.__state.enabled`，并不删除 menus /
     * auth_permissions 里的行（这些行承载运营改动，删了无法恢复）。因此展示层必须
     * 按这份黑名单剔除对应菜单与权限节点，否则会出现「插件已禁用，菜单和权限还挂着」。
     *
     * 判定口径（保守，避免误伤）：
     *   - 只认**明确写过 false** 的状态键：读不到状态（plugin_config 表不存在、DB 未就绪、
     *     老库从未写过状态）时一律不算禁用 —— 宁可多显示也不整批隐藏（fail-open）；
     *   - 只包含插件注册表里存在的 ident：库包菜单（`plugin` 存包标识）与 `system`
     *     系统菜单不在注册表内，天然不受影响。
     *
     * @return string[] 已禁用插件标识
     */
    public static function disabledPluginIdents(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        try {
            $manager = app(\wise\plugin\PluginContainerKeys::MANAGER);
            $config = app(\wise\plugin\PluginContainerKeys::CONFIG);

            foreach ($manager->getAllPlugins() as $ident => $metadata) {
                if ($config->get($ident . '.__state.enabled') === false) {
                    $cache[] = (string) $ident;
                }
            }
        } catch (\Throwable $e) {
            $cache = [];
        }

        return $cache;
    }

    /**
     * 从菜单/权限行集中剔除已禁用插件的行
     *
     * 菜单（`menus`）与权限（`auth_permissions`）都有 `plugin` 字段（`system` 或插件/包
     * 标识），故两处共用同一过滤口径，避免各自维护一份判定。
     *
     * @param array  $rows  行数组（模型 toArray 结果）
     * @param string $field 插件标识字段名
     * @return array 过滤后的行（保持原顺序、重建数字索引）
     */
    public static function filterDisabledPluginRows(array $rows, string $field = 'plugin'): array
    {
        $disabled = static::disabledPluginIdents();
        if ($disabled === [] || $rows === []) {
            return $rows;
        }

        return array_values(array_filter($rows, static function ($row) use ($disabled, $field): bool {
            $plugin = is_array($row) ? ($row[$field] ?? '') : '';
            return !in_array((string) $plugin, $disabled, true);
        }));
    }

    /**
     * 根据菜单路径获取权限标识码（ident）
     *
     * @param string $path 菜单路径
     * @return string|null 权限标识码，未找到返回 null
     */
    public function getPermissionIdentByPath(string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $ident = $this->generateIdent($path);
        $perm  = Perm::findByIdent($ident);
        return $perm ? $perm->getAttr('ident') : null;
    }

    /**
     * 获取指定用户的所有权限标识码（委托 RbacManager）
     *
     * @param int    $userId   用户 ID
     * @param string $userType 用户类型（如 admin/member）
     * @return array 权限标识码数组
     */
    public function getUserPermissionCodes(int $userId, string $userType = 'admin'): array
    {
        return Rbac::getUserPermissions($userId, $userType);
    }


    /**
     * 根据路径生成权限标识
     *
     * @param string $path 菜单路径（如 /admin/user/list）
     * @return string 权限标识（如 admin.user.list）
     */
    public function generateIdent(string $path): string
    {
        $methodConfig = $this->config['ident'] ?? [];
        $strategy     = $methodConfig['strategy'] ?? 'path_to_dot';

        switch ($strategy) {
            case 'path_lower':
                return strtolower(str_replace('/', '.', trim($path, '/')));

            case 'hash':
                return substr(md5($path), 0, 16);

            case 'callback':
                $cb = $methodConfig['callback'] ?? null;
                if (is_callable($cb)) {
                    return $cb($path);
                }
                return strtolower(str_replace('/', '.', trim($path, '/')));

            case 'path_to_dot':
            default:
                return strtolower(str_replace('/', '.', Str::snake(trim($path, '/'))));
        }
    }


    /**
     * 根据路径推断 HTTP 请求方法
     *
     * 返回值可以是**方法集合**（如 `GET,POST`）：RBAC 的 methodMatch() 已支持逗号列表与 `*`。
     * 这样 `edit` 同时接受 GET 表单页与 POST 提交，`delete` 同时接受 POST/DELETE，
     * 不会因为开发者习惯不同就 403。
     *
     * @param string $path 菜单路径
     * @return string HTTP 方法或方法集合（GET/POST/DELETE/GET,POST/*）
     */
    public function inferMethod(string $path): string
    {
        $methodConfig = $this->config['method'] ?? [];
        $strategy     = $methodConfig['strategy'] ?? 'path_suffix';

        $overrides = (array) ($methodConfig['overrides'] ?? []);
        $key       = '/' . trim($path, '/');

        foreach ([$path, $key] as $candidate) {
            if (isset($overrides[$candidate])) {
                return strtoupper(trim((string) $overrides[$candidate]));
            }
        }

        switch ($strategy) {
            case 'always_get':
                return 'GET';

            case 'always_post':
                return 'POST';

            case 'always_any':
                return '*';

            case 'callback':
                $cb = $methodConfig['callback'] ?? null;
                return is_callable($cb) ? strtoupper(trim((string) $cb($path))) : 'GET';

            case 'path_suffix':
            default:
                $segments    = explode('/', trim($path, '/'));
                $lastSegment = strtolower((string) end($segments));
                $pattern     = (array) ($methodConfig['pattern'] ?? []);

                return strtoupper((string) ($pattern[$lastSegment] ?? ($methodConfig['default_method'] ?? 'GET')));
        }
    }


    /**
     * 解析父菜单对应的权限 ID
     *
     * @param Menu|null $parentMenu 父菜单模型
     * @return int 父权限 ID
     */
    public function resolveParentPermId(?Menu $parentMenu): int
    {
        $parentConfig = $this->config['parent'] ?? [];
        $strategy     = $parentConfig['strategy'] ?? 'parent_menu';

        switch ($strategy) {
            case 'none':
                return 0;

            case 'callback':
                $cb = $parentConfig['callback'] ?? null;
                return is_callable($cb) ? $cb($parentMenu) : 0;

            case 'parent_menu':
            default:
                if ($parentMenu && (int)$parentMenu->getAttr('permission_id') > 0) {
                    return (int)$parentMenu->getAttr('permission_id');
                }
                return 0;
        }
    }


    /**
     * 幂等查找或创建菜单记录（含软删除恢复）
     *
     * 匹配是**两层**的（`plugin` + `parent_id` 为作用域）：
     *
     * 1. **原样 path 精确匹配**（绝大多数情况，也最省索引）；
     * 2. 未命中时按**归一化身份**找等价写法（{@see self::findEquivalentMenu()}）：
     *    `/plugin/x/y` 与 `/plugin/x/y/index` 运行时是同一个资源，若当成两个节点，
     *    就会建成**两个菜单 + 两份权限** —— 而角色授权挂在旧权限行上，页面等于悄悄丢权＼�。
     *
     * 无 path 的节点（`dir` 分组）仍按 `name` 匹配：名字没有「默认段可省略」的语义。
     */
    protected function findOrCreateMenu(array $menuData, string $pluginIdent, int $parentId): Menu
    {
        $path = $menuData['path'] ?? '';

        $query = Menu::withTrashed()
            ->where('plugin', $pluginIdent)
            ->where('parent_id', $parentId);

        if ($path !== '') {
            $menu = (clone $query)->where('path', $path)->find();

            if (!$menu) {
                $menu = $this->findEquivalentMenu($query, $path);
            }
        } else {
            $menu = $query->where('name', $menuData['name'])->find();
        }

        if ($menu) {
            if ($menu->getAttr('delete_time')) {
                $menu->restore();
            }
        } else {
            $menu = new Menu();
        }

        return $menu;
    }

    /**
     * 在同一 `plugin` + `parent_id` 作用域内，按**归一化身份**查找等价写法的菜单行。
     *
     * 只对 `plugin/` 前缀生效（宿主 `/admin/**` 不收敛，理由见 {@see MenuPathNormalizer} 约定 2）；
     * 两种写法都存在时取 `id` 最小的一条 —— 历史更久，权限与角色授权大概率挂在它上面。
     *
     * @param \think\db\Query $query 已限定 plugin + parent_id 的查询
     * @param string          $path  声明中的 path（未命中精确匹配）
     * @return Menu|null
     */
    protected function findEquivalentMenu($query, string $path): ?Menu
    {
        $variants = $this->normalizer()->variants($path);

        if (count($variants) < 2) {
            return null;
        }

        $candidates = [];
        foreach ($variants as $variant) {
            $candidates[] = $variant;
            $candidates[] = '/' . $variant;
        }

        return (clone $query)
            ->whereIn('path', array_values(array_unique($candidates)))
            ->order('id', 'asc')
            ->find();
    }

    /**
     * 填充菜单字段并保存
     */
    protected function fillMenuFields(Menu $menu, array $menuData, int $parentId, string $pluginIdent): void
    {
        $declaredPath = (string) ($menuData['path'] ?? '');
        $storedPath   = (string) $menu->getAttr('path');

        if ($storedPath !== '' && $this->normalizer()->isSame($storedPath, $declaredPath)) {
            $declaredPath = $storedPath;
        }

        $menu->setAttr('parent_id', $parentId);
        $menu->setAttr('name', $menuData['name']);
        $menu->setAttr('icon', $menuData['icon'] ?? '');
        $menu->setAttr('path', $declaredPath);
        $menu->setAttr('component', $menuData['component'] ?? '');
        $menu->setAttr('plugin', $pluginIdent);
        $menu->setAttr('sort', $menuData['sort'] ?? 0);
        $menu->setAttr('type', $menuData['type'] ?? Menu::TYPE_CATALOG);
        $menu->setAttr('status', $menuData['status'] ?? Menu::STATUS_ENABLE);
        $menu->setAttr('description', $menuData['description'] ?? '');

        if (array_key_exists('is_plugin_root', $menuData)) {
            $menu->setAttr('is_plugin_root', !empty($menuData['is_plugin_root']) ? 1 : 0);
        }
        if (array_key_exists('mount', $menuData)) {
            $menu->setAttr('mount', (int)$menuData['mount']);
        }
        if (array_key_exists('group', $menuData)) {
            $menu->setAttr('group', (string)$menuData['group']);
        }
        if (array_key_exists('pinned', $menuData)) {
            $menu->setAttr('pinned', !empty($menuData['pinned']) ? 1 : 0);
        }

        $menu->save();
    }
}
