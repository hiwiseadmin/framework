<?php
declare(strict_types=1);

namespace app\install\command;

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Util\Util;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\App;
use think\facade\Console as ConsoleFacade;
use think\facade\Db;

/**
 * 命令行安装工具
 *
 * 用法：
 *   # 交互式安装
 *   php think install
 *
 *   # 参数直接传入
 *   php think install --db_host=127.0.0.1 --db_port=3306 --db_name=myapp --db_user=root --db_pwd=123456 --admin_user=admin --admin_pwd=admin123 --admin_email=admin@example.com
 *
 *   # 强制重新安装
 *   php think install --force
 */
class Install extends Command
{
    protected $config = [];
    protected $interactive = true;

    protected function configure()
    {
        $this->setName('install')
            ->setDescription('WiseAdmin 系统安装向导')
            ->addOption('db_host',      null, Option::VALUE_REQUIRED, '数据库主机', '127.0.0.1')
            ->addOption('db_port',      null, Option::VALUE_REQUIRED, '数据库端口', '3306')
            ->addOption('db_name',      null, Option::VALUE_REQUIRED, '数据库名', '')
            ->addOption('db_user',      null, Option::VALUE_REQUIRED, '数据库用户名', 'root')
            ->addOption('db_pwd',       null, Option::VALUE_REQUIRED, '数据库密码', '')
            ->addOption('db_prefix',    null, Option::VALUE_REQUIRED, '表前缀', 'ws_')
            ->addOption('admin_user',   null, Option::VALUE_REQUIRED, '管理员账号', 'admin')
            ->addOption('admin_pwd',    null, Option::VALUE_REQUIRED, '管理员密码', '')
            ->addOption('admin_email',  null, Option::VALUE_REQUIRED, '管理员邮箱', 'admin@example.com')
            ->addOption('site_name',    null, Option::VALUE_REQUIRED, '站点名称', 'WiseAdmin')
            ->addOption('force',        'f',  Option::VALUE_NONE,     '强制重新安装');
    }

    protected function execute(Input $input, Output $output)
    {
        if (PHP_SAPI !== 'cli') {
            $output->writeln('<error>该命令仅限 CLI 环境执行</error>');
            return 1;
        }

        try {
            if (!$this->checkLockFile($input, $output)) return 0;

            $this->printBanner($output);

            $output->writeln('');
            $output->writeln('<comment>=== 第 1 步：环境检测 ===</comment>');
            if (!$this->checkEnvironment($output)) {
                $output->writeln('');
                $output->writeln('<error>环境检测未通过，无法继续安装</error>');
                return 1;
            }
            $output->writeln('<info>✓ 环境检测全部通过</info>');

            $output->writeln('');
            $output->writeln('<comment>=== 第 2 步：配置信息 ===</comment>');
            $this->collectConfig($input, $output);

            $output->writeln('');
            $this->showConfigSummary($output);

            if ($this->interactive) {
                $confirm = $this->output->confirm($input, '确认开始安装？', true);
                if (!$confirm) { $output->writeln('<comment>已取消</comment>'); return 0; }
            }

            $output->writeln('');
            $output->writeln('<comment>=== 第 3 步：执行安装 ===</comment>');
            $this->doInstall($output, $input->getOption('force'));

            $output->writeln('');
            $output->writeln('<info>╔══════════════════════════════════════╗</info>');
            $output->writeln('<info>║         ✓  安装完成！                ║</info>');
            $output->writeln('<info>╚══════════════════════════════════════╝</info>');
            $output->writeln('');
            $output->writeln('  后台地址：  <comment>' . $this->getAdminUrl() . '</comment>');
            $output->writeln('  管理员账号：<comment>' . $this->config['admin_user'] . '</comment>');
            $output->writeln('  管理员密码：<comment>' . $this->config['admin_pwd'] . '</comment>');
            $output->writeln('');
            $output->writeln('<comment>请登录后台后立即修改管理员密码！</comment>');
            $output->writeln('');
            $output->writeln('<comment>=== 重装开关（安全默认） ===</comment>');
            $output->writeln('  本次安装已写入 <info>ALLOW_REINSTALL = false</info>，即默认禁止重装：');
            $output->writeln('  php think install --force 与网页重装入口都会被拒绝。');
            $output->writeln('  确需重装时，临时把该值改为 <info>true</info>（网页重装还需同时配置 REINSTALL_KEY）。');


            return 0;
        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln('<error>安装失败：' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    protected function printBanner(Output $output): void
    {
        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║      WiseAdmin 系统安装向导         ║</info>');
        $output->writeln('<info>║      v' . config('install.system.version', '2.0.0') . '                          ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
    }

    protected function checkLockFile(Input $input, Output $output): bool
    {
        $lockFile = $this->getLockPath();

        if (!\app\install\service\InstallService::checkInstalled()) {
            if (file_exists($lockFile)) {
                @unlink($lockFile);
                $output->writeln('<comment>检测到陈旧安装锁（.env 无数据库配置），已自动清除，按全新安装处理</comment>');
            }

            return true;
        }

        if ($input->getOption('force')) {
            if (!\app\install\service\InstallService::reinstallAllowed()) {
                $output->writeln('<error>本环境未放开重装（需在 .env 设置 ALLOW_REINSTALL = true），已拒绝 --force</error>');
                return false;
            }

            $output->writeln('<comment>--force 模式：强制重新安装</comment>');
            $output->writeln('<comment>⚠  此操作将清空数据库中所有的表！</comment>');

            if ($this->interactive) {
                $confirm = $this->output->confirm($input, '确认清空数据并重新安装？', false);
                if (!$confirm) { $output->writeln('<comment>已取消</comment>'); return false; }
            }

            @unlink($lockFile);
            return true;
        }

        $output->writeln('<error>系统已安装，如需重新安装请使用 --force 参数</error>');
        return false;
    }

    protected function checkEnvironment(Output $output): bool
    {
        $errors = [];

        $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
        $this->printCheck($output, 'PHP 版本 >= 8.0', $phpOk, PHP_VERSION);
        if (!$phpOk) $errors[] = 'PHP 版本过低：' . PHP_VERSION;

        $required = [
            'pdo_mysql' => 'PDO MySQL', 'mysqli' => 'MySQLi',
            'json' => 'JSON', 'mbstring' => 'Mbstring', 'openssl' => 'OpenSSL',
        ];
        foreach ($required as $ext => $name) {
            $ok = extension_loaded($ext);
            $this->printCheck($output, $name . ' 扩展', $ok, $ok ? '已安装' : '未安装');
            if (!$ok) $errors[] = $name . ' 扩展未安装（必需）';
        }

        $optional = ['gd' => 'GD', 'curl' => 'cURL', 'zip' => 'Zip', 'fileinfo' => 'Fileinfo'];
        foreach ($optional as $ext => $name) {
            $ok = extension_loaded($ext);
            $this->printCheck($output, $name . ' 扩展', true, $ok ? '已安装' : '未安装（建议）', true);
        }

        $rootPath = App::getRootPath();
        $dirs = [
            'runtime/'        => $rootPath . 'runtime',
            'public/uploads/' => $rootPath . 'public' . DIRECTORY_SEPARATOR . 'uploads',
            '.env 可写'        => $rootPath . '.env',
        ];
        foreach ($dirs as $name => $path) {
            if (strpos($name, '.env') !== false) {
                $w = is_writable(dirname($path)) && (!file_exists($path) || is_writable($path));
            } else {
                if (!is_dir($path)) @mkdir($path, 0755, true);
                $w = is_dir($path) && is_writable($path);
            }
            $this->printCheck($output, $name, $w, $w ? '可写' : '不可写');
            if (!$w) $errors[] = $name . ' 无写入权限';
        }

        if (!empty($errors)) {
            $output->writeln('');
            foreach ($errors as $e) $output->writeln('  <error>✗</error> ' . $e);
            return false;
        }
        return true;
    }

    protected function printCheck(Output $output, string $label, bool $ok, string $detail, bool $warn = false): void
    {
        if ($ok && !$warn)
            $output->writeln(sprintf('  <info>✓</info> %-28s <info>%s</info>', $label, $detail));
        elseif ($warn)
            $output->writeln(sprintf('  <comment>!</comment> %-28s <comment>%s</comment>', $label, $detail));
        else
            $output->writeln(sprintf('  <error>✗</error> %-28s <error>%s</error>', $label, $detail));
    }

    protected function collectConfig(Input $input, Output $output): void
    {
        $hasAll = $input->hasParameterOption('--db_host') &&
                  $input->hasParameterOption('--db_name') &&
                  $input->hasParameterOption('--admin_pwd');

        if (!$input->isInteractive()) {
            $missing = [];
            foreach ([
                'db_host'   => '--db_host',
                'db_name'   => '--db_name',
                'db_user'   => '--db_user',
                'admin_pwd' => '--admin_pwd',
            ] as $option => $flag) {
                if (trim((string) $input->getOption($option)) === '') {
                    $missing[] = $flag;
                }
            }

            if ($missing !== []) {
                throw new \RuntimeException(
                    '非交互模式（-n）下必须显式提供：' . implode('、', $missing)
                    . '；否则会以默认值安装到非预期数据库。去掉 -n 可进入交互向导。'
                );
            }
        }

        $this->interactive = !$hasAll && $input->isInteractive();

        if ($this->interactive) {
            $output->writeln('<comment>请输入配置（直接回车使用默认值）：</comment>');
            $output->writeln('');
        }

        $this->config['hostname'] = $this->ask($input, $output, '数据库主机', 'db_host', '127.0.0.1');
        $this->config['hostport'] = $this->ask($input, $output, '数据库端口', 'db_port', '3306');
        $this->config['database'] = $this->ask($input, $output, '数据库名', 'db_name', 'wiseadmin');
        if (empty($this->config['database'])) throw new \RuntimeException('数据库名不能为空');

        $this->config['username'] = $this->ask($input, $output, '数据库用户名', 'db_user', 'root');
        $this->config['password'] = $this->ask($input, $output, '数据库密码', 'db_pwd', '');
        $this->config['prefix']   = $this->ask($input, $output, '表前缀', 'db_prefix', 'ws_');

        if ($this->interactive) {
            $output->writeln('');
            $output->writeln('<comment>管理员配置：</comment>');
            $output->writeln('');
        }

        $this->config['admin_user']  = $this->ask($input, $output, '管理员账号', 'admin_user', 'admin');
        $this->config['admin_pwd']   = $this->ask($input, $output, '管理员密码', 'admin_pwd', '');
        $this->config['admin_email'] = $this->ask($input, $output, '管理员邮箱', 'admin_email', 'admin@example.com');
        $this->config['site_name']   = $this->ask($input, $output, '站点名称', 'site_name', 'WiseAdmin');

        if (empty($this->config['admin_pwd'])) {
            $output->writeln('<comment>⚠ 警告：管理员密码为空，强烈建议设置强密码！</comment>');
            if ($this->interactive) {
                $confirm = $this->output->confirm($input, '确认密码为空？', false);
                if (!$confirm) {
                    $this->config['admin_pwd'] = $this->ask($input, $output, '管理员密码（重新输入）', 'admin_pwd', '');
                }
            }
        }
    }

    protected function ask(Input $input, Output $output, string $label, string $opt, string $default): string
    {
        if (!$this->interactive) return $input->getOption($opt) ?: $default;
        return $this->output->ask($input, "请输入{$label}：", $default);
    }

    protected function showConfigSummary(Output $output): void
    {
        $output->writeln('<comment>配置摘要：</comment>');
        $output->writeln('  ' . str_repeat('-', 40));
        $output->writeln(sprintf('  数据库：<info>%s@%s:%s</info>', $this->config['database'], $this->config['hostname'], $this->config['hostport']));
        $output->writeln(sprintf('  用户名：<info>%s</info>', $this->config['username']));
        $output->writeln(sprintf('  管理员：<info>%s</info>', $this->config['admin_user']));
        $output->writeln(sprintf('  站点名：<info>%s</info>', $this->config['site_name']));
        $output->writeln('  ' . str_repeat('-', 40));
    }

    protected function doInstall(Output $output, bool $force = false): void
    {
        $isForce = $force;
        $steps = [
            '测试数据库连接'   => fn() => $this->testDbConnection(),
            '写入配置文件'    => fn() => $this->writeEnv(),
            '创建数据库'     => fn() => $this->createDb(),
        ];

        if ($isForce) {
            $steps['清空数据库表'] = fn() => $this->dropTables();
        }

        $steps['执行数据库迁移'] = function () use ($isForce) {
            $this->runMigrations($isForce);
        };

        $steps['导入初始化数据'] = fn() => $this->importInitData();
        $steps['生成权限数据'] = fn() => $this->generatePermissions();
        $steps['创建管理员账户'] = fn() => $this->createAdmin();
        $steps['生成安装锁']   = fn() => $this->createLock();

        $i = 1;
        $total = count($steps);
        foreach ($steps as $name => $fn) {
            $output->write(sprintf('  [%d/%d] %-20s', $i++, $total, $name));
            $fn();
            $output->writeln('<info>✓</info>');
        }
    }

    protected function testDbConnection(): void
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config['hostname'], (int)$this->config['hostport']);
            $pdo = new \PDO($dsn, $this->config['username'], $this->config['password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5,
            ]);
            $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\PDOException $e) {
            throw new \RuntimeException('数据库连接失败：' . $e->getMessage());
        }
    }

    protected function writeEnv(): void
    {
        $envPath = App::getRootPath() . '.env';
        $envExists = file_exists($envPath);
        if ($envExists) {
            @copy($envPath, $envPath . '.cli-backup');
        }

        $content = $envExists ? file_get_contents($envPath) : <<<ENV
APP_DEBUG = false
ALLOW_REINSTALL = false
; 网页重装安全码：留空则网页重装入口禁用，需手动设置后方可启用
REINSTALL_KEY =

APP_NAME = WiseAdmin

DB_TYPE = mysql
DB_HOST = 127.0.0.1
DB_NAME = wiseadmin
DB_USER = root
DB_PASS =
DB_PORT = 3306
DB_CHARSET = utf8mb4
DB_PREFIX = ws_

DEFAULT_LANG = zh-cn
ENV;

        $this->normalizeDbBlock($content, [
            'DB_HOST'   => $this->config['hostname'],
            'DB_NAME'   => $this->config['database'],
            'DB_USER'   => $this->config['username'],
            'DB_PASS'   => $this->config['password'] ?? '',
            'DB_PORT'   => $this->config['hostport'],
            'DB_PREFIX' => $this->config['prefix'],
        ]);

        $safeSiteName = strtr($this->config['site_name'], ['\\' => '\\\\', '$' => '\\$']);
        $this->ensureEnvKey($content, 'APP_DEBUG', 'false');
        $this->ensureEnvKey($content, 'ALLOW_REINSTALL', 'false');
        if (!preg_match('/^REINSTALL_KEY\s*=/m', $content)) {
            $content .= "\nREINSTALL_KEY =";
        }
        $this->ensureEnvKey($content, 'APP_NAME', $safeSiteName);

        \app\install\service\InstallService::ensureAdminEntryKey($content);

        if (file_put_contents($envPath, $content) === false)
            throw new \RuntimeException('.env 文件写入失败');

        $seedReport = $this->seedPackageEnv($envPath);
        if (!empty($seedReport['written'])) {
            $this->output->write('  <comment>  [env 播种] 已生成/写入 ' . count($seedReport['written']) . ' 个包级密钥：' . implode(', ', $seedReport['written']) . '</comment>');
        }
        foreach ($seedReport['warnings'] ?? [] as $w) {
            $this->output->write('  <error>  [env 播种] ' . $w . '</error>');
        }

        $env = $this->app->env;
        $env->load($envPath);

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
    }

    /**
     * 确保 .env 内容中存在指定键：存在则替换其值，不存在则追加到末尾。
     * 用于保证 APP_DEBUG / ALLOW_REINSTALL 等固定键在重装（.env 已存在但缺键）时也能正确落盘。
     */
    protected function ensureEnvKey(string &$content, string $key, string $value): void
    {
        if (preg_match("/^{$key}\s*=/m", $content)) {
            $content = preg_replace("/^{$key}\s*=\s*.*/m", "{$key} = {$value}", $content);
        } else {
            $content .= "\n{$key} = {$value}";
        }
    }

    /**
     * 调用 EnvSeedService，扫描 wise-* 包声明的 .env 契约并幂等合并进 .env
     *
     * @return array{written:string[],skipped:string[],warnings:string[]}
     */
    protected function seedPackageEnv(string $envPath): array
    {
        try {
            $seed = new \wise\support\env\EnvSeedService();
            return $seed->seed($envPath, App::getRootPath() . 'vendor');
        } catch (\Throwable $e) {
            return ['written' => [], 'skipped' => [], 'warnings' => ['env 播种异常: ' . $e->getMessage()]];
        }
    }

    /**
     * 将数据库相关配置规整为「紧随 APP_NAME 之后的规范块」，杜绝重装时
     * DB_PORT 因在已有 .env 中缺失而被追加到文件末尾（wise-* 包播种之后）。
     *
     * 做法：
     *   1. 剔除 content 中所有零散的 DB_* 行（含可能散落在文件尾部的 DB_PORT）；
     *   2. 在 APP_NAME 行之后重建规范顺序的 DB 块。
     * wise-* 包配置不在此处理，恒由 seedPackageEnv 幂等追加在文件末尾。
     *
     * @param string $content 引用传入/传出
     * @param array<string,string> $db 键 DB_HOST/DB_NAME/DB_USER/DB_PASS/DB_PORT/DB_PREFIX => 值
     */
    protected function normalizeDbBlock(string &$content, array $db): void
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

        $esc = fn(string $v): string => strtr($v, ['\\' => '\\\\', '$' => '\\$']);
        $block = "DB_TYPE = mysql\n"
            . "DB_HOST = "   . $esc((string) ($db['DB_HOST']   ?? '127.0.0.1')) . "\n"
            . "DB_NAME = "   . $esc((string) ($db['DB_NAME']   ?? '')) . "\n"
            . "DB_USER = "   . $esc((string) ($db['DB_USER']   ?? 'root')) . "\n"
            . "DB_PASS = "   . $esc((string) ($db['DB_PASS']   ?? '')) . "\n"
            . "DB_PORT = "   . $esc((string) ($db['DB_PORT']   ?? '3306')) . "\n"
            . "DB_CHARSET = utf8mb4\n"
            . "DB_PREFIX = " . $esc((string) ($db['DB_PREFIX'] ?? 'ws_')) . "\n";

        if (preg_match('/^APP_NAME\s*=/m', $content)) {
            $content = preg_replace('/^APP_NAME\s*=\s*.*/m', "APP_NAME = " . $esc((string) ($db['APP_NAME'] ?? 'WiseAdmin')) . "\n" . $block, $content, 1);
        } else {
            $content = rtrim($content) . "\n\nAPP_NAME = " . $esc((string) ($db['APP_NAME'] ?? 'WiseAdmin')) . "\n" . $block;
        }
    }

    protected function createDb(): void
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config['hostname'], (int)$this->config['hostport']);
            $pdo = new \PDO($dsn, $this->config['username'], $this->config['password'] ?? '');
            $pdo->exec("SET time_zone = '+8:00'");
            $dbName = str_replace('`', '``', $this->config['database']);
            $pdo->exec(sprintf(
                "CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                $dbName
            ));
        } catch (\PDOException $e) {
            throw new \RuntimeException('数据库创建失败：' . $e->getMessage());
        }
    }

    /**
     * 清空数据库（用于 --force 强制重新安装）
     *
     * 强制安装时，数据库中可能已存在旧版本的业务表，
     * 直接执行 migrate:run 会因为表已存在而失败。
     * 必须先删除所有匹配前缀的表，确保迁移从零开始。
     */
    protected function dropTables(): void
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['hostname'],
                (int)$this->config['hostport'],
                $this->config['database']
            );
            $pdo = new \PDO($dsn, $this->config['username'], $this->config['password'] ?? '');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $prefix = $this->config['prefix'];

            $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($tables)) {
                return;
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\PDOException $e) {
        }
    }

    /**
     * 执行数据库迁移（多级回退策略）
     *
     * 回退链（按标准性/可靠性从高到低）：
     *   1. shell 子进程执行 php think migrate:run（最标准，走完整 Phinx Manager）
     *   2. 程序化调用 Console::call('migrate:run')（进程内复用同一命令，不依赖 shell 函数）
     *   3. 直接执行迁移文件（手工模拟 Phinx Manager，最后兜底）
     *
     * 全部失败 → 抛出异常，提示使用网页版安装方式
     */
    protected function runMigrations(bool $force = false): void
    {
        $errors = [];

        $shellMethod = $this->tryRunMigrationsViaShell();
        if ($shellMethod !== false && $this->verifyAllMigrationsComplete()) {
            return;
        }
        $errors[] = 'shell 命令：exec/shell_exec/system/proc_open 不可用或迁移不完整';

        if ($this->tryRunMigrationsViaConsole()) {
            return;
        }
        $errors[] = '程序化调用：Console::call 失败或迁移不完整';

        $hint = implode("\n    ", $errors);
        throw new \RuntimeException(
            "数据库迁移失败，所有可用方式均已尝试。\n\n" .
            "  {$hint}\n\n" .
            "  建议使用网页版安装方式：\n" .
            "  1. 在浏览器中访问：http://您的域名/install\n" .
            "  2. 按照页面引导完成数据库配置和安装"
        );
    }

    /**
     * 通过 shell 命令执行迁移（多函数回退）
     * @return string|false 成功时返回使用的函数名，失败返回 false
     */
    protected function tryRunMigrationsViaShell()
    {
        $rootPath = App::getRootPath();
        $cmd = sprintf('"%s" "%sthink" migrate:run 2>&1', PHP_BINARY, $rootPath);

        $executors = [
            'exec' => function ($cmd) {
                if (!function_exists('exec')) return null;
                $out = [];
                $rc = 0;
                exec($cmd, $out, $rc);
                return ['rc' => $rc, 'out' => implode("\n", $out)];
            },
            'shell_exec' => function ($cmd) {
                if (!function_exists('shell_exec')) return null;
                $result = shell_exec($cmd);
                return ['rc' => ($result === null ? 1 : 0), 'out' => (string)$result];
            },
            'system' => function ($cmd) {
                if (!function_exists('system')) return null;
                ob_start();
                $rc = 0;
                system($cmd, $rc);
                $out = ob_get_clean();
                return ['rc' => $rc, 'out' => $out];
            },
            'proc_open' => function ($cmd) {
                if (!function_exists('proc_open')) return null;
                $descriptors = [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $process = proc_open($cmd, $descriptors, $pipes);
                if (!is_resource($process)) return null;
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $rc = proc_close($process);
                return ['rc' => $rc, 'out' => $stdout . $stderr];
            },
        ];

        foreach ($executors as $name => $fn) {
            $result = $fn($cmd);
            if ($result !== null && $result['rc'] === 0) {
                return $name;
            }
        }

        return false;
    }

    /**
     * 程序化调用迁移命令（通过 Console call）
     *
     * 作为第二级回退（shell 不可用时的替代）。无论其返回什么，最终都通过
     * 对比迁移文件版本号 vs 数据库迁移记录来判断完整性——
     * 所有文件的版本都必须出现在迁移记录表中才算完整执行。
     *
     * @return bool
     */
    protected function tryRunMigrationsViaConsole(): bool
    {
        try {
            $output = ConsoleFacade::call('migrate:run');

            $outputText = '';
            if ($output !== null) {
                if (method_exists($output, 'fetch')) {
                    $outputText = $output->fetch();
                } else {
                    $outputText = (string)$output;
                }
            }
            $outputText = trim($outputText);

            $this->output->writeln('');
            if ($outputText !== '') {
                $this->output->writeln('<comment>  [migrate:run 输出]</comment>');
                $lines = explode("\n", $outputText);
                foreach ($lines as $line) {
                    $this->output->writeln('  ' . $line);
                }
            } else {
                $this->output->writeln('<comment>  [migrate:run 无输出 — 迁移命令可能静默失败]</comment>');
            }

            $fatalPatterns = ['/Exception/i', '/SQLSTATE/i', '/Error\b/i'];
            foreach ($fatalPatterns as $pattern) {
                if (preg_match($pattern, $outputText)) {
                    $this->output->writeln('<error>  [migrate:run 输出包含错误]</error>');
                    return false;
                }
            }

            return $this->verifyAllMigrationsComplete();
        } catch (\Throwable $e) {
            $this->output->writeln('<error>  [Console::call 异常] ' . $e->getMessage() . '</error>');
            return false;
        }
    }

    /**
     * 验证所有迁移文件是否都已完整执行
     *
     * 对比磁盘上的迁移文件版本号 和 数据库中 wsmigrations 表的执行记录。
     * 只有当所有文件的版本都存在于迁移记录中时，才算完整迁移。
     * 这能准确检测出：未执行、部分执行（只成功了一部分文件）等异常情况。
     *
     * @return bool
     */
    protected function verifyAllMigrationsComplete(): bool
    {
        $expectedVersions = $this->getMigrationVersionsOnDisk();
        if (empty($expectedVersions)) {
            $this->output->write('<comment>  [迁移验证] 未找到任何迁移文件</comment>');
            return $this->verifyPluginMigrationsComplete();
        }

        $ranVersions = $this->getRanMigrationVersions();

        if (empty($ranVersions)) {
            $this->output->write('<error>  [迁移验证] 迁移记录表为空或不存在 — 迁移未执行</error>');
            return false;
        }

        $missing = array_diff($expectedVersions, $ranVersions);
        if (!empty($missing)) {
            $this->output->write('<error>  [迁移验证] 以下迁移文件未被执行: ' . implode(', ', $missing) . '</error>');
            $this->output->write('<comment>  [迁移验证] 预期 ' . count($expectedVersions) . ' 个，实际执行 ' . count($ranVersions) . ' 个</comment>');
            return false;
        }

        $this->output->write('<info>  [迁移验证] 全部 ' . count($expectedVersions) . ' 个迁移文件已完整执行</info>');

        return $this->verifyPluginMigrationsComplete();
    }

    /**
     * 扫描迁移目录，获取所有待执行的迁移文件版本号
     *
     * 迁移目录来源与迁移引擎 WiseMigrate::getPaths() 保持一致：
     * 从 config('migration.paths') 读取，包含框架核心 database/migrations 以及
     * 各 wise-* 包通过 RegistersMigrations 注册的 database/migrations 目录。
     *
     * @return string[] 版本号列表（如 ['20260624010001', '20260624010002']）
     */
    protected function getMigrationVersionsOnDisk(): array
    {
        $paths = \think\facade\Config::get('migration.paths', []);
        if (empty($paths)) {
            $paths = [App::getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'migrations'];
        }

        $versions = [];
        foreach ($paths as $path) {
            $dir = rtrim((string) $path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '*.php') ?: [] as $file) {
                $fileName = basename($file);
                if (Util::isValidMigrationFileName($fileName)) {
                    $versions[] = Util::getVersionFromFileName($fileName);
                }
            }
        }

        return array_values(array_unique($versions));
    }

    /**
     * 创建安装上下文使用的独立 PDO 连接。
     *
     * 安装流程此时尚未加载框架数据库配置，需按安装参数自行建连。
     * 建连失败（参数错误、库不可达等）返回 null，由调用方按「无数据」处理。
     *
     * @return \PDO|null
     */
    protected function createPdo(): ?\PDO
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['hostname'],
                (int)$this->config['hostport'],
                $this->config['database']
            );

            return new \PDO($dsn, $this->config['username'], $this->config['password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * 查询数据库 migrations 表，获取已执行的迁移版本号
     *
     * @return string[] 版本号列表
     */
    protected function getRanMigrationVersions(): array
    {
        try {
            $pdo = $this->createPdo();
            if ($pdo === null) {
                return [];
            }

            $prefix = $this->config['prefix'];

            $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}migrations'");
            if ($stmt === false || $stmt->rowCount() === 0) {
                return [];
            }

            return $pdo->query("SELECT version FROM `{$prefix}migrations` ORDER BY version ASC")->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * 校验插件迁移的完整性（独立表 {prefix}plugin_migrations）
     *
     * 设计前提：框架核心 + wise-* 包走系统表 {prefix}migrations，
     * 业务插件（plugins/*）走插件表 {prefix}plugin_migrations，两条链路的版本号
     * 互不干扰。所以安装期的完整性校验必须同时覆盖这两张表，否则插件迁移无人把关。
     *
     * 判定规则（以「插件表中已有记录」为基准，避免把从未安装过的插件误判为缺失）：
     *   1. 插件迁移表不存在 / 无任何记录 → 本环境尚未执行过插件迁移，跳过（不算失败）；
     *   2. 对表中出现过的每个插件，比对其磁盘迁移目录
     *      plugins/{plugin}/database/migrations：磁盘有、记录里没有 → 该插件迁移不完整。
     *
     * @return bool
     */
    protected function verifyPluginMigrationsComplete(): bool
    {
        $ranByPlugin = $this->getRanPluginMigrationVersions();

        if (empty($ranByPlugin)) {
            $this->output->write('<comment>  [插件迁移验证] 无插件迁移记录（本环境未执行过插件迁移），跳过</comment>');
            return true;
        }

        $complete = true;

        foreach ($ranByPlugin as $plugin => $ranVersions) {
            $expectedVersions = $this->getPluginMigrationVersionsOnDisk($plugin);
            if (empty($expectedVersions)) {
                continue;
            }

            $missing = array_diff($expectedVersions, array_map('strval', $ranVersions));
            if (!empty($missing)) {
                $this->output->write('<error>  [插件迁移验证] 插件 ' . $plugin . ' 有以下迁移未被执行: ' . implode(', ', $missing) . '</error>');
                $complete = false;
                continue;
            }

            $this->output->write('<info>  [插件迁移验证] 插件 ' . $plugin . ' 的 ' . count($expectedVersions) . ' 个迁移已完整执行</info>');
        }

        return $complete;
    }

    /**
     * 查询插件迁移记录表，按插件分组返回已执行的版本号
     *
     * 表不存在（全新站点尚未执行过任何插件迁移）或库不可达时返回空数组。
     *
     * @return array<string, string[]> plugin => [version, ...]
     */
    protected function getRanPluginMigrationVersions(): array
    {
        try {
            $pdo = $this->createPdo();
            if ($pdo === null) {
                return [];
            }

            $prefix = $this->config['prefix'];
            $table  = $prefix . 'plugin_migrations';

            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt === false || $stmt->rowCount() === 0) {
                return [];
            }

            $rows = $pdo->query("SELECT plugin, version FROM `{$table}` ORDER BY plugin ASC, version ASC")
                ->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $plugin  = (string) ($row['plugin'] ?? '');
            $version = (string) ($row['version'] ?? '');

            if ($plugin === '' || $version === '') {
                continue;
            }

            $result[$plugin][] = $version;
        }

        return $result;
    }

    /**
     * 扫描指定插件的迁移目录，返回磁盘上的迁移版本号
     *
     * 目录不存在（插件未下载 / 已被删除）时返回空数组。
     *
     * @param string $plugin 插件标识
     * @return string[] 版本号列表
     */
    protected function getPluginMigrationVersionsOnDisk(string $plugin): array
    {
        if ($plugin === '' || preg_match('/^[A-Za-z0-9_\-]+$/', $plugin) !== 1) {
            return [];
        }

        $dir = App::getRootPath() . 'plugins' . DIRECTORY_SEPARATOR . $plugin
            . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;

        if (!is_dir($dir)) {
            return [];
        }

        $versions = [];
        foreach (glob($dir . '*.php') ?: [] as $file) {
            $fileName = basename($file);
            if (Util::isValidMigrationFileName($fileName)) {
                $versions[] = (string) Util::getVersionFromFileName($fileName);
            }
        }

        return array_values(array_unique($versions));
    }

    /**
     * 导入框架级初始化数据（SQL 文件）
     *
     * 在迁移完成之后执行，此时 config 等表已就绪。
     * 导入失败不阻断安装：表结构已就绪，配置项缺失仅影响默认值。
     */
    protected function importInitData(): void
    {
        try {
            $result = \app\install\service\InitDataService::import([
                '__SITE_NAME__'   => $this->config['site_name'] ?? 'WiseAdmin',
                '__ADMIN_EMAIL__' => $this->config['admin_email'] ?? 'admin@example.com',
            ]);

            if ($result['success']) {
                $this->output->write('  <info>  [初始化数据] 导入 ' . $result['executed'] . ' 条 SQL</info>');
                return;
            }

            $this->output->write('  <comment>  [初始化数据] 导入 ' . $result['executed'] . ' 条，失败 ' . count($result['errors']) . ' 条</comment>');
            foreach ($result['errors'] as $error) {
                $this->output->write('  <error>  [初始化数据] ' . $error . '</error>');
            }
        } catch (\Throwable $e) {
            $this->output->write('  <error>  [初始化数据] 异常：' . $e->getMessage() . '</error>');
        }
    }

    /**
     * 生成权限数据（菜单派生 + 控制器注解派生）
     *
     * 在迁移与初始化数据之后执行，此时 menus / auth_permissions 表已就绪。
     * 失败不阻断安装：权限可在后台手动补齐。
     */
    protected function generatePermissions(): void
    {
        try {
            $result = \app\install\service\PermissionInitService::build();
            $this->output->write(sprintf(
                '  <info>  [权限生成] 菜单派生 %d 条，Attribute 派生 %d 条</info>',
                $result['menus'],
                $result['attributes']
            ));
        } catch (\Throwable $e) {
            $this->output->write('  <error>  [权限生成] 异常：' . $e->getMessage() . '</error>');
        }
    }

    protected function createAdmin(): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            $data = [
                'username' => $this->config['admin_user'],
                'password' => password_hash($this->config['admin_pwd'], PASSWORD_DEFAULT),
                'nickname' => '超级管理员',
                'email'    => $this->config['admin_email'],
                'status'   => 1,
                'create_time' => $now, 'update_time' => $now,
            ];
            $exist = Db::name('admins')->where('username', $this->config['admin_user'])->find();
            if ($exist) {
                Db::name('admins')->where('username', $this->config['admin_user'])->update([
                    'password' => $data['password'], 'email' => $data['email'],
                    'nickname' => $data['nickname'], 'status' => 1, 'update_time' => $now,
                ]);
            } else {
                Db::name('admins')->insert($data);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('管理员创建失败：' . $e->getMessage());
        }
    }

    protected function createLock(): void
    {
        $path = $this->getLockPath();
        $data = [
            'install_time' => date('Y-m-d H:i:s'),
            'version'      => config('install.system.version', '2.0.0'),
            'site_name'    => $this->config['site_name'],
            'admin_user'   => $this->config['admin_user'],
            'db_host'      => $this->config['hostname'],
            'db_name'      => $this->config['database'],
        ];
        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false)
            throw new \RuntimeException('安装锁文件创建失败');
        @chmod($path, 0644);
    }

    protected function getLockPath(): string
    {
        return App::getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
    }

    protected function getAdminUrl(): string 
    { 
        return '/admin/login'; 
    }
}
