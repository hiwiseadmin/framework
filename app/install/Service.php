<?php
namespace app\install;

use think\Service as ThinkService;

class Service extends ThinkService
{
    /**
     * 注册服务
     *
     * @return void
     */
    public function register()
    {
        // 绑定服务到容器

        $this->commands([
            \app\install\command\Install::class,
        ]);
    }

    /**
     * 启动服务
     *
     * @return void
     */
    public function boot()
    {

    }
}
