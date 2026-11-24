<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\controller\BaseController;
use app\common\slot\SlotManager;

/**
 * 前端插槽 API 控制器
 *
 * 为前端 SlotManager 提供插槽定义和内容数据。
 *
 * @package app\admin\controller
 */
class Slot extends BaseController
{
    /**
     * 插槽管理首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取指定页面的插槽数据
     *
     * GET /admin/slot/list?page=dashboard
     *
     * @return \think\response\Json
     */
    public function list()
    {
        $page = $this->request->get('page', 'dashboard');

        $data = SlotManager::toFrontendData($page);

        return $this->success('ok', [
            'page'  => $page,
            'slots' => $data,
        ]);
    }

    /**
     * 获取指定插槽的内容
     *
     * GET /admin/slot/contents?slot=dashboard.widgets.top
     *
     * @return \think\response\Json
     */
    public function contents()
    {
        $slotName = $this->request->get('slot', '');

        if (empty($slotName)) {
            return $this->error('缺少 slot 参数');
        }

        $def = SlotManager::getDefinition($slotName);
        if (!$def) {
            return $this->error("插槽 [{$slotName}] 未定义");
        }

        $contents = SlotManager::resolveContents($slotName);

        return $this->success('ok', [
            'slot'     => $slotName,
            'title'    => $def['title'],
            'renderer' => $def['renderer'],
            'contents' => array_values($contents),
        ]);
    }

    /**
     * 获取所有已定义的插槽（用于管理后台）
     *
     * GET /admin/slot/definitions
     *
     * @return \think\response\Json
     */
    public function definitions()
    {
        $defs = SlotManager::getDefinitions();
        $result = [];

        foreach ($defs as $name => $def) {
            $def['contents_count'] = count(SlotManager::resolveContents($name));
            $result[] = $def;
        }

        return $this->success('ok', [
            'definitions' => $result,
            'total'       => count($result),
        ]);
    }
}
