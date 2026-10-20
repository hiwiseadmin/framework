<?php

declare(strict_types=1);

namespace plugin\match3game\controller\api;

use plugin\ApiController;

/**
 * 消消乐插件 API 控制器基类
 *
 * - 挂 JWT 认证中间件（认证通过后 $request->member 为 Member 实例）
 * - 提供 userId() / payload() 辅助方法
 *
 * URL 走插件 API catch-all：/api/match3game/{controller}/{action}
 */
abstract class Base extends ApiController
{
    /**
     * JWT 认证中间件（与 wise-jwt 签发 token 对接）
     *
     * @var array
     */
    protected $middleware = [
        \wise\member\middleware\JwtAuth::class,
    ];

    /**
     * 当前认证用户 ID
     */
    protected function userId(): int
    {
        return (int) ($this->request->member->id ?? 0);
    }

    /**
     * 读取 JSON 请求体（application/json）
     *
     * @param string $key     字段名（空返回全部）
     * @param mixed  $default 缺省值
     */
    protected function payload(string $key = '', $default = null)
    {
        $raw = $this->request->getInput();
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        if ($key === '') {
            return $data;
        }

        return $data[$key] ?? $default;
    }
}
