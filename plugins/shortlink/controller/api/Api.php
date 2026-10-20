<?php
namespace plugin\shortlink\controller\api;

use plugin\ApiController;
use plugin\shortlink\service\ShortLinkService;
use plugin\shortlink\service\StatService;
use plugin\shortlink\model\Link;
use app\admin\facade\CurrentAdmin;

/**
 * ShortLink 短链接 API 控制器
 *
 * 提供短链接的创建、查询、删除、统计接口。
 * 基于后台管理员会话鉴权（CurrentAdmin），通过 catch-all 统一调度。
 */
class Api extends ApiController
{
    /**
     * 获取当前登录管理员 ID
     *
     * @return int
     */
    protected function getAdminId(): int
    {
        try {
            return (int) CurrentAdmin::getAdminId();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 检查当前管理员是否有权操作指定资源
     *
     * @param int $resourceAdminId 资源所属管理员 ID
     * @return bool
     */
    protected function canManage(int $resourceAdminId): bool
    {
        if ($this->isSuperAdmin()) return true;
        return $resourceAdminId === $this->getAdminId();
    }

    /**
     * 判断是否为超级管理员
     *
     * @return bool
     */
    protected function isSuperAdmin(): bool
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) return false;
        $superIds = \think\facade\Config::get('app.super_admin_ids', []);
        return in_array($adminId, $superIds);
    }

    /**
     * 创建短链接
     */
    public function create()
    {
        $data    = $this->request->post();
        $adminId = $this->getAdminId();
        try {
            $link = (new ShortLinkService())->create($data, $adminId);
            return $this->success('创建成功', [
                'id'        => $link->id,
                'code'      => $link->code,
                'short_url' => $link->short_url,
                'long_url'  => $link->long_url,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 查询短链接信息
     */
    public function info()
    {
        $code = $this->request->param('code');
        if (empty($code)) return $this->error('短码不能为空');
        $link = (new ShortLinkService())->parse($code);
        if (!$link) return $this->error('短链接不存在');
        // 权限校验：只允许创建者或超管查看
        if (!$this->canManage((int) $link->admin_id)) {
            return $this->error('无权查看该短链接');
        }
        return $this->success('success', [
            'id'           => $link->id,
            'code'         => $link->code,
            'long_url'     => $link->long_url,
            'title'        => $link->title,
            'short_url'    => $link->short_url,
            'status'       => $link->status,
            'total_visits' => $link->total_visits,
            'unique_visits'=> $link->unique_visits,
            'expire_time'  => $link->expire_time,
        ]);
    }

    /**
     * 删除短链接
     */
    public function delete()
    {
        $id = $this->request->post('id/d', 0);
        if ($id <= 0) return $this->error('ID 不能为空');
        try {
            $link = Link::find($id);
            if (!$link) return $this->error('短链接不存在');
            if (!$this->canManage((int) $link->admin_id)) {
                return $this->error('无权删除该短链接');
            }
            (new ShortLinkService())->delete($id);
            return $this->success('删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 查询短链接统计
     */
    public function stats()
    {
        $id = $this->request->param('id/d', 0);
        if ($id <= 0) return $this->error('ID 不能为空');
        try {
            $link = Link::find($id);
            if (!$link) return $this->error('短链接不存在');
            if (!$this->canManage((int) $link->admin_id)) {
                return $this->error('无权查看该短链接');
            }
            return $this->success('success', (new StatService())->getStats($id));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
