<?php
namespace plugin\workflowdemo;

use wise\plugin\WiseBootServiceProvider;
use wise\workflow\WorkflowRegistry;
use think\facade\Event;


class BootServiceProvider extends WiseBootServiceProvider
{
    /**
     * 注册插件服务
     */
    public function register()
    {
        parent::register();

        // 注册工作流定义到全局注册表
        $this->registerWorkflows();
    }

    /**
     * 插件启动时执行的初始化操作
     */
    public function boot()
    {
        parent::boot();

        // 注册工作流事件监听器
        $this->registerWorkflowListeners();
    }

    /**
     * 注册工作流定义
     */
    protected function registerWorkflows()
    {
        // 获取工作流定义
        $definitions = Plugin::getWorkflowDefinitions();

        // 注册到全局注册表（WorkflowRegistry 使用静态方法）
        foreach ($definitions as $workflow) {
            WorkflowRegistry::add($workflow);
        }
    }

    /**
     * 注册工作流事件监听器
     */
    protected function registerWorkflowListeners()
    {
        // 监听工作流转换前事件
        Event::listen('workflow.before_transition', function ($params) {
            // $params = ['workflow' => $workflow, 'subject' => $subject, 'transition' => $transitionName]
            // 可以在这里记录日志、发送通知等
        });

        // 监听工作流转换后事件
        Event::listen('workflow.after_transition', function ($params) {
            // 工作流状态转换后的处理逻辑
            // 例如：发送通知、更新关联数据等
        });
    }
}
