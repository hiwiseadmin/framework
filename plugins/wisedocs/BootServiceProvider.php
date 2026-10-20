<?php
namespace plugin\wisedocs;

use wise\plugin\WiseBootServiceProvider;

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

        // 其他需要执行的初始化操作

    }
}