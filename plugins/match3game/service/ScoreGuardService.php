<?php

declare(strict_types=1);

namespace plugin\match3game\service;

/**
 * 分数/收集量理论上限校验 + 风控（文档 §3.3 第 4/5/7 项）
 *
 * 客户端权威 + 规则校验：服务端不重放整局，但拦截「超物理可能」的作弊数据。
 * 所有阈值均可通过 config/match3.php validator 节热调。
 *
 * 纯逻辑、无副作用，便于单元测试。
 */
class ScoreGuardService
{
    /** @var array 校验配置（validator 节） */
    protected array $config;

    /** 单颗宝石基础分（计分公式基准，与客户端约定一致） */
    protected int $perGemScore;

    public function __construct(array $config = [], int $perGemScore = 100)
    {
        $this->config      = $config;
        $this->perGemScore = $perGemScore;
    }

    /**
     * 收集/清除量里的**汇总标量键**：本局合计值，不是"某元素收集了几个"
     *
     * 【为什么必须排除】重放引擎的 `goals` 形状里带一个 `score` 汇总键
     * （`verify-php-replay.php` 就在断言它；`verify-strict-rollout.php` 构造 claim 时还专门 `unset` 它），
     * 而 `SubmissionValidator` 会把引擎的 `goals` 原样放进 meta → `Match3Validator` 再拿它过收集量上限。
     * 于是**分数被当成收集个数**去比：2026-09-27 真机 `score=8676` 撞上个数上限 `4536`
     * （9x9 × 28 步 × 2.0）→ 被误判「收集量超出物理上限」（`10008`）。
     * 分数的上限归 `checkScore()` 管（口径是 `格数 × 步数 × 单颗分 × 放宽系数`）—— 量纲不同，不能混用。
     *
     * ⚠️ 关卡元素码不会叫这些名字（是 `red_gem` / `ice` 这类），所以按白名单跳过是安全的；
     *    `type: score` 的**目标判定**不受影响（那条路走 `$score`，见 `Match3Validator::decidePass`）。
     */
    protected const SCALAR_KEYS = ['score', 'moves', 'maxCombo', 'stars', 'passed', 'outcome'];

    // ================================================================
    //  理论上限计算
    // ================================================================

    /**
     * 单局某元素最大可收集数量（物理上限）
     *
     * 公式：棋盘格数 × 步数 × 单步最大消除系数。
     * 系数含义：单步最多消除的宝石数 / 棋盘格数（连锁掉落上限）。
     *
     * @param int $rows 棋盘行
     * @param int $cols 棋盘列
     * @param int $moves 有效步数（含道具加步）
     */
    public function maxCollectable(int $rows, int $cols, int $moves): int
    {
        $ratio = (float) ($this->config['max_eliminate_per_move_ratio'] ?? 2.0);
        $cells = max(1, $rows * $cols);

        return (int) ceil($cells * max(0, $moves) * max(0.0, $ratio));
    }

    /**
     * 单局最大得分（物理上限）
     *
     * 公式：棋盘格数 × 步数 × 单颗分 × 放宽系数。
     *
     * @param int $rows 棋盘行
     * @param int $cols 棋盘列
     * @param int $moves 有效步数（含道具加步）
     */
    public function maxScore(int $rows, int $cols, int $moves): int
    {
        $factor = (float) ($this->config['score_limit_factor'] ?? 1.2);
        $cells  = max(1, $rows * $cols);

        return (int) ceil($cells * max(0, $moves) * $this->perGemScore * max(1.0, $factor));
    }

    /**
     * 最短对局时长（毫秒）—— **按步数折算后的上限**
     *
     * 【为什么名字没改】后台设置页按 `min_duration_ms` 展示，改名会打断既有口径；
     * 它的语义从"恒定下限"变成"折算上限"（见 `requiredDurationMs()`）。
     */
    public function minDurationMs(): int
    {
        return (int) ($this->config['min_duration_ms'] ?? 30000);
    }

    /** 每步最短耗时（毫秒）：时长下限 = 步数 × 本值 */
    public function minDurationPerMoveMs(): int
    {
        return max(0, (int) ($this->config['min_duration_per_move_ms'] ?? 300));
    }

    /** 绝对下限（毫秒）：步数极少（1~2 步）时的兜底 */
    public function minDurationAbsMs(): int
    {
        return max(0, (int) ($this->config['min_duration_abs_ms'] ?? 1500));
    }

    /**
     * 这一局要求的最短时长（按**步数**折算）
     *
     * 【为什么不再用恒定的 30s】2026-09-27 真机：玩家正常游玩，因"消除后掉落引发的连锁自动消除"
     * 让对局 14709ms 就结束，被 30s 的固定下限判成秒结算（`10008`）——
     * 而**步数少的关卡本来就可能在十几秒内打完**，时长与作弊之间没有稳定的对应关系。
     * 改成 `步数 × 每步最短耗时`（上限仍取 `min_duration_ms`）后：
     *   · 8 步 → 下限 2.4s，14.7s 正常通过；
     *   · 而"0.2s 交 8 步"依旧被拦。
     */
    public function requiredDurationMs(int $movesUsed): int
    {
        $byMoves = max(0, $movesUsed) * $this->minDurationPerMoveMs();

        return min($this->minDurationMs(), max($this->minDurationAbsMs(), $byMoves));
    }

    // ================================================================
    //  校验入口
    // ================================================================

    /**
     * 校验收集量是否在物理上限内
     *
     * @param array  $collect 实际收集/清除量 {element => count}
     * @param int    $rows    棋盘行
     * @param int    $cols    棋盘列
     * @param int    $moves   有效步数
     * @return string|null 违规原因；null 表示通过
     */
    public function checkCollect(array $collect, int $rows, int $cols, int $moves): ?string
    {
        $max = $this->maxCollectable($rows, $cols, $moves);

        foreach ($collect as $element => $count) {
            // ① 汇总标量（`score` 等）不是"收集了几个"：跳过 —— 见 SCALAR_KEYS 的说明
            if (in_array((string) $element, self::SCALAR_KEYS, true)) {
                continue;
            }

            // ② 非数值不是计数（`null` / 布尔 / 数组）：同样跳过，交给结构校验去报
            if (!is_int($count) && !is_float($count) && !(is_string($count) && is_numeric($count))) {
                continue;
            }

            $count = (int) $count;
            if ($count < 0) {
                return "收集量不能为负: {$element}={$count}";
            }
            if ($count > $max) {
                return sprintf(
                    '收集量超出物理上限: %s=%d（上限 %d，棋盘 %dx%d，步数 %d）',
                    $element,
                    $count,
                    $max,
                    $rows,
                    $cols,
                    $moves
                );
            }
        }

        return null;
    }

    /**
     * 校验分数是否在物理上限内
     *
     * @param int $score 客户端结算总分
     * @param int $rows  棋盘行
     * @param int $cols  棋盘列
     * @param int $moves 有效步数
     * @return string|null 违规原因；null 表示通过
     */
    public function checkScore(int $score, int $rows, int $cols, int $moves): ?string
    {
        if ($score < 0) {
            return '分数不能为负';
        }

        $max = $this->maxScore($rows, $cols, $moves);
        if ($score > $max) {
            return sprintf(
                '分数超出物理上限: %d（上限 %d，棋盘 %dx%d，步数 %d）',
                $score,
                $max,
                $rows,
                $cols,
                $moves
            );
        }

        return null;
    }

    /**
     * 校验对局时长下限（防秒结算）
     *
     * @param int $durationMs      客户端上报时长（毫秒）
     * @param int $movesUsed       有效步数（下限按它折算）
     * @param int $serverElapsedMs 服务端自测时长（`battle/start` → 现在；0 = 无此信息）
     * @return string|null 违规原因；null 表示通过
     */
    public function checkDuration(int $durationMs, int $movesUsed = 0, int $serverElapsedMs = 0): ?string
    {
        if ($durationMs < 0 || $serverElapsedMs < 0) {
            return '对局时长不能为负';
        }

        // 取两者较大值：服务端时钟只会让判定**更宽松**。
        // 客户端上报的 duration_ms 可能偏小（续局只算本进程、切后台挂起、连锁自动消除速通），
        // 但这不该被判成作弊 —— 2026-09-27 真机那次就是 14709ms 被恒定 30s 误杀。
        $effective = max($durationMs, $serverElapsedMs);
        $required  = $this->requiredDurationMs($movesUsed);

        if ($effective < $required) {
            return sprintf(
                '对局时长过短（%dms < %dms，步数 %d），疑似秒结算',
                $effective,
                $required,
                $movesUsed
            );
        }

        return null;
    }

    // ================================================================
    //  步数上限
    // ================================================================

    /**
     * 校验步数是否超上限（含道具加步）
     *
     * @param int $movesUsed   实际步数
     * @param int $levelMoves  关卡步数上限
     * @param int $addedMoves  道具加步数
     * @return string|null 违规原因；null 表示通过
     */
    public function checkMoves(int $movesUsed, int $levelMoves, int $addedMoves = 0): ?string
    {
        if ($movesUsed < 0) {
            return '步数不能为负';
        }

        $limit = $levelMoves + max(0, $addedMoves);
        if ($movesUsed > $limit) {
            return sprintf('步数超限: %d（上限 %d + 加步 %d = %d）', $movesUsed, $levelMoves, $addedMoves, $limit);
        }

        return null;
    }
}
