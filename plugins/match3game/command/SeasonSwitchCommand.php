<?php

declare(strict_types=1);

namespace plugin\match3game\command;

use plugin\match3game\service\social\SeasonService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 赛季切换 / 结算（05 §A7.1，P5-B09）
 *
 *   php think match3game:season                # 结算所有已到期赛季，并开启当前期
 *   php think match3game:season --settle=S2026-01   # 手动结算指定赛季
 *   php think match3game:season --current      # 只看当前赛季（不结算）
 *
 * 赛季周期 2 周；建议 crontab 每 6 小时跑一次（幂等）。
 */
class SeasonSwitchCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('match3game:season')
            ->setDescription('消消乐赛季结算/切换（周期 2 周）')
            ->addOption('settle', null, Option::VALUE_OPTIONAL, '手动结算指定 seasonId', '')
            ->addOption('current', null, Option::VALUE_NONE, '只查看当前赛季');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new SeasonService();

        if ($input->getOption('current')) {
            $season = $service->ensureSeason();
            $output->writeln(sprintf(
                '<info>当前赛季</info> %s（%s → %s）状态=%d',
                $season['season_id'],
                (string) $season['start_at'],
                (string) $season['end_at'],
                (int) $season['status']
            ));

            return 0;
        }

        $result = $service->switchSeason((string) $input->getOption('settle'));

        if ($result['settled'] !== null) {
            $output->writeln("<info>已结算</info> {$result['settled']}");
        } else {
            $output->writeln('<comment>没有已到期的赛季需要结算</comment>');
        }
        $output->writeln("<info>当前赛季</info> {$result['opened']}");

        return 0;
    }
}
