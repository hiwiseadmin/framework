<?php

declare(strict_types=1);

namespace wise\plugin\adapter;

use think\facade\Route;
use wise\support\context\ContextualLogger;

/**
 * 路由加载器 — 加载插件的路由定义文件。
 *
 * 支持三种路由类型：
 * - plugin.php：后台标准路由，URL 为 /plugin/{ident}/...
 * - api.php：API 路由（应用名=api 时自动加载）
 * - index.php：前端默认插件路由
 */
class RouteLoader
{
    /**
     * 插件目录根路径。
     *
     * @var string
     */
    protected string $pluginPath;

    public function __construct()
    {
        $this->pluginPath = plugin_path();
    }

    /**
     * 加载插件路由。
     *
     * @param string $pluginIdent 插件标识
     * @param bool   $isApi       是否为 API 请求
     * @param bool   $isDefault   是否为默认前端插件
     * @return void
     */
    public function load(string $pluginIdent, bool $isApi = false, bool $isDefault = false): void
    {
        $pluginDir = $this->pluginPath . $pluginIdent . DIRECTORY_SEPARATOR;

        if ($isApi) {
            $routeFile = $pluginDir . 'route' . DIRECTORY_SEPARATOR . 'api.php';
        } elseif ($isDefault) {
            $routeFile = $pluginDir . 'route' . DIRECTORY_SEPARATOR . 'index.php';
        } else {
            $routeFile = $pluginDir . 'route' . DIRECTORY_SEPARATOR . 'plugin.php';
        }

        if (!file_exists($routeFile)) {
            return;
        }

        try {
            if ($isApi || $isDefault) {
                Route::group(function () use ($routeFile) {
                    include $routeFile;
                });
            } else {
                Route::group('plugin/' . $pluginIdent, function () use ($routeFile) {
                    include $routeFile;
                });
            }
        } catch (\Throwable $e) {
            ContextualLogger::error(sprintf(
                '插件 "%s" 路由注册失败: %s',
                $pluginIdent,
                $e->getMessage()
            ));
            if (config('app.debug')) {
                throw $e;
            }
        }
    }
}
