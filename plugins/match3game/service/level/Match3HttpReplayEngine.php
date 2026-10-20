<?php
declare(strict_types=1);

namespace plugin\match3game\service\level;

use wise\support\context\ContextualLogger;

/**
 * HTTP 重放引擎 —— 调用独立的 Node 重放服务
 *
 * 【为什么需要它】
 *   前端是 TS 引擎，若 PHP 再写一份三消引擎，两边必然漂移（02 §10.3 方案 A 的代价）。
 *   把 TS 引擎编译后在 Node 服务里跑，PHP 通过 HTTP 调用，
 *   【单一实现、永不漂移】，这是中后期推荐方案（方案 B）。
 *
 * 【部署形态】
 *   Node 服务加载与前端同一份 match3 engine（npm 包），暴露：
 *     POST /replay  { schema, seed, ops } → { score, moves, goals, maxCombo }
 *
 * 【降级】
 *   调用失败、**或服务算法版本与本地不一致**（常驻进程未重启 / 磁盘 bundle 陈旧 → 结论不可信）
 *   时返回 null，SubmissionValidator 会退化为 L1+L2 弱校验 + 记录告警，
 *   不会阻断玩家通关（宁可放过，不可误杀）。
 *
 * 迁移自 docs/match3/code/Match3HttpReplayEngine.php（trace() → ContextualLogger）。
 */
class Match3HttpReplayEngine implements Match3ReplayEngineInterface
{
    protected string $endpoint;
    protected int    $timeoutMs;
    protected int    $retry;

    public function __construct(string $endpoint, int $timeoutMs = 2000, int $retry = 1)
    {
        $this->endpoint  = rtrim($endpoint, '/');
        $this->timeoutMs = $timeoutMs;
        $this->retry     = $retry;
    }

    public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
    {
        // ---- 陈旧引擎防线（与 `Match3NodeReplayEngine` 同策略，见那边「沉默故障防线①」）----
        //
        // node 模式每次 spawn 子进程，读的是**磁盘上最新的** bundle；http 模式是常驻进程，
        // 磁盘 bundle 更新了、**服务没重启**时，进程里跑的仍是旧算法 ——
        // 于是用旧算法校验新客户端，把"合法通关"判成 `invalid_op`（11001）。
        // 2026-10-02 第三关（M3-0103·初见寒冰，首次出现冰）线上 11001 即此形态。
        // 结论不可信时**降级**（回落 L1+L2 弱校验），绝不误杀。
        if (!$this->algorithmMatches()) {
            return null;
        }

        // ---- 灰度共存：这个提交的版本我能不能验？ ----
        //
        // 微信小游戏发布有**双版本窗口**（审核期 + 用户不更新）：服务端已跑 v7，用户手机还是 v6。
        // 不按版本分派 → 用 v7 算法验 v6 的提交 → 分数必然对不上 → strict 下批量拒单。
        // 版本**不在支持列表** → 直接返回 null（降级弱校验），**不打服务、不拒单**。
        if (!$this->versionSupported($fillVersion)) {
            ContextualLogger::warning('[Match3Replay] 客户端算法版本不在支持列表，降级为弱校验', [
                'client'    => $fillVersion,
                'supported' => $this->supportedVersions(),
            ]);

            return null;
        }

        $payload = json_encode([
            'schema'       => $schema,
            'seed'         => $seed,
            'ops'          => $ops,
            // 服务据此挑**对应版本**的归档引擎（`scripts/replay-server.ts`）
            'fill_version' => $fillVersion,
        ], JSON_UNESCAPED_UNICODE);

        $attempt = 0;
        do {
            $result = $this->post($payload);
            if ($result !== null) {
                return $result;
            }
            $attempt++;
        } while ($attempt < $this->retry);

        // 降级：记录告警，由上层按弱校验处理
        ContextualLogger::warning('[Match3Replay] Node 重放服务不可用，降级为弱校验', [
            'endpoint' => $this->endpoint,
        ]);

        return null;
    }

    /**
     * 支持哪些**客户端**算法版本（灰度共存）
     *
     * 配置：`match3game.match3.replay_engine.supported_fill_versions`（数组）
     *
     * 缺省 = `[Match3FillVersion::CURRENT]`，即只认当前版本（发布前的稳态）。
     * 灰度期把它改成 `['v6','v7']`：新旧客户端都能被正确重放。
     * 等旧版本流量归零（看埋点）后，把它收紧回 `[CURRENT]` —— 之后旧版本提交降级弱校验。
     *
     * @return array<int,string>
     */
    protected function supportedVersions(): array
    {
        $cfg = function_exists('config')
            ? config('match3game.match3.replay_engine.supported_fill_versions', null)
            : null;

        if (is_array($cfg) && $cfg !== []) {
            return array_values(array_map('strval', $cfg));
        }

        return [Match3FillVersion::CURRENT];
    }

    /**
     * 该客户端版本是否在支持列表内
     *
     * 空串 = 老客户端没带版本 → 放行（服务会用内置最新版，向后兼容）。
     */
    protected function versionSupported(string $fillVersion): bool
    {
        if ($fillVersion === '') {
            return true;
        }

        return in_array($fillVersion, $this->supportedVersions(), true);
    }

    /**
     * 重放服务与本地算法版本是否一致
     *
     * 探测 `/health` 的 `fillVersion`（`scripts/replay-server.ts` 会返回它）：
     *   · 显式不一致 → 告警 + `false`（调用方降级，不采信任何结论）
     *   · `/health` 探测失败 → `true`（把判断留给 `post()`，保持原有降级路径不变）
     *
     * ⚠️ 只缓存"一致"这一结论（`$matched`）：不一致属于故障态，修好（重启服务）后
     *    不应因为 php-fpm worker 常驻而一直拒绝重放。
     */
    protected function algorithmMatches(): bool
    {
        static $matched = false;

        if ($matched) {
            return true;
        }

        if (!function_exists('curl_init')) {
            return true;
        }

        $ch = curl_init($this->endpoint . '/health');
        if ($ch === false) {
            return true;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => min(1000, $this->timeoutMs),
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== 0 || $code < 200 || $code >= 300 || !is_string($raw) || $raw === '') {
            return true; // 探不通：交给 post() 的老路径处理
        }

        $data   = json_decode($raw, true);
        $remote = is_array($data) ? (string)($data['fillVersion'] ?? '') : '';

        if ($remote === Match3FillVersion::CURRENT) {
            $matched = true;

            return true;
        }

        ContextualLogger::warning(
            '[Match3Replay][http] 重放服务算法版本不符（服务未重启 / bundle 陈旧），降级为弱校验',
            [
                'endpoint' => $this->endpoint,
                'service'  => $remote,
                'expected' => Match3FillVersion::CURRENT,
            ]
        );

        return false;
    }

    public function isAvailable(): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init($this->endpoint . '/health');
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 1000,
            CURLOPT_NOBODY         => true,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function post(string $payload): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($this->endpoint . '/replay');
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
        ]);

        $raw = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($err !== 0 || !is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        // 服务端显式报告失败：区分"提交非法（拒单）"与"引擎能力不足（降级）"
        if (($data['ok'] ?? null) === false) {
            $kind   = (string) ($data['kind'] ?? 'degraded');
            $reason = (string) ($data['reason'] ?? 'unknown');

            if ($kind === 'invalid') {
                ContextualLogger::warning('[Match3Replay][http] ops 非法，L3 拒单: ' . $reason, [
                    'endpoint' => $this->endpoint,
                ]);

                return [Match3ReplayEngineInterface::INVALID_KEY => $reason];
            }

            return null;
        }

        if (!isset($data['score'])) {
            return null;
        }

        $out = [
            'score'    => (int)$data['score'],
            'moves'    => (int)($data['moves'] ?? 0),
            'goals'    => (array)($data['goals'] ?? []),
            'maxCombo' => (int)($data['maxCombo'] ?? 0),
        ];

        // 逐回合棋盘指纹（S5-3）：服务成功响应会把它平铺在顶层（`replay-server` 的
        // `{ok:true, ...result, result}`）。它是**可选**产物 —— 缺失时
        // `SubmissionValidator` 只是"这一回合无从比对"，不降级、不拒单。
        $fps = $data['turnFingerprints'] ?? null;
        if (is_array($fps) && $fps !== []) {
            $out['turnFingerprints'] = array_map('strval', $fps);
        }

        return $out;
    }
}
