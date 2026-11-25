<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\controller\traits\WiseConfigTrait;
use wise\config\model\ConfigModel;

/**
 * 配置分组管理控制器
 *
 * 路由: admin/wise_config/group/*
 * 实际使用 admin/wise_config/renameGroup 等旧路由（通过路由映射保持兼容）
 */
class WiseConfigGroup extends BaseController
{
    use WiseConfigTrait;

    protected function _initialize()
    {
        $this->initWiseConfig();
    }

    /**
     * 重命名分组
     */
    public function renameGroup()
    {
        $namespace  = $this->request->post('namespace', 'default');
        $oldGroup   = $this->request->post('old_group', '');
        $newGroup   = $this->request->post('new_group', '');

        if (empty($oldGroup) || empty($newGroup)) {
            return $this->error('分组名不能为空');
        }
        if ($oldGroup === $newGroup) {
            return $this->success('分组名未变化');
        }

        $count = ConfigModel::where('namespace', $namespace)
            ->where('group', $oldGroup)
            ->update(['group' => $newGroup]);

        $this->configManager->clearAllCache();
        return $this->success("已将 {$count} 条配置移至新分组");
    }

    /**
     * 删除分组（含组内所有配置）
     */
    public function deleteGroup()
    {
        $namespace = $this->request->post('namespace', 'default');
        $group     = $this->request->post('group', '');
        $force     = $this->request->post('force/d', 0);

        if (empty($group)) {
            return $this->error('分组名不能为空');
        }

        $count = ConfigModel::where('namespace', $namespace)
            ->where('group', $group)
            ->count();

        if ($count > 0 && !$force) {
            return $this->error('group_has_items', ['count' => $count]);
        }

        ConfigModel::where('namespace', $namespace)
            ->where('group', $group)
            ->delete();

        $this->configManager->clearAllCache();
        return $this->success("分组 '{$group}' 已删除（共 {$count} 条配置）");
    }

    /**
     * 获取适用于当前命名空间的所有分组（含空分组的虚拟占位）
     */
    public function availableGroups()
    {
        $namespace = $this->request->param('namespace', 'default');
        $groups = ConfigModel::where('namespace', $namespace)
            ->distinct(true)
            ->order('sort', 'asc')
            ->column('group');

        return $this->success('ok', $groups ?: []);
    }
}
