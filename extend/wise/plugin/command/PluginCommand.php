<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
// | 插件命令抽象基类，所有插件迁移和Seed命令的公共父类
// +----------------------------------------------------------------------
namespace wise\plugin\command;

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\AdapterInterface;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

/**
 * 插件命令抽象基类
 *
 * 核心职责：
 * 1. 为所有插件命令提供 --plugin / -p 选项
 * 2. 重写 getAdapter() 返回 PluginMysqlAdapter（注入插件名）
 * 3. 提供插件迁移目录和Seed目录的路径构建方法
 */
abstract class PluginCommand extends \think\migration\Command
{
    /**
     * 当前操作的插件名称
     * @var string
     */
    protected $pluginName = '';

    /**
     * 插件数据库适配器
     * @var \Phinx\Db\Adapter\AdapterInterface|null
     */
    protected $adapter = null;

    /**
     * 配置命令选项
     * 添加 --plugin 必填选项
     */
    protected function configure()
    {
        // 不调用 parent::configure()，因为父类 \think\migration\Command 会注册全局迁移选项
        // （如 --environment），这些选项在插件上下文中由 PluginCommand 自行管理，不需要继承。

        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '插件名称（需要指定）');
    }

    /**
     * 初始化
     * 获取插件名称并验证插件目录存在
     *
     * @param Input  $input
     * @param Output $output
     */
    protected function initialize(Input $input, Output $output)
    {
        parent::initialize($input, $output);

        $this->pluginName = $input->getOption('plugin');

        if (empty($this->pluginName)) {
            throw new \InvalidArgumentException('请通过 --plugin 或 -p 指定插件名称');
        }
    }

    /**
     * 获取插件数据库适配器（重写父类）
     *
     * 创建流程：
     * 1. 调用父类 getDbConfig() 获取数据库连接配置
     * 2. 通过 Phinx AdapterFactory 创建标准 MysqlAdapter（建立PDO连接）
     * 3. 取回PDO连接，创建 PluginMysqlAdapter 并注入插件名称
     * 4. 处理表前缀包装
     *
     * @return AdapterInterface
     */
    public function getAdapter()
    {
        if (isset($this->adapter)) {
            return $this->adapter;
        }

        $options = $this->getDbConfig();
        $options['adapter'] = 'plugin_mysql';
        $options['migration_table'] = $options['table_prefix'] . 'plugin_migrations';

        AdapterFactory::instance()->registerAdapter($options['adapter'], 'wise\plugin\command\db\PluginMysqlAdapter');
        /** @var \wise\plugin\command\db\PluginMysqlAdapter $adapter */
        $adapter = AdapterFactory::instance()->getAdapter($options['adapter'], $options);
        $adapter->setPluginName($this->pluginName);
        $adapter->setSchemaTableName($options['migration_table']);
        if ($adapter->hasOption('table_prefix') || $adapter->hasOption('table_suffix')) {
            $adapter = AdapterFactory::instance()->getWrapper('prefix', $adapter);
        }
        $this->adapter = $adapter;
        
        return $this->adapter;
    }

    /**
     * 获取插件迁移文件目录路径
     *
     * @return string
     */
    protected function getPluginMigrationPath(): string
    {
        return plugin_path() . DIRECTORY_SEPARATOR . $this->pluginName . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * 获取插件Seed文件目录路径
     *
     * @return string
     */
    protected function getPluginSeedPath(): string
    {
        return plugin_path() . DIRECTORY_SEPARATOR . $this->pluginName . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds';
    }

    /**
     * 获取插件名称
     *
     * @return string
     */
    public function getPluginName(): string
    {
        return $this->pluginName;
    }
}
