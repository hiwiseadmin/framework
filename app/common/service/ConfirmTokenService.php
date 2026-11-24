<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Cache;

/**
 * 一次性确认令牌（DoD 硬性：破坏性动作后端校验确认 token，不只靠前端弹窗）
 *
 * 用法（两段式）：
 *   1. 前端执行破坏性动作前，先 GET 一个 token（`issue`）；
 *   2. 携带 token 提交真正动作，后端 `verify` 通过才执行。
 *
 * 特性：
 *   - **一次性**：verify 成功后立即标记已用，重放同一 token 返回 false；
 *   - **场景隔离**：token 绑定 scene（如 `recycle.force_delete`），跨场景不可用；
 *   - **上下文绑定**：可选 context（如模型名），防止把 A 的确认令牌用到 B；
 *   - 短期有效（默认 300s）。
 */
class ConfirmTokenService
{
    private const CACHE_PREFIX = 'confirm_token:';
    private const DEFAULT_TTL = 300;

    /**
     * 签发确认令牌
     *
     * @param string               $scene   场景标识（如 recycle.force_delete / recycle.clear）
     * @param array<string, mixed> $context 绑定上下文（如 ['model' => 'Menu']），校验时按值比对
     * @param int|null             $ttl     有效期（秒），null 用默认
     */
    public function issue(string $scene, array $context = [], ?int $ttl = null): string
    {
        $token = bin2hex(random_bytes(16));

        Cache::set($this->key($token), [
            'scene'   => $scene,
            'context' => $context,
            'used'    => false,
        ], $ttl ?? self::DEFAULT_TTL);

        return $token;
    }

    /**
     * 校验并消耗确认令牌
     *
     * @param string               $scene   必须与签发时一致
     * @param string               $token   客户端提交的令牌
     * @param array<string, mixed> $context 若签发时传了 context，此处需提供等值（否则拒绝）
     */
    public function verify(string $scene, string $token, array $context = []): bool
    {
        if ($token === '') {
            return false;
        }

        $key  = $this->key($token);
        $data = Cache::get($key);

        if (!is_array($data) || ($data['scene'] ?? '') !== $scene) {
            return false;
        }

        if (!empty($data['used'])) {
            return false;
        }

        foreach ($data['context'] ?? [] as $field => $value) {
            if (!array_key_exists($field, $context) || $context[$field] != $value) {
                return false;
            }
        }

        $data['used'] = true;
        Cache::set($key, $data, self::DEFAULT_TTL);

        return true;
    }

    /** 主动作废（可选） */
    public function revoke(string $token): void
    {
        if ($token !== '') {
            Cache::delete($this->key($token));
        }
    }

    protected function key(string $token): string
    {
        return self::CACHE_PREFIX . $token;
    }
}
