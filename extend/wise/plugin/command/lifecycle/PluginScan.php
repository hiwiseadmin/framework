<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;

/**
 * PluginScan 命令扫描可用插件并显示其状态。
 *
 * 用法：php think wise:plugin:scan
 */
class PluginScan extends Command
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('plugin:scan')
            ->setDescription('扫描可用插件')
            ->addOption('refresh', null, \think\console\input\Option::VALUE_NONE, '强制刷新插件扫描缓存后再扫描（缓存已能按清单指纹自愈，一般无需手动）');
    }

    /**
     * 执行命令
     *
     * @param Input  $input  控制台输入参数
     * @param Output $output 控制台输出
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        $manager = $this->app->get(PluginContainerKeys::MANAGER);

        $plugins = $manager->getAllPlugins();

        if (empty($plugins)) {
            $output->comment('未发现插件。');
            return 0;
        }

        $output->info(sprintf('发现 %d 个插件：', count($plugins)));
        $output->writeln('');

        foreach ($plugins as $name => $metadata) {
            $status = $metadata->isEnabled() ? '<info>已启用</info>' : '<comment>已禁用</comment>';
            $output->writeln(sprintf(
                '  %-20s %s  %s (v%s by %s)',
                $name,
                $status,
                $metadata->getDescription(),
                $metadata->getVersion(),
                $metadata->getAuthor()
            ));
            $this->reportPackageDependencies($output, $metadata);
        }

        return 0;
    }

    /**
     * 提示「库包依赖」（manifest.json 的 packages 段）
     *
     * 插件对 composer 库包（如 wiseadmin/wise-member）的耦合分强弱两档：
     * 硬依赖缺失会**阻止插件加载**，软依赖缺失只降级相关功能 —— 两者都必须明确输出，
     * 否则会出现"显示已启用、实际不加载"或"某几个页面莫名报错"的困惑。
     *
     * @param  Output      $output
     * @param  object|null $metadata 插件元数据（PluginMetadata）
     * @return void
     */
    protected function reportPackageDependencies(Output $output, ?object $metadata): void
    {
        if ($metadata === null || !method_exists($metadata, 'hasPackages') || !$metadata->hasPackages()) {
            return;
        }

        try {
            $manager = $this->app->get(PluginContainerKeys::MANAGER);

            $missing = method_exists($manager, 'packageDependencyReport')
                ? $manager->packageDependencyReport($metadata)
                : [];
        } catch (\Throwable $e) {
            return;
        }

        $total = count($metadata->getPackages());

        if ($missing === []) {
            $output->writeln(sprintf('      <info>· 库包依赖 %d 项均已安装</info>', $total));

            return;
        }

        foreach ($missing as $item) {
            $output->writeln(sprintf(
                '      %s· %s</>',
                $item['required'] ? '<error>' : '<comment>',
                (string) $item['message']
            ));
        }
    }

}
