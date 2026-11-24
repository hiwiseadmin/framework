<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateMenusTable extends Migrator
{
    public function up()
    {
        $table = $this->table('menus', [
            'engine' => 'InnoDB',
            'comment' => '菜单表',
            'collation' => 'utf8mb4_unicode_ci'
        ]);

        $table->addColumn(Column::integer('parent_id')->setDefault(0)->setComment('父级菜单ID,0表示顶级菜单'))
              ->addColumn(Column::string('name', 100)->setComment('菜单名称'))
              ->addColumn(Column::string('icon', 50)->setNullable()->setComment('菜单图标'))
              ->addColumn(Column::string('path', 255)->setComment('菜单路径/路由'))
              ->addColumn(Column::string('component', 255)->setNullable()->setComment('前端组件路径'))
              ->addColumn(Column::integer('permission_id')->setNullable()->setComment('关联权限ID'))
              ->addColumn(Column::integer('sort')->setDefault(0)->setComment('排序号,越小越靠前'))
              ->addColumn(Column::enum('type', ['dir', 'menu', 'button'])->setDefault('menu')->setComment('菜单类型 dir:目录,menu:菜单,button:按钮'))
              ->addColumn(Column::string('plugin', 30)->setComment('插件名称,system:系统,其它为插件名称'))
              ->addColumn(Column::tinyInteger('mount')->setDefault(0)->setComment('插件挂载模式 0:module顶级模块 1:sidebar_group侧边栏分组 2:sidebar_fixed固定分组 3:launcher启动台'))
              ->addColumn(Column::string('group', 50)->setDefault('')->setComment('固定分组名，仅 sidebar_fixed 使用'))
              ->addColumn(Column::tinyInteger('pinned')->setDefault(0)->setComment('是否钉选到顶部导航 0:否 1:是'))
              ->addColumn(Column::tinyInteger('is_plugin_root')->setDefault(0)->setComment('是否为插件根容器节点 0:否 1:是'))
              ->addColumn(Column::string('description', 255)->setDefault('')->setComment('菜单描述'))
              ->addColumn(Column::tinyInteger('status')->setDefault(1)->setComment('状态 0:禁用,1:启用'))
              ->addColumn(Column::datetime('create_time')->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setComment('更新时间'))
              ->addColumn(Column::datetime('delete_time')->setNullable()->setComment('删除时间'));
              
        $menus = $this->generateMenuData();

        $table->insert($menus)->create();
    }
   
    /**
     * 插入默认菜单数据
     */
    protected function generateMenuData()
    {
        $time = date('Y-m-d H:i:s');
        
        $menus = [];
        $idCounter = 1;


        $consoleId = $idCounter++;
        $menus[] = $this->createMenuItem($consoleId, 0, '控制台', '', '', '', null, 0, 'dir', 1, $time);
        
        $dashboardId = $idCounter++;
        $menus[] = $this->createMenuItem($dashboardId, $consoleId, '数据面板', 'bi bi-speedometer', '/admin/dashboard/index', '', null, 100, 'menu', 1, $time);
        
        $manageId = $idCounter++;
        $menus[] = $this->createMenuItem($manageId, $consoleId, '权限管理', 'bi bi-person-fill-gear', '', '', null, 200, 'dir', 1, $time);
        
        $adminId = $idCounter++;
        $menus[] = $this->createMenuItem($adminId, $manageId, '用户管理', 'bi bi-people', '/admin/admin/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '用户列表', '', '/admin/admin/getlist', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '预览用户', '', '/admin/admin/preview', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '新增用户', '', '/admin/admin/add', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '保存用户', '', '/admin/admin/save', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '编辑用户', '', '/admin/admin/edit', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '更新用户', '', '/admin/admin/update', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '删除用户', '', '/admin/admin/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '彻底删除用户', '', '/admin/admin/forcedelete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '恢复用户', '', '/admin/admin/restore', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '启用/禁用用户', '', '/admin/admin/status', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '重置密码', '', '/admin/admin/resetPassword', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $adminId, '分配角色', '', '/admin/admin/assignRole', '', null, 0, 'button', 1, $time);

        $menuManageId = $idCounter++;
        $menus[] = $this->createMenuItem($menuManageId, $manageId, '菜单管理', 'bi bi-menu-button-fill', '/admin/menu/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '菜单列表', '', '/admin/menu/getlist', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '预览菜单', '', '/admin/menu/preview', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '新增菜单', '', '/admin/menu/add', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '保存菜单', '', '/admin/menu/save', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '编辑菜单', '', '/admin/menu/edit', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '更新菜单', '', '/admin/menu/update', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '删除菜单', '', '/admin/menu/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '彻底删除菜单', '', '/admin/menu/forcedelete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '恢复菜单', '', '/admin/menu/restore', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '启用/禁用菜单', '', '/admin/menu/status', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $menuManageId, '同步声明菜单', '', '/admin/menu/sync', '', null, 0, 'button', 1, $time);

        $roleId = $idCounter++;
        $menus[] = $this->createMenuItem($roleId, $manageId, '角色管理', 'bi bi-person-vcard', '/admin/role/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '角色列表', '', '/admin/role/getlist', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '预览角色', '', '/admin/role/preview', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '新增角色', '', '/admin/role/add', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '保存角色', '', '/admin/role/save', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '编辑角色', '', '/admin/role/edit', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '更新角色', '', '/admin/role/update', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '删除角色', '', '/admin/role/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '彻底删除角色', '', '/admin/role/forcedelete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '恢复角色', '', '/admin/role/restore', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $roleId, '启用/禁用角色', '', '/admin/role/status', '', null, 0, 'button', 1, $time);
        
        $permissionId = $idCounter++;
        $menus[] = $this->createMenuItem($permissionId, $manageId, '权限配置', 'bi bi-person-lines-fill', '/admin/permission/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '权限列表', '', '/admin/permission/getlist', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '预览权限', '', '/admin/permission/preview', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '新增权限', '', '/admin/permission/add', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '保存权限', '', '/admin/permission/save', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '编辑权限', '', '/admin/permission/edit', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '更新权限', '', '/admin/permission/update', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '删除权限', '', '/admin/permission/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '彻底删除权限', '', '/admin/permission/forcedelete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '恢复权限', '', '/admin/permission/restore', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $permissionId, '启用/禁用权限', '', '/admin/permission/status', '', null, 0, 'button', 1, $time);

        $sysConfId = $idCounter++;
        $menus[] = $this->createMenuItem($sysConfId, $consoleId, '系统配置', 'bi bi-gear-fill', '', '', null, 300, 'dir', 1, $time);

        $wiseConfigId = $idCounter++;
        $menus[] = $this->createMenuItem($wiseConfigId, $sysConfId, '系统配置', 'bi bi-sliders2', '/admin/wise_config/index', '', null, 0, 'menu', 1, $time);

        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '分组配置列表', '', '/admin/wise_config/getGroupConfigs', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '命名空间分组', '', '/admin/wise_config/getNamespaceGroups', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '命名空间列表', '', '/admin/wise_config/listNamespaces', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '搜索配置', '', '/admin/wise_config/search', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '保存配置', '', '/admin/wise_config/save', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '快速编辑', '', '/admin/wise_config/quickEdit', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '删除配置', '', '/admin/wise_config/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '切换状态', '', '/admin/wise_config/toggleStatus', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '继承状态', '', '/admin/wise_config/inheritStatus', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '恢复继承', '', '/admin/wise_config/resetInherit', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '添加标签', '', '/admin/wise_config/tagAdd', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '移除标签', '', '/admin/wise_config/tagRemove', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '刷新缓存', '', '/admin/wise_config/refreshCache', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '清除字典缓存', '', '/admin/wise_config/clearDictCache', '', null, 0, 'button', 1, $time);

        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '重命名分组', '', '/admin/wise_config/renameGroup', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '可用分组', '', '/admin/wise_config/availableGroups', '', null, 0, 'button', 1, $time);

        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '配置历史', '', '/admin/wise_config/history', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '历史回滚', '', '/admin/wise_config/historyRevert', '', null, 0, 'button', 1, $time);

        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '导出配置', '', '/admin/wise_config/export', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '复制命名空间', '', '/admin/wise_config/copyToNamespace', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseConfigId, '上传图片', '', '/admin/wise_config/uploadImage', '', null, 0, 'button', 1, $time);
        $wiseDictId = $idCounter++;
        $menus[] = $this->createMenuItem($wiseDictId, $sysConfId, '字典管理', 'bi bi-journal-bookmark-fill', '/admin/wise_dict/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '字典类型列表', '', '/admin/wise_dict/list', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '字典类型详情', '', '/admin/wise_dict/detail', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '保存字典类型', '', '/admin/wise_dict/save', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '删除字典类型', '', '/admin/wise_dict/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '切换字典类型状态', '', '/admin/wise_dict/toggleStatus', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '字典项列表', '', '/admin/wise_dict/items', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '保存字典项', '', '/admin/wise_dict/itemSave', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '删除字典项', '', '/admin/wise_dict/itemDelete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '批量删除字典项', '', '/admin/wise_dict/itemDeleteBatch', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '批量更新字典项状态', '', '/admin/wise_dict/itemBatchStatus', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $wiseDictId, '字典项树', '', '/admin/wise_dict/itemTree', '', null, 0, 'button', 1, $time);
        
        $configId = $idCounter++;
        $menus[] = $this->createMenuItem($configId, $consoleId, '常规配置', 'bi bi-gear', '', '', null, 400, 'dir', 1, $time);

        $iconsId = $idCounter++;
        $menus[] = $this->createMenuItem($iconsId, $configId, '图标管理', '', '/admin/icons/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $iconsId, '图标下载', '', '/admin/icons/download_svg', '', null, 0, 'menu', 1, $time);


        $attachmentId = $idCounter++;
        $menus[] = $this->createMenuItem($attachmentId, $consoleId, '附件管理', 'bi bi-files', '', '', null, 500, 'dir', 1, $time);
        $attachmentListId = $idCounter++;
        $menus[] = $this->createMenuItem($attachmentListId, $attachmentId, '附件列表', 'bi bi-list', '/admin/attachment/index', '', null, 0, 'menu', 1, $time);
        $storageCategoryId = $idCounter++;
        $menus[] = $this->createMenuItem($storageCategoryId, $attachmentId, '存储分类', 'bi bi-folder', '/admin/attachment_category/index', '', null, 0, 'button', 1, $time);
        $attachmentTrashId = $idCounter++;
        $menus[] = $this->createMenuItem($attachmentTrashId, $attachmentId, '回收站', 'bi bi-trash', '/admin/attachment/trash', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $attachmentTrashId, '恢复附件', '', '/admin/attachment/restore', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $attachmentTrashId, '清空回收站', '', '/admin/attachment/emptyTrash', '', null, 0, 'button', 1, $time);
        $storageConfigId = $idCounter++;
        $menus[] = $this->createMenuItem($storageConfigId, $attachmentId, '存储配置', 'bi bi-hdd-stack', '/admin/storage_config/index', '', null, 0, 'menu', 1, $time);

        $logId = $idCounter++;
        $menus[] = $this->createMenuItem($logId, $consoleId, '日志管理', 'bi bi-code-slash', '', '', null, 700, 'dir', 1, $time);
        $logLoginId = $idCounter++;
        $menus[] = $this->createMenuItem($logLoginId, $logId, '登录日志', 'bi bi-box-arrow-in-right', '/admin/login_log/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $logLoginId, '查看日志', '', '/admin/login_log/show', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $logLoginId, '删除日志', '', '/admin/login_log/delete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $logLoginId, '导出日志', '', '/admin/login_log/export', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $logLoginId, '清空日志', '', '/admin/login_log/clear', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $logLoginId, '彻底删除日志', '', '/admin/login_log/forcedelete', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $logLoginId, '恢复日志', '', '/admin/login_log/restore', '', null, 0, 'button', 1, $time);

        $pluginId = $idCounter++;
        $menus[] = $this->createMenuItem($pluginId, $consoleId, '插件管理', 'bi bi-plugin', '', '', null, 800, 'dir', 1, $time);
        $pluginListId = $idCounter++;
        $menus[] = $this->createMenuItem($pluginListId, $pluginId, '插件列表', 'bi bi-list', '/admin/plugin/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginListId, '安装', 'bi bi-box-arrow-down', '/admin/plugin/install', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginListId, '卸载', 'bi bi-box-arrow-up', '/admin/plugin/uninstall', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginListId, '启用', 'bi bi-play-fill', '/admin/plugin/enable', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginListId, '禁用', 'bi bi-stop-fill', '/admin/plugin/disable', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginListId, '插件配置', 'bi bi-gear', '/admin/plugin/config_index', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginListId, '保存配置', 'bi bi-save', '/admin/plugin/config', '', null, 0, 'button', 1, $time);

        $pluginCapabilityListId = $idCounter++;
        $menus[] = $this->createMenuItem($pluginCapabilityListId, $pluginId, '能力市场', 'bi bi-grid', '/admin/capability/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginCapabilityListId, '切换状态', 'bi bi-toggle-on', '/admin/capability/toggle', '', null, 0, 'button', 1, $time);

        $pluginConnectorListId = $idCounter++;
        $menus[] = $this->createMenuItem($pluginConnectorListId, $pluginId, '连接器配置', 'bi bi-link-45deg', '/admin/connector/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginConnectorListId, '健康检查', 'bi bi-heart-pulse', '/admin/connector/health', '', null, 0, 'button', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginConnectorListId, '保存配置', 'bi bi-save', '/admin/connector/save', '', null, 0, 'button', 1, $time);

        $pluginSlotListId = $idCounter++;
        $menus[] = $this->createMenuItem($pluginSlotListId, $pluginId, '插槽管理', 'bi bi-puzzle', '/admin/slot/index', '', null, 0, 'menu', 1, $time);
        $menus[] = $this->createMenuItem($idCounter++, $pluginSlotListId, '刷新内容', 'bi bi-arrow-repeat', '/admin/slot/contents', '', null, 0, 'button', 1, $time);

        $extensionId = $idCounter++;
        $menus[] = $this->createMenuItem($extensionId, $consoleId, '扩展管理', 'bi bi-columns-gap', '', '', null, 900, 'dir', 1, $time);


        return $menus;
    }
    
    /**
     * 创建菜单
     *
     * @param int $id 菜单 ID（可选）
     * @param int $parent_id 父菜单 ID（可选）
     * @param string $name 菜单名称
     * @param string $icon 图标类名
     * @param string $path 路径（可选）
     * @param string $component 组件（可选）
     * @param int|null $permission_id 权限 ID（可选）
     * @param int $sort 排序（可选）
     * @param string $type 类型（可选）
     * @param int $status 状态（可选）
     * @param string $time 创建时间（可选）
     */
    private function createMenuItem(int $id,int $parent_id, string $name, string $icon, string $path, string $component, ?int $permission_id, int $sort, string $type, int $status, string $time)
    {
        $menu = [
            'parent_id' => $parent_id,
            'name' => $name,
            'icon' => $icon,
            'path' => $path,
            'component' => $component,
            'permission_id' => $permission_id,
            'plugin' => 'system',
            'sort' => $sort,
            'type' => $type,
            'status' => $status,
            'create_time' => $time,
            'update_time' => $time
        ];
        
        if($id != null){
            $menu['id'] = $id;
        }
        
        return $menu;
    }

    public function down(): void
    {
        if ($this->hasTable('menus')) {
            $this->table('menus')->drop()->save();
        }
    }
}
