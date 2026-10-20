<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

use wise\support\context\ContextualLogger;

/**
 * Node 重放引擎 —— **后端直接复用前端 TS 算法**（算法单一实现）
 *
 * ============================================================================
 *  【为什么是它，而不是继续维护 PHP 引擎】
 *
 *  架构决定（2026-09-19）：棋盘生成与对局重放**只有一份实现** ——
 *  `match3game/src/core/*`（TypeScript）。本类把 PHP 侧接过去：
 *
 *      客户端 BattleLoop ─┐
 *                         ├── 同一份 TS 代码（零漂移空间）
 *      服务端 本类 → node ─┘
 *
 *  动机是实测出来的：`docs/match3/replay-divergence-report.md` 记录了 PHP 引擎与
 *  客户端在「道具清格」分支上 3/3 用例分歧（combo 档位 + 补货时机不同序），
 *  而"再修一次 PHP"只是把同一场对齐战无限延长。
 *
 *  ============================================================================
 *  【调用方式：子进程 + stdin/stdout JSON】
 *
 *    node dist/replay-engine.mjs   ←  {schema, seed, ops}
 *                                  →  {ok:true, result:{...}} | {ok:false, kind, reason}
 *
 *  · 不用 shell（`proc_open` 传数组），无注入面
 *  · 单次约 30~60ms（Node 启动开销），结算路径可接受
 *  · **失败一律降级**（返回 null → 上层回落 L1+L2），绝不因为引擎问题误杀玩家
 *
 *  ============================================================================
 *  【两条必须知道的"沉默故障"防线】
 *
 *  1. **bundle 陈旧**：磁盘上的 `dist/replay-engine.mjs` 可能是几天前构建的。
 *     本类会读取它返回的 `fillVersion` 与本地常量比对，不一致即降级并告警 ——
 *     否则会用旧算法去校验新客户端，误判成作弊。
 *  2. **bundle 缺失**：`isAvailable()` 为 false，`ReplayEngineFactory` 会回落到
 *     PHP 引擎（并告警），服务不中断。
 *
 *  ============================================================================
 *  【`invalid` 与 `degraded` 的区分（**已按 P0 改为一拒一降**）】
 *
 *    · `degraded` —— 引擎能力不足（非 down 重力 / 传送带 / behaviours / actors /
 *      rainbow 道具 / ops 超长）：回落 L1+L2 弱校验（宁可放过，不误杀）。
 *    · `invalid`  —— **提交本身非法**（不成线的交换、越界、不相邻、未知道具、
 *      op 结构损坏）：返回 `[Match3ReplayEngineInterface::INVALID_KEY => 原因]`，
 *      由 `SubmissionValidator` **拒单**（`invalid_op` / 11001）。
 *
 *    修这个区分的动机：若"非法"也降级，攻击者只要塞一条坏 op 就能让 L3 整体失效 ——
 *    那么 `strict` 再严也没有意义。
 */
class Match3NodeReplayEngine implements Match3ReplayEngineInterface
{
    /** 与 TS `src/core/schema.ts` 的 `FILL_VERSION` 必须一致（陈旧 bundle 检测 + fill_version 握手） */
    public const FILL_VERSION = Match3FillVersion::CURRENT;

    public const DEFAULT_TIMEOUT_MS = 3000;

    /** 单次重放的 ops 硬上限（与 TS `MAX_REPLAY_OPS` 一致，仅作早退优化） */
    public const MAX_OPS = 2000;

    protected string $binary;

    protected string $script;

    protected int $timeoutMs;

    /**
     * @param array<string,mixed> $config `replay_engine` 配置段（读取其中的 `node` 子段）
     */
    public function __construct(array $config = [])
    {
        $node = (array) ($config['node'] ?? []);

        $this->binary    = trim((string) ($node['binary'] ?? '')) ?: 'node';
        $this->script    = trim((string) ($node['script'] ?? '')) ?: self::defaultScriptPath();
        $this->timeoutMs = max(200, (int) ($node['timeout_ms'] ?? $config['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS));
    }

    /**
     * 引擎是否可用（只检查 bundle 是否存在）
     *
     * 刻意**不**探测 `node --version`：那会给每个请求加一次进程启动开销，
     * 而"node 不存在"的情况会在首次 replay 时暴露为降级告警（fail-safe）。
     */
    public function isAvailable(): bool
    {
        return is_file($this->script);
    }

    /** 便于排障：当前使用的 bundle 路径 */
    public function getScriptPath(): string
    {
        return $this->script;
    }

    /**
     * 重放一局
     *
     * @param  \stdClass|array<string,mixed> $schema
     * @param  array<int, array<string,mixed>> $ops
     * @return array<string,mixed>|null null = 降级（上层回落 L1+L2）
     */
    public function replay($schema, int $seed, array $ops, string $fillVersion = ''): ?array
    {
        // ---- 灰度共存：按客户端版本挑**对应版本的归档 bundle** ----
        //
        // 微信小游戏发布有双版本窗口（审核期 + 用户不更新）：服务端已跑 v7，用户手机还是 v6。
        // 用 v7 的 bundle 去验 v6 的提交 → 分数对不上 → strict 下批量拒单。
        // `npm run archive:replay` 会产出 `dist/replay-engine-v{N}.mjs`，这里挑对应的那个。
        $script = $this->scriptFor($fillVersion);
        if ($script === null) {
            // 该版本没有归档产物 → 结论不可信，**降级**（不拒单）
            $this->degrade('fill_version_not_archived', [
                'client' => $fillVersion,
                'script' => $this->script,
            ]);

            return null;
        }
        $this->script = $script;

        if (!$this->isAvailable()) {
            $this->degrade('bundle_missing', ['script' => $this->script]);

            return null;
        }

        if (count($ops) > self::MAX_OPS) {
            // 与 TS 侧同口径：超长 payload 属于"引擎不处理"，不是玩家作弊
            $this->degrade('too_many_ops', ['ops' => count($ops)]);

            return null;
        }

        $payload = json_encode(
            ['schema' => $schema, 'seed' => $seed, 'ops' => $ops],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($payload)) {
            $this->degrade('payload_encode_failed', ['error' => json_last_error_msg()]);

            return null;
        }

        $response = $this->invoke($payload);
        if ($response === null) {
            return null; // invoke() 内已记录原因
        }

        if (($response['ok'] ?? false) !== true) {
            $kind   = (string) ($response['kind'] ?? 'degraded');
            $reason = (string) ($response['reason'] ?? 'unknown');

            if ($kind === 'invalid') {
                // "提交非法" → 交给上层**拒单**。绝不能降级：
                // 否则塞一条坏 op 就能让 L3 整体失效（P0 修掉的绕过口）。
                return $this->invalid($reason, ['seed' => $seed, 'ops' => count($ops)]);
            }

            $this->degrade('unsupported:' . $reason, ['seed' => $seed, 'ops' => count($ops)]);

            return null;
        }

        $result = (array) ($response['result'] ?? []);

        if (!isset($result['score'])) {
            $this->degrade('malformed_result', ['keys' => array_keys($result)]);

            return null;
        }

        // 陈旧 bundle 检测：算法版本不一致 → 这批结论不可信，宁可降级
        //
        // ⚠️ 期望值必须是"**这次请求要验的版本**"：多版本共存时，v6 的归档产物
        //    当然会自报 v6 —— 那不是陈旧，那是我们要的。
        $expected = $fillVersion !== '' ? $fillVersion : self::FILL_VERSION;
        $remoteVersion = (string) ($result['fillVersion'] ?? '');
        if ($remoteVersion !== $expected) {
            $this->degrade('fill_version_mismatch', [
                'bundle'   => $remoteVersion,
                'expected' => $expected,
                'script'   => $this->script,
            ]);

            return null;
        }

        return $result;
    }

    /**
     * 该客户端版本应该用哪个 bundle
     *
     * @return string|null null = 没有对应归档（调用方降级）
     */
    protected function scriptFor(string $fillVersion): ?string
    {
        $dir = dirname($this->script);

        // 老客户端没带版本 / 就是当前版本 → **优先用归档产物**
        //
        // 【为什么要优先归档】`mode=http` 的服务加载的是归档文件（`dist/replay-engine-v{N}.mjs`）。
        //   若 node 模式走"无版本"那份，两种 mode 就会**用不同的文件** —— 一旦有人只跑了
        //   `build:replay` 而漏了 `archive:replay`，两边算法就不是同一份了。
        //   统一"归档产物才是权威"，只留无版本文件当兜底（老部署可能没有归档）。
        if ($fillVersion === '' || $fillVersion === self::FILL_VERSION) {
            $archived = $dir . '/replay-engine-' . self::FILL_VERSION . '.mjs';

            return is_file($archived) ? $archived : $this->script;
        }

        // 其它（旧）版本：必须有对应归档，否则结论不可信 → 由调用方降级
        $file = $dir . '/replay-engine-' . $fillVersion . '.mjs';

        return is_file($file) ? $file : null;
    }

    /**
     * 启动子进程完成一次调用
     *
     * 【为什么用**临时文件**重定向 stdin/stdout，而不是管道】
     *   Windows 下 `stream_set_blocking()` 对管道不生效 —— 首次 `fread()` 会一直阻塞到
     *   子进程产出数据或退出，**超时判定根本没机会执行**（实测：300ms 的超时被拖成 8.1s，
     *   正好等于子进程的 sleep 时长）。而 `stream_select()` 对管道在 Windows 上也会
     *   谎报"可读"，于是既不能靠 select 轮询、也不能靠非阻塞读。
     *
     *   改用文件重定向后：PHP 侧只轮询 `proc_get_status()`（不碰任何流），
     *   超时可靠、跨平台一致，也顺带省掉了管道 drain 的复杂度。
     *   代价是一次临时文件读写（payload/结果都是 KB 级，可忽略），用完即删。
     *
     * @return array<string,mixed>|null null = 调用失败（已记录原因）
     */
    protected function invoke(string $payload): ?array
    {
        $base = @tempnam(sys_get_temp_dir(), 'm3replay');
        if ($base === false) {
            $this->degrade('tempfile_failed');

            return null;
        }
        @unlink($base); // tempnam 会顺手建一个空文件，这里只用它的名字

        $inPath  = $base . '.in.json';
        $outPath = $base . '.out.json';
        $errPath = $base . '.err.txt';

        if (@file_put_contents($inPath, $payload) === false) {
            $this->degrade('tempfile_write_failed', ['path' => $inPath]);

            return null;
        }

        $descriptors = [
            0 => ['file', $inPath, 'r'],
            1 => ['file', $outPath, 'w'],
            2 => ['file', $errPath, 'w'],
        ];

        // 数组形式 = 不经 shell，参数无需转义，也没有命令注入面
        $process = @proc_open([$this->binary, $this->script], $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->cleanup([$inPath, $outPath, $errPath]);
            $this->degrade('spawn_failed', ['binary' => $this->binary, 'script' => $this->script]);

            return null;
        }

        $timedOut = $this->waitFor($process, $pid);
        $exitCode = (int) @proc_close($process);

        $stdout = (string) @file_get_contents($outPath);
        $stderr = (string) @file_get_contents($errPath);
        $this->cleanup([$inPath, $outPath, $errPath]);

        if ($timedOut) {
            $this->degrade('timeout', ['timeout_ms' => $this->timeoutMs, 'pid' => $pid]);

            return null;
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            $this->degrade('bad_output', [
                'exit'   => $exitCode,
                'stdout' => substr($stdout, 0, 200),
                'stderr' => substr($stderr, 0, 200),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * 等子进程结束，超时则强杀
     *
     * 纯轮询（10ms 一次），不读任何流 —— 见 `invoke()` 的说明。
     *
     * @param  resource $process
     * @param  int      $pid     出参：子进程 PID（供强杀用）
     * @return bool true = 超时被强杀
     */
    protected function waitFor($process, ?int &$pid = null): bool
    {
        $pid      = 0;
        $deadline = microtime(true) + $this->timeoutMs / 1000;

        while (true) {
            $status  = proc_get_status($process);
            $pid     = (int) ($status['pid'] ?? $pid);
            $running = (bool) ($status['running'] ?? false);

            if (!$running) {
                return false;
            }

            if (microtime(true) > $deadline) {
                $this->terminate($process, $pid);
                $this->waitForExit($process);

                return true;
            }

            // 10ms 轮询：Node 启动本身就 30~60ms，这点延迟可忽略
            usleep(10_000);
        }
    }

    /**
     * 强杀后给一小段优雅退出窗口，避免 `proc_close()` 长期阻塞
     *
     * @param resource $process
     */
    protected function waitForExit($process): void
    {
        $deadline = microtime(true) + 1.0;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                return;
            }
            usleep(10_000);
        }
    }

    /**
     * 删除临时文件（静默）
     *
     * @param array<int,string> $paths
     */
    protected function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    /**
     * 强杀子进程（超时兜底）
     *
     * ⚠️ **Windows 上不能只靠 `proc_terminate()`**：
     *   它不保证子进程立即结束，而随后的 `proc_close()` 会**一直等到子进程自己退出** ——
     *   对"挂死的 node"就等于无限期占住一个 PHP-FPM worker（实测：300ms 超时的用例
     *   实际阻塞了 8.1 秒，正好等于子进程的 sleep 时长）。
     *   因此 Windows 走 `taskkill /F /T`（连进程树一起杀）。
     *   自检脚本 §4 的第二条用例就是为这个坑写的。
     *
     * @param resource $process
     */
    protected function terminate($process, int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            // PID 来自 proc_get_status()，是整数，无注入面
            @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');

            return;
        }

        @proc_terminate($process, 9);
    }

    /**
     * 定位 bundle（配置未显式指定时的候选顺序）
     *
     * 找不到时返回首选候选路径 —— `isAvailable()` 会据此返回 false，
     * 由 `ReplayEngineFactory` 回落 PHP 引擎，不会中断服务。
     */
    protected static function defaultScriptPath(): string
    {
        $candidates = [];

        if (function_exists('root_path')) {
            $candidates[] = rtrim((string) root_path(), '/\\') . '/match3game/dist/replay-engine.mjs';
        }

        // 插件位于 <repo>/src/plugins/match3game/service/level → 上溯 5 级 = 仓库根
        $candidates[] = dirname(__DIR__, 5) . '/match3game/dist/replay-engine.mjs';

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0] ?? 'match3game/dist/replay-engine.mjs';
    }

    /**
     * 记录一次降级（可观测指标：降级率 = 无法重放的比例）
     *
     * @param array<string,mixed> $ctx
     */
    protected function degrade(string $reason, array $ctx = []): void
    {
        ContextualLogger::warning('[Match3Replay][node] 无法重放，回落 L1+L2 弱校验: ' . $reason, $ctx);
    }

    /**
     * 记录并返回"**提交非法**"（上层据此**拒单**）
     *
     * @param  array<string,mixed> $ctx
     * @return array<string,string> 直接 return 给调用方
     */
    protected function invalid(string $reason, array $ctx = []): array
    {
        ContextualLogger::warning('[Match3Replay][node] ops 非法，L3 拒单: ' . $reason, $ctx);

        return [Match3ReplayEngineInterface::INVALID_KEY => $reason];
    }
}
