<?php

declare(strict_types=1);

namespace plugin\match3game\service\level;

/**
 * 棋盘填充 / 对局重放**算法版本**（`fill_version`）
 *
 * ============================================================================
 *  【这是 strict 上线后的保险丝】
 *
 *  `compare_mode=strict` 下，"重放分 ≠ 上报分"就拒单。两端算法一旦不同源
 *  （客户端发的是旧包 / 服务端 `dist/replay-engine.mjs` 是旧构建），
 *  结果就是**批量误杀**——而且是在玩家打完一局之后才被发现。
 *
 *  这道握手让客户端在**开局前**就能发现：
 *
 *      battle/start / battle/latest / rogue/enter  →  fill_version: 'v1'
 *      客户端 checkFillVersion(remote)             →  mismatch 则提示强更、拒绝开局
 *
 *  ============================================================================
 *  【单一事实来源与护栏】
 *
 *  权威定义在前端：`match3game/src/core/schema.ts` 的 `FILL_VERSION`。
 *  PHP 侧只留一个镜像常量（服务端不下发"它自己是谁"，只下发约定值），
 *  `tests/verify-strict-rollout.php` §1 会**逐字解析 TS 源码**与这里比对 ——
 *  任何一侧改了、另一侧没跟上，自检立刻红。
 *
 *  【什么时候必须 bump】
 *   任何会改变棋盘推进结果的改动：RNG / 填充 / 重力 / 连锁 / 计分口径 /
 *   道具的清格与补货时机。bump 时前后端同时改（见上）。
 */
final class Match3FillVersion
{
    public const CURRENT = 'v7';

    private function __construct()
    {
    }
}
