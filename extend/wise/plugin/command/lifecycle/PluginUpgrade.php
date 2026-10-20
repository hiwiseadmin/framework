<?php

declare(strict_types=1);

namespace wise\plugin\command\lifecycle;

use think\console\input\Argument;
use think\console\input\Option;
use wise\plugin\exception\PluginInstallException;
use wise\plugin\PluginManager;

/**
 * PluginUpgrade 命令将插件升级到指定版本。
 *
 * 用法：
 *   php think plugin:upgrade <name> <version>
 *   php think plugin:upgrade <name> <version> --package-url=https://...
 *
 * 说明：
 *   - version 必须高于当前版本，否则升级会失败（PluginManager::upgrade 内部校验）。
 *   - 省略 --package-url 时假设新版本文件已就绪（本地替换），仅执行迁移/填充/菜单重注册。
 */
class PluginUpgrade extends PluginLifecycleCommand
{
    protected function configure(): void
    {
        $this->setName('plugin:upgrade')
            ->setDescription('升级插件')
            ->addArgument('name', Argument::REQUIRED, '插件名称')
            ->addArgument('version', Argument::REQUIRED, '目标版本号')
            ->addOption('package-url', null, Option::VALUE_OPTIONAL, '安装包下载地址（省略则假设文件已就绪）', '');
    }

    protected function getActionName(): string
    {
        return '升级';
    }

    protected function executeAction(PluginManager $manager, string $name): void
    {
        $version    = (string) $this->input->getArgument('version');
        $packageUrl = (string) $this->input->getOption('package-url');

        if ($version === '') {
            throw new PluginInstallException('目标版本号不能为空');
        }

        $metadata = $manager->upgrade($name, $version, $packageUrl);

        if ($this->output !== null) {
            $this->output->writeln(sprintf('  已升级到 v%s。', $metadata->getVersion()));
        }
    }
}
