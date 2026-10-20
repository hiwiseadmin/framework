<?php
// +----------------------------------------------------------------------
// | WiseAdmin - 多路径迁移抽象基类（框架级命令）
// +----------------------------------------------------------------------
// | 替代 think\migration\command\Migrate，支持从多个目录扫描迁移文件。
// | 所有迁移子命令（Run / Rollback / Breakpoint / Status）继承此类，
// | 自动获得多路径扫描能力。
// +----------------------------------------------------------------------

namespace wise\command\migrate;

use InvalidArgumentException;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\MigrationInterface;
use Phinx\Util\Util;
use think\migration\Command;

abstract class WiseMigrate extends Command
{
    /**
     * 已加载的迁移实例缓存
     * @var array|null
     */
    protected $migrations;

    /**
     * 获取所有迁移目录路径
     * 从 config('migration.paths') 读取，过滤不存在/不可读的目录
     *
     * @return array<string>
     */
    protected function getPaths(): array
    {
        $paths = $this->app->config->get('migration.paths', []);

        if (empty($paths)) {
            // 兜底：无配置时使用默认路径
            return [$this->app->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'migrations'];
        }

        return array_values(array_filter($paths, function ($path) {
            return is_dir($path) && is_readable($path);
        }));
    }

    /**
     * 多路径扫描：遍历所有路径，加载并合并迁移文件
     *
     * 完全兼容 think\migration\command\Migrate::getMigrations() 的行为，
     * 仅将单路径 glob 改为多路径遍历。
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getMigrations()
    {
        if (null === $this->migrations) {
            $fileNames = [];
            $versions  = [];

            foreach ($this->getPaths() as $path) {
                $phpFiles = glob($path . DIRECTORY_SEPARATOR . '*.php', defined('GLOB_BRACE') ? GLOB_BRACE : 0);

                foreach ($phpFiles as $filePath) {
                    if (!Util::isValidMigrationFileName(basename($filePath))) {
                        continue;
                    }

                    $version = Util::getVersionFromFileName(basename($filePath));

                    if (isset($versions[$version])) {
                        throw new InvalidArgumentException(
                            sprintf('Duplicate migration version "%s" found in "%s" and "%s"',
                                $version,
                                $versions[$version]->getVersion(),
                                $filePath
                            )
                        );
                    }

                    $class = Util::mapFileNameToClassName(basename($filePath));

                    if (isset($fileNames[$class])) {
                        // 多路径扫描时，类名冲突通常是因为迁移文件从包复制到了
                        // database/migrations/。跳过后续路径中的同名文件，保留首次出现的版本。
                        $this->output->writeln(
                            "<comment>  [SKIP] 重复类名 {$class}：" .
                            basename($filePath) . "（已在 {$fileNames[$class]} 中加载）</comment>"
                        );
                        continue;
                    }

                    $fileNames[$class] = basename($filePath);

                    require_once $filePath;

                    if (!class_exists($class)) {
                        throw new InvalidArgumentException(
                            sprintf('Could not find class "%s" in file "%s"', $class, $filePath)
                        );
                    }

                    $migration = new $class('default', $version, $this->input, $this->output);

                    if (!($migration instanceof AbstractMigration)) {
                        throw new InvalidArgumentException(
                            sprintf('The class "%s" in file "%s" must extend \Phinx\Migration\AbstractMigration',
                                $class, $filePath)
                        );
                    }

                    $versions[$version] = $migration;
                }
            }

            ksort($versions);
            $this->migrations = $versions;
        }

        return $this->migrations;
    }

    /**
     * 执行单个迁移
     *
     * 完整复制自 think\migration\command\Migrate::executeMigration()
     */
    protected function executeMigration(MigrationInterface $migration, $direction = MigrationInterface::UP)
    {
        $this->output->writeln('');
        $this->output->writeln(
            ' ==' .
            ' <info>' . $migration->getVersion() . ' ' . $migration->getName() . ':</info>' .
            ' <comment>' . ($direction === MigrationInterface::UP ? 'migrating' : 'reverting') . '</comment>'
        );

        $start     = microtime(true);
        $startTime = date('Y-m-d H:i:s');
        $direction = ($direction === MigrationInterface::UP) ? MigrationInterface::UP : MigrationInterface::DOWN;
        $migration->setMigratingUp($direction === MigrationInterface::UP);
        $migration->setAdapter($this->getAdapter());

        $migration->preFlightCheck();

        if (method_exists($migration, MigrationInterface::INIT)) {
            $migration->{MigrationInterface::INIT}();
        }

        if ($this->getAdapter()->hasTransactions()) {
            $this->getAdapter()->beginTransaction();
        }

        if (method_exists($migration, MigrationInterface::CHANGE)) {
            if (MigrationInterface::DOWN === $direction) {
                /** @var \Phinx\Db\Adapter\ProxyAdapter $proxyAdapter */
                $proxyAdapter = AdapterFactory::instance()->getWrapper('proxy', $this->getAdapter());
                $migration->setAdapter($proxyAdapter);
                $migration->{MigrationInterface::CHANGE}();
                $proxyAdapter->executeInvertedCommands();
                $migration->setAdapter($this->getAdapter());
            } else {
                $migration->{MigrationInterface::CHANGE}();
            }
        } else {
            $migration->{$direction}();
        }

        if ($this->getAdapter()->hasTransactions()) {
            $this->getAdapter()->commitTransaction();
        }

        $migration->postFlightCheck();

        $this->getAdapter()->migrated($migration, $direction, $startTime, date('Y-m-d H:i:s'));

        $end = microtime(true);

        $this->output->writeln(
            ' ==' .
            ' <info>' . $migration->getVersion() . ' ' . $migration->getName() . ':</info>' .
            ' <comment>' . ($direction === MigrationInterface::UP ? 'migrated' : 'reverted') .
            ' ' . sprintf('%.4fs', $end - $start) . '</comment>'
        );
    }

    /**
     * 获取已执行的版本日志
     */
    protected function getVersionLog()
    {
        return $this->getAdapter()->getVersionLog();
    }

    /**
     * 获取已执行的版本号列表
     */
    protected function getVersions()
    {
        return $this->getAdapter()->getVersions();
    }

    /**
     * 验证迁移目录
     */
    protected function verifyMigrationDirectory(string $path)
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException(sprintf('Migration directory "%s" does not exist', $path));
        }

        if (!is_writable($path)) {
            throw new InvalidArgumentException(sprintf('Migration directory "%s" is not writable', $path));
        }
    }
}
