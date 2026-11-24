<?php
declare(strict_types=1);

namespace wise\command\system;

use app\common\library\Version as AppVersion;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:version — 输出框架内部版本号
 *
 * 版本真源为应用根目录 `version.json`，读取逻辑见 {@see \app\common\library\Version}。
 *
 * 用法：
 *   php think wise:version            # 人读
 *   php think wise:version --json     # 机器读（部署脚本 / 监控 / 升级预检）
 *
 * 退出码：0 读到真实版本；1 未找到任何版本清单（已回落到 0.0.0）。
 * 之所以让「未知版本」返回非 0，是因为脚本拿它做升级前置校验时，
 * 一个假版本号比直接失败更危险。
 */
class Version extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:version')
            ->setDescription('显示 WiseAdmin 框架版本（版本号 / 通道 / 构建信息）')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出（供部署脚本 / 监控消费）')
            ->setHelp(<<<EOT
显示当前框架的内部版本号。

<info>php think wise:version</info>          人读输出
<info>php think wise:version --json</info>   JSON 输出（含 known / source，便于脚本判断）

版本来源：应用根目录 <info>version.json</info>；文件缺失时输出 <info>0.0.0</info> 并以退出码 1 告警。
EOT
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        $info = AppVersion::info();

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(
                $info + [
                    'php'      => PHP_VERSION,
                    'thinkphp' => $this->thinkVersion(),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return $info['known'] ? 0 : 1;
        }

        $output->writeln(sprintf('<info>WiseAdmin %s</info>', $info['product']));
        $output->writeln('  通道     ' . $info['channel']);
        $output->writeln('  提交     ' . ($info['commit'] !== '' ? $info['commit'] : '-'));
        $output->writeln('  构建时间 ' . ($info['builtAt'] !== '' ? $info['builtAt'] : '-'));
        $output->writeln('  版本来源 ' . ($info['source'] !== '' ? $info['source'] : '-'));
        $output->writeln(sprintf('  运行环境 PHP %s / ThinkPHP %s', PHP_VERSION, $this->thinkVersion()));

        if (!$info['known']) {
            $output->writeln('');
            $output->writeln('<error>未找到版本清单 ' . AppVersion::FILE_NAME . '，当前回落到 '
                . AppVersion::UNKNOWN . '</error>');
            $output->writeln('<comment>期望路径：' . $this->manifestPath() . '（发布包应自带）</comment>');
            $output->writeln('<comment>该场景退出码固定为 1（--json 亦同），请勿把 0.0.0 当作真实版本使用</comment>');

            return 1;
        }

        return 0;
    }

    /**
     * 版本清单期望路径（用于缺失时给出可定位提示）
     */
    private function manifestPath(): string
    {
        $root = function_exists('root_path')
            ? (string) root_path()
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . AppVersion::FILE_NAME;
    }

    /**
     * ThinkPHP 框架版本（vendor 缺失 InstalledVersions 时回落到 unknown）
     */
    private function thinkVersion(): string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return (string) \Composer\InstalledVersions::getPrettyVersion('topthink/framework');
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
