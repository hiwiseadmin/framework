<?php
namespace wise\plugin\command\db;

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\MigrationInterface;
use Phinx\Db\Table;
use Phinx\Db\Table\Column;

/**
 * 插件数据库迁移适配器
 *
 * 核心改动：
 * 1. 迁移记录表从 phinxlog 改为 plugin_migrations
 * 2. 新增 plugin 字段，与 version 组成联合主键
 * 3. 所有查询/写入操作都按 plugin 字段过滤
 */
class PluginMysqlAdapter extends MysqlAdapter implements AdapterInterface
{
    /**
     * 当前操作的插件名称
     * @var string
     */
    protected $pluginName = '';

    /**
     * 插件迁移记录表名
     * @var string
     */
    protected $schemaTableName = 'plugin_migrations';

    /**
     * 设置插件名称
     * @param string $pluginName
     * @return $this
     */
    public function setPluginName(string $pluginName): self
    {
        $this->pluginName = $pluginName;
        return $this;
    }

    /**
     * 获取插件名称
     * @return string
     */
    public function getPluginName(): string
    {
        return $this->pluginName;
    }

    /**
     * 确保 pluginName 已设置，未设置时抛出异常
     *
     * @return void
     * @throws \RuntimeException
     */
    protected function requirePluginName(): void
    {
        if (empty($this->pluginName)) {
            throw new \RuntimeException('PluginMysqlAdapter: pluginName must be set before performing this operation.');
        }
    }

    /**
     * 安全转义字符串值用于 SQL 拼接
     *
     * 使用 PDO::quote() 确保值被正确转义，防止 SQL 注入。
     * 命名区别于父类 quoteValue()，后者用于列值类型转换。
     *
     * @param string $value 需要转义的值
     * @return string
     */
    protected function quoteString(string $value): string
    {
        return $this->getConnection()->quote($value);
    }

    /**
     * 返回插件迁移记录表名
     * 重写父类，使用独立的 plugin_migrations 表
     * @return string
     */
    /**
     * 创建插件迁移记录表
     * 重写父类，新增 plugin 字段，使用 version+plugin 联合主键
     * @throws \InvalidArgumentException
     * @return void
     */
    public function createSchemaTable(): void
    {
        $options = [
            'id'          => false,
            'primary_key' => ['version', 'plugin'],
            'engine'      => 'InnoDB',
            'collation'   => 'utf8mb4_unicode_ci',
            'comment'     => '插件迁移记录表',
        ];

        $table = new Table($this->getSchemaTableName(), $options, $this);

        $table->addColumn('version', 'biginteger', ['null' => false, 'comment' => '迁移版本号'])
              ->addColumn('plugin', 'string', ['limit' => 100, 'null' => false, 'default' => '', 'comment' => '插件名称'])
              ->addColumn('migration_name', 'string', ['limit' => 100, 'default' => null, 'null' => true, 'comment' => '迁移类名'])
              ->addColumn('start_time', 'datetime', ['default' => null, 'null' => true, 'comment' => '开始时间'])
              ->addColumn('end_time', 'datetime', ['default' => null, 'null' => true, 'comment' => '结束时间'])
              ->addColumn('breakpoint', 'boolean', ['default' => false, 'null' => false, 'comment' => '断点标记'])
              ->addIndex(['plugin'], ['name' => 'idx_plugin'])
              ->save();
    }

    /**
     * 记录迁移执行
     * 重写父类，UP方向插入时包含 plugin 字段，DOWN方向删除时按 plugin 过滤
     *
     * @param MigrationInterface $migration 迁移实例
     * @param mixed $direction 方向（up/down）
     * @param mixed $startTime 开始时间
     * @param mixed $endTime 结束时间
     * @return AdapterInterface
     */
    public function migrated(MigrationInterface $migration, $direction, $startTime, $endTime): AdapterInterface
    {
        $this->requirePluginName();

        if (strcasecmp($direction, MigrationInterface::UP) === 0) {
            $sql = sprintf(
                "INSERT INTO %s (%s, %s, %s, %s, %s, %s) VALUES (%s, %s, %s, %s, %s, %s);",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('version'),
                $this->quoteColumnName('plugin'),
                $this->quoteColumnName('migration_name'),
                $this->quoteColumnName('start_time'),
                $this->quoteColumnName('end_time'),
                $this->quoteColumnName('breakpoint'),
                $this->quoteString((string) $migration->getVersion()),
                $this->quoteString($this->pluginName),
                $this->quoteString(substr($migration->getName(), 0, 100)),
                $this->quoteString($startTime),
                $this->quoteString($endTime),
                $this->castToBool(false)
            );

            $this->execute($sql);
        } else {
            $sql = sprintf(
                "DELETE FROM %s WHERE %s = %s AND %s = %s",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('version'),
                $this->quoteString((string) $migration->getVersion()),
                $this->quoteColumnName('plugin'),
                $this->quoteString($this->pluginName)
            );

            $this->execute($sql);
        }

        return $this;
    }

    /**
     * 获取迁移版本日志
     * 重写父类，按 plugin 过滤，仅返回当前插件的迁移记录
     *
     * @return array
     */
    public function getVersionLog(): array
    {
        $this->requirePluginName();

        $result = [];

        $orderBy = 'version ASC';
        if (isset($this->options['version_order'])) {
            switch ($this->options['version_order']) {
                case \Phinx\Config\Config::VERSION_ORDER_CREATION_TIME:
                    $orderBy = 'version ASC';
                    break;
                case \Phinx\Config\Config::VERSION_ORDER_EXECUTION_TIME:
                    $orderBy = 'start_time ASC, version ASC';
                    break;
            }
        }

        try {
            $sql = sprintf(
                "SELECT * FROM %s WHERE %s = %s ORDER BY %s",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('plugin'),
                $this->quoteString($this->pluginName),
                $orderBy
            );
            $rows = $this->fetchAll($sql);
        } catch (\PDOException $e) {
            if (!$this->isDryRunEnabled()) {
                throw $e;
            }
            $rows = [];
        }

        foreach ($rows as $version) {
            $result[(int)$version['version']] = $version;
        }

        return $result;
    }

    /**
     * 获取已执行的版本号列表
     * 重写父类，按 plugin 过滤
     *
     * @return array
     */
    public function getVersions(): array
    {
        return array_keys($this->getVersionLog());
    }

    /**
     * 切换迁移断点状态
     * 重写父类，按 plugin + version 定位记录
     *
     * @param MigrationInterface $migration
     * @return AdapterInterface
     */
    public function toggleBreakpoint(MigrationInterface $migration): AdapterInterface
    {
        $this->requirePluginName();

        $this->query(
            sprintf(
                "UPDATE %1\$s SET %2\$s = CASE %2\$s WHEN %3\$s THEN %4\$s ELSE %3\$s END, %7\$s = %7\$s WHERE %5\$s = %6\$s AND %8\$s = %9\$s;",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('breakpoint'),
                $this->castToBool(true),
                $this->castToBool(false),
                $this->quoteColumnName('version'),
                $this->quoteString((string) $migration->getVersion()),
                $this->quoteColumnName('start_time'),
                $this->quoteColumnName('plugin'),
                $this->quoteString($this->pluginName)
            )
        );

        return $this;
    }

    /**
     * 清除当前插件的所有断点
     * 重写父类，仅清除当前插件的断点
     *
     * @return int
     */
    public function resetAllBreakpoints(): int
    {
        $this->requirePluginName();

        return $this->execute(
            sprintf(
                "UPDATE %1\$s SET %2\$s = %3\$s, %4\$s = %4\$s WHERE %2\$s <> %3\$s AND %5\$s = %6\$s;",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('breakpoint'),
                $this->castToBool(false),
                $this->quoteColumnName('start_time'),
                $this->quoteColumnName('plugin'),
                $this->quoteString($this->pluginName)
            )
        );
    }

    /**
     * 设置断点
     * 重写父类，按 plugin + version 定位
     *
     * @param MigrationInterface $migration
     * @return AdapterInterface
     */
    public function setBreakpoint(MigrationInterface $migration): AdapterInterface
    {
        $this->requirePluginName();

        $this->query(
            sprintf(
                "UPDATE %s SET %s = %s WHERE %s = %s AND %s = %s;",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('breakpoint'),
                $this->castToBool(true),
                $this->quoteColumnName('version'),
                $this->quoteString((string) $migration->getVersion()),
                $this->quoteColumnName('plugin'),
                $this->quoteString($this->pluginName)
            )
        );

        return $this;
    }

    /**
     * 取消断点
     * 重写父类，按 plugin + version 定位
     *
     * @param MigrationInterface $migration
     * @return AdapterInterface
     */
    public function unsetBreakpoint(MigrationInterface $migration): AdapterInterface
    {
        $this->requirePluginName();

        $this->query(
            sprintf(
                "UPDATE %s SET %s = %s WHERE %s = %s AND %s = %s;",
                $this->quoteTableName($this->getSchemaTableName()),
                $this->quoteColumnName('breakpoint'),
                $this->castToBool(false),
                $this->quoteColumnName('version'),
                $this->quoteString((string) $migration->getVersion()),
                $this->quoteColumnName('plugin'),
                $this->quoteString($this->pluginName)
            )
        );

        return $this;
    }
}
