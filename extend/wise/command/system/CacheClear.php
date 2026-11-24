<?php
declare(strict_types=1);

namespace wise\command\system;

use app\common\service\CacheClearService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:cache:clear —— 清理运行时缓存
 *
 * 与后台「顶部导航 → 清理缓存」共用 CacheClearService，便于写进部署脚本 /
 * 容器入口 / CI，避免为了清缓存去走会话与权限。
 *
 * 用法：
 *   php think wise:cache:clear                        # 一键清理（模板+插件+配置+资源版本号）
 *   php think wise:cache:clear -t template            # 只清视图编译缓存
 *   php think wise:cache:clear -t template,config     # 多目标
 *   php think wise:cache:clear -t module              # 只把前端资源版本号 +1（部署后强制刷新前端）
 *   php think wise:cache:clear -t log                 # 只清超期运行日志（默认保留 7 天）
 *
 * 退出码：0 全部成功；2 有文件清理失败；1 参数/运行异常。
 */
class CacheClear extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:cache:clear')
            ->setDescription('清理运行时缓存（模板/插件/配置/资源版本号/日志）')
            ->addOption(
                'target',
                't',
                Option::VALUE_OPTIONAL,
                '清理目标，逗号分隔：template,plugin,config,module,log，或 all',
                'all'
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        $target = (string) $input->getOption('target');

        try {
            $result = (new CacheClearService())->clear($target);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        } catch (\Throwable $e) {
            $output->writeln('<error>缓存清理失败：' . $e->getMessage() . '</error>');
            return 1;
        }

        foreach ($result['targets'] as $item) {
            $output->writeln(sprintf(
                '  %-9s %-6s 删除 %d 个，失败 %d 个',
                $item['type'],
                $item['label'],
                $item['deleted'],
                count($item['failed'])
            ));

            foreach (array_slice($item['failed'], 0, 5) as $path) {
                $output->writeln('    <comment>失败：' . $path . '</comment>');
            }
        }

        if ($result['failed'] === []) {
            $output->writeln(sprintf('<info>完成：共删除 %d 个文件，耗时 %dms</info>', $result['deleted'], $result['took_ms']));
            return 0;
        }

        $output->writeln(sprintf(
            '<error>完成但有失败：成功 %d 个，失败 %d 个，请检查 runtime 目录权限</error>',
            $result['deleted'],
            count($result['failed'])
        ));
        return 2;
    }
}
