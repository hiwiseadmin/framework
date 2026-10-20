<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;
use wise\plugin\exception\PluginException;

/**
 * 插件生命周期命令抽象基类
 *
 * 统一管理 install / enable / disable / uninstall 命令的公共逻辑：
 * - 插件名获取与空值校验
 * - PluginManager 获取
 * - PluginException / Throwable 两级异常捕获与友好输出
 *
 * 子类只需实现 configure()、getActionName()、executeAction() 三个方法即可。
 */
abstract class PluginLifecycleCommand extends Command
{
    /**
     * 获取操作名称（用于输出消息，如"安装""启用""禁用""卸载"）
     *
     * @return string
     */
    abstract protected function getActionName(): string;

    /**
     * 执行具体的插件操作
     *
     * @param PluginManager $manager 插件管理器
     * @param string        $name    插件名称
     * @return void
     * @throws PluginException|\Throwable
     */
    abstract protected function executeAction(PluginManager $manager, string $name): void;

    /**
     * 执行命令
     *
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        $name = $input->getArgument('name');

        if (empty($name)) {
            $output->error('请指定插件名称。');
            return 1;
        }

        /** @var PluginManager $manager */
        $manager = $this->app->get(PluginContainerKeys::MANAGER);

        $action = $this->getActionName();

        try {
            $this->executeAction($manager, $name);
            $output->info(sprintf('插件 "%s" 已成功%s。', $name, $action));
            return 0;
        } catch (PluginException $e) {
            $output->error(sprintf('%s插件 "%s" 失败：%s', $action, $name, $e->getMessage()));
            return 1;
        } catch (\Throwable $e) {
            $output->error(sprintf('%s插件 "%s" 时发生未预期错误：%s', $action, $name, $e->getMessage()));
            return 1;
        }
    }
}
