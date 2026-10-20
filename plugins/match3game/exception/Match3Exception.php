<?php

declare(strict_types=1);

namespace plugin\match3game\exception;

use wise\support\exception\WiseException;

/**
 * 消消乐插件业务异常
 *
 * 继承 WiseException（构造签名 int $code, string $message, int $httpCode, ...）。
 * 通过静态工厂 from() 从 Match3ErrorCode 枚举创建，默认 HTTP 400 + 默认中文说明。
 */
class Match3Exception extends WiseException
{
    /**
     * 从错误码枚举创建异常（默认 HTTP 400 + 枚举 defaultMessage()）
     */
    public static function from(Match3ErrorCode $code, ?string $message = null): self
    {
        // 【2026-09-27 定案】业务错误统一 HTTP **200** + `code`（与 `JsonResponse::error()` 同一套方案）。
        // 401/403 由鉴权中间件直接构造响应，不经过这里；5xx 只留给真·服务故障。
        return new self($code->value, $message ?? $code->defaultMessage(), 200);
    }
}
