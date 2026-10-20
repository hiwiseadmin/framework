<?php
// +----------------------------------------------------------------------
// | 插件数据库迁移工具
// +----------------------------------------------------------------------
namespace wise\plugin\command\migrate;

use InvalidArgumentException;
use RuntimeException;
use Phinx\Util\Util;
use think\console\Input;
use think\console\input\Argument as InputArgument;
use think\console\Output;

/**
 * 创建插件迁移文件命令
 *
 * 用法：
 *   php think plugin:migrate:create CreateUserTable -p demo
 *   php think plugin:migrate:create AddEmailToUsers -p shop
 */
class Create extends PluginMigrate
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugin:migrate:create')
             ->setDescription('创建插件数据库迁移文件')
             ->addArgument('name', InputArgument::REQUIRED, '迁移名称（驼峰命名，如 CreateUserTable）')
             ->setHelp(sprintf(
                 '%s创建插件数据库迁移文件，文件生成到 plugins/{插件名}/database/migrations/ 目录%s',
                 PHP_EOL,
                 PHP_EOL
             ));
    }

    /**
     * 执行创建迁移文件
     *
     * @param Input  $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $path = $this->getPath();

        // 如果目录不存在，提示用户确认创建
        if (!is_dir($path)) {
            if ($this->output->confirm($this->input, '迁移目录不存在，是否创建？[y]/n')) {
                mkdir($path, 0755, true);
            } else {
                $output->writeln('<error>已取消，迁移目录不存在</error>');
                return;
            }
        }

        $this->verifyMigrationDirectory($path);

        $path      = realpath($path);
        $className = $input->getArgument('name');

        if (!Util::isValidPhinxClassName($className)) {
            throw new InvalidArgumentException(sprintf('The migration class name "%s" is invalid. Please use CamelCase format.', $className));
        }

        if (!Util::isUniqueMigrationClassName($className, $path)) {
            throw new InvalidArgumentException(sprintf('The migration class name "%s" already exists', $className));
        }

        // 生成文件名（时间戳格式：20240101120000_CreateUserTable.php）
        $fileName = Util::mapClassNameToFileName($className);
        $filePath = $path . DIRECTORY_SEPARATOR . $fileName;

        if (is_file($filePath)) {
            throw new InvalidArgumentException(sprintf('The file "%s" already exists', $filePath));
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
     * 获取迁移文件模板路径
     *
     * @return string
     */
    protected function getTemplate(): string
    {
        return __DIR__ . '/../../stubs/migrate.stub';
    }

    /**
     * 获取内置默认模板内容（当模板文件不存在时的回退方案）
     *
     * 默认使用 up/down 方式生成迁移模板。
     *
     * @param string $className 迁移类名
     * @return string
     */
    protected function getDefaultTemplate(string $className): string
    {
        return <<<EOT
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class $className extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up(): void
    {

    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {

    }
}
EOT;
    }
}
