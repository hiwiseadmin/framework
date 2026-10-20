<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
namespace wise\plugin\command\seed;

use InvalidArgumentException;
use RuntimeException;
use Phinx\Util\Util;
use think\console\Input;
use think\console\input\Argument as InputArgument;
use think\console\Output;
use wise\plugin\command\PluginCommand;

/**
 * 创建插件Seed文件命令
 *
 * 用法：
 *   php think plugin:seed:create UserData -p demo
 *   php think plugin:seed:create InitConfig -p shop
 */
class Create extends PluginCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:seed:create')
             ->setDescription('创建插件数据填充文件')
             ->addArgument('name', InputArgument::REQUIRED, 'Seed名称（驼峰命名，如 UserData）')
             ->setHelp(sprintf(
                 '%s创建插件数据填充文件，文件生成到 plugins/{插件名}/database/seeds/ 目录%s',
                 PHP_EOL,
                 PHP_EOL
             ));
    }

    /**
     * 获取Seed文件路径
     * 重写默认行为，返回插件Seed目录
     *
     * @return string
     */
    protected function getPath(): string
    {
        return $this->getPluginSeedPath();
    }

    /**
     * 执行创建Seed文件
     *
     * @param Input  $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $path = $this->getPath();

        if (!is_dir($path)) {
            if ($this->output->confirm($this->input, 'Seed目录不存在，是否创建？[y]/n')) {
                mkdir($path, 0755, true);
            } else {
                $output->writeln('<error>已取消，Seed目录不存在</error>');
                return;
            }
        }

        $this->verifyMigrationDirectory($path);

        $path      = realpath($path);
        $className = $input->getArgument('name');

        if (!Util::isValidPhinxClassName($className)) {
            throw new InvalidArgumentException(sprintf('The seed class name "%s" is invalid. Please use CamelCase format', $className));
        }

        // 生成文件名
        $filePath = $path . DIRECTORY_SEPARATOR . $className . '.php';

        if (is_file($filePath)) {
            throw new InvalidArgumentException(sprintf('The file "%s" already exists', basename($filePath)));
        }

        // 从模板生成文件内容
        $templatePath = $this->getTemplate();
        if (!file_exists($templatePath)) {
            // 回退到内置默认模板，确保命令始终可用
            $contents = $this->getDefaultTemplate($className);
        } else {
            $contents = file_get_contents($templatePath);
        }
        $contents = strtr($contents, [
            '$className' => $className,
        ]);

        if (false === file_put_contents($filePath, $contents)) {
            throw new RuntimeException(sprintf('The file "%s" could not be written to', $filePath));
        }

        $output->writeln('<info>created</info> .' . str_replace(getcwd(), '', $filePath));
    }

    /**
     * 获取Seed文件模板路径
     *
     * @return string
     */
    protected function getTemplate(): string
    {
        return __DIR__ . '/../../stubs/seed.stub';
    }

    /**
     * 获取内置默认模板内容（当模板文件不存在时的回退方案）
     *
     * @param string $className Seed 类名
     * @return string
     */
    protected function getDefaultTemplate(string $className): string
    {
        return <<<EOT
<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class {$className} extends AbstractSeed
{
    /**
     * 执行数据填充
     */
    public function run(): void
    {
        \$data = [
            // 填充数据
        ];

        \$this->table('table_name')->insert(\$data)->save();
    }
}

EOT;
    }
}
