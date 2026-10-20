<?php

declare(strict_types=1);

namespace plugin\match3game\command;

use plugin\match3game\model\Match3FriendHelp;
use plugin\match3game\model\Match3Run;
use plugin\match3game\model\Match3RunNode;
use plugin\match3game\service\rogue\Match3RunService;
use think\console\Command;
use think\console\input\Option;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * 历史数据清理（P6-B02 · 06 §二「写多读少」）
 *
 *   php think match3game:cleanup                       # 默认：90 天，含僵尸局关闭
 *   php think match3game:cleanup --days=30             # 保留 30 天
 *   php think match3game:cleanup --table=run_node      # 只清节点
 *   php think match3game:cleanup --dry-run             # 只统计不动数据（**建议首次先跑**）
 *   php think match3game:cleanup --grant-stone         # 关闭僵尸局时补发安慰灵石（产品决策，默认关）
 *
 * 建议 crontab：`0 4 * * *`（每天 04:00，业务低峰）
 *
 * 【清理对象】
 *   1. **僵尸局**（status=playing 且超期未结束）
 *      ⚠️ 这不是"清理"，是**修 bug**：`Match3RunService::startRun()` 会返回已有的进行中局，
 *      用户如果中途弃坑（不调 abandon），其 `match3_run` 会永远停在 playing，
 *      导致该用户**再也开不了新局**。本步骤把它们标记为已放弃，解除阻塞。
 *   2. `match3_run_node` —— 每局 15 行、含完整 final_schema，是**写多读少**的最大表
 *   3. `match3_friend_help` —— 好友助力流水（过期礼物 + 历史记录）
 *
 * 【安全设计】
 *   · `--days` 下限 30（防止手滑删近期数据）
 *   · 分批删除（默认 1000/批，最多 50 批），避免大事务长锁表
 *   · **只删已结束局的节点**（仍在进行中的局一个节点都不动）
 *   · 支持 `--dry-run`，首次运行强烈建议先跑一次看量级
 */
class CleanupCommand extends Command
{
    /** days 下限（安全护栏） */
    protected const MIN_DAYS = 30;

    protected function configure(): void
    {
        $this->setName('match3game:cleanup')
            ->setDescription('清理消消乐历史数据（90 天）+ 关闭僵尸局')
            ->addOption('days', null, Option::VALUE_OPTIONAL, '保留天数（<=' . self::MIN_DAYS . ' 会被抬高到 ' . self::MIN_DAYS . '）', 90)
            ->addOption('stale-run-days', null, Option::VALUE_OPTIONAL, '僵局判定天数（超过则视为弃坑）', 7)
            ->addOption('table', null, Option::VALUE_OPTIONAL, 'all | run_node | friend_help | stale_runs', 'all')
            ->addOption('batch', null, Option::VALUE_OPTIONAL, '每批删除条数', 1000)
            ->addOption('max-batches', null, Option::VALUE_OPTIONAL, '最多执行批次数（防御跑太久）', 50)
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计不删除')
            ->addOption('grant-stone', null, Option::VALUE_NONE, '关闭僵尸局时补发安慰灵石（默认不发）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $days     = max(self::MIN_DAYS, (int) $input->getOption('days'));
        $staleDays = max(1, (int) $input->getOption('stale-run-days'));
        $table    = (string) $input->getOption('table');
        $batch    = max(1, min(10000, (int) $input->getOption('batch')));
        $maxBatches = max(1, min(1000, (int) $input->getOption('max-batches')));
        $dryRun   = (bool) $input->getOption('dry-run');
        $grantStone = (bool) $input->getOption('grant-stone');

        $cutoff    = date('Y-m-d H:i:s', time() - $days * 86400);
        $cutoffDay = date('Y-m-d', time() - $days * 86400);
        $staleCut  = date('Y-m-d H:i:s', time() - $staleDays * 86400);

        $output->writeln(sprintf(
            '<info>清理开始</info> 保留 %d 天（cutoff %s）%s',
            $days,
            $cutoff,
            $dryRun ? '【DRY-RUN 只统计】' : ''
        ));

        $stat = [];

        // ---------- ① 僵尸局 ----------
        if (in_array($table, ['all', 'stale_runs'], true)) {
            $stat['staleRuns'] = $this->closeStaleRuns($output, $staleCut, $grantStone, $dryRun, $batch);
        }

        // ---------- ② match3_run_node ----------
        if (in_array($table, ['all', 'run_node'], true)) {
            $stat['runNodes'] = $this->cleanRunNodes($output, $cutoff, $batch, $maxBatches, $dryRun);
        }

        // ---------- ③ match3_friend_help ----------
        if (in_array($table, ['all', 'friend_help'], true)) {
            $stat['friendHelps'] = $this->cleanFriendHelps($output, $cutoffDay, $batch, $maxBatches, $dryRun);
        }

        $output->writeln(sprintf(
            '<info>清理完成</info> 僵尸局 %d / 节点 %d / 助力 %d',
            $stat['staleRuns'] ?? 0,
            $stat['runNodes'] ?? 0,
            $stat['friendHelps'] ?? 0
        ));

        ContextualLogger::info('[match3] 数据清理完成', array_merge($stat, [
            'days' => $days, 'dry_run' => $dryRun,
        ]));

        return 0;
    }

    /**
     * 关闭僵尸局（解除"永远开不了新局"的阻塞）
     *
     * @return int 关闭数量
     */
    protected function closeStaleRuns(Output $output, string $staleCut, bool $grantStone, bool $dryRun, int $batch): int
    {
        $closed = 0;

        $ids = Match3Run::where('status', Match3Run::STATUS_PLAYING)
            ->where('create_time', '<', $staleCut)
            ->limit($batch)
            ->column('id');

        if ($ids === [] || $ids === null) {
            $output->writeln('  僵尸局：0（无）');

            return 0;
        }

        $output->writeln('  僵尸局：发现 ' . count($ids) . ' 条');

        if ($dryRun) {
            return count($ids);
        }

        foreach ($ids as $id) {
            $row = Match3Run::find($id);
            if (!$row) {
                continue;
            }

            $data = [
                'status'      => Match3Run::STATUS_ABANDON,
                'finished_at' => date('Y-m-d H:i:s'),
            ];

            if ($grantStone) {
                // 安慰灵石：与 finishRun(cleared=false) 同口径
                $data['meta_reward'] = Match3RunService::calcMetaReward(
                    (int) $row->layer ?: 1,
                    (int) $row->score ?: 0,
                    false
                );
            }

            Match3Run::where('id', (int) $id)->update($data);
            $closed++;
        }

        $output->writeln('  僵尸局：已关闭 ' . $closed . ($grantStone ? '（已补发灵石）' : '（默认不发灵石，与手动放弃一致）'));

        return $closed;
    }

    /**
     * 清理节点：**只删已结束局的节点**
     *
     * @return int 删除数量
     */
    protected function cleanRunNodes(Output $output, string $cutoff, int $batch, int $maxBatches, bool $dryRun): int
    {
        // 仍在进行中的局（含刚被关闭前残留的），其节点一个都不动
        $playingRuns = Match3Run::where('status', Match3Run::STATUS_PLAYING)->column('run_id');
        $playingRuns = array_values(array_filter(array_map('strval', (array) $playingRuns)));

        $query = Match3RunNode::where('create_time', '<', $cutoff);
        if ($playingRuns !== []) {
            $query->whereNotIn('run_id', $playingRuns);
        }

        $total = (clone $query)->count();
        $output->writeln('  节点：命中 ' . $total . ' 条');

        if ($dryRun || $total === 0) {
            return $dryRun ? $total : 0;
        }

        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = (clone $query)->limit($batch)->column('id');
            if ($ids === []) {
                break;
            }

            Match3RunNode::whereIn('id', $ids)->delete();
            $deleted += count($ids);
        }

        $output->writeln('  节点：已删除 ' . $deleted);

        return $deleted;
    }

    /**
     * 清理好友助力流水（按 day 扫描，走 idx_day）
     *
     * @return int 删除数量
     */
    protected function cleanFriendHelps(Output $output, string $cutoffDay, int $batch, int $maxBatches, bool $dryRun): int
    {
        $query = Match3FriendHelp::where('day', '<', $cutoffDay);
        $total = (clone $query)->count();

        $output->writeln('  助力：命中 ' . $total . ' 条（day < ' . $cutoffDay . '）');

        if ($dryRun || $total === 0) {
            return $dryRun ? $total : 0;
        }

        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = (clone $query)->limit($batch)->column('id');
            if ($ids === []) {
                break;
            }

            Match3FriendHelp::whereIn('id', $ids)->delete();
            $deleted += count($ids);
        }

        $output->writeln('  助力：已删除 ' . $deleted);

        return $deleted;
    }
}
