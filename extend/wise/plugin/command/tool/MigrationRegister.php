<?php

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;

class MigrationRegister extends Command
{
    /**
     * 迁移命令覆盖服务类名
     */
    protected const SERVICE_CLASS = 'wise\command\MigrationOverrideService';

    protected function configure()
    {
        $this->setName('plugin:migrate:register')
             ->setDescription('将 MigrationOverrideService 追加到 vendor/services.php 末尾（--check 只检测不写入）')
             ->addOption('check', null, InputOption::VALUE_NONE, '只检测是否已注册，不写入文件（CI 用）');
    }

    /**
     * 判断 vendor/services.php 是否已登记迁移命令覆盖服务。
     */
    public static function isRegistered(string $servicesFile): bool
    {
        if (!is_file($servicesFile)) {
            return false;
        }

        return str_contains((string) file_get_contents($servicesFile), self::SERVICE_CLASS);
    }

    protected function execute(Input $input, Output $output)
    {
        $servicesFile = $this->app->getRootPath() . 'vendor' . DIRECTORY_SEPARATOR . 'services.php';

        if ($input->getOption('check')) {
            if (!is_file($servicesFile)) {
                $output->writeln('<error>vendor/services.php 不存在，请先执行 composer install</error>');
                return 2;
            }

            if (self::isRegistered($servicesFile)) {
                $output->writeln('<info>已注册：多路径迁移命令可用（migrate:* 扫描 config/migration.php 的全部路径）</info>');
                return 0;
            }

            $output->writeln('<error>未注册：migrate:* 已退回 Phinx 单路径命令</error>');
            $output->writeln('<comment>后果：仅扫描 database/migrations，extend/wise/plugin 与各包 registerMigrations() 的路径会被跳过。</comment>');
            $output->writeln('<comment>修复：php think plugin:migrate:register</comment>');
            return 1;
        }

        if (!is_file($servicesFile)) {
            $output->writeln('<error>vendor/services.php 不存在，请先执行 composer install</error>');
            return 0;
        }

        $content = (string) file_get_contents($servicesFile);

        if (self::isRegistered($servicesFile)) {
            $output->writeln('<info>MigrationOverrideService 已存在于 vendor/services.php，无需操作</info>');
            return 0;
        }

        preg_match_all('/\s+(\d+)\s+=>/', $content, $matches);
        $lastKey = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
        $nextKey = $lastKey + 1;

        $pos = strrpos($content, ');');
        if ($pos === false) {
            $output->writeln('<error>vendor/services.php 格式异常，无法定位结尾</error>');
            return 0;
        }

        $entry = "\n  // WiseAdmin: 迁移命令覆盖（由 plugin:migrate:register 自动追加）\n"
            . "  {$nextKey} => '" . self::SERVICE_CLASS . "',\n";

        $content = substr($content, 0, $pos) . $entry . substr($content, $pos);

        if (file_put_contents($servicesFile, $content) !== false) {
            $output->writeln("<info>MigrationOverrideService 已追加到 vendor/services.php 末尾（键 {$nextKey}）</info>");
        } else {
            $output->writeln('<error>写入 vendor/services.php 失败</error>');
        }

        return 0;
    }
}
