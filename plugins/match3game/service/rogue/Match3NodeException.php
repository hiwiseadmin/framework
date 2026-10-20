<?php
/**
 * 肉鸽节点服务异常（06 §十 业务码 41xxx）
 *
 * 迁移自 docs/match3/code/Match3NodeException.php（仅改命名空间）。
 */

declare(strict_types=1);

namespace plugin\match3game\service\rogue;

class Match3NodeException extends \RuntimeException
{
    /** run 不存在或不属于该用户 */
    public const CODE_RUN_NOT_FOUND = 41001;

    /** 节点不在地图中 / 不可达 / play_token 无效 */
    public const CODE_NODE_NOT_FOUND = 41002;

    /** 关卡模板不存在 */
    public const CODE_LEVEL_NOT_FOUND = 41003;

    /** 关卡校验失败（patch 把关卡改坏了） */
    public const CODE_LEVEL_INVALID = 41004;

    /** 本局已结束 */
    public const CODE_RUN_FINISHED = 41005;

    /** 遗物配置缺失 */
    public const CODE_RELIC_MISSING = 41006;

    /** 灵石不足 */
    public const CODE_STONE_NOT_ENOUGH = 41007;

    /** 节点已结算（play_token 已使用） */
    public const CODE_NODE_SETTLED = 41008;

    /**
     * @param array<int, string> $errors 详细错误列表（校验失败时携带）
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        protected array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
