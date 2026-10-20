<?php
/**
 * 消消乐插件命令行配置（CLI 由 `PluginServiceProvider::registerPluginConsoleCommands` 读取）
 *
 * ============================================================================
 *  【命令名到底怎么来的（以框架代码为准）】
 *
 *  `extend/wise/plugin/PluginServiceProvider.php:257` 的规则是：
 *
 *      字符串键 → $pluginIdent . ':' . preg_replace('/^match3game:/', '', $name)
 *      数字键   → 直接入列，用命令类自身 `setName()`（TP `Console::addCommand` 语义）
 *
 *  即字符串键会**先剥掉已有的插件前缀再加一次**，所以：
 *
 *      'match3game:daily' => DailyChallengeCommand::class   →  `match3game:daily`   ✅ 本文件采用
 *      'match3:daily'     => DailyChallengeCommand::class   →  `match3game:match3:daily`  ❌ 前缀重复
 *      数字键（类名列表）                                    →  `match3game:daily`（取 setName）
 *
 *  两种写法**最终命令名可以相同**，但本插件统一用字符串键：
 *  键名 = `setName()` 的值 = crontab 里写的名字，一处对不上 `verify-cron.php` 立刻红。
 *
 *  ⚠️ 2026-10-04：本文件曾被改成数字键（`[Class::class, ...]`），命令名没变、
 *     线上也照跑，但 `verify-cron.php` 的注册口径断言全线报红 → CI 门禁失效。
 *     已统一回字符串键（与 `setName()` 逐字一致）。
 *
 *  【crontab】名字必须与本文件一致（`php think list | grep match3` 可核对）：
 *      见 `tests/verify-cron.php` 第 3 节打印的建议行。
 * ============================================================================
 */
return [
    'commands' => [
        'match3game:daily'       => \plugin\match3game\command\DailyChallengeCommand::class,
        'match3game:pvp-timeout' => \plugin\match3game\command\PvpTimeoutCommand::class,
        'match3game:season'      => \plugin\match3game\command\SeasonSwitchCommand::class,
        'match3game:group-rank'  => \plugin\match3game\command\GroupRankCommand::class,
        'match3game:cleanup'     => \plugin\match3game\command\CleanupCommand::class,
    ],
];
