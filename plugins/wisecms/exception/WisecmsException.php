<?php

declare(strict_types=1);

namespace plugin\wisecms\exception;

/**
 * WiseCMS 统一业务异常
 *
 * 服务层统一抛出本异常（替代裸 \Exception），携带：
 *   - errorCode：6 位业务错误码（见 ErrorCode，对齐 config/error_code.php）
 *   - httpStatus：由错误码前 3 位推导的 HTTP 状态码
 *   - context：附加上下文（供日志/排查）
 *
 * 控制器 catch (\Exception) 后可依据 instanceof WisecmsException 获取错误码，
 * 用于统一响应与日志。
 */
class WisecmsException extends \RuntimeException
{
    protected int $errorCode;

    protected int $httpStatus;

    /** @var array<string, mixed> */
    protected array $context;

    public function __construct(
        string $message = '',
        int $errorCode = ErrorCode::SAVE_FAILED,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
        $this->errorCode  = $errorCode;
        $this->httpStatus = self::resolveHttpStatus($errorCode);
        $this->context    = $context;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 安全文案（P1-A5）：仅业务异常可回显，其余一律泛化
     *
     * 服务层抛出的 WisecmsException 文案是**有意面向用户**的业务提示
     * （如「URL别名已存在，请更换」），可安全回显；
     * 其余异常（SQL / 文件 / 类型错误）可能携带表结构、SQL 片段、绝对路径，
     * 必须替换为泛化文案，细节由调用方写入日志（`cms_log`）。
     *
     * 用法：`return $this->error(WisecmsException::userMessage($e));`
     */
    public static function userMessage(\Throwable $e, string $fallback = '操作失败，请稍后重试'): string
    {
        return $e instanceof self ? $e->getMessage() : $fallback;
    }

    /**
     * 由 6 位业务码推导 HTTP 状态码（前 3 位即 HTTP 语义）
     */
    protected static function resolveHttpStatus(int $code): int
    {
        $status = (int) substr((string) $code, 0, 3);
        return $status >= 400 && $status <= 599 ? $status : 500;
    }
}
