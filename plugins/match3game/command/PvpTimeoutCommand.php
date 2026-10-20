<?php

declare(strict_types=1);

namespace plugin\match3game\command;

use plugin\match3game\service\social\FriendService;
use plugin\match3game\service\social\PvpService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 对战 24h 超时判负（05 §A8.1 第 3 条，P5-B05）
 *
 *   php think match3game:pvp-timeout
 *
 * 建议 crontab：每 10 分钟一次（`asterisk-slash-10` 表达式），24h 内一定收敛
 *
 * 规则：
 *   · `待接受` 且过期 → 标记已过期（**不扣分**：没人开局，不该惩罚发起者）
 *   · `进行中` 且过期 → 未提交方 `status=超时` + 积分 `-20`；
 *     若恰有一方已完成，则他获胜
 *
 * 顺带清理过期未领取的好友礼物（24h 失效）。
 */
class PvpTimeoutCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('match3game:pvp-timeout')
            ->setDescription('处理过期的消消乐对战（24h 未提交判负）并清理过期礼物')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '单次最多处理场次', 200);
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));

        $pvp  = new PvpService();
        $stat = $pvp->expireOverdue($limit);

        $output->writeln(sprintf(
            '<info>对战过期处理完成</info> 待接受失效 %d 场 / 进行中超时 %d 场 / 扣分 %d 人',
            $stat['pendingExpired'],
            $stat['playingExpired'],
            $stat['penalized']
        ));

        $gifts = (new FriendService())->expireStale($limit * 2);
        $output->writeln("<info>过期礼物标记</info> {$gifts} 条");

        return 0;
    }
}
