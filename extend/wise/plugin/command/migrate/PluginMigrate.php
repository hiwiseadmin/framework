<?php
namespace wise\plugin\command\migrate;

use InvalidArgumentException;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\ProxyAdapter;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\MigrationInterface;
use Phinx\Util\Util;
use think\console\Input;
use think\console\Output;
use wise\plugin\command\PluginCommand;

/**
 * 插件迁移抽象基类
 *
 * 核心职责：
 * 1. getPath() 返回插件迁移文件目录
 * 2. getMigrations() 扫描插件目录加载迁移文件
 * 3. executeMigration() 执行单个迁移（含事务、CHANGE支持、ProxyAdapter）
 * 4. getVersions() / getVersionLog() 委托给 adapter（已按 plugin 过滤）
 *
 * 不继承 think\migration\command\Migrate 的原因：
 * 该类内部 getPath() 和 getMigrations() 写死了全局 database/migrations 路径，
 * 无法通过简单重写实现插件路径切换，因此选择继承 PluginCommand 自行实现。
 */
abstract class PluginMigrate extends PluginCommand
{
    /**
     * 已加载的迁移实例缓存
     * @var array|null
     */
    protected $migrations;

    /**
     * 获取插件迁移文件目录路径
     *
     * @return string
     */
    protected function getPath(): string
    {
        return $this->getPluginMigrationPath();
    }

    /**
     * 执行单个迁移
     *
     * 完整参考 think\migration\command\Migrate::executeMigration()
     * 支持：
     * - 事务处理（如果适配器支持）
     * - change() 方法的自动反转（通过 ProxyAdapter）
     * - up() / down() 方法的直接调用
     *
     * @param MigrationInterface $migration
     * @param string $direction
     * @return void
     */
    protected function executeMigration(MigrationInterface $migration, string $direction = MigrationInterface::UP)
    {
        $this->output->writeln('');
        $this->output->writeln(' ==' . ' <info>' . $migration->getVersion() . ' ' . $migration->getName() . ':</info>' . ' <comment>' . ($direction === MigrationInterface::UP ? 'migrating' : 'reverting') . '</comment>');

        $start     = microtime(true);
        $startTime = date('Y-m-d H:i:s');
        $direction = ($direction === MigrationInterface::UP) ? MigrationInterface::UP : MigrationInterface::DOWN;
        $migration->setAdapter($this->getAdapter());

        $adapter = $this->getAdapter();

        $inTransaction = $adapter->hasTransactions();
        if ($inTransaction) {
            $adapter->beginTransaction();
        }

        try {
            if (method_exists($migration, MigrationInterface::CHANGE)) {
                if ($direction === MigrationInterface::DOWN) {
                    $proxyAdapter = AdapterFactory::instance()->getWrapper('proxy', $adapter);
                    $migration->setAdapter($proxyAdapter);
                    $migration->change();
                    $proxyAdapter->executeInvertedCommands();
                    $migration->setAdapter($adapter);
                } else {
                    $migration->change();
                }
            } else {
                $migration->{$direction}();
            }

            if ($inTransaction) {
                $adapter->commitTransaction();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $adapter->rollbackTransaction();
            }
            throw $e;
        }

        $this->getAdapter()->migrated($migration, $direction, $startTime, date('Y-m-d H:i:s'));

        $end = microtime(true);

        $this->output->writeln(' ==' . ' <info>' . $migration->getVersion() . ' ' . $migration->getName() . ':</info>' . ' <comment>' . ($direction === MigrationInterface::UP ? 'migrated' : 'reverted') . ' ' . sprintf('%.4fs', $end - $start) . '</comment>');
    }

    /**
     * 获取已执行的版本号列表
     * 委托给 adapter（已按 plugin 过滤）
     *
     * @return array
     */
    protected function getVersions()
    {
        return $this->getAdapter()->getVersions();
    }

    /**
     * 获取版本日志
     * 委托给 adapter（已按 plugin 过滤）
     *
     * @return array
     */
    protected function getVersionLog()
    {
        return $this->getAdapter()->getVersionLog();
    }

    /**
     * 获取插件目录下所有迁移文件
     *
     * 完整参考 think\migration\command\Migrate::getMigrations()
     * 扫描插件迁移目录，加载并实例化迁移类
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getMigrations()
    {
        if (null === $this->migrations) {
            $phpFiles = glob($this->getPath() . DIRECTORY_SEPARATOR . '*.php', defined('GLOB_BRACE') ? GLOB_BRACE : 0);

            if ($phpFiles === false) {
                $phpFiles = [];
            }

            $fileNames = [];
            $versions = [];

            foreach ($phpFiles as $filePath) {
                if (Util::isValidMigrationFileName(basename($filePath))) {
                    $version = Util::getVersionFromFileName(basename($filePath));

                    if (isset($versions[$version])) {
                        throw new InvalidArgumentException(sprintf('Duplicate migration - "%s" has the same version as "%s"', $filePath, $versions[$version]->getVersion()));
                    }

                    $class = Util::mapFileNameToClassName(basename($filePath));

                    if (isset($fileNames[$class])) {
                        throw new InvalidArgumentException(sprintf('Migration "%s" has the same name as "%s"', basename($filePath), $fileNames[$class]));
                    }

                    $fileNames[$class] = basename($filePath);

                    require_once $filePath;
                    if (!class_exists($class)) {
                        throw new InvalidArgumentException(sprintf('Could not find class "%s" in file "%s"', $class, $filePath));
                    }

                    $migration = new $class('default', $version, $this->input, $this->output);

                    if (!($migration instanceof AbstractMigration)) {
                        throw new InvalidArgumentException(sprintf('The class "%s" in file "%s" must extend Phinx\Migration\AbstractMigration', $class, $filePath));
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
     * 获取当前最新的已执行版本号
     *
     * @return int|string
     */
    protected function getCurrentVersion()
    {
        $versions = $this->getVersions();
        $version  = 0;

        if (!empty($versions)) {
            $version = max($versions);
        }

        return $version;
    }
}
