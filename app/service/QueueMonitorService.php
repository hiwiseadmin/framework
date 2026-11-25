<?php
declare (strict_types=1);

namespace app\service;

use think\facade\Cache;
use wise\support\context\ContextualLogger;
use think\facade\Config;

class QueueMonitorService
{
    /**
     * 队列配置键名
     */
    const QUEUE_CONFIG_KEY = 'queue.default';
    
    /**
     * 自动重启阈值（秒）
     */
    const AUTO_RESTART_THRESHOLD = 300;
    
    /**
     * 检查并确保队列监听进程运行
     */
    public function ensureQueueRunning(): array
    {
        $result = [
            'status' => 'unknown',
            'message' => '',
            'actions_taken' => [],
            'previous_status' => $this->checkQueueStatus(),
            'current_status' => null,
        ];
        
        try {
            $status = $this->checkQueueStatus();
            $result['current_status'] = $status;
            
            if (!$status['redis_connected']) {
                $result['status'] = 'error';
                $result['message'] = 'Redis连接失败，无法启动队列';
                return $result;
            }
            
            if ($status['listen_running']) {
                $result['status'] = 'healthy';
                $result['message'] = '队列监听进程已在运行';
                
                $this->recordUptime();
                return $result;
            }
            
            if ($status['work_running']) {
                $result['status'] = 'healthy';
                $result['message'] = '队列工作进程已在运行';
                
                $this->recordUptime();
                return $result;
            }
            
            $result['status'] = 'restarting';
            $result['message'] = '启动队列监听进程';
            
            $startResult = $this->startQueueListen();
            
            if ($startResult['success']) {
                $result['actions_taken'][] = 'started_queue_listen';
                $result['pid'] = $startResult['pid'];
                $result['status'] = 'restarted';
                $result['message'] = '队列监听进程启动成功';
                
                $this->logRestartEvent('自动启动队列监听');
                
                sleep(2);
                $result['current_status'] = $this->checkQueueStatus();
                
            } else {
                $result['status'] = 'failed';
                $result['message'] = '队列监听进程启动失败: ' . $startResult['error'];
                $result['actions_taken'][] = 'failed_to_start_queue';
            }
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['message'] = '检查队列状态异常: ' . $e->getMessage();
            ContextualLogger::error('队列监控异常: ' . $e->getMessage());
        }
        
        return $result;
    }
    /**
     * 检查队列监听状态
     */
    public function checkQueueStatus(): array
    {
        $status = [
            'listen_running' => false,
            'work_running' => false,
            'redis_connected' => false,
            'database_connected' => false,
            'queue_driver' => Config::get(self::QUEUE_CONFIG_KEY, 'sync'),
            'last_check' => time(),
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'processed_jobs' => 0,
            'memory_usage' => memory_get_usage(true),
        ];
        
        $status['listen_running'] = $this->checkProcessRunning('think queue:listen');
        
        $status['work_running'] = $this->checkProcessRunning('think queue:work');
        
        $status['redis_connected'] = $this->checkRedisConnection();
        
        $status['database_connected'] = $this->checkDatabaseConnection();
        
        $queueStats = $this->getQueueStats();
        $status = array_merge($status, $queueStats);
        
        $status['heartbeat_active'] = $this->checkHeartbeat();
        
        Cache::set('queue:monitor:status', $status, 30);
        
        $this->recordCheckHistory($status);
        
        return $status;
    }
    
    /**
     * 读取「队列健康快照」（**只读缓存，不做任何进程探测**）
     *
     * 后台接口专用。QueueMonitorService 的活性探测依赖 shell_exec / kill，
     * 属框架 DoD 禁止的「HTTP 请求路径内调用 shell_exec」，只能在 CLI
     * （`php think async-task health`）执行；HTTP 侧仅读取由 CLI 写入的快照。
     *
     * @return array|null 快照不存在/已过期时返回 null
     */
    public function getCachedStatus(): ?array
    {
        try {
            $status = Cache::get('queue:monitor:status');

            return is_array($status) && !empty($status) ? $status : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 启动队列监听进程
     */
    public function startQueueListen(array $options = []): array
    {
        $result = [
            'success' => false,
            'pid' => null,
            'command' => '',
            'output' => '',
            'error' => '',
        ];
        
        try {
            $cmd = $this->buildQueueCommand($options);
            $result['command'] = $cmd;
            
            $queueDriver = Config::get(self::QUEUE_CONFIG_KEY, 'sync');
            
            if ($queueDriver === 'database') {
                $this->ensureQueueTables();
            }
            
            if (function_exists('shell_exec')) {
                $fullCmd = 'cd ' . escapeshellarg(root_path()) . ' && ' . $cmd . ' > /dev/null 2>&1 & echo $!';
                $output = shell_exec($fullCmd);
                
                if ($output) {
                    $pid = intval(trim($output));
                    if ($pid > 0) {
                        $result['success'] = true;
                        $result['pid'] = $pid;
                        $result['output'] = trim($output);
                        
                        Cache::set('queue:listen:pid', $pid, 86400);
                        
                        ContextualLogger::info("队列监听进程已启动，PID: {$pid}");
                    }
                }
            } else {
                $result['error'] = 'shell_exec函数不可用';
            }
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            ContextualLogger::error('启动队列监听失败: ' . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 构建队列命令
     */
    private function buildQueueCommand(array $options = []): string
    {
        $defaultOptions = [
            'driver' => Config::get(self::QUEUE_CONFIG_KEY, 'sync'),
            'queue' => 'default',
            'delay' => 0,
            'memory' => 128,
            'sleep' => 3,
            'tries' => 0,
            'timeout' => 60,
            'daemon' => false,
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        $cmd = 'php think queue:listen';
        
        if ($options['queue']) {
            $cmd .= ' --queue ' . escapeshellarg($options['queue']);
        }
        
        if ($options['delay'] > 0) {
            $cmd .= ' --delay ' . $options['delay'];
        }
        
        if ($options['memory'] > 0) {
            $cmd .= ' --memory ' . $options['memory'];
        }
        
        if ($options['sleep'] > 0) {
            $cmd .= ' --sleep ' . $options['sleep'];
        }
        
        if ($options['tries'] > 0) {
            $cmd .= ' --tries ' . $options['tries'];
        }
        
        if ($options['timeout'] > 0) {
            $cmd .= ' --timeout ' . $options['timeout'];
        }
        
        if ($options['daemon']) {
            $cmd .= ' --daemon';
        }
        
        return $cmd;
    }
    
    /**
     * 停止队列监听进程
     */
    public function stopQueueListen(): array
    {
        $result = [
            'success' => false,
            'stopped_pids' => [],
            'message' => '',
        ];
        
        try {
            $listenPids = $this->getProcessPids('think queue:listen');
            foreach ($listenPids as $pid) {
                if ($this->killProcess($pid)) {
                    $result['stopped_pids'][] = $pid;
                }
            }
            
            $workPids = $this->getProcessPids('think queue:work');
            foreach ($workPids as $pid) {
                if ($this->killProcess($pid)) {
                    $result['stopped_pids'][] = $pid;
                }
            }
            
            Cache::delete('queue:listen:pid');
            
            $result['success'] = count($result['stopped_pids']) > 0;
            $result['message'] = $result['success'] 
                ? '已停止 ' . count($result['stopped_pids']) . ' 个队列进程'
                : '未找到运行的队列进程';
                
            ContextualLogger::info('队列监听进程已停止: ' . json_encode($result['stopped_pids']));
            
        } catch (\Exception $e) {
            $result['message'] = '停止队列进程失败: ' . $e->getMessage();
            ContextualLogger::error('停止队列监听失败: ' . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 重启队列监听进程
     */
    public function restartQueueListen(): array
    {
        $result = [
            'success' => false,
            'stopped' => [],
            'started' => [],
            'message' => '',
        ];
        
        try {
            $stopResult = $this->stopQueueListen();
            $result['stopped'] = $stopResult['stopped_pids'];
            
            sleep(1);
            
            $startResult = $this->startQueueListen();
            
            if ($startResult['success']) {
                $result['started'] = [$startResult['pid']];
                $result['success'] = true;
                $result['message'] = '队列监听重启成功';
                
                $this->logRestartEvent('手动重启队列监听');
                
            } else {
                $result['message'] = '队列监听重启失败: ' . $startResult['error'];
            }
            
        } catch (\Exception $e) {
            $result['message'] = '重启队列监听失败: ' . $e->getMessage();
            ContextualLogger::error('重启队列监听失败: ' . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 检查进程是否在运行
     */
    private function checkProcessRunning(string $processName): bool
    {
        try {
            if (PHP_OS === 'Linux' || PHP_OS === 'Darwin') {
                $command = "ps aux | grep -v grep | grep -v 'sh -c' | grep '" . escapeshellcmd($processName) . "' | wc -l";
                $result = shell_exec($command);
                return intval(trim($result)) > 0;
            }
            
            if (PHP_OS === 'WINNT') {
                $command = 'tasklist /FI "IMAGENAME eq php.exe" /FO CSV';
                $output = shell_exec($command);
                
                if ($output && stripos($output, 'php.exe') !== false) {
                    $command2 = 'wmic process where "name=\'php.exe\'" get commandline';
                    $output2 = shell_exec($command2);
                    
                    return $output2 && stripos($output2, $processName) !== false;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            ContextualLogger::error('检查进程状态失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取进程PID列表
     */
    private function getProcessPids(string $processName): array
    {
        $pids = [];
        
        try {
            if (PHP_OS === 'Linux' || PHP_OS === 'Darwin') {
                $command = "ps aux | grep -v grep | grep -v 'sh -c' | grep '" . escapeshellcmd($processName) . "' | awk '{print $2}'";
                $result = shell_exec($command);
                
                if ($result) {
                    $pids = array_filter(array_map('intval', explode("\n", trim($result))));
                }
            }
            
        } catch (\Exception $e) {
            ContextualLogger::error('获取进程PID失败: ' . $e->getMessage());
        }
        
        return $pids;
    }
    
    /**
     * 终止进程
     */
    private function killProcess(int $pid): bool
    {
        try {
            if (PHP_OS === 'Linux' || PHP_OS === 'Darwin') {
                $command = "kill -15 {$pid} 2>/dev/null";
                shell_exec($command);
                
                sleep(1);
                
                if ($this->isProcessAlive($pid)) {
                    $command = "kill -9 {$pid} 2>/dev/null";
                    shell_exec($command);
                }
                
                return true;
            }
            
            if (PHP_OS === 'WINNT') {
                $command = "taskkill /F /PID {$pid}";
                shell_exec($command);
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            ContextualLogger::error('终止进程失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查进程是否存活
     */
    private function isProcessAlive(int $pid): bool
    {
        try {
            if (PHP_OS === 'Linux' || PHP_OS === 'Darwin') {
                $command = "ps -p {$pid} > /dev/null 2>&1 && echo 'alive'";
                $result = shell_exec($command);
                return trim($result) === 'alive';
            }
            
            return false;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 检查Redis连接
     */
    private function checkRedisConnection(): bool
    {
        try {
            $redis = Cache::store('redis')->handler();
            $result = $redis->ping();
            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 检查数据库连接
     */
    private function checkDatabaseConnection(): bool
    {
        try {
            $result = \think\facade\Db::query('SELECT 1');
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 逻辑表名 → 带表前缀的物理表名（与 Db::name() 的查询口径一致）
     */
    private function realTableName(string $table): string
    {
        return (string) \think\facade\Db::name($table)->getTable();
    }
    
    /**
     * 确保队列表存在（数据库驱动时）
     */
    private function ensureQueueTables(): bool
    {
        try {
            $tableName = $this->realTableName(Config::get('queue.connections.database.table', 'jobs'));
            $failedTableName = $this->realTableName(Config::get('queue.failed.table', 'failed_jobs'));
            
            $schemaSql = 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1';
            
            $jobsExists = \think\facade\Db::query($schemaSql, [$tableName]);
            
            if (empty($jobsExists)) {
                $this->createJobsTable($tableName);
            }
            
            $failedExists = \think\facade\Db::query($schemaSql, [$failedTableName]);
            
            if (empty($failedExists)) {
                $this->createFailedJobsTable($failedTableName);
            }
            
            return true;
            
        } catch (\Exception $e) {
            ContextualLogger::error('确保队列表存在失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建jobs表
     */
    private function createJobsTable(string $table): bool
    {
        try {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tab` varchar(50) NOT NULL DEFAULT '',
  `queue` varchar(255) NOT NULL DEFAULT 'default',
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `reserve_time` int(10) unsigned DEFAULT NULL,
  `available_time` int(10) unsigned NOT NULL,
  `create_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue` (`queue`),
  KEY `idx_reserve_time` (`reserve_time`),
  KEY `idx_available_time` (`available_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
            
            \think\facade\Db::execute($sql);
            ContextualLogger::info('创建jobs表成功');
            return true;
            
        } catch (\Exception $e) {
            ContextualLogger::error('创建jobs表失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建failed_jobs表
     */
    private function createFailedJobsTable(string $table): bool
    {
        try {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `fail_time` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
            
            \think\facade\Db::execute($sql);
            ContextualLogger::info('创建failed_jobs表成功');
            return true;
            
        } catch (\Exception $e) {
            ContextualLogger::error('创建failed_jobs表失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取队列统计信息
     */
    private function getQueueStats(): array
    {
        $stats = [
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'processed_jobs' => 0,
            'delayed_jobs' => 0,
            'reserved_jobs' => 0,
        ];
        
        try {
            $queueDriver = Config::get(self::QUEUE_CONFIG_KEY, 'sync');
            
            if ($queueDriver === 'database') {
                $tableName = Config::get('queue.connections.database.table', 'jobs');
                $failedTableName = Config::get('queue.failed.table', 'failed_jobs');
                
                $pending = \think\facade\Db::name($tableName)
                    ->whereNull('reserve_time')
                    ->where('available_time', '<=', time())
                    ->count();
                
                $stats['pending_jobs'] = $pending;
                
                $reserved = \think\facade\Db::name($tableName)
                    ->whereNotNull('reserve_time')
                    ->count();
                
                $stats['reserved_jobs'] = $reserved;
                
                $delayed = \think\facade\Db::name($tableName)
                    ->whereNull('reserve_time')
                    ->where('available_time', '>', time())
                    ->count();
                
                $stats['delayed_jobs'] = $delayed;
                
                $failed = \think\facade\Db::name($failedTableName)
                    ->count();
                
                $stats['failed_jobs'] = $failed;
                
                $stats['processed_jobs'] = Cache::get('queue:stats:processed', 0);
                
            } elseif ($queueDriver === 'redis') {
                $redis = Cache::store('redis')->handler();
                $prefix = Cache::getPrefix();
                
                $pending = $redis->llen($prefix . 'queues:default');
                $stats['pending_jobs'] = $pending;
                
                $failed = $redis->llen($prefix . 'queues:default:failed');
                $stats['failed_jobs'] = $failed;
                
                $stats['processed_jobs'] = Cache::get('queue:stats:processed', 0);
            }
            
        } catch (\Exception $e) {
            ContextualLogger::error('获取队列统计失败: ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * 检查心跳
     */
    private function checkHeartbeat(): bool
    {
        try {
            $lastHeartbeat = Cache::get('queue:heartbeat:last');
            if (!$lastHeartbeat) {
                return false;
            }
            
            return (time() - $lastHeartbeat) <= 30;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 记录检查历史
     */
    private function recordCheckHistory(array $status): void
    {
        try {
            $history = Cache::get('queue:monitor:history', []);
            
            $record = [
                'timestamp' => time(),
                'listen_running' => $status['listen_running'],
                'work_running' => $status['work_running'],
                'redis_connected' => $status['redis_connected'],
                'pending_jobs' => $status['pending_jobs'],
                'failed_jobs' => $status['failed_jobs'],
                'memory_usage' => $status['memory_usage'],
            ];
            
            array_unshift($history, $record);
            $history = array_slice($history, 0, 1000);
            
            Cache::set('queue:monitor:history', $history, 86400 * 7);
            
        } catch (\Exception $e) {
        }
    }
    
    /**
     * 记录正常运行时间
     */
    private function recordUptime(): void
    {
        try {
            $uptime = Cache::get('queue:uptime:current', [
                'start_time' => time(),
                'last_check' => time(),
                'checks_count' => 0,
            ]);
            
            $uptime['last_check'] = time();
            $uptime['checks_count']++;
            
            Cache::set('queue:uptime:current', $uptime, 86400);
            
        } catch (\Exception $e) {
        }
    }
    
    /**
     * 记录重启事件
     */
    private function logRestartEvent(string $reason): void
    {
        try {
            $events = Cache::get('queue:restart:events', []);
            
            $event = [
                'timestamp' => time(),
                'reason' => $reason,
                'pid' => getmypid(),
            ];
            
            array_unshift($events, $event);
            $events = array_slice($events, 0, 100);
            
            Cache::set('queue:restart:events', $events, 86400 * 30);
            
            ContextualLogger::info("队列重启事件: {$reason}");
            
        } catch (\Exception $e) {
            ContextualLogger::error('记录重启事件失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取监控统计
     */
    public function getMonitorStats(): array
    {
        try {
            $uptime = Cache::get('queue:uptime:current', []);
            $history = Cache::get('queue:monitor:history', []);
            $events = Cache::get('queue:restart:events', []);
            
            $uptimePercent = 100;
            if (!empty($history)) {
                $healthyCount = count(array_filter($history, function($record) {
                    return $record['listen_running'] || $record['work_running'];
                }));
                
                if (count($history) > 0) {
                    $uptimePercent = round(($healthyCount / count($history)) * 100, 2);
                }
            }
            
            return [
                'uptime_percent' => $uptimePercent,
                'current_uptime' => $uptime,
                'total_checks' => count($history),
                'total_restarts' => count($events),
                'recent_events' => array_slice($events, 0, 10),
                'queue_size_history' => array_slice(array_column($history, 'pending_jobs'), 0, 100),
            ];
            
        } catch (\Exception $e) {
            return [
                'uptime_percent' => 0,
                'current_uptime' => [],
                'total_checks' => 0,
                'total_restarts' => 0,
                'recent_events' => [],
                'queue_size_history' => [],
            ];
        }
    }
}