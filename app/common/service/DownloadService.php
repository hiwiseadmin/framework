<?php
namespace app\common\service;

use think\facade\Cache;
use think\facade\Config;
use wise\support\context\ContextualLogger;
use think\facade\Request;
use think\facade\Filesystem;
use think\exception\HttpException;
use wise\attachment\model\AttachmentModel as Attachment;
use wise\support\tools\ArchiveTool;
use wise\support\tools\HttpClient;
use Ramsey\Uuid\Uuid;

class DownloadService
{
    protected $config = [
        'storage_path'      => 'private/downloads/',
        'temp_url_expire'   => 3600,
        'download_limit'    => 10,
        'max_file_size'     => 1024 * 1024 * 100,
        'enable_logging'    => false,
        'enable_signature'  => true,
        'secret_key'        => '',
        'allowed_extensions'=> ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'zip', 'rar'],
        'zip_temp_path'      => 'runtime/zip/',
        'zip_expire_time'    => 1800,
        'zip_max_files'      => 50,
        'zip_max_size'       => 1024 * 1024 * 500,
        'zip_cleanup_age'    => 3600,
    ];

    /**
     * 构造函数
     * @param array $config 自定义配置
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);

        if (empty($this->config['secret_key'])) {
            $this->config['secret_key'] = env('app.app_key', 'thinkphp');
        }
    }

    /**
     * 设置配置
     * @param array $config
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * 获取配置
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 生成安全下载链接
     * @param string|int $fileId 文件ID或路径
     * @param array $options 选项
     * @return array 包含下载链接和信息
     *
     * $options 选项：
     * - user_id: 用户ID（可选）
     * - expire_time: 过期时间（秒）
     * - download_limit: 下载次数限制（次/分钟）
     * - ip_restrict: 是否限制IP地址（默认true）
     * - referer_check: 是否检查Referer（默认false）
     * - custom_data: 自定义数据（关联数组）
     * - filename: 自定义文件名（可选）
     * - qrcode: 是否生成二维码（默认false）
     */
    public function generateDownloadUrl($fileId, array $options = []): array
    {
        $defaultOptions = [
            'user_id'       => null,
            'expire_time'   => $this->config['temp_url_expire'],
            'download_limit'=> 1,
            'ip_restrict'   => true,
            'referer_check' => false,
            'custom_data'   => [],
            'filename'      => null,
            'qrcode'        => false,
        ];

        $options = array_merge($defaultOptions, $options);

        $token = $this->generateToken();

        $cacheData = [
            'file_id'        => $fileId,
            'user_id'        => $options['user_id'],
            'expire_time'    => time() + $options['expire_time'],
            'download_limit' => $options['download_limit'],
            'download_count' => 0,
            'ip_address'     => $options['ip_restrict'] ? Request::ip() : null,
            'referer'        => $options['referer_check'] ? Request::server('HTTP_REFERER') : null,
            'custom_data'    => $options['custom_data'],
            'filename'       => $options['filename'],
            'create_time'     => time(),
        ];

        if ($this->config['enable_signature']) {
            $cacheData['signature'] = $this->generateSignature($cacheData);
        }

        $cacheKey = $this->getCacheKey($token);
        Cache::set($cacheKey, $cacheData, $options['expire_time']);

        $downloadUrl = $this->buildDownloadUrl($token);

        return [
            'url'         => $downloadUrl,
            'token'       => $token,
            'expire_time' => $cacheData['expire_time'],
            'qrcode'      => $options['qrcode'] ? $this->generateQRCode($downloadUrl) : null,
        ];
    }

    /**
     * 生成ZIP打包下载链接
     * @param array $fileIds 文件ID数组
     * @param array $options 选项
     * @return array
     * @throws \Exception
     */
    public function generateZipDownload(array $fileIds, array $options = []): array
    {
        if (empty($fileIds)) {
            throw new \Exception('文件列表不能为空');
        }

        if (count($fileIds) > $this->config['zip_max_files']) {
            throw new \Exception('文件数量超过限制，最多允许' . $this->config['zip_max_files'] . '个文件');
        }

        $defaultOptions = [
            'zip_name'      => 'download_' . date('Ymd_His') . '.zip',
            'expire_time'   => $this->config['temp_url_expire'],
            'download_limit'=> 1,
            'user_id'       => null,
            'custom_data'   => [],
            'password'      => null,
            'compression'   => \ZipArchive::CM_DEFAULT,
        ];

        $options = array_merge($defaultOptions, $options);

        $totalSize = 0;
        $fileInfos = [];

        foreach ($fileIds as $fileId) {
            $fileInfo = $this->getFileInfo($fileId);
            $totalSize += $fileInfo['size'];
            $fileInfos[] = $fileInfo;

            if ($totalSize > $this->config['zip_max_size']) {
                throw new \Exception('文件总大小超过限制');
            }
        }

        $token = $this->generateToken();

        $cacheData = [
            'type'           => 'zip',
            'file_ids'       => $fileIds,
            'file_infos'     => $fileInfos,
            'zip_name'       => $options['zip_name'],
            'password'       => $options['password'],
            'compression'    => $options['compression'],
            'total_size'     => $totalSize,
            'file_count'     => count($fileIds),
            'expire_time'    => time() + $options['expire_time'],
            'download_limit' => $options['download_limit'],
            'download_count' => 0,
            'user_id'        => $options['user_id'],
            'custom_data'    => $options['custom_data'],
            'create_time'     => time(),
        ];

        if ($this->config['enable_signature']) {
            $cacheData['signature'] = $this->generateSignature($cacheData);
        }

        $cacheKey = $this->getCacheKey($token);
        Cache::set($cacheKey, $cacheData, $options['expire_time']);

        return [
            'url'         => $this->buildDownloadUrl($token),
            'token'       => $token,
            'zip_name'    => $options['zip_name'],
            'file_count'  => count($fileIds),
            'total_size'  => $totalSize,
            'expire_time' => $cacheData['expire_time'],
        ];
    }

    /**
     * 生成批量下载链接
     * @param array $fileIds 文件ID数组
     * @param array $options 选项
     * @return array
     */
    public function generateBatchUrls(array $fileIds, array $options = []): array
    {
        $result = [];
        foreach ($fileIds as $fileId) {
            $result[] = $this->generateDownloadUrl($fileId, $options);
        }
        return $result;
    }

    /**
     * 处理下载请求
     * @param string $token 下载令牌
     * @return \think\Response
     */
    public function processDownload(string $token)
    {
        try {
            $data = $this->validateToken($token);

            if (isset($data['type']) && $data['type'] === 'zip') {
                return $this->processZipDownload($data);
            } else {
                $fileInfo = $this->getFileInfo($data['file_id']);
                $this->checkPermission($data, $fileInfo);
                $this->updateDownloadStats($token, $data);

                if ($this->config['enable_logging']) {
                    $this->logDownload($data, $fileInfo);
                }

                return $this->deliverFile($fileInfo, $data['filename'] ?? null);
            }

        } catch (\Exception $e) {
            ContextualLogger::error('Download failed: ' . $e->getMessage());
            throw new HttpException(403, '下载失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成带进度的ZIP下载（异步）
     * @param array $fileIds
     * @param string $callbackUrl 进度回调URL
     * @param array $options
     * @return array
     */
    public function generateAsyncZipDownload(array $fileIds, string $callbackUrl = '', array $options = []): array
    {
        $taskId = uniqid('zip_task_', true);

        $taskData = [
            'file_ids'    => $fileIds,
            'options'     => $options,
            'status'      => 'pending',
            'progress'    => 0,
            'create_time'  => time(),
            'callback_url'=> $callbackUrl,
        ];

        Cache::set('zip_task:' . $taskId, $taskData, 86400);

        $this->startZipCreationTask($taskId, $fileIds, $options, $callbackUrl);

        return [
            'task_id' => $taskId,
            'status_url' => url('download/task/status', ['task_id' => $taskId], true, true),
        ];
    }

    /**
     * 启动ZIP创建任务，完成后通过 HttpClient 通知回调URL
     * @param string $taskId 任务ID
     * @param array $fileIds 文件ID数组
     * @param array $options 选项
     * @param string $callbackUrl 回调URL
     */
    protected function startZipCreationTask(string $taskId, array $fileIds, array $options = [], string $callbackUrl = ''): void
    {
        try {
            $fileInfos = [];
            foreach ($fileIds as $fileId) {
                $fileInfos[] = $this->getFileInfo($fileId);
            }

            $zipName = $options['zip_name'] ?? 'download_' . date('Ymd_His') . '.zip';
            $zipPath = $this->createZipFile($fileInfos, $zipName, $options);

            $taskData = Cache::get('zip_task:' . $taskId);
            if ($taskData) {
                $taskData['status'] = 'completed';
                $taskData['progress'] = 100;
                $taskData['zip_path'] = $zipPath;
                Cache::set('zip_task:' . $taskId, $taskData, 86400);
            }

            if (!empty($callbackUrl)) {
                $this->notifyCallback($callbackUrl, [
                    'task_id'   => $taskId,
                    'status'    => 'completed',
                    'zip_path'  => $zipPath,
                    'zip_name'  => $zipName,
                    'file_count'=> count($fileInfos),
                ]);
            }
        } catch (\Exception $e) {
            $taskData = Cache::get('zip_task:' . $taskId);
            if ($taskData) {
                $taskData['status'] = 'failed';
                $taskData['error'] = $e->getMessage();
                Cache::set('zip_task:' . $taskId, $taskData, 86400);
            }

            if (!empty($callbackUrl)) {
                $this->notifyCallback($callbackUrl, [
                    'task_id' => $taskId,
                    'status'  => 'failed',
                    'error'   => $e->getMessage(),
                ]);
            }

            ContextualLogger::error('异步ZIP创建任务失败: ' . $e->getMessage());
        }
    }

    /**
     * 使用 HttpClient 发送任务回调通知
     * @param string $callbackUrl 回调URL
     * @param array $data 通知数据
     */
    protected function notifyCallback(string $callbackUrl, array $data): void
    {
        try {
            $httpClient = new HttpClient([
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
            $httpClient->post($callbackUrl, [
                'json' => $data,
            ]);
        } catch (\Exception $e) {
            ContextualLogger::warning('ZIP任务回调通知失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取批量文件信息
     * @param array $fileIds
     * @return array
     * @throws \Exception
     */
    public function getBatchFileInfo(array $fileIds): array
    {
        $result = [
            'total_size' => 0,
            'file_count' => count($fileIds),
            'files' => [],
            'can_download' => true,
            'errors' => [],
        ];

        foreach ($fileIds as $fileId) {
            try {
                $fileInfo = $this->getFileInfo($fileId);
                $result['total_size'] += $fileInfo['size'];
                $result['files'][] = [
                    'id' => $fileId,
                    'name' => $fileInfo['filename'],
                    'size' => $fileInfo['size'],
                    'type' => $fileInfo['mime_type'],
                ];
            } catch (\Exception $e) {
                $result['can_download'] = false;
                $result['errors'][] = "文件{$fileId}: " . $e->getMessage();
            }
        }

        if ($result['total_size'] > $this->config['zip_max_size']) {
            $result['can_download'] = false;
            $result['errors'][] = '文件总大小超过限制';
        }

        if ($result['file_count'] > $this->config['zip_max_files']) {
            $result['can_download'] = false;
            $result['errors'][] = '文件数量超过限制';
        }

        return $result;
    }

    /**
     * 清理所有过期ZIP文件
     * @return int 清理的文件数量
     */
    public function cleanupExpiredZipFiles(): int
    {
        $zipTempDir = runtime_path() . $this->config['zip_temp_path'];
        if (!is_dir($zipTempDir)) {
            return 0;
        }

        $files = glob($zipTempDir . 'zip_*');
        $cleaned = 0;

        foreach ($files as $file) {
            $fileAge = time() - filemtime($file);
            if ($fileAge > $this->config['zip_cleanup_age']) {
                @unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * 清理过期的下载令牌
     * @return int 清理的数量
     */
    public function cleanupExpiredTokens(): int
    {
        return 0;
    }

    /**
     * 获取下载统计
     * @param string $period 时间周期：today, week, month, year
     * @return array
     */
    public function getDownloadStats(string $period = 'today'): array
    {
        if (!class_exists('app\model\DownloadLog')) {
            return [];
        }

        switch ($period) {
            case 'today':
                $startTime = strtotime('today');
                break;
            case 'week':
                $startTime = strtotime('-7 days');
                break;
            case 'month':
                $startTime = strtotime('-30 days');
                break;
            case 'year':
                $startTime = strtotime('-365 days');
                break;
            default:
                $startTime = strtotime('today');
                break;
        }

        $stats = \app\model\DownloadLog::where('download_time', '>=', $startTime)
            ->field('COUNT(*) as total_count, SUM(file_size) as total_size,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT ip_address) as unique_ips')
            ->find();

        return $stats ? $stats->toArray() : [];
    }

    /**
     * 使用 ArchiveTool 的 compressFiles() 直接创建ZIP文件（无需临时目录中转）
     *
     * @param array $fileInfos 文件信息数组
     * @param string $zipName ZIP文件名
     * @param array $options 选项（password, compression）
     * @return string ZIP文件路径
     * @throws \Exception
     */
    protected function createZipFile(array $fileInfos, string $zipName, array $options = []): string
    {
        $zipTempDir = runtime_path() . $this->config['zip_temp_path'];
        if (!is_dir($zipTempDir)) {
            mkdir($zipTempDir, 0755, true);
        }

        $zipPath = $zipTempDir . 'zip_' . uniqid() . '.zip';

        $files = [];
        foreach ($fileInfos as $fileInfo) {
            if (!file_exists($fileInfo['path'])) {
                continue;
            }
            $localName = $fileInfo['filename'] ?? basename($fileInfo['path']);
            $files[] = [
                'path' => $fileInfo['path'],
                'name'  => $localName,
            ];
        }

        if (empty($files)) {
            throw new \Exception('没有有效的文件可供打包');
        }

        $archiveTool = new ArchiveTool();

        $password = $options['password'] ?? null;
        if ($password) {
            $archiveTool->setPassword($password);
        }

        if (!$archiveTool->compressFiles($files, $zipPath, 'zip')) {
            throw new \Exception('ZIP压缩失败: ' . ($archiveTool->getLastError() ?: '未知错误'));
        }

        return $zipPath;
    }

    /**
     * 处理ZIP下载
     * @param array $tokenData
     * @return \think\Response
     */
    protected function processZipDownload(array $tokenData)
    {
        $this->checkZipPermission($tokenData);

        $cacheKey = 'zip_cache:' . md5(serialize($tokenData['file_ids']));
        $zipPath = Cache::get($cacheKey);

        if (!$zipPath || !file_exists($zipPath)) {
            $zipPath = $this->createZipFile(
                $tokenData['file_infos'],
                $tokenData['zip_name'],
                [
                    'password'    => $tokenData['password'] ?? null,
                    'compression'=> $tokenData['compression'] ?? \ZipArchive::CM_DEFAULT,
                ]
            );

            Cache::set($cacheKey, $zipPath, 300);
        }

        $this->updateDownloadStats($tokenData['token'], $tokenData);

        if ($this->config['enable_logging']) {
            $this->logZipDownload($tokenData);
        }

        return $this->deliverZipFile($zipPath, $tokenData['zip_name']);
    }

    /**
     * 验证ZIP下载权限
     * @param array $tokenData
     * @throws \Exception
     */
    protected function checkZipPermission(array $tokenData): void
    {
        if (!empty($tokenData['ip_address']) && $tokenData['ip_address'] !== Request::ip()) {
            throw new \Exception('IP地址不允许下载');
        }

        if (!empty($tokenData['user_id'])) {
            $userId = auth_id('member') ?? 0;
            if ($tokenData['user_id'] != $userId) {
                throw new \Exception('用户权限不足');
            }
        }

        if ($tokenData['file_count'] > $this->config['zip_max_files']) {
            throw new \Exception('文件数量超过限制');
        }

        if ($tokenData['total_size'] > $this->config['zip_max_size']) {
            throw new \Exception('文件总大小超过限制');
        }
    }

    /**
     * 发送ZIP文件给客户端
     * @param string $zipPath
     * @param string $filename
     * @return \think\Response
     */
    /**
     * 输出 ZIP 文件（FPM + Swoole 兼容）
     *
     * 返回 Response 对象替代 header()+readfile()+exit，
     * 避免 Swoole 协程下直接 kill worker 进程。
     *
     * @param string $zipPath   ZIP 文件路径
     * @param string $filename  下载文件名
     * @return \think\Response
     */
    protected function deliverZipFile(string $zipPath, string $filename): \think\Response
    {
        return response('', 200, [
            'Content-Type'              => 'application/zip',
            'Content-Disposition'       => 'attachment; filename="' . rawurlencode($filename) . '"',
            'Content-Length'            => (string) filesize($zipPath),
            'Content-Transfer-Encoding' => 'binary',
            'Cache-Control'             => 'no-cache, no-store, must-revalidate',
            'Pragma'                    => 'no-cache',
            'Expires'                   => '0',
        ])->sendfile($zipPath);
    }

    /**
     * 记录ZIP下载日志
     * @param array $tokenData
     */
    protected function logZipDownload(array $tokenData): void
    {
        try {
            if (class_exists('app\model\DownloadLog')) {
                \app\model\DownloadLog::create([
                    'token'         => $this->getCacheKey($tokenData['token'] ?? ''),
                    'user_id'       => $tokenData['user_id'] ?? 0,
                    'file_id'       => 0,
                    'file_name'     => $tokenData['zip_name'],
                    'file_size'     => $tokenData['total_size'],
                    'file_count'    => $tokenData['file_count'],
                    'ip_address'    => Request::ip(),
                    'user_agent'    => Request::server('HTTP_USER_AGENT'),
                    'referer'       => Request::server('HTTP_REFERER'),
                    'download_time' => time(),
                    'status'        => 'success',
                    'type'          => 'zip',
                ]);
            }
        } catch (\Exception $e) {
            ContextualLogger::error('ZIP下载日志记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证下载令牌
     * @param string $token
     * @return array
     * @throws \Exception
     */
    protected function validateToken(string $token): array
    {
        $cacheKey = $this->getCacheKey($token);
        $data = Cache::get($cacheKey);

        if (!$data) {
            throw new \Exception('下载链接已过期或不存在');
        }

        if (isset($data['expire_time']) && $data['expire_time'] < time()) {
            Cache::delete($cacheKey);
            throw new \Exception('下载链接已过期');
        }

        if (isset($data['download_limit']) &&
            isset($data['download_count']) &&
            $data['download_count'] >= $data['download_limit']) {
            throw new \Exception('下载次数已超过限制');
        }

        if ($this->config['enable_signature'] && isset($data['signature'])) {
            $signature = $data['signature'];
            unset($data['signature']);

            if ($this->generateSignature($data) !== $signature) {
                throw new \Exception('签名验证失败');
            }

            $data['signature'] = $signature;
        }

        return $data;
    }

    /**
     * 生成令牌
     * @return string
     */
    protected function generateToken(): string
    {
        return str_replace('-', '', Uuid::uuid4()->toString());
    }

    /**
     * 生成签名
     * @param array $data
     * @return string
     */
    protected function generateSignature(array $data): string
    {
        ksort($data);
        $string = http_build_query($data);
        return hash_hmac('sha256', $string, $this->config['secret_key']);
    }

    /**
     * 构建下载URL
     * @param string $token
     * @param bool $isZip 是否为ZIP下载
     * @return string
     */
    protected function buildDownloadUrl(string $token, bool $isZip = false): string
    {
        $route = $isZip ? 'download/zip' : 'download/file';
        return url($route, ['token' => $token], false, true);
    }

    /**
     * 获取缓存键名
     * @param string $token
     * @return string
     */
    protected function getCacheKey(string $token): string
    {
        return 'download_token:' . $token;
    }

    /**
     * 获取文件信息
     * @param mixed $fileId
     * @return array
     * @throws \Exception
     */
    public function getFileInfo($fileId): array
    {
        if (is_string($fileId) && file_exists($fileId)) {
            return [
                'path' => $fileId,
                'filename' => basename($fileId),
                'size' => filesize($fileId),
                'mime_type' => mime_content_type($fileId),
            ];
        }

        if (class_exists('\wise\attachment\model\AttachmentModel')) {
            $file = Attachment::find($fileId);
            if (!$file) {
                throw new \Exception('文件不存在');
            }

            $localRoot = Config::get('wise-attachment.drivers.local.root', root_path() . 'public/uploads');
            $realPath = rtrim($localRoot, '/\\') . '/' . ltrim($file->object_name, '/\\');

            if (!file_exists($realPath)) {
                throw new \Exception('文件不存在或已被删除');
            }

            return [
                'path'      => $realPath,
                'filename'  => basename($file->object_name),
                'size'      => $file->size,
                'mime_type' => $file->mime_type,
                'file_data' => $file->toArray(),
            ];
        }

        throw new \Exception('无效的文件标识');
    }

    /**
     * 检查下载权限
     * @param array $tokenData
     * @param array $fileInfo
     * @throws \Exception
     */
    protected function checkPermission(array $tokenData, array $fileInfo): void
    {
        if (!empty($tokenData['ip_address']) && $tokenData['ip_address'] !== Request::ip()) {
            throw new \Exception('IP地址不允许下载');
        }

        if (!empty($tokenData['referer'])) {
            $currentReferer = Request::server('HTTP_REFERER');
            if (empty($currentReferer) || strpos($currentReferer, $tokenData['referer']) === false) {
                throw new \Exception('来源页面不允许下载');
            }
        }

        if (!empty($tokenData['user_id'])) {
            $userId = auth_id('member') ?? 0;
            if ($tokenData['user_id'] != $userId) {
                throw new \Exception('用户权限不足');
            }
        }

        $extension = pathinfo($fileInfo['filename'], PATHINFO_EXTENSION);
        if (!in_array(strtolower($extension), $this->config['allowed_extensions'])) {
            throw new \Exception('文件类型不允许下载');
        }

        if ($fileInfo['size'] > $this->config['max_file_size']) {
            throw new \Exception('文件大小超过限制');
        }
    }

    /**
     * 更新下载统计
     * @param string $token
     * @param array $data
     */
    protected function updateDownloadStats(string $token, array &$data): void
    {
        $data['download_count']++;
        $data['last_download_time'] = time();
        $data['last_download_ip'] = Request::ip();

        $remainingTime = $data['expire_time'] - time();
        Cache::set($this->getCacheKey($token), $data, $remainingTime);
    }

    /**
     * 记录下载日志
     * @param array $tokenData
     * @param array $fileInfo
     */
    protected function logDownload(array $tokenData, array $fileInfo): void
    {
        try {
            if (class_exists('app\model\DownloadLog')) {
                \app\model\DownloadLog::create([
                    'token'         => $this->getCacheKey($tokenData['token'] ?? ''),
                    'user_id'       => $tokenData['user_id'] ?? 0,
                    'file_id'       => is_numeric($tokenData['file_id']) ? $tokenData['file_id'] : 0,
                    'file_name'     => $fileInfo['filename'],
                    'file_size'     => $fileInfo['size'],
                    'ip_address'    => Request::ip(),
                    'user_agent'    => Request::server('HTTP_USER_AGENT'),
                    'referer'       => Request::server('HTTP_REFERER'),
                    'download_time' => time(),
                    'status'        => 'success',
                ]);
            }
        } catch (\Exception $e) {
            ContextualLogger::error('下载日志记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 发送文件给客户端
     * @param array $fileInfo
     * @param string|null $customFilename
     * @return \think\Response
     */
    protected function deliverFile(array $fileInfo, ?string $customFilename = null)
    {
        $filename = $customFilename ?: $fileInfo['filename'];

        if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules())) {
            return response('', 200, [
                'X-Sendfile' => $fileInfo['path'],
                'Content-Type' => $fileInfo['mime_type'],
                'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
            ]);
        }

        if (strpos((string) request()->server('SERVER_SOFTWARE', ''), 'nginx') !== false) {
            $internalPath = '/protected_downloads/' . basename($fileInfo['path']);
            return response('', 200, [
                'X-Accel-Redirect' => $internalPath,
                'Content-Type' => $fileInfo['mime_type'],
                'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
            ]);
        }

        return download($fileInfo['path'], $filename);
    }

    /**
     * 生成二维码
     * @param string $url
     * @return string|null
     */
    protected function generateQRCode(string $url): ?string
    {
        if (extension_loaded('gd') && class_exists('Endroid\QrCode\QrCode')) {
            try {
                $qrCode = new \Endroid\QrCode\QrCode($url);
                $qrCode->setSize(200);
                return $qrCode->writeDataUri();
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }
}
