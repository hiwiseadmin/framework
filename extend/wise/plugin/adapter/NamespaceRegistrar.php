<?php

declare(strict_types=1);

namespace wise\plugin\adapter;

use wise\support\context\ContextualLogger;

/**
 * 命名空间注册器 — 注册插件的 PSR-4 命名空间到 Composer 自动加载器。
 *
 * 处理两类命名空间：
 * 1. 插件主命名空间（plugin\{ident}\  → plugins/{ident}/）
 * 2. 第三方命名空间（从 manifest.json 的 vendor 字段读取）
 */
class NamespaceRegistrar
{
    /**
     * 获取 Composer ClassLoader 单例（延迟加载，后续调用复用缓存）。
     *
     * @return \Composer\Autoload\ClassLoader
     */
    private static function getLoader(): \Composer\Autoload\ClassLoader
    {
        static $loader;
        if ($loader === null) {
            $loader = require root_path() . 'vendor/autoload.php';
        }
        return $loader;
    }

    /**
     * 注册插件主命名空间。
     *
     * @param string $pluginIdent 插件标识
     * @param string $pluginDir   插件目录
     * @return void
     */
    public function register(string $pluginIdent, string $pluginDir): void
    {
        try {
            $loader = self::getLoader();
            $prefixes = $loader->getPrefixesPsr4();
            $namespace = "plugin\\{$pluginIdent}\\";

            if (!isset($prefixes[$namespace])) {
                $loader->addPsr4($namespace, $pluginDir);
            }

            // 注册后必须清掉该前缀的「类不存在」负缓存，否则注册前的探测结果会继续生效。
            self::forgetMissingClasses($loader, $namespace);
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 命名空间注册失败: %s',
                $pluginIdent,
                $e->getMessage()
            ));
        }
    }

    /**
     * 清除 Composer ClassLoader 中指定命名空间前缀的「类不存在」负缓存。
     *
     * 背景（插件全量 403 的根因）：
     *   `/plugin/{ident}/...` 请求会先经过 app\plugin\middleware\PluginAdminAuth，
     *   该中间件在**插件命名空间尚未注册**时调用 class_exists('plugin\{ident}\controller\X')
     *   来判定控制器基类。Composer 会把这次查找失败写入 ClassLoader::$missingClasses
     *   （私有负缓存），而 addPsr4() **不会**清除它。
     *   于是随后 NamespaceRegistrar::register() 即使成功 addPsr4()，
     *   class_exists() 依旧命中负缓存返回 false，PluginDispatch 最终抛出
     *   「Plugin controller not exists」→ 403，表现为插件所有后台路径不可访问。
     *
     * 修复：注册命名空间后，主动移除该前缀对应的负缓存条目，让查找结果重新生效。
     * 只清理目标前缀，避免影响其它类的负缓存性能收益。
     *
     * @param \Composer\Autoload\ClassLoader $loader
     * @param string                          $namespace 形如 "plugin\demo\"
     */
    private static function forgetMissingClasses(\Composer\Autoload\ClassLoader $loader, string $namespace): void
    {
        try {
            $property = new \ReflectionProperty(\Composer\Autoload\ClassLoader::class, 'missingClasses');
            $property->setAccessible(true);

            $missing = $property->getValue($loader);
            if (!is_array($missing) || $missing === []) {
                return;
            }

            foreach (array_keys($missing) as $class) {
                if (str_starts_with((string) $class, $namespace)) {
                    unset($missing[$class]);
                }
            }

            $property->setValue($loader, $missing);
        } catch (\Throwable $e) {
            // 不同 Composer 版本的内部结构差异不影响主流程，忽略
        }
    }

    /**
     * 注册插件第三方命名空间（manifest.json 的 vendor 字段）。
     *
     * @param string $pluginDir  插件目录
     * @param array  $namespaces 第三方命名空间列表（从 manifest.json vendor 读取）
     * @return void
     */
    public function registerVendor(string $pluginDir, array $namespaces): void
    {
        if (empty($namespaces)) {
            return;
        }

        try {
            $loader = self::getLoader();
            $prefixes = $loader->getPrefixesPsr4();
            $extendDir = $pluginDir . 'extend' . DIRECTORY_SEPARATOR;

            foreach ($namespaces as $ns) {
                if (!isset($prefixes[$ns])) {
                    $loader->addPsr4($ns, $extendDir);
                }
            }
        } catch (\Throwable $e) {
            ContextualLogger::warning(sprintf(
                '插件 "%s" 第三方命名空间注册失败: %s',
                basename($pluginDir),
                $e->getMessage()
            ));
        }
    }
}
