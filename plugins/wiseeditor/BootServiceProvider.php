<?php
declare(strict_types=1);

namespace plugin\wiseeditor;

use wise\plugin\WiseBootServiceProvider;
use app\common\library\EditorManager;


class BootServiceProvider extends WiseBootServiceProvider
{
    public function boot()
    {
        parent::boot();

        // 注册编辑器驱动到全局 EditorManager
        $this->registerEditorDrivers();
    }

    public function register()
    {
        // P0-2：EditorManager 必须是容器单例。
        // 若用 bind(闭包)，容器每次 make() 都会 new 一个全新实例，
        // boot() 中注册到实例上的驱动会随实例被丢弃，后续 resolve 又是空实例，
        // 导致 has('richtext') 恒为 false、永远退化成 textarea。
        if (!$this->app->has('editor.manager')) {
            $this->app->instance('editor.manager', new EditorManager());
        }
    }

    /**
     * 向全局 EditorManager 注册编辑器驱动
     */
    protected function registerEditorDrivers(): void
    {
        try {
            $manager = $this->app->make('editor.manager');
            if ($manager instanceof EditorManager) {
                $provider = new EditorProvider();
                $provider->registerEditors($manager);
            }
        } catch (\Throwable $e) {
            // 注册失败不影响系统启动
        }
    }
}
