<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\plugin\composer_bridge\WisePluginInstaller;

/**
 * 插件桥接命令 — 将 Composer 安装的 wise-plugin 包同步到应用插件目录
 *
 * 用法：php think wise:plugin:bridge
 *
 * 该命令在 composer install/update 后通过 post-autoload-dump 脚本自动触发，
 * 也可手动执行。正式生产环境无 vendor/ 和 packages/ 目录时，命令直接跳过。
 */
class PluginBridge extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('plugin:bridge')
            ->setDescription('同步 Composer 安装的 wise-plugin 包到应用插件目录');
    }

    /**
     * 执行命令
     *
     * @param Input  $input  控制台输入
     * @param Output $output 控制台输出
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        $output->info('开始同步 wise-plugin 包...');

        $installer = new WisePluginInstaller();
        $result = $installer->bridge();

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                // 非致命错误用 comment 级别输出
                if (str_contains($error, '不存在') || str_contains($error, '跳过')) {
                    $output->comment('  ' . $error);
                } else {
                    $output->warning('  ' . $error);
                }
            }
        }

        if ($result['success'] > 0) {
            $output->info(sprintf('  成功同步 %d 个插件', $result['success']));
        }

        if ($result['success'] === 0 && empty($result['errors'])) {
            $output->comment('  未发现 wise-plugin 类型的包，无需同步');
        }

        $output->info('完成。');
        return 0;
    }
}
