<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
namespace wise\plugin\command\seed;

use InvalidArgumentException;
use Phinx\Seed\AbstractSeed;
use Phinx\Seed\SeedInterface;
use Phinx\Util\Util;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\plugin\command\PluginCommand;

/**
 * 执行插件Seed命令
 *
 * 用法：
 *   php think plugin:seed:run -p demo
 *   php think plugin:seed:run -p demo -s UserData
 */
class Run extends PluginCommand
{
    /**
     * Seed实例缓存
     * @var array|null
     */
    protected $seeds;

    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:seed:run')
             ->setDescription('执行插件数据填充')
             ->addOption('--seed', '-s', InputOption::VALUE_REQUIRED, '指定要执行的Seed类名')
             ->setHelp(<<<EOT
The <info>plugin:seed:run</info> 命令执行插件的所有或指定数据填充

<info>php think plugin:seed:run -p demo</info>
<info>php think plugin:seed:run -p demo -s UserData</info>
EOT
             );
    }

    /**
     * 获取Seed文件路径
     *
     * @return string
     */
    protected function getPath(): string
    {
        return $this->getPluginSeedPath();
    }

    /**
     * 执行Seed
     *
     * @param Input  $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $seed = $input->getOption('seed');

        $output->writeln('<info>== 插件 [' . $this->getPluginName() . '] 数据填充开始 ==</info>');

        $start = microtime(true);

        $this->seed($seed);

        $end = microtime(true);

        $output->writeln('');
        $output->writeln('<info>== 插件 [' . $this->getPluginName() . '] 数据填充完成. 耗时 ' . sprintf('%.4fs', $end - $start) . ' ==</info>');
    }

    /**
     * 执行Seed填充
     *
     * @param string|null $seed
     * @return void
     */
    protected function seed($seed = null)
    {
        $seeds = $this->getSeeds();

        if (null === $seed) {
            // 执行所有Seeder（按字母排序）
            foreach ($seeds as $seeder) {
                $this->executeSeed($seeder);
            }
        } else {
            // 执行指定Seeder
            if (array_key_exists($seed, $seeds)) {
                $this->executeSeed($seeds[$seed]);
            } else {
                throw new InvalidArgumentException(sprintf('The seed class "%s" does not exist', $seed));
            }
        }
    }

    /**
     * 执行单个Seed
     *
     * @param SeedInterface $seed
     * @return void
     */
    protected function executeSeed(SeedInterface $seed)
    {
        $this->output->writeln('');
        $this->output->writeln(' ==' . ' <info>' . $seed->getName() . ':</info>' . ' <comment>seeding</comment>');

        $start = microtime(true);

        $seed->setAdapter($this->getAdapter());

        $adapter = $this->getAdapter();
        $inTransaction = $adapter->hasTransactions();
        if ($inTransaction) {
            $adapter->beginTransaction();
        }

        try {
            if (method_exists($seed, SeedInterface::RUN)) {
                $seed->run();
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

        $end = microtime(true);

        $this->output->writeln(' ==' . ' <info>' . $seed->getName() . ':</info>' . ' <comment>seeded' . ' ' . sprintf('%.4fs', $end - $start) . '</comment>');
    }

    /**
     * 获取插件目录下所有Seed文件
     *
     * 参考 think\migration\command\Seed::getSeeds()
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getSeeds()
    {
        if (null === $this->seeds) {
            $phpFiles = glob($this->getPath() . DIRECTORY_SEPARATOR . '*.php', defined('GLOB_BRACE') ? GLOB_BRACE : 0);

            if ($phpFiles === false) {
                $phpFiles = [];
            }

            /** @var AbstractSeed[] $seeds */
            $seeds = [];
            $fileNames = [];

            foreach ($phpFiles as $filePath) {
                if (Util::isValidSeedFileName(basename($filePath))) {
                    $class             = pathinfo($filePath, PATHINFO_FILENAME);
                    $fileNames[$class] = basename($filePath);

                    require_once $filePath;
                    if (!class_exists($class)) {
                        throw new InvalidArgumentException(sprintf('Could not find class "%s" in file "%s"', $class, $filePath));
                    }

                    $seed = new $class($this->input, $this->output);

                    if (!($seed instanceof AbstractSeed)) {
                        throw new InvalidArgumentException(sprintf('The class "%s" in file "%s" must extend Phinx\Seed\AbstractSeed', $class, $filePath));
                    }

                    $seeds[$class] = $seed;
                }
            }

            ksort($seeds);
            $this->seeds = $seeds;
        }

        return $this->seeds;
    }
}
