<?php

declare(strict_types=1);

namespace plugin\match3game\command;

use plugin\match3game\service\rogue\DailyService;
use plugin\match3game\service\rogue\RelicService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 每日挑战生成 / 查询
 *
 *   php think match3game:daily                # 生成（或查看）今天的挑战
 *   php think match3game:daily --days=7       # 预生成未来 7 天（含今天）
 *   php think match3game:daily --date=2026-09-12
 *
 * 【说明】每日挑战是**懒生成**的（首个用户访问时创建），本命令只用于预热与排障：
 *   提前生成可以让运维在 00:00 前核对当日关卡是否可玩。
 */
class DailyChallengeCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('match3game:daily')
            ->setDescription('生成/查询消消乐每日挑战（全服一致）')
            ->addOption('days', null, Option::VALUE_OPTIONAL, '预生成天数（含今天）', 1)
            ->addOption('date', null, Option::VALUE_OPTIONAL, '指定日期 YYYY-MM-DD', '');
    }

    protected function execute(Input $input, Output $output): int
    {
        $service = new DailyService();
        $relics  = new RelicService();

        $date = (string) $input->getOption('date');
        $days = max(1, (int) $input->getOption('days'));

        $targets = [];
        if ($date !== '') {
            $targets[] = $date;
        } else {
            for ($i = 0; $i < $days; $i++) {
                $targets[] = date('Y-m-d', strtotime("+{$i} day"));
            }
        }

        foreach ($targets as $day) {
            try {
                $row = $service->ensure($day);
            } catch (\Throwable $e) {
                $output->writeln("<error>{$day} 生成失败: {$e->getMessage()}</error>");
                continue;
            }

            $pool = array_column($relics->byIds((array) $row['relic_pool']), 'name');

            $output->writeln(sprintf(
                '<info>%s</info>  mapSeed=%d  levelSeed=%d  layers=%d  灵石=%d',
                $row['day'],
                $row['map_seed'],
                $row['level_seed'],
                $row['layers'],
                $row['stone_reward']
            ));
            $output->writeln('         遗物序列: ' . ($pool !== [] ? implode('、', $pool) : '（空）'));
        }

        return 0;
    }
}
