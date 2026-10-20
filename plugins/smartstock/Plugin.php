<?php
namespace plugin\smartstock;

use plugin\BasePlugin;
use plugin\smartstock\workflow\SmartStockWorkflow;

class Plugin extends BasePlugin
{
    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function enable(): bool
    {
        return true;
    }

    public function disable(): bool
    {
        return true;
    }

    public function getConfig(): array
    {
        return [];
    }

    /**
     * 注册工作流定义
     * @return \wise\workflow\Workflow[]
     */
    public static function getWorkflowDefinitions(): array
    {
        return [
            SmartStockWorkflow::stockIn(),
            SmartStockWorkflow::stockOut(),
            SmartStockWorkflow::transfer(),
            SmartStockWorkflow::check(),
        ];
    }
}
