<?php
/**
 * 工作流演示插件 - 后端路由定义
 * 路由名称必须为 plugin，不能修改
 * 当url为 /plugin/workflowdemo/[控制器]/[方法] 时调用
 */
use think\facade\Route;

// 自动路由模式（无需手动定义）
// URL 映射规则：
// /plugin/workflowdemo/order/index     → plugin\workflowdemo\controller\Order::index()
// /plugin/workflowdemo/order/create    → plugin\workflowdemo\controller\Order::create()
// /plugin/workflowdemo/order/save      → plugin\workflowdemo\controller\Order::save()
// /plugin/workflowdemo/order/detail    → plugin\workflowdemo\controller\Order::detail()
// /plugin/workflowdemo/order/transition → plugin\workflowdemo\controller\Order::transition()
