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
        /** @var PluginManager $manager */
        $manager = $this->app->get(PluginContainerKeys::MANAGER);

        // 通过 PluginManager 获取插件列表（含正确的 enabled 状态）
        $plugins = $manager->getAllPlugins();

        if (empty($plugins)) {
            $output->comment('未发现插件。');
            return 0;
        }

        $output->info(sprintf('发现 %d 个插件：', count($plugins)));
        $output->writeln('');

        $validator = $this->memberProfileValidator();

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

            $this->reportMemberProfile($output, $metadata, $validator);
            $this->reportPackageDependencies($output, $metadata);
        }

        return 0;
    }

    /**
     * 校验并提示「会员声明」（manifest.json 的 member 段，P2-1）
     *
     * 语义校验器由 wise-member 提供（框架不反向依赖该包）：未安装时只提示
     * 「声明了但不会生效」，避免插件作者误以为显隐已受控。
     *
     * @param  object|null $validator MemberProfileValidator 实例（未安装时为 null）
     */
    protected function reportMemberProfile(Output $output, object $metadata, ?object $validator): void
    {
        if (!method_exists($metadata, 'getMemberProfile')) {
            return;
        }

        $profile = (array) $metadata->getMemberProfile();

        // 空声明（脚手架默认产物）不占输出
        if ($this->isEmptyMemberProfile($profile)) {
            return;
        }

        if ($validator === null) {
            $output->writeln('      <comment>· 声明了 member 段，但未安装 wise-member，该声明不会生效</comment>');

            return;
        }

        try {
            $problems = (array) $validator->validate($profile);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('      <error>· member 段校验异常：%s</error>', $e->getMessage()));

            return;
        }

        if ($problems === []) {
            $output->writeln('      <info>· member 段校验通过</info>');

            return;
        }

        foreach ($problems as $problem) {
            $output->writeln(sprintf('      <error>· member 段问题：%s</error>', $problem));
        }
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
            // 依赖判定不可用（如 installed.json 缺失且实现异常）时静默跳过，不影响扫描输出
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

    /**
     * 会员声明校验器（wise-member 未安装时返回 null）
     */
    protected function memberProfileValidator(): ?object
    {
        $class = 'wise\\member\\service\\MemberProfileValidator';

        try {
            if (class_exists($class)) {
                return $this->app->make($class);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * 空声明判定（三段皆空 = 未介入会员显隐）
     *
     * @param array<string, mixed> $profile
     */
    protected function isEmptyMemberProfile(array $profile): bool
    {
        foreach ($profile as $value) {
            if ($value !== [] && $value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
