<?php
namespace wise\command\migrate;

use InvalidArgumentException;
use think\console\Input;
use think\console\input\Argument as InputArgument;
use think\console\input\Option as InputOption;
use think\console\Output;

/**
 * WiseMigrateCreate — 多路径迁移文件创建命令
 *
 * 在指定的迁移路径中创建新的迁移文件。
 * 默认使用第一个注册的迁移路径，可通过 --path 指定目标路径。
 *
 * 用法：
 *   php think migrate:create MyMigration
 *   php think migrate:create MyMigration --path=/path/to/plugin/database/migrations
 *   php think migrate:create MyMigration --path-index=2
 */
class WiseMigrateCreate extends WiseMigrate
{
    protected function configure()
    {
        $this->setName('migrate:create')
            ->setDescription('创建新的数据库迁移文件（多路径支持）')
            ->addArgument('name', InputArgument::REQUIRED, '迁移文件名')
            ->addOption('--path', '-p', InputOption::VALUE_REQUIRED, '目标迁移路径（绝对路径）')
            ->addOption('--path-index', '-i', InputOption::VALUE_REQUIRED, '目标迁移路径索引（从1开始）')
            ->setHelp(<<<EOT
<info>php think migrate:create MyMigration</info>
<info>php think migrate:create MyMigration --path=/path/to/migrations</info>
<info>php think migrate:create MyMigration --path-index=2</info>
EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $paths = $this->getPaths();

        if (empty($paths)) {
            throw new InvalidArgumentException(
                '没有可用的迁移路径，请检查 config/migration.php 的 paths 配置'
            );
        }

        $targetPath = $this->resolveTargetPath($input, $paths);
        $this->verifyMigrationDirectory($targetPath);

        $creator = $this->app->get('migration.creator');

        $className = $input->getArgument('name');

        $originalPaths = $this->app->config->get('migration.paths', []);
        $this->app->config->set(['migration.paths' => [$targetPath]], 'migration');

        try {
            $filePath = $creator->create($className);
            $output->writeln('<info>created</info> .' . str_replace(getcwd(), '', realpath($filePath)));
            $output->writeln('  <comment>path: ' . $targetPath . '</comment>');
        } finally {
            $this->app->config->set(['migration.paths' => $originalPaths], 'migration');
        }
    }

    /**
     * 解析目标路径
     *
     * @param Input $input
     * @param array $paths 注册的迁移路径列表
     * @return string
     */
    private function resolveTargetPath(Input $input, array $paths): string
    {
        $customPath = $input->getOption('path');
        if ($customPath) {
            if (!in_array($customPath, $paths, true)) {
                throw new InvalidArgumentException(
                    sprintf('指定路径 "%s" 未在 migration.paths 中注册', $customPath)
                );
            }
            return $customPath;
        }

        $pathIndex = $input->getOption('path-index');
        if ($pathIndex !== null) {
            $index = (int) $pathIndex - 1;
            if (!isset($paths[$index])) {
                throw new InvalidArgumentException(
                    sprintf('路径索引 %d 无效（可用范围: 1-%d）', (int) $pathIndex, count($paths))
                );
            }
            return $paths[$index];
        }

        return $paths[0];
    }
}
