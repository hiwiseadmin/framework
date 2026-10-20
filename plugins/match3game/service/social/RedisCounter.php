<?php

declare(strict_types=1);

namespace plugin\match3game\service\social;

use wise\support\tools\RedisHelper;
use wise\support\context\ContextualLogger;

/**
 * Redis 原子计数器（05 §A7.2 / §A7.4 的"每日 N 次"配额）
 *
 * 【为什么必须走 Redis】"每日送体力 5 次"在 MySQL 里要 `SELECT COUNT` + `INSERT`，
 * 并发下必然超发（05 §A8.1 第 7 条"并发领取不超发"）。
 * Redis `INCR` 是单线程原子操作，天然防超发。
 *
 * 【降级】Redis 不可用时 `incr()` 返回 `false`，调用方退化为 MySQL 计数
 * （精度略降但功能不中断）。
 *
 * 【key 命名】`match3:{用途}:{uid}:{date}`，由调用方拼好传入。
 * key 一律使用 `withoutPrefix()`，即 Redis 里看到的就是完整 key，便于运维排查。
 */
class RedisCounter
{
    protected const PREFIX = 'match3:';

    protected ?RedisHelper $helper = null;

    protected ?bool $ok = null;

    public function available(): bool
    {
        return $this->conn() !== null;
    }

    /**
     * 自增并返回自增后的值；Redis 不可用 → false
     *
     * @param int $ttl 首次创建时的过期秒数
     */
    public function incr(string $key, int $ttl = 86400): int|false
    {
        $raw = $this->raw();
        if ($raw === null) {
            return false;
        }

        try {
            $value = (int) $raw->incr(self::PREFIX . $key);
            if ($value === 1) {
                $raw->expire(self::PREFIX . $key, $ttl);
            }

            return $value;
        } catch (\Throwable $e) {
            $this->warn('incr', $e);

            return false;
        }
    }

    /**
     * 回滚一次自增（配额判断失败时用，避免白吃一次额度）
     */
    public function decr(string $key): void
    {
        $raw = $this->raw();
        if ($raw === null) {
            return;
        }

        try {
            $raw->decr(self::PREFIX . $key);
        } catch (\Throwable $e) {
            $this->warn('decr', $e);
        }
    }

    public function get(string $key): ?int
    {
        $raw = $this->raw();
        if ($raw === null) {
            return null;
        }

        try {
            $v = $raw->get(self::PREFIX . $key);

            return ($v === false || $v === null) ? 0 : (int) $v;
        } catch (\Throwable $e) {
            $this->warn('get', $e);

            return null;
        }
    }

    /**
     * 冷却锁：`SET NX EX`，**未被占用**返回 true；已被占用返回 false
     *
     * 用于分享冷却（05 §A7.4）。
     *
     * 【故障一律放行（fail-open）】Redis 抛异常时返回 **true** ——
     *   调用方的语义是"是否允许继续"，把故障当成"冷却中"会让玩家看到
     *   「分享太频繁，请 N 秒后再试」，而实际上服务端根本没判成过。
     *   合规口径是"分享是可选的、不能不让人分享"，所以故障方向必须是**放行**，
     *   由每日配额（MySQL/Redis 计数）兜住超发风险。
     */
    public function acquire(string $key, int $ttl): bool
    {
        $raw = $this->raw();
        if ($raw === null) {
            return false;
        }

        try {
            // phpredis 与 Predis 的 SET NX EX 参数形式不同，分别处理
            if ($this->helper?->getDriver() === 'predis') {
                $res = $raw->set(self::PREFIX . $key, '1', 'EX', $ttl, 'NX');
            } else {
                $res = $raw->set(self::PREFIX . $key, '1', ['nx', 'ex' => $ttl]);
            }

            // 成功：phpredis → true，Predis → 'OK'；失败（已被占用）：false / null
            return $res === true || $res === 'OK';
        } catch (\Throwable $e) {
            $this->warn('acquire', $e);

            return true; // 故障放行：不把 Redis 抖动算到玩家头上（见方法注释）
        }
    }

    /**
     * 读一份 JSON 值（幂等键的"首次结果"）；不存在 / Redis 不可用 → null
     *
     * @return array<string,mixed>|null
     */
    public function getJson(string $key): ?array
    {
        $raw = $this->raw();
        if ($raw === null) {
            return null;
        }

        try {
            $val = $raw->get(self::PREFIX . $key);
            if (!is_string($val) || $val === '') {
                return null;
            }

            $decoded = json_decode($val, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->warn('getJson', $e);

            return null;
        }
    }

    /**
     * 覆盖写入一份 JSON 值（**不**保证 NX，用于收尾写"首次结果"）
     *
     * @param array<string,mixed> $value
     */
    public function put(string $key, array $value, int $ttl): void
    {
        $raw = $this->raw();
        if ($raw === null) {
            return;
        }

        try {
            $payload = json_encode($value, JSON_UNESCAPED_UNICODE);
            if (!is_string($payload)) {
                return;
            }

            $raw->setex(self::PREFIX . $key, $ttl, $payload);
        } catch (\Throwable $e) {
            $this->warn('put', $e);
        }
    }

    /**
     * 存一份"首次结果"（幂等键）：**已存在则返回旧值**，新建成功返回 null
     *
     * 用于分享的 `nonce` 去重（2026-10-04）：微信 `shareAppMessage` 的回调在部分机型
     * 会重复触发，同一份分享被上报多次时，服务端必须只结算一次。
     *
     * 【故障一律放行】Redis 不可用时返回 `null`（= 当作新建），由每日配额兜住重复发奖；
     *   宁可偶尔多发 20 金币，也不能因为 Redis 抖动把正常分享判成失败。
     *
     * @param array<string,mixed> $value 首次结果（JSON 可序列化）
     * @return array<string,mixed>|null null = 本次是首次（或 Redis 不可用）
     */
    public function putIfAbsent(string $key, array $value, int $ttl): ?array
    {
        $raw = $this->raw();
        if ($raw === null) {
            return null;
        }

        $full = self::PREFIX . $key;

        try {
            $payload = json_encode($value, JSON_UNESCAPED_UNICODE);
            if (!is_string($payload)) {
                return null;
            }

            // SET NX EX：抢到 = 首次；没抢到 = 已存在
            if ($this->helper?->getDriver() === 'predis') {
                $res = $raw->set($full, $payload, 'EX', $ttl, 'NX');
            } else {
                $res = $raw->set($full, $payload, ['nx', 'ex' => $ttl]);
            }

            if ($res === true || $res === 'OK') {
                return null; // 首次：调用方继续正常处理
            }

            $prev = $raw->get($full);
            if (!is_string($prev) || $prev === '') {
                return null; // 抢不到又读不到（极端竞态）→ 按首次处理，不阻塞玩家
            }

            $decoded = json_decode($prev, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->warn('putIfAbsent', $e);

            return null;
        }
    }

    protected function conn(): ?RedisHelper
    {
        if ($this->ok === false) {
            return null;
        }
        if ($this->helper !== null) {
            return $this->helper;
        }

        try {
            $helper = RedisHelper::getInstance('', config('cache.stores.redis'))->withoutPrefix();
            $helper->raw()->ping();
            $this->helper = $helper;
            $this->ok     = true;

            return $helper;
        } catch (\Throwable $e) {
            $this->ok = false;
            $this->warn('redis unavailable', $e);

            return null;
        }
    }

    /** @return object|null */
    protected function raw()
    {
        return $this->conn()?->raw();
    }

    protected function warn(string $method, \Throwable $e): void
    {
        ContextualLogger::warning('[match3][counter] ' . $method . ' 失败，已降级: ' . $e->getMessage());
    }
}
