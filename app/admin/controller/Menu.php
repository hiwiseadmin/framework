<?php
namespace app\admin\controller;

use app\common\controller\traits\CrudTrait;
use app\admin\model\Menu as MenuModel;
use wise\auth\model\Permission;
use app\admin\service\MenuPermissionBridge;
use app\admin\service\MenuItemSync;
use wise\support\tools\Tree;
use wise\operationlog\attribute\OperationLog;
use think\facade\Db;
use think\Model;

#[OperationLog(channel: 'admin', tags: ['admin', 'menu'])]
class Menu extends BaseController
{
    use CrudTrait;

    protected function getModel(): Model
    {
        return new MenuModel();
    }

    /**
     * 菜单首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 菜单列表（全量返回，非分页）
     */
    public function getList()
    {
        $menus = MenuModel::order('parent_id', 'asc')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        return $this->success('ok', ['list' => $menus, 'typeList' => MenuModel::getTypeText()]);
    }

    /**
     * 同步扩展点声明的后台菜单（ExtPoints::MENU_ITEM）
     *
     * 与 CLI `php think menu:sync` 同一实现（app\admin\service\MenuItemSync）；
     * 默认保留运营在后台的改动，仅补建缺失项与恢复软删项。
     *
     * 说明：本动作为新增入口，未在系统菜单里预置对应按钮权限，
     * 超级管理员可直接使用；其他管理员需先在「菜单管理」新增一个
     * 按钮菜单（路径 /admin/menu/sync）并授权。
     */
    #[OperationLog(template: '同步声明菜单', level: 'INFO')]
    public function sync()
    {
        try {
            $stat = app(MenuItemSync::class)->syncAll();

            $msg = sprintf(
                '同步完成：实现 %d，新增 %d，已存在 %d，停用 %d，清理 %d',
                $stat['packages'],
                $stat['created'],
                $stat['existing'],
                $stat['disabled'],
                $stat['pruned']
            );

            if (!empty($stat['orphans'])) {
                $msg .= sprintf(
                    '；发现已卸载实现 %d 个（%s），如需清理请执行 CLI：php think menu:sync --prune-orphans',
                    count($stat['orphans']),
                    implode('、', $stat['orphans'])
                );
            }

            return $this->success($msg, $stat);
        } catch (\Throwable $e) {
            return $this->error('同步失败：' . $e->getMessage());
        }
    }

    /**
     * 菜单预览
     */
    public function preview()
    {
        $id   = $this->request->param('id/d', 0);
        $menu = MenuModel::findOrEmpty($id);

        if ($menu->isEmpty()) {
            return $this->error('菜单不存在');
        }

        $menu->parent_name = $menu->parent_id ? MenuModel::where('id', $menu->parent_id)->value('name') : '顶级菜单';
        $menu->type_text   = MenuModel::getTypeText($menu->type);
        $menu->status_text = MenuModel::getStatusText($menu->status);

        return $this->assign('menu', $menu)->fetch();
    }

    /**
     * 新增页面（覆盖 trait，追加权限树/上级菜单/类型状态列表）
     */
    public function add()
    {
        $parent_id   = $this->request->param('id/d', 0);
        $permissions = Permission::where('status', 1)->select()->toArray();
        $parentMenus = MenuModel::getTreeList(0, false);

        return $this->assign([
            'isEdit'      => false,
            'row'         => null,
            'formAction'  => url('save'),
            'pageTitle'   => '添加菜单',
            'parent_id'   => $parent_id,
            'permissions' => $permissions,
            'parentMenus' => $parentMenus,
            'typeList'    => MenuModel::getTypeText(),
            'statusList'  => MenuModel::getStatusText(),
        ])->fetch('form');
    }

    /**
     * 新增保存
     */
    #[OperationLog(template: '创建菜单 {data.name}', level: 'INFO')]
    public function save()
    {
        $data = $this->request->param();
        unset($data['id']);

        $data = $this->filterDataGeneric(new MenuModel(), $data);

        try {
            (new MenuModel())->validateOrFail($data, [], 'add');

            $menu = MenuModel::create($data);

            MenuModel::syncPermissionForMenu($menu);

            return $this->success('菜单创建成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 编辑页面（覆盖 trait，追加权限树/上级菜单/类型状态列表）
     */
    public function edit()
    {
        $id   = $this->request->param('id/d', 0);
        $menu = MenuModel::find($id);

        if (!$menu) {
            return $this->error('菜单不存在');
        }

        $permissions = Permission::where('status', 1)->select()->toArray();

        $parentMenus = MenuModel::where('type', 'in', [MenuModel::TYPE_CATALOG, MenuModel::TYPE_MENU])
            ->where('status', 1)
            ->where('id', '<>', $id)
            ->select()->toArray();
        $parentMenus = Tree::instance()->getTreeList(Tree::instance()->init($parentMenus)->getTreeArray(0));

        return $this->assign([
            'isEdit'      => true,
            'row'         => $menu,
            'formAction'  => url('update'),
            'pageTitle'   => '编辑菜单',
            'permissions' => $permissions,
            'parentMenus' => $parentMenus,
            'typeList'    => MenuModel::getTypeText(),
            'statusList'  => MenuModel::getStatusText(),
        ])->fetch('form');
    }

    /**
     * 更新菜单
     */
    #[OperationLog(template: '更新菜单 ID:{id}', level: 'INFO')]
    public function update()
    {
        $id   = $this->request->param('id/d', 0);
        $data = $this->request->param();

        $data = $this->filterDataGeneric(new MenuModel(), $data);

        try {
            (new MenuModel())->validateOrFail($data, [], 'edit');

            $menu = MenuModel::find($id);
            if (!$menu) {
                return $this->error('菜单不存在');
            }

            $menu->save($data);

            MenuModel::syncPermissionForMenu($menu);

            return $this->success('菜单更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除前：检查子菜单
     */
    protected function beforeDelete(array $ids): void
    {
        foreach ($ids as $id) {
            if (MenuModel::where('parent_id', $id)->whereNotIn('id', $ids)->count() > 0) {
                throw new \think\Exception('请先删除子菜单');
            }
        }
    }

    /**
     * 删除后：清理关联权限
     */
    protected function afterDelete(array $ids): void
    {
        foreach ($ids as $id) {
            $menu = MenuModel::withTrashed()->find($id);
            if ($menu) {
                $bridge = app(MenuPermissionBridge::class);
                $bridge->deletePermissionForMenu($menu);
            }
        }
    }

    /**
     * 恢复后：重建菜单对应的权限节点并回绑
     *
     * `afterDelete()` 会在菜单删除时清掉关联权限，因此还原必须对称地补回来，
     * 否则还原后的菜单没有 permission_id，权限树里永远是断的。
     */
    protected function afterRestore(array $ids): void
    {
        foreach (MenuModel::whereIn('id', $ids)->select() as $menu) {
            MenuModel::syncPermissionForMenu($menu);
        }
    }

    /**
     * 恢复已删除菜单
     *
     * 委托给 `_restore()`：原实现调用的 `restoreByIds()` 只存在于可选的
     * `app\common\model\traits\SoftDeleteTrait`，Menu 模型并未引入，该方法实际不可用。
     */
    #[OperationLog(template: '恢复菜单', level: 'INFO')]
    public function restore()
    {
        return $this->_restore();
    }

    /**
     * 回收站列表（已删除菜单）
     */
    public function recycleList()
    {
        return $this->_recycleList();
    }

    /**
     * 签发「彻底删除」一次性确认令牌
     */
    public function recycleToken()
    {
        return $this->_recycleToken();
    }

    /**
     * 回收站：还原菜单
     */
    #[OperationLog(template: '还原回收站菜单', level: 'INFO')]
    public function recycleRestore()
    {
        return $this->_restore();
    }

    /**
     * 回收站：彻底删除菜单（仅限已在回收站的记录，需确认令牌）
     */
    #[OperationLog(template: '彻底删除回收站菜单', level: 'WARNING')]
    public function recycleForceDelete()
    {
        return $this->_forceDelete(true);
    }

    /**
     * 删除菜单（单条 / 批量）
     *
     * `beforeDelete()` 会联动清理菜单对应权限，因此入口必须委托给 trait 实现。
     */
    #[OperationLog(template: '删除菜单 ID:{id}', level: 'WARNING')]
    public function delete()
    {
        return $this->_delete();
    }

    /**
     * 切换菜单状态
     */
    #[OperationLog(template: '切换菜单 ID:{id} 状态', level: 'INFO')]
    public function status()
    {
        return $this->_status();
    }

    /**
     * 更新菜单排序值
     */
    #[OperationLog(template: '更新菜单排序', level: 'INFO')]
    public function sort()
    {
        return $this->_sort();
    }

    /**
     * 彻底删除菜单（物理删除，走 beforeDelete 清理关联权限）
     */
    #[OperationLog(template: '彻底删除菜单 ID:{id}', level: 'WARNING')]
    public function forceDelete()
    {
        return $this->_forceDelete();
    }

    /**
     * 获取菜单树形结构
     */
    public function getMenuTree()
    {
        $menus = MenuModel::select();
        return $this->success('获取成功', ['menus' => $menus->toArray()]);
    }

    /**
     * 拖动排序
     */
    #[OperationLog(template: '调整菜单排序', level: 'INFO')]
    public function dragsort()
    {
        $ids      = $this->request->param('ids', '');
        $changeid = $this->request->param('changeid', '');
        $pid      = $this->request->param('pid', '');
        $field    = $this->request->param('field', 'sort');

        if (!in_array($field, ['sort'], true)) {
            return $this->error('非法的排序字段');
        }

        if (empty($ids) || empty($changeid)) {
            return $this->error('参数错误');
        }

        $idsArr = explode(',', $ids);

        try {
            Db::startTrans();
            $weight = 0;
            foreach ($idsArr as $id) {
                $id = intval($id);
                if ($id <= 0) continue;
                MenuModel::where('id', $id)->update([$field => $weight]);
                $weight++;
            }
            Db::commit();
            return $this->success('排序成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量操作
     */
    #[OperationLog(template: '批量操作菜单', level: 'WARNING')]
    public function multi()
    {
        $action = $this->request->param('action', '');
        $ids    = $this->request->param('ids', '');
        $params = $this->request->param('params', '');

        if (empty($ids)) {
            return $this->error('请选择操作项');
        }

        $idsArr = explode(',', $ids);
        $idsArr = array_filter(array_map('intval', $idsArr));

        try {
            if ($action === 'del') {
                $model = $this->getModel();
                $this->beforeDelete($idsArr);
                Db::startTrans();
                $model->whereIn($model->getPk(), $idsArr)->delete();
                $this->afterDelete($idsArr);
                Db::commit();
                return $this->success('批量删除成功');
            }
            return $this->error('不支持的操作');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        }
    }
}
