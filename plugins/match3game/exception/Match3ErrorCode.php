<?php

declare(strict_types=1);

namespace plugin\match3game\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * 消消乐插件业务错误码（文档 §7.4）
 *
 * 这些是返回给客户端的「响应信封 code」业务码（非 HTTP 状态码）。
 * 前端契约以 docs/match3-game-backend-design.md §7.4 为准。
 */
enum Match3ErrorCode: int implements ErrorCodeInterface
{
    /** 参数错误 / 关卡不存在 */
    case LEVEL_NOT_FOUND      = 10001;

    /** 关卡未解锁 */
    case LEVEL_LOCKED         = 10002;

    /** 关卡已停用 */
    case LEVEL_DISABLED       = 10003;

    /** 体力不足 */
    case ENERGY_NOT_ENOUGH    = 10004;

    /** 背包道具不足 */
    case PROP_NOT_ENOUGH      = 10005;

    /** 对局不存在 / 已结算（battle_token 无效/重放） */
    case BATTLE_INVALID       = 10006;

    /** 对局已过期 */
    case BATTLE_EXPIRED       = 10007;

    /** 结算校验不通过（§3.3 任一项） */
    case SETTLE_INVALID       = 10008;

    /** 广告今日次数已达上限 */
    case AD_LIMIT_REACHED     = 10009;

    /** 兑换/购买余额不足 */
    case BALANCE_NOT_ENOUGH   = 10010;

    /** 关卡 Schema 校验不通过（Level Schema v1，01 §10.3） */
    case LEVEL_SCHEMA_INVALID = 10011;

    // ================================================================
    //  提交校验细分错误码（`API.md` §10.2）
    //
    //  【为什么要把它们真的返回出去】
    //   在 P0 之前，三层校验失败一律回 `10008 SETTLE_INVALID`，客户端只能看到
    //   "结算校验不通过"——分不清「被判定作弊」和「服务器引擎故障」，
    //   前者应该立即提示，后者应该静默重试。前端 `net/errorCodes.ts` 早已按
    //   11001~11005 写好分支与 UI 文案，只是后端从未返过，属于契约空转。
    // ================================================================

    /** 非法操作（ops 不合法 / 条数超限）—— **拒单** */
    case SUBMIT_INVALID_OP = 11001;

    /** 分数离谱（L2 粗筛未过） */
    case SUBMIT_INVALID_CLAIM = 11002;

    /** 重放与上报不符（strict 模式下的作弊判定） */
    case SUBMIT_RESULT_MISMATCH = 11003;

    /** 棋盘分歧（seed 不一致 / 客户端算法版本不符） */
    case SUBMIT_BOARD_MISMATCH = 11004;

    /** checkpoint 结构非法（可选字段，客户端可去掉后静默重提） */
    case SUBMIT_INVALID_CHECKPOINT = 11005;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::LEVEL_NOT_FOUND   => '参数错误或关卡不存在',
            self::LEVEL_LOCKED      => '关卡未解锁',
            self::LEVEL_DISABLED    => '关卡已停用',
            self::ENERGY_NOT_ENOUGH => '体力不足',
            self::PROP_NOT_ENOUGH   => '背包道具不足',
            self::BATTLE_INVALID    => '对局不存在或已结算',
            self::BATTLE_EXPIRED    => '对局已过期',
            self::SETTLE_INVALID    => '结算校验不通过',
            self::AD_LIMIT_REACHED  => '广告今日次数已达上限',
            self::BALANCE_NOT_ENOUGH => '兑换或购买余额不足',
            self::LEVEL_SCHEMA_INVALID => '关卡数据校验不通过',
            self::SUBMIT_INVALID_OP    => '操作数据不合法',
            self::SUBMIT_INVALID_CLAIM => '结算数据异常',
            self::SUBMIT_RESULT_MISMATCH => '成绩校验未通过',
            self::SUBMIT_BOARD_MISMATCH  => '棋盘数据不一致',
            self::SUBMIT_INVALID_CHECKPOINT => '校验点数据异常',
        };
    }

    /**
     * 把 `SubmissionValidator` 的字符串错误码映射成响应信封码
     *
     * 纯函数（无 DB、无配置依赖），可以脱离框架单测。
     *
     * ⚠️ 这里刻意用**字面量**而不是引用 `SubmissionValidator::ERR_*`：
     *    错误码定义在 service 层，让 exception 层反向依赖它会把依赖方向搞乱。
     *    代价是"字符串写错会静默落到 SETTLE_INVALID"，因此
     *    `tests/verify-strict-rollout.php` 有一条**漂移护栏**：
     *    遍历 `SubmissionValidator` 的全部 `ERR_*` 常量，断言每一个都能映射到
     *    非默认码 —— 任何改名/新增都会在那里红。
     *
     * @param string $errorCode `SubmissionValidator::ERR_*`（如 `invalid_op`）
     */
    public static function fromSubmitError(string $errorCode): self
    {
        return match ($errorCode) {
            'invalid_op'         => self::SUBMIT_INVALID_OP,
            'invalid_claim'      => self::SUBMIT_INVALID_CLAIM,
            'result_mismatch'    => self::SUBMIT_RESULT_MISMATCH,
            'board_mismatch'     => self::SUBMIT_BOARD_MISMATCH,
            'invalid_checkpoint' => self::SUBMIT_INVALID_CHECKPOINT,
            default              => self::SETTLE_INVALID,
        };
    }
}
