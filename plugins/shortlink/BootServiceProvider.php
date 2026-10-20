<?php

namespace plugin\shortlink;


/**
 * 短链接插件启动服务提供者
 * 负责插件启动时的初始化操作
 */
class BootServiceProvider extends WiseBootServiceProvider
{
    /**
     * 注册插件服务
     */
    public function register()
    {
        parent::register();
    }

    /**
     * 插件启动时执行的初始化操作
     */
    public function boot()
    {
        parent::boot();

        // 注册事件监听
        $this->registerEvents();
    }

    /**
     * 注册事件监听
     */
    protected function registerEvents()
    {
        // 事件监听已在 event.php 中注册
        // 此处预留扩展点，供其他插件通过 BootServiceProvider 扩展
    }
}
