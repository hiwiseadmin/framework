<?php
namespace plugin\smartstock\workflow;

use wise\workflow\Workflow;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;

/**
 * SmartStock 工作流定义
 *
 * 定义 4 个业务工作流，所有状态流转由 wise-workflow 引擎驱动。
 */
class SmartStockWorkflow
{
    /**
     * 入库工作流
     * 状态：draft -> confirmed -> reviewed -> completed / cancelled
     */
    public static function stockIn(): Workflow
    {
        $workflow = new Workflow('smartstock_stock_in', [], [], 'draft');
        $workflow->setMetadata(['title' => '入库工作流']);

        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('confirmed'));
        $workflow->addPlace(new Place('reviewed'));
        $workflow->addPlace(new Place('completed'));
        $workflow->addPlace(new Place('cancelled'));

        $workflow->addTransition(new Transition('submit', 'draft', 'confirmed'));
        $workflow->addTransition(new Transition('review', 'confirmed', 'reviewed'));
        $workflow->addTransition(new Transition('complete', 'reviewed', 'completed'));
        $workflow->addTransition(new Transition('cancel', ['draft', 'confirmed', 'reviewed'], 'cancelled'));

        return $workflow;
    }

    /**
     * 出库工作流
     * 状态：draft -> confirmed -> reviewed -> completed / cancelled
     */
    public static function stockOut(): Workflow
    {
        $workflow = new Workflow('smartstock_stock_out', [], [], 'draft');
        $workflow->setMetadata(['title' => '出库工作流']);

        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('confirmed'));
        $workflow->addPlace(new Place('reviewed'));
        $workflow->addPlace(new Place('completed'));
        $workflow->addPlace(new Place('cancelled'));

        $workflow->addTransition(new Transition('submit', 'draft', 'confirmed'));
        $workflow->addTransition(new Transition('review', 'confirmed', 'reviewed'));
        $workflow->addTransition(new Transition('complete', 'reviewed', 'completed'));
        $workflow->addTransition(new Transition('cancel', ['draft', 'confirmed', 'reviewed'], 'cancelled'));

        return $workflow;
    }

    /**
     * 调拨工作流
     * 状态：draft -> reviewed -> approved -> transferring -> completed / cancelled
     */
    public static function transfer(): Workflow
    {
        $workflow = new Workflow('smartstock_transfer', [], [], 'draft');
        $workflow->setMetadata(['title' => '调拨工作流']);

        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('reviewed'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addPlace(new Place('transferring'));
        $workflow->addPlace(new Place('completed'));
        $workflow->addPlace(new Place('cancelled'));

        $workflow->addTransition(new Transition('submit', 'draft', 'reviewed'));
        $workflow->addTransition(new Transition('approve', 'reviewed', 'approved'));
        $workflow->addTransition(new Transition('start_transfer', 'approved', 'transferring'));
        $workflow->addTransition(new Transition('complete', 'transferring', 'completed'));
        $workflow->addTransition(new Transition('cancel', ['draft', 'reviewed'], 'cancelled'));

        return $workflow;
    }

    /**
     * 盘点工作流
     * 状态：planned -> checking -> completed -> resolved
     */
    public static function check(): Workflow
    {
        $workflow = new Workflow('smartstock_check', [], [], 'planned');
        $workflow->setMetadata(['title' => '盘点工作流']);

        $workflow->addPlace(new Place('planned'));
        $workflow->addPlace(new Place('checking'));
        $workflow->addPlace(new Place('completed'));
        $workflow->addPlace(new Place('resolved'));

        $workflow->addTransition(new Transition('start_check', 'planned', 'checking'));
        $workflow->addTransition(new Transition('complete', 'checking', 'completed'));
        $workflow->addTransition(new Transition('resolve', 'completed', 'resolved'));

        return $workflow;
    }
}
