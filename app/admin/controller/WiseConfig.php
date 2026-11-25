<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\common\security\AccessPolicy;
use think\facade\View;
use app\admin\controller\traits\WiseConfigTrait;
use wise\config\ConfigManager;
use wise\config\model\ConfigModel;
use wise\config\DictSDK;

/**
 * 配置管理控制器（CRUD + 继承 + 标签 + 缓存）
 *
 * 提供命名空间为顶级维度、分组卡片为二级维度的配置管理界面。
 *
 * 页面架构：
 *   [命名空间Tab] [命名空间Tab] [+新建]      [🔍搜索] [🕐历史] [🗄缓存] [⬇导出]
 *   ┌─── 分组卡片 ───────────────────────────────┐
 *   │  站点名称     WiseAdmin       [复制] [编辑] [历史] │
 *   └──────────────────────────────────────────────┘
 *
 * @route admin/wise_config/*
 */
class WiseConfig extends BaseController
{
    use WiseConfigTrait;

    protected function _initialize()
    {
        $this->initWiseConfig();
    }


    /**
     * 配置管理主页面
     */
    public function index()
    {
        $wsconfig = View::__get('wsconfig');
        $wsconfig['js'] = '../admin/wise_config';

        $namespaces = ConfigModel::distinct(true)->column('namespace');
        $namespaces = $namespaces ?: ['default'];

        $groups = ConfigModel::where('namespace', 'default')
            ->order('sort', 'asc')
            ->distinct(true)
            ->column('group');

        return $this->assign([
            'wsconfig'   => $wsconfig,
            'namespaces' => $namespaces,
            'groups'     => $groups ?: [],
            'tagManager' => $this->tagManager !== null,
        ])->fetch();
    }


    /**
     * 获取单条配置详情（用于编辑弹窗）
     */
    public function detail()
    {
        $id = $this->request->param('id/d', 0);
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }
        $data = $config->toArray();
        if ($this->tagManager) {
            try {
                $data['tags_array'] = $this->tagManager->getTags($id);
            } catch (\Throwable $e) {
                $data['tags_array'] = [];
            }
        } else {
            $data['tags_array'] = [];
        }
        return $this->success('ok', $data);
    }

    /**
     * 按 namespace+group 获取分组内的全部配置
     *
     * 默认仅返回公开（visibility=1）配置项；
     * 私有配置仅供模块内部使用，不向系统配置页面展示。
     * 可通过 include_private=1 临时包含私有配置（调试用）。
     */
    public function getGroupConfigs()
    {
        $namespace      = $this->request->param('namespace', 'default');
        $group          = $this->request->param('group', '');
        $includePrivate = $this->request->param('include_private/d', 0);

        if (empty($group)) {
            return $this->error('分组名不能为空');
        }

        if ($includePrivate && !$this->isSuperAdmin()) {
            return $this->error('无权查看私有配置', [], 403);
        }

        $query = ConfigModel::where('namespace', $namespace)
            ->where('group', $group);
        if (!$includePrivate) {
            $query->where('visibility', 1);
        }
        $configs = $query->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($this->tagManager) {
            foreach ($configs as &$item) {
                $item['tags_array'] = $this->tagManager->getTags($item['id']);
            }
            unset($item);
        }

        return $this->success('ok', $configs);
    }

    /**
     * 获取命名空间下所有分组名称列表
     */
    public function getNamespaceGroups()
    {
        $namespace = $this->request->param('namespace', 'default');
        $groups = ConfigModel::where(['namespace' => $namespace, 'visibility'=>1])
            ->order('sort', 'asc')
            ->distinct(true)
            ->column('group');

        return $this->success('ok', $groups ?: []);
    }

    /**
     * 获取所有私有（非公开）配置项
     *
     * 用于系统配置页面的"临时展示非公开配置项"调试视图。
     * 该能力仅用于调试、重置或删除私有配置，不会持久化任何展示状态。
     * 返回按命名空间/分组/排序组织的私有配置列表。
     */
    public function getPrivateConfigs()
    {
        if (!$this->isSuperAdmin()) {
            return $this->error('无权查看私有配置', [], 403);
        }

        $configs = ConfigModel::where('visibility', 0)
            ->order('namespace', 'asc')
            ->order('group', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($this->tagManager) {
            foreach ($configs as &$item) {
                $item['tags_array'] = $this->tagManager->getTags($item['id']);
            }
            unset($item);
        }

        $grouped = [];
        foreach ($configs as $item) {
            $grouped[$item['namespace']][$item['group']][] = $item;
        }

        return $this->success('ok', [
            'list'    => $configs,
            'grouped' => $grouped,
        ]);
    }

    /**
     * 获取所有命名空间（含继承关系信息）
     */
    public function listNamespaces()
    {
        $namespaces = ConfigModel::distinct(true)->order('namespace', 'asc')->column('namespace');

        $result = [];
        foreach ($namespaces as $ns) {
            $item = ['name' => $ns, 'parent' => null];
            $parts = explode('.', $ns);
            if (count($parts) > 1) {
                array_pop($parts);
                $parent = implode('.', $parts);
                if (in_array($parent, $namespaces)) {
                    $item['parent'] = $parent;
                }
            }
            $result[] = $item;
        }

        return $this->success('ok', $result);
    }

    /**
     * 获取命名空间所有配置（用于全局搜索）
     */
    public function search()
    {
        $keyword = $this->request->param('keyword', '');
        if (mb_strlen($keyword) < 1) {
            return $this->success('ok', []);
        }

        $escaped = addcslashes((string) $keyword, '%_\\');
        $list = ConfigModel::where('key|title|value|description|tags', 'like', "%{$escaped}%")
            ->where('visibility', 1)
            ->order('namespace', 'asc')
            ->order('group', 'asc')
            ->limit(20)
            ->select()
            ->toArray();

        return $this->success('ok', $list);
    }

    /**
     * 保存配置（新增/更新，支持行内编辑）
     */
    public function save()
    {
        $data  = $this->request->post();
        $isNew = empty($data['id']);

        $result = $this->validateSaveData($data);
        if ($result !== true) {
            return $this->error($result);
        }

        $namespace = $data['namespace'] ?? 'default';
        $group     = $data['group'] ?? 'system';

        $visibility = isset($data['visibility']) ? (int) $data['visibility'] : 1;
        if (!$isNew) {
            $existing = ConfigModel::find((int) $data['id']);
            if ($existing && !$this->canAccessConfig($existing)) {
                return $this->error('无权限操作私有配置');
            }
        }
        if (!AccessPolicy::canAccessConfig($this->isSuperAdmin(), $visibility)) {
            return $this->error('无权限操作私有配置');
        }

        if ($isNew) {
            $exists = ConfigModel::where('namespace', $namespace)
                ->where('key', $data['key'])
                ->find();
            if ($exists) {
                return $this->error("键名 '{$data['key']}' 在当前命名空间中已存在");
            }
        }

        $extra = [
            'type'        => $data['type'] ?? 'text',
            'group'       => $group,
            'title'       => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'inherit'     => isset($data['inherit']) ? (int)$data['inherit'] : 1,
            'sort'        => isset($data['sort']) ? (int)$data['sort'] : 0,
            'status'      => isset($data['status']) ? (int)$data['status'] : 1,
            'visibility'  => isset($data['visibility']) ? (int)$data['visibility'] : 1,
        ];

        if (isset($data['tags']) && is_array($data['tags'])) {
            $extra['tags'] = $data['tags'];
        }

        $path  = $namespace . ':' . $group . ':' . $data['key'];
        $value = $data['value'] ?? '';
        $id    = $isNew ? null : (int)$data['id'];

        try {
            $result = $this->configManager
                ->namespace($namespace)
                ->set($path, $value, $extra, $id);

            if ($result) {
                return $this->success($isNew ? '新增成功' : '保存成功');
            }
            return $this->error('保存失败');
        } catch (\Exception $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 校验配置保存数据
     *
     * 返回 true 表示通过；否则返回错误提示字符串。
     * 主要防止：
     *  - key/namespace/group 含冒号或特殊字符导致配置路径被篡改（路径注入）
     *  - type 传入白名单之外的任意值
     *  - title/description 超长导致数据异常
     *
     * @param array $data
     * @return true|string
     */
    private function validateSaveData(array $data)
    {
        $key = $data['key'] ?? '';
        if ($key === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
            return '配置键名只能包含字母、数字、下划线和中划线';
        }

        $namespace = $data['namespace'] ?? 'default';
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $namespace)) {
            return '命名空间只能包含字母、数字、下划线和中划线';
        }

        $group = $data['group'] ?? 'system';
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $group)) {
            return '分组名只能包含字母、数字、下划线和中划线';
        }

        $type = $data['type'] ?? 'text';
        if (!in_array($type, ['text', 'number', 'html', 'image', 'json'], true)) {
            return '配置类型不合法';
        }

        if (isset($data['title']) && mb_strlen((string) $data['title']) > 100) {
            return '配置标题长度不能超过100个字符';
        }
        if (isset($data['description']) && mb_strlen((string) $data['description']) > 1000) {
            return '配置描述长度不能超过1000个字符';
        }

        return true;
    }

    /**
     * 是否为超级管理员（探测失败一律拒绝）
     *
     * @return bool
     */
    private function isSuperAdmin(): bool
    {
        try {
            return (bool) \app\admin\facade\CurrentAdmin::isSuperAdmin();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 是否允许访问指定配置项（私有配置仅超管）
     */
    private function canAccessConfig($config): bool
    {
        $visibility = 1;
        if (is_object($config)) {
            $visibility = (int) ($config->visibility ?? 1);
        } elseif (is_array($config)) {
            $visibility = (int) ($config['visibility'] ?? 1);
        }

        return AccessPolicy::canAccessConfig($this->isSuperAdmin(), $visibility);
    }

    /**
     * 行内快速编辑（仅更新 value）
     */
    public function quickEdit()
    {
        $id    = $this->request->post('id/d', 0, 'intval');
        $value = $this->request->post('value', '');

        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }

        $path  = $config->namespace . ':' . $config->group . ':' . $config->key;
        $extra = [
            'type'       => $config->type,
            'group'      => $config->group,
            'title'      => $config->title,
            'inherit'    => $config->inherit,
            'sort'       => $config->sort,
            'status'     => $config->status,
            'visibility' => $config->visibility ?? 1,
        ];

        try {
            $this->configManager
                ->namespace($config->namespace)
                ->set($path, $value, $extra, $id);

            return $this->success('更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除配置
     */
    public function delete()
    {
        $id = $this->request->post('id/d', 0);
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }

        $path = $config->namespace . ':' . $config->group . ':' . $config->key;
        try {
            if ($this->configManager->namespace($config->namespace)->delete($path)) {
                return $this->success('删除成功');
            }
            return $this->error('删除失败');
        } catch (\Exception $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 切换配置启用/禁用
     */
    public function toggleStatus()
    {
        $id     = $this->request->post('id/d', 0);
        $status = $this->request->post('status/d', 1);
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }
        $config->status = $status;
        $config->save();
        $this->configManager->clearAllCache();
        return $this->success($status == 1 ? '已启用' : '已禁用');
    }

    /**
     * 获取各命名空间配置数量
     */
    public function namespaceConfigCounts()
    {
        $counts = ConfigModel::field('namespace, COUNT(*) as count')
            ->group('namespace')
            ->select()
            ->toArray();

        $result = [];
        foreach ($counts as $row) {
            $result[$row['namespace']] = (int)$row['count'];
        }
        return $this->success('ok', $result);
    }


    /**
     * 查看配置继承来源
     */
    public function inheritStatus()
    {
        $id = $this->request->param('id/d', 0);
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }

        $path = $config->namespace . ':' . $config->group . ':' . $config->key;
        $status = $this->configManager
            ->namespace($config->namespace)
            ->getOverrideStatus($path);

        return $this->success('ok', array_merge($status ?: [], [
            'config' => $config->toArray(),
        ]));
    }

    /**
     * 重置配置（恢复继承）
     */
    public function resetInherit()
    {
        $id = $this->request->post('id/d', 0);
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }
        $path = $config->namespace . ':' . $config->group . ':' . $config->key;
        if ($this->configManager->namespace($config->namespace)->reset($path)) {
            return $this->success('已恢复继承');
        }
        return $this->error('操作失败');
    }


    /**
     * 给配置添加标签
     */
    public function tagAdd()
    {
        $id  = $this->request->post('id/d', 0);
        $tag = trim($this->request->post('tag', ''));
        if (!$id || !$tag) {
            return $this->error('参数错误');
        }
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }
        $path = $config->namespace . ':' . $config->group . ':' . $config->key;
        if ($this->configManager->namespace($config->namespace)->addTag($path, $tag)) {
            return $this->success('标签已添加');
        }
        return $this->error('添加失败');
    }

    /**
     * 移除标签
     */
    public function tagRemove()
    {
        $id  = $this->request->post('id/d', 0);
        $tag = trim($this->request->post('tag', ''));
        if (!$id || !$tag) {
            return $this->error('参数错误');
        }
        $config = ConfigModel::find($id);
        if (!$config) {
            return $this->error('配置项不存在');
        }
        if (!$this->canAccessConfig($config)) {
            return $this->error('无权限操作私有配置');
        }
        $path = $config->namespace . ':' . $config->group . ':' . $config->key;
        if ($this->configManager->namespace($config->namespace)->removeTag($path, $tag)) {
            return $this->success('标签已移除');
        }
        return $this->error('移除失败');
    }


    /**
     * 刷新配置缓存
     */
    public function refreshCache()
    {
        $this->configManager->clearAllCache();
        return $this->success('配置缓存已刷新');
    }

    /**
     * 清除字典缓存
     */
    public function clearDictCache()
    {
        DictSDK::clearAllCache();
        return $this->success('字典缓存已清除');
    }
}
