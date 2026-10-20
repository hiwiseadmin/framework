<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

use wise\support\context\ContextualLogger;

/**
 * 重放引擎工厂（配置驱动）
 *
 * 配置：config/match3game/match3.php → replay_engine
 *   'replay_engine' => [
 *       'mode'       => 'node',          // null_engine | php | node | http
 *       'timeout_ms' => 3000,
 *       'node'       => ['binary' => '', 'script' => '', 'timeout_ms' => 3000],
 *       'endpoint'   => 'http://127.0.0.1:9100',   // mode=http 时用
 *   ]
 *
 * 【四种模式】
 *   · `null_engine`  不重放（只有 L1+L2 弱校验）
 *   · **`node`**     `Match3NodeReplayEngine` —— 子进程调 **前端同一份 TS 引擎**。
 *                    **算法单一实现**（2026-09-19 决定），是唯一能安全上 `strict` 的模式
 *   · `php`          `Match3PhpReplayEngine` —— 纯 PHP，**已确认与客户端有分歧**
 *                    （道具清格分支，见 `docs/match3/replay-divergence-report.md`）。
 *                    保留为**回滚 / 对照基线**，不再是长期方案
 *   · `http`         常驻 Node 重放服务（同一份 TS 引擎）。QPS 高到子进程开销
 *                    不可接受时切它，业务代码零改动
 *
 * ⚠️ 上线前必须让 mode 不是 `null_engine`（06 §9.3）：
 *    否则可被"合理分数"的辅助工具刷分。
 */
class ReplayEngineFactory
{
    public static function make(?array $config = null): Match3ReplayEngineInterface
    {
        $config ??= self::readConfig();

        $mode = (string)($config['mode'] ?? 'null_engine');

        if ($mode === 'node') {
            $engine = new Match3NodeReplayEngine($config);

            if ($engine->isAvailable()) {
                return $engine;
            }

            // bundle 未构建：回落 PHP 引擎，**服务不中断但必须告警** ——
            // 否则会静默用"已知有分歧的第二套实现"跑生产，且没人知道。
            ContextualLogger::warning(
                '[Match3Replay] mode=node 但 bundle 不存在，已回落 PHP 引擎'
                . '（请在前端仓库执行: npm run build:replay）',
                ['script' => $engine->getScriptPath()]
            );

            return new Match3PhpReplayEngine($config);
        }

        if ($mode === 'php') {
            return new Match3PhpReplayEngine($config);
        }

        if ($mode === 'http') {
            $endpoint = (string)($config['endpoint'] ?? '');
            if ($endpoint !== '') {
                return new Match3HttpReplayEngine(
                    $endpoint,
                    (int)($config['timeout_ms'] ?? 2000),
                    (int)($config['retry'] ?? 1)
                );
            }
            // 配了 http 却没配 endpoint：降级为 null，避免拿着空 URL 去请求
        }

        return new Match3NullReplayEngine();
    }

    protected static function readConfig(): array
    {
        // 无框架环境下（单测/离线脚本）config() 不存在
        if (!function_exists('config')) {
            return [];
        }

        $all = config('match3game.match3', []);

        return is_array($all['replay_engine'] ?? null) ? $all['replay_engine'] : [];
    }
}
