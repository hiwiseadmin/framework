<?php
namespace plugin\wisecms\service\engine;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\content\ContentService;

use plugin\wisecms\model\Schedule;
use plugin\wisecms\model\Content;
use think\facade\Cache;
use think\facade\Db;

class SchedulerService
{
    /**
     * 获取排期日历数据
     */
    public function getCalendar(int $siteId, string $start, string $end): array
    {
        // 时间字段统一：scheduled_time 已是 DATETIME，改用字符串边界比较；
        // 纯日期入参补齐到当天末刻，避免漏掉末日的排期（原 int 实现同样有此问题）
        $startAt = cms_datetime($start) ?? date('Y-m-01 00:00:00');
        $endAt   = cms_datetime($end) ?? date('Y-m-t 23:59:59');
        if (strlen(trim($end)) <= 10) {
            $endAt = substr($endAt, 0, 10) . ' 23:59:59';
        }

        $schedules = Schedule::where('scheduled_time', '>=', $startAt)
            ->where('scheduled_time', '<=', $endAt)
            ->where('status', 'pending')
            ->with(['content'])
            ->order('scheduled_time', 'asc')
            ->select();

        $events = [];
        foreach ($schedules as $s) {
            $events[] = [
                'id' => $s->id,
                'content_id' => $s->content_id,
                'title' => $s->content ? $s->content->title : '未知内容',
                'start' => date('Y-m-d H:i', cms_timestamp($s->scheduled_time)),
                'status' => $s->status,
                'catid' => $s->content->catid ?? 0,
            ];
        }
        return $events;
    }

    /**
     * 批量排期
     *
     * @param array $contentIds 内容 ID 列表
     * @param mixed $time       排期时间：DATETIME 字符串（含 datetime-local）或 unix 时间戳
     */
    public function batchSchedule(array $contentIds, $time): int
    {
        $scheduledAt = cms_datetime($time);
        if ($scheduledAt === null) {
            throw new WisecmsException('排期时间无效', ErrorCode::VALIDATE_FAILED);
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $contentIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }

        $count = 0;

        Db::startTrans();
        try {
            foreach ($ids as $id) {
                // P1-C：同一内容**只保留一条 pending 排期** —— 复用既有行，
                // 而不是「置 cancelled + 新建」。原实现在并发/重复提交下会积累多条
                // pending，cron 扫描时同一内容被重复触发发布流程；且整批无事务。
                $schedule = Schedule::where('content_id', $id)->where('status', 'pending')->find();

                if ($schedule) {
                    $schedule->scheduled_time = $scheduledAt;
                    $schedule->remark         = '';
                    $schedule->save();
                } else {
                    Schedule::create([
                        'content_id'     => $id,
                        'scheduled_time' => $scheduledAt,
                        'status'         => 'pending',
                    ]);
                }

                $count++;
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $count;
    }

    /**
     * 移动排期
     *
     * @param mixed $newTime 同 batchSchedule() 的 $time
     */
    public function moveSchedule(int $scheduleId, $newTime): Schedule
    {
        $schedule = Schedule::find($scheduleId);
        if (!$schedule) throw new WisecmsException('排期不存在', ErrorCode::NOT_FOUND);

        $scheduledAt = cms_datetime($newTime);
        if ($scheduledAt === null) {
            throw new WisecmsException('排期时间无效', ErrorCode::VALIDATE_FAILED);
        }

        $schedule->scheduled_time = $scheduledAt;
        $schedule->save();
        return $schedule;
    }

    /**
     * 取消排期
     */
    public function cancelSchedule(int $contentId): bool
    {
        return Schedule::where('content_id', $contentId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']) > 0;
    }

    /**
     * 执行排期发布（由 crontab 触发）
     *
     * P1-B5 修复要点：
     *   1. **可发布状态由「仅草稿」放宽为「草稿 + 待审核」**。开启「先审后发」后，
     *      新建内容一律是 `STATUS_PENDING`，旧实现只处理 `STATUS_DRAFT`，
     *      导致该模式下排期发布**从未生效**且无任何提示。
     *   2. 状态变更统一走 `ContentService::updateStatus()`（状态机唯一收口），
     *      因此「先审后发」下对草稿的越权发布会被拒绝并如实记录。
     *   3. 幂等收敛：内容已发布（人工提前发布）时只收敛排期状态，不再重复发布；
     *      归档等终态则标记失败并写明原因，避免每次 cron 反复重试。
     *
     * 事务说明：**刻意不把整批包进一个事务** —— `updateStatus()` 内会触发同步 Webhook
     * 投递，包在长事务里会长时间持锁。改为依赖上述幂等收敛：即使「内容已发布但排期
     * 未落库」的极端窗口出现，下一轮也会被正确收敛。
     *
     * @return array{published:int, ids:int[], failed:int, skipped:int}
     */
    public function executeScheduledPublish(): array
    {
        // 时间字段统一：scheduled_time / actual_time 均为 DATETIME，比较与写入都用字符串
        $nowAt = cms_now();
        $schedules = Schedule::where('scheduled_time', '<=', $nowAt)
            ->where('status', 'pending')
            ->select();

        $contentService = new ContentService();
        $published = [];
        $failed    = 0;
        $skipped   = 0;

        foreach ($schedules as $s) {
            try {
                $content = Content::find($s->content_id);
                if (!$content) {
                    $s->status = 'failed';
                    $s->remark = '内容不存在';
                    $s->save();
                    $failed++;
                    continue;
                }

                $status = (int) $content->status;

                // 已发布：只收敛排期状态（可能由人工提前发布），避免每轮重复扫描
                if ($status === ContentService::STATUS_PUBLISHED) {
                    $s->status      = 'published';
                    $s->actual_time = $nowAt;
                    $s->remark      = '内容已发布，排期自动收敛';
                    $s->save();
                    $published[] = (int) $s->content_id;
                    continue;
                }

                // 归档等终态：不再自动发布
                if (!in_array($status, [ContentService::STATUS_DRAFT, ContentService::STATUS_PENDING], true)) {
                    $s->status = 'failed';
                    $s->remark = '内容当前状态不允许排期发布（status=' . $status . '）';
                    $s->save();
                    $failed++;
                    cms_log('warning', '排期发布跳过：状态不允许', [
                        'content_id' => $s->content_id,
                        'status'     => $status,
                    ]);
                    continue;
                }

                // 统一走状态机：含「先审后发」校验与状态变更快照
                $contentService->updateStatus((int) $s->content_id, ContentService::STATUS_PUBLISHED);

                $s->status      = 'published';
                $s->actual_time = $nowAt;
                $s->remark      = '';
                $s->save();
                $published[] = (int) $s->content_id;
            } catch (WisecmsException $e) {
                // 业务拒绝（典型：先审后发模式下草稿不可直接发布）——如实记录，不再静默
                $s->status = 'failed';
                $s->remark = $e->getMessage();
                $s->save();
                $failed++;
                cms_log('warning', '排期发布被状态机拒绝: ' . $e->getMessage(), ['content_id' => $s->content_id]);
            } catch (\Throwable $e) {
                $s->status = 'failed';
                $s->remark = '发布异常，详见系统日志';
                $s->save();
                $failed++;
                cms_log('error', '排期发布异常: ' . $e->getMessage(), [
                    'content_id' => $s->content_id,
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine(),
                ]);
            }
        }

        return ['published' => count($published), 'ids' => $published, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * 检测排期冲突
     */
    public function detectConflicts(int $catId, int $timestamp, int $windowMinutes = 30): array
    {
        // 以 int 时间戳做窗口算术，再转为 DATETIME 字符串查询
        $ts    = $timestamp > 0 ? $timestamp : time();
        $start = date('Y-m-d H:i:s', $ts - ($windowMinutes * 60));
        $end   = date('Y-m-d H:i:s', $ts + ($windowMinutes * 60));

        $conflicts = Schedule::where('scheduled_time', '>=', $start)
            ->where('scheduled_time', '<=', $end)
            ->where('status', 'pending')
            ->with(['content'])
            ->select()->toArray();

        return array_filter($conflicts, fn($s) => ($s['content']['catid'] ?? 0) == $catId);
    }

    /**
     * 推荐最佳发布时间
     */
    public function suggestTime(int $catId): array
    {
        // 基于历史发布时段统计；无历史数据时回退到运营经验时段
        $hours = $this->popularPublishHours($catId);
        if (empty($hours)) {
            $hours = [9, 10, 11, 14, 15, 16, 20, 21];
        }
        $today = strtotime(date('Y-m-d'));
        $suggestions = [];
        foreach ($hours as $hour) {
            $ts = $today + $hour * 3600;
            $conflicts = $this->detectConflicts($catId, $ts, 60);
            $suggestions[] = [
                'time' => date('Y-m-d H:i', $ts),
                'timestamp' => $ts,
                'conflict_count' => count($conflicts),
                'available' => count($conflicts) == 0,
            ];
        }
        return $suggestions;
    }

    /**
     * 统计历史发布最集中的时段（按小时，最多 $limit 个）
     *
     * 直接交给数据库聚合，避免把内容表全量拉到内存。
     *
     * @return int[] 0-23 的小时列表（升序）；无数据时返回空数组
     */
    protected function popularPublishHours(int $catId, int $limit = 8): array
    {
        $rows = Content::where('status', ContentService::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->when($catId > 0, static fn($q) => $q->where('catid', $catId))
            // create_time 为 DATETIME，可直接取 HOUR 聚合
            ->field('HOUR(create_time) AS h, COUNT(*) AS c')
            ->group('h')
            ->order('c', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $hours = [];
        foreach ($rows as $row) {
            $hour = (int) ($row['h'] ?? -1);
            if ($hour >= 0 && $hour <= 23) {
                $hours[] = $hour;
            }
        }

        sort($hours);

        return $hours;
    }
}
