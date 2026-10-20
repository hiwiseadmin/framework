<?php
namespace app\service;

use think\Service;

/**
 * 扩展加载器服务
 */
class ExtendLoaderService extends Service
{
    /** @var array 所有扩展配置的集合 */
    protected $extensions = [];

    /** @var string 缓存文件路径 */
    protected $cacheFile;

    /** @var string 扩展根目录 */
    protected $extendPath;

    public function register()
    {
        $this->extendPath = $this->app->getRootPath() . 'extend';
        if (!is_dir($this->extendPath)) {
            return;
        }

        $this->cacheFile = $this->app->getRuntimePath() . 'extend_cache.php';

        // 获取当前扩展目录的指纹（hash）
        $currentFingerprint = $this->getExtendFingerprint();
        $cachedExtensions   = null;
        $needScan           = true;

        // 如果缓存文件存在，读取并比较指纹
        if (file_exists($this->cacheFile)) {
            $cacheData = include $this->cacheFile;
            if (is_array($cacheData) && isset($cacheData['fingerprint'], $cacheData['extensions'])) {
                if ($cacheData['fingerprint'] === $currentFingerprint) {
                    // 指纹一致，直接使用缓存
                    $this->extensions = $cacheData['extensions'];
                    $needScan = false;
                }
            }
        }

        // 需要重新扫描
        if ($needScan) {
            $this->extensions = $this->scanExtensions($this->extendPath);
            // 写入新缓存（包含指纹）
            $this->writeCache($this->extensions, $currentFingerprint);
        }

        // 注册各项资源（register 阶段允许做的事）
        foreach ($this->extensions as $ext) {
            // 1. 注册服务类
            foreach ($ext['services'] as $serviceClass) {
                if (class_exists($serviceClass)) {
                    $this->app->register($serviceClass);
                }
            }

            // 2. 注册中间件别名
            if (!empty($ext['middleware']['alias'])) {
                $middlewareAlias = $this->app->config->get('middleware.alias', []);
                if (!is_array($middlewareAlias)) {
                    $middlewareAlias = [];
                }
                $middlewareAlias = array_merge($middlewareAlias, $ext['middleware']['alias']);
                $this->app->config->set(['alias' => $middlewareAlias], 'middleware');
            }

            // 3. 注册命令行
            if (!empty($ext['commands'])) {
                $this->commands($ext['commands']);
            }
        }
    }

    public function boot()
    {
        // boot 阶段加载需要框架基础设施就绪的资源（路由、事件、独立配置）
        foreach ($this->extensions as $ext) {
            $extPath = $this->extendPath . DIRECTORY_SEPARATOR . $ext['name'] . DIRECTORY_SEPARATOR;

            // 加载扩展专属配置文件
            if (!empty($ext['config'])) {
                $configFile = $extPath . $ext['config'];
                if (file_exists($configFile)) {
                    $config = include $configFile;
                    if (is_array($config)) {
                        // 以扩展名作为配置域，避免污染全局配置
                        $this->app->config->set($config, $ext['name']);
                    }
                }
            }

            // 加载事件定义
            if (!empty($ext['event'])) {
                $eventFile = $extPath . $ext['event'];
                if (file_exists($eventFile)) {
                    $events = include $eventFile;
                    if (is_array($events)) {
                        foreach ($events as $event => $listeners) {
                            if (is_array($listeners)) {
                                foreach ($listeners as $listener) {
                                    $this->app->event->listen($event, $listener);
                                }
                            } else {
                                $this->app->event->listen($event, $listeners);
                            }
                        }
                    }
                }
            }

            // 加载路由文件
            if (!empty($ext['routes'])) {
                $routeFile = $extPath . $ext['routes'];
                if (file_exists($routeFile)) {
                    $this->loadRoutesFrom($routeFile);
                }
            }
        }
    }

    /**
     * 计算当前 extend 目录的指纹（基于所有扩展的 extend.json 修改时间与内容哈希）
     */
    protected function getExtendFingerprint(): string
    {
        $hashData = '';
        $dirs = glob($this->extendPath . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
            return md5('');
        }
        sort($dirs); // 保证顺序一致
        foreach ($dirs as $dir) {
            $jsonFile = $dir . DIRECTORY_SEPARATOR . 'extend.json';
            if (file_exists($jsonFile)) {
                $hashData .= $dir . filemtime($jsonFile) . filesize($jsonFile);
            }
        }
        return md5($hashData);
    }

    /**
     * 扫描扩展目录，返回标准化后的扩展配置数组
     */
    protected function scanExtensions(string $extendPath): array
    {
        $extensions = [];
        $dirs = glob($extendPath . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $dir) {
            $name = basename($dir);
            $jsonFile = $dir . DIRECTORY_SEPARATOR . 'extend.json';
            if (!file_exists($jsonFile)) {
                continue;
            }

            $jsonContent = file_get_contents($jsonFile);
            if ($jsonContent === false) {
                continue;
            }
            $config = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // 可以记录日志提示 JSON 格式错误
                continue;
            }

            // 提供默认值并标准化
            $ext = [
                'name'       => $name,
                'services'   => (array) ($config['services'] ?? []),
                'middleware' => [
                    'alias'    => (array) ($config['middleware']['alias'] ?? []),
                    'priority' => (array) ($config['middleware']['priority'] ?? []),
                ],
                'commands'   => (array) ($config['commands'] ?? []),
                'routes'     => (string) ($config['routes'] ?? ''),
                'config'     => (string) ($config['config'] ?? ''),
                'event'      => (string) ($config['event'] ?? ''),
            ];

            $extensions[] = $ext;
        }

        return $extensions;
    }

    /**
     * 写入缓存文件（含指纹）
     */
    protected function writeCache(array $extensions, string $fingerprint): void
    {
        $data = [
            'fingerprint' => $fingerprint,
            'extensions'  => $extensions,
        ];
        $content = "<?php\r\nreturn " . var_export($data, true) . ";";
        // 原子写入，避免并发读取不完整
        file_put_contents($this->cacheFile, $content, LOCK_EX);
        // 清空 opcache 对缓存文件的影响
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }
    }
}