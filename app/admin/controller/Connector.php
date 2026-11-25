<?php
declare(strict_types=1);

namespace app\admin\controller;

use wise\support\connector\ConnectorManager;
use wise\support\connector\ConnectorType;

/**
 * 连接器配置管理
 *
 * 管理所有已注册的外部系统连接器，支持配置编辑和健康检查。
 */
class Connector extends BaseController
{
    /**
     * 连接器管理首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取所有连接器列表
     */
    public function getList()
    {
        $manager = app(ConnectorManager::class);
        $all = $manager->all();
        $result = [];

        foreach ($all as $type => $connectors) {
            foreach ($connectors as $name => $connector) {
                $result[] = [
                    'id'          => $name,
                    'name'        => $connector->getName(),
                    'type'        => $type,
                    'type_label'  => ConnectorType::from($type)->label(),
                    'description' => $connector->getDescription(),
                    'actions'     => array_keys($connector->getActions()),
                    'healthy'     => $this->safeHealthCheck($manager, $name),
                    'config'      => $connector->getConfig(),
                ];
            }
        }

        return $this->success('ok', ['connectors' => $result]);
    }

    /**
     * 健康检查
     */
    public function health()
    {
        $name = $this->request->post('name', '');
        if (empty($name)) {
            return $this->error('缺少连接器名称');
        }

        $manager = app(ConnectorManager::class);
        $healthy = $this->safeHealthCheck($manager, $name);

        return $this->success('ok', ['name' => $name, 'healthy' => $healthy]);
    }

    /**
     * 获取连接器配置
     */
    public function config()
    {
        $name = $this->request->get('name', '');
        if (empty($name)) {
            return $this->error('缺少连接器名称');
        }

        $manager = app(ConnectorManager::class);
        $connector = $manager->driver($name);

        return $this->success('ok', [
            'name'   => $name,
            'title'  => $connector->getName(),
            'type'   => $connector->getType()->value,
            'schema' => $connector->getConfigSchema(),
            'config' => $connector->getConfig(),
        ]);
    }

    /**
     * 保存连接器配置
     */
    public function save()
    {
        $name = $this->request->post('name', '');
        $config = $this->request->post('config/a', []);

        if (empty($name)) {
            return $this->error('缺少连接器名称');
        }

        $manager = app(ConnectorManager::class);
        $manager->setConfig($name, $config);

        return $this->success('配置已保存');
    }

    protected function safeHealthCheck(ConnectorManager $manager, string $name): bool
    {
        try {
            return $manager->healthCheck($name);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
