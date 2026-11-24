<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Cache;
use think\facade\Log;

/**
 * 运行时缓存清理服务
 *
 * 后台「清理缓存」按钮与 CLI（php think wise:cache:clear）共用同一套逻辑，
 * 控制器只负责参数校验与响应，不再直接操作文件系统。
 *
 * 设计约束（改动前请先读完）：
 *  1. 只清白名单内的目标，不做「扫 runtime 目录猜模块」的全量删除 ——
 *     旧实现用排除法清 runtime/{dir}/temp，新增业务目录带 temp 时会被误删。
 *  2. 视图编译目录用 rename 原子切换，避免「删到一半被并发请求 include 到不完整文件」。
 *  3. **禁止**使用默认 store 的 Cache::clear()：File 驱动 clear() 删的是 path 整体
 *     （vendor/topthink/framework/src/think/cache/driver/File.php），而默认 store
 *     未显式配置 path（驱动实例化时取 runtime/{应用}/cache/）、prefix 为空，
 *     会连带删掉该应用下的 shared_file / wise_auth 等同级目录，导致全员权限重建。
 *  4. 资源版本号已迁到独立文件（见 AssetVersion），config 清理不会再误删它，
 *     因此两者完全解耦：只有 module 目标才会自增版本号。
 *
 * @see \app\admin\controller\Idler::clearCache()
 * @see \wise\command\system\CacheClear
 */
class CacheClearService
{
    /** 视图编译缓存：runtime/temp + runtime/{应用}/temp */
    public const TARGET_TEMPLATE = 'template';

    /** 插件缓存：runtime/plugin/temp + 插件短周期缓存键 */
    public const TARGET_PLUGIN = 'plugin';

    /** 配置缓存：shared_file store + 字典 tag */
    public const TARGET_CONFIG = 'config';

    /** 组件缓存（资源版本）：前端静态资源版本号，仅影响浏览器缓存 */
    public const TARGET_MODULE = 'module';

    /** 运行日志：runtime/log 下超期日志 */
    public const TARGET_LOG = 'log';

    /**
     * 可清理目标注册表
     *
     * in_all = true 的目标参与「一键清理」，新增目标只需改这里 + 实现 clean{Target}()。
     */
    public const TARGETS = [
        self::TARGET_TEMPLATE => ['label' => '模板缓存', 'in_all' => true],
        self::TARGET_PLUGIN   => ['label' => '插件缓存', 'in_all' => true],
        self::TARGET_CONFIG   => ['label' => '配置缓存', 'in_all' => true],
        self::TARGET_MODULE   => ['label' => '组件缓存', 'in_all' => true],
        self::TARGET_LOG      => ['label' => '运行日志', 'in_all' => false],
    ];

    /**
     * 固定执行顺序
     *
     * 版本号已与配置缓存解耦（见 AssetVersion），顺序不再承担「先清后刷」的职责，
     * 保留固定顺序只是为了输出稳定、便于逐项核对。
     */
    protected const EXEC_ORDER = [
        self::TARGET_TEMPLATE,
        self::TARGET_PLUGIN,
        self::TARGET_CONFIG,
        self::TARGET_MODULE,
        self::TARGET_LOG,
    ];

    /** 日志保留天数（log 目标） */
    protected const LOG_KEEP_DAYS = 7;

    /** 并发清理锁文件（放 runtime 根，避免被视图缓存清理连带删除） */
    protected const LOCK_FILE = 'cache_clear.lock';

    protected string $runtimePath;

    public function __construct(?string $runtimePath = null)
    {
        $this->runtimePath = rtrim($runtimePath ?? runtime_path(), '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 归一化清理目标
     *
     * 支持 'all'、'template,config'、['template', 'plugin'] 三种入参形式。
     *
     * @param string|array $type
     * @return string[] 按 EXEC_ORDER 排好序的目标列表
     * @throws \InvalidArgumentException 出现未知目标时抛出
     */
    public function resolve(string|array $type): array
    {
        $raw = is_array($type) ? $type : explode(',', $type);
        $raw = array_values(array_filter(array_map('trim', $raw), static fn ($v) => $v !== ''));

        if ($raw === []) {
            $raw = [self::TARGET_TEMPLATE];
        }

        if (in_array('all', $raw, true)) {
            $raw = array_keys(array_filter(self::TARGETS, static fn ($meta) => $meta['in_all']));
        }

        $unknown = array_diff($raw, array_keys(self::TARGETS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('未知的缓存类型：' . implode(',', $unknown));
        }

        return array_values(array_intersect(self::EXEC_ORDER, array_unique($raw)));
    }

    /**
     * 执行清理
     *
     * @param string|array $type
     * @return array{
     *     targets: array<int, array{type: string, label: string, deleted: int, failed: array}>,
     *     deleted: int,
     *     failed: array,
     *     took_ms: int
     * }
     * @throws \InvalidArgumentException 未知目标
     * @throws \RuntimeException 已有清理任务在执行
     */
    public function clear(string|array $type): array
    {
        $targets = $this->resolve($type);

        $lock = $this->acquireLock();
        if ($lock === null) {
            throw new \RuntimeException('已有清理任务在执行，请稍后再试');
        }

        $started = microtime(true);
        $results = [];

        try {
            foreach ($targets as $target) {
                $meta    = self::TARGETS[$target];
                $method  = 'clean' . ucfirst($target);
                $partial = $this->{$method}();

                $results[$target] = [
                    'type'    => $target,
                    'label'   => $meta['label'],
                    'deleted' => $partial['deleted'],
                    'failed'  => $partial['failed'],
                ];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $failed = [];
        foreach ($results as $result) {
            $failed = array_merge($failed, $result['failed']);
        }

        if ($failed !== []) {
            Log::warning('[cache-clear] 部分文件清理失败', ['failed' => array_slice($failed, 0, 20)]);
        }

        return [
            'targets' => array_values($results),
            'deleted' => (int) array_sum(array_column($results, 'deleted')),
            'failed'  => $failed,
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }


    /**
     * 模板缓存：所有视图编译目录
     *
     * 视图编译目录恒为名为 temp 的目录（think-view 默认 cache_path =
     * runtime/temp，多应用/插件为 runtime/{app}/temp），因此按目录名精确命中，
     * 不依赖「排除 cache/log/session」这种会随新增目录失效的猜测。
     *
     * @return array{deleted: int, failed: array}
     */
    protected function cleanTemplate(): array
    {
        $stat = ['deleted' => 0, 'failed' => []];

        $this->merge($stat, $this->sweepTrash());

        foreach ($this->viewCacheDirs() as $dir) {
            $this->merge($stat, $this->purge($dir));
        }

        return $stat;
    }

    /**
     * 插件缓存：插件视图编译产物 + 插件短周期缓存键
     *
     * @return array{deleted: int, failed: array}
     */
    protected function cleanPlugin(): array
    {
        $stat = ['deleted' => 0, 'failed' => []];

        foreach (glob($this->runtimePath . 'plugin/temp', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->merge($stat, $this->deleteContents($dir));
        }

        try {
            Cache::delete('plugin_remote_category_index');
        } catch (\Throwable $e) {
            $stat['failed'][] = 'plugin_remote_category_index: ' . $e->getMessage();
        }

        return $stat;
    }

    /**
     * 配置缓存：shared_file store + 字典 tag
     *
     * 说明：File 驱动的 clear() 只删 <path>/<prefix>，即 runtime/cache/shared/，
     * 不会波及默认 file store（未配 path，驱动实例化时取 runtime/{应用}/cache/）
     * 与 wise-auth 独立目录（runtime/cache/wise_auth/，由 AuthServiceProvider 固定）。
     * 权限缓存请用 php think wise:auth:clear 单独清。
     *
     * 局限：字典 tag 清的是「本次请求所在应用」的默认 store（admin → runtime/admin/cache/），
     * 其它应用的 dict 缓存不经由此处清理；多应用共用字典时需各自清一次。
     *
     * @return array{deleted: int, failed: array}
     */
    protected function cleanConfig(): array
    {
        $stat = ['deleted' => 0, 'failed' => []];

        $sharedDir = $this->storeDir('shared_file');
        $stat['deleted'] += $this->countFiles($sharedDir);

        try {
            Cache::store('shared_file')->clear();
        } catch (\Throwable $e) {
            $stat['failed'][] = 'shared_file: ' . $e->getMessage();
        }

        try {
            $driver = Cache::store();
            if (method_exists($driver, 'tag')) {
                $driver->tag('dict')->clear();
            }
        } catch (\Throwable $e) {
            $stat['failed'][] = 'dict tag: ' . $e->getMessage();
        }


        return $stat;
    }

    /**
     * 组件缓存：资源版本号自增
     *
     * 不删任何服务端文件，唯一作用是让页面里 ?_v= 变化，使浏览器重新拉取
     * js/css（见 app/middleware/Environment::resolveAssetVersion()）。
     *
     * 版本号存 runtime/asset_version（见 AssetVersion）：
     *  - 只有这里（和 CLI 同名目标）会自增，其余清理不会删它 → 永久单调；
     *  - 每次点击必然 +1，不再有「同一秒内点两次版本不变」的问题；
     *  - 与服务器时钟解耦，NTP 回拨也不会让版本回退。
     *
     * @return array{deleted: int, failed: array}
     */
    protected function cleanModule(): array
    {
        $stat = ['deleted' => 0, 'failed' => []];

        try {
            AssetVersion::bump();
        } catch (\Throwable $e) {
            $stat['failed'][] = 'asset_version: ' . $e->getMessage();
        }

        return $stat;
    }

    /**
     * 运行日志：删除超过保留天数的日志文件（目录结构保留）
     *
     * @return array{deleted: int, failed: array}
     */
    protected function cleanLog(): array
    {
        $stat     = ['deleted' => 0, 'failed' => []];
        $deadline = time() - self::LOG_KEEP_DAYS * 86400;

        foreach (glob($this->runtimePath . 'log/*/*.log') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime >= $deadline) {
                continue;
            }

            if (@unlink($file)) {
                $stat['deleted']++;
            } else {
                $stat['failed'][] = $file;
            }
        }

        return $stat;
    }


    /**
     * 解析 File 驱动 store 的实际缓存目录（path + prefix）
     *
     * 与 vendor/topthink/framework/src/think/cache/driver/File.php 的
     * getCacheKey()/clear() 口径保持一致：文件落在 <path>/<prefix>/ 下。
     */
    protected function storeDir(string $store): string
    {
        $path   = (string) (config('cache.stores.' . $store . '.path') ?: $this->runtimePath . 'cache');
        $prefix = (string) (config('cache.stores.' . $store . '.prefix') ?: '');

        $dir = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
        if ($prefix !== '') {
            $dir .= $prefix . DIRECTORY_SEPARATOR;
        }

        return $dir;
    }

    /**
     * 统计目录下的文件数量（供 store 级清理提供可读的删除数）
     */
    protected function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        } catch (\Throwable) {
        }

        return $count;
    }

    /**
     * 视图编译目录全集：runtime/temp + runtime/{应用}/temp
     *
     * @return string[]
     */
    protected function viewCacheDirs(): array
    {
        $dirs = [];

        if (is_dir($this->runtimePath . 'temp')) {
            $dirs[] = $this->runtimePath . 'temp';
        }

        foreach (glob($this->runtimePath . '*/temp', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[] = $dir;
        }

        return array_values(array_unique($dirs));
    }

    /**
     * 清理历史残留的原子切换垃圾目录（temp_gc_*）
     *
     * purge() 把旧目录改名成 `<temp>_gc_<时间>_<随机>` 后删除；rename 成功后若删除
     * 失败（Windows 文件被占用、权限不足），这些目录就留在磁盘上，而
     * viewCacheDirs() 只匹配名为 temp 的目录，正常路径永远不会再命中这些垃圾目录
     * → 每次点击都会新增一个残留。这里在清理前统一扫一遍，能删就删，删不掉如实记入 failed。
     *
     * @return array{deleted: int, failed: array}
     */
    protected function sweepTrash(): array
    {
        $stat = ['deleted' => 0, 'failed' => []];

        $patterns = [
            $this->runtimePath . 'temp_gc_*',
            $this->runtimePath . '*/temp_gc_*',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $trash) {
                $this->merge($stat, $this->deleteContents($trash, true));
            }
        }

        return $stat;
    }

    /**
     * 原子清空目录：rename 旧目录 → 立刻建回同名空目录 → 删除旧目录
     *
     * rename 在同盘内是原子操作：正在编译/读取的请求不会看到「删了一半」的目录，
     * 新请求落到新建的空目录后按需重新编译。
     *
     * @return array{deleted: int, failed: array}
     */
    protected function purge(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['deleted' => 0, 'failed' => []];
        }

        $trash = $dir . '_gc_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

        if (!@rename($dir, $trash)) {
            return $this->deleteContents($dir);
        }

        $stat = ['deleted' => 0, 'failed' => []];

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $stat['failed'][] = '目录重建失败：' . $dir;
        }

        $this->merge($stat, $this->deleteContents($trash, true));

        return $stat;
    }

    /**
     * 递归删除目录内容，逐项统计成功/失败
     *
     * 刻意不复用 DirFile::rmDirs()：它用 @ 抑制全部错误且恒返回 true，
     * 上层 try/catch 因此永远捕不到「删不掉」，前端会假报成功。
     *
     * @param string $dir      目标目录
     * @param bool   $withself 是否连目录自身一起删
     * @return array{deleted: int, failed: array}
     */
    protected function deleteContents(string $dir, bool $withself = false): array
    {
        $stat = ['deleted' => 0, 'failed' => []];

        if (!is_dir($dir)) {
            return $stat;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                $path = $file->getPathname();

                if ($file->isFile() || $file->isLink()) {
                    if (@unlink($path)) {
                        $stat['deleted']++;
                    } else {
                        $stat['failed'][] = $path;
                    }
                } elseif (!@rmdir($path)) {
                    $stat['failed'][] = $path;
                }
            }

            if ($withself && !@rmdir($dir)) {
                $stat['failed'][] = $dir;
            }
        } catch (\Throwable $e) {
            $stat['failed'][] = $dir . ': ' . $e->getMessage();
        }

        return $stat;
    }

    /**
     * 合并统计结果
     *
     * @param array{deleted: int, failed: array} $stat
     * @param array{deleted: int, failed: array} $part
     */
    protected function merge(array &$stat, array $part): void
    {
        $stat['deleted'] += $part['deleted'];
        $stat['failed']   = array_merge($stat['failed'], $part['failed']);
    }

    /**
     * 获取非阻塞排他锁
     *
     * 放在 runtime 根目录：模板缓存清理只动各应用下的 temp 目录，不会把它删掉，
     * 避免「锁文件被自己清掉」导致并发保护失效。
     *
     * @return resource|null 拿不到锁返回 null
     */
    protected function acquireLock()
    {
        $fp = @fopen($this->runtimePath . self::LOCK_FILE, 'c');
        if ($fp === false) {
            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }

        return $fp;
    }
}
