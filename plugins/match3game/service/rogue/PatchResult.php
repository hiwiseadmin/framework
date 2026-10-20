<?php
/**
 * Patch 应用结果
 *
 * 迁移自 docs/match3/code/PatchResult.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

class PatchResult
{
    /**
     * @param \stdClass|array|scalar|null $doc      应用后的新文档（原文档未被修改）
     * @param array<int, array<string,mixed>> $applied 成功应用的操作
     * @param array<int, array<string,mixed>> $skipped 被跳过的操作（非空即需关注）
     */
    public function __construct(
        public $doc,
        public array $applied = [],
        public array $skipped = [],
    ) {
    }

    /**
     * 是否有被跳过的操作（选择器未命中、路径不存在等）
     */
    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }
}
