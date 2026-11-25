<?php

declare(strict_types=1);

namespace wise\plugin\middleware;

use think\App;
use think\Request;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;

/**
 * 插件加载中间件
 *
 * 替代 PluginServiceProvider::initializeEnabledPlugins()，
 * 使用 ThinkPHP 标准中间件模式在请求管道中初始化所有已启用插件。
 *
 * 优势：
 * - 单插件异常不阻断其他插件和整个请求
 * - 错误隔离在请求范围内，不影响应用稳定性
 * - 符合 ThinkPHP 中间件惯例
 * - 可通过中间件配置灵活控制执行顺序
 */
class PluginLoader
{
    /**
     * 是否已完成首次初始化（跨请求共享）。
     *
     * 注意：PHP-FPM 常驻进程下，booted 置为 true 后不会自动重置。
     * 运行期间安装/启用新插件后，需重启 PHP-FPM 或调用 resetBootState()。
     *
     * @var bool
     */
    protected static bool $booted = false;

    /**
     * 重置启动状态（供管理命令或测试用例调用）。
     */
    public static function resetBootState(): void
    {
        static::$booted = false;
        \wise\plugin\WiseBootServiceProvider::resetBootState();
    }

    /**
     * 处理请求
     *
     * @param Request  $request 请求对象
     * @param \Closure $next    下一个中间件
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if (!static::$booted) {
            static::$booted = true;
            $this->bootPlugins($request);
        }

        return $next($request);
    }

    /**
     * 初始化所有已启用插件
     *
     * @param Request $request
     * @return void
     */
    protected function bootPlugins(Request $request): void
    {
        try {
            $app = app();

            if (!$app->has(PluginContainerKeys::MANAGER)) {
                return;
            }

            $manager = $app->get(PluginContainerKeys::MANAGER);
            $plugins = $manager->getAllPlugins();

            foreach ($plugins as $name => $metadata) {
                if (!$metadata->isEnabled()) {
                    continue;
                }

                $missingPackages = $manager->missingPackages($metadata);

                if ($missingPackages !== []) {
                    try {
                        $app->log->warning(sprintf(
                            '插件 "%s" 依赖的库包未安装，已跳过加载：%s',
                            $name,
                            implode('、', array_map(
                                static fn (array $item): string => $item['package'],
                                $missingPackages
                            ))
                        ));
                    } catch (\Throwable $logError) {
                    }

                    continue;
                }

                try {
                    $manager->initServiceProvider($name);
                } catch (\Throwable $e) {
                    try {
                        $app->log->error(sprintf(
                            '插件 "%s" 启动失败: %s',
                            $name,
                            $e->getMessage()
                        ));
                    } catch (\Throwable $logError) {
                    }
                }
            }
        } catch (\Throwable $e) {
            try {
                app()->log->error('插件系统初始化失败: ' . $e->getMessage());
            } catch (\Throwable $logError) {
            }
        }
    }
}
