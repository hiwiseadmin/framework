<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\service\model\MassAssignmentGuard;

/**
 * 后台广告管理（**计划功能**，保留不动）
 *
 * 决策记录见建表迁移 `20251102000019_create_wisecms_ad_table.php`：
 * 广告系统仅有后台 CRUD、暂无前台消费方，属计划功能而非死代码。
 *
 * 注：`wisecms_ad` 无 `site_id` 列，故列表不跟随后台站点选择器（保持全站共享）。
 */
class Ad extends BaseController
{
    use PageFallbackTrait;

    public function index()
    {
        $list = \plugin\wisecms\model\Ad::order('sort', 'asc')->paginate(15);
        if ($this->request->isAjax()) return $this->success('', $list->toArray());
        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $list->toArray());
        return $this->fetch('admin/ad/index');
    }

    public function create()
    {
        if ($this->request->isPost()) {
            // P1-1：黑名单剔除 id / create_time / view_count / click_count 等非表单字段
            $data = $this->normalizeTimeWindow(
                MassAssignmentGuard::filter($this->request->post(), 'wisecms_ad')
            );
            // create_time / update_time 由模型 autoWriteTimestamp 维护
            \plugin\wisecms\model\Ad::create($data);
            return $this->success('广告创建成功');
        }
        return $this->fetch('admin/ad/create');
    }

    public function edit()
    {
        $ad = \plugin\wisecms\model\Ad::find($this->request->param('id'));
        if (!$ad) return $this->pageFallback('广告不存在', plugin_url('wisecms/ad/index'));
        if ($this->request->isPost()) {
            // P1-1：同上（计数列由前台累加，不接受客户端写入）
            $ad->save($this->normalizeTimeWindow(
                MassAssignmentGuard::filter($this->request->post(), 'wisecms_ad')
            ));
            return $this->success('更新成功');
        }
        $this->assign('ad', $ad);
        return $this->fetch('admin/ad/edit');
    }

    /**
     * 生效/失效时间归一（时间字段统一：两列为 DATETIME，NULL = 不限）
     *
     * 表单控件为 `datetime-local`，提交值是 `2026-10-05T12:00`（含 T），
     * 直接入库会被 MySQL 拒绝，故统一走 cms_datetime()。
     */
    private function normalizeTimeWindow(array $data): array
    {
        foreach (['start_time', 'end_time'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = cms_datetime($data[$field]);
            }
        }

        return $data;
    }

    public function delete()
    {
        \plugin\wisecms\model\Ad::destroy($this->request->post('id'));
        return $this->success('广告已删除');
    }
}
