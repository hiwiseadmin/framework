<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\library\LangPack;
use think\Service;

/**
 * 语言包注册服务 —— 把包 / 插件 / 宿主语言包接入 ThinkPHP 的 `lang.extend_list`
 *
 * 🔴 为什么必须写在 `register()` 而不是 `boot()`
 * --------------------------------------------
 * ThinkPHP 的启动顺序（见 vendor/topthink/framework/src/think/App.php:458-493）：
 *
 *   1. load()                  ← 载入 config/*.php，并执行 app/service.php 各服务的 register()
 *   2. loadLangPack()          ← **switchLangSet(默认语言)**，此处即读取 lang.extend_list
 *   3. 初始化器 BootService    ← 此时才执行各服务的 boot()
 *
 * 而 `Lang::get()` 按 range 缓存已加载的语言包（Lang.php:233）——
 * **一旦某个 range 加载过就永不重载**。所以：
 *
 *   - 在 register() 注册 → 默认语言能拿到包/插件语言包 ✅
 *   - 在 boot()   注册 → 默认语言的包/插件语言包**永久丢失**（第 2 步已加载并缓存，
 *                        第 3 步再改 extend_list 也不会重载）❌
 *
 * 顺带一提：同一原因导致 `app/{app}/lang/` 对**默认语言**实际不生效
 * （第 2 步冻结 range 时多应用尚未解析，此刻 appPath 仍是 `app/`），
 * 故「跨应用共用的框架级文案」应放在 `{root}/app/lang/`（见 LangPack 文档）。
 *
 * 为什么注册在 `app/service.php`（而非某个应用目录）
 * -----------------------------------------------
 * 该文件在**多应用解析之前**、以基础应用路径（`app/`）被加载，故对所有应用
 * （admin / api / plugin / member / index）与 CLI 命令一致生效。
 *
 * @see \app\common\library\LangPack 发现规则与优先级
 */
class LangPackService extends Service
{
    public function register(): void
    {
        try {
            LangPack::register();
        } catch (\Throwable $e) {
            try {
                $this->app->log->error('语言包注册失败: ' . $e->getMessage());
            } catch (\Throwable $ignore) {
            }
        }
    }
}
