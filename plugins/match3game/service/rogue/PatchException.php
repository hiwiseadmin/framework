<?php
/**
 * Patch 引擎异常
 *
 * 与 TS 版 PatchError 对齐：name = "PatchException"
 * （跨语言比对时，test-vectors.json 的 expectError 字段用类名区分）
 *
 * 迁移自 docs/match3/code/PatchException.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

class PatchException extends \RuntimeException
{
    /** @var string|null 出错的路径 */
    protected ?string $patchPath = null;

    public function __construct(string $message = '', ?string $patchPath = null, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->patchPath = $patchPath;
    }

    public function getPatchPath(): ?string
    {
        return $this->patchPath;
    }
}
