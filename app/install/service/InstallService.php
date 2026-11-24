<?php
declare(strict_types=1);

namespace app\install\service;

use think\facade\Db;
use think\facade\Config;
use think\facade\App;
use think\facade\Cache;
use think\facade\Env;

class InstallService
{
    /**
     * 安装锁文件相对路径（与 CLI 安装命令 app/install/command/Install.php 保持一致）
     * 统一存放于 {root}/app/install/install.lock
     */
    public const LOCK_FILE_REL = 'app' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'install.lock';

    /**
     * 获取安装锁文件绝对路径
     */
    public static function getLockFilePath(): string
    {
        return App::getRootPath() . self::LOCK_FILE_REL;
    }

    /**
     * 检查是否已安装
     *
     * 严格判断：存在 {root}/app/install/install.lock 即视为已安装。
     */
    public static function checkInstalled(): bool
    {
        return file_exists(self::getLockFilePath());
    }

    /**
     * 判断当前是否允许网页版「重新安装」。
     *
     * 采用与 CLI 安装命令 command/Install.php 的 checkLockFile() 一致的
     * 多重判断逻辑：
     *   1. 未安装（不存在 install.lock）时不存在“重装”概念，返回 false；
     *   2. 已安装且 .env 显式设置 ALLOW_REINSTALL=false 时，禁止网页重装，
     *      与 CLI 的 --force 守卫保持一致（防止生产环境误操作清空数据库）。
     *
     * 注意：本方法只判定“是否放开重装入口”，真正执行前还需校验 REINSTALL_KEY。
     */
    public static function canReinstall(): bool
    {
        if (!self::checkInstalled()) {
            return false;
        }

        return env('ALLOW_REINSTALL', true) !== false;
    }
    
    /**
     * 检查环境要求
     */
    public static function checkEnvironment(): array
    {
        $requirements = [
            'php_version' => [
                'name' => 'PHP版本',
                'require' => '>=7.4.0',
                'current' => PHP_VERSION,
                'result' => version_compare(PHP_VERSION, '7.4.0', '>='),
            ],
            'pdo_mysql' => [
                'name' => 'PDO MySQL扩展',
                'require' => '必需',
                'current' => extension_loaded('pdo_mysql') ? '已安装' : '未安装',
                'result' => extension_loaded('pdo_mysql'),
            ],
            'openssl' => [
                'name' => 'OpenSSL扩展',
                'require' => '必需',
                'current' => extension_loaded('openssl') ? '已安装' : '未安装',
                'result' => extension_loaded('openssl'),
            ],
            'mbstring' => [
                'name' => 'Mbstring扩展',
                'require' => '必需',
                'current' => extension_loaded('mbstring') ? '已安装' : '未安装',
                'result' => extension_loaded('mbstring'),
            ],
            'json' => [
                'name' => 'JSON扩展',
                'require' => '必需',
                'current' => extension_loaded('json') ? '已安装' : '未安装',
                'result' => extension_loaded('json'),
            ],
        ];
        
        return $requirements;
    }
    
    /**
     * 检查目录权限
     */
    public static function checkDirectoryPermissions(): array
    {
        $directories = [
            'runtime' => [
                'path' => App::getRootPath() . 'runtime',
                'require' => '可读写',
            ],
            'public/uploads' => [
                'path' => App::getRootPath() . 'public/uploads',
                'require' => '可读写',
            ],
            'config' => [
                'path' => App::getConfigPath(),
                'require' => '可读',
            ],
        ];
        
        $results = [];
        foreach ($directories as $name => $dir) {
            if (!is_dir($dir['path'])) {
                @mkdir($dir['path'], 0755, true);
            }
            
            $isWritable = is_writable($dir['path']);
            $results[$name] = [
                'name' => $name,
                'path' => $dir['path'],
                'require' => $dir['require'],
                'current' => $isWritable ? '可写' : '不可写',
                'result' => $isWritable,
            ];
        }
        
        return $results;
    }
    
    /**
     * 测试数据库连接
     */
    public static function testDatabaseConnection(array $config): array
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $config['hostname'],
                $config['hostport']
            );
            
            $pdo = new \PDO($dsn, $config['username'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            
            $stmt = $pdo->query("SHOW DATABASES LIKE '{$config['database']}'");
            $databaseExists = $stmt->rowCount() > 0;
            
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $versionOk = version_compare($version, '5.7.0', '>=');
            
            return [
                'success' => true,
                'database_exists' => $databaseExists,
                'database_version' => $version,
                'version_ok' => $versionOk,
                'message' => $databaseExists ? 
                    '数据库连接成功，数据库已存在' : 
                    '数据库连接成功，数据库不存在将自动创建',
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => '连接失败: ' . $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }
    
    /**
     * 写入配置文件
     */
    public static function writeConfig(array $config): bool
    {
        $envPath = App::getRootPath() . '.env';
        
        if (file_exists($envPath)) {
            copy($envPath, $envPath . '.backup-' . date('YmdHis'));
        }
        
        $envContent = self::generateEnvContent($config);
        $result = file_put_contents($envPath, $envContent);

        try {
            $seed = new \wise\support\env\EnvSeedService();
            $report = $seed->seed($envPath, App::getRootPath() . 'vendor');
            foreach ($report['warnings'] as $warning) {
                error_log('[env-seed] ' . $warning);
            }
        } catch (\Throwable $e) {
        }

        try {
            app()->env->load($envPath);
        } catch (\Throwable $e) {
        }

        try {
            $dbConfig = config('database');
            if (is_array($dbConfig) && isset($dbConfig['connections'])) {
                foreach ($dbConfig['connections'] as $connName => $connConfig) {
                    $dbConfig['connections'][$connName]['hostname'] = env('DB_HOST', $connConfig['hostname'] ?? '');
                    $dbConfig['connections'][$connName]['hostport'] = env('DB_PORT', $connConfig['hostport'] ?? '');
                    $dbConfig['connections'][$connName]['database'] = env('DB_NAME', $connConfig['database'] ?? '');
                    $dbConfig['connections'][$connName]['username'] = env('DB_USER', $connConfig['username'] ?? '');
                    $dbConfig['connections'][$connName]['password'] = env('DB_PASS', $connConfig['password'] ?? '');
                    $dbConfig['connections'][$connName]['prefix']  = env('DB_PREFIX', $connConfig['prefix'] ?? '');
                }
                \think\facade\Config::set(['database' => $dbConfig], '');
            }
        } catch (\Throwable $e) {
        }

        return $result !== false;
    }
    
    /**
     * 执行安装
     */
    public static function executeInstall(array $config): array
    {
        set_time_limit(300);
        
        try {
            if (!self::writeConfig($config)) {
                throw new \Exception('配置文件写入失败，请检查目录权限');
            }
            
            self::createDatabase($config);
            
            $migrationResult = self::runMigrations();
            if (!$migrationResult['success']) {
                throw new \Exception('数据库迁移失败: ' . $migrationResult['message']);
            }
            
            $seedResult = self::runSeeds($config);
            if (!$seedResult['success']) {
                throw new \Exception('数据初始化失败: ' . $seedResult['message']);
            }
            
            self::importInitData($config);
            
            self::generatePermissions($config);
            
            self::createLockFile($config);
            
            self::clearCache();
            
            return [
                'success' => true,
                'message' => '安装成功',
                'data' => [
                    'admin_url' => url('/admin/login')->build(),
                    'admin_user' => $config['admin_username'],
                ],
            ];
        } catch (\Exception $e) {
            self::restoreBackup();
            
            return [
                'success' => false,
                'message' => '安装失败: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 创建数据库
     */
    private static function createDatabase(array $config): void
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $config['hostname'],
                $config['hostport']
            );
            
            $pdo = new \PDO($dsn, $config['username'], $config['password']);
            
            $pdo->exec("SET time_zone = '+8:00'");
            
            $sql = sprintf(
                "CREATE DATABASE IF NOT EXISTS `%s` 
                DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                $config['database']
            );
            
            $pdo->exec($sql);
            
            $pdo->exec("USE `{$config['database']}`");
            
        } catch (\PDOException $e) {
            throw new \Exception('数据库创建失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 运行迁移 - 调用命令行
     */
    private static function runMigrations(): array
    {
        try {
            $rootPath = App::getRootPath();
            
            $command = sprintf(
                '%s %sthink migrate:run --force',
                PHP_BINARY,
                $rootPath
            );
            
            $output = [];
            $returnCode = 0;
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \Exception('迁移命令执行失败: ' . implode("\n", $output));
            }
            
            
            return [
                'success' => true,
                'message' => '数据库迁移完成',
                'output' => $output,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 直接运行迁移（备选方案）
     */
    private static function runMigrationsDirectly(): void
    {
        $migratePath = App::getRootPath() . 'database/migrations/';
        
        if (!is_dir($migratePath)) {
            throw new \Exception('迁移目录不存在: ' . $migratePath);
        }
        
        $migrationFiles = glob($migratePath . '*.php');
        
        if (empty($migrationFiles)) {
            return;
        }
        
        foreach ($migrationFiles as $migrationFile) {
            $className = '\\app\\database\\migrations\\' . 
                        pathinfo($migrationFile, PATHINFO_FILENAME);
            
            if (!class_exists($className)) {
                require_once $migrationFile;
            }
            
            if (class_exists($className)) {
                $instance = new $className();
                if (method_exists($instance, 'up')) {
                    $instance->up();
                }
            }
        }
    }
    
    /**
     * 运行数据填充
     */
    private static function runSeeds(array $config): array
    {
        try {
            $seedsPath = App::getRootPath() . 'database/seeds/';
            
            if (!is_dir($seedsPath)) {
                return self::createDefaultData($config);
            }
            
            $seedFiles = glob($seedsPath . '*.php');
            
            if (empty($seedFiles)) {
                return self::createDefaultData($config);
            }
            
            $results = [];
            foreach ($seedFiles as $seedFile) {
                $fileName = pathinfo($seedFile, PATHINFO_FILENAME);
                
                try {
                    require_once $seedFile;
                    
                    $className = self::convertToClassName($fileName);
                    
                    if (!class_exists($className)) {
                        $className = '\\app\\database\\seeds\\' . $className;
                        
                        if (!class_exists($className)) {
                            $results[] = [
                                'file' => $fileName,
                                'success' => false,
                                'message' => '类不存在',
                            ];
                            continue;
                        }
                    }
                    
                    $instance = new $className();
                    
                    if (method_exists($instance, 'run')) {
                        $instance->run();
                        $results[] = [
                            'file' => $fileName,
                            'success' => true,
                            'message' => '执行成功',
                        ];
                    } else {
                        $results[] = [
                            'file' => $fileName,
                            'success' => false,
                            'message' => '无run方法',
                        ];
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'file' => $fileName,
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                    throw $e;
                }
            }
            
            self::updateAdminAccount($config);
            
            return [
                'success' => true,
                'message' => '数据填充完成',
                'results' => $results,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '数据填充失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 导入框架级初始化数据（SQL 文件）
     *
     * 在迁移与种子数据之后执行，此时 config 等表已就绪。
     * 导入失败不阻断安装，仅记录日志。
     */
    private static function importInitData(array $config): void
    {
        try {
            $result = InitDataService::import([
                '__SITE_NAME__'   => $config['site_name'] ?? '后台管理系统',
                '__ADMIN_EMAIL__' => $config['admin_email'] ?? (($config['admin_username'] ?? 'admin') . '@example.com'),
            ]);

            if (!$result['success']) {
                error_log('[init-data] 导入失败：' . implode('；', $result['errors']));
            }
        } catch (\Throwable $e) {
            error_log('[init-data] 异常：' . $e->getMessage());
        }
    }
    
    /**
     * 生成权限列表（菜单派生 + 控制器注解派生）
     *
     * 在迁移与初始化数据之后执行，此时 menus / auth_permissions 表已就绪。
     * 失败不阻断安装，仅记录日志。
     */
    private static function generatePermissions(array $config): void
    {
        try {
            $result = \app\install\service\PermissionInitService::build();
            error_log(sprintf(
                '[permission-init] 菜单派生 %d 条，Attribute 派生 %d 条',
                $result['menus'],
                $result['attributes']
            ));
        } catch (\Throwable $e) {
            error_log('[permission-init] 异常：' . $e->getMessage());
        }
    }
    
    /**
     * 创建默认数据
     */
    private static function createDefaultData(array $config): array
    {
        try {
            $adminData = [
                'username' => $config['admin_username'],
                'password' => password_hash($config['admin_password'], PASSWORD_DEFAULT),
                'nickname' => '超级管理员',
                'email'    => $config['admin_email'] ?? $config['admin_username'] . '@example.com',
                'avatar'   => '',
                'status'   => 1,
                'create_time' => time(),
                'update_time' => time(),
            ];
            
            $userId = Db::name('admin_user')->insertGetId($adminData);
            
            $roleData = [
                'name' => '超级管理员',
                'description' => '拥有所有权限',
                'status' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ];
            
            $roleId = Db::name('admin_role')->insertGetId($roleData);
            
            Db::name('admin_user_role')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
            
            $menus = [
                [
                    'pid' => 0,
                    'type' => 0,
                    'title' => '控制台',
                    'icon' => 'dashboard',
                    'url' => 'dashboard/index',
                    'permission' => '',
                    'sort' => 0,
                    'status' => 1,
                    'create_time' => time(),
                    'update_time' => time(),
                ],
            ];
            
            foreach ($menus as $menu) {
                Db::name('admin_menu')->insert($menu);
            }
            
            return [
                'success' => true,
                'message' => '默认数据创建完成',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '创建默认数据失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 更新管理员账户信息
     */
    private static function updateAdminAccount(array $config): void
    {
        try {
            Db::name('admin_user')
                ->where('username', 'admin')
                ->update([
                    'username' => $config['admin_username'],
                    'password' => password_hash($config['admin_password'], PASSWORD_DEFAULT),
                    'email' => $config['admin_email'] ?? $config['admin_username'] . '@example.com',
                    'update_time' => time(),
                ]);
        } catch (\Exception $e) {
        }
    }
    
    /**
     * 创建安装锁文件
     */
    private static function createLockFile(array $config): void
    {
        $lockFile = self::getLockFilePath();
        $data = [
            'install_time' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
            'site_name' => $config['site_name'] ?? '后台管理系统',
            'admin_user' => $config['admin_username'],
            'config' => [
                'database' => $config['database'],
                'host' => $config['hostname'],
                'port' => $config['hostport'],
            ],
            'install_mode' => 'web',
        ];
        
        if (file_put_contents($lockFile, json_encode($data, JSON_PRETTY_PRINT)) === false) {
            throw new \Exception('创建安装锁文件失败');
        }
        
        @chmod($lockFile, 0644);
    }
    
    /**
     * 清除缓存
     */
    private static function clearCache(): void
    {
        try {
            $cacheDir = App::getRootPath() . 'runtime/cache/';
            if (is_dir($cacheDir)) {
                array_map('unlink', glob($cacheDir . '*'));
            }
            
            Cache::clear();
            
        } catch (\Exception $e) {
        }
    }
    
    /**
     * 恢复备份配置
     */
    private static function restoreBackup(): void
    {
        $envPath = App::getRootPath() . '.env';
        $backups = glob($envPath . '.backup-*');
        
        if (!empty($backups)) {
            rsort($backups);
            $latestBackup = $backups[0];
            
            if (file_exists($latestBackup)) {
                copy($latestBackup, $envPath);
                unlink($latestBackup);
            }
        }
    }
    
    /**
     * 确保 .env 中存在后台安全入口 ADMIN_ENTRY（缺失时自动生成 24 位随机串）
     *
     * 目的：避免所有安装共用源码里写死的默认入口。入口写入 .env（不入版本库、
     * 发布不覆盖），由安装流程（CLI / Web）统一幂等生成。
     *
     * 幂等约束：键已存在则绝不动它——重装 / 二次安装不会洗掉用户已设置的入口，
     * 与 EnvSeedService 的「不覆盖已有键」策略保持一致。
     *
     * @param string $content .env 内容（引用传入/传出）
     * @return bool 是否生成了新入口（true=本次写入）
     */
    public static function ensureAdminEntryKey(string &$content): bool
    {
        if (preg_match('/^ADMIN_ENTRY\s*=/m', $content)) {
            return false;
        }

        try {
            $entry = self::generateRandomToken(24);
        } catch (\Throwable $e) {
            return false;
        }

        $content .= "\n; 后台安全入口：开启安全入口功能(config/entry.php 的 enabled)后，需先访问该地址才能进入 /admin；若泄露请删除本行后重新安装生成\nADMIN_ENTRY = {$entry}\n";

        return true;
    }

    /**
     * 生成 URL 路径可直接使用的随机 token（去除易混淆字符 0/O/1/l/I）
     */
    private static function generateRandomToken(int $length): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }

    /**
     * 生成.env内容
     */
    private static function generateEnvContent(array $config): string
    {
        $envPath = App::getRootPath() . '.env';
        
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
        } else {
            $envContent = <<<ENV
APP_DEBUG = false
ALLOW_REINSTALL = false
; 网页重装安全码：留空则网页重装入口禁用，需手动设置后方可启用
REINSTALL_KEY =

APP_NAME = 后台管理系统

DB_TYPE = mysql
DB_HOST = 127.0.0.1
DB_NAME = 
DB_USER = root
DB_PASS = 
DB_PORT = 3306
DB_CHARSET = utf8mb4
DB_PREFIX = ws_

ENV;
        }
        
        self::normalizeDbBlock($envContent, [
            'DB_HOST'   => $config['hostname'] ?? '127.0.0.1',
            'DB_NAME'   => $config['database'] ?? '',
            'DB_USER'   => $config['username'] ?? 'root',
            'DB_PASS'   => $config['password'] ?? '',
            'DB_PORT'   => $config['hostport'] ?? '3306',
            'DB_PREFIX' => $config['prefix'] ?? 'ws_',
        ]);
        
        $siteName = strtr((string) ($config['site_name'] ?? '后台管理系统'), ['\\' => '\\\\', '$' => '\\$']);
        if (preg_match('/^APP_NAME\s*=/m', $envContent)) {
            $envContent = preg_replace('/^APP_NAME\s*=\s*.*/m', 'APP_NAME = ' . $siteName, $envContent);
        } else {
            $envContent .= "\nAPP_NAME = {$siteName}";
        }
        
        if (preg_match('/^APP_DEBUG\s*=/m', $envContent)) {
            $envContent = preg_replace('/^APP_DEBUG\s*=\s*.*/m', 'APP_DEBUG = false', $envContent);
        }

        self::ensureAdminEntryKey($envContent);

        return $envContent;
    }
    
    /**
     * 将数据库相关配置规整为「紧随 APP_NAME 之后的规范块」，杜绝重装时
     * DB_PORT 因在已有 .env 中缺失而被追加到文件末尾（wise-* 包播种之后）。
     *
     * 做法：
     *   1. 剔除 content 中所有零散的 DB_* 行（含可能散落在文件尾部的 DB_PORT）；
     *   2. 在 APP_NAME 行之后重建规范顺序的 DB 块。
     * wise-* 包配置不在此处理，恒由 EnvSeedService 幂等追加在文件末尾。
     *
     * @param string $content 引用传入/传出
     * @param array<string,string> $db 键 DB_HOST/DB_NAME/DB_USER/DB_PASS/DB_PORT/DB_PREFIX => 值
     */
    private static function normalizeDbBlock(string &$content, array $db): void
    {
        $lines = explode("\n", $content);
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed !== '' && $trimmed[0] !== '#' && $trimmed[0] !== ';') {
                if (preg_match('/^(?:export\s+)?DB_[A-Za-z0-9_]*\s*=/', $trimmed)) {
                    continue;
                }
            }
            $kept[] = $line;
        }
        $content = implode("\n", $kept);

        $esc = static fn(string $v): string => strtr($v, ['\\' => '\\\\', '$' => '\\$']);
        $block = "DB_TYPE = mysql\n"
            . "DB_HOST = "   . $esc((string) ($db['DB_HOST']   ?? '127.0.0.1')) . "\n"
            . "DB_NAME = "   . $esc((string) ($db['DB_NAME']   ?? '')) . "\n"
            . "DB_USER = "   . $esc((string) ($db['DB_USER']   ?? 'root')) . "\n"
            . "DB_PASS = "   . $esc((string) ($db['DB_PASS']   ?? '')) . "\n"
            . "DB_PORT = "   . $esc((string) ($db['DB_PORT']   ?? '3306')) . "\n"
            . "DB_CHARSET = utf8mb4\n"
            . "DB_PREFIX = " . $esc((string) ($db['DB_PREFIX'] ?? 'ws_')) . "\n";

        if (preg_match('/^APP_NAME\s*=/m', $content)) {
            $content = preg_replace('/^APP_NAME\s*=\s*.*/m', "APP_NAME = " . $esc((string) ($db['APP_NAME'] ?? '后台管理系统')) . "\n" . $block, $content, 1);
        } else {
            $content = rtrim($content) . "\n\nAPP_NAME = " . $esc((string) ($db['APP_NAME'] ?? '后台管理系统')) . "\n" . $block;
        }
    }
    
    /**
     * 将文件名转换为类名
     */
    private static function convertToClassName(string $fileName): string
    {
        $fileName = preg_replace('/^\d+_/', '', $fileName);
        
        $parts = preg_split('/[-_]/', $fileName);
        $parts = array_map('ucfirst', $parts);
        
        return implode('', $parts);
    }
    
    /**
     * 获取安装状态
     */
    public static function getInstallStatus(): array
    {
        $lockFile = self::getLockFilePath();
        
        if (!file_exists($lockFile)) {
            return [
                'installed' => false,
                'message' => '未安装',
            ];
        }
        
        try {
            $data = json_decode(file_get_contents($lockFile), true);
            
            return [
                'installed' => true,
                'install_time' => $data['install_time'] ?? '未知',
                'version' => $data['version'] ?? '1.0.0',
                'site_name' => $data['site_name'] ?? '后台管理系统',
            ];
        } catch (\Exception $e) {
            return [
                'installed' => true,
                'message' => '已安装（锁文件解析失败）',
            ];
        }
    }
}