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
        // 联动重置插件服务提供者的进程级 boot 标记，保证同进程内可完整重新初始化
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
        // 仅在首次请求时初始化插件（后续请求复用已注册的服务）
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
            /** @var App $app */
            $app = app();

            if (!$app->has(PluginContainerKeys::MANAGER)) {
                return;
            }

            /** @var PluginManager $manager */
            $manager = $app->get(PluginContainerKeys::MANAGER);
            $plugins = $manager->getAllPlugins();

            /** @var string $name */
            foreach ($plugins as $name => $metadata) {
                if (!$metadata->isEnabled()) {
                    continue;
                }

                // 库包依赖闸门：插件声明了**硬依赖**（manifest.packages，如 wiseadmin/wise-member）
                // 但该库包未安装时，其继承的基类 / 中间件类都不存在 —— 加载即 500。
                // 这里直接跳过并记日志：库包装回后下次请求自动恢复，无需人工改状态。
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
                        // 日志不可用，忽略
                    }

                    continue;
                }

                try {
                    $manager->initServiceProvider($name);
                } catch (\Throwable $e) {
                    // 单插件失败不阻断其他插件和请求
                    try {
                        $app->log->error(sprintf(
                            '插件 "%s" 启动失败: %s',
                            $name,
                            $e->getMessage()
                        ));
                    } catch (\Throwable $logError) {
                        // 日志不可用，忽略
                    }
                }
            }
        } catch (\Throwable $e) {
            // 整个初始化流程失败，记录但不阻断请求
            try {
                app()->log->error('插件系统初始化失败: ' . $e->getMessage());
            } catch (\Throwable $logError) {
                // 日志不可用，忽略
            }
        }
    }
}
