<?php
namespace plugin\smartstock;

use wise\plugin\WiseBootServiceProvider;
use wise\workflow\WorkflowRegistry;


class BootServiceProvider extends WiseBootServiceProvider
{
    /**
     * 注册插件服务
     */
    public function register()
    {
        parent::register();

        // 注册工作流定义
        $this->registerWorkflows();
    }

    /**
     * 插件启动时执行的初始化操作
     */
    public function boot()
    {
        parent::boot();
    }

    /**
     * 注册 4 个工作流定义到全局注册表
     */
    protected function registerWorkflows(): void
    {
        $definitions = Plugin::getWorkflowDefinitions();
        foreach ($definitions as $workflow) {
            WorkflowRegistry::add($workflow);
        }
    }
}
