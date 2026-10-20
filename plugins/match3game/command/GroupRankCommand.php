<?php

declare(strict_types=1);

namespace plugin\match3game\command;

use plugin\match3game\model\Match3GroupBattle;
use plugin\match3game\service\social\GroupBattleService;
use plugin\match3game\service\social\SocialRules;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 群擂台排名重算（05 §A7.3，P5-B10）
 *
 *   php think match3game:group-rank                  # 重算本期（+ 上一期做收尾）
 *   php think match3game:group-rank --period=2026-W37
 *   php think match3game:group-rank --all            # 重算库里所有周期（排障用）
 *
 * 建议 crontab：`0 2 * * *`（每日 02:00，05 §A7.3 明确要求）
 *
 * 重算内容：每群 Top5 累加 → 群总分 → 群间排名（1..N）+ 推 Redis 榜。
 */
class GroupRankCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('match3game:group-rank')
            ->setDescription('重算消消乐群擂台排名（每日 02:00）')
            ->addOption('period', null, Option::VALUE_OPTIONAL, '周期 2026-W37（默认本期）', '')
            ->addOption('all', null, Option::VALUE_NONE, '重算所有周期');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new GroupBattleService();

        $periods = [];
        if ($input->getOption('all')) {
            $periods = Match3GroupBattle::distinct(true)->column('period_id');
        } else {
            $period = (string) $input->getOption('period');
            $periods = [$period !== '' ? $period : SocialRules::periodId()];
        }

        foreach ($periods as $periodId) {
            // 先逐群重算 Top5 总分，再算群间排名
            $groups = Match3GroupBattle::where('period_id', $periodId)->column('group_id');
            foreach ($groups as $groupId) {
                $service->recalcGroupScore((string) $groupId, (string) $periodId);
            }

            $count = $service->recalcRanks((string) $periodId);
            $output->writeln("<info>{$periodId}</info> 重算完成，共 {$count} 个群");
        }

        return 0;
    }
}
