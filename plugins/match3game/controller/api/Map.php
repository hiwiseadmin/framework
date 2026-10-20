<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\match3game\service\LevelProgressService;

/**
 * 消消乐关卡地图 API（§6.3 map / progress/refresh）
 *
 * GET  /api/match3game/map/index     关卡地图（解锁态/星数/当前关）
 * POST /api/match3game/map/refresh   刷新当前关/解锁态
 */
class Map extends Base
{
    /**
     * 关卡地图
     */
    public function index()
    {
        $userId  = $this->userId();
        $service = new LevelProgressService();

        return $this->success('ok', $service->getMap($userId));
    }

    /**
     * 刷新当前关 / 解锁态（与 map 相同，客户端可轮询）
     */
    public function refresh()
    {
        $userId  = $this->userId();
        $service = new LevelProgressService();

        return $this->success('ok', $service->getMap($userId));
    }
}
