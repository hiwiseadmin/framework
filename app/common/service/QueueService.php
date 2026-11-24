<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\AsyncTask;
use app\common\job\AsyncTaskJob;
use app\common\job\TaskContext;
use think\facade\Queue;
use think\facade\Db;
use wise\support\context\ContextualLogger;
use think\facade\Config;
use think\App;

/**
 * 队列管理服务
 *
 * 提供统一的任务投递 API，封装 think-queue 组件，
 * 支持框架和插件便捷调用。
 *
 * 功能：
 *   - Job 类投递（push / later / pushOn）
 *   - 闭包任务（task / taskLater + registerHandler）
 *   - **受管闭包任务（dispatch）**：落 async_tasks 注册表，可查进度、可协作式取消
 *   - 失败任务管理（查询 / 重试 / 删除）
 *   - 队列统计（积压量 / 概览）
 *
 * 使用示例：
 *   // 投递 Job 类
 *   QueueService::instance()->push(\app\job\MyJob::class, $data, 'my_queue');
 *
 *   // 闭包任务（插件友好，不落注册表、无进度/取消能力）
 *   QueueService::registerHandler('cms:sync', function($data) { ... });
 *   QueueService::instance()->task('cms:sync', ['article_id' => 1]);
 *
 *   // 受管闭包任务（可查进度 / 可取消）
 *   // Handler 接收第二个参数 TaskContext，在检查点接受取消指令
 *   QueueService::registerHandler('cms:export', function(array $data, TaskContext $ctx) {
 *       $ctx->setTotal(100);
 *       for ($i = 0; $i < 100; $i++) {
 *           $ctx->checkpoint();        // 命中取消 → 框架置 canceled
 *           $ctx->progress($i + 1);
 *       }
 *   });
 *   $task = QueueService::instance()->dispatch('cms:export', ['kb_id' => 1], ['name' => '导出知识库']);
 *
 *   // 框架内置 handler
 *   // system:cleanup — 清理指定目录下的过期临时文件
 *   QueueService::instance()->task('system:cleanup', [
 *       'dir'       => runtime_path('temp'),
 *       'older_than'=> 86400,  // 秒，默认 24h
 *   ]);
 */
class QueueService
{
    /**
     * 单例实例
     * @var static|null
     */
    protected static $instance = null;

    /**
     * 队列配置
     * @var array
     */
    protected $config;

    /**
     * 回调处理器映射表
     * handler_name => callable
     * @var array
     */
    protected static $handlers = [];

    /**
     * 获取单例实例
     *
     * @return static
     */
    public static function instance(): self
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * 构造函数
     */
    protected function __construct()
    {
        $this->config = Config::get('queue', []);

        $this->registerBuiltinHandlers();
    }

    /**
     * 注册框架内置 handler
     */
    protected function registerBuiltinHandlers(): void
    {
        if (self::getHandler('system:cleanup') !== null) {
            return;
        }

        self::registerHandler('system:cleanup', function (array $data): void {
            $dir       = $data['dir'] ?? runtime_path('temp');
            $olderThan = $data['older_than'] ?? 86400;
            $now       = time();
            $deleted   = 0;

            if (!is_dir($dir)) {
                ContextualLogger::info("QueueService: system:cleanup — 目录不存在: {$dir}", ['queue']);
                return;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && ($now - $file->getMTime()) > $olderThan) {
                    @unlink($file->getRealPath());
                    $deleted++;
                }
            }

            ContextualLogger::info("QueueService: system:cleanup — 完成，清理 {$deleted} 个过期文件 [dir: {$dir}]", ['queue']);
        });
    }

    /**
     * 防止克隆
     */
    protected function __clone()
    {
    }


    /**
     * 立即投递任务到队列
     *
     * @param string $jobClass Job 类名
     * @param array  $data     任务数据
     * @param string|null $queue 队列名称，null 使用默认队列
     * @return mixed
     */
    public function push(string $jobClass, array $data = [], string $queue = null)
    {
        return Queue::push($jobClass, $data, $queue);
    }

    /**
     * 延迟投递任务到队列
     *
     * @param int    $delay    延迟秒数
     * @param string $jobClass Job 类名
     * @param array  $data     任务数据
     * @param string|null $queue 队列名称，null 使用默认队列
     * @return mixed
     */
    public function later(int $delay, string $jobClass, array $data = [], string $queue = null)
    {
        return Queue::later($delay, $jobClass, $data, $queue);
    }

    /**
     * 投递任务到指定连接
     *
     * @param string $connection 连接名（sync / database / redis）
     * @param string $jobClass   Job 类名
     * @param array  $data       任务数据
     * @param string|null $queue 队列名称，null 使用默认队列
     * @return mixed
     */
    public function pushOn(string $connection, string $jobClass, array $data = [], string $queue = null)
    {
        return Queue::connection($connection)->push($jobClass, $data, $queue);
    }


    /**
     * 投递回调标识任务（立即执行）
     *
     * 插件通过 registerHandler 注册处理器后，使用此方法投递。
     *
     * @param string $handler 回调标识（如 'cms:sync_article'）
     * @param array  $payload 任务数据
     * @param array  $options 选项：queue(队列名), connection(连接名), delay(延迟秒数)
     * @return mixed
     */
    public function task(string $handler, array $payload = [], array $options = [])
    {
        $data = $this->buildTaskData($handler, $payload);
        $queue      = $options['queue'] ?? null;
        $connection = $options['connection'] ?? null;
        $delay      = $options['delay'] ?? 0;

        if ($connection) {
            return Queue::connection($connection)->later($delay, AsyncTaskJob::class, $data, $queue);
        }

        if ($delay > 0) {
            return Queue::later($delay, AsyncTaskJob::class, $data, $queue);
        }

        return Queue::push(AsyncTaskJob::class, $data, $queue);
    }

    /**
     * 投递延迟回调标识任务
     *
     * @param int    $delay   延迟秒数
     * @param string $handler 回调标识
     * @param array  $payload 任务数据
     * @param array  $options 选项：queue(队列名), connection(连接名)
     * @return mixed
     */
    public function taskLater(int $delay, string $handler, array $payload = [], array $options = [])
    {
        $options['delay'] = $delay;
        return $this->task($handler, $payload, $options);
    }

    /**
     * 投递「受管」闭包任务（可查进度 / 可取消）
     *
     * 与 task() 的唯一区别：先落 async_tasks 注册表并把 task_id 塞进 payload，
     * AsyncTaskJob 据此上报 running/进度/终态。适合长耗时任务（导入导出、
     * 批量发信、索引重建等）。
     *
     * Handler 会额外收到第二个参数 {@see TaskContext}（若其形参数量 ≥ 2）：
     * 通过 $ctx->progress() 上报进度、$ctx->checkpoint() 接受取消指令。
     *
     * ⚠️ 进度/取消是**协作式**的：Handler 不埋检查点则无法被中止。
     *
     * @param string $handler 回调标识
     * @param array  $payload 任务数据
     * @param array  $options name(展示名) / queue / connection / delay / created_by
     * @return AsyncTask|null 受管任务记录；注册表不可用时降级为不跟踪投递并返回 null
     */
    public function dispatch(string $handler, array $payload = [], array $options = []): ?AsyncTask
    {
        try {
            $task = AsyncTaskService::instance()->create($handler, $payload, $options);
        } catch (\Throwable $e) {
            ContextualLogger::error(
                'QueueService::dispatch 创建任务记录失败，降级为不跟踪投递: ' . $e->getMessage(),
                ['queue']
            );
            $this->task($handler, $payload, $options);
            return null;
        }

        $taskId = (int) $task->getAttr('id');
        $taskNo = (string) $task->getAttr('task_no');

        $data = $this->buildTaskData($handler, $payload, [
            'task_id' => $taskId,
            'task_no' => $taskNo,
        ]);

        $queue      = $options['queue'] ?? null;
        $connection = $options['connection'] ?? null;
        $delay      = (int) ($options['delay'] ?? 0);

        try {
            if ($connection) {
                Queue::connection($connection)->later($delay, AsyncTaskJob::class, $data, $queue);
            } elseif ($delay > 0) {
                Queue::later($delay, AsyncTaskJob::class, $data, $queue);
            } else {
                Queue::push(AsyncTaskJob::class, $data, $queue);
            }
        } catch (\Throwable $e) {
            AsyncTaskService::instance()->markFailed($taskId, '入队失败: ' . $e->getMessage());
            ContextualLogger::error('QueueService::dispatch 入队失败: ' . $e->getMessage(), ['queue']);
        }

        return $task;
    }

    /**
     * 注册回调处理器
     *
     * 在插件 Plugin.php 的 boot() 方法中调用。
     * handler 命名建议：{插件标识}:{动作}，如 'cms:sync_article'。
     *
     * @param string   $name     回调标识
     * @param callable $callback 回调函数，接收 $data 参数
     * @return void
     */
    public static function registerHandler(string $name, callable $callback): void
    {
        static::$handlers[$name] = $callback;
    }

    /**
     * 获取已注册的回调处理器
     *
     * @param string $name 回调标识
     * @return callable|null
     */
    public static function getHandler(string $name)
    {
        return static::$handlers[$name] ?? null;
    }

    /**
     * 移除回调处理器
     *
     * @param string $name 回调标识
     * @return void
     */
    public static function removeHandler(string $name): void
    {
        unset(static::$handlers[$name]);
    }

    /**
     * 获取所有已注册的处理器名称
     *
     * @return array
     */
    public static function getRegisteredHandlers(): array
    {
        return array_keys(static::$handlers);
    }

    /**
     * 构建任务数据
     *
     * @param string $handler 回调标识
     * @param array  $payload 任务负载
     * @param array  $extra   附加键（受管任务会带上 task_id / task_no）
     * @return array
     */
    protected function buildTaskData(string $handler, array $payload, array $extra = []): array
    {
        return array_merge([
            'handler' => $handler,
            'payload' => $payload,
        ], $extra);
    }


    /**
     * 获取失败任务列表
     *
     * @param int $limit 限制条数
     * @return array
     */
    public function getFailedJobs(int $limit = 20): array
    {
        try {
            $table = $this->getFailedTable();
            return Db::name($table)->order('id', 'desc')->limit($limit)->select()->toArray();
        } catch (\Exception $e) {
            ContextualLogger::error('QueueService::getFailedJobs 失败: ' . $e->getMessage(), ['queue']);
            return [];
        }
    }

    /**
     * 查看失败任务详情
     *
     * @param int $id 失败任务ID
     * @return array|null
     */
    public function findFailedJob(int $id): ?array
    {
        try {
            $table = $this->getFailedTable();
            $row = Db::name($table)->where('id', $id)->find();
            return $row ?: null;
        } catch (\Exception $e) {
            ContextualLogger::error('QueueService::findFailedJob 失败: ' . $e->getMessage(), ['queue']);
            return null;
        }
    }

    /**
     * 重试失败任务
     *
     * 参照 queue:retry 命令实现，将失败任务重新推回队列。
     *
     * @param int $id 失败任务ID
     * @return bool
     */
    public function retryFailed(int $id): bool
    {
        try {
            $app = App::getInstance();
            $failer = $app['queue.failer'];
            $job = $failer->find($id);

            if ($job === null) {
                ContextualLogger::warning("QueueService::retryFailed: 未找到失败任务 #{$id}", ['queue']);
                return false;
            }

            $payload = json_decode($job['payload'], true);
            if (isset($payload['attempts'])) {
                $payload['attempts'] = 0;
            }

            $app['queue']
                ->connection($job['connection'])
                ->pushRaw(json_encode($payload), $job['queue']);

            $failer->forget($id);

            return true;
        } catch (\Exception $e) {
            ContextualLogger::error('QueueService::retryFailed 失败: ' . $e->getMessage(), ['queue']);
            return false;
        }
    }

    /**
     * 重试所有失败任务
     *
     * @return int 成功重试的数量
     */
    public function retryAllFailed(): int
    {
        $count = 0;
        $jobs = $this->getFailedJobs(1000);
        foreach ($jobs as $job) {
            if ($this->retryFailed((int)$job['id'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 删除失败任务
     *
     * @param int $id 失败任务ID
     * @return bool
     */
    public function deleteFailed(int $id): bool
    {
        try {
            $table = $this->getFailedTable();
            return Db::name($table)->where('id', $id)->delete() > 0;
        } catch (\Exception $e) {
            ContextualLogger::error('QueueService::deleteFailed 失败: ' . $e->getMessage(), ['queue']);
            return false;
        }
    }

    /**
     * 清空失败任务表
     *
     * @return bool
     */
    public function clearFailedJobs(): bool
    {
        try {
            $table = $this->getFailedTable();
            Db::name($table)->delete(true);
            return true;
        } catch (\Exception $e) {
            ContextualLogger::error('QueueService::clearFailedJobs 失败: ' . $e->getMessage(), ['queue']);
            return false;
        }
    }

    /**
     * 获取失败任务表名
     *
     * @return string
     */
    protected function getFailedTable(): string
    {
        return $this->config['failed']['table'] ?? 'failed_jobs';
    }


    /**
     * 获取队列积压量
     *
     * @param string|null $queue 队列名称，null 查询默认队列
     * @return int
     */
    public function getQueueSize(string $queue = null): int
    {
        $connection = $this->config['default'] ?? 'database';

        try {
            if ($connection === 'database') {
                $table = $this->config['connections']['database']['table'] ?? 'jobs';
                $query = Db::name($table)->whereNull('reserve_time')->where('available_time', '<=', time());
                if ($queue) {
                    $query->where('queue', $queue);
                }
                return (int)$query->count();
            }

            if ($connection === 'redis') {
                $redisQueue = $queue ?? ($this->config['connections']['redis']['queue'] ?? 'default');
                return (int)Queue::connection($connection)->size($redisQueue);
            }

            return 0;
        } catch (\Exception $e) {
            ContextualLogger::error('QueueService::getQueueSize 失败: ' . $e->getMessage(), ['queue']);
            return 0;
        }
    }

    /**
     * 获取队列统计概览
     *
     * @return array
     */
    public function getQueueStats(): array
    {
        $connection = $this->config['default'] ?? 'database';
        $failedJobs = $this->getFailedJobs();
        $pending = $this->getQueueSize();

        $stats = [
            'connection' => $connection,
            'pending'     => $pending,
            'failed'      => count($failedJobs),
            'recent_failed' => array_slice($failedJobs, 0, 5),
            'handlers'    => self::getRegisteredHandlers(),
        ];

        if ($connection === 'database') {
            $table = $this->config['connections']['database']['table'] ?? 'jobs';
            try {
                $stats['delayed'] = (int)Db::name($table)
                    ->whereNull('reserve_time')
                    ->where('available_time', '>', time())
                    ->count();
                $stats['reserved'] = (int)Db::name($table)
                    ->whereNotNull('reserve_time')
                    ->count();
            } catch (\Exception $e) {
                $stats['delayed'] = 0;
                $stats['reserved'] = 0;
            }
        }

        return $stats;
    }
}
