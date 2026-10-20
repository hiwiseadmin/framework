<?php
/**
 * 三层校验结果
 *
 * 迁移自 docs/match3/code/Match3ValidationResult.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\level;

class Match3ValidationResult
{
    public function __construct(
        public bool $passed,
        public array $errors = [],
        public ?string $errorCode = null,
        public array $meta = [],
        public bool $replayed = false,
    ) {
    }

    public static function ok(array $meta = [], bool $replayed = false): self
    {
        return new self(true, [], null, $meta, $replayed);
    }

    /**
     * @param array<int, string> $errors
     */
    public static function fail(string $code, array $errors): self
    {
        return new self(false, $errors, $code);
    }

    public function toArray(): array
    {
        return [
            'passed'     => $this->passed,
            'errors'     => $this->errors,
            'error_code' => $this->errorCode,
            'meta'       => $this->meta,
            'replayed'   => $this->replayed,
        ];
    }
}
