<?php
/**
 * 重放引擎接口（L3 校验）
 *
 * 【为什么用接口】
 *   完整重放 = 实现一整套三消引擎（消除/掉落/连锁/特殊元素/行为），
 *   在 PHP 里重写一遍风险高、维护贵（02 §10.3 已论证）。
 *
 *   因此把它抽象成接口，按阶段切换实现：
 *     · 内测期   → Match3NullReplayEngine（不重放，只做 L1+L2 弱校验）
 *     · 正式期   → Match3HttpReplayEngine（调 Node 重放服务，与前端共用同一份 TS 引擎）
 *     · 也可自行实现本接口，用 PHP 写完整引擎
 *
 * 【契约】
 *   replay() 必须对相同 (schema, seed, ops) 永远返回相同结果（确定性）。
 *
 * 迁移自 docs/match3/code/Match3ReplayEngineInterface.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

interface Match3ReplayEngineInterface
{
    /**
     * "**提交非法**"的返回标记（与"引擎不可用"必须区分）
     *
     * `replay()` 的两种失败语义完全不同，混在一起就是**安全漏洞**：
     *
     * | 返回 | 语义 | 上层处置 |
     * |---|---|---|
     * | `null` | **引擎能力不足**（非 down 重力 / 传送带 / behaviours / actors / rainbow 道具 / ops 超长） | 回落 L1+L2 弱校验（宁可放过，不误杀） |
     * | `[INVALID_KEY => 原因]` | **提交本身非法**（不成线的交换、越界、不相邻、未知道具、op 结构损坏） | **拒单**（`invalid_op`） |
     *
     * 若把"非法"也降级，攻击者只要塞一条非法 op 就能让 L3 整体失效 ——
     * 这是 P0 修掉的绕过口。
     */
    public const INVALID_KEY = '_invalid';

    /**
     * 重放一局
     *
     * @param  \stdClass|array<string,mixed> $schema 最终关卡 schema（下发时存档的那份）
     * @param  int    $seed      本局种子
     * @param  array<int, array<string,mixed>> $ops 玩家操作序列
     * @param  string $fillVersion **客户端自报的棋盘算法版本**（`submission.fill_version`）
     *
     *        ⚠️ 这是**灰度共存**的关键：小游戏发布有双版本窗口（审核期 + 用户不更新），
     *        服务端跑着新版、用户手机还是旧版。若不按版本挑算法，用新算法验旧提交
     *        必然分数对不上 → `strict` 下批量拒单。
     *        传 `''`/`null` = 老客户端没带版本 → 用最新版（向后兼容）。
     *
     * @return array<string,mixed>|null {
     *     score: int, moves: int, goals: array<string,int>, maxCombo: int
     * } 返回 null = 未启用 / 无法忠实重放 / **该版本不在支持列表**（回落弱校验，不拒单）；
     *   返回 `[self::INVALID_KEY => string]` = 提交非法（应拒单）
     */
    public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array;

    /**
     * 引擎是否可用
     */
    public function isAvailable(): bool;
}
