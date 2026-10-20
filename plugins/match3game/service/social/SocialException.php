<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use RuntimeException;

/**
 * 社交模块业务异常
 *
 * 与 `Match3NodeException`（肉鸽）分离，便于控制器分别映射错误码。
 */
class SocialException extends RuntimeException
{
    public const CODE_BAD_REQUEST      = 30001;
    public const CODE_NOT_FOUND        = 30002;
    public const CODE_FORBIDDEN        = 30003;
    public const CODE_BATTLE_CLOSED    = 30004;
    public const CODE_ALREADY_DONE     = 30005;
    public const CODE_LIMIT            = 30006;
    public const CODE_GROUP_ID_INVALID = 30007;
    public const CODE_ENERGY_FULL      = 30008;
    public const CODE_COOLDOWN         = 30009;
    public const CODE_SEASON_CLOSED    = 30010;

    public function __construct(string $message = '', int $code = self::CODE_BAD_REQUEST, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): int
    {
        return $this->getCode() > 0 ? $this->getCode() : self::CODE_BAD_REQUEST;
    }
}
