<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\plugin\PluginContainerKeys;
use wise\plugin\config\PluginConfig;
use wise\plugin\PluginManager;

/**
 * 插件配置自检命令。
 *
 * 用法：
 *   php think plugin:config:check                    # 检查所有插件
 *   php think plugin:config:check --plugin=wisecms    # 只检查指定插件
 *   php think plugin:config:check --seed              # 对缺失的默认值立即播种
 *
 * 用途：
 *   - 验证 manifest 的 config 字段被正确归一化（兼容 map / list / default 三种写法）
 *   - 排查「后台改了配置但插件读不到」「配置页全是空值」类问题
 *   - 升级后确认新增配置项已自动补齐
 */
class PluginConfigCheck extends Command
{
    /**
     * 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('plugin:config:check')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '只检查指定插件（目录名）')
            ->addOption('seed', null, InputOption::VALUE_NONE, '对缺失的默认值执行播种')
            ->setDescription('检查插件配置 schema 归一化结果与默认值播种情况');
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

        /** @var PluginConfig $config */
        $config = $this->app->get(PluginContainerKeys::CONFIG);

        $only   = (string) $input->getOption('plugin');
        $doSeed = (bool) $input->getOption('seed');

        $plugins = $manager->getAllPlugins();

        if ($only !== '') {
            if (!isset($plugins[$only])) {
                $output->error("插件不存在：{$only}");
                return 1;
            }
            $plugins = [$only => $plugins[$only]];
        }

        if ($plugins === []) {
            $output->comment('未发现插件。');
            return 0;
        }

        foreach ($plugins as $name => $metadata) {
            $schema = $manager->getPluginConfigSchema((string) $name);

            if ($schema === []) {
                $output->writeln(sprintf('  <comment>%-16s</comment> 无配置声明', (string) $name));
                continue;
            }

            $output->writeln(sprintf(
                '  <info>%-16s</info> %d 个配置项（v%s）',
                (string) $name,
                count($schema),
                $metadata->getVersion()
            ));

            $seeded = [];
            if ($doSeed) {
                $seeded = $manager->seedDefaultConfig((string) $name, $schema);
            }

            // 已存储的原始值（用于区分「已存」与「走默认值」）
            $stored = $config->all((string) $name);

            foreach ($schema as $key => $field) {
                $exists = array_key_exists($key, $stored);
                $flag   = $exists ? '已存      ' : '<comment>默认</comment>      ';
                $mark   = in_array($key, $seeded, true) ? ' <info>[本次播种]</info>' : '';

                $value = $exists ? $stored[$key] : $field['value'];

                $output->writeln(sprintf(
                    '      %-22s %-9s %s %s%s',
                    (string) $key,
                    (string) $field['type'],
                    $flag,
                    $this->formatValue($value),
                    $mark
                ));
            }

            $output->writeln('');
        }

        return 0;
    }

    /**
     * 格式化配置值用于命令行展示。
     *
     * @param mixed $value 配置值
     * @return string
     */
    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '<comment>null</comment>';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '<error>[不可序列化]</error>' : $json;
    }
}
