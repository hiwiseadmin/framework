<?php
declare(strict_types=1);

namespace app\common\resetter;

use think\App;
use think\swoole\contract\ResetterInterface;
use think\swoole\Sandbox;
use wise\support\context\RequestStateRegistry;

/**
 * 包 / 插件请求级状态重置器（think-swoole 桥接）
 *
 * 这是「统一 resetters 机制」在宿主侧的**唯一**桥接点：
 *   - 框架、wise-* 包、插件只需实现
 *     {@see \wise\support\contracts\RequestStateResetterInterface} 并在 boot 阶段注册到
 *     {@see RequestStateRegistry}，无需为每个包各写一个 think-swoole Resetter；
 *   - 本类在此把注册表接入 think-swoole 的 `swoole.resetters`，由沙箱在
 *     **每个请求开始前**（`Sandbox::init()` → `resetApp()`，业务回调之前）统一执行。
 *
 * 与 HttpEnd 的分工：
 *   - 本类：Swoole 协程运行时，请求**开始前**清上一个请求的残留（强保证）；
 *   - HttpEnd：非协程运行时（FPM / Workerman / Swoole 同步 worker）兜底，
 *     见 {@see \wise\support\SupportServiceProvider::boot()}。
 *
 * 说明：本类仅在安装了 `topthink/think-swoole` 并由 `swoole.resetters` 实例化时
 * 才会被加载；未安装时该配置项无消费方，不会触发类加载（故可安全保留 ::class 引用）。
 *
 * @disregard P1009 think-swoole 为可选依赖
 */
class PackageStateResetter implements ResetterInterface
{
    /**
     * @param App     $app
     * @param Sandbox $sandbox
     * @return void
     */
    public function handle($app, $sandbox)
    {
        RequestStateRegistry::runAll();
    }
}
