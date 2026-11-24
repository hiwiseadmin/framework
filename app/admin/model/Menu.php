<?php
namespace app\admin\model;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;
use app\admin\facade\CurrentAdmin;
use wise\support\tools\Tree;
use think\facade\Db;

class Menu extends BaseModel
{
    use SoftDelete;

    protected $name = 'menus';

    /**
     * 可写字段白名单（防批量赋值）
     */
    protected $allowWriteField = [
        'parent_id', 'name', 'icon', 'path', 'component',
        'permission_id', 'sort', 'type', 'status', 'plugin',
        'mount', 'group', 'pinned', 'is_plugin_root', 'description',
    ];
    
    const TYPE_CATALOG = 'dir';
    const TYPE_MENU = 'menu';
    const TYPE_BUTTON = 'button';
    
    const STATUS_DISABLE = 0;
    const STATUS_ENABLE = 1;

    const MOUNT_MODULE        = \wise\support\menu\MenuMount::MODULE;
    const MOUNT_SIDEBAR_GROUP = \wise\support\menu\MenuMount::SIDEBAR_GROUP;
    const MOUNT_SIDEBAR_FIXED = \wise\support\menu\MenuMount::SIDEBAR_FIXED;
    const MOUNT_LAUNCHER      = \wise\support\menu\MenuMount::LAUNCHER;

    /** 默认固定分组名 */
    const DEFAULT_PLUGIN_GROUP = '插件中心';

    /** 「插件扩展」系统顶级容器名（收纳单顶级菜单插件） */
    const PLUGIN_EXTEND_NAME = '插件扩展';

    /** 顶部常用插件区数量上限（先写死，后续可配置化） */
    const PINNED_LIMIT = 5;
    
    /**
     * 批量创建菜单（幂等，支持重复安装）
     *
     * 策略：同 plugin + path + parent_id 的菜单已存在（含软删除）→ 复用；否则新建。
     * 注意：本方法不创建权限，如需同步创建权限请使用 createMenusWithPermissions()。
     *
     * ⚠️ 本方法的 path 匹配是**精确匹配**，不认「等价写法」（`/plugin/x/y` 与
     * `/plugin/x/y/index` 会被当成两个节点 → 重复菜单）。需要归一化身份匹配（并同步权限）
     * 请用 `createMenusWithPermissions()`（其落库原语 `MenuPermissionBridge::findOrCreateMenu()`
     * 已接入 `MenuPathNormalizer`，见方桼�。
     *
     * @param string $pluginIdent 插件标识
     * @param array  $menus       菜单数据数组
     * @param int    $parentId    父菜单ID，默认0表示根菜单
     * @return bool
     */
    public static function createMenus($pluginIdent, $menus, $parentId = 0)
    {
        Db::startTrans();
        try {
            foreach ($menus as $menuData) {
                $path = $menuData['path'] ?? '';

                $query = self::withTrashed()
                    ->where('plugin', $pluginIdent)
                    ->where('parent_id', $parentId);

                if ($path !== '') {
                    $query->where('path', $path);
                } else {
                    $query->where('name', $menuData['name']);
                }

                $menu = $query->find();

                if ($menu) {
                    if ($menu->delete_time) {
                        $menu->restore();
                    }
                } else {
                    $menu = new self();
                    $menu->setAttr('plugin', $pluginIdent);
                    $menu->setAttr('parent_id', $parentId);
                }

                $menu->setAttr('name', $menuData['name']);
                $menu->setAttr('icon', $menuData['icon'] ?? '');
                $menu->setAttr('path', $path);
                $menu->setAttr('component', $menuData['component'] ?? '');
                $menu->setAttr('permission_id', $menuData['permission_id'] ?? '');
                $menu->setAttr('sort', $menuData['sort'] ?? 0);
                $menu->setAttr('type', $menuData['type'] ?? self::TYPE_CATALOG);
                $menu->setAttr('status', $menuData['status'] ?? self::STATUS_ENABLE);
                $menu->save();

                $currentId = $menu->getAttr('id');

                if (!empty($menuData['child'])) {
                    $children = $menuData['child'];
                    if (is_array($children) && !isset($children[0])) {
                        $children = [$children];
                    }
                    self::createMenus($pluginIdent, $children, $currentId);
                }
            }
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 获取挂载模式文本
     * @param int|null $mount 挂载模式
     * @return array|string
     */
    public static function getMountText($mount = null)
    {
        $mounts = [
            self::MOUNT_MODULE => '顶级模块',
            self::MOUNT_SIDEBAR_GROUP => '侧边栏分组',
            self::MOUNT_SIDEBAR_FIXED => '固定分组',
            self::MOUNT_LAUNCHER => '启动台',
        ];

        if ($mount !== null) {
            return $mounts[$mount] ?? '未知';
        }

        return $mounts;
    }

    /**
     * 幂等查找插件根容器节点（含软删除恢复）
     *
     * 每个插件至多一个 is_plugin_root=1 的节点，作为其菜单树容器。
     * 该节点可能位于：
     *   - parent_id=0（多顶级菜单插件，作为顶部常用区候选）
     *   - 「插件扩展」节点下（单顶级菜单插件）
     *
     * @param string $pluginIdent 插件标识
     * @return \think\Model|null
     */
    public static function findPluginRoot(string $pluginIdent)
    {
        $root = self::withTrashed()
            ->where('plugin', $pluginIdent)
            ->where('is_plugin_root', 1)
            ->order('id', 'asc')
            ->find();

        if ($root && $root->getAttr('delete_time')) {
            $root->restore();
        }

        return $root;
    }

    /**
     * 创建或更新插件根容器节点
     *
     * 挂载位置（parent_id）随 manifest 结构变化而纠正：
     *   - 单顶级菜单插件 → parent_id = 「插件扩展」节点 id
     *   - 多顶级菜单插件 → parent_id = 0（顶部常用区候选）
     * 展示字段（mount/pinned/name/icon）在节点已存在时保留，
     * 以尊重管理员在「菜单布局」页的覆盖。
     *
     * @param string $pluginIdent 插件标识
     * @param array  $data        根节点数据（name/icon/mount/pinned/sort/parent_id）
     * @return \think\Model 根节点实例
     */
    public static function createPluginRoot(string $pluginIdent, array $data = [])
    {
        $root  = self::findPluginRoot($pluginIdent);
        $isNew = !$root;

        if ($isNew) {
            $root = new self();
            $root->setAttr('plugin', $pluginIdent);
            $root->setAttr('is_plugin_root', 1);
            $root->setAttr('type', self::TYPE_CATALOG);
            $root->setAttr('status', self::STATUS_ENABLE);
            $root->setAttr('name', $data['name'] ?? $pluginIdent);
            $root->setAttr('icon', $data['icon'] ?? 'bi bi-plugin');
            if (array_key_exists('sort', $data)) {
                $root->setAttr('sort', (int)$data['sort']);
            }
            if (array_key_exists('pinned', $data)) {
                $root->setAttr('pinned', !empty($data['pinned']) ? 1 : 0);
            }
        }

        if (array_key_exists('parent_id', $data)) {
            $root->setAttr('parent_id', (int)$data['parent_id']);
        }
        if (array_key_exists('mount', $data)) {
            $root->setAttr('mount', (int)$data['mount']);
            if ((int)$data['mount'] !== self::MOUNT_LAUNCHER) {
                $root->setAttr('pinned', 0);
            }
        }

        $root->setAttr('path', $data['path'] ?? '');
        $root->setAttr('description', $data['description'] ?? '');
        $root->save();

        return $root;
    }

    /**
     * 幂等创建/获取「插件扩展」系统顶级容器节点。
     *
     * 收纳所有「单顶级菜单」插件的入口，位于顶部导航第二个固定 Tab。
     *
     * @return int 节点 ID
     */
    public static function ensurePluginExtendRoot(): int
    {
        $node = self::withTrashed()
            ->where('plugin', 'system')
            ->where('parent_id', 0)
            ->where('name', self::PLUGIN_EXTEND_NAME)
            ->find();

        if ($node) {
            if ($node->getAttr('delete_time')) {
                $node->restore();
            }
            return (int)$node->getAttr('id');
        }

        $node = new self();
        $node->setAttr('plugin', 'system');
        $node->setAttr('parent_id', 0);
        $node->setAttr('name', self::PLUGIN_EXTEND_NAME);
        $node->setAttr('icon', 'bi bi-puzzle');
        $node->setAttr('path', '');
        $node->setAttr('type', self::TYPE_CATALOG);
        $node->setAttr('status', self::STATUS_ENABLE);
        $node->setAttr('sort', 1);
        $node->setAttr('is_plugin_root', 0);
        $node->setAttr('mount', self::MOUNT_MODULE);
        $node->setAttr('pinned', 0);
        $node->save();

        return (int)$node->getAttr('id');
    }

    /**
     * 解析系统顶级「控制台」根节点（包自持容器目录的挂载锚点）
     *
     * 侧边栏先按「系统顶级」渲染顶部 Tab，再把其子节点渲染为一级目录，
     * 故「控制台」是包容器目录的唯一挂载点。按语义解析，不硬编码 ID。
     *
     * @return int|null 系统菜单未初始化时返回 null
     */
    public static function resolveConsoleRoot(): ?int
    {
        $node = self::where('plugin', 'system')
            ->where('parent_id', 0)
            ->where('type', self::TYPE_CATALOG)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find();

        return $node ? (int)$node->getAttr('id') : null;
    }

    /**
     * 按名称解析「已存在的目录节点」（供包声明挂靠到框架目录）
     *
     * @param  string $name 目录名称，如「插件管理」
     * @return int|null 未找到返回 null
     */
    public static function resolveCatalogByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $node = self::withTrashed()
            ->where('type', self::TYPE_CATALOG)
            ->where('name', $name)
            ->order('plugin', 'asc')
            ->order('id', 'asc')
            ->find();

        if ($node === null) {
            return null;
        }

        if ($node->getAttr('delete_time')) {
            $node->restore();
        }

        return (int)$node->getAttr('id');
    }

    /**
     * 幂等创建/获取「包自持容器目录」
     *
     * 归属本实现（plugin = $ident），因此能被 MenuItemSync::uninstall() 按 plugin
     * 作用域连带清理（卸载无痕 —— 该方法不排除 `is_plugin_root=1`，故两种模式都清）。
     *
     * ── 两种落库形态 ────────────────────────────────────────────────
     * 1. **控制台侧边栏**（默认）：挂 `$parentId`（通常为控制台根），`is_plugin_root = 0`；
     * 2. **应用启动台**（`mount = MenuMount::LAUNCHER`，2026-10-13 支持）：
     *    强制挂**顶层** `parent_id = 0` + `is_plugin_root = 1` ——
     *    前者让 `MenuLauncherService` 判定 `canPin = 1`（可钉选到「常用插件区」），
     *    后者复用「菜单根容器」既有语义（prune 永不删、常用区渲染依赖它）。
     *    动机：wise-* 包变多时侧边栏拥挤，把整包挪到启动台即可分流。
     *
     * ── 复用语义 ────────────────────────────────────────────────────
     * 按「实现 + 类型 + 名称」定位（**不限定 parent_id**）：模式切换时移动同一行，
     * 而不是在另一个父级下新建同名容器（否则会出现两个「应用市场」）。
     * 命中已有行时：恢复软删 → **仅当模式声明变化才同步** mount / is_plugin_root /
     * parent_id；name / icon / sort 等运营在后台的调整一律保留（与菜单"声明是真相、
     * 但保留运营改动"的既有约定一致）。
     *
     * @param  int                 $parentId 父节点（通常为控制台根；启动台模式会被强制为 0）
     * @param  string              $ident    实现标识
     * @param  string              $name     目录名称
     * @param  array<string,mixed> $attrs    可选：icon / sort / mount / is_plugin_root / pinned / description
     * @return array{id:int, created:bool}
     */
    public static function ensureContainer(int $parentId, string $ident, string $name, array $attrs = []): array
    {
        $name  = trim($name);
        $mount = (int) ($attrs['mount'] ?? self::MOUNT_MODULE);

        $isRoot = array_key_exists('is_plugin_root', $attrs)
            ? !empty($attrs['is_plugin_root'])
            : $mount === self::MOUNT_LAUNCHER;

        if ($isRoot) {
            $parentId = 0;
        }

        $node = self::withTrashed()
            ->where('plugin', $ident)
            ->where('type', self::TYPE_CATALOG)
            ->where('name', $name)
            ->order('id', 'asc')
            ->find();

        if ($node) {
            if ($node->getAttr('delete_time')) {
                $node->restore();
            }

            $changed = false;

            if ((int) $node->getAttr('mount') !== $mount) {
                $node->setAttr('mount', $mount);
                $changed = true;
            }
            if ((int) $node->getAttr('is_plugin_root') !== ($isRoot ? 1 : 0)) {
                $node->setAttr('is_plugin_root', $isRoot ? 1 : 0);
                $changed = true;
            }
            if ((int) $node->getAttr('parent_id') !== $parentId) {
                $node->setAttr('parent_id', $parentId);
                $changed = true;
            }

            if ($changed) {
                $node->save();
            }

            return ['id' => (int)$node->getAttr('id'), 'created' => false];
        }

        $node = new self();
        $node->setAttr('plugin', $ident);
        $node->setAttr('parent_id', $parentId);
        $node->setAttr('name', $name);
        $node->setAttr('icon', (string)($attrs['icon'] ?? ''));
        $node->setAttr('path', '');
        $node->setAttr('component', '');
        $node->setAttr('type', self::TYPE_CATALOG);
        $node->setAttr('status', self::STATUS_ENABLE);
        $node->setAttr('sort', (int)($attrs['sort'] ?? 0));
        $node->setAttr('is_plugin_root', $isRoot ? 1 : 0);
        $node->setAttr('pinned', !empty($attrs['pinned']) ? 1 : 0);
        $node->setAttr('mount', $mount);
        $node->setAttr('description', (string)($attrs['description'] ?? ''));
        $node->save();

        return ['id' => (int)$node->getAttr('id'), 'created' => true];
    }

    /**
     * 将指定插件已有的顶级（parent_id=0 且非插件根）菜单挂载到插件根节点下。
     *
     * 用于兼容旧版格式：早期插件把每个分组都建成 parent_id=0，
     * 升级后调用此方法将其包裹进插件根，使"一个插件 = 一个入口"。
     *
     * @param string $pluginIdent 插件标识
     * @param int    $rootId      插件根节点ID
     * @return int 被重构的顶级菜单数量
     */
    public static function rebasePluginTopMenus(string $pluginIdent, int $rootId): int
    {
        $topMenus = self::where('plugin', $pluginIdent)
            ->where('parent_id', 0)
            ->where('is_plugin_root', 0)
            ->select();

        $count = 0;
        foreach ($topMenus as $menu) {
            $menu->setAttr('parent_id', $rootId);
            $menu->save();
            $count++;
        }

        return $count;
    }

    /**
     * 获取插件根节点（供 Index 控制器将插件与普通根菜单统一渲染时使用）
     *
     * @return array 已启用且可见的插件根节点列表
     */
    public static function getPluginRoots()
    {
        return self::where('status', self::STATUS_ENABLE)
            ->where('is_plugin_root', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取菜单类型文本
     * @param int $type
     * @return array|string
     */
    public static function getTypeText($type = null)
    {
        $types = [
            self::TYPE_CATALOG => '目录',
            self::TYPE_MENU => '菜单',
            self::TYPE_BUTTON => '按钮'
        ];
        
        if ($type !== null) {
            return $types[$type] ?? '未知';
        }
        
        return $types;
    }
    
    /**
     * 获取菜单状态文本
     * @param int $status
     * @return string
     */
    public static function getStatusText($status = null)
    {
        $statuses = [
            self::STATUS_DISABLE => '禁用',
            self::STATUS_ENABLE => '启用'
        ];
        
        if ($status !== null) {
            return $statuses[$status] ?? '未知';
        }
        
        return $statuses;
    }
    
    /**
     * 获取当前用户的所有权限标识码
     */
    public function permission()
    {
        $bridge = app(\app\admin\service\MenuPermissionBridge::class);
        return $bridge->getUserPermissionCodes(CurrentAdmin::getAdminId(), 'admin');
    }
    
    /**
     * 获取子菜单
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id')
                    ->where('status', self::STATUS_ENABLE)
                    ->order('sort', 'asc')
                    ->order('id', 'asc');
    }
    
    /**
     * 获取所有菜单并生成树形结构
     * @param int $parentId 父级ID
     * @param bool $withButton 是否包含按钮类型
     * @return array
     */
    public static function getTreeArray($parentId = 0, $withButton = false)
    {
        $query = self::where(['status' => self::STATUS_ENABLE])
            ->order('sort', 'asc')
            ->order('id', 'asc');
        
        if (!$withButton) {
            $query->where('type', '<>', self::TYPE_BUTTON);
        }
        
        $menus = $query->select()->toArray();

        $menus = \app\admin\service\MenuPermissionBridge::filterDisabledPluginRows($menus);

        return Tree::instance()->init($menus)->getTreeArray($parentId);
    }
    
    /**
     * 获取所有菜单并生成扁平树形结构
     * @param int $parentId 父级ID
     * @param bool $withButton 是否包含按钮类型
     * @return array
     */
    public static function getTreeList($parentId = 0, $withButton = false)
    {
        $query = self::where(['status' => self::STATUS_ENABLE])
            ->order('sort', 'asc')
            ->order('id', 'asc');
        
        if (!$withButton) {
            $query->where('type', '<>', self::TYPE_BUTTON);
        }
        
        $menus = $query->select()->toArray();

        $menus = \app\admin\service\MenuPermissionBridge::filterDisabledPluginRows($menus);

        return Tree::instance()->getTreeList(Tree::instance()->init($menus)->getTreeArray($parentId));
    }

    /**
     * 根据角色ID获取有权限的菜单
     * @param array|int $roleId 角色ID
     * @return array
     */
    public static function getMenuByRoleId($roleId)
    {
        if (CurrentAdmin::isSuperAdmin()) {
            return self::getTreeArray();
        }

        if (!is_array($roleId)) {
            $roleId = [$roleId];
        }
        $bridge = app(\app\admin\service\MenuPermissionBridge::class);
        $menuIds = $bridge->filterMenuIdsByRoles($roleId);
                      
        $allMenuIds = $menuIds;
        foreach ($menuIds as $id) {
            $parentIds = self::getParentIds($id);
            $allMenuIds = array_merge($allMenuIds, $parentIds);
        }
        
        $allMenuIds = array_unique($allMenuIds);
        
        return self::buildTreeByMenuIds($allMenuIds);
    }
    
    /**
     * 获取所有父级ID
     * @param int $menuId 菜单ID
     * @return array
     */
    public static function getParentIds($menuId)
    {
        $parentIds = [];
        $menu = self::find($menuId);
        
        while ($menu && $menu['parent_id'] != 0) {
            $parentIds[] = $menu['parent_id'];
            $menu = self::find($menu['parent_id']);
        }
        
        return $parentIds;
    }
    
    /**
     * 根据菜单ID数组构建树形结构
     * @param array $menuIds 菜单ID数组
     * @param int $parentId 父级ID
     * @return array
     */
    public static function buildTreeByMenuIds($menuIds, $parentId = 0)
    {
        $menus = self::where('parent_id', $parentId)
                    ->where('status', self::STATUS_ENABLE)
                    ->whereIn('id', $menuIds)
                    ->order('sort', 'asc')
                    ->order('id', 'asc')
                    ->select()
                    ->toArray();

        $menus = \app\admin\service\MenuPermissionBridge::filterDisabledPluginRows($menus);
                    
        foreach ($menus as &$menu) {
            $children = self::buildTreeByMenuIds($menuIds, $menu['id']);

            $menu['childlist'] = $children;
            $menu['children']  = $children;
        }
        unset($menu);
        
        return $menus;
    }

    /**
     * 根据权限ID获取菜单
     * @param int $permissionId 权限ID
     * @return \think\Model|array|null
     */
    public static function getMenuByPermissionId($permissionId)
    {
        return self::where('permission_id', $permissionId)
            ->where('status', self::STATUS_ENABLE)
            ->find();
    }

    /**
     * 批量创建菜单并联动创建权限（安装时使用，幂等）
     *
     * 策略：
     *   - 同 plugin + parent_id + path（或 name）的菜单已存在（含软删除）→ 复用/恢复
     *   - 菜单有 path → 自动创建/复用权限并双向绑定
     *   - 菜单无 path（纯目录） → 不生成权限
     *
     * @param string $pluginIdent      插件/实现标识
     * @param array  $menus            菜单数据数组
     * @param int    $parentId         父菜单ID
     * @param int    $permParentId     父权限ID
     * @param bool   $preserveExisting true=已存在的菜单不改写字段（保留运营改动，只补建/恢复）
     * @return bool
     */
    public static function createMenusWithPermissions(
        string $pluginIdent,
        array $menus,
        int $parentId = 0,
        int $permParentId = 0,
        bool $preserveExisting = false
    ): bool {
        $bridge = app(\app\admin\service\MenuPermissionBridge::class);
        $bridge->syncBatch($pluginIdent, $menus, $parentId, $permParentId, $preserveExisting);
        return true;
    }

    /**
     * 为菜单同步权限（菜单新增/编辑时调用）
     *
     * 策略：
     *   - 菜单有 path 且无关联权限 → 自动创建权限并绑定
     *   - 菜单有 path 且有关联权限 → 更新权限的 name/ident
     *   - 菜单无 path（纯目录） → 不创建权限
     *
     * @param self $menu 菜单模型实例
     * @return bool
     */
    public static function syncPermissionForMenu(self $menu): bool
    {
        $bridge = app(\app\admin\service\MenuPermissionBridge::class);
        return $bridge->syncPermissionForMenu($menu);
    }

    /**
     * 根据路径获取菜单
     * @param string $path 路径
     * @return array|null
     */
    public static function getPermissionCodeByPath($path = null){
        if (is_null($path)) {
            $path = app('http')->getName() . '/' . request()->controller(true) . '/' . request()->action(true);
        }
        $menu = self::where('path', $path)
            ->where('status', self::STATUS_ENABLE)
            ->find();
        if ($menu && $menu->getAttr('permission_id')) {
            $bridge = app(\app\admin\service\MenuPermissionBridge::class);
            return $bridge->getPermissionIdentByPath($path);
        }
        return null;
    }
}
