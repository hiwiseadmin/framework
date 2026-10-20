<?php

declare(strict_types=1);

namespace plugin\wisecms\exception;

/**
 * WiseCMS 业务错误码
 *
 * 对齐 config/error_code.php 的 6 位编码规范：前 3 位为 HTTP 状态码，
 * 后 3 位为模块号段（本插件复用「数据 600-699」「第三方 800-899」「参数 000-099」号段）。
 */
final class ErrorCode
{
    // ── 参数 / 校验（400xxx） ──
    public const PARAM_INVALID   = 400000;
    public const VALIDATE_FAILED = 400006;

    // ── 数据（404600-404699 / 409600-409699 / 500600-500699） ──
    public const NOT_FOUND         = 404600; // 记录不存在
    public const DUPLICATE         = 409600; // 数据重复
    public const CONFLICT          = 409601; // 版本冲突 / 快照损坏 / 并发写入被拒
    public const SAVE_FAILED       = 500600; // 保存失败
    public const DELETE_FAILED     = 500601; // 删除失败
    public const TRANSACTION_FAILED = 500605; // 事务失败

    // ── 第三方（500800-500899） ──
    public const EXTERNAL_REQUEST_FAILED = 500800;

    private function __construct()
    {
    }
}
