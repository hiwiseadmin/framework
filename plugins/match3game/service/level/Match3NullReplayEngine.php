<?php
/**
 * 空重放引擎（默认实现）
 *
 * 不执行重放，只做 L1（操作合法性）+ L2（分数粗筛）。
 * 适用于内测期；正式上线必须替换为 Match3HttpReplayEngine（见 06 §9.3 上线硬检查）。
 *
 * 迁移自 docs/match3/code/Match3NullReplayEngine.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

class Match3NullReplayEngine implements Match3ReplayEngineInterface
{
    public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
