<?php

declare(strict_types=1);

namespace wise\plugin\audit;

/**
 * 单条预检结果。
 *
 * 定位：**只描述问题，不承载修复动作**。渲染、聚合、退出码判定分别在
 * {@see \wise\plugin\command\tool\PluginAudit} 与 {@see AuditRunner} 中完成。
 *
 * 级别语义（与设计口径一致）：
 *  - ERROR：确定性规则（结构 / 清单），本地与服务端结论应一致，必须修；
 *  - WARN ：低敏正则提示、建议项，**不阻断**，服务端会做 AST 与人工审核。
 */
final class AuditIssue
{
    public const ERROR = 'error';

    public const WARN = 'warn';

    public function __construct(
        public readonly string $rule,
        public readonly string $level,
        public readonly string $message,
        public readonly string $file = '',
        public readonly int $line = 0,
        public readonly string $hint = ''
    ) {
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /**
     * 位置串（file:line），无文件时返回空串。
     */
    public function location(): string
    {
        if ($this->file === '') {
            return '';
        }

        return $this->line > 0 ? $this->file . ':' . $this->line : $this->file;
    }

    /**
     * @return array{rule: string, level: string, message: string, file: string, line: int, hint: string}
     */
    public function toArray(): array
    {
        return [
            'rule'    => $this->rule,
            'level'   => $this->level,
            'message' => $this->message,
            'file'    => $this->file,
            'line'    => $this->line,
            'hint'    => $this->hint,
        ];
    }
}
